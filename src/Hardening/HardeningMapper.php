<?php

/**
 * -------------------------------------------------------------------------
 * Docker Inventory plugin for GLPI
 * Copyright (C) 2026 Vincent GUILLOTTE
 * https://github.com/parime/glpi-docker-inventory
 * -------------------------------------------------------------------------
 * LICENSE
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version. See LICENSE for the full text.
 * -------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace GlpiPlugin\Dockerinventory\Hardening;

/**
 * Configurations à risque des conteneurs (#71), fonctions pures : réglages lus dans le détail
 * d'un conteneur Docker (/containers/<id>/json), la spécification d'un service Swarm ou le modèle
 * de pod d'une charge Kubernetes, traduits en écarts (code, détail).
 *
 * Seuls les champs de sécurité sont lus : mode privilégié, utilisateur, réseau, PID et IPC de
 * l'hôte, capacités ajoutées, limites de mémoire et de processeur, système de fichiers en lecture
 * seule, montages de l'hôte (chemin source). Jamais les variables d'environnement, ni les
 * commandes, ni le contenu des secrets.
 *
 * Rien n'est corrigé ni signalé à la plateforme : le plugin constate, la personne décide (exception
 * justifiée et datée, voir HardeningException).
 *
 * @phpstan-type Finding array{code: string, detail: string}
 */
final class HardeningMapper
{
    public const SEVERITY_CRITICAL = 'critical';
    public const SEVERITY_HIGH = 'high';
    public const SEVERITY_MEDIUM = 'medium';
    public const SEVERITY_LOW = 'low';

    /** Du plus grave au moins grave. */
    public const SEVERITIES = [self::SEVERITY_CRITICAL, self::SEVERITY_HIGH, self::SEVERITY_MEDIUM, self::SEVERITY_LOW];

    public const PRIVILEGED = 'privileged';
    public const DOCKER_SOCKET = 'docker_socket';
    public const HOST_NETWORK = 'host_network';
    public const HOST_PID = 'host_pid';
    public const DANGEROUS_CAPABILITIES = 'dangerous_capabilities';
    public const SENSITIVE_MOUNT = 'sensitive_mount';
    public const HOST_IPC = 'host_ipc';
    public const ADDED_CAPABILITIES = 'added_capabilities';
    public const ROOT_USER = 'root_user';
    public const NO_MEMORY_LIMIT = 'no_memory_limit';
    public const NO_CPU_LIMIT = 'no_cpu_limit';
    public const WRITABLE_ROOTFS = 'writable_rootfs';

    /** Écart => gravité, dans l'ordre d'affichage. */
    public const RULES = [
        self::PRIVILEGED             => self::SEVERITY_CRITICAL,
        self::DOCKER_SOCKET          => self::SEVERITY_CRITICAL,
        self::HOST_NETWORK           => self::SEVERITY_HIGH,
        self::HOST_PID               => self::SEVERITY_HIGH,
        self::DANGEROUS_CAPABILITIES => self::SEVERITY_HIGH,
        self::SENSITIVE_MOUNT        => self::SEVERITY_HIGH,
        self::HOST_IPC               => self::SEVERITY_MEDIUM,
        self::ADDED_CAPABILITIES     => self::SEVERITY_MEDIUM,
        self::ROOT_USER              => self::SEVERITY_MEDIUM,
        self::NO_MEMORY_LIMIT        => self::SEVERITY_LOW,
        self::NO_CPU_LIMIT           => self::SEVERITY_LOW,
        self::WRITABLE_ROOTFS        => self::SEVERITY_LOW,
    ];

    /** Capacités qui donnent la main sur l'hôte ou ses autres conteneurs. */
    public const DANGEROUS_CAPS = [
        'ALL', 'SYS_ADMIN', 'NET_ADMIN', 'SYS_PTRACE', 'SYS_MODULE', 'SYS_RAWIO', 'DAC_READ_SEARCH',
        'SYS_BOOT', 'SYS_TIME', 'BPF', 'PERFMON', 'MAC_ADMIN', 'MAC_OVERRIDE',
    ];

    /** Sockets des moteurs de conteneurs : qui les monte pilote l'hôte. */
    private const SOCKETS = ['docker.sock', 'containerd.sock', 'crio.sock', 'podman.sock', 'dockershim.sock'];

    /** Répertoires de l'hôte sensibles en eux-mêmes (le répertoire seul, pas son contenu). */
    private const SENSITIVE_EXACT = ['/', '/var', '/run', '/var/run', '/home'];

    /** Répertoires de l'hôte sensibles, avec tout leur contenu. */
    private const SENSITIVE_TREES = [
        '/etc', '/root', '/proc', '/sys', '/boot', '/dev', '/usr', '/lib', '/lib64', '/bin', '/sbin',
        '/var/lib/docker', '/var/lib/kubelet', '/var/lib/containerd',
    ];

    private const DETAIL_MAX = 255;

    /**
     * Détail d'un conteneur Docker (hôte autonome) : HostConfig.{Privileged, NetworkMode, PidMode,
     * IpcMode, CapAdd, Memory, NanoCpus, CpuQuota, ReadonlyRootfs, Binds}, Config.User,
     * Mounts[].{Type, Source}. Rien d'autre n'est lu.
     *
     * @param array<mixed> $inspect Réponse de /docker/containers/<id>/json.
     * @return list<Finding>
     */
    public static function fromContainerInspect(array $inspect): array
    {
        $host = self::arr($inspect['HostConfig'] ?? null);
        $config = self::arr($inspect['Config'] ?? null);
        $findings = [];
        if (($host['Privileged'] ?? false) === true) {
            $findings[] = self::finding(self::PRIVILEGED);
        }
        $modes = [
            'NetworkMode' => self::HOST_NETWORK,
            'PidMode'     => self::HOST_PID,
            'IpcMode'     => self::HOST_IPC,
        ];
        foreach ($modes as $field => $code) {
            if (strtolower(self::str($host[$field] ?? null)) === 'host') {
                $findings[] = self::finding($code);
            }
        }
        $findings = array_merge($findings, self::capabilityFindings(self::strings(self::arr($host['CapAdd'] ?? null))));

        $sources = [];
        foreach (self::arr($inspect['Mounts'] ?? null) as $mount) {
            $mount = self::arr($mount);
            if (strtolower(self::str($mount['Type'] ?? null)) === 'bind') {
                $sources[] = self::str($mount['Source'] ?? null);
            }
        }
        if (!array_key_exists('Mounts', $inspect)) {
            // Ancienne forme « source:destination[:options] ».
            foreach (self::strings(self::arr($host['Binds'] ?? null)) as $bind) {
                $source = explode(':', $bind, 2)[0];
                if (str_starts_with($source, '/')) {
                    $sources[] = $source;
                }
            }
        }
        $findings = array_merge($findings, self::mountFindings($sources));

        if (self::isRootUser(self::str($config['User'] ?? null))) {
            $findings[] = self::finding(self::ROOT_USER, self::str($config['User'] ?? null));
        }
        if ((int) ($host['Memory'] ?? 0) <= 0) {
            $findings[] = self::finding(self::NO_MEMORY_LIMIT);
        }
        if ((int) ($host['NanoCpus'] ?? 0) <= 0 && (int) ($host['CpuQuota'] ?? 0) <= 0) {
            $findings[] = self::finding(self::NO_CPU_LIMIT);
        }
        if (($host['ReadonlyRootfs'] ?? false) !== true) {
            $findings[] = self::finding(self::WRITABLE_ROOTFS);
        }

        return self::sort($findings);
    }

    /**
     * Service Swarm (/docker/services) : Spec.TaskTemplate.ContainerSpec.{User, CapabilityAdd,
     * ReadOnly, Mounts[].{Type, Source}}, Spec.TaskTemplate.Resources.Limits.{MemoryBytes, NanoCPUs},
     * réseaux (réseau « host »). Swarm ne connaît ni le mode privilégié ni le PID de l'hôte.
     *
     * @param array<mixed> $service
     * @param array<string, string> $networkNames Identifiant de réseau => nom.
     * @return list<Finding>
     */
    public static function fromSwarmService(array $service, array $networkNames = []): array
    {
        $spec = self::arr($service['Spec'] ?? null);
        $template = self::arr($spec['TaskTemplate'] ?? null);
        $container = self::arr($template['ContainerSpec'] ?? null);
        $limits = self::arr(self::arr($template['Resources'] ?? null)['Limits'] ?? null);
        $findings = [];

        $networks = self::arr($template['Networks'] ?? null) ?: self::arr($spec['Networks'] ?? null);
        foreach ($networks as $network) {
            $target = self::str(self::arr($network)['Target'] ?? null);
            if ($target !== '' && strtolower($networkNames[$target] ?? $target) === 'host') {
                $findings[] = self::finding(self::HOST_NETWORK);
                break;
            }
        }
        $findings = array_merge(
            $findings,
            self::capabilityFindings(self::strings(self::arr($container['CapabilityAdd'] ?? null)))
        );
        $sources = [];
        foreach (self::arr($container['Mounts'] ?? null) as $mount) {
            $mount = self::arr($mount);
            if (strtolower(self::str($mount['Type'] ?? null)) === 'bind') {
                $sources[] = self::str($mount['Source'] ?? null);
            }
        }
        $findings = array_merge($findings, self::mountFindings($sources));
        $user = self::str($container['User'] ?? null);
        if (self::isRootUser($user)) {
            $findings[] = self::finding(self::ROOT_USER, $user);
        }
        if ((int) ($limits['MemoryBytes'] ?? 0) <= 0) {
            $findings[] = self::finding(self::NO_MEMORY_LIMIT);
        }
        if ((int) ($limits['NanoCPUs'] ?? 0) <= 0) {
            $findings[] = self::finding(self::NO_CPU_LIMIT);
        }
        if (($container['ReadOnly'] ?? false) !== true) {
            $findings[] = self::finding(self::WRITABLE_ROOTFS);
        }

        return self::sort($findings);
    }

    /**
     * Conteneur d'un modèle de pod Kubernetes : spec.{hostNetwork, hostPID, hostIPC,
     * securityContext.{runAsUser, runAsNonRoot}, volumes[].hostPath.path} et, pour le conteneur,
     * securityContext.{privileged, runAsUser, runAsNonRoot, readOnlyRootFilesystem, capabilities.add},
     * resources.limits.{memory, cpu}, volumeMounts[].name.
     *
     * @param array<mixed> $podSpec
     * @param array<mixed> $container
     * @return list<Finding>
     */
    public static function fromPodSpec(array $podSpec, array $container): array
    {
        $podSecurity = self::arr($podSpec['securityContext'] ?? null);
        $security = self::arr($container['securityContext'] ?? null);
        $limits = self::arr(self::arr($container['resources'] ?? null)['limits'] ?? null);
        $findings = [];
        if (($security['privileged'] ?? false) === true) {
            $findings[] = self::finding(self::PRIVILEGED);
        }
        $modes = ['hostNetwork' => self::HOST_NETWORK, 'hostPID' => self::HOST_PID, 'hostIPC' => self::HOST_IPC];
        foreach ($modes as $field => $code) {
            if (($podSpec[$field] ?? false) === true) {
                $findings[] = self::finding($code);
            }
        }
        $findings = array_merge($findings, self::capabilityFindings(
            self::strings(self::arr(self::arr($security['capabilities'] ?? null)['add'] ?? null))
        ));

        // Volumes hostPath montés par ce conteneur.
        $hostPaths = [];
        foreach (self::arr($podSpec['volumes'] ?? null) as $volume) {
            $volume = self::arr($volume);
            $path = self::str(self::arr($volume['hostPath'] ?? null)['path'] ?? null);
            if ($path !== '') {
                $hostPaths[self::str($volume['name'] ?? null)] = $path;
            }
        }
        $sources = [];
        foreach (self::arr($container['volumeMounts'] ?? null) as $mount) {
            $name = self::str(self::arr($mount)['name'] ?? null);
            if (isset($hostPaths[$name])) {
                $sources[] = $hostPaths[$name];
            }
        }
        $findings = array_merge($findings, self::mountFindings($sources));

        $runAsUser = $security['runAsUser'] ?? $podSecurity['runAsUser'] ?? null;
        $runAsNonRoot = $security['runAsNonRoot'] ?? $podSecurity['runAsNonRoot'] ?? null;
        if (is_int($runAsUser) || (is_string($runAsUser) && ctype_digit($runAsUser))) {
            if ((int) $runAsUser === 0) {
                $findings[] = self::finding(self::ROOT_USER, '0');
            }
        } elseif ($runAsNonRoot !== true) {
            $findings[] = self::finding(self::ROOT_USER);
        }
        if (self::str($limits['memory'] ?? null) === '') {
            $findings[] = self::finding(self::NO_MEMORY_LIMIT);
        }
        if (self::str($limits['cpu'] ?? null) === '') {
            $findings[] = self::finding(self::NO_CPU_LIMIT);
        }
        if (($security['readOnlyRootFilesystem'] ?? false) !== true) {
            $findings[] = self::finding(self::WRITABLE_ROOTFS);
        }

        return self::sort($findings);
    }

    /**
     * Utilisateur d'exécution root : aucun utilisateur défini (celui de l'image, root le plus
     * souvent), « root » ou l'identifiant 0, avec ou sans groupe.
     */
    public static function isRootUser(string $user): bool
    {
        $name = strtolower(explode(':', trim($user), 2)[0]);

        return $name === '' || $name === 'root' || $name === '0';
    }

    /**
     * Capacités ajoutées (« CAP_SYS_ADMIN » ou « SYS_ADMIN ») : les dangereuses à part.
     *
     * @param list<string> $capabilities
     * @return list<Finding>
     */
    public static function capabilityFindings(array $capabilities): array
    {
        $dangerous = [];
        $others = [];
        foreach ($capabilities as $capability) {
            $name = strtoupper(trim($capability));
            if (str_starts_with($name, 'CAP_')) {
                $name = substr($name, 4);
            }
            if ($name === '' || preg_match('/^[A-Z0-9_]+$/', $name) !== 1) {
                continue;
            }
            if (in_array($name, self::DANGEROUS_CAPS, true)) {
                $dangerous[$name] = true;
            } else {
                $others[$name] = true;
            }
        }
        $findings = [];
        if ($dangerous !== []) {
            $findings[] = self::finding(self::DANGEROUS_CAPABILITIES, implode(', ', array_keys($dangerous)));
        }
        if ($others !== []) {
            $findings[] = self::finding(self::ADDED_CAPABILITIES, implode(', ', array_keys($others)));
        }

        return $findings;
    }

    /**
     * Montages de l'hôte : socket d'un moteur de conteneurs, répertoire sensible.
     *
     * @param list<string> $sources Chemins sur l'hôte.
     * @return list<Finding>
     */
    public static function mountFindings(array $sources): array
    {
        $sockets = [];
        $sensitive = [];
        foreach ($sources as $source) {
            $path = self::normalizePath($source);
            if ($path === '') {
                continue;
            }
            if (in_array(basename($path), self::SOCKETS, true)) {
                $sockets[$path] = true;
            } elseif (self::isSensitivePath($path)) {
                $sensitive[$path] = true;
            }
        }
        $findings = [];
        if ($sockets !== []) {
            $findings[] = self::finding(self::DOCKER_SOCKET, implode(', ', array_keys($sockets)));
        }
        if ($sensitive !== []) {
            $findings[] = self::finding(self::SENSITIVE_MOUNT, implode(', ', array_keys($sensitive)));
        }

        return $findings;
    }

    public static function isSensitivePath(string $path): bool
    {
        $path = self::normalizePath($path);
        if ($path === '') {
            return false;
        }
        if (in_array($path, self::SENSITIVE_EXACT, true)) {
            return true;
        }
        foreach (self::SENSITIVE_TREES as $tree) {
            if ($path === $tree || str_starts_with($path, $tree . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Écarts de plusieurs conteneurs d'un même service (Compose) : un par code, détails réunis.
     *
     * @param list<list<Finding>> $lists
     * @return list<Finding>
     */
    public static function merge(array $lists): array
    {
        $details = [];
        foreach ($lists as $findings) {
            foreach ($findings as $finding) {
                $details[$finding['code']] ??= [];
                foreach (explode(', ', $finding['detail']) as $part) {
                    if ($part !== '') {
                        $details[$finding['code']][$part] = true;
                    }
                }
            }
        }
        $merged = [];
        foreach ($details as $code => $parts) {
            $merged[] = self::finding((string) $code, implode(', ', array_keys($parts)));
        }

        return self::sort($merged);
    }

    /**
     * Gravité d'un écart (basse pour un code inconnu).
     */
    public static function severity(string $code): string
    {
        return self::RULES[$code] ?? self::SEVERITY_LOW;
    }

    /**
     * Gravité la plus haute d'une liste, '' si elle est vide.
     *
     * @param list<string> $codes
     */
    public static function worst(array $codes): string
    {
        foreach (self::SEVERITIES as $severity) {
            foreach ($codes as $code) {
                if (self::severity($code) === $severity) {
                    return $severity;
                }
            }
        }

        return '';
    }

    /**
     * Écarts enregistrés : « [] » quand le service a été analysé sans écart.
     *
     * @param list<Finding> $findings
     */
    public static function encode(array $findings): string
    {
        return (string) json_encode($findings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Écarts enregistrés, lus de façon défensive ; null si le service n'a jamais été analysé.
     *
     * @return list<Finding>|null
     */
    public static function decode(?string $json): ?array
    {
        if ($json === null || trim($json) === '') {
            return null;
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return null;
        }
        $findings = [];
        foreach ($data as $finding) {
            if (!is_array($finding) || !isset(self::RULES[$finding['code'] ?? null])) {
                continue;
            }
            $findings[] = self::finding((string) $finding['code'], self::str($finding['detail'] ?? null));
        }

        return self::sort($findings);
    }

    /**
     * Écarts d'un service face à ses exceptions : une exception couvre l'écart jusqu'à sa date de
     * fin incluse ; expirée, l'écart compte de nouveau.
     *
     * @param list<Finding> $findings
     * @param array<string, string> $exceptions Code => date de fin (AAAA-MM-JJ).
     * @return list<array{code: string, detail: string, severity: string, exception: string}>
     *     `exception` : « active », « expired » ou ''.
     */
    public static function evaluate(array $findings, array $exceptions, string $today): array
    {
        $rows = [];
        foreach ($findings as $finding) {
            $end = $exceptions[$finding['code']] ?? null;
            $state = '';
            if ($end !== null) {
                $state = substr($end, 0, 10) >= substr($today, 0, 10) ? 'active' : 'expired';
            }
            $rows[] = $finding + ['severity' => self::severity($finding['code']), 'exception' => $state];
        }

        return $rows;
    }

    /**
     * @param list<Finding> $findings
     * @return list<Finding>
     */
    private static function sort(array $findings): array
    {
        $order = array_flip(array_keys(self::RULES));
        $unique = [];
        foreach ($findings as $finding) {
            $unique[$finding['code']] ??= $finding;
        }
        $unique = array_values($unique);
        usort(
            $unique,
            static fn (array $a, array $b): int => ($order[$a['code']] ?? 99) <=> ($order[$b['code']] ?? 99)
        );

        return $unique;
    }

    /**
     * @return Finding
     */
    private static function finding(string $code, string $detail = ''): array
    {
        return ['code' => $code, 'detail' => mb_substr($detail, 0, self::DETAIL_MAX)];
    }

    private static function normalizePath(string $path): string
    {
        $path = trim($path);
        if ($path === '' || !str_starts_with($path, '/')) {
            return '';
        }
        $path = (string) preg_replace('#/+#', '/', $path);

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    /**
     * @param array<mixed> $values
     * @return list<string>
     */
    private static function strings(array $values): array
    {
        $strings = [];
        foreach ($values as $value) {
            if (is_scalar($value) && trim((string) $value) !== '') {
                $strings[] = trim((string) $value);
            }
        }

        return $strings;
    }

    /**
     * @return array<mixed>
     */
    private static function arr(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}

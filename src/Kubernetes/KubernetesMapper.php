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

namespace GlpiPlugin\Dockerinventory\Kubernetes;

use GlpiPlugin\Dockerinventory\Portainer\PortainerMapper;

/**
 * Réponses de l'API Kubernetes => valeurs enregistrées par le plugin (fonctions pures, lecture
 * défensive : champ absent toléré, champ inconnu ignoré).
 *
 * - Namespace => stack de nature « namespace » (application GLPI s'il a au moins une charge).
 * - Deployment, StatefulSet, DaemonSet, CronJob => une fiche de service par conteneur du modèle de
 *   pod (les init containers ne sont pas inventoriés) : image, tag, empreinte lue dans le statut
 *   des pods (imageID), réplicas voulus et prêts, état, ports, noms des secrets et des volumes
 *   persistants, jamais les variables d'environnement.
 * - Pod => rattaché à sa charge par ses propriétaires (ReplicaSet => Deployment, Job => CronJob
 *   par le nom du Job « <cronjob>-<horodatage> », les Jobs n'étant pas lus).
 * - Nœud => nom, rôle (control-plane ou worker), état (Ready), version du kubelet, adresses.
 *
 * @phpstan-type Workload array{service_uid: string, name: string, stack_name: string, kind: string,
 *     image: string, tag: string, digest: string, mode: string, replicas: int|null,
 *     running_replicas: int, ports: string, networks: string, secrets: string, volumes: string,
 *     state: string, restart_policy: string, container_uids: string, container_names: string,
 *     placement: array<string, int>}
 * @phpstan-type PodIndex array<string, array{digests: array<string, array<string, int>>,
 *     placement: array<string, int>}>
 * @phpstan-type K8sNode array{node_uid: string, hostname: string, role: string, availability: string,
 *     state: string, engine_version: string, address: string, addresses: list<string>}
 */
final class KubernetesMapper
{
    public const STACK_KIND = 'namespace';

    public const KIND_DEPLOYMENT = 'deployment';
    public const KIND_STATEFULSET = 'statefulset';
    public const KIND_DAEMONSET = 'daemonset';
    public const KIND_CRONJOB = 'cronjob';

    /** Nature de la charge => ressource de l'API qui la liste. */
    public const WORKLOAD_RESOURCES = [
        self::KIND_DEPLOYMENT  => 'deployments',
        self::KIND_STATEFULSET => 'statefulsets',
        self::KIND_DAEMONSET   => 'daemonsets',
        self::KIND_CRONJOB     => 'cronjobs',
    ];

    /** Namespaces du système ignorés par défaut (réglage de la connexion). */
    public const DEFAULT_EXCLUDED = 'kube-system, kube-public, kube-node-lease';

    /** États d'une charge (colonne `state`). */
    public const STATE_AVAILABLE = 'k8s_available';
    public const STATE_PROGRESSING = 'k8s_progressing';
    public const STATE_DEGRADED = 'k8s_degraded';
    public const STATE_UNAVAILABLE = 'k8s_unavailable';
    public const STATE_SCALED_DOWN = 'k8s_scaled_down';
    public const STATE_SUSPENDED = 'k8s_suspended';
    public const STATE_ACTIVE = 'k8s_active';
    public const STATE_SCHEDULED = 'k8s_scheduled';

    public const ROLE_CONTROL_PLANE = 'k8s_control_plane';
    public const ROLE_WORKER = 'k8s_worker';

    private const UID_MAX = 255;

    /**
     * @return list<string>
     */
    public static function workloadKinds(): array
    {
        return array_keys(self::WORKLOAD_RESOURCES);
    }

    /**
     * Motifs des namespaces ignorés : noms séparés par des virgules, des espaces ou des retours à la
     * ligne ; « * » accepté (« cattle-* »).
     *
     * @return list<string>
     */
    public static function excludedPatterns(string $text): array
    {
        $patterns = preg_split('/[\s,;]+/', strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter(
            $patterns,
            static fn (string $p): bool => preg_match('/^[a-z0-9*?.-]{1,63}$/', $p) === 1
        )));
    }

    /**
     * Texte enregistré du réglage : motifs valides, une virgule entre chacun.
     */
    public static function normalizeExcluded(string $text): string
    {
        return mb_substr(implode(', ', self::excludedPatterns($text)), 0, 4000);
    }

    /**
     * @param list<string> $patterns
     */
    public static function isExcluded(string $namespace, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (fnmatch($pattern, $namespace)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Noms des namespaces d'une liste.
     *
     * @param list<array<string, mixed>> $items
     * @return list<string>
     */
    public static function namespaceNames(array $items): array
    {
        $names = [];
        foreach ($items as $item) {
            $name = self::str(self::arr($item['metadata'] ?? null)['name'] ?? null);
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * @param array<string, mixed> $node
     * @return K8sNode|null
     */
    public static function mapNode(array $node): ?array
    {
        $metadata = self::arr($node['metadata'] ?? null);
        $name = self::str($metadata['name'] ?? null);
        if ($name === '') {
            return null;
        }
        $labels = self::arr($metadata['labels'] ?? null);
        $isControlPlane = array_key_exists('node-role.kubernetes.io/control-plane', $labels)
            || array_key_exists('node-role.kubernetes.io/master', $labels);
        $status = self::arr($node['status'] ?? null);
        $state = 'unknown';
        foreach (self::arr($status['conditions'] ?? null) as $condition) {
            $condition = self::arr($condition);
            if (self::str($condition['type'] ?? null) === 'Ready') {
                $state = match (self::str($condition['status'] ?? null)) {
                    'True'  => 'ready',
                    'False' => 'not_ready',
                    default => 'unknown',
                };
            }
        }
        $addresses = [];
        foreach (['InternalIP', 'ExternalIP'] as $type) {
            foreach (self::arr($status['addresses'] ?? null) as $address) {
                $address = self::arr($address);
                $value = self::str($address['address'] ?? null);
                if (self::str($address['type'] ?? null) === $type && $value !== '') {
                    $addresses[] = $value;
                }
            }
        }
        $info = self::arr($status['nodeInfo'] ?? null);
        $kubelet = self::str($info['kubeletVersion'] ?? null);
        $runtime = self::str($info['containerRuntimeVersion'] ?? null);
        $uid = self::str($metadata['uid'] ?? null);
        $cordoned = (bool) (self::arr($node['spec'] ?? null)['unschedulable'] ?? false);

        return [
            'node_uid'       => mb_substr($uid !== '' ? $uid : 'k8s:' . $name, 0, 64),
            'hostname'       => mb_substr($name, 0, 255),
            'role'           => $isControlPlane ? self::ROLE_CONTROL_PLANE : self::ROLE_WORKER,
            'availability'   => $cordoned ? 'cordoned' : 'active',
            'state'          => $state,
            'engine_version' => mb_substr(trim($kubelet . ($runtime !== '' ? ' (' . $runtime . ')' : '')), 0, 64),
            'address'        => mb_substr($addresses[0] ?? '', 0, 255),
            'addresses'      => array_values(array_unique($addresses)),
        ];
    }

    /**
     * Empreinte du dépôt lue dans l'imageID d'un conteneur (« docker.io/library/nginx@sha256:… »,
     * « docker-pullable://… »). Un identifiant d'image local (« sha256:… » seul) n'est pas une
     * empreinte du registre : vide.
     */
    public static function digestOf(string $imageId): string
    {
        $at = strrpos($imageId, '@');
        if ($at === false) {
            return '';
        }
        $digest = substr($imageId, $at + 1);

        return preg_match('/^sha256:[0-9a-f]{64}$/', $digest) === 1 ? $digest : '';
    }

    /**
     * Pods rattachés à leur charge : empreintes vues par conteneur (et sur combien de pods) et pods
     * en cours par nœud.
     *
     * @param list<array<string, mixed>> $pods
     * @param list<array<string, mixed>> $replicaSets
     * @param array<string, list<string>> $cronJobs Namespace => noms des CronJobs.
     * @return PodIndex Clé « nature/namespace/nom ».
     */
    public static function podIndex(array $pods, array $replicaSets, array $cronJobs): array
    {
        $deploymentOf = [];
        foreach ($replicaSets as $rs) {
            $metadata = self::arr($rs['metadata'] ?? null);
            $owner = self::controller($metadata);
            if ($owner !== null && $owner['kind'] === 'Deployment') {
                $key = self::str($metadata['namespace'] ?? null) . '/' . self::str($metadata['name'] ?? null);
                $deploymentOf[$key] = $owner['name'];
            }
        }

        $index = [];
        foreach ($pods as $pod) {
            $metadata = self::arr($pod['metadata'] ?? null);
            $namespace = self::str($metadata['namespace'] ?? null);
            $owner = self::controller($metadata);
            if ($owner === null || $namespace === '') {
                continue;
            }
            $workload = match ($owner['kind']) {
                'ReplicaSet'  => isset($deploymentOf[$namespace . '/' . $owner['name']])
                    ? [self::KIND_DEPLOYMENT, $deploymentOf[$namespace . '/' . $owner['name']]]
                    : self::deploymentFromHash($owner['name'], $metadata),
                'StatefulSet' => [self::KIND_STATEFULSET, $owner['name']],
                'DaemonSet'   => [self::KIND_DAEMONSET, $owner['name']],
                'Job'         => self::cronJobOf($owner['name'], $cronJobs[$namespace] ?? []),
                default       => null,
            };
            if ($workload === null) {
                continue;
            }
            $key = $workload[0] . '/' . $namespace . '/' . $workload[1];
            $index[$key] ??= ['digests' => [], 'placement' => []];
            $status = self::arr($pod['status'] ?? null);
            foreach (self::arr($status['containerStatuses'] ?? null) as $container) {
                $container = self::arr($container);
                $name = self::str($container['name'] ?? null);
                $digest = self::digestOf(self::str($container['imageID'] ?? null));
                if ($name !== '' && $digest !== '') {
                    $index[$key]['digests'][$name][$digest] = ($index[$key]['digests'][$name][$digest] ?? 0) + 1;
                }
            }
            $node = self::str(self::arr($pod['spec'] ?? null)['nodeName'] ?? null);
            if ($node !== '' && self::str($status['phase'] ?? null) === 'Running') {
                $index[$key]['placement'][$node] = ($index[$key]['placement'][$node] ?? 0) + 1;
            }
        }

        return $index;
    }

    /**
     * Noms des CronJobs par namespace (rattachement des pods de leurs Jobs).
     *
     * @param list<array<string, mixed>> $items
     * @return array<string, list<string>>
     */
    public static function cronJobNames(array $items): array
    {
        $names = [];
        foreach ($items as $item) {
            $metadata = self::arr($item['metadata'] ?? null);
            $namespace = self::str($metadata['namespace'] ?? null);
            $name = self::str($metadata['name'] ?? null);
            if ($namespace !== '' && $name !== '') {
                $names[$namespace][] = $name;
            }
        }

        return $names;
    }

    /**
     * Charges d'une liste (Deployments, StatefulSets, DaemonSets ou CronJobs) : une entrée par
     * conteneur du modèle de pod.
     *
     * @param list<array<string, mixed>> $items
     * @param PodIndex $pods
     * @param list<string> $excluded Motifs des namespaces ignorés.
     * @return list<Workload>
     */
    public static function mapWorkloads(string $kind, array $items, array $pods, array $excluded = []): array
    {
        $workloads = [];
        foreach ($items as $item) {
            $metadata = self::arr($item['metadata'] ?? null);
            $name = self::str($metadata['name'] ?? null);
            $namespace = self::str($metadata['namespace'] ?? null);
            if ($name === '' || $namespace === '' || self::isExcluded($namespace, $excluded)) {
                continue;
            }
            $spec = self::arr($item['spec'] ?? null);
            $status = self::arr($item['status'] ?? null);
            $template = $kind === self::KIND_CRONJOB
                ? self::arr(self::arr(self::arr($spec['jobTemplate'] ?? null)['spec'] ?? null)['template'] ?? null)
                : self::arr($spec['template'] ?? null);
            $podSpec = self::arr($template['spec'] ?? null);
            $containers = [];
            foreach (self::arr($podSpec['containers'] ?? null) as $container) {
                $container = self::arr($container);
                if (self::str($container['name'] ?? null) !== '') {
                    $containers[] = $container;
                }
            }
            [$replicas, $ready, $mode] = self::replicas($kind, $spec, $status);
            $state = self::state($kind, $metadata, $spec, $status, $replicas, $ready);
            $index = $pods[$kind . '/' . $namespace . '/' . $name] ?? ['digests' => [], 'placement' => []];
            $volumes = self::volumes($kind, $spec, $podSpec);
            foreach ($containers as $container) {
                $containerName = self::str($container['name']);
                $image = PortainerMapper::parseImage(self::str($container['image'] ?? null));
                $digest = $image['digest'] !== ''
                    ? $image['digest']
                    : self::mostSeen($index['digests'][$containerName] ?? []);
                $uid = 'k8s/' . $kind . '/' . $namespace . '/' . $name . '/' . $containerName;
                $workloads[] = [
                    'service_uid'      => mb_substr($uid, 0, self::UID_MAX),
                    'name'             => mb_substr(
                        count($containers) > 1 ? $name . '/' . $containerName : $name,
                        0,
                        255
                    ),
                    'stack_name'       => mb_substr($namespace, 0, 255),
                    'kind'             => $kind,
                    'image'            => mb_substr($image['image'], 0, 255),
                    'tag'              => mb_substr($image['tag'], 0, 255),
                    'digest'           => mb_substr($digest, 0, 255),
                    'mode'             => mb_substr($mode, 0, 32),
                    'replicas'         => $replicas,
                    'running_replicas' => $ready,
                    'ports'            => self::ports($container),
                    'networks'         => '',
                    'secrets'          => self::secrets($podSpec, $container),
                    'volumes'          => $volumes,
                    'state'            => $state,
                    'restart_policy'   => mb_substr(self::str($podSpec['restartPolicy'] ?? null), 0, 64),
                    'container_uids'   => '',
                    'container_names'  => $containerName,
                    'placement'        => $index['placement'],
                ];
            }
        }

        return $workloads;
    }

    /**
     * Réplicas voulus (null : sans objet, CronJob), réplicas prêts (Jobs actifs pour un CronJob) et
     * mode affiché (planification d'un CronJob).
     *
     * @param array<mixed> $spec
     * @param array<mixed> $status
     * @return array{0: int|null, 1: int, 2: string}
     */
    private static function replicas(string $kind, array $spec, array $status): array
    {
        return match ($kind) {
            self::KIND_DAEMONSET => [
                (int) ($status['desiredNumberScheduled'] ?? 0),
                (int) ($status['numberReady'] ?? 0),
                'global',
            ],
            self::KIND_CRONJOB => [
                null,
                count(self::arr($status['active'] ?? null)),
                self::str($spec['schedule'] ?? null),
            ],
            // Deployment, StatefulSet : 1 réplica si le champ est absent (défaut de Kubernetes).
            default => [
                array_key_exists('replicas', $spec) ? (int) $spec['replicas'] : 1,
                (int) ($status['readyReplicas'] ?? 0),
                'replicated',
            ],
        };
    }

    /**
     * @param array<mixed> $metadata
     * @param array<mixed> $spec
     * @param array<mixed> $status
     */
    private static function state(
        string $kind,
        array $metadata,
        array $spec,
        array $status,
        ?int $replicas,
        int $ready
    ): string {
        if ($kind === self::KIND_CRONJOB) {
            return match (true) {
                (bool) ($spec['suspend'] ?? false) => self::STATE_SUSPENDED,
                $ready > 0                         => self::STATE_ACTIVE,
                default                            => self::STATE_SCHEDULED,
            };
        }
        $desired = (int) $replicas;
        if ($desired === 0) {
            return self::STATE_SCALED_DOWN;
        }
        $updatedField = $kind === self::KIND_DAEMONSET ? 'updatedNumberScheduled' : 'updatedReplicas';
        // Champ absent (ancien StatefulSet) : rien n'est en cours de mise à jour.
        $updated = (int) ($status[$updatedField] ?? $desired);
        $generation = (int) ($metadata['generation'] ?? 0);
        $observed = (int) ($status['observedGeneration'] ?? $generation);
        if ($ready >= $desired && $updated >= $desired && $observed >= $generation) {
            return self::STATE_AVAILABLE;
        }
        if ($ready === 0) {
            return self::STATE_UNAVAILABLE;
        }
        if ($observed < $generation || $updated < $desired) {
            return self::STATE_PROGRESSING;
        }

        return self::STATE_DEGRADED;
    }

    /**
     * @param array<mixed> $container
     */
    private static function ports(array $container): string
    {
        $ports = [];
        foreach (self::arr($container['ports'] ?? null) as $port) {
            $port = self::arr($port);
            $number = (int) ($port['containerPort'] ?? 0);
            if ($number <= 0) {
                continue;
            }
            $host = (int) ($port['hostPort'] ?? 0);
            $protocol = self::str($port['protocol'] ?? null);
            $protocol = strtolower($protocol !== '' ? $protocol : 'TCP');
            $ports[] = ($host > 0 ? $host . ':' : '') . $number . '/' . $protocol;
        }

        return self::join($ports);
    }

    /**
     * Noms des secrets utilisés (volumes, variables par référence, accès aux registres), jamais
     * leurs valeurs ni les variables d'environnement.
     *
     * @param array<mixed> $podSpec
     * @param array<mixed> $container
     */
    private static function secrets(array $podSpec, array $container): string
    {
        $names = [];
        foreach (self::arr($podSpec['volumes'] ?? null) as $volume) {
            $names[] = self::str(self::arr(self::arr($volume)['secret'] ?? null)['secretName'] ?? null);
        }
        foreach (self::arr($container['envFrom'] ?? null) as $source) {
            $names[] = self::str(self::arr(self::arr($source)['secretRef'] ?? null)['name'] ?? null);
        }
        foreach (self::arr($container['env'] ?? null) as $variable) {
            $from = self::arr(self::arr($variable)['valueFrom'] ?? null);
            $names[] = self::str(self::arr($from['secretKeyRef'] ?? null)['name'] ?? null);
        }
        foreach (self::arr($podSpec['imagePullSecrets'] ?? null) as $pull) {
            $names[] = self::str(self::arr($pull)['name'] ?? null);
        }

        return self::join($names);
    }

    /**
     * Volumes persistants : demandes de volume (PVC) du modèle et modèles de demande d'un StatefulSet.
     *
     * @param array<mixed> $spec
     * @param array<mixed> $podSpec
     */
    private static function volumes(string $kind, array $spec, array $podSpec): string
    {
        $names = [];
        foreach (self::arr($podSpec['volumes'] ?? null) as $volume) {
            $claim = self::arr(self::arr($volume)['persistentVolumeClaim'] ?? null);
            $names[] = self::str($claim['claimName'] ?? null);
        }
        if ($kind === self::KIND_STATEFULSET) {
            foreach (self::arr($spec['volumeClaimTemplates'] ?? null) as $claim) {
                $names[] = self::str(self::arr(self::arr($claim)['metadata'] ?? null)['name'] ?? null);
            }
        }

        return self::join($names);
    }

    /**
     * @param array<string, int> $digests Empreinte => nombre de pods.
     */
    private static function mostSeen(array $digests): string
    {
        if ($digests === []) {
            return '';
        }
        arsort($digests);

        return (string) array_key_first($digests);
    }

    /**
     * Propriétaire « contrôleur » d'un objet (ownerReferences).
     *
     * @param array<mixed> $metadata
     * @return array{kind: string, name: string}|null
     */
    private static function controller(array $metadata): ?array
    {
        foreach (self::arr($metadata['ownerReferences'] ?? null) as $owner) {
            $owner = self::arr($owner);
            if ((bool) ($owner['controller'] ?? false)) {
                $kind = self::str($owner['kind'] ?? null);
                $name = self::str($owner['name'] ?? null);

                return $kind !== '' && $name !== '' ? ['kind' => $kind, 'name' => $name] : null;
            }
        }

        return null;
    }

    /**
     * ReplicaSet absent de la liste (créé entre les deux lectures) : nom du Deployment déduit du
     * suffixe « pod-template-hash ».
     *
     * @param array<mixed> $podMetadata
     * @return array{0: string, 1: string}|null
     */
    private static function deploymentFromHash(string $replicaSet, array $podMetadata): ?array
    {
        $hash = self::str(self::arr($podMetadata['labels'] ?? null)['pod-template-hash'] ?? null);
        if ($hash !== '' && str_ends_with($replicaSet, '-' . $hash)) {
            return [self::KIND_DEPLOYMENT, substr($replicaSet, 0, -strlen('-' . $hash))];
        }

        return null;
    }

    /**
     * CronJob d'un Job (« <cronjob>-<minutes> »), parmi ceux du namespace ; null pour un Job isolé.
     *
     * @param list<string> $cronJobs
     * @return array{0: string, 1: string}|null
     */
    private static function cronJobOf(string $job, array $cronJobs): ?array
    {
        if (preg_match('/^(.+)-\d+$/', $job, $m) === 1 && in_array($m[1], $cronJobs, true)) {
            return [self::KIND_CRONJOB, $m[1]];
        }

        return null;
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

    /**
     * @param list<string> $values
     */
    private static function join(array $values): string
    {
        $values = array_values(array_unique(array_filter($values, static fn (string $v): bool => $v !== '')));
        sort($values);

        return implode(', ', $values);
    }
}

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

namespace GlpiPlugin\Dockerinventory\Exposure;

/**
 * Exposition des services (#72), fonctions pures :
 * - routes Traefik lues dans les étiquettes d'un service Swarm ou d'un conteneur
 *   (`traefik.http.routers.<routeur>.rule`, `.tls`, `.tls.certresolver`, `.entrypoints`,
 *   `traefik.http.services.<service>.loadbalancer.server.port`, ancienne forme `traefik.frontend.rule`) ;
 * - ingress Kubernetes (hôtes, chemins, TLS) et services NodePort ou LoadBalancer, rattachés aux
 *   charges dont le modèle de pod porte toutes les étiquettes du sélecteur du service ;
 * - ports publiés enregistrés par l'import (« 8080:80/tcp », « 192.0.2.5:8080:80/tcp (host) ») ;
 * - portée de chaque exposition (Internet, réseau interne, hôte seul) et flux proposé pour la
 *   matrice des flux de GRC Manager.
 *
 * Rien n'est deviné au-delà de ce que la plateforme déclare : un nom d'hôte public est « Internet
 * (probable) », un port publié sur toutes les adresses de l'hôte est « réseau de l'hôte ».
 *
 * @phpstan-type Route array{source: string, host: string, path: string, tls: bool, port: int,
 *     protocol: string, name: string, entrypoints: string}
 * @phpstan-type Exposure array{source: string, host: string, path: string, tls: bool, port: int,
 *     target_port: int, protocol: string, name: string, entrypoints: string, scope: string}
 */
final class ExposureMapper
{
    public const SOURCE_PORT = 'port';
    public const SOURCE_TRAEFIK = 'traefik';
    public const SOURCE_INGRESS = 'ingress';
    public const SOURCE_K8S_SERVICE = 'k8s_service';

    public const SOURCES = [self::SOURCE_PORT, self::SOURCE_TRAEFIK, self::SOURCE_INGRESS, self::SOURCE_K8S_SERVICE];

    /** Nom d'hôte public ou adresse publique : sans doute joignable depuis Internet. */
    public const SCOPE_INTERNET = 'internet';

    /** Nom ou adresse d'un réseau interne, port de nœud Kubernetes. */
    public const SCOPE_INTERNAL = 'internal';

    /** Port publié sur toutes les adresses de l'hôte : joignable par tout réseau qui atteint l'hôte. */
    public const SCOPE_HOST = 'host';

    /** Port publié sur l'adresse de bouclage : joignable depuis l'hôte seulement. */
    public const SCOPE_LOCAL = 'local';

    public const SCOPES = [self::SCOPE_INTERNET, self::SCOPE_HOST, self::SCOPE_INTERNAL, self::SCOPE_LOCAL];

    /** Types de flux de la matrice de GRC Manager (NetworkFlow::getTypes()). */
    public const FLOW_INTERNET = 'internet_publication';
    public const FLOW_INTERNAL = 'internal';

    /** Suffixes de noms réservés aux réseaux internes. */
    private const INTERNAL_SUFFIXES = [
        '.local', '.lan', '.internal', '.intranet', '.intra', '.corp', '.home', '.home.arpa', '.localdomain',
        '.localhost', '.svc', '.cluster.local',
    ];

    private const ROUTER_LABEL = '/^traefik\.(http|tcp)\.routers\.([^.]+)\.(rule|tls|tls\.certresolver|entrypoints)$/';

    private const SERVICE_PORT_LABEL = '/^traefik\.(?:http|tcp)\.services\.[^.]+\.loadbalancer\.server\.port$/';

    private const PORT_ENTRY = '/^(?:(?<ip>\[[0-9a-f:]+\]|[0-9a-f.:]+):)?(?<published>\d+):(?<target>\d+)'
        . '\/(?<protocol>[a-z]+)(?: \(host\))?$/i';

    /** Au plus ce nombre de routes par service (étiquettes ou ingress). */
    public const MAX_ROUTES = 50;

    /**
     * Routes Traefik déclarées par des étiquettes. Rien si `traefik.enable` vaut « false ».
     *
     * @param array<mixed> $labels
     * @return list<Route>
     */
    public static function traefikRoutes(array $labels): array
    {
        if (strtolower(self::str($labels['traefik.enable'] ?? null)) === 'false') {
            return [];
        }
        $routers = [];
        $servicePorts = [];
        foreach ($labels as $key => $value) {
            $key = strtolower((string) $key);
            $value = self::str($value);
            if (preg_match(self::ROUTER_LABEL, $key, $m) === 1) {
                $routers[$m[1] . '/' . $m[2]][$m[3]] = $value;
            } elseif (preg_match(self::SERVICE_PORT_LABEL, $key) === 1) {
                $servicePorts[] = (int) $value;
            } elseif ($key === 'traefik.frontend.rule') {
                // Traefik 1 : « Host:a.exemple.test,b.exemple.test;PathPrefix:/api ».
                $routers['http/frontend']['rule'] = self::legacyRule($value);
            } elseif ($key === 'traefik.port') {
                $servicePorts[] = (int) $value;
            }
        }
        $port = count(array_unique($servicePorts)) === 1 ? max(0, $servicePorts[0]) : 0;
        ksort($routers);
        $routes = [];
        foreach ($routers as $id => $router) {
            [$protocol, $name] = explode('/', (string) $id, 2);
            $rule = $router['rule'] ?? '';
            $hosts = self::ruleValues($rule, $protocol === 'tcp' ? ['HostSNI'] : ['Host']);
            $paths = self::ruleValues($rule, ['PathPrefix', 'Path']);
            $tls = in_array(strtolower($router['tls'] ?? ''), ['true', '1'], true)
                || ($router['tls.certresolver'] ?? '') !== '';
            foreach ($hosts === [] ? [''] : $hosts as $host) {
                $routes[] = [
                    'source'      => self::SOURCE_TRAEFIK,
                    'host'        => $host === '*' ? '' : mb_substr(strtolower($host), 0, 255),
                    'path'        => mb_substr($paths[0] ?? '', 0, 255),
                    'tls'         => $tls,
                    'port'        => $port,
                    'protocol'    => 'tcp',
                    'name'        => mb_substr($name, 0, 128),
                    'entrypoints' => mb_substr($router['entrypoints'] ?? '', 0, 128),
                ];
            }
        }

        return array_slice($routes, 0, self::MAX_ROUTES);
    }

    /**
     * Routes Kubernetes par charge : clé « nature/namespace/nom » (voir workloadKey()).
     *
     * @param list<array<string, mixed>>|null $services Liste /api/v1/services, null si illisible.
     * @param list<array<string, mixed>>|null $ingresses Liste des ingress, null si illisible.
     * @param array<string, list<array<string, mixed>>> $workloads Nature => objets (deployments...).
     * @return array<string, list<Route>>
     */
    public static function kubernetesRoutes(?array $services, ?array $ingresses, array $workloads): array
    {
        // Étiquettes du modèle de pod de chaque charge, par namespace.
        $templates = [];
        foreach ($workloads as $kind => $items) {
            foreach ($items as $item) {
                $metadata = self::arr($item['metadata'] ?? null);
                $namespace = self::str($metadata['namespace'] ?? null);
                $name = self::str($metadata['name'] ?? null);
                if ($namespace === '' || $name === '') {
                    continue;
                }
                $spec = self::arr($item['spec'] ?? null);
                $template = $kind === 'cronjob'
                    ? self::arr(self::arr(self::arr($spec['jobTemplate'] ?? null)['spec'] ?? null)['template'] ?? null)
                    : self::arr($spec['template'] ?? null);
                $templates[$namespace][$kind . '/' . $namespace . '/' . $name]
                    = self::arr(self::arr($template['metadata'] ?? null)['labels'] ?? null);
            }
        }

        $routes = [];
        // Service Kubernetes => charges sélectionnées.
        $targets = [];
        foreach ($services ?? [] as $service) {
            $metadata = self::arr($service['metadata'] ?? null);
            $namespace = self::str($metadata['namespace'] ?? null);
            $name = self::str($metadata['name'] ?? null);
            $spec = self::arr($service['spec'] ?? null);
            $selector = self::arr($spec['selector'] ?? null);
            if ($namespace === '' || $name === '' || $selector === []) {
                continue;
            }
            $keys = [];
            foreach ($templates[$namespace] ?? [] as $key => $labels) {
                if (self::matches($selector, $labels)) {
                    $keys[] = $key;
                }
            }
            $targets[$namespace . '/' . $name] = $keys;

            $type = self::str($spec['type'] ?? null);
            if ($type !== 'NodePort' && $type !== 'LoadBalancer') {
                continue;
            }
            $addresses = [];
            $loadBalancer = self::arr(self::arr($service['status'] ?? null)['loadBalancer'] ?? null);
            foreach (self::arr($loadBalancer['ingress'] ?? null) as $lb) {
                $lb = self::arr($lb);
                $address = self::str($lb['hostname'] ?? null) ?: self::str($lb['ip'] ?? null);
                if ($address !== '') {
                    $addresses[] = strtolower($address);
                }
            }
            foreach (self::arr($spec['ports'] ?? null) as $port) {
                $port = self::arr($port);
                $protocol = strtolower(self::str($port['protocol'] ?? null) ?: 'tcp');
                $entries = [];
                $nodePort = (int) ($port['nodePort'] ?? 0);
                if ($nodePort > 0) {
                    $entries[] = ['', $nodePort, 'NodePort'];
                }
                if ($type === 'LoadBalancer') {
                    foreach ($addresses as $address) {
                        $entries[] = [$address, (int) ($port['port'] ?? 0), 'LoadBalancer'];
                    }
                }
                foreach ($entries as [$host, $number, $entry]) {
                    foreach ($keys as $key) {
                        $routes[$key][] = [
                            'source'      => self::SOURCE_K8S_SERVICE,
                            'host'        => mb_substr($host, 0, 255),
                            'path'        => '',
                            'tls'         => false,
                            'port'        => max(0, $number),
                            'protocol'    => in_array($protocol, ['tcp', 'udp'], true) ? $protocol : 'tcp',
                            'name'        => mb_substr($name, 0, 128),
                            'entrypoints' => $entry,
                        ];
                    }
                }
            }
        }

        foreach ($ingresses ?? [] as $ingress) {
            $metadata = self::arr($ingress['metadata'] ?? null);
            $namespace = self::str($metadata['namespace'] ?? null);
            $name = self::str($metadata['name'] ?? null);
            if ($namespace === '' || $name === '') {
                continue;
            }
            $spec = self::arr($ingress['spec'] ?? null);
            $tlsHosts = [];
            foreach (self::arr($spec['tls'] ?? null) as $tls) {
                foreach (self::arr(self::arr($tls)['hosts'] ?? null) as $host) {
                    $tlsHosts[] = strtolower(self::str($host));
                }
            }
            $class = self::str($spec['ingressClassName'] ?? null);
            $paths = [];
            foreach (self::arr($spec['rules'] ?? null) as $rule) {
                $rule = self::arr($rule);
                $host = strtolower(self::str($rule['host'] ?? null));
                foreach (self::arr(self::arr($rule['http'] ?? null)['paths'] ?? null) as $path) {
                    $path = self::arr($path);
                    $paths[] = [$host, self::str($path['path'] ?? null) ?: '/', self::arr($path['backend'] ?? null)];
                }
            }
            $default = self::arr($spec['defaultBackend'] ?? null);
            if ($default !== []) {
                $paths[] = ['', '/', $default];
            }
            foreach ($paths as [$host, $path, $backend]) {
                $backendService = self::arr($backend['service'] ?? null);
                $serviceName = self::str($backendService['name'] ?? null);
                $tls = $host !== '' ? in_array($host, $tlsHosts, true) : $tlsHosts !== [];
                foreach ($targets[$namespace . '/' . $serviceName] ?? [] as $key) {
                    $routes[$key][] = [
                        'source'      => self::SOURCE_INGRESS,
                        'host'        => mb_substr($host, 0, 255),
                        'path'        => mb_substr($path, 0, 255),
                        'tls'         => $tls,
                        'port'        => 0,
                        'protocol'    => 'tcp',
                        'name'        => mb_substr($name, 0, 128),
                        'entrypoints' => mb_substr($class, 0, 128),
                    ];
                }
            }
        }

        foreach ($routes as $key => $list) {
            $routes[$key] = array_slice(array_values(array_unique($list, SORT_REGULAR)), 0, self::MAX_ROUTES);
        }
        ksort($routes);

        return $routes;
    }

    /**
     * Clé « nature/namespace/nom » d'une charge, d'après l'identifiant de son service
     * (« k8s/nature/namespace/nom/conteneur »).
     */
    public static function workloadKey(string $serviceUid): string
    {
        $parts = explode('/', $serviceUid);
        if (count($parts) < 5 || $parts[0] !== 'k8s') {
            return '';
        }

        return $parts[1] . '/' . $parts[2] . '/' . $parts[3];
    }

    /**
     * Valeur enregistrée de la colonne `routes` (JSON).
     *
     * @param list<Route> $routes
     */
    public static function encode(array $routes): string
    {
        return $routes === [] ? '' : (string) json_encode($routes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Routes enregistrées, lues de façon défensive.
     *
     * @return list<Route>
     */
    public static function decode(string $json): array
    {
        $data = $json === '' ? [] : json_decode($json, true);
        if (!is_array($data)) {
            return [];
        }
        $routes = [];
        foreach ($data as $route) {
            if (!is_array($route) || !in_array($route['source'] ?? null, self::SOURCES, true)) {
                continue;
            }
            $routes[] = [
                'source'      => (string) $route['source'],
                'host'        => self::str($route['host'] ?? null),
                'path'        => self::str($route['path'] ?? null),
                'tls'         => (bool) ($route['tls'] ?? false),
                'port'        => (int) ($route['port'] ?? 0),
                'protocol'    => self::str($route['protocol'] ?? null) ?: 'tcp',
                'name'        => self::str($route['name'] ?? null),
                'entrypoints' => self::str($route['entrypoints'] ?? null),
            ];
        }

        return $routes;
    }

    /**
     * Expositions d'un service : ports publiés (colonne `ports`, sauf pour Kubernetes où ce sont les
     * ports des conteneurs) puis routes, avec leur portée.
     *
     * @return list<Exposure>
     */
    public static function exposures(string $ports, string $routesJson, bool $isKubernetes): array
    {
        $exposures = [];
        if (!$isKubernetes) {
            foreach ($ports === '' ? [] : explode(', ', $ports) as $entry) {
                $port = self::parsePort($entry);
                if ($port !== null) {
                    $exposures[] = $port;
                }
            }
        }
        foreach (self::decode($routesJson) as $route) {
            $isRoute = in_array($route['source'], [self::SOURCE_TRAEFIK, self::SOURCE_INGRESS], true);
            $scope = $route['host'] !== ''
                ? self::hostScope($route['host'])
                : ($route['source'] === self::SOURCE_K8S_SERVICE ? self::SCOPE_INTERNAL : self::SCOPE_HOST);
            $exposures[] = [
                'source'      => $route['source'],
                'host'        => $route['host'],
                'path'        => $route['path'],
                'tls'         => $route['tls'],
                // Port d'entrée d'une route HTTP : celui du point d'entrée, 443 avec TLS, 80 sinon.
                'port'        => $isRoute ? ($route['tls'] ? 443 : 80) : $route['port'],
                'target_port' => $isRoute ? $route['port'] : 0,
                'protocol'    => $route['protocol'],
                'name'        => $route['name'],
                'entrypoints' => $route['entrypoints'],
                'scope'       => $scope,
            ];
        }

        return $exposures;
    }

    /**
     * Port publié enregistré par l'import : « [adresse:]publié:cible/protocole[ (host)] ».
     *
     * @return Exposure|null
     */
    public static function parsePort(string $entry): ?array
    {
        if (preg_match(self::PORT_ENTRY, trim($entry), $m) !== 1 || (int) $m['published'] <= 0) {
            return null;
        }
        $ip = trim($m['ip'], '[]');
        $scope = match (true) {
            $ip === ''                         => self::SCOPE_HOST,
            self::isLoopback($ip)              => self::SCOPE_LOCAL,
            self::isPublicIp($ip)              => self::SCOPE_INTERNET,
            default                            => self::SCOPE_INTERNAL,
        };

        return [
            'source'      => self::SOURCE_PORT,
            'host'        => $ip,
            'path'        => '',
            'tls'         => false,
            'port'        => (int) $m['published'],
            'target_port' => (int) $m['target'],
            'protocol'    => strtolower((string) $m['protocol']),
            'name'        => '',
            'entrypoints' => '',
            'scope'       => $scope,
        ];
    }

    /**
     * Portée d'un nom d'hôte ou d'une adresse.
     */
    public static function hostScope(string $host): string
    {
        $host = strtolower(trim($host, " []\t."));
        if ($host === '' || $host === 'localhost' || self::isLoopback($host)) {
            return $host === '' ? self::SCOPE_HOST : self::SCOPE_LOCAL;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return self::isPublicIp($host) ? self::SCOPE_INTERNET : self::SCOPE_INTERNAL;
        }
        if (!str_contains($host, '.')) {
            return self::SCOPE_INTERNAL;
        }
        foreach (self::INTERNAL_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return self::SCOPE_INTERNAL;
            }
        }

        return self::SCOPE_INTERNET;
    }

    /**
     * Flux proposé pour la matrice des flux de GRC Manager, d'après une exposition. Les valeurs
     * suivent les champs de NetworkFlow ; rien n'est enregistré ici.
     *
     * @param Exposure $exposure
     * @return array{flow_type: string, name: string, source_label: string, destination_label: string,
     *     protocol: string, ports: string}|null Null pour un port joignable depuis l'hôte seul.
     */
    public static function proposal(
        array $exposure,
        string $serviceName,
        string $internetLabel,
        string $internalLabel
    ): ?array {
        if ($exposure['scope'] === self::SCOPE_LOCAL) {
            return null;
        }
        $internet = $exposure['scope'] === self::SCOPE_INTERNET;
        $destination = $exposure['host'] !== ''
            ? $exposure['host'] . ($exposure['path'] !== '' && $exposure['path'] !== '/' ? $exposure['path'] : '')
            : $serviceName;
        if ($exposure['host'] === '' || $exposure['source'] === self::SOURCE_PORT) {
            $destination = $serviceName . ($exposure['host'] !== '' ? ' (' . $exposure['host'] . ')' : '');
        }
        $source = $internet ? $internetLabel : $internalLabel;
        $port = $exposure['port'] > 0 ? (string) $exposure['port'] : '';

        return [
            'flow_type'         => $internet ? self::FLOW_INTERNET : self::FLOW_INTERNAL,
            'name'              => mb_substr(
                $source . ' → ' . $destination . ($port !== '' ? ':' . $port : ''),
                0,
                255
            ),
            'source_label'      => $source,
            'destination_label' => mb_substr($destination, 0, 255),
            'protocol'          => $exposure['protocol'] === 'udp' ? 'udp' : 'tcp',
            'ports'             => $port,
        ];
    }

    /**
     * Un flux enregistré dans GRC documente-t-il cette exposition ? Même destination (application
     * du service, ou libellé qui contient l'hôte ou le nom du service), port couvert et même protocole.
     *
     * @param array{flow_type: string, name: string, source_label: string, destination_label: string,
     *     protocol: string, ports: string} $proposal
     * @param list<array{destination_itemtype: string, destination_items_id: int, destination_label: string,
     *     protocol: string, ports: string}> $flows Flux actifs de la matrice.
     */
    public static function isDocumented(
        array $proposal,
        int $applianceId,
        string $host,
        string $serviceName,
        array $flows
    ): bool {
        foreach ($flows as $flow) {
            $label = strtolower($flow['destination_label']);
            $sameTarget = ($applianceId > 0 && $flow['destination_itemtype'] === 'Appliance'
                    && $flow['destination_items_id'] === $applianceId)
                || ($host !== '' && $label !== '' && str_contains($label, strtolower($host)))
                || ($serviceName !== '' && $label !== '' && str_contains($label, strtolower($serviceName)));
            if (!$sameTarget) {
                continue;
            }
            $protocol = $flow['protocol'];
            if (!in_array($protocol, ['any', 'tcp_udp', $proposal['protocol']], true)) {
                continue;
            }
            if (self::portCovered((int) $proposal['ports'], $flow['ports'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Le port est-il dans la liste canonique de GRC (« 80,443,8000-8100 » ou « any ») ?
     */
    public static function portCovered(int $port, string $ports): bool
    {
        $ports = strtolower(trim($ports));
        if ($ports === '' || $ports === 'any' || $port <= 0) {
            return true;
        }
        foreach (explode(',', $ports) as $part) {
            $part = trim($part);
            if (preg_match('/^(\d+)(?:-(\d+))?$/', $part, $m) !== 1) {
                continue;
            }
            $low = (int) $m[1];
            $high = isset($m[2]) ? (int) $m[2] : $low;
            if ($port >= $low && $port <= $high) {
                return true;
            }
        }

        return false;
    }

    /**
     * Valeurs des fonctions d'une règle Traefik : Host(`a`, `b`) || Host(`c`) => [a, b, c].
     *
     * @param list<string> $functions
     * @return list<string>
     */
    private static function ruleValues(string $rule, array $functions): array
    {
        $values = [];
        $names = implode('|', array_map('preg_quote', $functions));
        if (preg_match_all('/\b(?:' . $names . ')\(([^)]*)\)/', $rule, $calls) > 0) {
            foreach ($calls[1] as $arguments) {
                preg_match_all('/[`"\']([^`"\']+)[`"\']/', $arguments, $quoted);
                foreach ($quoted[1] as $value) {
                    $value = trim($value);
                    if ($value !== '' && !in_array($value, $values, true)) {
                        $values[] = $value;
                    }
                }
            }
        }

        return $values;
    }

    /**
     * Règle Traefik 1 (« Host:a,b;PathPrefix:/api ») réécrite à la manière de Traefik 2.
     */
    private static function legacyRule(string $rule): string
    {
        $parts = [];
        foreach (explode(';', $rule) as $matcher) {
            [$name, $values] = array_pad(explode(':', $matcher, 2), 2, '');
            $quoted = array_map(static fn (string $v): string => '`' . trim($v) . '`', explode(',', $values));
            $parts[] = trim($name) . '(' . implode(', ', $quoted) . ')';
        }

        return implode(' && ', $parts);
    }

    /**
     * @param array<mixed> $selector
     * @param array<mixed> $labels
     */
    private static function matches(array $selector, array $labels): bool
    {
        foreach ($selector as $key => $value) {
            if (self::str($labels[$key] ?? null) !== self::str($value)) {
                return false;
            }
        }

        return true;
    }

    private static function isLoopback(string $ip): bool
    {
        return str_starts_with($ip, '127.') || $ip === '::1';
    }

    private static function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        return self::isDocumentationIp($ip)
            || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /**
     * Adresses de documentation (RFC 5737, RFC 3849) : elles figurent des adresses publiques dans
     * les exemples et les jeux de données fictifs, quelle que soit la version de PHP.
     */
    private static function isDocumentationIp(string $ip): bool
    {
        foreach (['192.0.2.', '198.51.100.', '203.0.113.'] as $prefix) {
            if (str_starts_with($ip, $prefix)) {
                return true;
            }
        }

        return str_starts_with(strtolower($ip), '2001:db8:');
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

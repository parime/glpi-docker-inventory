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

namespace GlpiPlugin\Dockerinventory\Traefik;

use GlpiPlugin\Dockerinventory\Exposure\ExposureMapper;

/**
 * Configuration réellement appliquée par Traefik (lue par TraefikClient), mise en forme pour
 * l'affichage et comparée aux routes déclarées par les étiquettes des services inventoriés (#72).
 * Aucune lecture de base ni d'appel réseau ici : tout se teste sur des réponses fictives.
 *
 * Chaque route reçoit :
 * - sa portée (ExposureMapper::hostScope() de ses noms d'hôte, la plus large l'emporte) ;
 * - son rapprochement avec les étiquettes : déclarée, différente (noms d'hôte autres que ceux des
 *   étiquettes), non inventoriée (fournisseur Docker sans service inventorié), autre fournisseur
 *   (fichier, Kubernetes, Consul...), interne (tableau de bord, API de Traefik) ;
 * - des signalements, jamais corrigés par le plugin : route publique sans TLS ni redirection vers
 *   HTTPS, route d'administration sans authentification, route publique sans en-têtes de sécurité,
 *   tableau de bord ou API de Traefik exposés sans authentification (api.insecure).
 *
 * @phpstan-type Declared array{name: string, host: string, service_id: int, service: string}
 * @phpstan-type TraefikRoute array{name: string, provider: string, protocol: string, rule: string,
 *     hosts: list<string>, paths: list<string>, entrypoints: list<string>, tls: bool, service: string,
 *     servers: int, middlewares: list<string>, status: string, scope: string, match: string,
 *     services: list<array{id: int, name: string}>, checks: list<string>}
 */
final class TraefikMapper
{
    public const MAX_ROUTES = 100;

    public const MATCH_DECLARED = 'declared';
    public const MATCH_DIFFERS = 'differs';
    public const MATCH_NOT_INVENTORIED = 'not_inventoried';
    public const MATCH_OTHER_PROVIDER = 'other_provider';
    public const MATCH_INTERNAL = 'internal';

    public const MATCHES = [self::MATCH_DECLARED, self::MATCH_DIFFERS, self::MATCH_NOT_INVENTORIED,
        self::MATCH_OTHER_PROVIDER, self::MATCH_INTERNAL];

    public const CHECK_DASHBOARD = 'dashboard_exposed';
    public const CHECK_NO_TLS = 'no_tls';
    public const CHECK_NO_AUTH = 'no_auth';
    public const CHECK_NO_HEADERS = 'no_headers';

    public const CHECKS = [self::CHECK_DASHBOARD, self::CHECK_NO_TLS, self::CHECK_NO_AUTH, self::CHECK_NO_HEADERS];

    /** Fournisseurs Traefik qui lisent les étiquettes des conteneurs ou des services Swarm. */
    private const LABEL_PROVIDERS = ['docker', 'swarm'];

    /** Services internes de Traefik : son API et son tableau de bord. */
    private const API_SERVICES = ['api@internal', 'dashboard@internal'];

    private const AUTH_TYPES = ['basicauth', 'digestauth', 'forwardauth'];

    /** Mots qui désignent une interface d'administration, dans le nom, l'hôte ou le chemin. */
    private const ADMIN_PATTERN = '/(?<![a-z])(admin|administration|dashboard|manage|manager|portainer|traefik'
        . '|grafana|phpmyadmin|adminer|kibana|console)(?![a-z])/i';

    private const MAX_ITEMS = 10;

    /**
     * @param array<string, list<array<string, mixed>>> $snapshot Réponses de l'API (TraefikClient::snapshot()).
     * @param list<Declared> $declared Routes Traefik des étiquettes des services inventoriés.
     * @return array{routes: list<TraefikRoute>, not_applied: list<Declared>, truncated: bool,
     *     counts: array<string, int>}
     */
    public static function map(array $snapshot, array $declared): array
    {
        $entrypoints = [];
        foreach ($snapshot['entrypoints'] ?? [] as $entrypoint) {
            $redirect = $entrypoint['http']['redirections']['entryPoint']['to'] ?? '';
            $entrypoints[self::str($entrypoint['name'] ?? null)] = [
                'address'  => self::str($entrypoint['address'] ?? null),
                'redirect' => is_string($redirect) && $redirect !== '',
            ];
        }
        $middlewares = [];
        foreach ($snapshot['middlewares'] ?? [] as $middleware) {
            $middlewares[self::str($middleware['name'] ?? null)] = strtolower(self::str($middleware['type'] ?? null));
        }
        $servers = [];
        foreach ($snapshot['services'] ?? [] as $service) {
            $list = $service['loadBalancer']['servers'] ?? [];
            $servers[self::str($service['name'] ?? null)] = is_array($list) ? count($list) : 0;
        }
        $byName = [];
        foreach ($declared as $route) {
            $byName[strtolower($route['name'])][] = $route;
        }

        $routes = [];
        $applied = [];
        foreach (['http' => 'routers', 'tcp' => 'tcp_routers'] as $protocol => $key) {
            foreach ($snapshot[$key] ?? [] as $router) {
                $route = self::route($protocol, $router, $entrypoints, $middlewares, $servers, $byName);
                if (in_array($route['provider'], self::LABEL_PROVIDERS, true)) {
                    $applied[strtolower($route['name'])] = true;
                }
                $routes[] = $route;
            }
        }
        // Routes signalées d'abord, puis par nom.
        usort($routes, static fn (array $a, array $b): int => [$b['checks'] !== [], $a['name']]
            <=> [$a['checks'] !== [], $b['name']]);

        $notApplied = [];
        foreach ($declared as $route) {
            if (!isset($applied[strtolower($route['name'])])) {
                $notApplied[] = $route;
            }
        }

        $counts = ['routes' => count($routes), 'internet' => 0, 'not_applied' => count($notApplied)]
            + array_fill_keys(self::MATCHES, 0) + array_fill_keys(self::CHECKS, 0);
        foreach ($routes as $route) {
            $counts['internet'] += $route['scope'] === ExposureMapper::SCOPE_INTERNET ? 1 : 0;
            $counts[$route['match']]++;
            foreach ($route['checks'] as $check) {
                $counts[$check]++;
            }
        }

        return [
            'routes'      => array_slice($routes, 0, self::MAX_ROUTES),
            'not_applied' => array_slice($notApplied, 0, self::MAX_ROUTES),
            'truncated'   => count($routes) > self::MAX_ROUTES || count($notApplied) > self::MAX_ROUTES,
            'counts'      => $counts,
        ];
    }

    /**
     * @param array<string, mixed> $router
     * @param array<string, array{address: string, redirect: bool}> $entrypoints
     * @param array<string, string> $middlewares Nom qualifié (nom@fournisseur) => type.
     * @param array<string, int> $servers Service qualifié => nombre de serveurs.
     * @param array<string, list<Declared>> $byName
     * @return TraefikRoute
     */
    private static function route(
        string $protocol,
        array $router,
        array $entrypoints,
        array $middlewares,
        array $servers,
        array $byName
    ): array {
        $fullName = self::str($router['name'] ?? null);
        $at = strrpos($fullName, '@');
        $provider = strtolower(self::str($router['provider'] ?? null));
        if ($provider === '' && $at !== false) {
            $provider = strtolower(substr($fullName, $at + 1));
        }
        $name = $at !== false ? substr($fullName, 0, $at) : $fullName;
        $rule = self::str($router['rule'] ?? null);
        $hosts = array_map('strtolower', ExposureMapper::ruleValues($rule, $protocol === 'tcp' ? ['HostSNI'] : ['Host']));
        $hosts = array_values(array_filter($hosts, static fn (string $host): bool => $host !== '*'));
        $paths = ExposureMapper::ruleValues($rule, ['PathPrefix', 'Path']);
        $service = self::qualify(self::str($router['service'] ?? null), $provider);

        $entryNames = [];
        $redirected = false;
        foreach (self::strings($router['entryPoints'] ?? null) as $entrypoint) {
            $address = $entrypoints[$entrypoint]['address'] ?? '';
            $entryNames[] = $address !== '' ? $entrypoint . ' (' . $address . ')' : $entrypoint;
            $redirected = $redirected || ($entrypoints[$entrypoint]['redirect'] ?? false);
        }
        $types = [];
        $middlewareNames = [];
        foreach (self::strings($router['middlewares'] ?? null) as $middleware) {
            $qualified = self::qualify($middleware, $provider);
            $middlewareNames[] = $qualified;
            $types[] = $middlewares[$qualified] ?? '';
        }
        $redirected = $redirected || in_array('redirectscheme', $types, true);
        $authenticated = array_intersect(self::AUTH_TYPES, $types) !== [];
        $tls = is_array($router['tls'] ?? null);

        $scope = ExposureMapper::SCOPE_INTERNAL;
        foreach ($hosts === [] ? [''] : $hosts as $host) {
            $hostScope = ExposureMapper::hostScope($host);
            if ($hostScope === ExposureMapper::SCOPE_INTERNET) {
                $scope = $hostScope;
                break;
            }
            $scope = $hostScope === ExposureMapper::SCOPE_HOST ? $hostScope : $scope;
        }

        $checks = [];
        $isApi = in_array($service, self::API_SERVICES, true);
        if ($isApi && !$authenticated) {
            $checks[] = self::CHECK_DASHBOARD;
        } elseif (
            !$isApi && $protocol === 'http' && !$authenticated
            && preg_match(self::ADMIN_PATTERN, $name . ' ' . implode(' ', $hosts) . ' ' . implode(' ', $paths)) === 1
        ) {
            $checks[] = self::CHECK_NO_AUTH;
        }
        if ($protocol === 'http' && $scope === ExposureMapper::SCOPE_INTERNET && !$redirected) {
            if (!$tls) {
                $checks[] = self::CHECK_NO_TLS;
            }
            if (!in_array('headers', $types, true)) {
                $checks[] = self::CHECK_NO_HEADERS;
            }
        }

        $declared = $byName[strtolower($name)] ?? [];
        $match = match (true) {
            $provider === 'internal' || $isApi => self::MATCH_INTERNAL,
            !in_array($provider, self::LABEL_PROVIDERS, true) => self::MATCH_OTHER_PROVIDER,
            $declared === [] => self::MATCH_NOT_INVENTORIED,
            self::sameHosts($hosts, $declared) => self::MATCH_DECLARED,
            default => self::MATCH_DIFFERS,
        };
        $linked = [];
        foreach ($declared as $entry) {
            $linked[$entry['service_id']] = ['id' => $entry['service_id'], 'name' => $entry['service']];
        }

        return [
            'name'        => mb_substr($name, 0, 128),
            'provider'    => mb_substr($provider, 0, 32),
            'protocol'    => $protocol,
            'rule'        => mb_substr($rule, 0, 255),
            'hosts'       => self::cut($hosts, 255),
            'paths'       => self::cut($paths, 255),
            'entrypoints' => self::cut($entryNames, 64),
            'tls'         => $tls,
            'service'     => mb_substr($service, 0, 128),
            'servers'     => $servers[$service] ?? 0,
            'middlewares' => self::cut($middlewareNames, 64),
            'status'      => mb_substr(strtolower(self::str($router['status'] ?? null)), 0, 16),
            'scope'       => $scope,
            'match'       => $match,
            'services'    => array_slice(array_values($linked), 0, self::MAX_ITEMS),
            'checks'      => $checks,
        ];
    }

    /**
     * Mêmes noms d'hôte que les étiquettes ? Une étiquette sans nom d'hôte ne contredit rien.
     *
     * @param list<string> $hosts
     * @param list<Declared> $declared
     */
    private static function sameHosts(array $hosts, array $declared): bool
    {
        $expected = array_values(array_unique(array_filter(array_column($declared, 'host'))));
        if ($expected === []) {
            return true;
        }
        sort($expected);
        $actual = array_values(array_unique($hosts));
        sort($actual);

        return $expected === $actual;
    }

    /**
     * Nom qualifié par son fournisseur : « auth » d'un routeur Docker est « auth@docker ».
     */
    private static function qualify(string $name, string $provider): string
    {
        return $name === '' || str_contains($name, '@') || $provider === '' ? $name : $name . '@' . $provider;
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private static function cut(array $values, int $length): array
    {
        return array_map(
            static fn (string $value): string => mb_substr($value, 0, $length),
            array_slice($values, 0, self::MAX_ITEMS)
        );
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(self::str(...), $value),
            static fn (string $item): bool => $item !== ''
        ));
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}

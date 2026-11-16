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

namespace GlpiPlugin\Dockerinventory\Tests;

use GlpiPlugin\Dockerinventory\Kubernetes\KubernetesClient;

/**
 * Jeu de données fictif du faux Kubernetes (tests/mock-kubernetes/fixture.json), servi en mémoire
 * comme le fait tests/mock-kubernetes/server.py : /version et les listes de l'API, paginées par
 * limit et continue. Les erreurs sont levées comme celles du client HTTP de GLPI
 * (« HTTP 403 returned for »).
 */
final class KubernetesFixture
{
    public const TOKEN = 'jeton-fictif-du-compte-de-service';

    /**
     * @return array<string, mixed>
     */
    public static function load(): array
    {
        $data = json_decode((string) file_get_contents(__DIR__ . '/mock-kubernetes/fixture.json'), true);

        return is_array($data) ? $data : [];
    }

    /**
     * Transport du client : réponses du jeu $fixture ; $fail[ressource ou chemin] => code HTTP.
     *
     * @param array<string, mixed> $fixture
     * @param array<string, int> $fail Nom de ressource (« cronjobs ») ou chemin => code d'erreur.
     * @param list<string>|null $log Chemins demandés.
     * @param list<array<string, string>>|null $headersLog En-têtes envoyés.
     * @return \Closure(string, array<string, scalar>, array<string, string>): string
     */
    public static function transport(
        array $fixture,
        array $fail = [],
        ?array &$log = null,
        ?array &$headersLog = null
    ): \Closure {
        return static function (string $url, array $query, array $headers) use (
            $fixture,
            $fail,
            &$log,
            &$headersLog
        ): string {
            $path = (string) parse_url($url, PHP_URL_PATH);
            $log[] = $path;
            $headersLog[] = $headers;
            if (($headers['Authorization'] ?? '') !== 'Bearer ' . self::TOKEN) {
                throw new \RuntimeException('HTTP 401 returned for "' . $url . '".');
            }
            $resource = array_search($path, KubernetesClient::RESOURCES, true);
            foreach ([$path, (string) $resource] as $key) {
                if ($key !== '' && isset($fail[$key])) {
                    throw new \RuntimeException('HTTP ' . $fail[$key] . ' returned for "' . $url . '?limit=500".');
                }
            }
            if ($path === '/version') {
                return (string) json_encode($fixture['version']);
            }
            if ($resource === false) {
                throw new \RuntimeException('HTTP 404 returned for "' . $url . '".');
            }
            $items = is_array($fixture[$resource] ?? null) ? $fixture[$resource] : [];
            $limit = max(1, (int) ($query['limit'] ?? 500));
            $offset = (int) base64_decode((string) ($query['continue'] ?? ''), true);
            $page = array_slice($items, $offset, $limit);
            $next = $offset + $limit < count($items) ? base64_encode((string) ($offset + $limit)) : '';

            return (string) json_encode([
                'kind'       => 'List',
                'apiVersion' => 'v1',
                'metadata'   => ['resourceVersion' => '1000'] + ($next !== '' ? ['continue' => $next] : []),
                'items'      => $page,
            ]);
        };
    }
}

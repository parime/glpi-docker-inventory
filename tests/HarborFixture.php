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

/**
 * Jeu de données fictif du faux Harbor (tests/mock-harbor/fixture.json), servi en mémoire comme le
 * fait tests/mock-harbor/server.py (format de l'API Harbor 2.x, pagination, nom de dépôt encodé
 * deux fois). Les erreurs sont levées comme celles du client HTTP de GLPI (« HTTP 500 returned »).
 */
final class HarborFixture
{
    public const REPORT_MIME = 'application/vnd.security.vulnerability.report; version=1.1';

    /**
     * @return array<string, mixed>
     */
    public static function load(): array
    {
        $data = json_decode((string) file_get_contents(__DIR__ . '/mock-harbor/fixture.json'), true);

        return is_array($data) ? $data : [];
    }

    /**
     * Transport du client Harbor : réponses du jeu $fixture ; $fail[chemin] => message d'erreur.
     *
     * @param array<string, mixed> $fixture
     * @param array<string, string> $fail Préfixe de chemin => message de l'exception levée.
     * @param list<string>|null $log Chemins demandés.
     * @return \Closure(string, array<string, scalar>, array<string, string>): string
     */
    public static function transport(array $fixture, array $fail = [], ?array &$log = null): \Closure
    {
        return static function (string $url, array $query, array $headers) use ($fixture, $fail, &$log): string {
            $path = (string) parse_url($url, PHP_URL_PATH);
            $log[] = $path;
            foreach ($fail as $prefix => $message) {
                if (str_starts_with($path, $prefix)) {
                    throw new \RuntimeException($message);
                }
            }

            return self::respond($fixture, $path, $query);
        };
    }

    /**
     * @param array<string, mixed> $fixture
     * @param array<string, scalar> $query
     */
    public static function respond(array $fixture, string $path, array $query): string
    {
        $projects = self::projects($fixture);
        $page = max(1, (int) ($query['page'] ?? 1));
        $size = max(1, min(100, (int) ($query['page_size'] ?? 10)));
        $slice = static fn (array $items): string => (string) json_encode(array_slice($items, ($page - 1) * $size, $size));

        if ($path === '/api/v2.0/projects') {
            $items = [];
            foreach ($projects as $project) {
                $items[] = [
                    'project_id' => $project['project_id'],
                    'name'       => $project['name'],
                    'repo_count' => count($project['repositories']),
                    'metadata'   => ['public' => !empty($project['public']) ? 'true' : 'false'],
                ];
            }

            return $slice($items);
        }
        if (preg_match('#^/api/v2\.0/projects/([^/]+)/repositories$#', $path, $m) === 1) {
            $project = $projects[rawurldecode($m[1])] ?? throw new \RuntimeException('HTTP 404 returned');
            $names = array_keys($project['repositories']);
            sort($names);

            return $slice(array_map(static fn (string $name): array => [
                'name' => $project['name'] . '/' . $name,
            ], $names));
        }
        if (preg_match('#^/api/v2\.0/projects/([^/]+)/repositories/([^/]+)/artifacts$#', $path, $m) === 1) {
            $project = $projects[rawurldecode($m[1])] ?? throw new \RuntimeException('HTTP 404 returned');
            $artifacts = $project['repositories'][rawurldecode(rawurldecode($m[2]))]
                ?? throw new \RuntimeException('HTTP 404 returned');

            return $slice(array_map(self::expand(...), $artifacts));
        }

        throw new \RuntimeException('HTTP 404 returned for "' . $path . '".');
    }

    /**
     * Projets par nom, avec les artefacts générés (« generate »).
     *
     * @param array<string, mixed> $fixture
     * @return array<string, array<string, mixed>>
     */
    private static function projects(array $fixture): array
    {
        $projects = [];
        foreach ($fixture['projects'] ?? [] as $project) {
            foreach ($fixture['generate'] ?? [] as $full => $count) {
                [$name, $repository] = explode('/', (string) $full, 2);
                if ($name !== $project['name']) {
                    continue;
                }
                for ($i = 0; $i < (int) $count; $i++) {
                    $project['repositories'][$repository][] = [
                        'digest'    => 'sha256:' . str_pad(dechex($i), 64, '0', STR_PAD_LEFT),
                        'tags'      => ['build-' . $i],
                        'push_time' => '2026-10-01T08:00:00.000Z',
                        'size'      => 1000 + $i,
                    ];
                }
            }
            $projects[(string) $project['name']] = $project;
        }

        return $projects;
    }

    /**
     * @param array<string, mixed> $artifact
     * @return array<string, mixed>
     */
    private static function expand(array $artifact): array
    {
        $scan = $artifact['scan'] ?? null;
        $overview = null;
        if (is_array($scan)) {
            $counts = [];
            foreach (['Critical', 'High', 'Medium', 'Low'] as $name) {
                $count = (int) ($scan[strtolower($name)] ?? 0);
                if ($count > 0) {
                    $counts[$name] = $count;
                }
            }
            $report = [
                'scan_status' => $scan['status'] ?? 'Success',
                'end_time'    => $scan['end_time'] ?? '',
                'scanner'     => ['name' => $scan['scanner'] ?? 'Trivy', 'version' => 'v0.57.0'],
            ];
            if ($report['scan_status'] === 'Success') {
                $report['summary'] = ['total' => array_sum($counts), 'fixable' => (int) ($scan['fixable'] ?? 0),
                    'summary' => $counts];
            }
            $overview = [self::REPORT_MIME => $report];
        }

        return [
            'digest'        => $artifact['digest'],
            'type'          => 'IMAGE',
            'size'          => $artifact['size'] ?? 0,
            'push_time'     => $artifact['push_time'] ?? '',
            'tags'          => array_map(static fn (string $tag): array => ['name' => $tag], $artifact['tags'] ?? []),
            'references'    => array_map(
                static fn (string $digest): array => ['child_digest' => $digest],
                $artifact['children'] ?? []
            ),
            'scan_overview' => $overview,
        ];
    }
}

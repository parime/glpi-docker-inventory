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

namespace GlpiPlugin\Dockerinventory\Harbor;

/**
 * Réponses de l'API de Harbor 2.x => valeurs enregistrées. Fonctions pures, sans GLPI ; lecture
 * défensive : champ absent toléré, champ inconnu ignoré.
 *
 * Résumé d'analyse (« scan_overview ») : un rapport par type MIME, dont on garde le premier qui
 * a un état ; état (« scan_status »), analyseur, fin d'analyse, nombre de vulnérabilités par
 * gravité (« summary.summary ») et corrigibles (« summary.fixable »).
 *
 * @phpstan-type Project array{uid: int, name: string, is_public: int, repo_count: int}
 * @phpstan-type Scan array{scan_status: string, severity: string, scanner: string, scan_time: string|null,
 *     critical: int, high: int, medium: int, low: int, other: int, total: int, fixable: int}
 * @phpstan-type Artifact array{digest: string, child_digests: list<string>, tags: list<string>,
 *     artifact_type: string, size: int, push_time: string|null}
 */
final class HarborMapper
{
    public const STATUS_SUCCESS = 'success';
    public const STATUS_ERROR = 'error';
    public const STATUS_RUNNING = 'running';
    public const STATUS_PENDING = 'pending';
    public const STATUS_STOPPED = 'stopped';
    public const STATUS_NOT_SCANNED = 'not_scanned';

    public const STATUSES = [
        self::STATUS_SUCCESS,
        self::STATUS_ERROR,
        self::STATUS_RUNNING,
        self::STATUS_PENDING,
        self::STATUS_STOPPED,
        self::STATUS_NOT_SCANNED,
    ];

    public const SEVERITY_CRITICAL = 'critical';
    public const SEVERITY_HIGH = 'high';
    public const SEVERITY_MEDIUM = 'medium';
    public const SEVERITY_LOW = 'low';
    /** Analyse réussie, aucune vulnérabilité de gravité connue (ou seulement « négligeable »). */
    public const SEVERITY_NONE = 'none';
    /** Pas d'analyse réussie : gravité inconnue. */
    public const SEVERITY_UNKNOWN = '';

    /** Du plus grave au moins grave. */
    public const SEVERITIES = [
        self::SEVERITY_CRITICAL,
        self::SEVERITY_HIGH,
        self::SEVERITY_MEDIUM,
        self::SEVERITY_LOW,
        self::SEVERITY_NONE,
    ];

    /**
     * @param array<mixed> $data Élément de GET /api/v2.0/projects.
     * @return Project|null
     */
    public static function project(array $data): ?array
    {
        $name = $data['name'] ?? null;
        if (!is_string($name) || $name === '') {
            return null;
        }
        $metadata = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
        $public = $metadata['public'] ?? false;

        return [
            'uid'        => self::int($data['project_id'] ?? 0),
            'name'       => $name,
            'is_public'  => ($public === true || $public === 'true') ? 1 : 0,
            'repo_count' => self::int($data['repo_count'] ?? 0),
        ];
    }

    /**
     * Nom du dépôt dans son projet : « boutique/api » => « api », « archives/anciens/portail »
     * => « anciens/portail ». Vide si le nom ne commence pas par le projet.
     *
     * @param array<mixed> $data Élément de GET /api/v2.0/projects/<projet>/repositories.
     */
    public static function repositoryName(array $data, string $project): string
    {
        $name = $data['name'] ?? null;
        if (!is_string($name) || !str_starts_with($name, $project . '/')) {
            return '';
        }

        return substr($name, strlen($project) + 1);
    }

    /**
     * @param array<mixed> $data Élément de GET .../artifacts.
     * @return Artifact|null
     */
    public static function artifact(array $data): ?array
    {
        $digest = $data['digest'] ?? null;
        if (!is_string($digest) || preg_match('/^[a-z0-9]+:[a-f0-9]{32,}$/', $digest) !== 1) {
            return null;
        }
        $tags = [];
        foreach (is_array($data['tags'] ?? null) ? $data['tags'] : [] as $tag) {
            if (is_array($tag) && is_string($tag['name'] ?? null) && $tag['name'] !== '') {
                $tags[] = $tag['name'];
            }
        }
        $children = [];
        foreach (is_array($data['references'] ?? null) ? $data['references'] : [] as $reference) {
            $child = is_array($reference) ? ($reference['child_digest'] ?? null) : null;
            if (is_string($child) && $child !== '') {
                $children[] = $child;
            }
        }

        return [
            'digest'        => $digest,
            'child_digests' => $children,
            'tags'          => $tags,
            'artifact_type' => is_string($data['type'] ?? null) ? strtolower($data['type']) : '',
            'size'          => max(0, self::int($data['size'] ?? 0)),
            'push_time'     => self::date($data['push_time'] ?? null),
        ];
    }

    /**
     * Résumé d'analyse d'un artefact ; « non analysé » sans résumé.
     *
     * @param mixed $overview Champ « scan_overview » de l'artefact.
     * @return Scan
     */
    public static function scan(mixed $overview): array
    {
        $scan = [
            'scan_status' => self::STATUS_NOT_SCANNED,
            'severity'    => self::SEVERITY_UNKNOWN,
            'scanner'     => '',
            'scan_time'   => null,
            'critical'    => 0,
            'high'        => 0,
            'medium'      => 0,
            'low'         => 0,
            'other'       => 0,
            'total'       => 0,
            'fixable'     => 0,
        ];
        $report = self::report($overview);
        if ($report === null) {
            return $scan;
        }
        $status = strtolower(is_string($report['scan_status'] ?? null) ? $report['scan_status'] : '');
        $scan['scan_status'] = match ($status) {
            'success'                => self::STATUS_SUCCESS,
            'error'                  => self::STATUS_ERROR,
            'running'                => self::STATUS_RUNNING,
            'pending', 'scheduled'   => self::STATUS_PENDING,
            'stopped'                => self::STATUS_STOPPED,
            default                  => self::STATUS_NOT_SCANNED,
        };
        $scanner = is_array($report['scanner'] ?? null) ? $report['scanner'] : [];
        $name = is_string($scanner['name'] ?? null) ? $scanner['name'] : '';
        $version = is_string($scanner['version'] ?? null) ? $scanner['version'] : '';
        $scan['scanner'] = mb_substr(trim($name . ' ' . $version), 0, 255);
        $scan['scan_time'] = self::date($report['end_time'] ?? null) ?? self::date($report['start_time'] ?? null);

        $summary = is_array($report['summary'] ?? null) ? $report['summary'] : [];
        $counts = is_array($summary['summary'] ?? null) ? $summary['summary'] : [];
        foreach ($counts as $severity => $count) {
            $key = match (strtolower((string) $severity)) {
                'critical' => 'critical',
                'high'     => 'high',
                'medium'   => 'medium',
                'low'      => 'low',
                default    => 'other',
            };
            $scan[$key] += max(0, self::int($count));
        }
        $scan['total'] = max(
            self::int($summary['total'] ?? 0),
            $scan['critical'] + $scan['high'] + $scan['medium'] + $scan['low'] + $scan['other']
        );
        $scan['fixable'] = max(0, self::int($summary['fixable'] ?? 0));
        if ($scan['scan_status'] === self::STATUS_SUCCESS) {
            $scan['severity'] = self::severity($scan);
        }

        return $scan;
    }

    /**
     * Gravité la plus haute d'une analyse réussie.
     *
     * @param array{critical: int, high: int, medium: int, low: int} $counts
     */
    public static function severity(array $counts): string
    {
        return match (true) {
            $counts['critical'] > 0 => self::SEVERITY_CRITICAL,
            $counts['high'] > 0     => self::SEVERITY_HIGH,
            $counts['medium'] > 0   => self::SEVERITY_MEDIUM,
            $counts['low'] > 0      => self::SEVERITY_LOW,
            default                 => self::SEVERITY_NONE,
        };
    }

    /**
     * Rang d'une gravité pour trier (0 : critique ... ; inconnue en dernier).
     */
    public static function rank(string $severity): int
    {
        $rank = array_search($severity, self::SEVERITIES, true);

        return $rank === false ? count(self::SEVERITIES) : (int) $rank;
    }

    /**
     * Rapport retenu dans « scan_overview » : le premier (par type MIME) qui a un état.
     *
     * @return array<mixed>|null
     */
    private static function report(mixed $overview): ?array
    {
        if (!is_array($overview)) {
            return null;
        }
        foreach ($overview as $report) {
            if (is_array($report) && is_string($report['scan_status'] ?? null)) {
                return $report;
            }
        }

        return null;
    }

    /**
     * Date ISO 8601 de Harbor (« 2026-11-14T03:00:00.000Z ») => « Y-m-d H:i:s » dans le fuseau de
     * PHP ; null pour une date absente ou nulle (« 0001-01-01T00:00:00Z »).
     */
    public static function date(mixed $value): ?string
    {
        if (!is_string($value) || $value === '' || str_starts_with($value, '0001-')) {
            return null;
        }
        try {
            $date = new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
        if ((int) $date->format('Y') < 1971) {
            return null;
        }

        return $date->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
    }

    private static function int(mixed $value): int
    {
        return is_int($value) || (is_string($value) && is_numeric($value)) || is_float($value) ? (int) $value : 0;
    }
}

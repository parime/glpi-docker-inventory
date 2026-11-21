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

namespace GlpiPlugin\Dockerinventory\Scan;

use GlpiPlugin\Dockerinventory\Harbor\HarborMapper;

/**
 * Lecture d'un rapport d'analyse d'image produit hors du plugin, en CI par exemple (#73) :
 * JSON de Trivy (`trivy image -f json`), JSON de Grype (`grype -o json`) ou SARIF 2.1.0
 * (Docker Scout `docker scout cves --format sarif`, Trivy ou Grype). Fonctions pures, sans GLPI.
 *
 * Lecture sûre d'un fichier déposé par une personne : taille bornée (MAX_BYTES), profondeur JSON
 * bornée (MAX_DEPTH), nombre de vulnérabilités borné (MAX_FINDINGS), chaque valeur lue avec son
 * type attendu et tronquée ; rien n'est exécuté ni appelé. Seules les MAX_STORED vulnérabilités les
 * plus graves sont gardées en détail ; les compteurs portent sur toutes.
 *
 * @phpstan-type Finding array{id: string, severity: string, package: string, installed: string, fixed: string}
 * @phpstan-type Report array{scanner: string, tool: string, tool_version: string, image: string, tag: string,
 *     digest: string, scan_date: ?string, counts: array{critical: int, high: int, medium: int, low: int,
 *     other: int}, fixable: int, total: int, findings: list<Finding>, truncated: bool}
 */
final class ScanReportParser
{
    public const MAX_BYTES = 20 * 1024 * 1024;
    public const MAX_DEPTH = 64;
    public const MAX_FINDINGS = 100000;
    public const MAX_STORED = 500;

    public const SCANNER_TRIVY = 'trivy';
    public const SCANNER_GRYPE = 'grype';
    public const SCANNER_SCOUT = 'scout';
    /** SARIF d'un autre outil. */
    public const SCANNER_SARIF = 'sarif';

    public const SCANNERS = [self::SCANNER_TRIVY, self::SCANNER_GRYPE, self::SCANNER_SCOUT, self::SCANNER_SARIF];

    /** Gravité inconnue, négligeable ou non reconnue. */
    public const OTHER = 'other';

    public const COUNTED = [
        HarborMapper::SEVERITY_CRITICAL,
        HarborMapper::SEVERITY_HIGH,
        HarborMapper::SEVERITY_MEDIUM,
        HarborMapper::SEVERITY_LOW,
    ];

    /**
     * @param string $reference Image analysée saisie (nom:tag ou nom@empreinte) ; prime sur le rapport.
     * @return Report
     */
    public static function parse(string $content, string $reference = ''): array
    {
        if (strlen($content) > self::MAX_BYTES) {
            throw new ScanReportException(ScanReportException::TOO_LARGE);
        }
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }
        if (trim($content) === '') {
            throw new ScanReportException(ScanReportException::EMPTY);
        }
        try {
            $data = json_decode($content, true, self::MAX_DEPTH, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException $e) {
            throw new ScanReportException(
                $e->getCode() === JSON_ERROR_DEPTH ? ScanReportException::TOO_DEEP : ScanReportException::INVALID_JSON
            );
        }
        if (!is_array($data) || array_is_list($data)) {
            throw new ScanReportException(ScanReportException::UNKNOWN_FORMAT);
        }

        if (isset($data['runs']) && is_array($data['runs'])) {
            $report = self::sarif($data);
        } elseif (isset($data['matches']) && is_array($data['matches'])) {
            $report = self::grype($data);
        } elseif (isset($data['Results']) || (isset($data['SchemaVersion'], $data['ArtifactName']))) {
            $report = self::trivy($data);
        } else {
            throw new ScanReportException(ScanReportException::UNKNOWN_FORMAT);
        }

        $reference = trim($reference);
        if ($reference !== '') {
            $split = self::splitReference($reference);
            if ($split['image'] === '') {
                throw new ScanReportException(ScanReportException::NO_IMAGE);
            }
            // Empreinte du rapport gardée si la saisie n'en donne pas et vise la même image.
            if ($split['digest'] === '' && $split['image'] === $report['image']) {
                $split['digest'] = $report['digest'];
            }
            $report = $split + $report;
        }
        if ($report['image'] === '') {
            throw new ScanReportException(ScanReportException::NO_IMAGE);
        }

        return $report;
    }

    /**
     * « registre.exemple.test:5000/app:1.2@sha256:... » => image, tag et empreinte (vides si invalides).
     *
     * @return array{image: string, tag: string, digest: string}
     */
    public static function splitReference(string $reference): array
    {
        $reference = trim($reference);
        $digest = '';
        $at = strpos($reference, '@');
        if ($at !== false) {
            $digest = strtolower(substr($reference, $at + 1));
            $reference = substr($reference, 0, $at);
        }
        $tag = '';
        $colon = strrpos($reference, ':');
        $slash = strrpos($reference, '/');
        if ($colon !== false && ($slash === false || $colon > $slash)) {
            $tag = substr($reference, $colon + 1);
            $reference = substr($reference, 0, $colon);
        }
        $image = strtolower($reference);
        if (strlen($image) > 255 || preg_match('#^[a-z0-9][a-z0-9._/:-]*$#', $image) !== 1) {
            $image = '';
        }

        return [
            'image'  => $image,
            'tag'    => preg_match('/^[\w][\w.-]{0,127}$/', $tag) === 1 ? $tag : '',
            'digest' => preg_match('/^sha256:[a-f0-9]{64}$/', $digest) === 1 ? $digest : '',
        ];
    }

    /**
     * Gravité donnée par l'outil => critical, high, medium, low ou other.
     */
    public static function severity(mixed $value): string
    {
        $value = strtolower(self::str($value, 16));

        return match ($value) {
            'critical'              => HarborMapper::SEVERITY_CRITICAL,
            'high', 'important'     => HarborMapper::SEVERITY_HIGH,
            'medium', 'moderate'    => HarborMapper::SEVERITY_MEDIUM,
            'low'                   => HarborMapper::SEVERITY_LOW,
            default                 => self::OTHER,
        };
    }

    /**
     * Score CVSS => gravité (échelle CVSS v3 : 9 critique, 7 haute, 4 moyenne, au-dessus de 0 basse).
     */
    public static function severityFromScore(float $score): string
    {
        return match (true) {
            $score >= 9.0 => HarborMapper::SEVERITY_CRITICAL,
            $score >= 7.0 => HarborMapper::SEVERITY_HIGH,
            $score >= 4.0 => HarborMapper::SEVERITY_MEDIUM,
            $score > 0.0  => HarborMapper::SEVERITY_LOW,
            default       => self::OTHER,
        };
    }

    /**
     * @param array<mixed> $data
     * @return Report
     */
    private static function trivy(array $data): array
    {
        $metadata = self::arr($data['Metadata'] ?? null);
        $report = self::report(
            self::SCANNER_TRIVY,
            'Trivy',
            self::str(self::arr($data['Trivy'] ?? null)['Version'] ?? '', 32),
            self::str($data['ArtifactName'] ?? '', 300),
            self::arr($metadata['RepoDigests'] ?? null),
            self::date($data['CreatedAt'] ?? null)
        );
        $findings = [];
        foreach (self::arr($data['Results'] ?? null) as $result) {
            foreach (self::arr(self::arr($result)['Vulnerabilities'] ?? null) as $vuln) {
                $vuln = self::arr($vuln);
                self::add($findings, [
                    'id'        => self::str($vuln['VulnerabilityID'] ?? '', 64),
                    'severity'  => self::severity($vuln['Severity'] ?? ''),
                    'package'   => self::str($vuln['PkgName'] ?? '', 128),
                    'installed' => self::str($vuln['InstalledVersion'] ?? '', 64),
                    'fixed'     => self::str($vuln['FixedVersion'] ?? '', 64),
                ]);
            }
        }

        return self::finish($report, $findings);
    }

    /**
     * @param array<mixed> $data
     * @return Report
     */
    private static function grype(array $data): array
    {
        $descriptor = self::arr($data['descriptor'] ?? null);
        $target = self::arr(self::arr($data['source'] ?? null)['target'] ?? null);
        $tags = self::arr($target['tags'] ?? null);
        $name = self::str($target['userInput'] ?? '', 300);
        if ($name === '' && $tags !== []) {
            $name = self::str($tags[0] ?? '', 300);
        }
        $report = self::report(
            self::SCANNER_GRYPE,
            'Grype',
            self::str($descriptor['version'] ?? '', 32),
            $name,
            self::arr($target['repoDigests'] ?? null),
            self::date($descriptor['timestamp'] ?? null)
        );
        $findings = [];
        foreach ($data['matches'] as $match) {
            $match = self::arr($match);
            $vuln = self::arr($match['vulnerability'] ?? null);
            $artifact = self::arr($match['artifact'] ?? null);
            $fix = self::arr($vuln['fix'] ?? null);
            $versions = self::arr($fix['versions'] ?? null);
            self::add($findings, [
                'id'        => self::str($vuln['id'] ?? '', 64),
                'severity'  => self::severity($vuln['severity'] ?? ''),
                'package'   => self::str($artifact['name'] ?? '', 128),
                'installed' => self::str($artifact['version'] ?? '', 64),
                'fixed'     => self::str($fix['state'] ?? '', 16) === 'fixed' ? self::str($versions[0] ?? '', 64) : '',
            ]);
        }

        return self::finish($report, $findings);
    }

    /**
     * SARIF 2.1.0 : gravité de la règle (score « security-severity », sinon gravité CVSS ou étiquette),
     * à défaut niveau du résultat ; paquet et versions lus dans le message (forme de Trivy et Scout).
     *
     * @param array<mixed> $data
     * @return Report
     */
    private static function sarif(array $data): array
    {
        $runs = self::arr($data['runs']);
        $first = self::arr($runs[0] ?? null);
        $driver = self::arr(self::arr($first['tool'] ?? null)['driver'] ?? null);
        $tool = self::str($driver['name'] ?? '', 64);
        $lower = strtolower($tool);
        $scanner = match (true) {
            str_contains($lower, 'scout') => self::SCANNER_SCOUT,
            str_contains($lower, 'trivy') => self::SCANNER_TRIVY,
            str_contains($lower, 'grype') => self::SCANNER_GRYPE,
            default                       => self::SCANNER_SARIF,
        };
        $properties = self::arr($first['properties'] ?? null);
        $invocation = self::arr(self::arr($first['invocations'] ?? null)[0] ?? null);
        $report = self::report(
            $scanner,
            $tool,
            self::str($driver['version'] ?? ($driver['semanticVersion'] ?? ''), 32),
            self::str($properties['imageName'] ?? '', 300),
            self::arr($properties['repoDigests'] ?? null),
            self::date($invocation['endTimeUtc'] ?? null)
        );
        $findings = [];
        foreach ($runs as $run) {
            $run = self::arr($run);
            $rules = [];
            $driver = self::arr(self::arr($run['tool'] ?? null)['driver'] ?? null);
            foreach (self::arr($driver['rules'] ?? null) as $rule) {
                $rule = self::arr($rule);
                $id = self::str($rule['id'] ?? '', 64);
                if ($id !== '') {
                    $rules[$id] = self::ruleSeverity(self::arr($rule['properties'] ?? null));
                }
            }
            foreach (self::arr($run['results'] ?? null) as $result) {
                $result = self::arr($result);
                $id = self::str($result['ruleId'] ?? '', 64);
                $text = self::arr($result['message'] ?? null)['text'] ?? '';
                $text = is_string($text) && mb_check_encoding($text, 'UTF-8') ? mb_substr($text, 0, 4000) : '';
                $severity = $rules[$id] ?? self::OTHER;
                if ($severity === self::OTHER) {
                    $severity = match (self::str($result['level'] ?? '', 16)) {
                        'error'   => HarborMapper::SEVERITY_HIGH,
                        'warning' => HarborMapper::SEVERITY_MEDIUM,
                        'note'    => HarborMapper::SEVERITY_LOW,
                        default   => self::OTHER,
                    };
                }
                self::add($findings, [
                    'id'        => $id,
                    'severity'  => $severity,
                    'package'   => self::field($text, 'Package', 128),
                    'installed' => self::field($text, 'Installed Version', 64),
                    'fixed'     => self::field($text, 'Fixed Version', 64),
                ]);
            }
        }

        return self::finish($report, $findings);
    }

    /**
     * @param array<mixed> $properties
     */
    private static function ruleSeverity(array $properties): string
    {
        $score = $properties['security-severity'] ?? null;
        if ((is_string($score) || is_int($score) || is_float($score)) && is_numeric($score)) {
            $severity = self::severityFromScore((float) $score);
            if ($severity !== self::OTHER) {
                return $severity;
            }
        }
        $severity = self::severity($properties['cvssV3_severity'] ?? '');
        if ($severity !== self::OTHER) {
            return $severity;
        }
        foreach (self::arr($properties['tags'] ?? null) as $tag) {
            $severity = self::severity($tag);
            if ($severity !== self::OTHER) {
                return $severity;
            }
        }

        return self::OTHER;
    }

    /**
     * Valeur d'une ligne « Nom: valeur » du message d'un résultat SARIF.
     */
    private static function field(string $text, string $name, int $max): string
    {
        if (preg_match('/^' . preg_quote($name, '/') . ':\s*(.+)$/mi', $text, $m) !== 1) {
            return '';
        }

        return self::str($m[1], $max);
    }

    /**
     * @param list<mixed> $digests Références « dépôt@sha256:... ».
     * @return Report
     */
    private static function report(
        string $scanner,
        string $tool,
        string $version,
        string $name,
        array $digests,
        ?string $date
    ): array {
        $split = self::splitReference($name);
        foreach ($digests as $digest) {
            $candidate = self::splitReference(self::str($digest, 400))['digest'];
            if ($candidate !== '' && $split['digest'] === '') {
                $split['digest'] = $candidate;
            }
        }

        return $split + [
            'scanner'      => $scanner,
            'tool'         => $tool,
            'tool_version' => $version,
            'scan_date'    => $date,
            'counts'       => ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0, 'other' => 0],
            'fixable'      => 0,
            'total'        => 0,
            'findings'     => [],
            'truncated'    => false,
        ];
    }

    /**
     * Ajoute une vulnérabilité (une seule fois par identifiant, paquet et version installée).
     *
     * @param array<string, Finding> $findings
     * @param Finding $finding
     */
    private static function add(array &$findings, array $finding): void
    {
        if ($finding['id'] === '') {
            return;
        }
        $key = $finding['id'] . "\0" . $finding['package'] . "\0" . $finding['installed'];
        if (isset($findings[$key])) {
            return;
        }
        if (count($findings) >= self::MAX_FINDINGS) {
            throw new ScanReportException(ScanReportException::TOO_MANY);
        }
        $findings[$key] = $finding;
    }

    /**
     * Compteurs par gravité, corrigeables, détail des plus graves.
     *
     * @param Report $report
     * @param array<string, Finding> $findings
     * @return Report
     */
    private static function finish(array $report, array $findings): array
    {
        foreach ($findings as $finding) {
            $key = in_array($finding['severity'], self::COUNTED, true) ? $finding['severity'] : self::OTHER;
            $report['counts'][$key]++;
            if ($finding['fixed'] !== '') {
                $report['fixable']++;
            }
        }
        $report['total'] = count($findings);
        $list = array_values($findings);
        usort($list, static fn (array $a, array $b): int => [
            self::rank($a['severity']), $a['fixed'] === '' ? 1 : 0, $a['id'], $a['package'],
        ] <=> [
            self::rank($b['severity']), $b['fixed'] === '' ? 1 : 0, $b['id'], $b['package'],
        ]);
        $report['truncated'] = count($list) > self::MAX_STORED;
        $report['findings'] = array_slice($list, 0, self::MAX_STORED);

        return $report;
    }

    private static function rank(string $severity): int
    {
        $rank = array_search($severity, self::COUNTED, true);

        return $rank === false ? count(self::COUNTED) : (int) $rank;
    }

    /**
     * Date ISO 8601 => « AAAA-MM-JJ HH:MM:SS » (fuseau du serveur), null si absente ou invraisemblable.
     */
    private static function date(mixed $value): ?string
    {
        $value = self::str($value, 64);
        if ($value === '') {
            return null;
        }
        try {
            $date = new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
        $year = (int) $date->format('Y');
        if ($year < 2000 || $year > 2100) {
            return null;
        }

        return $date->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
    }

    /**
     * @return array<mixed>
     */
    private static function arr(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * Texte d'une valeur scalaire, sans caractère de contrôle, tronqué ; '' pour le reste.
     */
    private static function str(mixed $value, int $max): string
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return '';
        }
        $value = (string) $value;
        if (!mb_check_encoding($value, 'UTF-8')) {
            return '';
        }
        $value = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value));

        return mb_substr($value, 0, $max);
    }
}

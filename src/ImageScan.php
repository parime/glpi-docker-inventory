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

namespace GlpiPlugin\Dockerinventory;

use DbUtils;
use GlpiPlugin\Dockerinventory\Compat\Base\ImageScanBase;
use GlpiPlugin\Dockerinventory\Harbor\HarborLinker;
use GlpiPlugin\Dockerinventory\Harbor\HarborMapper;
use GlpiPlugin\Dockerinventory\Scan\ScanReportException;
use GlpiPlugin\Dockerinventory\Scan\ScanReportParser;
use GlpiPlugin\Dockerinventory\Wud\WudMapper;
use Session;

/**
 * Analyse d'image importée (#73) : rapport de Trivy, Grype ou Docker Scout déposé par une personne,
 * pour les images qui ne sont pas dans Harbor. Une analyse par entité, image, tag et empreinte : un
 * nouveau dépôt remplace la précédente (historique gardé). Rien n'est lancé sur les plateformes.
 *
 * Rattachement aux services à l'affichage, comme pour Harbor (HarborLinker) : même image, puis
 * empreinte, sinon tag. Droit propre : importer (CREATE) et supprimer (PURGE), en plus de la lecture
 * des services.
 */
class ImageScan extends ImageScanBase
{
    public const RIGHTNAME = 'plugin_dockerinventory_imagescan';

    /** Au-delà, l'analyse est signalée comme trop ancienne. */
    public const STALE_DAYS = 30;

    public const FILTER_CRITICAL_HIGH = 'critical_high';
    public const FILTER_STALE = 'stale';
    public const FILTERS = [self::FILTER_CRITICAL_HIGH, self::FILTER_STALE];

    public static function getTypeName($nb = 0)
    {
        return $nb > 1
            ? __('Analyses d’images importées', 'dockerinventory')
            : __('Analyse d’image importée', 'dockerinventory');
    }

    public static function getIcon()
    {
        return 'ti ti-file-search';
    }

    public static function canView(): bool
    {
        return Service::canView();
    }

    public static function canCreate(): bool
    {
        return Service::canView() && (bool) Session::haveRight(self::RIGHTNAME, CREATE);
    }

    public static function canUpdate(): bool
    {
        return self::canCreate();
    }

    public static function canDelete(): bool
    {
        return false;
    }

    public static function canPurge(): bool
    {
        return Service::canView() && (bool) Session::haveRight(self::RIGHTNAME, PURGE);
    }

    /**
     * @return array<int, string>
     */
    public function getRights($interface = 'central')
    {
        return [
            CREATE => __('Importer une analyse', 'dockerinventory'),
            PURGE  => __('Supprimer une analyse', 'dockerinventory'),
        ];
    }

    public static function getPageURL(bool $full = true): string
    {
        return ImageVersion::getOverviewURL($full) . '?view=' . Menu::TAB_SCANS;
    }

    public static function getFormURLForActions(): string
    {
        global $CFG_GLPI;

        return (string) $CFG_GLPI['root_doc'] . '/plugins/dockerinventory/front/imagescan.form.php';
    }

    /**
     * @return array<string, string>
     */
    public static function errorLabels(): array
    {
        return [
            ScanReportException::EMPTY          => __('Le fichier est vide.', 'dockerinventory'),
            ScanReportException::TOO_LARGE      => sprintf(
                __('Le fichier dépasse %d Mo.', 'dockerinventory'),
                intdiv(ScanReportParser::MAX_BYTES, 1024 * 1024)
            ),
            ScanReportException::INVALID_JSON   => __('Le fichier n’est pas du JSON valide.', 'dockerinventory'),
            ScanReportException::TOO_DEEP       => __('Le fichier est trop imbriqué pour être lu.', 'dockerinventory'),
            ScanReportException::UNKNOWN_FORMAT => __(
                'Format non reconnu : rapport JSON de Trivy ou de Grype, ou SARIF, attendu.',
                'dockerinventory'
            ),
            ScanReportException::NO_IMAGE       => __(
                'Image analysée inconnue : indiquez-la (nom:tag) dans le formulaire.',
                'dockerinventory'
            ),
            ScanReportException::TOO_MANY       => sprintf(
                __('Le rapport compte plus de %d vulnérabilités.', 'dockerinventory'),
                ScanReportParser::MAX_FINDINGS
            ),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function scannerLabels(): array
    {
        return [
            ScanReportParser::SCANNER_TRIVY => 'Trivy',
            ScanReportParser::SCANNER_GRYPE => 'Grype',
            ScanReportParser::SCANNER_SCOUT => 'Docker Scout',
            ScanReportParser::SCANNER_SARIF => 'SARIF',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function methodLabels(): array
    {
        return [
            HarborLinker::METHOD_DIGEST    => __('Par empreinte : c’est l’image qui tourne', 'dockerinventory'),
            HarborLinker::METHOD_TAG       => __(
                'Par tag (empreinte déployée inconnue) : probablement l’image qui tourne',
                'dockerinventory'
            ),
            HarborLinker::METHOD_TAG_DIGEST_DIFFERS => __(
                'Par tag : empreinte déployée différente de celle de l’analyse',
                'dockerinventory'
            ),
        ];
    }

    /**
     * Pastille d'une gravité ; « other » : gravité inconnue ou négligeable donnée par l'outil.
     *
     * @return array{label: string, color: string, icon: string, help: string}
     */
    public static function severityInfo(string $severity): array
    {
        if (in_array($severity, ScanReportParser::COUNTED, true)) {
            return HarborArtifact::severityInfo($severity);
        }

        return [
            'label' => __('Gravité inconnue', 'dockerinventory'),
            'color' => 'secondary',
            'icon'  => 'ti ti-help-circle',
            'help'  => __('Gravité inconnue ou négligeable selon l’outil d’analyse.', 'dockerinventory'),
        ];
    }

    /**
     * Importe un rapport dans l'entité active (remplace l'analyse de la même image, tag et empreinte).
     *
     * @return array{ok: bool, message: string, id: int}
     */
    public static function import(string $content, string $filename, string $reference = ''): array
    {
        if (!self::canCreate()) {
            return self::refusal(__('Vous n’avez pas le droit d’importer une analyse.', 'dockerinventory'));
        }
        try {
            $report = ScanReportParser::parse($content, $reference);
        } catch (ScanReportException $e) {
            return self::refusal(self::errorLabels()[$e->reason] ?? __('Fichier refusé.', 'dockerinventory'));
        }
        $entityId = (int) Session::getActiveEntity();
        $key = WudMapper::referenceKey($report['image']);
        $values = [
            'name'          => $report['image'] . ($report['tag'] !== '' ? ':' . $report['tag'] : ''),
            'image'         => $report['image'],
            'scanner'       => $report['scanner'],
            'tool'          => $report['tool'],
            'tool_version'  => $report['tool_version'],
            'scan_date'     => $report['scan_date'],
            'critical'      => $report['counts']['critical'],
            'high'          => $report['counts']['high'],
            'medium'        => $report['counts']['medium'],
            'low'           => $report['counts']['low'],
            'other'         => $report['counts']['other'],
            'fixable'       => $report['fixable'],
            'total'         => $report['total'],
            'findings'      => (string) json_encode($report['findings'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'is_truncated'  => $report['truncated'] ? 1 : 0,
            'filename'      => mb_substr(basename(str_replace('\\', '/', $filename)), 0, 255),
            'users_id'      => (int) Session::getLoginUserID(),
        ];
        $criteria = [
            'entities_id' => $entityId,
            'image_key'   => $key,
            'tag'         => $report['tag'],
            'digest'      => $report['digest'],
        ];
        $scan = new self();
        if ($scan->getFromDBByCrit($criteria)) {
            $ok = $scan->update(['id' => $scan->getID()] + $values);
            $id = $scan->getID();
        } else {
            $id = (int) $scan->add($values + $criteria);
            $ok = $id > 0;
        }

        return $ok
            ? ['ok' => true, 'message' => sprintf(
                __('Analyse importée : %1$s, %2$d vulnérabilités.', 'dockerinventory'),
                $values['name'],
                $report['total']
            ), 'id' => $id]
            : self::refusal(__('L’analyse n’a pas pu être enregistrée.', 'dockerinventory'));
    }

    /**
     * @return array{ok: bool, message: string, id: int}
     */
    private static function refusal(string $message): array
    {
        return ['ok' => false, 'message' => $message, 'id' => 0];
    }

    /**
     * Supprime une analyse visible (entités actives).
     */
    public static function remove(int $id): bool
    {
        $scan = new self();

        return self::canPurge() && $scan->can($id, PURGE) && $scan->delete(['id' => $id], true);
    }

    /**
     * Sous-page « Analyses importées » : analyses visibles, services rattachés, compteurs.
     *
     * @return array{scans: list<array<string, mixed>>, counts: array<string, int>, coverage: ?int}
     */
    public static function overview(string $filter = ''): array
    {
        $scans = self::scans();
        $services = self::services();
        $linked = self::link($scans, $services);
        $now = (string) $_SESSION['glpi_currenttime'];
        $counts = [
            'scans'         => count($scans),
            'critical_high' => 0,
            'stale'         => 0,
            'services'      => count($services),
            'covered'       => count($linked),
        ];
        $byScan = [];
        foreach ($linked as $serviceId => $link) {
            $byScan[$link['scan']][] = $services[$serviceId] + ['method' => $link['method']];
        }
        $rows = [];
        foreach ($scans as $scan) {
            $row = self::present($scan, $now, $byScan[(int) $scan['id']] ?? []);
            if ($row['critical'] + $row['high'] > 0) {
                $counts['critical_high']++;
            }
            if ($row['stale']) {
                $counts['stale']++;
            }
            if (
                ($filter === self::FILTER_CRITICAL_HIGH && $row['critical'] + $row['high'] === 0)
                || ($filter === self::FILTER_STALE && !$row['stale'])
            ) {
                continue;
            }
            $rows[] = $row;
        }
        usort($rows, static fn (array $a, array $b): int => [HarborMapper::rank($a['severity_code']), $a['name']]
            <=> [HarborMapper::rank($b['severity_code']), $b['name']]);

        return [
            'scans'    => $rows,
            'counts'   => $counts,
            'coverage' => $counts['services'] > 0 ? (int) round(100 * $counts['covered'] / $counts['services']) : null,
        ];
    }

    /**
     * Carte « Analyse importée » de l'onglet Versions d'un service, null sans analyse rattachée.
     *
     * @return array<string, mixed>|null
     */
    public static function forService(Service $service): ?array
    {
        if ((string) $service->fields['image'] === '') {
            return null;
        }
        $scans = self::scans();
        $links = self::link($scans, [(int) $service->getID() => $service->fields]);
        $link = $links[(int) $service->getID()] ?? null;
        if ($link === null) {
            return null;
        }
        foreach ($scans as $scan) {
            if ((int) $scan['id'] === $link['scan']) {
                return self::present($scan, (string) $_SESSION['glpi_currenttime'], []) + [
                    'method_label' => self::methodLabels()[$link['method']] ?? '',
                    'page_url'     => self::getPageURL(),
                ];
            }
        }

        return null;
    }

    /**
     * Service => analyse rattachée : même image (clé normalisée), puis empreinte, sinon tag.
     *
     * @param list<array<string, mixed>> $scans
     * @param array<int, array<string, mixed>> $services
     * @return array<int, array{scan: int, method: string}>
     */
    public static function link(array $scans, array $services): array
    {
        $byImage = [];
        foreach ($scans as $scan) {
            $byImage[(string) $scan['image_key']][] = [
                'id'            => (int) $scan['id'],
                'digest'        => (string) $scan['digest'],
                'child_digests' => [],
                'tags'          => (string) $scan['tag'] !== '' ? [(string) $scan['tag']] : [],
            ];
        }
        $links = [];
        foreach ($services as $id => $service) {
            $candidates = $byImage[WudMapper::referenceKey((string) $service['image'])] ?? [];
            if ($candidates === []) {
                continue;
            }
            $match = HarborLinker::match((string) $service['digest'], (string) $service['tag'], $candidates);
            if ($match !== null) {
                $links[(int) $id] = ['scan' => $match['id'], 'method' => $match['method']];
            }
        }

        return $links;
    }

    /**
     * Analyse prête à afficher : compteurs, gravité, ancienneté, vulnérabilités détaillées.
     *
     * @param array<string, mixed> $scan
     * @param list<array<string, mixed>> $services
     * @return array<string, mixed>
     */
    public static function present(array $scan, string $now, array $services): array
    {
        $counts = [];
        foreach (['critical', 'high', 'medium', 'low'] as $key) {
            $counts[$key] = (int) $scan[$key];
        }
        $severity = HarborMapper::severity($counts);
        $date = $scan['scan_date'] !== null ? (string) $scan['scan_date'] : '';
        $age = $date !== '' ? (int) floor((strtotime($now) - strtotime($date)) / DAY_TIMESTAMP) : null;
        $findings = json_decode((string) ($scan['findings'] ?? '[]'), true);
        $rows = [];
        foreach (is_array($findings) ? $findings : [] as $finding) {
            if (is_array($finding) && isset($finding['id'], $finding['severity'])) {
                $rows[] = [
                    'id'        => (string) $finding['id'],
                    'severity'  => self::severityInfo((string) $finding['severity']),
                    'package'   => (string) ($finding['package'] ?? ''),
                    'installed' => (string) ($finding['installed'] ?? ''),
                    'fixed'     => (string) ($finding['fixed'] ?? ''),
                ];
            }
        }
        $digest = (string) $scan['digest'];
        $list = [];
        foreach ($services as $service) {
            $list[] = [
                'name'   => (string) $service['name'],
                'stack'  => (string) $service['stack_name'],
                'url'    => Service::getFormURLWithID((int) $service['id']),
                'method' => self::methodLabels()[(string) $service['method']] ?? '',
                'exact'  => $service['method'] === HarborLinker::METHOD_DIGEST,
            ];
        }

        return [
            'id'            => (int) $scan['id'],
            'name'          => (string) $scan['name'],
            'image'         => (string) $scan['image'],
            'tag'           => (string) $scan['tag'],
            'digest'        => $digest,
            'digest_short'  => $digest !== '' ? substr($digest, 0, 19) : '',
            'scanner'       => self::scannerLabels()[(string) $scan['scanner']] ?? (string) $scan['scanner'],
            'tool'          => (string) $scan['tool'],
            'tool_version'  => (string) $scan['tool_version'],
            'scan_date'     => $date,
            'age'           => $age,
            'stale'         => $age === null || $age > self::STALE_DAYS,
            'severity_code' => $severity,
            'severity'      => $severity === HarborMapper::SEVERITY_NONE && (int) $scan['total'] > 0
                ? self::severityInfo(ScanReportParser::OTHER)
                : HarborArtifact::severityInfo($severity),
            'scanned'       => true,
            'counts'        => $counts,
            'critical'      => $counts['critical'],
            'high'          => $counts['high'],
            'other'         => (int) $scan['other'],
            'total'         => (int) $scan['total'],
            'fixable'       => (int) $scan['fixable'],
            'truncated'     => (bool) $scan['is_truncated'],
            'findings'      => $rows,
            'filename'      => (string) $scan['filename'],
            'date_mod'      => (string) ($scan['date_mod'] ?? ''),
            'services'      => $list,
        ];
    }

    /**
     * Analyses des entités actives.
     *
     * @return list<array<string, mixed>>
     */
    private static function scans(): array
    {
        global $DB;

        $rows = [];
        foreach (
            $DB->request([
                'FROM'  => self::getTable(),
                'WHERE' => (new DbUtils())->getEntitiesRestrictCriteria(self::getTable(), '', '', true),
                'ORDER' => ['name'],
            ]) as $row
        ) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Services actifs avec une image, dans les entités actives, par identifiant.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function services(): array
    {
        global $DB;

        $rows = [];
        foreach (
            $DB->request([
                'SELECT' => ['id', 'name', 'stack_name', 'image', 'tag', 'digest'],
                'FROM'   => Service::getTable(),
                'WHERE'  => ['is_retired' => 0, ['NOT' => ['image' => '']]]
                    + (new DbUtils())->getEntitiesRestrictCriteria(Service::getTable(), '', '', true),
                'ORDER'  => ['stack_name', 'name'],
            ]) as $row
        ) {
            $rows[(int) $row['id']] = $row;
        }

        return $rows;
    }
}

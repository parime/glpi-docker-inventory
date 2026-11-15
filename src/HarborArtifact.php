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

use CommonDBTM;
use DbUtils;
use GlpiPlugin\Dockerinventory\Harbor\HarborLinker;
use GlpiPlugin\Dockerinventory\Harbor\HarborMapper;
use GlpiPlugin\Dockerinventory\Harbor\HarborSettings;
use GlpiPlugin\Dockerinventory\Registry\RegistryClient;
use GlpiPlugin\Dockerinventory\Registry\RegistryResolver;
use GlpiPlugin\Dockerinventory\Ticket\VulnTickets;
use GlpiPlugin\Dockerinventory\Version\VersionLinker;

/**
 * Artefact d'un dépôt Harbor (image identifiée par son empreinte) et résumé de son analyse de
 * vulnérabilités, lus par Harbor\HarborImporter, en lecture seule : aucune page ne les modifie.
 *
 * Affichage (droit de lecture des services du plugin), sans entrée de menu : onglet « Images
 * Harbor » de la page « Versions des images », carte « Vulnérabilités (Harbor) » de l'onglet
 * « Versions » des services.
 *
 * @phpstan-type Link array{connection_id: int, host: string, repository: string,
 *     artifact: array<string, mixed>|null, method: string}
 */
class HarborArtifact extends CommonDBTM
{
    /** Filtres de la page : vulnérabilités critiques ou hautes ; analyse absente ou en échec. */
    public const FILTER_CRITICAL_HIGH = 'critical_high';
    public const FILTER_UNKNOWN = 'unknown';

    /** @var list<array{id: int, host: string, entities: list<int>, level: int, name: string}>|null */
    private static ?array $targets = null;

    /** @var array<string, list<array<string, mixed>>> Artefacts actifs par connexion et dépôt. */
    private static array $candidates = [];

    public static function getTypeName($nb = 0)
    {
        return $nb > 1 ? __('Images Harbor', 'dockerinventory') : __('Image Harbor', 'dockerinventory');
    }

    public static function getIcon()
    {
        return 'ti ti-shield-search';
    }

    public static function canView(): bool
    {
        return Service::canView();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canUpdate(): bool
    {
        return false;
    }

    public static function canDelete(): bool
    {
        return false;
    }

    public static function canPurge(): bool
    {
        return false;
    }

    /**
     * Onglet « Images Harbor » de la page des versions.
     */
    public static function getPageURL(bool $full = true): string
    {
        return ImageVersion::getOverviewURL($full) . '?view=harbor';
    }

    /**
     * Libellé, couleur, icône et aide de chaque gravité (chaîne vide : analyse inconnue).
     *
     * @return array<string, array{label: string, color: string, icon: string, help: string}>
     */
    public static function severityInfos(): array
    {
        return [
            HarborMapper::SEVERITY_CRITICAL => [
                'label' => __('Critique', 'dockerinventory'),
                'color' => 'danger',
                'icon'  => 'ti ti-alert-octagon',
                'help'  => __('Au moins une vulnérabilité critique.', 'dockerinventory'),
            ],
            HarborMapper::SEVERITY_HIGH => [
                'label' => __('Haute', 'dockerinventory'),
                'color' => 'orange',
                'icon'  => 'ti ti-alert-triangle',
                'help'  => __('Au moins une vulnérabilité haute, aucune critique.', 'dockerinventory'),
            ],
            HarborMapper::SEVERITY_MEDIUM => [
                'label' => __('Moyenne', 'dockerinventory'),
                'color' => 'warning',
                'icon'  => 'ti ti-alert-circle',
                'help'  => __('Vulnérabilités moyennes au plus.', 'dockerinventory'),
            ],
            HarborMapper::SEVERITY_LOW => [
                'label' => __('Basse', 'dockerinventory'),
                'color' => 'info',
                'icon'  => 'ti ti-info-circle',
                'help'  => __('Vulnérabilités basses seulement.', 'dockerinventory'),
            ],
            HarborMapper::SEVERITY_NONE => [
                'label' => __('Aucune vulnérabilité', 'dockerinventory'),
                'color' => 'success',
                'icon'  => 'ti ti-shield-check',
                'help'  => __('Analyse réussie sans vulnérabilité connue.', 'dockerinventory'),
            ],
            HarborMapper::SEVERITY_UNKNOWN => [
                'label' => __('Non analysée', 'dockerinventory'),
                'color' => 'secondary',
                'icon'  => 'ti ti-help-circle',
                'help'  => __(
                    'Pas d’analyse réussie dans Harbor (jamais lancée, en cours ou en échec).',
                    'dockerinventory'
                ),
            ],
        ];
    }

    /**
     * @return array{label: string, color: string, icon: string, help: string}
     */
    public static function severityInfo(string $severity): array
    {
        return self::severityInfos()[$severity] ?? self::severityInfos()[HarborMapper::SEVERITY_UNKNOWN];
    }

    /**
     * Pastille d'un artefact : sa gravité si l'analyse a réussi, sinon l'état de l'analyse (en cours,
     * en attente, en échec, arrêtée) plutôt que « non analysée ».
     *
     * @return array{label: string, color: string, icon: string, help: string}
     */
    public static function badgeInfo(string $severity, string $status): array
    {
        if ($severity !== HarborMapper::SEVERITY_UNKNOWN) {
            return self::severityInfo($severity);
        }
        $states = [
            HarborMapper::STATUS_RUNNING => ['info', 'ti ti-loader'],
            HarborMapper::STATUS_PENDING => ['info', 'ti ti-clock'],
            HarborMapper::STATUS_ERROR   => ['warning', 'ti ti-alert-circle'],
            HarborMapper::STATUS_STOPPED => ['secondary', 'ti ti-player-stop'],
        ];
        if (!isset($states[$status])) {
            return self::severityInfo(HarborMapper::SEVERITY_UNKNOWN);
        }

        return [
            'label' => self::statusLabels()[$status],
            'color' => $states[$status][0],
            'icon'  => $states[$status][1],
            'help'  => __(
                'Pas encore de résultat d’analyse dans Harbor pour cette image : voir l’état de l’analyse.',
                'dockerinventory'
            ),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function statusLabels(): array
    {
        return [
            HarborMapper::STATUS_SUCCESS     => __('Analyse réussie', 'dockerinventory'),
            HarborMapper::STATUS_ERROR       => __('Analyse en échec', 'dockerinventory'),
            HarborMapper::STATUS_RUNNING     => __('Analyse en cours', 'dockerinventory'),
            HarborMapper::STATUS_PENDING     => __('Analyse en attente', 'dockerinventory'),
            HarborMapper::STATUS_STOPPED     => __('Analyse arrêtée', 'dockerinventory'),
            HarborMapper::STATUS_NOT_SCANNED => __('Jamais analysée', 'dockerinventory'),
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
                'Par tag : empreinte déployée différente de celle de Harbor',
                'dockerinventory'
            ),
        ];
    }

    /**
     * @return array<string, array{label: string, color: string, icon: string, help: string}>
     */
    public static function provenanceInfos(): array
    {
        return [
            HarborLinker::PROVENANCE_MANAGED => [
                'label' => __('Registre maîtrisé', 'dockerinventory'),
                'color' => 'success',
                'icon'  => 'ti ti-building-warehouse',
                'help'  => __('L’image vient de l’un des registres maîtrisés.', 'dockerinventory'),
            ],
            HarborLinker::PROVENANCE_UNMANAGED => [
                'label' => __('Hors registre maîtrisé', 'dockerinventory'),
                'color' => 'danger',
                'icon'  => 'ti ti-world-download',
                'help'  => __(
                    'L’image ne vient d’aucun registre maîtrisé : ni analysée ni contrôlée par Harbor.',
                    'dockerinventory'
                ),
            ],
            HarborLinker::PROVENANCE_UNKNOWN => [
                'label' => __('Provenance non évaluée', 'dockerinventory'),
                'color' => 'secondary',
                'icon'  => 'ti ti-help-circle',
                'help'  => __(
                    'Aucun registre maîtrisé déclaré (ni connexion Harbor, ni liste).',
                    'dockerinventory'
                ),
            ],
        ];
    }

    /**
     * Connexions Harbor actives : hôte du registre, entités couvertes (entité la plus proche d'abord).
     *
     * @return list<array{id: int, host: string, entities: list<int>, level: int, name: string}>
     */
    public static function harborTargets(): array
    {
        global $DB;

        if (self::$targets !== null) {
            return self::$targets;
        }
        $targets = [];
        $where = ['is_active' => 1, 'type' => Connection::TYPE_HARBOR];
        foreach ($DB->request(['FROM' => Connection::getTable(), 'WHERE' => $where, 'ORDER' => 'id']) as $row) {
            $entity = $DB->request([
                'SELECT' => ['level'],
                'FROM'   => 'glpi_entities',
                'WHERE'  => ['id' => (int) $row['entities_id']],
            ])->current();
            $targets[] = [
                'id'       => (int) $row['id'],
                'host'     => RegistryResolver::harborHost(
                    (string) $row['url'],
                    (string) ($row['registry_host'] ?? '')
                ),
                'entities' => array_values(array_map(
                    'intval',
                    (new DbUtils())->getSonsOf('glpi_entities', (int) $row['entities_id'])
                )),
                'level'    => (int) ($entity['level'] ?? 0),
                'name'     => (string) $row['name'],
            ];
        }
        usort($targets, static fn (array $a, array $b): int => [$b['level'], $a['id']] <=> [$a['level'], $b['id']]);

        return self::$targets = $targets;
    }

    /**
     * Registres maîtrisés : la liste saisie, sinon les hôtes des connexions Harbor actives.
     *
     * @return list<string>
     */
    public static function managedHosts(): array
    {
        $configured = HarborSettings::configuredHosts();
        if ($configured !== []) {
            return $configured;
        }

        return array_values(array_unique(array_filter(array_column(self::harborTargets(), 'host'))));
    }

    /**
     * Relire les connexions et les artefacts (tests, après une lecture dans la même requête).
     */
    public static function resetCache(): void
    {
        self::$targets = null;
        self::$candidates = [];
        HarborSettings::reset();
    }

    /**
     * Artefact Harbor de l'image d'un service (empreinte, sinon tag), et la façon de le rattacher.
     *
     * @param array{image: string, tag: string, digest: string, entities_id: int} $service
     * @return Link
     */
    public static function linkService(array $service): array
    {
        $resolved = RegistryResolver::resolve($service['image'], $service['entities_id'], self::harborTargets());
        $link = [
            'connection_id' => 0,
            'host'          => $resolved['host'],
            'repository'    => $resolved['repository'],
            'artifact'      => null,
            'method'        => '',
        ];
        if ($resolved['kind'] !== RegistryClient::KIND_HARBOR || $resolved['connection_id'] === 0) {
            return $link;
        }
        $link['connection_id'] = $resolved['connection_id'];
        $rows = self::candidates($resolved['connection_id'], $resolved['repository']);
        $candidates = array_map(static fn (array $row): array => [
            'id'            => (int) $row['id'],
            'digest'        => (string) $row['digest'],
            'child_digests' => array_values(array_filter(explode(' ', (string) $row['child_digests']))),
            'tags'          => self::tagList((string) $row['tags']),
        ], $rows);
        $match = HarborLinker::match($service['digest'], $service['tag'], $candidates);
        if ($match !== null) {
            foreach ($rows as $row) {
                if ((int) $row['id'] === $match['id']) {
                    $link['artifact'] = $row;
                    $link['method'] = $match['method'];
                }
            }
        }

        return $link;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function candidates(int $connectionId, string $repository): array
    {
        global $DB;

        $key = $connectionId . ':' . $repository;
        if (!isset(self::$candidates[$key])) {
            $rows = [];
            foreach (
                $DB->request([
                    'FROM'  => static::getTable(),
                    'WHERE' => [
                        'plugin_dockerinventory_connections_id' => $connectionId,
                        'repository'                            => $repository,
                        'is_retired'                            => 0,
                    ],
                    // Le plus récent d'abord : un tag n'est porté que par un artefact, mais par prudence.
                    'ORDER' => ['push_time DESC', 'id DESC'],
                ]) as $row
            ) {
                $rows[] = $row;
            }
            self::$candidates[$key] = $rows;
        }

        return self::$candidates[$key];
    }

    /**
     * @return list<string>
     */
    public static function tagList(string $tags): array
    {
        return array_values(array_filter(
            array_map('trim', explode(',', $tags)),
            static fn (string $tag): bool => $tag !== ''
        ));
    }

    /**
     * Valeurs d'un artefact prêtes à afficher (échappées par Twig).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function present(array $row): array
    {
        $digest = (string) ($row['digest'] ?? '');
        $status = (string) ($row['scan_status'] ?? HarborMapper::STATUS_NOT_SCANNED);

        return [
            'id'           => (int) ($row['id'] ?? 0),
            'project'      => (string) ($row['project'] ?? ''),
            'repository'   => (string) ($row['repository'] ?? ''),
            'tags'         => self::tagList((string) ($row['tags'] ?? '')),
            'digest'       => $digest,
            'short_digest' => VersionLinker::shortDigest($digest),
            'size'         => self::formatSize((int) ($row['size'] ?? 0)),
            'push_time'    => (string) ($row['push_time'] ?? ''),
            'status'       => $status,
            'status_label' => self::statusLabels()[$status] ?? $status,
            'severity'     => self::badgeInfo((string) ($row['severity'] ?? ''), $status),
            'severity_code' => (string) ($row['severity'] ?? ''),
            'scanned'      => $status === HarborMapper::STATUS_SUCCESS,
            'counts'       => [
                HarborMapper::SEVERITY_CRITICAL => (int) ($row['critical'] ?? 0),
                HarborMapper::SEVERITY_HIGH     => (int) ($row['high'] ?? 0),
                HarborMapper::SEVERITY_MEDIUM   => (int) ($row['medium'] ?? 0),
                HarborMapper::SEVERITY_LOW      => (int) ($row['low'] ?? 0),
            ],
            'other'        => (int) ($row['other'] ?? 0),
            'total'        => (int) ($row['total'] ?? 0),
            'fixable'      => (int) ($row['fixable'] ?? 0),
            'scanner'      => (string) ($row['scanner'] ?? ''),
            'scan_time'    => (string) ($row['scan_time'] ?? ''),
            'is_retired'   => (bool) ($row['is_retired'] ?? false),
            'connection'   => (string) ($row['connection_name'] ?? ''),
            'services'     => [],
        ];
    }

    /**
     * Taille lisible (« 187,7 Mo » selon la langue), chaîne vide si inconnue.
     */
    public static function formatSize(int $bytes): string
    {
        if ($bytes <= 0) {
            return '';
        }
        $units = [__('o', 'dockerinventory'), __('Ko', 'dockerinventory'), __('Mo', 'dockerinventory'),
            __('Go', 'dockerinventory')];
        $power = min(count($units) - 1, (int) floor(log($bytes, 1024)));

        return \Html::formatNumber($bytes / (1024 ** $power), false, $power === 0 ? 0 : 1) . ' ' . $units[$power];
    }

    /**
     * Carte « Vulnérabilités (Harbor) » de l'onglet Versions d'un service.
     *
     * @return array<string, mixed>
     */
    public static function forService(Service $service): array
    {
        $values = [
            'image'       => (string) $service->fields['image'],
            'tag'         => (string) $service->fields['tag'],
            'digest'      => (string) $service->fields['digest'],
            'entities_id' => (int) $service->fields['entities_id'],
        ];
        $link = self::linkService($values);
        $managed = self::managedHosts();
        $provenance = HarborLinker::provenance($values['image'], $managed);
        $artifact = $link['artifact'] !== null ? self::present($link['artifact']) : null;
        $ticket = null;
        if (\Ticket::canView()) {
            $found = VulnTickets::ticketFor((int) $service->getID());
            $ticket = $found === null ? null : $found + ['url' => \Ticket::getFormURLWithID($found['id'])];
        }

        return [
            'has_image'     => $values['image'] !== '',
            'host'          => $link['host'],
            'repository'    => $link['repository'],
            'in_harbor'     => $link['connection_id'] > 0,
            'connection'    => self::targetName($link['connection_id']),
            'artifact'      => $artifact,
            'method'        => $link['method'],
            'method_label'  => self::methodLabels()[$link['method']] ?? '',
            'provenance'    => self::provenanceInfos()[$provenance],
            'provenance_code' => $provenance,
            'managed_hosts' => $managed,
            'has_harbor'    => self::harborTargets() !== [],
            'ticket'        => $ticket,
            'page_url'      => self::getPageURL(),
            'severities'    => self::severityInfos(),
        ];
    }

    private static function targetName(int $connectionId): string
    {
        foreach (self::harborTargets() as $target) {
            if ($target['id'] === $connectionId) {
                return $target['name'];
            }
        }

        return '';
    }

    /**
     * Onglet « Images Harbor » : images déployées (provenance, artefact, gravité) et artefacts lus,
     * filtrés par gravité, provenance et projet.
     *
     * @param string $severity Gravité, FILTER_CRITICAL_HIGH, FILTER_UNKNOWN ou chaîne vide (toutes).
     * @param bool $unmanagedOnly Seulement les images déployées hors registre maîtrisé.
     * @param string $project Projet Harbor, chaîne vide : tous.
     * @param bool $deployedOnly Seulement les artefacts déployés.
     * @return array{deployed: list<array<string, mixed>>, artifacts: list<array<string, mixed>>,
     *     counts: array<string, int>, deployed_counts: array<string, int>, projects: list<string>,
     *     unmanaged: int, managed_hosts: list<string>}
     */
    public static function overview(string $severity, bool $unmanagedOnly, string $project, bool $deployedOnly): array
    {
        $managed = self::managedHosts();
        $deployed = [];
        $deployedCounts = array_fill_keys(array_keys(self::severityInfos()), 0);
        $unmanaged = 0;
        $byArtifact = [];
        foreach (self::services() as $row) {
            $link = self::linkService([
                'image'       => (string) $row['image'],
                'tag'         => (string) $row['tag'],
                'digest'      => (string) $row['digest'],
                'entities_id' => (int) $row['entities_id'],
            ]);
            $provenance = HarborLinker::provenance((string) $row['image'], $managed);
            $artifact = $link['artifact'] !== null ? self::present($link['artifact']) : null;
            // Gravité de l'artefact rattaché, quelle que soit la façon (empreinte différente : analyse
            // du tag actuel, signalée à côté).
            $code = $artifact !== null ? $artifact['severity_code'] : HarborMapper::SEVERITY_UNKNOWN;
            $service = [
                'id'          => (int) $row['id'],
                'name'        => (string) $row['name'],
                'url'         => Service::getFormURLWithID((int) $row['id']),
                'stack_name'  => (string) $row['stack_name'],
                'image'       => (string) $row['image'],
                'tag'         => (string) $row['tag'],
                'provenance'  => self::provenanceInfos()[$provenance],
                'provenance_code' => $provenance,
                'artifact'    => $artifact,
                'method'      => $link['method'],
                'method_label' => self::methodLabels()[$link['method']] ?? '',
                'severity'    => $artifact !== null ? $artifact['severity'] : self::severityInfo($code),
                'severity_code' => $code,
            ];
            if ($artifact !== null) {
                $byArtifact[$artifact['id']][] = ['name' => $service['name'], 'url' => $service['url']];
            }
            $deployedCounts[$code] = ($deployedCounts[$code] ?? 0) + 1;
            $unmanaged += $provenance === HarborLinker::PROVENANCE_UNMANAGED ? 1 : 0;
            if (
                self::matchesSeverity($code, $severity)
                && (!$unmanagedOnly || $provenance === HarborLinker::PROVENANCE_UNMANAGED)
                && ($project === '' || ($artifact !== null && $artifact['project'] === $project))
            ) {
                $deployed[] = $service;
            }
        }
        usort($deployed, static fn (array $a, array $b): int => [
            HarborMapper::rank($a['severity_code']), $a['provenance_code'] !== HarborLinker::PROVENANCE_UNMANAGED,
            $a['stack_name'], $a['name'],
        ] <=> [
            HarborMapper::rank($b['severity_code']), $b['provenance_code'] !== HarborLinker::PROVENANCE_UNMANAGED,
            $b['stack_name'], $b['name'],
        ]);

        $artifacts = [];
        $counts = array_fill_keys(array_keys(self::severityInfos()), 0);
        $projects = [];
        foreach (self::artifacts() as $row) {
            $artifact = self::present($row);
            $artifact['services'] = $byArtifact[$artifact['id']] ?? [];
            $projects[$artifact['project']] = true;
            $counts[$artifact['severity_code']] = ($counts[$artifact['severity_code']] ?? 0) + 1;
            if (
                self::matchesSeverity($artifact['severity_code'], $severity)
                && ($project === '' || $artifact['project'] === $project)
                && (!$deployedOnly || $artifact['services'] !== [])
            ) {
                $artifacts[] = $artifact;
            }
        }
        usort($artifacts, static fn (array $a, array $b): int => [
            $a['services'] === [], HarborMapper::rank($a['severity_code']), $a['repository'], $b['push_time'],
        ] <=> [
            $b['services'] === [], HarborMapper::rank($b['severity_code']), $b['repository'], $a['push_time'],
        ]);
        $projectNames = array_keys($projects);
        sort($projectNames);

        return [
            'deployed'        => $deployed,
            'artifacts'       => $artifacts,
            'counts'          => $counts,
            'deployed_counts' => $deployedCounts,
            'projects'        => $projectNames,
            'unmanaged'       => $unmanaged,
            'managed_hosts'   => $managed,
        ];
    }

    private static function matchesSeverity(string $code, string $filter): bool
    {
        return match ($filter) {
            ''                         => true,
            self::FILTER_CRITICAL_HIGH => in_array(
                $code,
                [HarborMapper::SEVERITY_CRITICAL, HarborMapper::SEVERITY_HIGH],
                true
            ),
            self::FILTER_UNKNOWN       => $code === HarborMapper::SEVERITY_UNKNOWN,
            default                    => $code === $filter,
        };
    }

    /**
     * Services actifs avec une image, dans les entités actives.
     *
     * @return list<array<string, mixed>>
     */
    private static function services(): array
    {
        global $DB;

        $rows = [];
        foreach (
            $DB->request([
                'SELECT' => ['id', 'name', 'stack_name', 'image', 'tag', 'digest', 'entities_id'],
                'FROM'   => Service::getTable(),
                'WHERE'  => ['is_retired' => 0, ['NOT' => ['image' => '']]]
                    + (new DbUtils())->getEntitiesRestrictCriteria(Service::getTable(), '', '', true),
                'ORDER'  => ['stack_name', 'name'],
            ]) as $row
        ) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Artefacts actifs dans les entités actives, avec le nom de leur connexion.
     *
     * @return list<array<string, mixed>>
     */
    private static function artifacts(): array
    {
        global $DB;

        $rows = [];
        foreach (
            $DB->request([
                'SELECT'    => ['a.*', 'c.name AS connection_name'],
                'FROM'      => static::getTable() . ' AS a',
                'LEFT JOIN' => [
                    Connection::getTable() . ' AS c' => [
                        'ON' => ['a' => 'plugin_dockerinventory_connections_id', 'c' => 'id'],
                    ],
                ],
                'WHERE'     => ['a.is_retired' => 0]
                    + (new DbUtils())->getEntitiesRestrictCriteria('a', '', '', true),
                'ORDER'     => ['a.repository', 'a.push_time DESC'],
            ]) as $row
        ) {
            $rows[] = $row;
        }

        return $rows;
    }
}

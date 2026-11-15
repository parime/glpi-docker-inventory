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

namespace GlpiPlugin\Dockerinventory\Api;

use DbUtils;
use GlpiPlugin\Dockerinventory\Connection;
use GlpiPlugin\Dockerinventory\Harbor\HarborLinker;
use GlpiPlugin\Dockerinventory\Harbor\HarborMapper;
use GlpiPlugin\Dockerinventory\HarborArtifact;
use GlpiPlugin\Dockerinventory\ImageVersion;
use GlpiPlugin\Dockerinventory\Node;
use GlpiPlugin\Dockerinventory\Service;
use GlpiPlugin\Dockerinventory\Version\VersionChange;
use GlpiPlugin\Dockerinventory\Version\VersionComparator;
use GlpiPlugin\Dockerinventory\Version\VersionLinker;

/**
 * API de lecture publique et stable (#10), pour les autres plugins (preuves de GRC Manager :
 * A.5.9, A.8.8, A.8.9, A.8.32). Lecture seule : aucune méthode n'écrit.
 *
 * Contrat :
 * - chaque méthode renvoie null si l'utilisateur n'a pas le droit de lecture des services du
 *   plugin ; sinon, seulement ce qu'il voit dans le plugin (entités actives, droits) ;
 * - chaque ligne porte son `entities_id`, pour que l'appelant puisse revérifier ;
 * - les libellés, couleurs et icônes Tabler viennent du plugin (dans la langue de la session) ;
 * - VERSION augmente quand une clé disparaît ou change de sens ; une clé ajoutée ne la change pas.
 */
final class Evidence
{
    public const VERSION = 1;

    /** Écarts de version qui demandent une mise à jour (correctif, mineure, majeure). */
    public const OUTDATED_GAPS = [
        VersionComparator::GAP_MAJOR,
        VersionComparator::GAP_MINOR,
        VersionComparator::GAP_PATCH,
    ];

    public static function canRead(): bool
    {
        return Service::canView();
    }

    /**
     * Inventaire (A.5.9) : services, stacks et nœuds actifs ou retirés, et, pour qui peut voir les
     * connexions, la date du dernier import réussi de chacune.
     *
     * @return array{services: int, retired_services: int, stacks: int, nodes: int,
     *     services_url: string, can_view_connections: bool,
     *     connections: list<array{id: int, name: string, type: string, type_label: string,
     *     entities_id: int, last_success_date: string, last_status: int, last_status_label: string,
     *     url: string}>}|null
     */
    public static function inventory(): ?array
    {
        global $DB;

        if (!self::canRead()) {
            return null;
        }
        $services = 0;
        $retired = 0;
        $stacks = [];
        foreach (
            $DB->request([
                'SELECT' => ['is_retired', 'plugin_dockerinventory_stacks_id'],
                'FROM'   => Service::getTable(),
                'WHERE'  => self::entityCriteria(Service::getTable()),
            ]) as $row
        ) {
            if ((int) $row['is_retired'] === 1) {
                $retired++;
                continue;
            }
            $services++;
            if ((int) $row['plugin_dockerinventory_stacks_id'] > 0) {
                $stacks[(int) $row['plugin_dockerinventory_stacks_id']] = true;
            }
        }

        // Les nœuds n'ont pas d'entité : celle de leur connexion.
        $nodes = 0;
        foreach (
            $DB->request([
                'SELECT'     => ['n.id'],
                'FROM'       => Node::getTable() . ' AS n',
                'INNER JOIN' => [
                    Connection::getTable() . ' AS c' => [
                        'ON' => ['n' => 'plugin_dockerinventory_connections_id', 'c' => 'id'],
                    ],
                ],
                'WHERE'      => ['n.is_retired' => 0] + self::entityCriteria('c'),
            ]) as $row
        ) {
            $nodes++;
        }

        $connections = [];
        $canViewConnections = Connection::canView();
        if ($canViewConnections) {
            $types = Connection::typeLabels();
            $columns = ['id', 'name', 'type', 'entities_id', 'last_import_status'];
            if (Connection::hasSuccessDate()) {
                $columns[] = 'last_success_date';
            }
            foreach (
                $DB->request([
                    'SELECT' => $columns,
                    'FROM'   => Connection::getTable(),
                    'WHERE'  => ['is_active' => 1] + self::entityCriteria(Connection::getTable()),
                    'ORDER'  => ['type', 'name'],
                ]) as $row
            ) {
                $type = (string) $row['type'];
                $connections[] = [
                    'id'                => (int) $row['id'],
                    'name'              => (string) $row['name'],
                    'type'              => $type,
                    'type_label'        => $types[$type] ?? $type,
                    'entities_id'       => (int) $row['entities_id'],
                    'last_success_date' => (string) ($row['last_success_date'] ?? ''),
                    'last_status'       => (int) $row['last_import_status'],
                    'last_status_label' => Connection::statusLabel((int) $row['last_import_status']),
                    'url'               => Connection::getFormURLWithID((int) $row['id']),
                ];
            }
        }

        return [
            'services'             => $services,
            'retired_services'     => $retired,
            'stacks'               => count($stacks),
            'nodes'                => $nodes,
            'services_url'         => Service::getSearchURL(),
            'can_view_connections' => $canViewConnections,
            'connections'          => $connections,
        ];
    }

    /**
     * Écarts de version des services actifs (A.8.8) : nombre par écart, et les services en retard
     * d'un correctif, d'une version mineure ou majeure (les plus graves d'abord).
     *
     * @return array{counts: array<string, int>, gaps: array<string, array{label: string, color: string,
     *     icon: string, help: string}>, outdated: list<array{service: string, url: string, stack: string,
     *     image: string, deployed_tag: string, latest_tag: string, gap: string, entities_id: int}>,
     *     outdated_total: int, page_url: string}|null
     */
    public static function versionGaps(int $limit = 20): ?array
    {
        if (!self::canRead()) {
            return null;
        }
        $counts = array_fill_keys(VersionComparator::GAPS, 0);
        $outdated = [];
        foreach (ImageVersion::fetch(['v.plugin_dockerinventory_services_id' => ['>', 0]]) as $row) {
            if ((int) ($row['service_retired'] ?? 0) === 1 || ($row['service_name'] ?? null) === null) {
                continue;
            }
            $gap = (string) $row['gap'];
            $counts[$gap] = ($counts[$gap] ?? 0) + 1;
            if (in_array($gap, self::OUTDATED_GAPS, true)) {
                $outdated[] = [
                    'service'      => (string) $row['service_name'],
                    'url'          => Service::getFormURLWithID((int) $row['plugin_dockerinventory_services_id']),
                    'stack'        => (string) ($row['stack_name'] ?? ''),
                    'image'        => (string) $row['image'],
                    'deployed_tag' => (string) $row['deployed_tag'],
                    'latest_tag'   => (string) $row['latest_tag'],
                    'gap'          => $gap,
                    'entities_id'  => (int) $row['entities_id'],
                ];
            }
        }

        return [
            'counts'         => $counts,
            'gaps'           => ImageVersion::gapInfos(),
            'outdated'       => array_slice($outdated, 0, max(0, $limit)),
            'outdated_total' => count($outdated),
            'page_url'       => ImageVersion::getOverviewURL(),
        ];
    }

    /**
     * Vulnérabilités des images déployées, d'après les analyses de Harbor (A.8.8) : nombre de
     * services par gravité, et les services dont l'image a des vulnérabilités critiques ou hautes.
     *
     * @return array{has_harbor: bool, counts: array<string, int>, severities: array<string,
     *     array{label: string, color: string, icon: string, help: string}>,
     *     services: list<array{service: string, url: string, stack: string, image: string, tag: string,
     *     severity: string, critical: int, high: int, fixable: int, entities_id: int}>,
     *     services_total: int, page_url: string}|null
     */
    public static function vulnerabilities(int $limit = 20): ?array
    {
        if (!self::canRead()) {
            return null;
        }
        $overview = HarborArtifact::overview('', false, '', false);
        $entities = self::serviceEntities();
        $rows = [];
        foreach ($overview['deployed'] as $service) {
            $code = (string) $service['severity_code'];
            $artifact = $service['artifact'];
            if (
                !is_array($artifact)
                || !in_array($code, [HarborMapper::SEVERITY_CRITICAL, HarborMapper::SEVERITY_HIGH], true)
                || !isset($entities[(int) $service['id']])
            ) {
                continue;
            }
            $rows[] = [
                'service'     => (string) $service['name'],
                'url'         => (string) $service['url'],
                'stack'       => (string) $service['stack_name'],
                'image'       => (string) $service['image'],
                'tag'         => (string) $service['tag'],
                'severity'    => $code,
                'critical'    => (int) $artifact['counts'][HarborMapper::SEVERITY_CRITICAL],
                'high'        => (int) $artifact['counts'][HarborMapper::SEVERITY_HIGH],
                'fixable'     => (int) $artifact['fixable'],
                'entities_id' => $entities[(int) $service['id']],
            ];
        }

        return [
            'has_harbor'     => HarborArtifact::harborTargets() !== [],
            'counts'         => $overview['deployed_counts'],
            'severities'     => HarborArtifact::severityInfos(),
            'services'       => array_slice($rows, 0, max(0, $limit)),
            'services_total' => count($rows),
            'page_url'       => HarborArtifact::getPageURL(),
        ];
    }

    /**
     * Configuration des services actifs (A.8.9) : image, tag, empreinte, réplicas et ports, et la
     * provenance de chaque image (registre maîtrisé ou non). La liste des registres maîtrisés n'est
     * pas renvoyée : elle comprend les hôtes des connexions Harbor de toutes les entités.
     *
     * @return array{services: list<array{service: string, url: string, stack: string, image: string,
     *     tag: string, digest: string, replicas: string, replicas_ok: bool, ports: list<string>,
     *     provenance: string, entities_id: int}>, services_total: int, provenance_counts: array<string, int>,
     *     provenances: array<string, array{label: string, color: string, icon: string, help: string}>,
     *     services_url: string}|null
     */
    public static function configuration(int $limit = 50): ?array
    {
        global $DB;

        if (!self::canRead()) {
            return null;
        }
        $managed = HarborArtifact::managedHosts();
        $counts = [
            HarborLinker::PROVENANCE_MANAGED   => 0,
            HarborLinker::PROVENANCE_UNMANAGED => 0,
            HarborLinker::PROVENANCE_UNKNOWN   => 0,
        ];
        $rows = [];
        foreach (
            $DB->request([
                'FROM'  => Service::getTable(),
                'WHERE' => ['is_retired' => 0] + self::entityCriteria(Service::getTable()),
                'ORDER' => ['stack_name', 'name'],
            ]) as $row
        ) {
            $present = Service::present($row);
            $provenance = HarborLinker::provenance($present['image'], $managed);
            $counts[$provenance]++;
            $rows[] = [
                'service'     => $present['name'],
                'url'         => $present['url'],
                'stack'       => $present['stack_name'],
                'image'       => $present['image'],
                'tag'         => $present['tag'],
                'digest'      => VersionLinker::shortDigest($present['digest']),
                'replicas'    => $present['replicas'],
                'replicas_ok' => $present['replicas_ok'],
                'ports'       => $present['ports'],
                'provenance'  => $provenance,
                'entities_id' => (int) $row['entities_id'],
            ];
        }

        return [
            'services'          => array_slice($rows, 0, max(0, $limit)),
            'services_total'    => count($rows),
            'provenance_counts' => $counts,
            'provenances'       => HarborArtifact::provenanceInfos(),
            'services_url'      => Service::getSearchURL(),
        ];
    }

    /**
     * Changements de version observés depuis une date (A.8.32), les plus récents d'abord.
     *
     * @param string $since Date et heure (« AAAA-MM-JJ HH:MM:SS »).
     * @return array{changes: list<array{date: string, service: string, url: string, stack: string,
     *     kind: string, kind_label: string, old_image: string, new_image: string, old_tag: string,
     *     new_tag: string, old_digest: string, new_digest: string, entities_id: int}>, total: int}|null
     */
    public static function versionChanges(string $since, int $limit = 50): ?array
    {
        global $DB;

        if (!self::canRead() || !$DB->tableExists(VersionChange::TABLE)) {
            return null;
        }
        $where = ['date_change' => ['>=', $since]] + self::entityCriteria(VersionChange::TABLE);
        $labels = self::changeLabels();
        $changes = [];
        $total = 0;
        foreach (
            $DB->request([
                'FROM'  => VersionChange::TABLE,
                'WHERE' => $where,
                'ORDER' => ['date_change DESC', 'id DESC'],
            ]) as $row
        ) {
            $total++;
            if (count($changes) >= max(0, $limit)) {
                continue;
            }
            $serviceId = (int) $row['plugin_dockerinventory_services_id'];
            $kind = (string) $row['kind'];
            $changes[] = [
                'date'        => (string) $row['date_change'],
                'service'     => (string) $row['service_name'],
                'url'         => $serviceId > 0 ? Service::getFormURLWithID($serviceId) : '',
                'stack'       => (string) $row['stack_name'],
                'kind'        => $kind,
                'kind_label'  => $labels[$kind] ?? $kind,
                'old_image'   => (string) $row['old_image'],
                'new_image'   => (string) $row['new_image'],
                'old_tag'     => (string) $row['old_tag'],
                'new_tag'     => (string) $row['new_tag'],
                'old_digest'  => VersionLinker::shortDigest((string) $row['old_digest']),
                'new_digest'  => VersionLinker::shortDigest((string) $row['new_digest']),
                'entities_id' => (int) $row['entities_id'],
            ];
        }

        return ['changes' => $changes, 'total' => $total];
    }

    /**
     * @return array<string, string>
     */
    public static function changeLabels(): array
    {
        return [
            VersionChange::KIND_IMAGE  => __('Autre image', 'dockerinventory'),
            VersionChange::KIND_TAG    => __('Nouveau tag', 'dockerinventory'),
            VersionChange::KIND_DIGEST => __('Image reconstruite (même tag)', 'dockerinventory'),
        ];
    }

    /**
     * Entité des services actifs visibles, par identifiant.
     *
     * @return array<int, int>
     */
    private static function serviceEntities(): array
    {
        global $DB;

        $entities = [];
        foreach (
            $DB->request([
                'SELECT' => ['id', 'entities_id'],
                'FROM'   => Service::getTable(),
                'WHERE'  => ['is_retired' => 0] + self::entityCriteria(Service::getTable()),
            ]) as $row
        ) {
            $entities[(int) $row['id']] = (int) $row['entities_id'];
        }

        return $entities;
    }

    /**
     * @return array<int|string, mixed>
     */
    private static function entityCriteria(string $table): array
    {
        return (new DbUtils())->getEntitiesRestrictCriteria($table, '', '', true);
    }
}

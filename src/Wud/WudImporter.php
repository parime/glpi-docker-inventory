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

namespace GlpiPlugin\Dockerinventory\Wud;

use DbUtils;
use GlpiPlugin\Dockerinventory\Connection;
use GlpiPlugin\Dockerinventory\ImageVersion;
use GlpiPlugin\Dockerinventory\Service;
use GlpiPlugin\Dockerinventory\Ticket\UpdateTickets;
use GlpiPlugin\Dockerinventory\Version\VersionComparator;
use GlpiPlugin\Dockerinventory\Version\VersionLinker;

/**
 * Lecture d'une connexion WUD et mise à jour des versions des images.
 *
 * Règles :
 * - WUD est seulement lu (GET /api/app, GET /api/containers) ; son calcul de l'écart est repris ;
 * - chaque conteneur est rattaché à un service actif de l'entité de la connexion (ou de ses
 *   sous-entités) : par identifiant ou nom du conteneur (hôtes autonomes), puis par empreinte, puis
 *   par nom du service Swarm, puis par image et tag ;
 * - une ligne par service, une ligne par conteneur sans service (listé à part) ;
 * - seules les tables du plugin sont écrites ; les lignes de cette connexion qui ne correspondent
 *   plus à rien sont supprimées (données recalculées, comme la répartition des tâches) ;
 * - WUD injoignable ou en erreur : rien n'est modifié ;
 * - un service rattaché perd sa ligne du repli par les registres (source « registry ») ;
 * - tickets de mise à jour ensuite, si la connexion Portainer du service les a activés.
 *
 * @phpstan-import-type WudContainer from WudMapper
 * @phpstan-type WudReport array{status: int, wud_version: string, containers: int, services: int,
 *     unmatched: int, out_of_sync: int, gaps: array<string, int>,
 *     errors: list<array{endpoint: string, code: string}>,
 *     tickets: array{created: int, followups: int, unchanged: int, errors: int}}
 */
final class WudImporter
{
    /** @var \Closure(Connection): WudClient */
    private \Closure $clientFactory;

    private string $now = '';

    /**
     * @param (\Closure(Connection): WudClient)|null $clientFactory
     */
    public function __construct(?\Closure $clientFactory = null)
    {
        $this->clientFactory = $clientFactory ?? static fn (Connection $connection): WudClient
            => $connection->getWudClient();
    }

    /**
     * @return WudReport
     */
    public function import(Connection $connection): array
    {
        global $DB;

        $this->now = (string) ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'));
        $report = [
            'status'      => Connection::STATUS_OK,
            'wud_version' => '',
            'containers'  => 0,
            'services'    => 0,
            'unmatched'   => 0,
            'out_of_sync' => 0,
            'gaps'        => array_fill_keys(VersionComparator::GAPS, 0),
            'errors'      => [],
            'tickets'     => UpdateTickets::emptyReport(),
        ];

        try {
            $client = ($this->clientFactory)($connection);
            $report['wud_version'] = $client->version();
            $raw = $client->containers();
        } catch (WudException $e) {
            $report['errors'][] = ['endpoint' => '', 'code' => $e->getMessage()];
            $report['status'] = Connection::STATUS_ERROR;
            $this->saveReport($connection, $report);

            return $report;
        }

        $containers = [];
        foreach ($raw as $container) {
            $mapped = WudMapper::mapContainer($container);
            if ($mapped !== null) {
                $containers[] = $mapped;
            }
        }
        $report['containers'] = count($containers);

        $services = $this->candidateServices($connection);
        $links = VersionLinker::link($containers, array_values($services));
        $kept = [];
        foreach ($links['linked'] as $serviceId => $linked) {
            if ($linked === []) {
                continue;
            }
            $service = $services[$serviceId];
            $values = VersionLinker::summarize($service, $linked);
            $kept[] = $this->upsert(
                $connection,
                $serviceId,
                '',
                $values,
                $service['entities_id'],
                $service['is_recursive']
            );
            $report['services']++;
            $report['out_of_sync'] += $values['is_out_of_sync'];
            $report['gaps'][$values['gap']] = ($report['gaps'][$values['gap']] ?? 0) + 1;
        }
        foreach ($links['unmatched'] as $container) {
            $values = VersionLinker::summarize(
                ['id' => 0, 'name' => '', 'image' => '', 'tag' => $container['tag'], 'digest' => $container['digest']],
                [$container]
            );
            $values['match_method'] = $container['reason'] ?? VersionLinker::UNMATCHED_NO_SERVICE;
            $kept[] = $this->upsert(
                $connection,
                0,
                $container['uid'],
                $values,
                (int) $connection->fields['entities_id'],
                (int) $connection->fields['is_recursive']
            );
            $report['unmatched']++;
        }

        $this->deleteOthers((int) $connection->getID(), $kept);
        $covered = array_map('intval', array_keys(array_filter($links['linked'])));
        if ($covered !== []) {
            // Services désormais suivis par WUD : la ligne du repli par les registres ne sert plus.
            $DB->delete(ImageVersion::getTable(), [
                'source'                             => ImageVersion::SOURCE_REGISTRY,
                'plugin_dockerinventory_services_id' => $covered,
            ]);
        }
        $report['tickets'] = (new UpdateTickets())->process($covered);
        $this->saveReport($connection, $report);

        return $report;
    }

    /**
     * Services actifs de l'entité de la connexion et de ses sous-entités.
     *
     * @return array<int, array{id: int, name: string, image: string, tag: string, digest: string,
     *     container_uids: list<string>, container_names: list<string>, entities_id: int, is_recursive: int}>
     */
    private function candidateServices(Connection $connection): array
    {
        global $DB;

        $entities = (new DbUtils())->getSonsOf('glpi_entities', (int) $connection->fields['entities_id']);
        $services = [];
        $iterator = $DB->request([
            'SELECT' => [
                'id', 'name', 'image', 'tag', 'digest', 'container_uids', 'container_names', 'entities_id',
                'is_recursive',
            ],
            'FROM'   => Service::getTable(),
            'WHERE'  => ['is_retired' => 0, 'entities_id' => array_values($entities)],
            'ORDER'  => 'id',
        ]);
        foreach ($iterator as $row) {
            $services[(int) $row['id']] = [
                'id'           => (int) $row['id'],
                'name'         => (string) $row['name'],
                'image'        => (string) $row['image'],
                'tag'          => (string) $row['tag'],
                'digest'       => (string) $row['digest'],
                // Conteneurs des services des hôtes autonomes (vides pour un service Swarm).
                'container_uids'  => self::split((string) $row['container_uids']),
                'container_names' => self::split((string) $row['container_names']),
                'entities_id'  => (int) $row['entities_id'],
                'is_recursive' => (int) $row['is_recursive'],
            ];
        }

        return $services;
    }

    /**
     * @return list<string>
     */
    private static function split(string $value): array
    {
        return $value === '' ? [] : explode(', ', $value);
    }

    /**
     * @param array<string, int|string> $values Valeurs de VersionLinker::summarize().
     */
    private function upsert(
        Connection $connection,
        int $serviceId,
        string $containerUid,
        array $values,
        int $entityId,
        int $isRecursive
    ): int {
        global $DB;

        $table = ImageVersion::getTable();
        $where = [
            'plugin_dockerinventory_connections_id' => (int) $connection->getID(),
            'plugin_dockerinventory_services_id'    => $serviceId,
            'container_uid'                         => mb_substr($containerUid, 0, 128),
        ];
        $row = [
            'entities_id'       => $entityId,
            'is_recursive'      => $isRecursive,
            'containers'        => (string) $values['containers'],
            'container_count'   => (int) $values['container_count'],
            'image'             => mb_substr((string) $values['image'], 0, 255),
            'registry_name'     => mb_substr((string) $values['registry_name'], 0, 255),
            'registry_url'      => mb_substr((string) $values['registry_url'], 0, 255),
            'deployed_tag'      => mb_substr((string) $values['deployed_tag'], 0, 255),
            'deployed_digest'   => mb_substr((string) $values['deployed_digest'], 0, 255),
            'latest_tag'        => mb_substr((string) $values['latest_tag'], 0, 255),
            'latest_digest'     => mb_substr((string) $values['latest_digest'], 0, 255),
            'gap'               => (string) $values['gap'],
            'update_available'  => (int) $values['update_available'],
            'is_out_of_sync'    => (int) $values['is_out_of_sync'],
            'deployed_versions' => (string) $values['deployed_versions'],
            'match_method'      => (string) $values['match_method'],
            'source'            => ImageVersion::SOURCE_WUD,
            'error'             => mb_substr((string) $values['error'], 0, 255),
            'date_checked'      => $this->now,
            'date_mod'          => $this->now,
        ];
        $existing = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => $table,
            'WHERE'  => $where,
            'LIMIT'  => 1,
        ])->current();
        if (is_array($existing)) {
            $DB->update($table, $row, ['id' => (int) $existing['id']]);

            return (int) $existing['id'];
        }
        $DB->insert($table, $where + $row + ['date_creation' => $this->now]);

        return (int) $DB->insertId();
    }

    /**
     * @param list<int> $kept
     */
    private function deleteOthers(int $connectionId, array $kept): void
    {
        global $DB;

        $where = ['plugin_dockerinventory_connections_id' => $connectionId];
        if ($kept !== []) {
            $where[] = ['NOT' => ['id' => $kept]];
        }
        $DB->delete(ImageVersion::getTable(), $where);
    }

    /**
     * @param WudReport $report
     */
    private function saveReport(Connection $connection, array $report): void
    {
        global $DB;

        $DB->update(Connection::getTable(), [
            'last_import_date'   => $this->now,
            'last_import_status' => $report['status'],
            'last_import_report' => (string) json_encode($report),
        ], ['id' => (int) $connection->getID()]);
        $connection->getFromDB((int) $connection->getID());
    }
}

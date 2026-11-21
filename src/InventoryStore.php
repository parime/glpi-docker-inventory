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

use Appliance;
use DbUtils;
use GlpiPlugin\Dockerinventory\Portainer\PortainerMapper;
use GlpiPlugin\Dockerinventory\Version\VersionChange;

/**
 * Écritures communes des imports de l'inventaire (Portainer, Kubernetes) dans les tables du plugin :
 * stacks (ou namespaces) et leur application GLPI, services, nœuds rattachés aux ordinateurs,
 * répartition sur les nœuds, retraits, compte rendu de la connexion.
 *
 * Seule écriture hors des tables du plugin : la création, une fois, d'une application (Appliance)
 * absente. Aucun ordinateur n'est créé ni modifié ; rien n'est jamais supprimé (« retiré »).
 */
final class InventoryStore
{
    public const STACKS_TABLE = 'glpi_plugin_dockerinventory_stacks';

    public const PLACEMENT_TABLE = 'glpi_plugin_dockerinventory_services_nodes';

    /** Applications créées par cet import. */
    public int $appliancesCreated = 0;

    /** Nœuds rattachés à un ordinateur par cet import. */
    public int $linkedNodes = 0;

    public function __construct(private readonly string $now)
    {
    }

    public function now(): string
    {
        return $this->now;
    }

    /**
     * Stack gérée par la source (identifiant de stack), ou regroupement repéré par son nom
     * (identifiant 0 : environnement et nom du projet Compose, ou namespace Kubernetes).
     *
     * @param string $applianceComment Commentaire d'une application créée.
     * @return array{id: int, appliances_id: int}
     */
    public function upsertStack(
        Connection $connection,
        int $endpointId,
        int $stackUid,
        string $name,
        string $kind,
        string $applianceComment
    ): array {
        global $DB;

        $table = self::STACKS_TABLE;
        $where = ['plugin_dockerinventory_connections_id' => (int) $connection->getID(), 'stack_uid' => $stackUid];
        if ($stackUid === 0) {
            $where += ['endpoint_uid' => $endpointId, 'compose_project' => $name];
        }
        $existing = $DB->request(['FROM' => $table, 'WHERE' => $where, 'LIMIT' => 1])->current();
        $applianceId = $this->ensureAppliance(
            $connection,
            $name,
            $applianceComment,
            (int) ($existing['appliances_id'] ?? 0)
        );
        $values = [
            'endpoint_uid'  => $endpointId,
            'kind'          => $kind,
            'name'          => $name,
            'appliances_id' => $applianceId,
            'is_retired'    => 0,
            'last_seen'     => $this->now,
            'date_mod'      => $this->now,
        ];
        if (is_array($existing)) {
            $DB->update($table, $values, ['id' => (int) $existing['id']]);

            return ['id' => (int) $existing['id'], 'appliances_id' => $applianceId];
        }
        $DB->insert($table, $where + $values + ['date_creation' => $this->now]);

        return ['id' => (int) $DB->insertId(), 'appliances_id' => $applianceId];
    }

    /**
     * Application GLPI de la stack : celle déjà liée, sinon une application du même nom dans
     * l'entité de la connexion, sinon une nouvelle (environnement choisi sur la connexion). Une
     * application existante n'est jamais modifiée ; une application mise à la corbeille reste liée
     * et n'est pas restaurée.
     *
     */
    private function ensureAppliance(
        Connection $connection,
        string $name,
        string $comment,
        int $currentId
    ): int {
        global $DB;

        if ($currentId > 0 && countElementsInTable(Appliance::getTable(), ['id' => $currentId]) > 0) {
            return $currentId;
        }
        $entityId = (int) $connection->fields['entities_id'];
        $match = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => Appliance::getTable(),
            'WHERE'  => ['name' => $name, 'entities_id' => $entityId, 'is_deleted' => 0],
            'ORDER'  => 'id',
            'LIMIT'  => 1,
        ])->current();
        if (is_array($match)) {
            return (int) $match['id'];
        }

        $appliance = new Appliance();
        $id = $appliance->add([
            'name'                     => $name,
            'entities_id'              => $entityId,
            'is_recursive'             => (int) $connection->fields['is_recursive'],
            'applianceenvironments_id' => (int) $connection->fields['applianceenvironments_id'],
            'comment'                  => $comment,
        ]);
        if ($id === false) {
            return 0;
        }
        $this->appliancesCreated++;

        return (int) $id;
    }

    /**
     * @param array{node_uid: string, hostname: string, role: string, availability: string, state: string,
     *     engine_version: string, address: string, addresses?: list<string>} $node
     */
    public function upsertNode(Connection $connection, int $endpointId, array $node): int
    {
        global $DB;

        $table = Node::getTable();
        $where = [
            'plugin_dockerinventory_connections_id' => (int) $connection->getID(),
            'node_uid'                              => $node['node_uid'],
        ];
        $computerId = $this->matchComputer($connection, $node['hostname'], $node['addresses'] ?? []);
        if ($computerId > 0) {
            $this->linkedNodes++;
        }
        $values = [
            'endpoint_uid'   => $endpointId,
            'hostname'       => $node['hostname'],
            'role'           => $node['role'],
            'availability'   => $node['availability'],
            'state'          => $node['state'],
            'engine_version' => $node['engine_version'],
            'address'        => $node['address'],
            'computers_id'   => $computerId,
            'is_retired'     => 0,
            'last_seen'      => $this->now,
            'date_mod'       => $this->now,
        ];
        $existing = $DB->request(['SELECT' => ['id'], 'FROM' => $table, 'WHERE' => $where, 'LIMIT' => 1])->current();
        if (is_array($existing)) {
            $DB->update($table, $values, ['id' => (int) $existing['id']]);

            return (int) $existing['id'];
        }
        $DB->insert($table, $where + $values + ['date_creation' => $this->now]);

        return (int) $DB->insertId();
    }

    /**
     * Ordinateur dont le nom est le nom d'hôte du nœud (complet ou court), sinon qui porte l'une de
     * ses adresses IP, dans l'entité de la connexion ou ses sous-entités. Zéro si aucun ou plusieurs.
     *
     * @param list<string> $addresses
     */
    public function matchComputer(Connection $connection, string $hostname, array $addresses = []): int
    {
        global $DB;

        $entities = array_values((new DbUtils())->getSonsOf('glpi_entities', (int) $connection->fields['entities_id']));
        $base = ['is_deleted' => 0, 'is_template' => 0, 'entities_id' => $entities];
        if ($hostname !== '') {
            $rows = iterator_to_array($DB->request([
                'SELECT' => ['id'],
                'FROM'   => 'glpi_computers',
                'WHERE'  => [
                    'name' => array_values(array_unique([$hostname, PortainerMapper::shortHostname($hostname)])),
                ] + $base,
                'LIMIT'  => 2,
            ]), false);
            if (count($rows) === 1) {
                return (int) $rows[0]['id'];
            }
            if (count($rows) > 1) {
                return 0;
            }
        }
        $addresses = array_values(array_filter($addresses, static fn (string $a): bool => $a !== ''));
        if ($addresses === []) {
            return 0;
        }
        $ids = [];
        $iterator = $DB->request([
            'SELECT' => ['mainitems_id'],
            'FROM'   => 'glpi_ipaddresses',
            'WHERE'  => [
                'name'         => $addresses,
                'mainitemtype' => 'Computer',
                'is_deleted'   => 0,
            ],
        ]);
        foreach ($iterator as $row) {
            $ids[(int) $row['mainitems_id']] = true;
        }
        if ($ids === []) {
            return 0;
        }
        $rows = iterator_to_array($DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_computers',
            'WHERE'  => ['id' => array_keys($ids)] + $base,
            'LIMIT'  => 2,
        ]), false);

        return count($rows) === 1 ? (int) $rows[0]['id'] : 0;
    }

    /**
     * @param array<string, mixed> $service Valeurs d'un mapper (PortainerMapper, KubernetesMapper).
     * @param array{id: int, appliances_id: int} $stackRow
     * @param bool $keepDigest Empreinte gardée telle qu'enregistrée (pods illisibles cette fois).
     */
    public function upsertService(
        Connection $connection,
        int $endpointId,
        string $endpointName,
        array $service,
        array $stackRow,
        bool $keepDigest = false
    ): int {
        global $DB;

        $table = Service::getTable();
        $where = [
            'plugin_dockerinventory_connections_id' => (int) $connection->getID(),
            'service_uid'                           => $service['service_uid'],
        ];
        $values = [
            'name'             => $service['name'],
            'entities_id'      => (int) $connection->fields['entities_id'],
            'is_recursive'     => (int) $connection->fields['is_recursive'],
            'endpoint_uid'     => $endpointId,
            'endpoint_name'    => $endpointName,
            'stack_name'       => $service['stack_name'],
            'plugin_dockerinventory_stacks_id' => $stackRow['id'],
            'appliances_id'    => $stackRow['appliances_id'],
            'image'            => $service['image'],
            'tag'              => $service['tag'],
            'digest'           => $service['digest'],
            'mode'             => $service['mode'],
            'replicas'         => $service['replicas'],
            'running_replicas' => $service['running_replicas'],
            'ports'            => $service['ports'],
            'networks'         => $service['networks'],
            'secrets'          => $service['secrets'],
            'kind'             => $service['kind'] ?? PortainerMapper::KIND_SWARM,
            'volumes'          => $service['volumes'] ?? '',
            'state'            => $service['state'] ?? '',
            'restart_policy'   => $service['restart_policy'] ?? '',
            'container_uids'   => $service['container_uids'] ?? '',
            'container_names'  => $service['container_names'] ?? '',
            'is_retired'       => 0,
            'date_retired'     => null,
            'last_seen'        => $this->now,
            'date_mod'         => $this->now,
        ];
        // Routes (#72) : seulement si la source les a lues ; sinon celles du dernier import restent.
        if (array_key_exists('routes', $service)) {
            $values['routes'] = (string) $service['routes'];
        }
        // Configuration à risque (#71) : seulement si la source l'a lue ; sinon l'analyse précédente reste.
        if (array_key_exists('hardening', $service)) {
            $values['hardening'] = (string) $service['hardening'];
        }
        $existing = $DB->request([
            'SELECT' => ['id', 'image', 'tag', 'digest'],
            'FROM'   => $table,
            'WHERE'  => $where,
            'LIMIT'  => 1,
        ])->current();
        if (is_array($existing)) {
            if ($keepDigest) {
                $values['digest'] = (string) $existing['digest'];
            }
            $DB->update($table, $values, ['id' => (int) $existing['id']]);
            $this->recordVersionChange((int) $existing['id'], $where, [
                'image'  => (string) $existing['image'],
                'tag'    => (string) $existing['tag'],
                'digest' => (string) $existing['digest'],
            ], $values);

            return (int) $existing['id'];
        }
        $DB->insert($table, $where + $values + ['date_creation' => $this->now]);

        return (int) $DB->insertId();
    }

    /**
     * Journal des changements de version (#10) : une ligne quand l'image, le tag ou l'empreinte d'un
     * service changent d'un import à l'autre (voir VersionChange), dans l'entité du service.
     *
     * @param array<string, mixed> $where Connexion et identifiant du service dans la source.
     * @param array{image: string, tag: string, digest: string} $old
     * @param array<string, mixed> $values Valeurs du service qui viennent d'être enregistrées.
     */
    private function recordVersionChange(int $serviceId, array $where, array $old, array $values): void
    {
        global $DB;

        $new = [
            'image'  => (string) $values['image'],
            'tag'    => (string) $values['tag'],
            'digest' => (string) $values['digest'],
        ];
        $kind = VersionChange::detect($old, $new);
        if ($kind === null || !$DB->tableExists(VersionChange::TABLE)) {
            return;
        }
        $DB->insert(VersionChange::TABLE, [
            'entities_id'                           => (int) $values['entities_id'],
            'is_recursive'                          => (int) $values['is_recursive'],
            'plugin_dockerinventory_connections_id' => (int) $where['plugin_dockerinventory_connections_id'],
            'plugin_dockerinventory_services_id'    => $serviceId,
            'service_name'                          => mb_substr((string) $values['name'], 0, 255),
            'stack_name'                            => mb_substr((string) $values['stack_name'], 0, 255),
            'kind'                                  => $kind,
            'old_image'                             => mb_substr($old['image'], 0, 255),
            'new_image'                             => mb_substr($new['image'], 0, 255),
            'old_tag'                               => mb_substr($old['tag'], 0, 255),
            'new_tag'                               => mb_substr($new['tag'], 0, 255),
            'old_digest'                            => mb_substr($old['digest'], 0, 255),
            'new_digest'                            => mb_substr($new['digest'], 0, 255),
            'date_change'                           => $this->now,
        ]);
    }

    /**
     * Répartition des tâches (ou pods) en cours du service sur les nœuds (recalculée).
     *
     * @param array<string, int> $placement Identifiant du nœud dans la source => tâches en cours.
     * @param array<string, int> $nodeRows Identifiant du nœud dans la source => ligne du plugin.
     */
    public function replacePlacement(int $serviceId, array $placement, array $nodeRows): void
    {
        global $DB;

        $DB->delete(self::PLACEMENT_TABLE, ['plugin_dockerinventory_services_id' => $serviceId]);
        foreach ($placement as $nodeUid => $count) {
            if (isset($nodeRows[$nodeUid])) {
                $DB->insert(self::PLACEMENT_TABLE, [
                    'plugin_dockerinventory_services_id' => $serviceId,
                    'plugin_dockerinventory_nodes_id'    => $nodeRows[$nodeUid],
                    'running_tasks'                      => $count,
                ]);
            }
        }
    }

    /**
     * Marque « retiré » ce qui, dans le périmètre donné, n'a pas été vu. Jamais de suppression.
     *
     * @param array<int|string, mixed> $scope Conditions du périmètre lu (en plus de la connexion).
     * @param list<int> $seen
     * @return int Nombre de lignes nouvellement retirées.
     */
    public function retire(string $table, int $connectionId, array $scope, array $seen): int
    {
        global $DB;

        $where = [
            'plugin_dockerinventory_connections_id' => $connectionId,
            'is_retired'                            => 0,
        ] + $scope;
        if ($seen !== []) {
            $where[] = ['NOT' => ['id' => $seen]];
        }
        $ids = [];
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => $table, 'WHERE' => $where]) as $row) {
            $ids[] = (int) $row['id'];
        }
        if ($ids === []) {
            return 0;
        }
        $values = ['is_retired' => 1, 'date_mod' => $this->now];
        if ($table === Service::getTable()) {
            // Plus aucune tâche connue : ni réplicas en cours, ni nœud.
            $values['date_retired'] = $this->now;
            $values['running_replicas'] = 0;
            $DB->delete(self::PLACEMENT_TABLE, ['plugin_dockerinventory_services_id' => $ids]);
        }
        $DB->update($table, $values, ['id' => $ids]);

        return count($ids);
    }

    /**
     * Services actifs de la connexion (garde-fou : un import vide ne vide jamais l'inventaire).
     *
     * @param array<int|string, mixed> $scope
     */
    public function activeServices(int $connectionId, array $scope = []): int
    {
        return countElementsInTable(
            Service::getTable(),
            ['plugin_dockerinventory_connections_id' => $connectionId, 'is_retired' => 0] + $scope
        );
    }

    /**
     * @param array<string, mixed> $report
     */
    public function saveReport(Connection $connection, array $report, int $status): void
    {
        global $DB;

        $DB->update(Connection::getTable(), [
            'last_import_date'   => $this->now,
            'last_import_status' => $status,
            'last_import_report' => (string) json_encode($report),
        ] + Connection::successValues($status, $this->now), [
            'id' => (int) $connection->getID(),
        ]);
        $connection->getFromDB((int) $connection->getID());
    }
}

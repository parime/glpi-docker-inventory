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
use GlpiPlugin\Dockerinventory\Portainer\PortainerClient;
use GlpiPlugin\Dockerinventory\Portainer\PortainerException;
use GlpiPlugin\Dockerinventory\Portainer\PortainerMapper;

/**
 * Import en lecture seule d'un serveur Portainer.
 *
 * Règles :
 * - stack Swarm ou Compose (hôte Docker autonome, stack Portainer ou projet Compose déployé hors
 *   Portainer) => application GLPI (Appliance) : celle déjà liée, sinon une application du même
 *   nom dans l'entité de la connexion, sinon une nouvelle, créée avec l'environnement choisi sur
 *   la connexion. Une application existante n'est jamais modifiée ;
 * - service Swarm, service Compose ou conteneur hors Compose => fiche du plugin (image, tag,
 *   empreinte, réplicas ou conteneurs, ports, réseaux, volumes et secrets par leur nom seulement,
 *   état et politique de redémarrage des conteneurs) ;
 * - nœud Swarm ou hôte autonome => rattaché à l'ordinateur du même nom d'hôte (nom complet ou
 *   court) dans l'entité de la connexion et ses sous-entités, s'il est unique. Aucun ordinateur
 *   n'est créé ni modifié ;
 * - service, stack ou nœud absent d'un environnement lu avec succès (ou d'un environnement
 *   disparu) => marqué « retiré ». Rien n'est supprimé. Un environnement en erreur ne retire rien.
 *
 * @phpstan-type Report array{status: int, stacks: int, services: int, retired: int, nodes: int,
 *     linked_nodes: int, appliances_created: int, errors: list<array{endpoint: string, code: string}>}
 */
final class Importer
{
    /** @var \Closure(Connection): PortainerClient */
    private \Closure $clientFactory;

    private string $now = '';

    /**
     * @param (\Closure(Connection): PortainerClient)|null $clientFactory
     */
    public function __construct(?\Closure $clientFactory = null)
    {
        $this->clientFactory = $clientFactory ?? static fn (Connection $connection): PortainerClient
            => $connection->getClient();
    }

    /**
     * @return Report
     */
    public function import(Connection $connection): array
    {
        $this->now = (string) ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'));
        $report = [
            'status'             => Connection::STATUS_OK,
            'stacks'             => 0,
            'services'           => 0,
            'retired'            => 0,
            'nodes'              => 0,
            'linked_nodes'       => 0,
            'appliances_created' => 0,
            'errors'             => [],
        ];

        try {
            $client = ($this->clientFactory)($connection);
            $endpoints = $client->endpoints();
            $stacks = $client->stacks();
        } catch (PortainerException $e) {
            $report['errors'][] = ['endpoint' => '', 'code' => $e->getMessage()];
            $report['status'] = Connection::STATUS_ERROR;
            $this->saveReport($connection, $report);

            return $report;
        }

        $listed = [];
        $read = [];
        $seen = ['services' => [], 'stacks' => [], 'nodes' => []];
        foreach ($endpoints as $endpoint) {
            $endpointId = (int) ($endpoint['Id'] ?? 0);
            if ($endpointId <= 0) {
                continue;
            }
            $listed[] = $endpointId;
            $endpointName = is_scalar($endpoint['Name'] ?? null) ? (string) $endpoint['Name'] : '';
            if (!in_array((int) ($endpoint['Type'] ?? 0), PortainerMapper::DOCKER_ENDPOINT_TYPES, true)) {
                // Kubernetes, Azure : rien à lire ici, mais l'environnement est bien connu.
                $read[] = $endpointId;
                continue;
            }
            try {
                $services = $client->services($endpointId);
                $nodes = $client->nodes($endpointId);
                $tasks = $client->tasks($endpointId);
                $networks = $client->networks($endpointId);
            } catch (PortainerException $e) {
                if ($e->getMessage() !== PortainerException::NOT_SWARM) {
                    $report['errors'][] = ['endpoint' => $endpointName, 'code' => $e->getMessage()];
                    continue;
                }
                // Hôte Docker autonome, sans Swarm : stacks Compose et conteneurs.
                try {
                    $this->importStandalone($client, $connection, $endpoint, $stacks, $report, $seen);
                    $read[] = $endpointId;
                } catch (PortainerException $e) {
                    $report['errors'][] = ['endpoint' => $endpointName, 'code' => $e->getMessage()];
                }
                continue;
            }
            $read[] = $endpointId;

            $stackRows = [];
            foreach ($stacks as $stack) {
                if (
                    (int) ($stack['EndpointId'] ?? 0) !== $endpointId
                    || (int) ($stack['Type'] ?? 0) !== PortainerMapper::STACK_TYPE_SWARM
                    || !is_scalar($stack['Name'] ?? null)
                    || (int) ($stack['Id'] ?? 0) <= 0
                ) {
                    continue;
                }
                $row = $this->upsertStack(
                    $connection,
                    $endpointId,
                    $endpointName,
                    (int) $stack['Id'],
                    (string) $stack['Name'],
                    PortainerMapper::KIND_SWARM,
                    $report
                );
                $stackRows[(string) $stack['Name']] = $row;
                $seen['stacks'][] = $row['id'];
                $report['stacks']++;
            }

            $nodeRows = [];
            foreach ($nodes as $node) {
                $mapped = PortainerMapper::mapNode($node);
                if ($mapped === null) {
                    continue;
                }
                $nodeRows[$mapped['node_uid']] = $this->upsertNode($connection, $endpointId, $mapped, $report);
                $seen['nodes'][] = $nodeRows[$mapped['node_uid']];
                $report['nodes']++;
            }

            $running = PortainerMapper::runningTasks($tasks);
            $networkNames = PortainerMapper::networkNames($networks);
            foreach ($services as $service) {
                $mapped = PortainerMapper::mapService($service, $networkNames, $running['services']);
                if ($mapped === null) {
                    continue;
                }
                $stackRow = $stackRows[$mapped['stack_name']] ?? ['id' => 0, 'appliances_id' => 0];
                $serviceId = $this->upsertService($connection, $endpointId, $endpointName, $mapped, $stackRow);
                $this->replacePlacement($serviceId, $running['placement'][$mapped['service_uid']] ?? [], $nodeRows);
                $seen['services'][] = $serviceId;
                $report['services']++;
            }
        }

        $connectionId = (int) $connection->getID();
        $report['retired'] = $this->retire(Service::getTable(), $connectionId, $seen['services'], $read, $listed);
        $this->retire('glpi_plugin_dockerinventory_stacks', $connectionId, $seen['stacks'], $read, $listed);
        $this->retire(Node::getTable(), $connectionId, $seen['nodes'], $read, $listed);

        if ($report['errors'] !== []) {
            $report['status'] = $read === [] ? Connection::STATUS_ERROR : Connection::STATUS_PARTIAL;
        }
        $this->saveReport($connection, $report);

        return $report;
    }

    /**
     * Environnement Docker autonome (sans Swarm) : l'hôte comme un nœud, chaque stack Compose de
     * Portainer et chaque projet Compose vu sur les conteneurs comme une stack, chaque service
     * Compose et chaque conteneur hors Compose comme un service. Lecture seule : informations du
     * moteur, liste des conteneurs et des images, détail de chaque conteneur pour sa politique de
     * redémarrage (rien d'autre n'en est lu, ni variables d'environnement ni secrets).
     *
     * @param array<string, mixed> $endpoint
     * @param list<array<string, mixed>> $stacks
     * @param Report $report
     * @param array{services: list<int>, stacks: list<int>, nodes: list<int>} $seen
     * @throws PortainerException Lecture impossible : rien n'est retiré dans cet environnement.
     */
    private function importStandalone(
        PortainerClient $client,
        Connection $connection,
        array $endpoint,
        array $stacks,
        array &$report,
        array &$seen
    ): void {
        $endpointId = (int) $endpoint['Id'];
        $endpointName = is_scalar($endpoint['Name'] ?? null) ? (string) $endpoint['Name'] : '';
        $info = $client->info($endpointId);
        $images = PortainerMapper::imageIndex($client->images($endpointId));
        $containers = [];
        $policies = [];
        foreach ($client->containers($endpointId) as $raw) {
            $container = PortainerMapper::mapContainer($raw, $images);
            if ($container === null) {
                continue;
            }
            $containers[] = $container;
            try {
                $policies[$container['uid']] = PortainerMapper::restartPolicy(
                    $client->container($endpointId, $container['uid'])
                );
            } catch (PortainerException $e) {
                if ($e->getMessage() !== PortainerException::NOT_FOUND) {
                    throw $e;
                }
                // Conteneur supprimé entre la liste et son détail : politique inconnue.
            }
        }

        // L'hôte, rattaché à l'ordinateur du même nom comme un nœud Swarm.
        $host = PortainerMapper::mapHost(
            $info,
            $endpointId,
            (int) ($endpoint['Status'] ?? 1) === 1,
            PortainerMapper::endpointHost(is_scalar($endpoint['URL'] ?? null) ? (string) $endpoint['URL'] : '')
        );
        $nodeId = $this->upsertNode($connection, $endpointId, $host, $report);
        $seen['nodes'][] = $nodeId;
        $report['nodes']++;

        // Stacks Compose de Portainer, puis projets Compose déployés hors Portainer.
        $stackRows = [];
        foreach ($stacks as $stack) {
            if (
                (int) ($stack['EndpointId'] ?? 0) !== $endpointId
                || (int) ($stack['Type'] ?? 0) !== PortainerMapper::STACK_TYPE_COMPOSE
                || !is_scalar($stack['Name'] ?? null)
                || (string) $stack['Name'] === ''
                || (int) ($stack['Id'] ?? 0) <= 0
            ) {
                continue;
            }
            $row = $this->upsertStack(
                $connection,
                $endpointId,
                $endpointName,
                (int) $stack['Id'],
                (string) $stack['Name'],
                PortainerMapper::KIND_COMPOSE,
                $report
            );
            // Portainer donne au projet Compose le nom de la stack, en minuscules.
            $stackRows[strtolower((string) $stack['Name'])] = $row;
            $seen['stacks'][] = $row['id'];
            $report['stacks']++;
        }
        foreach ($containers as $container) {
            $project = strtolower($container['project']);
            if ($project === '' || isset($stackRows[$project])) {
                continue;
            }
            $row = $this->upsertStack(
                $connection,
                $endpointId,
                $endpointName,
                0,
                $container['project'],
                PortainerMapper::KIND_COMPOSE,
                $report
            );
            $stackRows[$project] = $row;
            $seen['stacks'][] = $row['id'];
            $report['stacks']++;
        }

        foreach (PortainerMapper::groupContainers($endpointId, $containers, $policies) as $service) {
            $stackRow = $stackRows[strtolower($service['stack_name'])] ?? ['id' => 0, 'appliances_id' => 0];
            $serviceId = $this->upsertService($connection, $endpointId, $endpointName, $service, $stackRow);
            $running = $service['running_replicas'];
            $this->replacePlacement(
                $serviceId,
                $running > 0 ? [$host['node_uid'] => $running] : [],
                [$host['node_uid'] => $nodeId]
            );
            $seen['services'][] = $serviceId;
            $report['services']++;
        }
    }

    /**
     * Stack Swarm ou Compose gérée par Portainer (identifiant de stack), ou projet Compose déployé
     * hors Portainer (identifiant 0 : environnement et nom du projet).
     *
     * @param Report $report
     * @return array{id: int, appliances_id: int}
     */
    private function upsertStack(
        Connection $connection,
        int $endpointId,
        string $endpointName,
        int $stackUid,
        string $name,
        string $kind,
        array &$report
    ): array {
        global $DB;

        $table = 'glpi_plugin_dockerinventory_stacks';
        $where = ['plugin_dockerinventory_connections_id' => (int) $connection->getID(), 'stack_uid' => $stackUid];
        if ($stackUid === 0) {
            $where += ['endpoint_uid' => $endpointId, 'compose_project' => $name];
        }
        $existing = $DB->request(['FROM' => $table, 'WHERE' => $where, 'LIMIT' => 1])->current();
        $applianceId = $this->ensureAppliance(
            $connection,
            $name,
            $endpointName,
            (int) ($existing['appliances_id'] ?? 0),
            $report
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
     * Application GLPI de la stack. Seule écriture hors des tables du plugin : la création, une
     * fois, d'une application absente. Une application existante n'est jamais modifiée ; une
     * application mise à la corbeille reste liée et n'est pas restaurée.
     *
     * @param Report $report
     */
    private function ensureAppliance(
        Connection $connection,
        string $stackName,
        string $endpointName,
        int $currentId,
        array &$report
    ): int {
        global $DB;

        if ($currentId > 0 && countElementsInTable(Appliance::getTable(), ['id' => $currentId]) > 0) {
            return $currentId;
        }
        $entityId = (int) $connection->fields['entities_id'];
        $match = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => Appliance::getTable(),
            'WHERE'  => ['name' => $stackName, 'entities_id' => $entityId, 'is_deleted' => 0],
            'ORDER'  => 'id',
            'LIMIT'  => 1,
        ])->current();
        if (is_array($match)) {
            return (int) $match['id'];
        }

        $appliance = new Appliance();
        $id = $appliance->add([
            'name'                     => $stackName,
            'entities_id'              => $entityId,
            'is_recursive'             => (int) $connection->fields['is_recursive'],
            'applianceenvironments_id' => (int) $connection->fields['applianceenvironments_id'],
            'comment'                  => sprintf(
                __('Créée par le plugin Inventaire Docker pour la stack Portainer « %1$s » (%2$s).', 'dockerinventory'),
                $stackName,
                $endpointName
            ),
        ]);
        if ($id === false) {
            return 0;
        }
        $report['appliances_created']++;

        return (int) $id;
    }

    /**
     * @param array{node_uid: string, hostname: string, role: string, availability: string, state: string,
     *     engine_version: string, address: string} $node
     * @param Report $report
     */
    private function upsertNode(Connection $connection, int $endpointId, array $node, array &$report): int
    {
        global $DB;

        $table = Node::getTable();
        $where = [
            'plugin_dockerinventory_connections_id' => (int) $connection->getID(),
            'node_uid'                              => $node['node_uid'],
        ];
        $computerId = $this->matchComputer($connection, $node['hostname']);
        if ($computerId > 0) {
            $report['linked_nodes']++;
        }
        $values = [
            'endpoint_uid'    => $endpointId,
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
     * Ordinateur dont le nom est le nom d'hôte du nœud (complet ou court), dans l'entité de la
     * connexion ou ses sous-entités. Zéro si aucun ou plusieurs.
     */
    private function matchComputer(Connection $connection, string $hostname): int
    {
        global $DB;

        if ($hostname === '') {
            return 0;
        }
        $entities = (new DbUtils())->getSonsOf('glpi_entities', (int) $connection->fields['entities_id']);
        $rows = iterator_to_array($DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_computers',
            'WHERE'  => [
                'name'        => array_values(array_unique([$hostname, PortainerMapper::shortHostname($hostname)])),
                'is_deleted'  => 0,
                'is_template' => 0,
                'entities_id' => array_values($entities),
            ],
            'LIMIT'  => 2,
        ]), false);

        return count($rows) === 1 ? (int) $rows[0]['id'] : 0;
    }

    /**
     * @param array<string, mixed> $service Valeurs de PortainerMapper::mapService() ou groupContainers().
     * @param array{id: int, appliances_id: int} $stackRow
     */
    private function upsertService(
        Connection $connection,
        int $endpointId,
        string $endpointName,
        array $service,
        array $stackRow
    ): int {
        global $DB;

        $table = Service::getTable();
        $where = [
            'plugin_dockerinventory_connections_id' => (int) $connection->getID(),
            'service_uid'                      => $service['service_uid'],
        ];
        $values = [
            'name'             => $service['name'],
            'entities_id'      => (int) $connection->fields['entities_id'],
            'is_recursive'     => (int) $connection->fields['is_recursive'],
            'endpoint_uid'      => $endpointId,
            'endpoint_name'    => $endpointName,
            'stack_name'       => $service['stack_name'],
            'plugin_dockerinventory_stacks_id'        => $stackRow['id'],
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
        $existing = $DB->request(['SELECT' => ['id'], 'FROM' => $table, 'WHERE' => $where, 'LIMIT' => 1])->current();
        if (is_array($existing)) {
            $DB->update($table, $values, ['id' => (int) $existing['id']]);

            return (int) $existing['id'];
        }
        $DB->insert($table, $where + $values + ['date_creation' => $this->now]);

        return (int) $DB->insertId();
    }

    /**
     * Répartition des tâches en cours du service sur les nœuds (données du plugin, recalculées).
     *
     * @param array<string, int> $placement Identifiant Docker du nœud => tâches en cours.
     * @param array<string, int> $nodeRows Identifiant Docker du nœud => ligne du plugin.
     */
    private function replacePlacement(int $serviceId, array $placement, array $nodeRows): void
    {
        global $DB;

        $table = 'glpi_plugin_dockerinventory_services_nodes';
        $DB->delete($table, ['plugin_dockerinventory_services_id' => $serviceId]);
        foreach ($placement as $nodeUid => $count) {
            if (isset($nodeRows[$nodeUid])) {
                $DB->insert($table, [
                    'plugin_dockerinventory_services_id'   => $serviceId,
                    'plugin_dockerinventory_nodes_id'      => $nodeRows[$nodeUid],
                    'running_tasks' => $count,
                ]);
            }
        }
    }

    /**
     * Marque « retiré » ce qui n'a pas été vu dans un environnement lu avec succès, ou dans un
     * environnement qui n'existe plus dans Portainer.
     *
     * @param list<int> $seen
     * @param list<int> $read
     * @param list<int> $listed
     * @return int Nombre de lignes nouvellement retirées.
     */
    private function retire(string $table, int $connectionId, array $seen, array $read, array $listed): int
    {
        global $DB;

        $scope = [];
        if ($read !== []) {
            $scope[] = ['endpoint_uid' => $read];
        }
        $scope[] = $listed === [] ? ['endpoint_uid' => ['>', -1]] : ['NOT' => ['endpoint_uid' => $listed]];
        $where = [
            'plugin_dockerinventory_connections_id' => $connectionId,
            'is_retired'     => 0,
            'OR'             => $scope,
        ];
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
            $DB->delete('glpi_plugin_dockerinventory_services_nodes', ['plugin_dockerinventory_services_id' => $ids]);
        }
        $DB->update($table, $values, ['id' => $ids]);

        return count($ids);
    }

    /**
     * @param Report $report
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

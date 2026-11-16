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
 *   disparu) => marqué « retiré ». Rien n'est supprimé. Un environnement en erreur ne retire rien ;
 * - compte sans droits (Portainer CE n'a pas de rôle en lecture seule) : aucun environnement visible,
 *   ou environnement que Portainer annonce peuplé mais dont le compte ne voit rien => avertissement,
 *   rien n'est retiré. Un import qui ne trouve plus rien alors que des services étaient actifs ne
 *   retire rien non plus : une perte de droits ne vide jamais l'inventaire.
 *
 * @phpstan-type Report array{status: int, stacks: int, services: int, retired: int, nodes: int,
 *     linked_nodes: int, appliances_created: int, errors: list<array{endpoint: string, code: string}>,
 *     warnings: list<array{endpoint: string, code: string}>}
 */
final class Importer
{
    /** Le compte ne voit aucun environnement (liste vide). */
    public const WARNING_NO_ENDPOINT = 'no_endpoint';

    /** Portainer annonce des services ou des conteneurs que le compte ne voit pas. */
    public const WARNING_HIDDEN = 'hidden_resources';

    /** Rien trouvé alors que des services étaient actifs : rien n'est retiré. */
    public const WARNING_EMPTY_KEPT = 'empty_kept';

    /** @var \Closure(Connection): PortainerClient */
    private \Closure $clientFactory;

    private InventoryStore $store;

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
        $this->store = new InventoryStore((string) ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s')));
        $report = [
            'status'             => Connection::STATUS_OK,
            'stacks'             => 0,
            'services'           => 0,
            'retired'            => 0,
            'nodes'              => 0,
            'linked_nodes'       => 0,
            'appliances_created' => 0,
            'errors'             => [],
            'warnings'           => [],
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

        if ($endpoints === []) {
            $report['warnings'][] = ['endpoint' => '', 'code' => self::WARNING_NO_ENDPOINT];
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
                    if ($this->importStandalone($client, $connection, $endpoint, $stacks, $report, $seen)) {
                        $read[] = $endpointId;
                    } else {
                        $report['warnings'][] = ['endpoint' => $endpointName, 'code' => self::WARNING_HIDDEN];
                    }
                } catch (PortainerException $e) {
                    $report['errors'][] = ['endpoint' => $endpointName, 'code' => $e->getMessage()];
                }
                continue;
            }
            if (PortainerMapper::resourcesHidden($endpoint, true, count($services))) {
                // Services invisibles pour ce compte : rien n'est lu ni retiré ici.
                $report['warnings'][] = ['endpoint' => $endpointName, 'code' => self::WARNING_HIDDEN];
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
                if ($mapped['stack_name'] !== '' && !isset($stackRows[$mapped['stack_name']])) {
                    // Stack déployée hors Portainer (docker stack deploy) : application comme une
                    // stack Portainer, repérée par l'environnement et son nom.
                    $stackRows[$mapped['stack_name']] = $this->upsertStack(
                        $connection,
                        $endpointId,
                        $endpointName,
                        0,
                        $mapped['stack_name'],
                        PortainerMapper::KIND_SWARM,
                        $report
                    );
                    $seen['stacks'][] = $stackRows[$mapped['stack_name']]['id'];
                    $report['stacks']++;
                }
                $stackRow = $stackRows[$mapped['stack_name']] ?? ['id' => 0, 'appliances_id' => 0];
                $serviceId = $this->upsertService($connection, $endpointId, $endpointName, $mapped, $stackRow);
                $this->replacePlacement($serviceId, $running['placement'][$mapped['service_uid']] ?? [], $nodeRows);
                $seen['services'][] = $serviceId;
                $report['services']++;
            }
        }

        $connectionId = (int) $connection->getID();
        $active = $this->store->activeServices($connectionId);
        if ($seen['services'] === [] && $seen['stacks'] === [] && $active > 0) {
            // Perte de droits plutôt que tout supprimé dans Portainer : l'inventaire est gardé.
            $report['warnings'][] = ['endpoint' => '', 'code' => self::WARNING_EMPTY_KEPT];
        } elseif ($listed !== []) {
            $report['retired'] = $this->retire(Service::getTable(), $connectionId, $seen['services'], $read, $listed);
            $this->retire(InventoryStore::STACKS_TABLE, $connectionId, $seen['stacks'], $read, $listed);
            $this->retire(Node::getTable(), $connectionId, $seen['nodes'], $read, $listed);
        }

        if ($report['errors'] !== []) {
            $report['status'] = $read === [] ? Connection::STATUS_ERROR : Connection::STATUS_PARTIAL;
        } elseif ($report['warnings'] !== []) {
            $report['status'] = Connection::STATUS_WARNING;
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
     * @return bool Faux si Portainer annonce des conteneurs que le compte ne voit pas : rien n'est
     *     alors enregistré ni retiré dans cet environnement.
     * @throws PortainerException Lecture impossible : rien n'est retiré dans cet environnement.
     */
    private function importStandalone(
        PortainerClient $client,
        Connection $connection,
        array $endpoint,
        array $stacks,
        array &$report,
        array &$seen
    ): bool {
        $endpointId = (int) $endpoint['Id'];
        $endpointName = is_scalar($endpoint['Name'] ?? null) ? (string) $endpoint['Name'] : '';
        $info = $client->info($endpointId);
        $rawContainers = $client->containers($endpointId);
        if (PortainerMapper::resourcesHidden($endpoint, false, count($rawContainers))) {
            return false;
        }
        $images = PortainerMapper::imageIndex($client->images($endpointId));
        $containers = [];
        $policies = [];
        foreach ($rawContainers as $raw) {
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

        return true;
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
        $row = $this->store->upsertStack($connection, $endpointId, $stackUid, $name, $kind, sprintf(
            __('Créée par le plugin Inventaire Docker pour la stack Portainer « %1$s » (%2$s).', 'dockerinventory'),
            $name,
            $endpointName
        ));
        $report['appliances_created'] = $this->store->appliancesCreated;

        return $row;
    }

    /**
     * @param array{node_uid: string, hostname: string, role: string, availability: string, state: string,
     *     engine_version: string, address: string} $node
     * @param Report $report
     */
    private function upsertNode(Connection $connection, int $endpointId, array $node, array &$report): int
    {
        $id = $this->store->upsertNode($connection, $endpointId, $node);
        $report['linked_nodes'] = $this->store->linkedNodes;

        return $id;
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
        return $this->store->upsertService($connection, $endpointId, $endpointName, $service, $stackRow);
    }

    /**
     * @param array<string, int> $placement Identifiant Docker du nœud => tâches en cours.
     * @param array<string, int> $nodeRows Identifiant Docker du nœud => ligne du plugin.
     */
    private function replacePlacement(int $serviceId, array $placement, array $nodeRows): void
    {
        $this->store->replacePlacement($serviceId, $placement, $nodeRows);
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
        $scope = [];
        if ($read !== []) {
            $scope[] = ['endpoint_uid' => $read];
        }
        $scope[] = $listed === [] ? ['endpoint_uid' => ['>', -1]] : ['NOT' => ['endpoint_uid' => $listed]];

        return $this->store->retire($table, $connectionId, ['OR' => $scope], $seen);
    }

    /**
     * @param Report $report
     */
    private function saveReport(Connection $connection, array $report): void
    {
        $this->store->saveReport($connection, $report, $report['status']);
    }
}

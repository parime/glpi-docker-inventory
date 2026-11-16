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

namespace GlpiPlugin\Dockerinventory\Kubernetes;

use GlpiPlugin\Dockerinventory\Connection;
use GlpiPlugin\Dockerinventory\InventoryStore;
use GlpiPlugin\Dockerinventory\Node;
use GlpiPlugin\Dockerinventory\Service;

/**
 * Import en lecture seule d'un cluster Kubernetes, dans les mêmes tables que Portainer :
 * - namespace qui a au moins une charge (hors namespaces ignorés) => application GLPI (Appliance),
 *   comme une stack : celle déjà liée, sinon une application du même nom dans l'entité de la
 *   connexion, sinon une nouvelle ; une application existante n'est jamais modifiée ;
 * - conteneur d'un Deployment, StatefulSet, DaemonSet ou CronJob => fiche de service ;
 * - nœud => rattaché à l'ordinateur du même nom (complet ou court), sinon de la même adresse IP,
 *   dans l'entité de la connexion et ses sous-entités, s'il est unique ;
 * - charge disparue d'une liste lue en entier => « retirée », jamais supprimée ;
 * - liste refusée (403), limitée (429) ou en erreur : rien n'en est retiré, import « partiel » ou
 *   « avertissement » ; après une limite (429), plus aucun appel jusqu'à l'import suivant ;
 * - aucune charge trouvée alors que des services étaient actifs : rien n'est retiré (une perte de
 *   droits ne vide jamais l'inventaire).
 *
 * @phpstan-import-type Workload from KubernetesMapper
 * @phpstan-type K8sReport array{status: int, version: string, namespaces: int, stacks: int, services: int,
 *     retired: int, nodes: int, linked_nodes: int, appliances_created: int, requests: int,
 *     errors: list<array{endpoint: string, code: string}>, warnings: list<array{endpoint: string, code: string}>}
 */
final class KubernetesImporter
{
    /** Le compte ne voit aucun namespace. */
    public const WARNING_NO_NAMESPACE = 'k8s_no_namespace';

    /** Le compte ne peut pas lister une ressource (403) : rien n'en est lu ni retiré. */
    public const WARNING_FORBIDDEN = 'k8s_forbidden_resource';

    /** Aucune charge trouvée alors que des services étaient actifs : rien n'est retiré. */
    public const WARNING_EMPTY_KEPT = 'k8s_empty_kept';

    /** Liste trop longue (plus de MAX_PAGES pages) : rien n'en est retiré. */
    public const WARNING_INCOMPLETE = 'k8s_incomplete';

    /** @var \Closure(Connection): KubernetesClient */
    private \Closure $clientFactory;

    private InventoryStore $store;

    private bool $rateLimited = false;

    /** @var array<string, true> Ressources lues en partie seulement. */
    private array $incomplete = [];

    /**
     * @param (\Closure(Connection): KubernetesClient)|null $clientFactory
     */
    public function __construct(?\Closure $clientFactory = null)
    {
        $this->clientFactory = $clientFactory ?? static fn (Connection $connection): KubernetesClient
            => $connection->getKubernetesClient();
    }

    /**
     * @return K8sReport
     */
    public function import(Connection $connection): array
    {
        $this->store = new InventoryStore((string) ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s')));
        $this->rateLimited = false;
        $this->incomplete = [];
        $report = [
            'status'             => Connection::STATUS_OK,
            'version'            => '',
            'namespaces'         => 0,
            'stacks'             => 0,
            'services'           => 0,
            'retired'            => 0,
            'nodes'              => 0,
            'linked_nodes'       => 0,
            'appliances_created' => 0,
            'requests'           => 0,
            'errors'             => [],
            'warnings'           => [],
        ];
        $client = ($this->clientFactory)($connection);
        $connectionId = (int) $connection->getID();
        $clusterName = (string) ($connection->fields['name'] ?? '');
        $excluded = KubernetesMapper::excludedPatterns((string) ($connection->fields['k8s_excluded_namespaces'] ?? ''));

        try {
            $report['version'] = $client->version();
            $namespaces = $client->list('namespaces');
        } catch (KubernetesException $e) {
            if ($e->getMessage() === KubernetesException::FORBIDDEN) {
                $report['warnings'][] = ['endpoint' => 'namespaces', 'code' => self::WARNING_FORBIDDEN];
                $report['status'] = Connection::STATUS_WARNING;
            } else {
                $report['errors'][] = ['endpoint' => '', 'code' => $e->getMessage()];
                $report['status'] = Connection::STATUS_ERROR;
            }
            $report['requests'] = $client->requests();
            $this->store->saveReport($connection, $report, $report['status']);

            return $report;
        }
        $names = KubernetesMapper::namespaceNames($namespaces['items']);
        $report['namespaces'] = count($names);
        if ($names === []) {
            $report['warnings'][] = ['endpoint' => '', 'code' => self::WARNING_NO_NAMESPACE];
        }

        // Listes du cluster ; une liste illisible vaut null (rien n'en est déduit ni retiré).
        $lists = [];
        $resources = ['nodes', 'pods', 'replicasets', 'cronjobs', 'deployments', 'statefulsets', 'daemonsets'];
        foreach ($resources as $resource) {
            $lists[$resource] = $this->read($client, $resource, $report);
        }

        // Nœuds.
        $nodeRows = [];
        $seenNodes = [];
        foreach ($lists['nodes'] ?? [] as $item) {
            $node = KubernetesMapper::mapNode($item);
            if ($node === null) {
                continue;
            }
            $nodeRows[$node['hostname']] = $this->store->upsertNode($connection, 0, $node);
            $seenNodes[] = $nodeRows[$node['hostname']];
            $report['nodes']++;
        }

        // Charges, rattachées à leurs pods.
        $podIndex = KubernetesMapper::podIndex(
            $lists['pods'] ?? [],
            $lists['replicasets'] ?? [],
            KubernetesMapper::cronJobNames($lists['cronjobs'] ?? [])
        );
        $podsRead = $lists['pods'] !== null && !isset($this->incomplete['pods']);
        $readKinds = [];
        $workloads = [];
        foreach (KubernetesMapper::WORKLOAD_RESOURCES as $kind => $resource) {
            if ($lists[$resource] === null) {
                continue;
            }
            if (!isset($this->incomplete[$resource])) {
                $readKinds[] = $kind;
            }
            foreach (KubernetesMapper::mapWorkloads($kind, $lists[$resource], $podIndex, $excluded) as $workload) {
                $workloads[] = $workload;
            }
        }

        $stackRows = [];
        $seenStacks = [];
        $seenServices = [];
        foreach ($workloads as $workload) {
            $namespace = $workload['stack_name'];
            if (!isset($stackRows[$namespace])) {
                $stackRows[$namespace] = $this->store->upsertStack(
                    $connection,
                    0,
                    0,
                    $namespace,
                    KubernetesMapper::STACK_KIND,
                    sprintf(
                        __(
                            'Créée par le plugin Inventaire Docker pour le namespace Kubernetes « %1$s » (%2$s).',
                            'dockerinventory'
                        ),
                        $namespace,
                        $clusterName
                    )
                );
                $seenStacks[] = $stackRows[$namespace]['id'];
                $report['stacks']++;
            }
            $serviceId = $this->store->upsertService(
                $connection,
                0,
                $clusterName,
                $workload,
                $stackRows[$namespace],
                !$podsRead
            );
            if ($podsRead) {
                $this->store->replacePlacement($serviceId, $workload['placement'], $nodeRows);
            }
            $seenServices[] = $serviceId;
            $report['services']++;
        }

        $allKinds = count($readKinds) === count(KubernetesMapper::WORKLOAD_RESOURCES);
        $nodesRead = $lists['nodes'] !== null && !isset($this->incomplete['nodes']);
        if ($seenServices === [] && $this->store->activeServices($connectionId) > 0) {
            // Perte de droits plutôt que tout supprimé dans le cluster : l'inventaire est gardé.
            $report['warnings'][] = ['endpoint' => '', 'code' => self::WARNING_EMPTY_KEPT];
        } else {
            if ($readKinds !== []) {
                $report['retired'] = $this->store->retire(
                    Service::getTable(),
                    $connectionId,
                    ['kind' => $readKinds],
                    $seenServices
                );
            }
            if ($allKinds) {
                $this->store->retire(
                    InventoryStore::STACKS_TABLE,
                    $connectionId,
                    ['kind' => KubernetesMapper::STACK_KIND],
                    $seenStacks
                );
            }
            if ($nodesRead) {
                $this->store->retire(Node::getTable(), $connectionId, [], $seenNodes);
            }
        }

        $report['requests'] = $client->requests();
        $report['appliances_created'] = $this->store->appliancesCreated;
        $report['linked_nodes'] = $this->store->linkedNodes;
        if ($report['errors'] !== []) {
            $report['status'] = Connection::STATUS_PARTIAL;
        } elseif ($report['warnings'] !== []) {
            $report['status'] = Connection::STATUS_WARNING;
        }
        $this->store->saveReport($connection, $report, $report['status']);

        return $report;
    }

    /**
     * Liste complète d'une ressource, ou null si elle n'a pas pu être lue en entier.
     *
     * @param key-of<KubernetesClient::RESOURCES> $resource
     * @param K8sReport $report
     * @return list<array<string, mixed>>|null
     */
    private function read(KubernetesClient $client, string $resource, array &$report): ?array
    {
        if ($this->rateLimited) {
            // Limite atteinte : plus d'appel jusqu'à l'import suivant (rien n'est attendu ici).
            return null;
        }
        try {
            $page = $client->list($resource);
        } catch (KubernetesException $e) {
            if ($e->getMessage() === KubernetesException::FORBIDDEN) {
                $report['warnings'][] = ['endpoint' => $resource, 'code' => self::WARNING_FORBIDDEN];
            } else {
                $report['errors'][] = ['endpoint' => $resource, 'code' => $e->getMessage()];
                $this->rateLimited = $e->getMessage() === KubernetesException::RATE_LIMITED;
            }

            return null;
        }
        if (!$page['complete']) {
            // Lue en partie : ce qui est lu est enregistré, rien n'en est retiré.
            $report['warnings'][] = ['endpoint' => $resource, 'code' => self::WARNING_INCOMPLETE];
            $this->incomplete[$resource] = true;
        }

        return $page['items'];
    }
}

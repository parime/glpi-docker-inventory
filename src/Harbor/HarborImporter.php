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

use GlpiPlugin\Dockerinventory\Connection;
use GlpiPlugin\Dockerinventory\HarborArtifact;
use GlpiPlugin\Dockerinventory\Registry\RegistryException;
use GlpiPlugin\Dockerinventory\Ticket\UpdateTickets;
use GlpiPlugin\Dockerinventory\Ticket\VulnTickets;

/**
 * Lecture d'une connexion Harbor : projets, dépôts, artefacts et résumé de leurs analyses, dans
 * les tables du plugin (projets, artefacts). Lecture seule vers Harbor (GET).
 *
 * Règles :
 * - un artefact par dépôt et par empreinte ; ses tags, sa taille, sa date de publication et son
 *   analyse sont remplacés à chaque lecture ;
 * - artefact, dépôt ou projet absent d'une liste lue en entier : artefacts « retirés », jamais
 *   supprimés (ils redeviennent actifs s'ils réapparaissent) ; une liste en erreur ou incomplète
 *   ne retire rien ;
 * - une erreur sur un dépôt n'arrête pas la lecture des autres ; une limite de requêtes (429)
 *   arrête les appels pour cette lecture (lecture « partielle », données déjà lues gardées) ;
 * - liste des projets illisible : échec, rien n'est modifié ;
 * - tickets de vulnérabilité ensuite, pour les connexions Portainer qui les ont activés.
 *
 * @phpstan-type HarborReport array{status: int, projects: int, repositories: int, artifacts: int,
 *     scanned: int, critical: int, high: int, retired: int, requests: int,
 *     errors: list<array{endpoint: string, code: string}>,
 *     tickets: array{created: int, followups: int, unchanged: int, errors: int}}
 * @phpstan-type ReadState array{projects_complete: bool, projects: array<string, true>,
 *     complete_projects: array<string, list<string>>, complete_repositories: array<string, true>}
 */
final class HarborImporter
{
    public const PROJECTS_TABLE = 'glpi_plugin_dockerinventory_harborprojects';

    /** @var \Closure(Connection): HarborClient */
    private \Closure $clientFactory;

    private string $now = '';

    /**
     * @param (\Closure(Connection): HarborClient)|null $clientFactory
     */
    public function __construct(?\Closure $clientFactory = null, private readonly bool $withTickets = true)
    {
        $this->clientFactory = $clientFactory ?? static fn (Connection $harbor): HarborClient
            => $harbor->getHarborClient();
    }

    /**
     * @return HarborReport
     */
    public static function emptyReport(): array
    {
        return [
            'status'       => Connection::STATUS_OK,
            'projects'     => 0,
            'repositories' => 0,
            'artifacts'    => 0,
            'scanned'      => 0,
            'critical'     => 0,
            'high'         => 0,
            'retired'      => 0,
            'requests'     => 0,
            'errors'       => [],
            'tickets'      => UpdateTickets::emptyReport(),
        ];
    }

    /**
     * @return HarborReport
     */
    public function import(Connection $connection): array
    {
        $this->now = (string) ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'));
        $connectionId = (int) $connection->getID();
        $report = self::emptyReport();
        $client = ($this->clientFactory)($connection);
        $state = [
            'projects_complete'     => false,
            'projects'              => [],
            'complete_projects'     => [],
            'complete_repositories' => [],
        ];
        try {
            $projects = $client->projects();
        } catch (RegistryException $e) {
            $report['status'] = Connection::STATUS_ERROR;
            $report['errors'][] = ['endpoint' => 'projects', 'code' => $e->getMessage()];
            $report['requests'] = $client->requests();
            $this->save($connectionId, $report);

            return $report;
        }
        $state['projects_complete'] = $projects['complete'];
        $seen = [];
        $stopped = false;
        foreach ($projects['items'] as $data) {
            $project = HarborMapper::project($data);
            if ($project === null) {
                continue;
            }
            $state['projects'][$project['name']] = true;
            $this->upsertProject($connectionId, $project);
            $report['projects']++;
            if ($stopped) {
                continue;
            }
            try {
                $repositories = $client->repositories($project['name']);
            } catch (RegistryException $e) {
                $report['errors'][] = ['endpoint' => $project['name'], 'code' => $e->getMessage()];
                $stopped = $e->getMessage() === RegistryException::RATE_LIMITED;
                continue;
            }
            $names = [];
            foreach ($repositories['items'] as $repositoryData) {
                $name = HarborMapper::repositoryName($repositoryData, $project['name']);
                if ($name === '') {
                    continue;
                }
                $full = $project['name'] . '/' . $name;
                $names[] = $full;
                $report['repositories']++;
                if ($stopped) {
                    continue;
                }
                try {
                    $artifacts = $client->artifacts($project['name'], $name);
                } catch (RegistryException $e) {
                    $report['errors'][] = ['endpoint' => $full, 'code' => $e->getMessage()];
                    $stopped = $e->getMessage() === RegistryException::RATE_LIMITED;
                    continue;
                }
                foreach ($artifacts['items'] as $artifactData) {
                    $artifact = HarborMapper::artifact($artifactData);
                    if ($artifact === null) {
                        continue;
                    }
                    $scan = HarborMapper::scan($artifactData['scan_overview'] ?? null);
                    $seen[] = $this->upsertArtifact($connection, $project['name'], $full, $artifact, $scan);
                    $report['artifacts']++;
                    if ($scan['scan_status'] === HarborMapper::STATUS_SUCCESS) {
                        $report['scanned']++;
                        $report['critical'] += $scan['critical'] > 0 ? 1 : 0;
                        $report['high'] += $scan['critical'] === 0 && $scan['high'] > 0 ? 1 : 0;
                    }
                }
                if ($artifacts['complete']) {
                    $state['complete_repositories'][$full] = true;
                }
            }
            if ($repositories['complete']) {
                $state['complete_projects'][$project['name']] = $names;
            }
        }
        $report['retired'] = $this->retire($connectionId, $seen, $state);
        $report['requests'] = $client->requests();
        if ($report['errors'] !== []) {
            $report['status'] = Connection::STATUS_PARTIAL;
        }
        $this->save($connectionId, $report);
        if ($this->withTickets) {
            $report['tickets'] = (new VulnTickets())->process();
        }

        return $report;
    }

    /**
     * Un artefact actif non revu est-il à retirer ? Oui seulement si la liste qui aurait dû le
     * contenir a été lue en entier : son dépôt, la liste des dépôts de son projet, ou la liste des
     * projets.
     *
     * @param ReadState $state
     */
    public static function shouldRetire(string $project, string $repository, array $state): bool
    {
        if (isset($state['complete_repositories'][$repository])) {
            return true;
        }
        if (isset($state['complete_projects'][$project])) {
            return !in_array($repository, $state['complete_projects'][$project], true);
        }

        return $state['projects_complete'] && !isset($state['projects'][$project]);
    }

    /**
     * @param array{uid: int, name: string, is_public: int, repo_count: int} $project
     */
    private function upsertProject(int $connectionId, array $project): void
    {
        global $DB;

        $where = ['plugin_dockerinventory_connections_id' => $connectionId, 'name' => mb_substr($project['name'], 0, 255)];
        $values = [
            'project_uid'  => $project['uid'],
            'is_public'    => $project['is_public'],
            'repo_count'   => $project['repo_count'],
            'is_retired'   => 0,
            'last_seen'    => $this->now,
            'date_mod'     => $this->now,
        ];
        $existing = $DB->request(['SELECT' => ['id'], 'FROM' => self::PROJECTS_TABLE, 'WHERE' => $where])->current();
        if (is_array($existing)) {
            $DB->update(self::PROJECTS_TABLE, $values, ['id' => (int) $existing['id']]);
        } else {
            $DB->insert(self::PROJECTS_TABLE, $where + $values + ['date_creation' => $this->now]);
        }
    }

    /**
     * @param array{digest: string, child_digests: list<string>, tags: list<string>, artifact_type: string,
     *     size: int, push_time: string|null} $artifact
     * @param array{scan_status: string, severity: string, scanner: string, scan_time: string|null,
     *     critical: int, high: int, medium: int, low: int, other: int, total: int, fixable: int} $scan
     */
    private function upsertArtifact(
        Connection $connection,
        string $project,
        string $repository,
        array $artifact,
        array $scan
    ): int {
        global $DB;

        $table = HarborArtifact::getTable();
        $where = [
            'plugin_dockerinventory_connections_id' => (int) $connection->getID(),
            'repository'                            => mb_substr($repository, 0, 255),
            'digest'                                => mb_substr($artifact['digest'], 0, 255),
        ];
        $values = [
            'entities_id'   => (int) $connection->fields['entities_id'],
            'is_recursive'  => 1,
            'project'       => mb_substr($project, 0, 255),
            'tags'          => implode(', ', $artifact['tags']),
            'child_digests' => implode(' ', $artifact['child_digests']),
            'artifact_type' => mb_substr($artifact['artifact_type'], 0, 32),
            'size'          => $artifact['size'],
            'push_time'     => $artifact['push_time'],
            'is_retired'    => 0,
            'date_retired'  => null,
            'last_seen'     => $this->now,
            'date_mod'      => $this->now,
        ] + $scan;
        $existing = $DB->request(['SELECT' => ['id'], 'FROM' => $table, 'WHERE' => $where, 'LIMIT' => 1])->current();
        if (is_array($existing)) {
            $DB->update($table, $values, ['id' => (int) $existing['id']]);

            return (int) $existing['id'];
        }
        $DB->insert($table, $where + $values + ['date_creation' => $this->now]);

        return (int) $DB->insertId();
    }

    /**
     * @param list<int> $seen
     * @param ReadState $state
     */
    private function retire(int $connectionId, array $seen, array $state): int
    {
        global $DB;

        $table = HarborArtifact::getTable();
        $where = ['plugin_dockerinventory_connections_id' => $connectionId, 'is_retired' => 0];
        if ($seen !== []) {
            $where[] = ['NOT' => ['id' => $seen]];
        }
        $ids = [];
        foreach ($DB->request(['SELECT' => ['id', 'project', 'repository'], 'FROM' => $table, 'WHERE' => $where]) as $row) {
            if (self::shouldRetire((string) $row['project'], (string) $row['repository'], $state)) {
                $ids[] = (int) $row['id'];
            }
        }
        if ($ids !== []) {
            $DB->update($table, ['is_retired' => 1, 'date_retired' => $this->now], ['id' => $ids]);
        }
        if ($state['projects_complete']) {
            $projectWhere = ['plugin_dockerinventory_connections_id' => $connectionId, 'is_retired' => 0];
            if ($state['projects'] !== []) {
                $projectWhere[] = ['NOT' => ['name' => array_keys($state['projects'])]];
            }
            $DB->update(self::PROJECTS_TABLE, ['is_retired' => 1, 'date_mod' => $this->now], $projectWhere);
        }

        return count($ids);
    }

    /**
     * Dernière lecture de Harbor, affichée sur la connexion (sans les tickets).
     *
     * @param HarborReport $report
     */
    private function save(int $connectionId, array $report): void
    {
        global $DB;

        unset($report['tickets']);
        $DB->update(Connection::getTable(), [
            'last_scan_date'   => $this->now,
            'last_scan_status' => $report['status'],
            'last_scan_report' => (string) json_encode($report),
        ], ['id' => $connectionId]);
    }
}

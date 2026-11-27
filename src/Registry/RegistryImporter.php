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

namespace GlpiPlugin\Dockerinventory\Registry;

use DbUtils;
use GlpiPlugin\Dockerinventory\Connection;
use GlpiPlugin\Dockerinventory\ImageVersion;
use GlpiPlugin\Dockerinventory\Service;
use GlpiPlugin\Dockerinventory\Ticket\UpdateTickets;
use GlpiPlugin\Dockerinventory\Version\TagSelector;
use GlpiPlugin\Dockerinventory\Version\VersionComparator;

/**
 * Repli sans WUD : pour chaque service actif qu'aucune connexion WUD ne couvre, dernière version
 * lue directement dans le registre de son image.
 *
 * Règles :
 * - registres seulement lus (GET de la liste des tags) ;
 * - Docker Hub (anonyme) : une vérification par image et par jour au plus (cache dans la table
 *   glpi_plugin_dockerinventory_registrytags), et plus aucun appel à Docker Hub pendant la
 *   vérification dès qu'il refuse (429) ou ne répond pas ;
 * - Harbor : la connexion Harbor active dont l'hôte du registre est celui de l'image, et qui couvre
 *   l'entité du service ; compte robot facultatif (projets publics) ;
 * - registre inconnu, ou privé sans identifiants : version « non vérifiée », sans erreur bloquante ;
 * - une ligne par service dans la table des versions (source « registry »), comme avec WUD ; les
 *   lignes « registry » qui ne correspondent plus à rien sont supprimées ;
 * - tickets de mise à jour ensuite, si la connexion Portainer du service les a activés.
 *
 * @phpstan-type RegistryReport array{status: int, services: int, checked: int, not_checked: int,
 *     untrackable: int, cached: int, requests: int, gaps: array<string, int>, with_image: int,
 *     wud_covered: int, reason: string, harbors: array<int, array{host: string, services: int}>,
 *     errors: list<array{endpoint: string, code: string}>,
 *     tickets: array{created: int, followups: int, unchanged: int, errors: int}}
 */
final class RegistryImporter
{
    public const CACHE_TABLE = 'glpi_plugin_dockerinventory_registrytags';

    /** Durée de validité du cache de Docker Hub. */
    public const CACHE_TTL = DAY_TIMESTAMP;

    /** Raison d'une vérification sans aucun service (#129) : aucun service inventorié avec une image. */
    public const REASON_NO_SERVICE = 'no_service';

    /** Raison d'une vérification sans aucun service : tous les services avec une image sont suivis par WUD. */
    public const REASON_ALL_WUD = 'all_wud';

    /** Codes qui n'empêchent pas une vérification réussie (rien à corriger côté appel). */
    private const NOT_ERRORS = [
        RegistryException::UNKNOWN_REGISTRY,
        RegistryException::NO_CREDENTIALS,
        RegistryException::NOT_FOUND,
        RegistryException::SKIPPED,
    ];

    /** @var \Closure(?Connection): RegistryClient */
    private \Closure $clientFactory;

    private string $now = '';

    /** @var array<string, RegistryClient> */
    private array $clients = [];

    /** @var array<string, array{tags: list<array{name: string, digest: string}>, error: string, date: string}> */
    private array $memo = [];

    private bool $hubBlocked = false;

    /** Services actifs avec une image, suivis ou non par WUD. */
    private int $withImage = 0;

    /** Services actifs avec une image laissés à WUD. */
    private int $wudCovered = 0;

    /** @var array<int, array{services: int, requests: int, errors: list<array{endpoint: string, code: string}>}> */
    private array $harborReports = [];

    /**
     * @param (\Closure(?Connection): RegistryClient)|null $clientFactory Connexion Harbor, ou null
     *        pour Docker Hub.
     */
    public function __construct(?\Closure $clientFactory = null)
    {
        $this->clientFactory = $clientFactory ?? static fn (?Connection $harbor): RegistryClient
            => $harbor === null ? RegistryClient::dockerHub() : $harbor->getRegistryClient();
    }

    /**
     * @return RegistryReport
     */
    public function run(): array
    {
        global $DB;

        $this->now = (string) ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'));
        $report = [
            'status'      => Connection::STATUS_OK,
            'services'    => 0,
            'checked'     => 0,
            'not_checked' => 0,
            'untrackable' => 0,
            'cached'      => 0,
            'requests'    => 0,
            'gaps'        => array_fill_keys(VersionComparator::GAPS, 0),
            'errors'      => [],
            'tickets'     => UpdateTickets::emptyReport(),
            'with_image'  => 0,
            'wud_covered' => 0,
            'reason'      => '',
            'harbors'     => [],
        ];
        [$harbors, $targets] = $this->harbors();
        $kept = [];
        $checked = [];
        foreach ($this->uncoveredServices() as $service) {
            $resolved = RegistryResolver::resolve($service['image'], $service['entities_id'], $targets);
            $harbor = $harbors[$resolved['connection_id']] ?? null;
            $lookup = $this->lookup($resolved, $harbor, $report);
            $values = $lookup['error'] === ''
                ? TagSelector::evaluate($service['tag'], $service['digest'], $lookup['tags'])
                : ['latest_tag' => '', 'latest_digest' => '', 'gap' => VersionComparator::GAP_UNKNOWN,
                    'update_available' => 0];
            if ($lookup['error'] === '' && !VersionComparator::isTrackable($service['tag'])) {
                $report['untrackable']++;
            } elseif ($values['gap'] === VersionComparator::GAP_UNKNOWN) {
                $report['not_checked']++;
            } else {
                $report['checked']++;
            }
            $kept[] = $this->upsert($service, $resolved, $harbor, $values, $lookup);
            $checked[] = $service['id'];
            $report['services']++;
            $report['gaps'][$values['gap']] = ($report['gaps'][$values['gap']] ?? 0) + 1;
            if ($harbor !== null) {
                $this->harborReports[(int) $harbor->getID()]['services']++;
            }
        }
        foreach ($this->clients as $client) {
            $report['requests'] += $client->requests();
        }

        $where = ['source' => ImageVersion::SOURCE_REGISTRY];
        if ($kept !== []) {
            $where[] = ['NOT' => ['id' => $kept]];
        }
        $DB->delete(ImageVersion::getTable(), $where);

        $errors = self::blockingErrors($report['errors']);
        if ($errors !== []) {
            $report['status'] = Connection::STATUS_PARTIAL;
        }
        $report['errors'] = $errors;
        $this->saveHarborReports($harbors);
        $report['with_image'] = $this->withImage;
        $report['wud_covered'] = $this->wudCovered;
        $report['reason'] = self::emptyReason($report['services'], $this->withImage, $this->wudCovered);
        foreach ($targets as $target) {
            $report['harbors'][$target['id']] = [
                'host'     => $target['host'],
                'services' => $this->harborReports[$target['id']]['services'] ?? 0,
            ];
        }
        $report['tickets'] = (new UpdateTickets())->process($checked);

        return $report;
    }

    /**
     * Connexions Harbor actives, et leur cible de rattachement (hôte, entités couvertes).
     *
     * @return array{0: array<int, Connection>, 1: list<array{id: int, host: string, entities: list<int>,
     *     level: int}>}
     */
    private function harbors(): array
    {
        global $DB;

        $connections = [];
        $targets = [];
        $where = ['is_active' => 1, 'type' => Connection::TYPE_HARBOR];
        foreach ($DB->request(['FROM' => Connection::getTable(), 'WHERE' => $where, 'ORDER' => 'id']) as $row) {
            $connection = new Connection();
            if (!$connection->getFromDB((int) $row['id'])) {
                continue;
            }
            $id = (int) $row['id'];
            $connections[$id] = $connection;
            $this->harborReports[$id] = ['services' => 0, 'requests' => 0, 'errors' => []];
            $entities = (new DbUtils())->getSonsOf('glpi_entities', (int) $row['entities_id']);
            $targets[] = [
                'id'       => $id,
                'host'     => RegistryResolver::harborHost(
                    (string) $row['url'],
                    (string) ($row['registry_host'] ?? '')
                ),
                'entities' => array_values(array_map('intval', $entities)),
                'level'    => (int) ($DB->request([
                    'SELECT' => ['level'],
                    'FROM'   => 'glpi_entities',
                    'WHERE'  => ['id' => (int) $row['entities_id']],
                ])->current()['level'] ?? 0),
            ];
        }
        // Plusieurs Harbor pour le même hôte : celui de l'entité la plus proche du service d'abord.
        usort($targets, static fn (array $a, array $b): int => [$b['level'], $a['id']] <=> [$a['level'], $b['id']]);

        return [$connections, $targets];
    }

    /**
     * Services actifs avec une image, qu'aucune ligne WUD ne couvre.
     *
     * @return list<array{id: int, image: string, tag: string, digest: string, entities_id: int, is_recursive: int}>
     */
    private function uncoveredServices(): array
    {
        global $DB;

        $covered = [];
        $iterator = $DB->request([
            'SELECT' => ['plugin_dockerinventory_services_id'],
            'FROM'   => ImageVersion::getTable(),
            'WHERE'  => ['source' => ImageVersion::SOURCE_WUD, 'plugin_dockerinventory_services_id' => ['>', 0]],
        ]);
        foreach ($iterator as $row) {
            $covered[(int) $row['plugin_dockerinventory_services_id']] = true;
        }
        $services = [];
        $iterator = $DB->request([
            'SELECT' => ['id', 'image', 'tag', 'digest', 'entities_id', 'is_recursive'],
            'FROM'   => Service::getTable(),
            'WHERE'  => ['is_retired' => 0, ['NOT' => ['image' => '']]],
            'ORDER'  => 'id',
        ]);
        $this->withImage = 0;
        $this->wudCovered = 0;
        foreach ($iterator as $row) {
            $this->withImage++;
            if (isset($covered[(int) $row['id']])) {
                $this->wudCovered++;
                continue;
            }
            $services[] = [
                'id'           => (int) $row['id'],
                'image'        => (string) $row['image'],
                'tag'          => (string) $row['tag'],
                'digest'       => (string) $row['digest'],
                'entities_id'  => (int) $row['entities_id'],
                'is_recursive' => (int) $row['is_recursive'],
            ];
        }

        return $services;
    }

    /**
     * Pourquoi aucun service n'a été vérifié (#129), pour le dire au lieu d'afficher des compteurs à
     * zéro : aucun service inventorié avec une image (Harbor seul, sans Portainer, Docker ni
     * Kubernetes), ou tous suivis par What's Up Docker. Vide si au moins un service a été vérifié.
     */
    public static function emptyReason(int $services, int $withImage, int $wudCovered): string
    {
        return match (true) {
            $services > 0             => '',
            $withImage === 0          => self::REASON_NO_SERVICE,
            $wudCovered >= $withImage => self::REASON_ALL_WUD,
            default                   => '',
        };
    }

    /**
     * Tags du dépôt (une seule lecture par dépôt et par vérification ; cache d'un jour pour Docker Hub).
     *
     * @param array{kind: string, host: string, repository: string, connection_id: int} $resolved
     * @param RegistryReport $report
     * @return array{tags: list<array{name: string, digest: string}>, error: string, date: string}
     */
    private function lookup(array $resolved, ?Connection $harbor, array &$report): array
    {
        if ($resolved['kind'] === RegistryResolver::KIND_UNKNOWN) {
            return ['tags' => [], 'error' => RegistryException::UNKNOWN_REGISTRY, 'date' => $this->now];
        }
        $memoKey = $resolved['kind'] . ':' . $resolved['connection_id'] . ':' . $resolved['repository'];
        if (isset($this->memo[$memoKey])) {
            return $this->memo[$memoKey];
        }
        $isHub = $resolved['kind'] === RegistryClient::KIND_DOCKER_HUB;
        if ($isHub) {
            $cached = $this->readCache($resolved['repository']);
            if ($cached !== null) {
                $report['cached']++;

                return $this->memo[$memoKey] = $cached;
            }
            if ($this->hubBlocked) {
                return $this->memo[$memoKey] = ['tags' => [], 'error' => RegistryException::SKIPPED,
                    'date' => $this->now];
            }
        }
        $client = $this->client($harbor);
        $result = ['tags' => [], 'error' => '', 'date' => $this->now];
        try {
            $result['tags'] = $client->tags($resolved['repository']);
        } catch (RegistryException $e) {
            $code = $e->getMessage();
            if ($code === RegistryException::UNAUTHORIZED && !$client->hasCredentials()) {
                $code = RegistryException::NO_CREDENTIALS;
            }
            $result['error'] = $code;
            $error = ['endpoint' => ($isHub ? 'Docker Hub' : $resolved['host']) . ' ' . $resolved['repository'],
                'code' => $code];
            $report['errors'][] = $error;
            if ($harbor !== null) {
                $this->harborReports[(int) $harbor->getID()]['errors'][] = $error;
            }
            if ($isHub && !in_array($code, [RegistryException::NOT_FOUND, RegistryException::UNAUTHORIZED], true)) {
                // Limite atteinte ou Docker Hub injoignable : plus d'appel pendant cette vérification.
                $this->hubBlocked = true;
            }
        }
        $lasting = ['', RegistryException::NOT_FOUND, RegistryException::UNAUTHORIZED];
        if ($isHub && in_array($result['error'], $lasting, true)) {
            // Résultat valable une journée ; une limite ou une panne sera retentée plus tard.
            $this->writeCache($resolved['repository'], $result);
        }

        return $this->memo[$memoKey] = $result;
    }

    private function client(?Connection $harbor): RegistryClient
    {
        $key = $harbor === null ? 'hub' : 'harbor:' . $harbor->getID();

        return $this->clients[$key] ??= ($this->clientFactory)($harbor);
    }

    /**
     * @return array{tags: list<array{name: string, digest: string}>, error: string, date: string}|null
     */
    private function readCache(string $repository): ?array
    {
        global $DB;

        $row = $DB->request([
            'FROM'  => self::CACHE_TABLE,
            'WHERE' => ['registry' => RegistryClient::KIND_DOCKER_HUB, 'repository' => mb_substr($repository, 0, 255)],
            'LIMIT' => 1,
        ])->current();
        if (!is_array($row) || ($row['date_checked'] ?? null) === null) {
            return null;
        }
        if (strtotime((string) $row['date_checked']) <= strtotime($this->now) - self::CACHE_TTL) {
            return null;
        }
        $tags = json_decode((string) $row['tags'], true);
        $list = [];
        foreach (is_array($tags) ? $tags : [] as $tag) {
            if (is_array($tag) && is_string($tag['name'] ?? null)) {
                $list[] = ['name' => $tag['name'], 'digest' => is_string($tag['digest'] ?? null) ? $tag['digest'] : ''];
            }
        }

        return ['tags' => $list, 'error' => (string) $row['error'], 'date' => (string) $row['date_checked']];
    }

    /**
     * @param array{tags: list<array{name: string, digest: string}>, error: string, date: string} $result
     */
    private function writeCache(string $repository, array $result): void
    {
        global $DB;

        $where = ['registry' => RegistryClient::KIND_DOCKER_HUB, 'repository' => mb_substr($repository, 0, 255)];
        $values = [
            'tags'         => (string) json_encode($result['tags']),
            'error'        => $result['error'],
            'date_checked' => $this->now,
        ];
        $existing = $DB->request(['SELECT' => ['id'], 'FROM' => self::CACHE_TABLE, 'WHERE' => $where])->current();
        if (is_array($existing)) {
            $DB->update(self::CACHE_TABLE, $values, ['id' => (int) $existing['id']]);
        } else {
            $DB->insert(self::CACHE_TABLE, $where + $values);
        }
    }

    /**
     * @param array{id: int, image: string, tag: string, digest: string, entities_id: int, is_recursive: int} $service
     * @param array{kind: string, host: string, repository: string, connection_id: int} $resolved
     * @param array{latest_tag: string, latest_digest: string, gap: string, update_available: int} $values
     * @param array{tags: list<array{name: string, digest: string}>, error: string, date: string} $lookup
     */
    private function upsert(array $service, array $resolved, ?Connection $harbor, array $values, array $lookup): int
    {
        global $DB;

        $table = ImageVersion::getTable();
        $where = [
            'plugin_dockerinventory_connections_id' => $resolved['connection_id'],
            'plugin_dockerinventory_services_id'    => $service['id'],
            'container_uid'                         => '',
        ];
        $isHub = $resolved['kind'] === RegistryClient::KIND_DOCKER_HUB;
        $row = [
            'entities_id'       => $service['entities_id'],
            'is_recursive'      => $service['is_recursive'],
            'containers'        => '',
            'container_count'   => 0,
            'image'             => mb_substr($resolved['repository'], 0, 255),
            'registry_name'     => $isHub ? 'hub' : mb_substr($resolved['host'], 0, 255),
            'registry_url'      => $isHub ? 'https://registry-1.docker.io'
                : mb_substr($harbor !== null ? (string) $harbor->fields['url'] : '', 0, 255),
            'deployed_tag'      => mb_substr($service['tag'], 0, 255),
            'deployed_digest'   => mb_substr($service['digest'], 0, 255),
            'latest_tag'        => mb_substr($values['latest_tag'], 0, 255),
            'latest_digest'     => mb_substr($values['latest_digest'], 0, 255),
            'gap'               => $values['gap'],
            'update_available'  => $values['update_available'],
            'is_out_of_sync'    => 0,
            'deployed_versions' => '',
            'match_method'      => '',
            'source'            => ImageVersion::SOURCE_REGISTRY,
            'error'             => $lookup['error'],
            'date_checked'      => $lookup['date'],
            'date_mod'          => $this->now,
        ];
        $existing = $DB->request(['SELECT' => ['id'], 'FROM' => $table, 'WHERE' => $where, 'LIMIT' => 1])->current();
        if (is_array($existing)) {
            $DB->update($table, $row, ['id' => (int) $existing['id']]);

            return (int) $existing['id'];
        }
        $DB->insert($table, $where + $row + ['date_creation' => $this->now]);

        return (int) $DB->insertId();
    }

    /**
     * Erreurs à signaler : sans les raisons d'une version non vérifiée qui ne demandent rien
     * (registre inconnu, image introuvable ou privée...).
     *
     * @param list<array{endpoint: string, code: string}> $errors
     * @return list<array{endpoint: string, code: string}>
     */
    private static function blockingErrors(array $errors): array
    {
        return array_values(array_filter(
            $errors,
            static fn (array $e): bool => !in_array($e['code'], self::NOT_ERRORS, true)
        ));
    }

    /**
     * Dernière vérification de chaque connexion Harbor (affichée sur la connexion).
     *
     * @param array<int, Connection> $harbors
     */
    private function saveHarborReports(array $harbors): void
    {
        global $DB;

        foreach ($harbors as $id => $harbor) {
            $part = $this->harborReports[$id];
            $client = $this->clients['harbor:' . $id] ?? null;
            $part['requests'] = $client !== null ? $client->requests() : 0;
            $errors = self::blockingErrors($part['errors']);
            $status = match (true) {
                $errors === []                       => Connection::STATUS_OK,
                count($errors) < $part['requests']   => Connection::STATUS_PARTIAL,
                default                              => Connection::STATUS_ERROR,
            };
            $DB->update(Connection::getTable(), [
                'last_import_date'   => $this->now,
                'last_import_status' => $status,
                'last_import_report' => (string) json_encode(['status' => $status] + $part),
            ] + Connection::successValues($status, $this->now), ['id' => $id]);
            $harbor->getFromDB($id);
        }
    }
}

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

namespace GlpiPlugin\Dockerinventory\Traefik;

use DbUtils;
use GlpiPlugin\Dockerinventory\Connection;
use GlpiPlugin\Dockerinventory\Exposure\ExposureMapper;
use GlpiPlugin\Dockerinventory\Service;

/**
 * Lecture sur clic (bouton « Lire Traefik maintenant », aucune tâche automatique) de la
 * configuration appliquée par Traefik, comparée aux routes des étiquettes des services inventoriés
 * dans les entités de la session. Le compte rendu est enregistré sur la connexion (dernière
 * lecture) ; rien n'est modifié dans Traefik ni dans l'inventaire.
 *
 * @phpstan-import-type Declared from TraefikMapper
 */
final class TraefikReader
{
    /** @var \Closure(Connection): TraefikClient */
    private \Closure $clientFactory;

    /**
     * @param (\Closure(Connection): TraefikClient)|null $clientFactory
     */
    public function __construct(?\Closure $clientFactory = null)
    {
        $this->clientFactory = $clientFactory ?? static fn (Connection $connection): TraefikClient
            => $connection->getTraefikClient();
    }

    /**
     * @return array<string, mixed> Compte rendu enregistré (status, version, routes, not_applied...).
     */
    public function read(Connection $connection): array
    {
        global $DB;

        $now = (string) ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'));
        $report = ['status' => Connection::STATUS_OK, 'traefik_version' => '', 'errors' => []];
        try {
            $client = ($this->clientFactory)($connection);
            $report['traefik_version'] = $client->version();
            $report += TraefikMapper::map($client->snapshot(), self::declaredRoutes());
            if ($report['truncated']) {
                $report['status'] = Connection::STATUS_PARTIAL;
            }
        } catch (TraefikException $e) {
            $report['errors'][] = ['endpoint' => '', 'code' => $e->getMessage()];
            $report['status'] = Connection::STATUS_ERROR;
        }

        $DB->update(Connection::getTable(), [
            'last_import_date'   => $now,
            'last_import_status' => $report['status'],
            'last_import_report' => (string) json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ] + Connection::successValues((int) $report['status'], $now), ['id' => (int) $connection->getID()]);
        $connection->getFromDB((int) $connection->getID());

        return $report;
    }

    /**
     * Routes Traefik déclarées par les étiquettes des services en service, dans les entités de la session.
     *
     * @return list<Declared>
     */
    public static function declaredRoutes(): array
    {
        global $DB;

        $declared = [];
        foreach (
            $DB->request([
                'SELECT' => ['id', 'name', 'stack_name', 'routes'],
                'FROM'   => Service::getTable(),
                'WHERE'  => ['is_retired' => 0, ['NOT' => ['routes' => '']], ['NOT' => ['routes' => null]]]
                    + (new DbUtils())->getEntitiesRestrictCriteria(Service::getTable(), '', '', true),
                'ORDER'  => ['stack_name', 'name'],
            ]) as $row
        ) {
            $label = trim((string) $row['stack_name'] . ' / ' . (string) $row['name'], ' /');
            foreach (ExposureMapper::decode((string) $row['routes']) as $route) {
                if ($route['source'] !== ExposureMapper::SOURCE_TRAEFIK || $route['name'] === '') {
                    continue;
                }
                $declared[] = [
                    'name'       => $route['name'],
                    'host'       => $route['host'],
                    'service_id' => (int) $row['id'],
                    'service'    => $label,
                ];
            }
        }

        return $declared;
    }
}

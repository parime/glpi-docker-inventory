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
use CommonGLPI;
use Computer;
use DbUtils;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Dockerinventory\Portainer\PortainerMapper;

/**
 * Nœud Docker Swarm, ou hôte d'un environnement Docker autonome (rôle « standalone »), rattaché
 * par l'import à l'ordinateur du même nom d'hôte. Pas de page
 * propre : il s'affiche dans l'onglet « Services du nœud » de l'ordinateur et sur la fiche des
 * services. Lisible avec le droit de lecture des services du plugin.
 */
class Node extends CommonDBTM
{
    public static function getTypeName($nb = 0)
    {
        return $nb > 1 ? __('Nœuds Swarm', 'dockerinventory') : __('Nœud Swarm', 'dockerinventory');
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof Computer || $item->isNewItem() || !Service::canView()) {
            return '';
        }
        $count = 0;
        if ($_SESSION['glpishow_count_on_tabs'] ?? false) {
            $count = countElementsInTable(static::getTable(), ['computers_id' => (int) $item->getID()]);
        }

        $label = __('Services du nœud', 'dockerinventory');

        return self::createTabEntry($label, $count, static::class, Service::getIcon());
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        // ajax/common.tabs.php sert le contenu sans passer par getTabNameForItem().
        if (!$item instanceof Computer || !Service::canView() || !$item->can($item->getID(), READ)) {
            return false;
        }
        global $DB;

        $nodes = [];
        $serviceTable = Service::getTable();
        $nodeIterator = $DB->request([
            'FROM'  => static::getTable(),
            'WHERE' => ['computers_id' => (int) $item->getID()],
            'ORDER' => ['is_retired', 'hostname'],
        ]);
        foreach ($nodeIterator as $nodeRow) {
            $node = self::present($nodeRow);
            $node['services'] = [];
            if ($nodeRow['role'] === PortainerMapper::ROLE_STANDALONE) {
                // Hôte autonome : tous ses services, conteneurs arrêtés compris.
                $iterator = $DB->request([
                    'SELECT' => ['s.*', 's.running_replicas AS running_tasks'],
                    'FROM'   => $serviceTable . ' AS s',
                    'WHERE'  => [
                        's.plugin_dockerinventory_connections_id' => $nodeRow['plugin_dockerinventory_connections_id'],
                        's.endpoint_uid'                          => $nodeRow['endpoint_uid'],
                    ] + (new DbUtils())->getEntitiesRestrictCriteria('s', '', '', true),
                    'ORDER'  => ['s.is_retired', 's.stack_name', 's.name'],
                ]);
                foreach ($iterator as $row) {
                    $node['services'][] = Service::present($row) + ['running_tasks' => (int) $row['running_tasks']];
                }
                $nodes[] = $node;
                continue;
            }
            $iterator = $DB->request([
                'SELECT'     => ['s.*', 'sn.running_tasks'],
                'FROM'       => 'glpi_plugin_dockerinventory_services_nodes AS sn',
                'INNER JOIN' => [
                    $serviceTable . ' AS s' => ['ON' => ['sn' => 'plugin_dockerinventory_services_id', 's' => 'id']],
                ],
                'WHERE'      => ['sn.plugin_dockerinventory_nodes_id' => (int) $nodeRow['id']]
                    + (new DbUtils())->getEntitiesRestrictCriteria('s', '', '', true),
                'ORDER'      => ['s.is_retired', 's.name'],
            ]);
            foreach ($iterator as $row) {
                $node['services'][] = Service::present($row) + ['running_tasks' => (int) $row['running_tasks']];
            }
            $nodes[] = $node;
        }
        TemplateRenderer::getInstance()->display('@dockerinventory/computer_nodes.html.twig', [
            'nodes' => $nodes,
        ]);

        return true;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function present(array $row): array
    {
        $computer = null;
        $computerId = (int) ($row['computers_id'] ?? 0);
        $item = new Computer();
        if ($computerId > 0 && $item->can($computerId, READ)) {
            $computer = ['name' => (string) $item->fields['name'], 'url' => Computer::getFormURLWithID($computerId)];
        }

        return [
            'hostname'       => (string) ($row['hostname'] ?? ''),
            'role'           => (string) ($row['role'] ?? ''),
            'availability'   => (string) ($row['availability'] ?? ''),
            'state'          => (string) ($row['state'] ?? ''),
            'engine_version' => (string) ($row['engine_version'] ?? ''),
            'address'        => (string) ($row['address'] ?? ''),
            'is_retired'     => (bool) ($row['is_retired'] ?? false),
            'last_seen'      => (string) ($row['last_seen'] ?? ''),
            'running_tasks'  => (int) ($row['running_tasks'] ?? 0),
            'computer'       => $computer,
        ];
    }
}

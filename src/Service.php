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
use CommonGLPI;
use DbUtils;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Dockerinventory\Compat\Base\ServiceBase;
use GlpiPlugin\Dockerinventory\Portainer\PortainerMapper;
use Session;

/**
 * Service Docker importé depuis Portainer : service Swarm, service Compose d'un hôte autonome ou
 * conteneur autonome hors Compose. Lecture seule : seul l'import écrit ces fiches,
 * et ne les supprime jamais (un service disparu est marqué « retiré »).
 */
class Service extends ServiceBase
{
    public const RIGHTNAME = 'plugin_dockerinventory_service';

    public static function getTypeName($nb = 0)
    {
        return $nb > 1
            ? __('Services de conteneurs', 'dockerinventory')
            : __('Service de conteneur', 'dockerinventory');
    }

    public static function getIcon()
    {
        return 'ti ti-brand-docker';
    }

    public static function canView(): bool
    {
        return (bool) Session::haveRight(self::RIGHTNAME, READ);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canUpdate(): bool
    {
        return false;
    }

    public static function canDelete(): bool
    {
        return false;
    }

    public static function canPurge(): bool
    {
        return false;
    }

    /**
     * @return array<int, string>
     */
    public function getRights($interface = 'central')
    {
        return [READ => __('Read')];
    }

    public function defineTabs($options = [])
    {
        $tabs = [];
        $this->addDefaultFormTab($tabs);
        $this->addStandardTab(ImageVersion::class, $tabs, $options);

        return $tabs;
    }

    public function rawSearchOptions()
    {
        $table = static::getTable();
        $own = [
            2  => ['id', __('ID'), 'number'],
            3  => ['image', __('Image', 'dockerinventory'), 'string'],
            4  => ['tag', __('Tag', 'dockerinventory'), 'string'],
            5  => ['digest', __('Empreinte (digest)', 'dockerinventory'), 'string'],
            6  => ['replicas', __('Réplicas voulus', 'dockerinventory'), 'number'],
            7  => ['running_replicas', __('Réplicas en cours', 'dockerinventory'), 'number'],
            9  => ['stack_name', __('Stack', 'dockerinventory'), 'string'],
            10 => ['is_retired', __('Retiré', 'dockerinventory'), 'bool'],
            11 => ['last_seen', __('Vu la dernière fois', 'dockerinventory'), 'datetime'],
            12 => ['endpoint_name', __('Environnement Portainer', 'dockerinventory'), 'string'],
            14 => ['ports', __('Ports publiés', 'dockerinventory'), 'text'],
            15 => ['networks', __('Réseaux', 'dockerinventory'), 'text'],
            16 => ['secrets', __('Secrets (noms)', 'dockerinventory'), 'text'],
            17 => ['date_retired', __('Retiré le', 'dockerinventory'), 'datetime'],
            18 => ['kind', __('Nature', 'dockerinventory'), 'specific'],
            19 => ['state', __('État des conteneurs', 'dockerinventory'), 'string'],
            20 => ['restart_policy', __('Politique de redémarrage', 'dockerinventory'), 'string'],
            21 => ['volumes', __('Volumes (noms)', 'dockerinventory'), 'text'],
        ];
        $options = parent::rawSearchOptions();
        foreach ($own as $id => [$field, $name, $datatype]) {
            $options[] = [
                'id'            => $id,
                'table'         => $table,
                'field'         => $field,
                'name'          => $name,
                'datatype'      => $datatype,
                'massiveaction' => false,
            ] + ($datatype === 'specific' ? ['searchtype' => ['equals', 'notequals']] : []);
        }
        $options[] = [
            'id'            => 8,
            'table'         => Appliance::getTable(),
            'field'         => 'name',
            'linkfield'     => 'appliances_id',
            'name'          => Appliance::getTypeName(1),
            'datatype'      => 'dropdown',
            'massiveaction' => false,
        ];
        $options[] = [
            'id'            => 13,
            'table'         => Connection::getTable(),
            'field'         => 'name',
            'linkfield'     => 'plugin_dockerinventory_connections_id',
            'name'          => Connection::getTypeName(1),
            'datatype'      => 'itemlink',
            'massiveaction' => false,
        ];
        $options[] = [
            'id'            => 80,
            'table'         => 'glpi_entities',
            'field'         => 'completename',
            'name'          => \Entity::getTypeName(1),
            'datatype'      => 'dropdown',
            'massiveaction' => false,
        ];

        return $options;
    }

    /**
     * Libellés des natures de service (colonne `kind`).
     *
     * @return array<string, string>
     */
    public static function kindLabels(): array
    {
        return [
            PortainerMapper::KIND_SWARM     => __('Service Swarm', 'dockerinventory'),
            PortainerMapper::KIND_COMPOSE   => __('Service Compose', 'dockerinventory'),
            PortainerMapper::KIND_CONTAINER => __('Conteneur autonome', 'dockerinventory'),
        ];
    }

    /**
     * @param array<string, mixed>|string $values
     * @param array<string, mixed> $options
     */
    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if ($field === 'kind') {
            $value = is_array($values) ? (string) ($values[$field] ?? '') : (string) $values;

            return htmlescape(self::kindLabels()[$value] ?? $value);
        }

        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    /**
     * @param array<string, mixed>|string $values
     * @param array<string, mixed> $options
     */
    public static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = [])
    {
        if ($field === 'kind') {
            $value = is_array($values) ? (string) ($values[$field] ?? '') : (string) $values;

            return \Dropdown::showFromArray(
                (string) $name,
                self::kindLabels(),
                ['display' => false, 'value' => $value] + $options
            );
        }

        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }

    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);
        TemplateRenderer::getInstance()->display('@dockerinventory/service_form.html.twig', [
            'item'      => $this,
            'params'    => ['canedit' => false] + $options,
            'service'   => self::present($this->fields),
            'appliance' => self::applianceLink((int) $this->fields['appliances_id']),
            'nodes'     => $this->getNodes(),
        ]);

        return true;
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof Appliance || $item->isNewItem() || !self::canView()) {
            return '';
        }
        $count = 0;
        if ($_SESSION['glpishow_count_on_tabs'] ?? false) {
            $count = countElementsInTable(static::getTable(), self::criteriaForAppliance((int) $item->getID()));
        }

        return self::createTabEntry(__('Services', 'dockerinventory'), $count, static::class, self::getIcon());
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        // ajax/common.tabs.php sert le contenu sans passer par getTabNameForItem().
        if (!$item instanceof Appliance || !self::canView() || !$item->can($item->getID(), READ)) {
            return false;
        }
        global $DB;

        $services = [];
        $iterator = $DB->request([
            'FROM'  => static::getTable(),
            'WHERE' => self::criteriaForAppliance((int) $item->getID()),
            'ORDER' => ['is_retired', 'name'],
        ]);
        foreach ($iterator as $row) {
            $services[] = self::present($row);
        }
        TemplateRenderer::getInstance()->display('@dockerinventory/appliance_services.html.twig', [
            'services' => $services,
        ]);

        return true;
    }

    /**
     * @return array<int|string, mixed>
     */
    private static function criteriaForAppliance(int $applianceId): array
    {
        return ['appliances_id' => $applianceId]
            + (new DbUtils())->getEntitiesRestrictCriteria(static::getTable(), '', '', true);
    }

    /**
     * Valeurs prêtes à afficher (échappées par Twig).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function present(array $row): array
    {
        $digest = (string) ($row['digest'] ?? '');
        $replicas = $row['replicas'] ?? null;
        $running = (int) ($row['running_replicas'] ?? 0);
        $kind = (string) ($row['kind'] ?? '') ?: PortainerMapper::KIND_SWARM;
        $states = self::split((string) ($row['state'] ?? ''));

        return [
            'id'           => (int) ($row['id'] ?? 0),
            'url'          => self::getFormURLWithID((int) ($row['id'] ?? 0)),
            'name'         => (string) ($row['name'] ?? ''),
            'stack_name'   => (string) ($row['stack_name'] ?? ''),
            'image'        => (string) ($row['image'] ?? ''),
            'tag'          => (string) ($row['tag'] ?? ''),
            'digest'       => $digest,
            'digest_short' => strlen($digest) > 19 ? substr($digest, 0, 19) . '...' : $digest,
            'mode'         => (string) ($row['mode'] ?? ''),
            'replicas'     => $replicas === null ? sprintf(__('%d (global)', 'dockerinventory'), $running)
                : sprintf('%d / %d', $running, (int) $replicas),
            'replicas_ok'  => $replicas === null ? $running > 0 : $running >= (int) $replicas,
            'ports'        => self::split((string) ($row['ports'] ?? '')),
            'networks'     => self::split((string) ($row['networks'] ?? '')),
            'secrets'      => self::split((string) ($row['secrets'] ?? '')),
            'volumes'      => self::split((string) ($row['volumes'] ?? '')),
            'kind'         => $kind,
            'kind_label'   => self::kindLabels()[$kind] ?? $kind,
            'is_swarm'     => $kind === PortainerMapper::KIND_SWARM,
            'states'       => $states,
            'restart_policy' => (string) ($row['restart_policy'] ?? ''),
            'endpoint'     => (string) ($row['endpoint_name'] ?? ''),
            'is_retired'   => (bool) ($row['is_retired'] ?? false),
            'date_retired' => (string) ($row['date_retired'] ?? ''),
            'last_seen'    => (string) ($row['last_seen'] ?? ''),
        ];
    }

    /**
     * @return list<string>
     */
    private static function split(string $value): array
    {
        return $value === '' ? [] : explode(', ', $value);
    }

    /**
     * @return array{name: string, url: string}|null Null si absente ou non visible.
     */
    private static function applianceLink(int $applianceId): ?array
    {
        $appliance = new Appliance();
        if ($applianceId <= 0 || !$appliance->can($applianceId, READ)) {
            return null;
        }

        return ['name' => (string) $appliance->fields['name'], 'url' => Appliance::getFormURLWithID($applianceId)];
    }

    /**
     * Nœuds où tournent les tâches du service, avec l'ordinateur rattaché quand il est visible.
     *
     * @return list<array<string, mixed>>
     */
    private function getNodes(): array
    {
        global $DB;

        $nodes = [];
        $iterator = $DB->request([
            'SELECT'     => ['n.*', 'sn.running_tasks'],
            'FROM'       => 'glpi_plugin_dockerinventory_services_nodes AS sn',
            'INNER JOIN' => [
                Node::getTable() . ' AS n' => ['ON' => ['sn' => 'plugin_dockerinventory_nodes_id', 'n' => 'id']],
            ],
            'WHERE'      => ['sn.plugin_dockerinventory_services_id' => (int) $this->getID()],
            'ORDER'      => 'n.hostname',
        ]);
        foreach ($iterator as $row) {
            $nodes[] = Node::present($row);
        }

        return $nodes;
    }
}

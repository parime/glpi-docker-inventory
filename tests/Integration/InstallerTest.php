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

namespace GlpiPlugin\Dockerinventory\Tests\Integration;

use GlpiPlugin\Dockerinventory\Install\Installer;
use GlpiPlugin\Dockerinventory\Service;
use Migration;
use PHPUnit\Framework\TestCase;

/**
 * Mise à jour depuis la 0.3.0 (#25) : les tables des stacks et des services sont ramenées à leur
 * forme 0.3.0, avec une stack et un service Swarm, puis l'installateur est relancé.
 */
final class InstallerTest extends TestCase
{
    private const STACKS = 'glpi_plugin_dockerinventory_stacks';

    /** Connexion fictive, sans ligne dans la table des connexions : rien d'autre n'est touché. */
    private const CONNECTION = 999001;

    protected function tearDown(): void
    {
        global $DB;

        $DB->delete(self::STACKS, ['plugin_dockerinventory_connections_id' => self::CONNECTION]);
        $DB->delete(Service::getTable(), ['plugin_dockerinventory_connections_id' => self::CONNECTION]);
    }

    public function testUpgradeFrom030KeepsSwarmRowsAndAddsComposeColumns(): void
    {
        global $DB;

        $this->downgradeTo030();
        $this->assertFalse($DB->fieldExists(self::STACKS, 'compose_project', false));
        $this->assertFalse($DB->fieldExists(Service::getTable(), 'kind', false));
        $DB->insert(self::STACKS, [
            'plugin_dockerinventory_connections_id' => self::CONNECTION,
            'endpoint_uid'                          => 1,
            'stack_uid'                             => 11,
            'name'                                  => 'boutique',
        ]);
        $DB->insert(Service::getTable(), [
            'name'                                  => 'boutique_web',
            'plugin_dockerinventory_connections_id' => self::CONNECTION,
            'endpoint_uid'                          => 1,
            'service_uid'                           => 'svc-boutique-web',
            'stack_name'                            => 'boutique',
            'image'                                 => 'boutique/web',
            'tag'                                   => '2.4.1',
        ]);

        $this->assertTrue((new Installer())->install(new Migration(PLUGIN_DOCKERINVENTORY_VERSION)));

        foreach (['kind', 'compose_project'] as $field) {
            $this->assertTrue($DB->fieldExists(self::STACKS, $field, false), $field);
        }
        foreach (['kind', 'volumes', 'state', 'restart_policy', 'container_uids', 'container_names'] as $field) {
            $this->assertTrue($DB->fieldExists(Service::getTable(), $field, false), $field);
        }
        $stack = $DB->request(['FROM' => self::STACKS, 'WHERE' => [
            'plugin_dockerinventory_connections_id' => self::CONNECTION,
        ]])->current();
        $this->assertSame('swarm', $stack['kind']);
        $this->assertSame('', $stack['compose_project']);
        $service = $DB->request(['FROM' => Service::getTable(), 'WHERE' => [
            'plugin_dockerinventory_connections_id' => self::CONNECTION,
        ]])->current();
        $this->assertSame('swarm', $service['kind']);
        $this->assertSame('2.4.1', $service['tag']);

        // Deux projets Compose hors Portainer (stack_uid 0) sur le même hôte : acceptés.
        foreach (['wiki-interne', 'outils-labo'] as $project) {
            $this->assertTrue((bool) $DB->insert(self::STACKS, [
                'plugin_dockerinventory_connections_id' => self::CONNECTION,
                'endpoint_uid'                          => 2,
                'stack_uid'                             => 0,
                'kind'                                  => 'compose',
                'compose_project'                       => $project,
                'name'                                  => $project,
            ]));
        }
        // Identifiant de service Compose plus long que les 64 caractères de la 0.3.0.
        $uid = 'compose:2:' . str_repeat('projet-fictif-', 6) . ':service';
        $DB->insert(Service::getTable(), [
            'name'                                  => 'service',
            'plugin_dockerinventory_connections_id' => self::CONNECTION,
            'service_uid'                           => $uid,
            'kind'                                  => 'compose',
        ]);
        $this->assertSame(1, countElementsInTable(Service::getTable(), ['service_uid' => $uid]));

        // Relancer l'installateur (mise à jour suivante) ne change rien.
        $this->assertTrue((new Installer())->install(new Migration(PLUGIN_DOCKERINVENTORY_VERSION)));
        $this->assertSame(3, countElementsInTable(self::STACKS, [
            'plugin_dockerinventory_connections_id' => self::CONNECTION,
        ]));
    }

    /**
     * Forme 0.3.0 des tables des stacks et des services (colonnes et clé d'unicité).
     */
    private function downgradeTo030(): void
    {
        global $DB;

        $migration = new Migration(PLUGIN_DOCKERINVENTORY_VERSION);
        $migration->dropKey(self::STACKS, 'unicity');
        $migration->dropField(self::STACKS, 'kind');
        $migration->dropField(self::STACKS, 'compose_project');
        $migration->migrationOneTable(self::STACKS);
        $migration->addKey(self::STACKS, ['plugin_dockerinventory_connections_id', 'stack_uid'], 'unicity', 'UNIQUE');
        $migration->migrationOneTable(self::STACKS);

        $services = Service::getTable();
        $migration->dropKey($services, 'kind');
        foreach (['kind', 'volumes', 'state', 'restart_policy', 'container_uids', 'container_names'] as $field) {
            $migration->dropField($services, $field);
        }
        $migration->changeField($services, 'service_uid', 'service_uid', "varchar(64) NOT NULL DEFAULT ''");
        $migration->migrationOneTable($services);
        $this->assertFalse($DB->fieldExists($services, 'volumes', false));
    }
}

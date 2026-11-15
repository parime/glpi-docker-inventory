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

use GlpiPlugin\Dockerinventory\Connection;
use GlpiPlugin\Dockerinventory\Harbor\HarborImporter;
use GlpiPlugin\Dockerinventory\Harbor\HarborSettings;
use GlpiPlugin\Dockerinventory\HarborArtifact;
use GlpiPlugin\Dockerinventory\Install\Installer;
use GlpiPlugin\Dockerinventory\Lifecycle\LifecycleChecker;
use GlpiPlugin\Dockerinventory\Lifecycle\ProductMap;
use GlpiPlugin\Dockerinventory\Lifecycle\Settings;
use GlpiPlugin\Dockerinventory\LifecycleProduct;
use GlpiPlugin\Dockerinventory\Service;
use GlpiPlugin\Dockerinventory\Ticket\EolTickets;
use GlpiPlugin\Dockerinventory\Ticket\VulnTickets;
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
     * Mise à jour depuis la 0.4.0 (#26) : tables de la fin de support, correspondances fournies,
     * réglages des tickets de fin de support (désactivés) et tâche automatique quotidienne ; la
     * source reste désactivée.
     */
    public function testUpgradeFrom040AddsEndOfSupport(): void
    {
        global $DB;

        $mappings = [];
        foreach ($DB->request(['FROM' => LifecycleProduct::getTable()]) as $row) {
            unset($row['id']);
            $mappings[] = $row;
        }
        $settings = Settings::get();
        try {
            foreach ([LifecycleProduct::getTable(), LifecycleChecker::CACHE_TABLE, EolTickets::TABLE] as $table) {
                $DB->dropTable($table, true);
            }
            $migration = new Migration(PLUGIN_DOCKERINVENTORY_VERSION);
            $migration->dropField(Connection::getTable(), 'ticket_eol_enabled');
            $migration->dropField(Connection::getTable(), 'ticket_eol_days');
            $migration->migrationOneTable(Connection::getTable());
            Settings::delete();
            \CronTask::unregister('dockerinventory');

            $this->assertTrue((new Installer())->install(new Migration(PLUGIN_DOCKERINVENTORY_VERSION)));

            foreach ([LifecycleProduct::getTable(), LifecycleChecker::CACHE_TABLE, EolTickets::TABLE] as $table) {
                $this->assertTrue($DB->tableExists($table, false), $table);
            }
            $this->assertTrue($DB->fieldExists(Connection::getTable(), 'ticket_eol_enabled', false));
            $this->assertTrue($DB->fieldExists(Connection::getTable(), 'ticket_eol_days', false));
            $this->assertSame(count(ProductMap::DEFAULTS), countElementsInTable(LifecycleProduct::getTable()));
            $this->assertSame(1, countElementsInTable(
                LifecycleProduct::getTable(),
                ['image' => 'postgres', 'product' => 'postgresql', 'is_default' => 1]
            ));
            $this->assertFalse(Settings::enabled());
            $this->assertSame(Settings::DEFAULT_WARNING_DAYS, Settings::warningDays());
            $this->assertSame(1, countElementsInTable('glpi_crontasks', [
                'itemtype' => LifecycleProduct::class,
                'name'     => LifecycleProduct::CRON_NAME,
            ]));

            // Correspondance supprimée par l'administrateur : pas rajoutée à la mise à jour suivante.
            $DB->delete(LifecycleProduct::getTable(), ['image' => 'postgres']);
            $this->assertTrue((new Installer())->install(new Migration(PLUGIN_DOCKERINVENTORY_VERSION)));
            $this->assertSame(0, countElementsInTable(LifecycleProduct::getTable(), ['image' => 'postgres']));
        } finally {
            $DB->delete(LifecycleProduct::getTable(), ['id' => ['>', 0]]);
            foreach ($mappings as $row) {
                $DB->insert(LifecycleProduct::getTable(), $row);
            }
            Settings::save($settings['enabled'], $settings['warning_days']);
            (new Installer())->install(new Migration(PLUGIN_DOCKERINVENTORY_VERSION));
        }
    }

    /**
     * Mise à jour depuis la 0.5.0 (#9) : tables de Harbor (projets, artefacts) et des tickets de
     * vulnérabilité, réglages des tickets (désactivés), dernière lecture de Harbor, tâche
     * automatique ; les connexions existantes sont gardées.
     */
    public function testUpgradeFrom050AddsHarbor(): void
    {
        global $DB;

        $connectionId = (int) (new Connection())->add([
            'name'    => 'Portainer fictif (mise à jour 0.5.0)',
            'url'     => 'https://portainer.exemple.test:9443',
            'api_key' => 'ptr_fictive_cle_migration',
        ]);
        $this->assertGreaterThan(0, $connectionId);
        try {
            foreach ([HarborImporter::PROJECTS_TABLE, HarborArtifact::getTable(), VulnTickets::TABLE] as $table) {
                $DB->dropTable($table, true);
            }
            $migration = new Migration(PLUGIN_DOCKERINVENTORY_VERSION);
            $fields = ['ticket_vuln_enabled', 'ticket_vuln_severity', 'last_scan_date', 'last_scan_status',
                'last_scan_report'];
            foreach ($fields as $field) {
                $migration->dropField(Connection::getTable(), $field);
            }
            $migration->migrationOneTable(Connection::getTable());
            \CronTask::unregister('dockerinventory');
            $this->assertFalse($DB->fieldExists(Connection::getTable(), 'ticket_vuln_enabled', false));

            $this->assertTrue((new Installer())->install(new Migration(PLUGIN_DOCKERINVENTORY_VERSION)));

            foreach ([HarborImporter::PROJECTS_TABLE, HarborArtifact::getTable(), VulnTickets::TABLE] as $table) {
                $this->assertTrue($DB->tableExists($table, false), $table);
            }
            foreach ($fields as $field) {
                $this->assertTrue($DB->fieldExists(Connection::getTable(), $field, false), $field);
            }
            $connection = new Connection();
            $this->assertTrue($connection->getFromDB($connectionId));
            $this->assertSame(0, (int) $connection->fields['ticket_vuln_enabled']);
            $this->assertSame('critical', $connection->fields['ticket_vuln_severity']);
            $this->assertSame('https://portainer.exemple.test:9443', $connection->fields['url']);
            $this->assertSame(1, countElementsInTable('glpi_crontasks', [
                'itemtype' => Connection::class,
                'name'     => Installer::CRON_HARBOR,
            ]));
            $this->assertSame([], HarborSettings::configuredHosts());
        } finally {
            (new Connection())->delete(['id' => $connectionId], true);
            (new Installer())->install(new Migration(PLUGIN_DOCKERINVENTORY_VERSION));
        }
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

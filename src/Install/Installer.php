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

namespace GlpiPlugin\Dockerinventory\Install;

use CronTask;
use DBConnection;
use DisplayPreference;
use GlpiPlugin\Dockerinventory\Connection;
use GlpiPlugin\Dockerinventory\ImageVersion;
use GlpiPlugin\Dockerinventory\Lifecycle\Settings;
use GlpiPlugin\Dockerinventory\LifecycleProduct;
use GlpiPlugin\Dockerinventory\Service;
use Migration;
use ProfileRight;

/**
 * Installation, mise à jour et désinstallation. Tables créées une fois (CREATE TABLE seulement
 * ici), évolutions futures par Migration. La désinstallation retire les données du plugin et tout
 * ce qu'il a écrit dans les tables du cœur (droit, tâche automatique, préférences d'affichage),
 * mais jamais les applications (Appliance) créées pour les stacks ni les tickets de mise à jour :
 * ce sont des objets GLPI que les utilisateurs ont pu compléter et lier.
 */
final class Installer
{
    public const TABLES = [
        'glpi_plugin_dockerinventory_connections',
        'glpi_plugin_dockerinventory_stacks',
        'glpi_plugin_dockerinventory_services',
        'glpi_plugin_dockerinventory_nodes',
        'glpi_plugin_dockerinventory_services_nodes',
        'glpi_plugin_dockerinventory_imageversions',
        'glpi_plugin_dockerinventory_registrytags',
        'glpi_plugin_dockerinventory_tickets',
        'glpi_plugin_dockerinventory_lifecycleproducts',
        'glpi_plugin_dockerinventory_lifecycles',
        'glpi_plugin_dockerinventory_eoltickets',
    ];

    public const CRON_NAME = 'portainerimport';
    public const CRON_WUD = 'wudimport';
    public const CRON_REGISTRY = 'registrycheck';

    /** Colonnes affichées par défaut dans la liste des services (options de recherche de Service). */
    private const DEFAULT_COLUMNS = [8, 3, 4, 6, 7, 10, 11];

    /** Colonnes affichées par défaut dans la recherche des versions (options de ImageVersion). */
    private const VERSION_COLUMNS = [1, 3, 4, 5, 6, 7, 8, 9, 10];

    /** Colonnes affichées par défaut dans la liste des connexions : type, adresse, active, dernier import. */
    private const CONNECTION_COLUMNS = [7, 3, 4, 5];

    public function install(Migration $migration): bool
    {
        $created = $this->createTables();
        $this->migrate($migration);
        if (in_array(LifecycleProduct::getTable(), $created, true)) {
            // Correspondances image => produit fournies, une seule fois (modifiables ensuite).
            LifecycleProduct::installDefaults();
        }

        $firstInstall = countElementsInTable('glpi_profilerights', ['name' => Service::RIGHTNAME]) === 0;
        if ($firstInstall) {
            // Une seule fois : addProfileRights() insère sans vérifier, et la mise à jour d'une
            // version à l'autre repasse par install() (doublon refusé par la clé unique).
            ProfileRight::addProfileRights([Service::RIGHTNAME]);
            $this->grantReadToAdministrators();
            $this->installDisplayPreferences(Service::class, self::DEFAULT_COLUMNS);
        }
        $defaults = [ImageVersion::class => self::VERSION_COLUMNS, Connection::class => self::CONNECTION_COLUMNS];
        foreach ($defaults as $itemtype => $columns) {
            if (countElementsInTable(DisplayPreference::getTable(), ['itemtype' => $itemtype]) === 0) {
                $this->installDisplayPreferences($itemtype, $columns);
            }
        }

        foreach ([self::CRON_NAME, self::CRON_WUD, self::CRON_REGISTRY] as $cron) {
            CronTask::register(Connection::class, $cron, HOUR_TIMESTAMP, [
                'state'         => CronTask::STATE_WAITING,
                'logs_lifetime' => 30,
            ]);
        }
        // Fin de support : une fois par jour, sans effet tant que la source est désactivée.
        CronTask::register(LifecycleProduct::class, LifecycleProduct::CRON_NAME, DAY_TIMESTAMP, [
            'state'         => CronTask::STATE_WAITING,
            'logs_lifetime' => 30,
        ]);

        $migration->executeMigration();

        return true;
    }

    public function uninstall(): bool
    {
        global $DB;

        CronTask::unregister('dockerinventory');
        ProfileRight::deleteProfileRights([Service::RIGHTNAME]);
        $this->deleteCoreRowsOfPluginItemtypes();
        Settings::delete();

        foreach (self::TABLES as $table) {
            $DB->dropTable($table, true);
        }

        return true;
    }

    /**
     * @return list<string> Tables créées par cet appel.
     */
    private function createTables(): array
    {
        global $DB;

        $charset = DBConnection::getDefaultCharset();
        $collation = DBConnection::getDefaultCollation();
        $sign = DBConnection::getDefaultPrimaryKeySignOption();
        $options = "ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC";

        $definitions = [
            'glpi_plugin_dockerinventory_connections' => "
                `id` int {$sign} NOT NULL AUTO_INCREMENT,
                `name` varchar(255) NOT NULL DEFAULT '',
                `entities_id` int {$sign} NOT NULL DEFAULT '0',
                `is_recursive` tinyint NOT NULL DEFAULT '0',
                `is_active` tinyint NOT NULL DEFAULT '1',
                `type` varchar(32) NOT NULL DEFAULT 'portainer',
                `url` varchar(255) NOT NULL DEFAULT '',
                `username` varchar(255) NOT NULL DEFAULT '',
                `api_key` text,
                `registry_host` varchar(255) NOT NULL DEFAULT '',
                `allow_http` tinyint NOT NULL DEFAULT '0',
                `applianceenvironments_id` int {$sign} NOT NULL DEFAULT '0',
                `ticket_enabled` tinyint NOT NULL DEFAULT '0',
                `ticket_threshold` varchar(16) NOT NULL DEFAULT 'major',
                `ticket_itilcategories_id` int {$sign} NOT NULL DEFAULT '0',
                `ticket_groups_id` int {$sign} NOT NULL DEFAULT '0',
                `ticket_tickettemplates_id` int {$sign} NOT NULL DEFAULT '0',
                `ticket_eol_enabled` tinyint NOT NULL DEFAULT '0',
                `ticket_eol_days` int NOT NULL DEFAULT '90',
                `last_import_date` timestamp NULL DEFAULT NULL,
                `last_import_status` tinyint NOT NULL DEFAULT '0',
                `last_import_report` text,
                `comment` text,
                `date_mod` timestamp NULL DEFAULT NULL,
                `date_creation` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `name` (`name`),
                KEY `entities_id` (`entities_id`),
                KEY `is_recursive` (`is_recursive`),
                KEY `is_active` (`is_active`),
                KEY `type` (`type`),
                KEY `applianceenvironments_id` (`applianceenvironments_id`)",
            'glpi_plugin_dockerinventory_stacks' => "
                `id` int {$sign} NOT NULL AUTO_INCREMENT,
                `plugin_dockerinventory_connections_id` int {$sign} NOT NULL DEFAULT '0',
                `endpoint_uid` int NOT NULL DEFAULT '0',
                `stack_uid` int NOT NULL DEFAULT '0',
                `kind` varchar(16) NOT NULL DEFAULT 'swarm',
                `compose_project` varchar(255) NOT NULL DEFAULT '',
                `name` varchar(255) NOT NULL DEFAULT '',
                `appliances_id` int {$sign} NOT NULL DEFAULT '0',
                `is_retired` tinyint NOT NULL DEFAULT '0',
                `last_seen` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                `date_creation` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity` (`plugin_dockerinventory_connections_id`, `stack_uid`, `endpoint_uid`,
                    `compose_project`),
                KEY `appliances_id` (`appliances_id`)",
            'glpi_plugin_dockerinventory_services' => "
                `id` int {$sign} NOT NULL AUTO_INCREMENT,
                `name` varchar(255) NOT NULL DEFAULT '',
                `entities_id` int {$sign} NOT NULL DEFAULT '0',
                `is_recursive` tinyint NOT NULL DEFAULT '0',
                `plugin_dockerinventory_connections_id` int {$sign} NOT NULL DEFAULT '0',
                `endpoint_uid` int NOT NULL DEFAULT '0',
                `endpoint_name` varchar(255) NOT NULL DEFAULT '',
                `service_uid` varchar(255) NOT NULL DEFAULT '',
                `kind` varchar(16) NOT NULL DEFAULT 'swarm',
                `stack_name` varchar(255) NOT NULL DEFAULT '',
                `plugin_dockerinventory_stacks_id` int {$sign} NOT NULL DEFAULT '0',
                `appliances_id` int {$sign} NOT NULL DEFAULT '0',
                `image` varchar(255) NOT NULL DEFAULT '',
                `tag` varchar(255) NOT NULL DEFAULT '',
                `digest` varchar(255) NOT NULL DEFAULT '',
                `mode` varchar(32) NOT NULL DEFAULT '',
                `replicas` int DEFAULT NULL,
                `running_replicas` int NOT NULL DEFAULT '0',
                `ports` text,
                `networks` text,
                `secrets` text,
                `volumes` text,
                `state` varchar(64) NOT NULL DEFAULT '',
                `restart_policy` varchar(64) NOT NULL DEFAULT '',
                `container_uids` text,
                `container_names` text,
                `is_retired` tinyint NOT NULL DEFAULT '0',
                `date_retired` timestamp NULL DEFAULT NULL,
                `last_seen` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                `date_creation` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity` (`plugin_dockerinventory_connections_id`, `service_uid`),
                KEY `name` (`name`),
                KEY `entities_id` (`entities_id`),
                KEY `is_recursive` (`is_recursive`),
                KEY `plugin_dockerinventory_stacks_id` (`plugin_dockerinventory_stacks_id`),
                KEY `appliances_id` (`appliances_id`),
                KEY `kind` (`kind`),
                KEY `is_retired` (`is_retired`)",
            'glpi_plugin_dockerinventory_nodes' => "
                `id` int {$sign} NOT NULL AUTO_INCREMENT,
                `plugin_dockerinventory_connections_id` int {$sign} NOT NULL DEFAULT '0',
                `endpoint_uid` int NOT NULL DEFAULT '0',
                `node_uid` varchar(64) NOT NULL DEFAULT '',
                `hostname` varchar(255) NOT NULL DEFAULT '',
                `role` varchar(32) NOT NULL DEFAULT '',
                `availability` varchar(32) NOT NULL DEFAULT '',
                `state` varchar(32) NOT NULL DEFAULT '',
                `engine_version` varchar(64) NOT NULL DEFAULT '',
                `address` varchar(255) NOT NULL DEFAULT '',
                `computers_id` int {$sign} NOT NULL DEFAULT '0',
                `is_retired` tinyint NOT NULL DEFAULT '0',
                `last_seen` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                `date_creation` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity` (`plugin_dockerinventory_connections_id`, `node_uid`),
                KEY `computers_id` (`computers_id`)",
            'glpi_plugin_dockerinventory_services_nodes' => "
                `id` int {$sign} NOT NULL AUTO_INCREMENT,
                `plugin_dockerinventory_services_id` int {$sign} NOT NULL DEFAULT '0',
                `plugin_dockerinventory_nodes_id` int {$sign} NOT NULL DEFAULT '0',
                `running_tasks` int NOT NULL DEFAULT '0',
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity` (`plugin_dockerinventory_services_id`, `plugin_dockerinventory_nodes_id`),
                KEY `plugin_dockerinventory_nodes_id` (`plugin_dockerinventory_nodes_id`)",
            // Une ligne par service suivi (container_uid vide) ou par conteneur sans service
            // (plugin_dockerinventory_services_id = 0), pour chaque connexion WUD ; repli sans WUD
            // (source « registry ») : une ligne par service, connexion Harbor ou 0 (Docker Hub,
            // registre inconnu).
            'glpi_plugin_dockerinventory_imageversions' => "
                `id` int {$sign} NOT NULL AUTO_INCREMENT,
                `entities_id` int {$sign} NOT NULL DEFAULT '0',
                `is_recursive` tinyint NOT NULL DEFAULT '0',
                `plugin_dockerinventory_connections_id` int {$sign} NOT NULL DEFAULT '0',
                `plugin_dockerinventory_services_id` int {$sign} NOT NULL DEFAULT '0',
                `container_uid` varchar(128) NOT NULL DEFAULT '',
                `containers` text,
                `container_count` int NOT NULL DEFAULT '0',
                `image` varchar(255) NOT NULL DEFAULT '',
                `registry_name` varchar(255) NOT NULL DEFAULT '',
                `registry_url` varchar(255) NOT NULL DEFAULT '',
                `deployed_tag` varchar(255) NOT NULL DEFAULT '',
                `deployed_digest` varchar(255) NOT NULL DEFAULT '',
                `latest_tag` varchar(255) NOT NULL DEFAULT '',
                `latest_digest` varchar(255) NOT NULL DEFAULT '',
                `gap` varchar(16) NOT NULL DEFAULT '',
                `update_available` tinyint NOT NULL DEFAULT '0',
                `is_out_of_sync` tinyint NOT NULL DEFAULT '0',
                `deployed_versions` text,
                `match_method` varchar(32) NOT NULL DEFAULT '',
                `source` varchar(16) NOT NULL DEFAULT 'wud',
                `error` varchar(255) NOT NULL DEFAULT '',
                `date_checked` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                `date_creation` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity` (`plugin_dockerinventory_connections_id`, `plugin_dockerinventory_services_id`,
                    `container_uid`),
                KEY `entities_id` (`entities_id`),
                KEY `is_recursive` (`is_recursive`),
                KEY `plugin_dockerinventory_services_id` (`plugin_dockerinventory_services_id`),
                KEY `gap` (`gap`)",
            // Cache des tags de Docker Hub (une lecture par image et par jour au plus).
            'glpi_plugin_dockerinventory_registrytags' => "
                `id` int {$sign} NOT NULL AUTO_INCREMENT,
                `registry` varchar(16) NOT NULL DEFAULT '',
                `repository` varchar(255) NOT NULL DEFAULT '',
                `tags` mediumtext,
                `error` varchar(64) NOT NULL DEFAULT '',
                `date_checked` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity` (`registry`, `repository`),
                KEY `date_checked` (`date_checked`)",
            // Ticket de mise à jour suivi pour chaque service, et la situation qu'il décrit
            // (dernier ticket ou suivi) : un seul ticket par service, suivi quand elle change.
            'glpi_plugin_dockerinventory_tickets' => "
                `id` int {$sign} NOT NULL AUTO_INCREMENT,
                `plugin_dockerinventory_services_id` int {$sign} NOT NULL DEFAULT '0',
                `tickets_id` int {$sign} NOT NULL DEFAULT '0',
                `gap` varchar(16) NOT NULL DEFAULT '',
                `deployed_tag` varchar(255) NOT NULL DEFAULT '',
                `latest_tag` varchar(255) NOT NULL DEFAULT '',
                `date_mod` timestamp NULL DEFAULT NULL,
                `date_creation` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity` (`plugin_dockerinventory_services_id`),
                KEY `tickets_id` (`tickets_id`)",
            // Fin de support (#26) : correspondance explicite image => produit de la source.
            'glpi_plugin_dockerinventory_lifecycleproducts' => "
                `id` int {$sign} NOT NULL AUTO_INCREMENT,
                `image` varchar(255) NOT NULL DEFAULT '',
                `product` varchar(64) NOT NULL DEFAULT '',
                `is_default` tinyint NOT NULL DEFAULT '0',
                `date_mod` timestamp NULL DEFAULT NULL,
                `date_creation` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `image` (`image`),
                KEY `product` (`product`)",
            // Cache des cycles de vie d'un produit (une lecture par jour au plus) ; date_success :
            // dernière lecture réussie (données gardées si une lecture échoue ensuite).
            'glpi_plugin_dockerinventory_lifecycles' => "
                `id` int {$sign} NOT NULL AUTO_INCREMENT,
                `product` varchar(64) NOT NULL DEFAULT '',
                `cycles` mediumtext,
                `error` varchar(64) NOT NULL DEFAULT '',
                `date_checked` timestamp NULL DEFAULT NULL,
                `date_success` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `product` (`product`)",
            // Ticket de fin de support suivi pour chaque service, et la branche qu'il décrit.
            'glpi_plugin_dockerinventory_eoltickets' => "
                `id` int {$sign} NOT NULL AUTO_INCREMENT,
                `plugin_dockerinventory_services_id` int {$sign} NOT NULL DEFAULT '0',
                `tickets_id` int {$sign} NOT NULL DEFAULT '0',
                `product` varchar(64) NOT NULL DEFAULT '',
                `cycle` varchar(64) NOT NULL DEFAULT '',
                `eol` varchar(16) NOT NULL DEFAULT '',
                `date_mod` timestamp NULL DEFAULT NULL,
                `date_creation` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity` (`plugin_dockerinventory_services_id`),
                KEY `tickets_id` (`tickets_id`)",
        ];

        $created = [];
        foreach ($definitions as $table => $columns) {
            if (!$DB->tableExists($table)) {
                $DB->doQuery("CREATE TABLE `{$table}` ({$columns}) {$options}");
                $created[] = $table;
            }
        }

        return $created;
    }

    /**
     * Mise à jour d'une installation existante (0.1.0 : connexions Portainer seulement ; 0.2.0 :
     * sans l'hôte du registre des connexions Harbor ni les réglages des tickets ; le cache des
     * registres et le suivi des tickets sont des tables créées plus haut).
     */
    private function migrate(Migration $migration): void
    {
        $table = Connection::getTable();
        $migration->addField($table, 'type', "varchar(32) NOT NULL DEFAULT 'portainer'", ['after' => 'is_active']);
        $migration->addField($table, 'username', "varchar(255) NOT NULL DEFAULT ''", ['after' => 'url']);
        $migration->addField($table, 'registry_host', "varchar(255) NOT NULL DEFAULT ''", ['after' => 'api_key']);
        // Tickets de mise à jour (désactivés par défaut).
        $sign = DBConnection::getDefaultPrimaryKeySignOption();
        $ticketFields = [
            'ticket_enabled'            => "tinyint NOT NULL DEFAULT '0'",
            'ticket_threshold'          => "varchar(16) NOT NULL DEFAULT 'major'",
            'ticket_itilcategories_id'  => "int {$sign} NOT NULL DEFAULT '0'",
            'ticket_groups_id'          => "int {$sign} NOT NULL DEFAULT '0'",
            'ticket_tickettemplates_id' => "int {$sign} NOT NULL DEFAULT '0'",
            // 0.4.0 => tickets de fin de support (#26), désactivés par défaut.
            'ticket_eol_enabled'        => "tinyint NOT NULL DEFAULT '0'",
            'ticket_eol_days'           => "int NOT NULL DEFAULT '90'",
        ];
        $after = 'applianceenvironments_id';
        foreach ($ticketFields as $field => $definition) {
            $migration->addField($table, $field, $definition, ['after' => $after]);
            $after = $field;
        }
        $migration->addKey($table, 'type');
        $migration->migrationOneTable($table);

        $this->migrateStandaloneHosts($migration);
    }

    /**
     * 0.3.0 => hôtes Docker autonomes et stacks Compose (#25) : nature des stacks et des services,
     * projet Compose sans stack Portainer, identifiants de service plus longs, état, politique de
     * redémarrage, volumes et conteneurs des services. Les lignes existantes sont des stacks et
     * services Swarm (valeur par défaut de `kind`).
     */
    private function migrateStandaloneHosts(Migration $migration): void
    {
        global $DB;

        $stacks = 'glpi_plugin_dockerinventory_stacks';
        if (!$DB->fieldExists($stacks, 'compose_project', false)) {
            $migration->addField($stacks, 'kind', "varchar(16) NOT NULL DEFAULT 'swarm'", ['after' => 'stack_uid']);
            $migration->addField($stacks, 'compose_project', "varchar(255) NOT NULL DEFAULT ''", ['after' => 'kind']);
            // Un projet Compose déployé hors Portainer n'a pas d'identifiant de stack (0) : la clé
            // d'unicité comprend l'environnement et le projet.
            $migration->dropKey($stacks, 'unicity');
            $migration->migrationOneTable($stacks);
            $migration->addKey(
                $stacks,
                ['plugin_dockerinventory_connections_id', 'stack_uid', 'endpoint_uid', 'compose_project'],
                'unicity',
                'UNIQUE'
            );
            $migration->migrationOneTable($stacks);
        }

        $services = Service::getTable();
        $migration->changeField($services, 'service_uid', 'service_uid', "varchar(255) NOT NULL DEFAULT ''");
        $migration->addField($services, 'kind', "varchar(16) NOT NULL DEFAULT 'swarm'", ['after' => 'service_uid']);
        $after = 'secrets';
        foreach (
            [
                'volumes'         => 'text',
                'state'           => "varchar(64) NOT NULL DEFAULT ''",
                'restart_policy'  => "varchar(64) NOT NULL DEFAULT ''",
                'container_uids'  => 'text',
                'container_names' => 'text',
            ] as $field => $definition
        ) {
            $migration->addField($services, $field, $definition, ['after' => $after]);
            $after = $field;
        }
        $migration->addKey($services, 'kind');
        $migration->migrationOneTable($services);
    }

    /**
     * Premier accès : lecture des services pour les profils qui administrent déjà la configuration
     * de GLPI (super-administrateur). Les autres profils se règlent dans l'onglet du plugin.
     */
    private function grantReadToAdministrators(): void
    {
        global $DB;

        foreach ($DB->request(['FROM' => 'glpi_profilerights', 'WHERE' => ['name' => 'config']]) as $row) {
            if (((int) $row['rights'] & UPDATE) === UPDATE) {
                ProfileRight::updateProfileRights((int) $row['profiles_id'], [Service::RIGHTNAME => READ]);
            }
        }
    }

    /**
     * @param class-string<\CommonDBTM> $itemtype
     * @param list<int> $columns
     */
    private function installDisplayPreferences(string $itemtype, array $columns): void
    {
        global $DB;

        $hasInterface = $DB->fieldExists(DisplayPreference::getTable(), 'interface');
        foreach ($columns as $rank => $num) {
            $DB->insert(DisplayPreference::getTable(), [
                'itemtype' => $itemtype,
                'num'      => $num,
                'rank'     => $rank + 1,
                'users_id' => 0,
            ] + ($hasInterface ? ['interface' => 'central'] : []));
        }
    }

    /**
     * Lignes des tables du cœur qui désignent un type d'objet du plugin (préférences d'affichage,
     * recherches enregistrées, historique...) : toutes les colonnes itemtype/itemtype_link.
     */
    private function deleteCoreRowsOfPluginItemtypes(): void
    {
        global $DB;

        $like = addcslashes('GlpiPlugin\\Dockerinventory\\', '\\') . '%';
        $columns = $DB->request([
            'SELECT' => ['TABLE_NAME', 'COLUMN_NAME'],
            'FROM'   => 'information_schema.COLUMNS',
            'WHERE'  => [
                'TABLE_SCHEMA' => $DB->dbdefault,
                'COLUMN_NAME'  => ['itemtype', 'itemtype_link'],
                ['NOT' => ['TABLE_NAME' => ['LIKE', 'glpi\_plugin\_%']]],
            ],
        ]);
        foreach ($columns as $column) {
            $DB->delete($column['TABLE_NAME'], [$column['COLUMN_NAME'] => ['LIKE', $like]]);
        }
    }
}

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
use GlpiPlugin\Dockerinventory\ExploitSignal;
use GlpiPlugin\Dockerinventory\Harbor\HarborSettings;
use GlpiPlugin\Dockerinventory\HardeningException;
use GlpiPlugin\Dockerinventory\ImageScan;
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
        'glpi_plugin_dockerinventory_harborprojects',
        'glpi_plugin_dockerinventory_harborartifacts',
        'glpi_plugin_dockerinventory_vulntickets',
        'glpi_plugin_dockerinventory_versionchanges',
        'glpi_plugin_dockerinventory_hardeningexceptions',
        'glpi_plugin_dockerinventory_imagescans',
        'glpi_plugin_dockerinventory_exploitsignals',
    ];

    public const CRON_NAME = 'portainerimport';
    public const CRON_WUD = 'wudimport';
    public const CRON_REGISTRY = 'registrycheck';
    public const CRON_HARBOR = 'harborimport';
    public const CRON_KUBERNETES = 'kubernetesimport';

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
        // 0.12.0 => exceptions aux écarts de configuration (#71) : droit ajouté une seule fois,
        // accordé aux administrateurs de la configuration de GLPI.
        if (countElementsInTable('glpi_profilerights', ['name' => HardeningException::RIGHTNAME]) === 0) {
            ProfileRight::addProfileRights([HardeningException::RIGHTNAME]);
            $this->grantToAdministrators([HardeningException::RIGHTNAME => CREATE | PURGE]);
        }
        // 0.14.0 => analyses d'images importées (#73) : même règle.
        if (countElementsInTable('glpi_profilerights', ['name' => ImageScan::RIGHTNAME]) === 0) {
            ProfileRight::addProfileRights([ImageScan::RIGHTNAME]);
            $this->grantToAdministrators([ImageScan::RIGHTNAME => CREATE | PURGE]);
        }
        $defaults = [ImageVersion::class => self::VERSION_COLUMNS, Connection::class => self::CONNECTION_COLUMNS];
        foreach ($defaults as $itemtype => $columns) {
            if (countElementsInTable(DisplayPreference::getTable(), ['itemtype' => $itemtype]) === 0) {
                $this->installDisplayPreferences($itemtype, $columns);
            }
        }

        $crons = [self::CRON_NAME, self::CRON_WUD, self::CRON_REGISTRY, self::CRON_HARBOR, self::CRON_KUBERNETES];
        foreach ($crons as $cron) {
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
        // Exploitation connue (#84) : téléchargement quotidien désactivé tant que l'administrateur
        // ne l'active pas (état gardé si la tâche existe déjà).
        CronTask::register(ExploitSignal::class, ExploitSignal::CRON_NAME, DAY_TIMESTAMP, [
            'state'         => CronTask::STATE_DISABLE,
            'logs_lifetime' => 30,
        ]);

        // Rappel avant l'expiration d'une exception (#99) : une fois par jour, notification à
        // l'auteur de l'exception (modèle et notification créés une seule fois).
        CronTask::register(HardeningException::class, HardeningException::CRON_NAME, DAY_TIMESTAMP, [
            'state'         => CronTask::STATE_WAITING,
            'logs_lifetime' => 30,
        ]);
        ExpiryNotification::install();

        $migration->executeMigration();

        return true;
    }

    public function uninstall(): bool
    {
        global $DB;

        CronTask::unregister('dockerinventory');
        ProfileRight::deleteProfileRights([Service::RIGHTNAME, HardeningException::RIGHTNAME, ImageScan::RIGHTNAME]);
        // Avant le nettoyage général : cibles, traductions et liens des notifications avec elles.
        ExpiryNotification::uninstall();
        $this->deleteCoreRowsOfPluginItemtypes();
        Settings::delete();
        HarborSettings::delete();
        ExploitSignal::uninstall();

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
                `ca_certificate` text,
                `k8s_excluded_namespaces` text,
                `allow_http` tinyint NOT NULL DEFAULT '0',
                `applianceenvironments_id` int {$sign} NOT NULL DEFAULT '0',
                `ticket_enabled` tinyint NOT NULL DEFAULT '0',
                `ticket_threshold` varchar(16) NOT NULL DEFAULT 'major',
                `ticket_itilcategories_id` int {$sign} NOT NULL DEFAULT '0',
                `ticket_groups_id` int {$sign} NOT NULL DEFAULT '0',
                `ticket_tickettemplates_id` int {$sign} NOT NULL DEFAULT '0',
                `ticket_eol_enabled` tinyint NOT NULL DEFAULT '0',
                `ticket_eol_days` int NOT NULL DEFAULT '90',
                `ticket_vuln_enabled` tinyint NOT NULL DEFAULT '0',
                `ticket_vuln_severity` varchar(16) NOT NULL DEFAULT 'critical',
                `last_import_date` timestamp NULL DEFAULT NULL,
                `last_import_status` tinyint NOT NULL DEFAULT '0',
                `last_import_report` text,
                `last_success_date` timestamp NULL DEFAULT NULL,
                `last_scan_date` timestamp NULL DEFAULT NULL,
                `last_scan_status` tinyint NOT NULL DEFAULT '0',
                `last_scan_report` text,
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
                `routes` text,
                `hardening` text,
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
            // Harbor (#9) : projets visibles par le compte robot de chaque connexion Harbor.
            'glpi_plugin_dockerinventory_harborprojects' => "
                `id` int {$sign} NOT NULL AUTO_INCREMENT,
                `plugin_dockerinventory_connections_id` int {$sign} NOT NULL DEFAULT '0',
                `project_uid` int NOT NULL DEFAULT '0',
                `name` varchar(255) NOT NULL DEFAULT '',
                `is_public` tinyint NOT NULL DEFAULT '0',
                `repo_count` int NOT NULL DEFAULT '0',
                `is_retired` tinyint NOT NULL DEFAULT '0',
                `last_seen` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                `date_creation` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity` (`plugin_dockerinventory_connections_id`, `name`)",
            // Harbor (#9) : un artefact par dépôt et par empreinte, tags et résumé de son analyse.
            'glpi_plugin_dockerinventory_harborartifacts' => "
                `id` int {$sign} NOT NULL AUTO_INCREMENT,
                `entities_id` int {$sign} NOT NULL DEFAULT '0',
                `is_recursive` tinyint NOT NULL DEFAULT '1',
                `plugin_dockerinventory_connections_id` int {$sign} NOT NULL DEFAULT '0',
                `project` varchar(255) NOT NULL DEFAULT '',
                `repository` varchar(255) NOT NULL DEFAULT '',
                `digest` varchar(255) NOT NULL DEFAULT '',
                `child_digests` text,
                `tags` text,
                `artifact_type` varchar(32) NOT NULL DEFAULT '',
                `size` bigint NOT NULL DEFAULT '0',
                `push_time` timestamp NULL DEFAULT NULL,
                `scan_status` varchar(16) NOT NULL DEFAULT 'not_scanned',
                `severity` varchar(16) NOT NULL DEFAULT '',
                `scanner` varchar(255) NOT NULL DEFAULT '',
                `scan_time` timestamp NULL DEFAULT NULL,
                `critical` int NOT NULL DEFAULT '0',
                `high` int NOT NULL DEFAULT '0',
                `medium` int NOT NULL DEFAULT '0',
                `low` int NOT NULL DEFAULT '0',
                `other` int NOT NULL DEFAULT '0',
                `total` int NOT NULL DEFAULT '0',
                `fixable` int NOT NULL DEFAULT '0',
                `is_retired` tinyint NOT NULL DEFAULT '0',
                `date_retired` timestamp NULL DEFAULT NULL,
                `last_seen` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                `date_creation` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity` (`plugin_dockerinventory_connections_id`, `repository`, `digest`),
                KEY `entities_id` (`entities_id`),
                KEY `is_recursive` (`is_recursive`),
                KEY `digest` (`digest`),
                KEY `severity` (`severity`),
                KEY `is_retired` (`is_retired`)",
            // Ticket de vulnérabilité suivi pour chaque service, et l'image qu'il décrit.
            'glpi_plugin_dockerinventory_vulntickets' => "
                `id` int {$sign} NOT NULL AUTO_INCREMENT,
                `plugin_dockerinventory_services_id` int {$sign} NOT NULL DEFAULT '0',
                `tickets_id` int {$sign} NOT NULL DEFAULT '0',
                `digest` varchar(255) NOT NULL DEFAULT '',
                `critical` int NOT NULL DEFAULT '0',
                `high` int NOT NULL DEFAULT '0',
                `date_mod` timestamp NULL DEFAULT NULL,
                `date_creation` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity` (`plugin_dockerinventory_services_id`),
                KEY `tickets_id` (`tickets_id`)",
            // Changements de version observés par l'import Portainer (#10) : tag, empreinte ou image
            // d'un service qui changent d'un import à l'autre. Journal en lecture seule, jamais purgé
            // automatiquement (preuve de gestion des changements, A.8.32).
            'glpi_plugin_dockerinventory_versionchanges' => "
                `id` int {$sign} NOT NULL AUTO_INCREMENT,
                `entities_id` int {$sign} NOT NULL DEFAULT '0',
                `is_recursive` tinyint NOT NULL DEFAULT '0',
                `plugin_dockerinventory_connections_id` int {$sign} NOT NULL DEFAULT '0',
                `plugin_dockerinventory_services_id` int {$sign} NOT NULL DEFAULT '0',
                `service_name` varchar(255) NOT NULL DEFAULT '',
                `stack_name` varchar(255) NOT NULL DEFAULT '',
                `kind` varchar(16) NOT NULL DEFAULT '',
                `old_image` varchar(255) NOT NULL DEFAULT '',
                `new_image` varchar(255) NOT NULL DEFAULT '',
                `old_tag` varchar(255) NOT NULL DEFAULT '',
                `new_tag` varchar(255) NOT NULL DEFAULT '',
                `old_digest` varchar(255) NOT NULL DEFAULT '',
                `new_digest` varchar(255) NOT NULL DEFAULT '',
                `date_change` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `entities_id` (`entities_id`),
                KEY `is_recursive` (`is_recursive`),
                KEY `plugin_dockerinventory_services_id` (`plugin_dockerinventory_services_id`),
                KEY `date_change` (`date_change`)",
            // Exceptions justifiées et datées aux écarts de configuration (#71) : une par service et par écart.
            'glpi_plugin_dockerinventory_hardeningexceptions' => "
                `id` int {$sign} NOT NULL AUTO_INCREMENT,
                `entities_id` int {$sign} NOT NULL DEFAULT '0',
                `plugin_dockerinventory_services_id` int {$sign} NOT NULL DEFAULT '0',
                `code` varchar(32) NOT NULL DEFAULT '',
                `justification` text,
                `date_end` date DEFAULT NULL,
                `reminded_date_end` date DEFAULT NULL,
                `users_id` int {$sign} NOT NULL DEFAULT '0',
                `date_mod` timestamp NULL DEFAULT NULL,
                `date_creation` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity` (`plugin_dockerinventory_services_id`, `code`),
                KEY `entities_id` (`entities_id`),
                KEY `users_id` (`users_id`),
                KEY `date_end` (`date_end`)",
            // Analyses d'images importées (#73) : une par entité, image, tag et empreinte.
            'glpi_plugin_dockerinventory_imagescans' => "
                `id` int {$sign} NOT NULL AUTO_INCREMENT,
                `name` varchar(255) NOT NULL DEFAULT '',
                `entities_id` int {$sign} NOT NULL DEFAULT '0',
                `is_recursive` tinyint NOT NULL DEFAULT '0',
                `image` varchar(255) NOT NULL DEFAULT '',
                `image_key` varchar(255) NOT NULL DEFAULT '',
                `tag` varchar(128) NOT NULL DEFAULT '',
                `digest` varchar(80) NOT NULL DEFAULT '',
                `scanner` varchar(16) NOT NULL DEFAULT '',
                `tool` varchar(64) NOT NULL DEFAULT '',
                `tool_version` varchar(32) NOT NULL DEFAULT '',
                `scan_date` timestamp NULL DEFAULT NULL,
                `critical` int NOT NULL DEFAULT '0',
                `high` int NOT NULL DEFAULT '0',
                `medium` int NOT NULL DEFAULT '0',
                `low` int NOT NULL DEFAULT '0',
                `other` int NOT NULL DEFAULT '0',
                `fixable` int NOT NULL DEFAULT '0',
                `total` int NOT NULL DEFAULT '0',
                `findings` mediumtext,
                `is_truncated` tinyint NOT NULL DEFAULT '0',
                `filename` varchar(255) NOT NULL DEFAULT '',
                `users_id` int {$sign} NOT NULL DEFAULT '0',
                `date_mod` timestamp NULL DEFAULT NULL,
                `date_creation` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity` (`entities_id`, `image_key`, `tag`, `digest`),
                KEY `is_recursive` (`is_recursive`),
                KEY `image_key` (`image_key`),
                KEY `users_id` (`users_id`),
                KEY `scan_date` (`scan_date`)",
            // 0.15.0 => exploitation connue (#84) : signaux CISA KEV et EPSS des CVE des analyses
            // importées, refaits depuis les fichiers en cache (ExploitSignal::rebuild()).
            'glpi_plugin_dockerinventory_exploitsignals' => "
                `id` int {$sign} NOT NULL AUTO_INCREMENT,
                `cve_id` varchar(32) NOT NULL DEFAULT '',
                `is_kev` tinyint NOT NULL DEFAULT '0',
                `kev_date_added` date NULL DEFAULT NULL,
                `kev_due_date` date NULL DEFAULT NULL,
                `kev_ransomware` tinyint NOT NULL DEFAULT '0',
                `kev_name` varchar(255) NOT NULL DEFAULT '',
                `epss` float NULL DEFAULT NULL,
                `epss_percentile` float NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `cve_id` (`cve_id`),
                KEY `is_kev` (`is_kev`)",
        ];

        $created = [];
        foreach ($definitions as $table => $columns) {
            if (!$DB->tableExists($table, false)) {
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
        global $DB;

        $table = Connection::getTable();
        $migration->addField($table, 'type', "varchar(32) NOT NULL DEFAULT 'portainer'", ['after' => 'is_active']);
        $migration->addField($table, 'username', "varchar(255) NOT NULL DEFAULT ''", ['after' => 'url']);
        $migration->addField($table, 'registry_host', "varchar(255) NOT NULL DEFAULT ''", ['after' => 'api_key']);
        // 0.7.0 => connexions Kubernetes (#31) : certificat d'autorité, namespaces ignorés.
        $migration->addField($table, 'ca_certificate', 'text', ['after' => 'registry_host']);
        $migration->addField($table, 'k8s_excluded_namespaces', 'text', ['after' => 'ca_certificate']);
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
            // 0.5.0 => tickets de vulnérabilité (#9), désactivés par défaut.
            'ticket_vuln_enabled'       => "tinyint NOT NULL DEFAULT '0'",
            'ticket_vuln_severity'      => "varchar(16) NOT NULL DEFAULT 'critical'",
        ];
        $after = 'applianceenvironments_id';
        foreach ($ticketFields as $field => $definition) {
            $migration->addField($table, $field, $definition, ['after' => $after]);
            $after = $field;
        }
        // 0.5.0 => dernière lecture de l'API de Harbor (#9), à côté de celle des registres.
        $after = 'last_import_report';
        foreach (
            [
                'last_scan_date'   => 'timestamp NULL DEFAULT NULL',
                'last_scan_status' => "tinyint NOT NULL DEFAULT '0'",
                'last_scan_report' => 'text',
            ] as $field => $definition
        ) {
            $migration->addField($table, $field, $definition, ['after' => $after]);
            $after = $field;
        }
        // 0.7.0 => date du dernier import réussi (#10), preuve de fraîcheur de l'inventaire.
        $hadSuccessDate = $DB->fieldExists($table, 'last_success_date', false);
        $migration->addField($table, 'last_success_date', 'timestamp NULL DEFAULT NULL', [
            'after' => 'last_import_report',
        ]);
        $migration->addKey($table, 'type');
        $migration->migrationOneTable($table);
        if (!$hadSuccessDate) {
            $this->initSuccessDates();
        }

        $this->migrateStandaloneHosts($migration);

        // 0.16.0 => rappel avant l'expiration d'une exception (#99) : date de fin déjà rappelée.
        $exceptions = HardeningException::getTable();
        $migration->addField($exceptions, 'reminded_date_end', 'date DEFAULT NULL', ['after' => 'date_end']);
        $migration->migrationOneTable($exceptions);
    }

    /**
     * Connexions existantes : leur dernier import, s'il a réussi (même en partie), est le dernier réussi connu.
     */
    private function initSuccessDates(): void
    {
        global $DB;

        $table = Connection::getTable();
        foreach (
            $DB->request([
                'SELECT' => ['id', 'last_import_date'],
                'FROM'   => $table,
                'WHERE'  => [
                    'NOT' => ['last_import_date' => null],
                    'last_import_status' => Connection::SUCCESS_STATUSES,
                ],
            ]) as $row
        ) {
            $DB->update($table, ['last_success_date' => $row['last_import_date']], ['id' => (int) $row['id']]);
        }
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
                // Routes Traefik, ingress et services Kubernetes exposés (JSON, #72).
                'routes'          => 'text',
                // Configuration à risque lue à l'import (JSON, #71) ; NULL tant que rien n'est analysé.
                'hardening'       => 'text',
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
        $this->grantToAdministrators([Service::RIGHTNAME => READ]);
    }

    /**
     * @param array<string, int> $rights Droit => valeur, pour les profils qui modifient la configuration.
     */
    private function grantToAdministrators(array $rights): void
    {
        global $DB;

        foreach ($DB->request(['FROM' => 'glpi_profilerights', 'WHERE' => ['name' => 'config']]) as $row) {
            if (((int) $row['rights'] & UPDATE) === UPDATE) {
                ProfileRight::updateProfileRights((int) $row['profiles_id'], $rights);
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

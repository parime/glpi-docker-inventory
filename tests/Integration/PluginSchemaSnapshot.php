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

use RuntimeException;

/**
 * Copie de sauvegarde des tables du plugin pour les tests qui changent le schéma (#113).
 *
 * take() copie chaque table `glpi_plugin_dockerinventory_*` (structure et lignes) dans une table
 * `zz_ditest_*`, ainsi que les tâches automatiques et la configuration du plugin, puis note la liste
 * des tables copiées dans la table témoin `zz_ditestok`, en dernier. restore() remet les tables
 * d'origine telles quelles (RENAME TABLE : colonnes, clés et lignes identiques), supprime celles que
 * le test a créées, rend les tâches et la configuration, puis supprime le témoin.
 *
 * Processus interrompu (tué, erreur fatale) : le take() suivant reprend avant toute nouvelle copie.
 * Témoin présent : la remise en état est terminée (elle peut reprendre là où elle s'est arrêtée).
 * Témoin absent : la copie n'était pas finie, donc le test n'avait encore rien changé, et les copies
 * partielles sont seulement supprimées.
 *
 * Réservé aux tests : le SQL brut (CREATE TABLE … LIKE, RENAME TABLE) n'a pas d'équivalent dans
 * l'API de GLPI.
 */
final class PluginSchemaSnapshot
{
    private const PREFIX = 'glpi_plugin_dockerinventory_';
    private const BACKUP_PREFIX = 'zz_ditest_';
    private const MARKER = 'zz_ditestok';
    private const CRON_ITEMTYPE = 'GlpiPlugin\\\\Dockerinventory\\\\%';
    private const CONFIG_CONTEXT = 'plugin:dockerinventory';

    /** @var list<array<string, mixed>> */
    private array $crontasks = [];

    /** @var list<array<string, mixed>> */
    private array $configs = [];

    private bool $taken = false;

    public function take(): void
    {
        global $DB;

        // Copies laissées par un test interrompu : traitées d'abord, jamais écrasées.
        self::restoreTables();

        $tables = self::pluginTables();
        foreach ($tables as $table) {
            $backup = self::backupName($table);
            $DB->doQuery('CREATE TABLE ' . $DB->quoteName($backup) . ' LIKE ' . $DB->quoteName($table));
            $DB->doQuery('INSERT INTO ' . $DB->quoteName($backup) . ' SELECT * FROM ' . $DB->quoteName($table));
        }
        $this->crontasks = self::rows('glpi_crontasks', ['itemtype' => ['LIKE', self::CRON_ITEMTYPE]]);
        $this->configs = self::rows('glpi_configs', ['context' => self::CONFIG_CONTEXT]);
        $DB->doQuery('CREATE TABLE ' . $DB->quoteName(self::MARKER) . ' (`name` varchar(64) NOT NULL PRIMARY KEY)');
        foreach ($tables as $table) {
            $DB->insert(self::MARKER, ['name' => $table]);
        }
        $this->taken = true;
    }

    public function restore(): void
    {
        global $DB;

        if (!$this->taken) {
            self::restoreTables();

            return;
        }
        self::restoreTables();

        $DB->delete('glpi_crontasks', ['itemtype' => ['LIKE', self::CRON_ITEMTYPE]]);
        foreach ($this->crontasks as $row) {
            $DB->insert('glpi_crontasks', $row);
        }
        $DB->delete('glpi_configs', ['context' => self::CONFIG_CONTEXT]);
        foreach ($this->configs as $row) {
            $DB->insert('glpi_configs', $row);
        }
        $this->taken = false;
    }

    /**
     * Remet chaque table copiée (liste du témoin) à sa place et supprime les tables du plugin hors de
     * cette liste (créées pendant le test). Sans témoin, supprime seulement les copies partielles.
     * Peut être relancée après une interruption : une table déjà rendue n'a plus de copie.
     */
    private static function restoreTables(): void
    {
        global $DB;

        if (!$DB->tableExists(self::MARKER, false)) {
            foreach (self::backupTables() as $backup) {
                $DB->doQuery('DROP TABLE IF EXISTS ' . $DB->quoteName($backup));
            }

            return;
        }
        $kept = [];
        foreach ($DB->request(['FROM' => self::MARKER]) as $row) {
            $kept[] = (string) $row['name'];
        }
        foreach ($kept as $table) {
            $backup = self::backupName($table);
            if ($DB->tableExists($backup, false)) {
                $DB->doQuery('DROP TABLE IF EXISTS ' . $DB->quoteName($table));
                $DB->doQuery('RENAME TABLE ' . $DB->quoteName($backup) . ' TO ' . $DB->quoteName($table));
            }
        }
        foreach (self::pluginTables() as $table) {
            if (!in_array($table, $kept, true)) {
                $DB->doQuery('DROP TABLE IF EXISTS ' . $DB->quoteName($table));
            }
        }
        if (self::backupTables() !== []) {
            throw new RuntimeException('Copies de sauvegarde des tables du plugin non rendues.');
        }
        $DB->doQuery('DROP TABLE ' . $DB->quoteName(self::MARKER));
    }

    /** @return list<string> */
    private static function pluginTables(): array
    {
        return self::tables('glpi\_plugin\_dockerinventory\_%');
    }

    /** @return list<string> */
    private static function backupTables(): array
    {
        return self::tables('zz\_ditest\_%');
    }

    /** @return list<string> */
    private static function tables(string $pattern): array
    {
        global $DB;

        $tables = [];
        foreach ($DB->listTables($pattern) as $row) {
            $tables[] = (string) $row['TABLE_NAME'];
        }
        sort($tables);

        return $tables;
    }

    private static function backupName(string $table): string
    {
        return self::BACKUP_PREFIX . substr($table, strlen(self::PREFIX));
    }

    /**
     * @param array<string, mixed> $where
     *
     * @return list<array<string, mixed>>
     */
    private static function rows(string $table, array $where): array
    {
        global $DB;

        $rows = [];
        foreach ($DB->request(['FROM' => $table, 'WHERE' => $where]) as $row) {
            $rows[] = $row;
        }

        return $rows;
    }
}

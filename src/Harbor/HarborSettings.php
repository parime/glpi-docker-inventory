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

use Config;
use GlpiPlugin\Dockerinventory\Lifecycle\Settings;

/**
 * Registres maîtrisés, dans la configuration de GLPI (contexte « plugin:dockerinventory ») : hôtes
 * des registres d'où les images déployées doivent venir. Liste vide : les hôtes des connexions
 * Harbor actives.
 */
final class HarborSettings
{
    public const MANAGED_REGISTRIES = 'harbor_managed_registries';

    private static ?string $cache = null;

    /**
     * Hôtes saisis (liste vide : ceux des connexions Harbor).
     *
     * @return list<string>
     */
    public static function configuredHosts(): array
    {
        return HarborLinker::parseHosts(self::text());
    }

    /**
     * Texte enregistré, un hôte par ligne.
     */
    public static function text(): string
    {
        if (self::$cache === null) {
            $values = Config::getConfigurationValues(Settings::CONTEXT, [self::MANAGED_REGISTRIES]);
            self::$cache = (string) ($values[self::MANAGED_REGISTRIES] ?? '');
        }

        return self::$cache;
    }

    /**
     * Enregistre les hôtes valides, un par ligne.
     *
     * @return list<string> Hôtes enregistrés.
     */
    public static function save(string $text): array
    {
        $hosts = HarborLinker::parseHosts($text);
        Config::setConfigurationValues(Settings::CONTEXT, [self::MANAGED_REGISTRIES => implode("\n", $hosts)]);
        self::$cache = null;

        return $hosts;
    }

    /**
     * Désinstallation : réglage retiré de la configuration de GLPI.
     */
    public static function delete(): void
    {
        Config::deleteConfigurationValues(Settings::CONTEXT, [self::MANAGED_REGISTRIES]);
        self::$cache = null;
    }

    public static function reset(): void
    {
        self::$cache = null;
    }
}

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

namespace GlpiPlugin\Dockerinventory\Lifecycle;

use Config;

/**
 * Réglages de la fin de support, dans la configuration de GLPI (contexte « plugin:dockerinventory ») :
 * appel sortant vers la source des cycles de vie (désactivé par défaut) et seuil de « bientôt en fin
 * de support ».
 */
final class Settings
{
    public const CONTEXT = 'plugin:dockerinventory';
    public const ENABLED = 'lifecycle_enabled';
    public const WARNING_DAYS = 'lifecycle_warning_days';

    public const DEFAULT_WARNING_DAYS = 90;
    public const MAX_DAYS = 3650;

    /** @var array{enabled: bool, warning_days: int}|null Valeurs lues pendant la requête en cours. */
    private static ?array $cache = null;

    /**
     * @return array{enabled: bool, warning_days: int}
     */
    public static function get(): array
    {
        if (self::$cache === null) {
            $values = Config::getConfigurationValues(self::CONTEXT, [self::ENABLED, self::WARNING_DAYS]);
            $days = $values[self::WARNING_DAYS] ?? null;
            self::$cache = [
                'enabled'      => (bool) (int) ($values[self::ENABLED] ?? 0),
                'warning_days' => is_numeric($days) ? self::clampDays((int) $days) : self::DEFAULT_WARNING_DAYS,
            ];
        }

        /** @var array{enabled: bool, warning_days: int} */
        return self::$cache;
    }

    public static function enabled(): bool
    {
        return self::get()['enabled'];
    }

    public static function warningDays(): int
    {
        return self::get()['warning_days'];
    }

    public static function save(bool $enabled, int $warningDays): void
    {
        Config::setConfigurationValues(self::CONTEXT, [
            self::ENABLED      => $enabled ? 1 : 0,
            self::WARNING_DAYS => self::clampDays($warningDays),
        ]);
        self::$cache = null;
    }

    /**
     * Désinstallation : réglages retirés de la configuration de GLPI.
     */
    public static function delete(): void
    {
        Config::deleteConfigurationValues(self::CONTEXT, [self::ENABLED, self::WARNING_DAYS]);
        self::$cache = null;
    }

    /**
     * Relire la configuration (tests, changement dans la même requête).
     */
    public static function reset(): void
    {
        self::$cache = null;
    }

    public static function clampDays(int $days): int
    {
        return max(0, min(self::MAX_DAYS, $days));
    }
}

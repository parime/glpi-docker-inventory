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

/**
 * Page de configuration du plugin (Configuration > Plugins > Inventaire Docker, front/config.php) :
 * le seul endroit où le plugin se règle (charte commune, section 9), en onglets. Réservée au droit
 * de configuration de GLPI, comme les connexions et la fin de support.
 */
final class ConfigPage
{
    public const TAB_CONNECTIONS = 'connections';
    public const TAB_REGISTRIES = 'registries';
    public const TAB_LIFECYCLE = 'lifecycle';
    public const TAB_EXPLOITS = 'exploits';

    public const TABS = [self::TAB_CONNECTIONS, self::TAB_REGISTRIES, self::TAB_LIFECYCLE, self::TAB_EXPLOITS];

    /**
     * Adresse de la page, sur un onglet (connexions par défaut, sans paramètre : la recherche GLPI
     * des connexions y ajoute les siens).
     *
     * @param array<string, string> $params
     */
    public static function url(string $tab = self::TAB_CONNECTIONS, array $params = [], bool $full = true): string
    {
        global $CFG_GLPI;

        $url = ($full ? (string) $CFG_GLPI['root_doc'] : '') . '/plugins/dockerinventory/front/config.php';
        if ($tab !== self::TAB_CONNECTIONS) {
            $params = ['tab' => $tab] + $params;
        }

        return $params === [] ? $url : $url . '?' . http_build_query($params);
    }

    /**
     * Onglet demandé, connexions si inconnu.
     */
    public static function tab(string $requested): string
    {
        return in_array($requested, self::TABS, true) ? $requested : self::TAB_CONNECTIONS;
    }

    /**
     * Onglets de la page, pour templates/_section_nav.html.twig.
     *
     * @return list<array{label: string, icon: string, url: string, active: bool}>
     */
    public static function tabs(string $active): array
    {
        $labels = [
            self::TAB_CONNECTIONS => [Connection::getTypeName(2), Connection::getIcon()],
            self::TAB_REGISTRIES  => [__('Registres maîtrisés', 'dockerinventory'), 'ti ti-building-warehouse'],
            self::TAB_LIFECYCLE   => [LifecycleProduct::getTypeName(2), LifecycleProduct::getIcon()],
            self::TAB_EXPLOITS    => [ExploitSignal::getTypeName(), ExploitSignal::getIcon()],
        ];
        $tabs = [];
        foreach ($labels as $tab => [$label, $icon]) {
            $tabs[] = ['label' => $label, 'icon' => $icon, 'url' => self::url($tab), 'active' => $tab === $active];
        }

        return $tabs;
    }
}

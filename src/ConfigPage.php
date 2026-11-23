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
    public const TAB_CERTIFICATES = 'certificates';

    public const TABS = [
        self::TAB_CONNECTIONS,
        self::TAB_REGISTRIES,
        self::TAB_LIFECYCLE,
        self::TAB_EXPLOITS,
        self::TAB_CERTIFICATES,
    ];

    /** Réglage du composant « Version du plugin » (#121) : vérification sur GitHub, active par défaut. */
    public const VERSION_CHECK = 'version_check';

    /** Dépôt GitHub dont la dernière version publiée est lue. */
    public const REPOSITORY = 'parime/glpi-docker-inventory';

    /**
     * Vérification des nouvelles versions activée (désactivable pour une instance hors ligne).
     */
    public static function versionCheck(): bool
    {
        $values = \Config::getConfigurationValues(Lifecycle\Settings::CONTEXT, [self::VERSION_CHECK]);

        return ($values[self::VERSION_CHECK] ?? '1') !== '0';
    }

    public static function saveVersionCheck(bool $enabled): void
    {
        \Config::setConfigurationValues(Lifecycle\Settings::CONTEXT, [self::VERSION_CHECK => $enabled ? '1' : '0']);
    }

    /**
     * État de l'encadré « Version du plugin », lu sur GitHub au plus toutes les 12 heures.
     *
     * @return array<string, mixed>
     */
    public static function pluginVersion(): array
    {
        return (new PluginVersion('dockerinventory', self::REPOSITORY, PLUGIN_DOCKERINVENTORY_VERSION))
            ->status(self::versionCheck());
    }

    /**
     * Onglet « Certificats TLS » (#78) : délai d'alerte, état de la tâche automatique.
     *
     * @return array{warning_days: int, max_days: int, cron_active: bool, cron_url: string}
     */
    public static function certificateSettings(): array
    {
        $cron = new \CronTask();
        $found = $cron->getFromDBbyName(Certificate::class, Certificate::CRON_NAME);

        return [
            'warning_days' => Certificate::warningDays(),
            'max_days'     => Certificate::MAX_WARNING_DAYS,
            'cron_active'  => $found && (int) $cron->fields['state'] !== \CronTask::STATE_DISABLE,
            'cron_url'     => $found ? \CronTask::getFormURLWithID((int) $cron->getID()) : '',
        ];
    }

    /**
     * Désinstallation : réglage et état mis en cache retirés.
     */
    public static function uninstallVersionCheck(): void
    {
        \Config::deleteConfigurationValues(Lifecycle\Settings::CONTEXT, [self::VERSION_CHECK]);
        PluginVersion::uninstall('dockerinventory');
    }

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
            self::TAB_CERTIFICATES => [Certificate::getTypeName(2), Certificate::getIcon()],
        ];
        $tabs = [];
        foreach ($labels as $tab => [$label, $icon]) {
            $tabs[] = ['label' => $label, 'icon' => $icon, 'url' => self::url($tab), 'active' => $tab === $active];
        }

        return $tabs;
    }
}

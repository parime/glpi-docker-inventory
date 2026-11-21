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

use CommonGLPI;

/**
 * Seule entrée de menu d'usage du plugin : Parc > Inventaire Docker (charte commune, section 9).
 * Ses écrans sont des sous-pages reliées par une barre d'onglets commune
 * (templates/_section_nav.html.twig) : services, versions des images, images Harbor et
 * vulnérabilités, analyses importées (#73), fin de support des images, exposition, configuration
 * à risque. Visible avec le droit de lecture du plugin. Les réglages (connexions, registres
 * maîtrisés, fin de support) sont dans la page de configuration du plugin (ConfigPage).
 */
class Menu extends CommonGLPI
{
    public const TAB_SERVICES = 'service';
    public const TAB_VERSIONS = 'versions';
    public const TAB_HARBOR = 'harbor';
    public const TAB_SCANS = 'scans';
    public const TAB_EOL = 'eol';
    public const TAB_EXPOSURE = 'exposure';
    public const TAB_HARDENING = 'hardening';

    public static function getTypeName($nb = 0)
    {
        return __('Inventaire Docker', 'dockerinventory');
    }

    /**
     * @return string
     */
    public static function getIcon()
    {
        return 'ti ti-brand-docker';
    }

    public static function canView(): bool
    {
        return Service::canView();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * Entrée Parc > Inventaire Docker, qui ouvre la liste des services. Les options donnent le fil
     * d'Ariane de chaque sous-page (Parc > Inventaire Docker > Versions des images...).
     *
     * @return array<string, mixed>|false
     */
    public static function getMenuContent()
    {
        if (!self::canView()) {
            return false;
        }
        $options = [];
        foreach (self::screens() as $key => $screen) {
            $options[$key] = [
                'title' => $screen['label'],
                'page'  => $screen['url'],
                'icon'  => $screen['icon'],
            ];
        }
        $options[self::TAB_SERVICES]['links'] = ['search' => Service::getSearchURL(false)];

        return [
            'title'   => self::getTypeName(2),
            'page'    => Service::getSearchURL(false),
            'icon'    => self::getIcon(),
            'options' => $options,
        ];
    }

    /**
     * Écrans de l'entrée, dans l'ordre des onglets (adresses relatives à la racine de GLPI).
     *
     * @return array<string, array{label: string, icon: string, url: string}>
     */
    private static function screens(): array
    {
        return [
            self::TAB_SERVICES => [
                'label' => __('Services', 'dockerinventory'),
                'icon'  => Service::getIcon(),
                'url'   => Service::getSearchURL(false),
            ],
            self::TAB_VERSIONS => [
                'label' => ImageVersion::getTypeName(2),
                'icon'  => ImageVersion::getIcon(),
                'url'   => ImageVersion::getOverviewURL(false),
            ],
            self::TAB_HARBOR => [
                'label' => __('Images Harbor et vulnérabilités', 'dockerinventory'),
                'icon'  => HarborArtifact::getIcon(),
                'url'   => HarborArtifact::getPageURL(false),
            ],
            self::TAB_SCANS => [
                'label' => __('Analyses importées', 'dockerinventory'),
                'icon'  => ImageScan::getIcon(),
                'url'   => ImageScan::getPageURL(false),
            ],
            self::TAB_EOL => [
                'label' => __('Fin de support des images', 'dockerinventory'),
                'icon'  => 'ti ti-calendar-x',
                'url'   => ImageVersion::getEolURL(false),
            ],
            self::TAB_EXPOSURE => [
                'label' => Exposure::getTypeName(1),
                'icon'  => Exposure::getIcon(),
                'url'   => Exposure::getPageURL(false),
            ],
            self::TAB_HARDENING => [
                'label' => Hardening::getTypeName(1),
                'icon'  => Hardening::getIcon(),
                'url'   => Hardening::getPageURL(false),
            ],
        ];
    }

    /**
     * Onglets des sous-pages, pour templates/_section_nav.html.twig (adresses complètes).
     *
     * @return list<array{label: string, icon: string, url: string, active: bool}>
     */
    public static function tabs(string $active): array
    {
        global $CFG_GLPI;

        $tabs = [];
        foreach (self::screens() as $key => $screen) {
            $tabs[] = [
                'label'  => $screen['label'],
                'icon'   => $screen['icon'],
                'url'    => (string) $CFG_GLPI['root_doc'] . $screen['url'],
                'active' => $key === $active,
            ];
        }

        return $tabs;
    }
}

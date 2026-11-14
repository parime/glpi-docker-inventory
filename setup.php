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

use Glpi\Plugin\Hooks;
use GlpiPlugin\Dockerinventory\Connection;
use GlpiPlugin\Dockerinventory\ImageVersion;
use GlpiPlugin\Dockerinventory\Node;
use GlpiPlugin\Dockerinventory\Profile;
use GlpiPlugin\Dockerinventory\Service;

define('PLUGIN_DOCKERINVENTORY_VERSION', '0.4.0');
define('PLUGIN_DOCKERINVENTORY_MIN_GLPI', '11.0.0');
// GLPI 11 et 12 depuis un seul code source (voir src/Compat/).
define('PLUGIN_DOCKERINVENTORY_MAX_GLPI', '12.99.99');
define('PLUGIN_DOCKERINVENTORY_MIN_PHP', '8.2.0');

function plugin_init_dockerinventory(): void
{
    global $PLUGIN_HOOKS;

    // Clé littérale : GLPI 12 a supprimé la constante Hooks::CSRF_COMPLIANT (la clé reste la même).
    $PLUGIN_HOOKS['csrf_compliant']['dockerinventory'] = true;

    // Version majeure de GLPI non vérifiée : rien n'est déclaré (les classes pourraient ne pas
    // se charger). Comparaison de version et non isPluginActive() : cette fonction sert aussi
    // pendant l'installation, avant l'activation.
    if (version_compare(GLPI_VERSION, PLUGIN_DOCKERINVENTORY_MAX_GLPI, '>')) {
        return;
    }

    // Clé d'API Portainer (ou mot de passe WUD) chiffrée avec la clé de GLPI : rechiffrée par
    // « glpi:security:change_key ».
    $PLUGIN_HOOKS[Hooks::SECURED_FIELDS]['dockerinventory'] = ['glpi_plugin_dockerinventory_connections.api_key'];

    // Onglets « Services » et « Versions » sur les applications, « Services du nœud » sur les
    // ordinateurs, droits du plugin sur les profils.
    Plugin::registerClass(Service::class, ['addtabon' => ['Appliance']]);
    Plugin::registerClass(ImageVersion::class, ['addtabon' => ['Appliance']]);
    Plugin::registerClass(Node::class, ['addtabon' => ['Computer']]);
    Plugin::registerClass(Profile::class, ['addtabon' => ['Profile']]);
    Plugin::registerClass(Connection::class);

    // Services et versions des images dans Parc ; connexions (Portainer, WUD) dans Configuration
    // (et depuis le nom du plugin dans Configuration > Plugins).
    $PLUGIN_HOOKS[Hooks::MENU_TOADD]['dockerinventory'] = [
        'assets' => [Service::class, ImageVersion::class],
        'config' => [Connection::class],
    ];
    $PLUGIN_HOOKS[Hooks::CONFIG_PAGE]['dockerinventory'] = 'front/connection.php';
}

/**
 * @return array<string, mixed>
 */
function plugin_version_dockerinventory(): array
{
    return [
        'name'         => 'Inventaire Docker',
        'version'      => PLUGIN_DOCKERINVENTORY_VERSION,
        'author'       => 'Vincent GUILLOTTE',
        'license'      => 'GPL-3.0-or-later',
        'homepage'     => 'https://github.com/parime/glpi-docker-inventory',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_DOCKERINVENTORY_MIN_GLPI,
                'max' => PLUGIN_DOCKERINVENTORY_MAX_GLPI,
            ],
            'php'  => [
                'min' => PLUGIN_DOCKERINVENTORY_MIN_PHP,
            ],
        ],
    ];
}

function plugin_dockerinventory_check_prerequisites(): bool
{
    if (version_compare(PHP_VERSION, PLUGIN_DOCKERINVENTORY_MIN_PHP, '<')) {
        echo sprintf('PHP %s or later is required.', PLUGIN_DOCKERINVENTORY_MIN_PHP);
        return false;
    }

    return true;
}

function plugin_dockerinventory_check_config(bool $verbose = false): bool
{
    return true;
}

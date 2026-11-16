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

use GlpiPlugin\Dockerinventory\ImageVersion;
use GlpiPlugin\Dockerinventory\LifecycleProduct;
use GlpiPlugin\Dockerinventory\Service;

// Ancienne adresse de la page « Fin de support des images » (menu Configuration, jusqu'à la version
// 0.8.0), gardée pour les favoris (#35) : les administrateurs de la configuration vont à l'onglet
// « Fin de support des images » de la page de configuration du plugin, les autres à la sous-page de
// lecture de Parc > Inventaire Docker. Aucune action ici.
Session::checkSeveralRightsOr([LifecycleProduct::RIGHTNAME => UPDATE, Service::RIGHTNAME => READ]);

if (!LifecycleProduct::canView()) {
    Html::redirect(ImageVersion::getEolURL());
}
$add = (string) ($_GET['add'] ?? '');
Html::redirect(LifecycleProduct::getPageURL(true, $add !== '' ? ['add' => $add] : []));

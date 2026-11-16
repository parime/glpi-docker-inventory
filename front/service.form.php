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

use GlpiPlugin\Dockerinventory\Menu;
use GlpiPlugin\Dockerinventory\Service;

// Fiche en lecture seule : aucune action POST. check() contrôle le droit ET l'entité de la fiche.
$service = new Service();
$id = (int) ($_GET['id'] ?? 0);
$service->check($id, READ);

Html::header(Service::getTypeName(2), '', 'assets', Menu::class, Menu::TAB_SERVICES);
$service->display(['id' => $id]);
Html::footer();

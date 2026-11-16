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
use GlpiPlugin\Dockerinventory\Menu;
use GlpiPlugin\Dockerinventory\Service;

// Recherche GLPI sur les versions (vues enregistrées, exports) : droit de lecture du plugin ; la
// recherche filtre par entités actives.
Session::checkRight(Service::RIGHTNAME, READ);

Html::header(ImageVersion::getTypeName(2), '', 'assets', Menu::class, Menu::TAB_VERSIONS);
Search::show(ImageVersion::class);
Html::footer();

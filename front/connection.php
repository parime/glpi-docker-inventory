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

use GlpiPlugin\Dockerinventory\ConfigPage;
use GlpiPlugin\Dockerinventory\Connection;

// Ancienne adresse de la liste des connexions (menu Configuration > Connexions Docker, jusqu'à la
// version 0.8.0) : redirige vers l'onglet « Connexions Docker » de la page de configuration du
// plugin (#35), avec les paramètres de recherche, pour garder les favoris. Aucune action ici.
Session::checkRight(Connection::RIGHTNAME, UPDATE);

$params = array_filter($_GET, static fn ($key): bool => is_string($key) && $key !== 'tab', ARRAY_FILTER_USE_KEY);
$url = ConfigPage::url();
Html::redirect($params === [] ? $url : $url . '?' . http_build_query($params));

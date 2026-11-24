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

// Cible de notification du rappel des certificats TLS (#78) : elle n'a pas de fiche propre, elle se
// règle dans Configuration > Notifications. GLPI propose pourtant l'adresse générique de la classe :
// elle mène aux notifications (droit de lecture des notifications du cœur), au lieu d'un refus.
Session::checkRight('notification', READ);

Html::redirect(Notification::getSearchURL());

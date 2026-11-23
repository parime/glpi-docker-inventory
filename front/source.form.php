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

use GlpiPlugin\Dockerinventory\Connection;
use GlpiPlugin\Dockerinventory\ManualRead;
use GlpiPlugin\Dockerinventory\Service;

// Lectures manuelles des écrans d'usage de Parc > Inventaire Docker (#118) : « Lire maintenant »
// d'une source (Services, Exposition), « Lire Harbor maintenant » (Images Harbor), vérification des
// registres (Versions des images), « Mettre à jour maintenant » (Fin de support des images),
// catalogues KEV et EPSS (Analyses importées). Même droit qu'avant : mise à jour de la configuration
// de GLPI. Aucune page affichée : retour à l'écran d'où vient le formulaire.
Session::checkRight(Connection::RIGHTNAME, UPDATE);

$result = ManualRead::run($_POST, $_FILES);
if ($result === null) {
    Html::redirect(Service::getSearchURL());
}
ManualRead::flash($result);
Html::back();

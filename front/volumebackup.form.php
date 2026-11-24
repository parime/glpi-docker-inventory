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

use GlpiPlugin\Dockerinventory\Service;
use GlpiPlugin\Dockerinventory\VolumeBackup;

// Sauvegardes déclarées des volumes (#78) : enregistrer ou supprimer une déclaration, rien d'autre.
// Chaque action contrôle le droit du plugin et l'accès au service (droit et entité). Aucune page
// affichée ; aucune sauvegarde n'est lancée ni vérifiée par le plugin.
Session::checkRight(Service::RIGHTNAME, READ);

if (isset($_POST['add_backup'])) {
    Session::checkRight(VolumeBackup::RIGHTNAME, CREATE);
    $serviceId = (int) ($_POST['plugin_dockerinventory_services_id'] ?? 0);
    (new Service())->check($serviceId, READ);
    $error = VolumeBackup::record(
        $serviceId,
        (string) ($_POST['volume'] ?? ''),
        (string) ($_POST['backed_up_by'] ?? ''),
        (string) ($_POST['date_verified'] ?? '')
    );
    Session::addMessageAfterRedirect(
        htmlescape($error !== '' ? $error : __('Sauvegarde déclarée.', 'dockerinventory')),
        false,
        $error !== '' ? ERROR : INFO
    );
    Html::back();
} elseif (isset($_POST['purge_backup'])) {
    Session::checkRight(VolumeBackup::RIGHTNAME, PURGE);
    $backup = new VolumeBackup();
    $backup->check((int) ($_POST['id'] ?? 0), PURGE);
    (new Service())->check((int) $backup->fields['plugin_dockerinventory_services_id'], READ);
    VolumeBackup::remove($backup->getID());
    Session::addMessageAfterRedirect(htmlescape(__('Déclaration supprimée.', 'dockerinventory')), false, INFO);
    Html::back();
}

Html::redirect(Service::getSearchURL());

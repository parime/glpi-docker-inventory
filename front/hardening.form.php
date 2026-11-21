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

use GlpiPlugin\Dockerinventory\Hardening;
use GlpiPlugin\Dockerinventory\HardeningException;
use GlpiPlugin\Dockerinventory\Service;

// Exceptions aux écarts de configuration (#71) : enregistrer ou supprimer, rien d'autre. Chaque
// action contrôle le droit du plugin et l'accès au service (droit et entité). Aucune page affichée.
Session::checkRight(Service::RIGHTNAME, READ);

if (isset($_POST['add_exception'])) {
    Session::checkRight(HardeningException::RIGHTNAME, CREATE);
    $serviceId = (int) ($_POST['plugin_dockerinventory_services_id'] ?? 0);
    (new Service())->check($serviceId, READ);
    $error = HardeningException::record(
        $serviceId,
        (string) ($_POST['code'] ?? ''),
        (string) ($_POST['justification'] ?? ''),
        (string) ($_POST['date_end'] ?? '')
    );
    Session::addMessageAfterRedirect(
        htmlescape($error !== '' ? $error : __('Exception enregistrée.', 'dockerinventory')),
        false,
        $error !== '' ? ERROR : INFO
    );
    Html::back();
} elseif (isset($_POST['purge_exception'])) {
    Session::checkRight(HardeningException::RIGHTNAME, PURGE);
    $exception = new HardeningException();
    $exception->check((int) ($_POST['id'] ?? 0), PURGE);
    (new Service())->check((int) $exception->fields['plugin_dockerinventory_services_id'], READ);
    HardeningException::remove($exception->getID());
    Session::addMessageAfterRedirect(htmlescape(__('Exception supprimée.', 'dockerinventory')), false, INFO);
    Html::back();
}

Html::redirect(Hardening::getPageURL());

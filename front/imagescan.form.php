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

use GlpiPlugin\Dockerinventory\ImageScan;
use GlpiPlugin\Dockerinventory\Scan\ScanReportParser;
use GlpiPlugin\Dockerinventory\Service;

// Analyses d'images importées (#73) : déposer un rapport ou supprimer une analyse, rien d'autre.
// Le fichier est lu dans la limite de taille, jamais exécuté ni gardé ; aucune page affichée.
Session::checkRight(Service::RIGHTNAME, READ);

if (isset($_POST['import_scan'])) {
    Session::checkRight(ImageScan::RIGHTNAME, CREATE);
    $file = $_FILES['report'] ?? null;
    $content = null;
    $error = __('Choisissez un fichier de rapport.', 'dockerinventory');
    if (is_array($file) && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_INI_SIZE) {
        $error = ImageScan::errorLabels()['too_large'];
    } elseif (
        is_array($file) && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK
        && is_string($file['tmp_name'] ?? null) && is_uploaded_file($file['tmp_name'])
    ) {
        if ((int) filesize($file['tmp_name']) > ScanReportParser::MAX_BYTES) {
            $error = ImageScan::errorLabels()['too_large'];
        } else {
            $content = (string) file_get_contents($file['tmp_name'], false, null, 0, ScanReportParser::MAX_BYTES + 1);
        }
    }
    if ($content !== null) {
        $result = ImageScan::import($content, (string) ($file['name'] ?? ''), (string) ($_POST['reference'] ?? ''));
        $error = $result['ok'] ? '' : $result['message'];
        if ($result['ok']) {
            Session::addMessageAfterRedirect(htmlescape($result['message']), false, INFO);
        }
    }
    if ($error !== '') {
        Session::addMessageAfterRedirect(htmlescape($error), false, ERROR);
    }
    Html::redirect(ImageScan::getPageURL());
} elseif (isset($_POST['purge_scan'])) {
    Session::checkRight(ImageScan::RIGHTNAME, PURGE);
    $scan = new ImageScan();
    $scan->check((int) ($_POST['id'] ?? 0), PURGE);
    ImageScan::remove($scan->getID());
    Session::addMessageAfterRedirect(htmlescape(__('Analyse supprimée.', 'dockerinventory')), false, INFO);
    Html::redirect(ImageScan::getPageURL());
}

Html::redirect(ImageScan::getPageURL());

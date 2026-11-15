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
use GlpiPlugin\Dockerinventory\Registry\RegistryImporter;

Session::checkRight(Connection::RIGHTNAME, UPDATE);

$connection = new Connection();
$id = (int) ($_REQUEST['id'] ?? 0);

if (isset($_POST['add'])) {
    $connection->check(-1, CREATE, $_POST);
    $newId = $connection->add($_POST);
    Html::redirect($newId
        ? Connection::getFormURLWithID((int) $newId)
        : Connection::getFormURL() . '?type=' . rawurlencode((string) ($_POST['type'] ?? '')));
} elseif (isset($_POST['update'])) {
    $connection->check($id, UPDATE);
    $connection->update($_POST);
    Html::back();
} elseif (isset($_POST['purge'])) {
    $connection->check($id, PURGE);
    $connection->delete($_POST, true);
    $connection->redirectToList();
} elseif (isset($_POST['test'])) {
    $connection->check($id, UPDATE);
    $result = $connection->testConnection();
    Session::addMessageAfterRedirect(htmlescape($result['message']), false, $result['ok'] ? INFO : ERROR);
    Html::back();
} elseif (isset($_POST['registry_check'])) {
    // Page des versions : vérification des registres (Docker Hub, Harbor) sans attendre la tâche.
    $report = (new RegistryImporter())->run();
    Session::addMessageAfterRedirect(htmlescape(Connection::registryMessage($report)), false, INFO);
    Html::back();
} elseif (isset($_POST['harbor_import'])) {
    // Connexion Harbor : projets, artefacts et analyses sans attendre la tâche automatique.
    $connection->check($id, UPDATE);
    if ($connection->isHarbor()) {
        $report = $connection->runHarborImport();
        Session::addMessageAfterRedirect(
            htmlescape(Connection::harborMessage($report)),
            false,
            $report['status'] === Connection::STATUS_ERROR ? ERROR : INFO
        );
    }
    Html::back();
} elseif (isset($_POST['import'])) {
    $connection->check($id, UPDATE);
    $report = $connection->runImport();
    Session::addMessageAfterRedirect(
        htmlescape($connection->importMessage($report)),
        false,
        $report['status'] === Connection::STATUS_ERROR ? ERROR : INFO
    );
    Html::back();
}

$connection->check($id > 0 ? $id : -1, $id > 0 ? READ : CREATE);
Html::header(Connection::getTypeName(2), '', 'config', Connection::class);
// Nouvelle connexion : type choisi sur la première page (Portainer ou WUD).
$connection->display(['id' => $id, 'type' => (string) ($_GET['type'] ?? '')]);
Html::footer();

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
    // Compte Portainer qui ne voit rien : avertissement, pas succès.
    $level = $result['ok'] ? (($result['warning'] ?? false) ? WARNING : INFO) : ERROR;
    Session::addMessageAfterRedirect(htmlescape($result['message']), false, $level);
    Html::back();
} elseif (isset($_POST['registry_check'])) {
    // Ancien envoi de la page des versions (jusqu'à la version 0.16.0) : front/source.form.php.
    ManualRead::flash(ManualRead::registries());
    Html::back();
} elseif (($legacy = ManualRead::legacy($_POST, $_FILES)) !== null) {
    // Anciens boutons « Importer maintenant », « Lire … maintenant » de la fiche (jusqu'à la version
    // 0.16.0) : la lecture est faite, puis l'utilisateur va à l'écran d'usage qui la propose (#118).
    Html::redirect($legacy);
}

$connection->check($id > 0 ? $id : -1, $id > 0 ? READ : CREATE);
Html::header(Connection::getTypeName(2), '', 'config', 'plugin');
// Nouvelle connexion : type choisi sur la première page (Portainer ou WUD).
$connection->display(['id' => $id, 'type' => (string) ($_GET['type'] ?? '')]);
Html::footer();

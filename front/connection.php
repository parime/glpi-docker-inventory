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

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Dockerinventory\Connection;
use GlpiPlugin\Dockerinventory\Harbor\HarborSettings;
use GlpiPlugin\Dockerinventory\HarborArtifact;

// Page de configuration du plugin : connexions Portainer, WUD et Harbor, registres maîtrisés.
// Administrateurs de la configuration de GLPI seulement.
Session::checkRight(Connection::RIGHTNAME, UPDATE);

if (isset($_POST['save_managed_registries'])) {
    $hosts = HarborSettings::save((string) ($_POST['managed_registries'] ?? ''));
    Session::addMessageAfterRedirect(htmlescape(sprintf(
        __('Registres maîtrisés enregistrés : %s', 'dockerinventory'),
        $hosts === [] ? __('ceux des connexions Harbor', 'dockerinventory') : implode(', ', $hosts)
    )), false, INFO);
    Html::back();
}

Html::header(Connection::getTypeName(2), '', 'config', Connection::class);
TemplateRenderer::getInstance()->display('@dockerinventory/managed_registries.html.twig', [
    'text'          => HarborSettings::text(),
    // Hôtes des connexions Harbor des entités actives seulement (#48).
    'harbor_hosts'  => HarborArtifact::visibleHarborHosts(),
    'page_url'      => Connection::getSearchURL(),
    'harbor_url'    => HarborArtifact::getPageURL(),
]);
Search::show(Connection::class);
Html::footer();

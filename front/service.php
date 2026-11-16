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
use GlpiPlugin\Dockerinventory\Menu;
use GlpiPlugin\Dockerinventory\Service;

// Liste des services, première sous-page de l'entrée Parc > Inventaire Docker : droit de lecture du
// plugin ; la recherche filtre par entités actives.
Session::checkRight(Service::RIGHTNAME, READ);

Html::header(Service::getTypeName(2), '', 'assets', Menu::class, Menu::TAB_SERVICES);
// Encadré d'aide commun (charte, section 8 ; #39) : texte fixe, rendu après le contrôle de droit.
TemplateRenderer::getInstance()->display('@dockerinventory/_page_help.html.twig', [
    'page'     => 'services',
    'purpose'  => __(
        'Tous les services de conteneurs inventoriés : Swarm, Compose, conteneurs autonomes et Kubernetes.',
        'dockerinventory'
    ),
    'expected' => __(
        'Recherchez ou filtrez, puis ouvrez un service pour voir son image, sa version et son état.',
        'dockerinventory'
    ) . ' ' . __('Un service disparu de la source est marqué « retiré », jamais supprimé.', 'dockerinventory'),
]);
TemplateRenderer::getInstance()->display('@dockerinventory/_section_nav.html.twig', [
    'tabs' => Menu::tabs(Menu::TAB_SERVICES),
]);
Search::show(Service::class);
Html::footer();

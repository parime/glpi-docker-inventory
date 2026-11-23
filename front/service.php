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
use GlpiPlugin\Dockerinventory\ManualRead;
use GlpiPlugin\Dockerinventory\Menu;
use GlpiPlugin\Dockerinventory\Service;
use GlpiPlugin\Dockerinventory\VolumeBackup;

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
// Filtre « sans sauvegarde » (#78) : services actifs dont un volume n'a pas de sauvegarde déclarée.
TemplateRenderer::getInstance()->display('@dockerinventory/_backup_filter.html.twig', [
    'backups'  => VolumeBackup::overview(),
    'list_url' => VolumeBackup::unbackedSearchURL(),
]);
Search::show(Service::class);
// Bloc « Sources » sous la liste (#118) : état de chaque connexion et « Lire maintenant » (avant sur la fiche de
// la connexion, dans la configuration). Les registres Harbor y sont vérifiés (versions des images).
TemplateRenderer::getInstance()->display('@dockerinventory/_sources.html.twig', [
    'sources'  => Connection::sourceRows(),
    'title'    => __('Sources', 'dockerinventory'),
    'help'     => __(
        'Plateformes lues par le plugin, sans rien y modifier, par les tâches automatiques ou sur clic.',
        'dockerinventory'
    ),
    'read_url' => ManualRead::formURL(),
    'can_run'  => ManualRead::canRun(),
    'form_url' => Connection::canView() ? Connection::getFormURL() : '',
]);
Html::footer();

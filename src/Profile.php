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

declare(strict_types=1);

namespace GlpiPlugin\Dockerinventory;

use CommonGLPI;
use Glpi\Application\View\TemplateRenderer;
use Session;

/**
 * Onglet « Inventaire Docker » des profils : le droit du plugin s'attribue depuis Administration >
 * Profils, avec la matrice du cœur (enregistrée par front/profile.form.php du cœur, droit
 * « profile »). Lecture seule en lot 1 : un seul niveau, Lire.
 */
class Profile extends CommonGLPI
{
    public static function getTypeName($nb = 0)
    {
        return __('Inventaire Docker', 'dockerinventory');
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        return self::canManage($item)
            ? self::createTabEntry(self::getTypeName(), 0, static::class, Service::getIcon())
            : '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        // ajax/common.tabs.php sert le contenu sans passer par getTabNameForItem().
        if (!self::canManage($item)) {
            return false;
        }
        TemplateRenderer::getInstance()->display('@dockerinventory/profile_rights.html.twig', [
            'item'   => $item,
            'title'  => self::getTypeName(),
            'rights' => [
                [
                    'itemtype' => Service::class,
                    'label'    => Service::getTypeName(2),
                    'field'    => Service::RIGHTNAME,
                    'rights'   => [READ => __('Read')],
                ],
                [
                    'itemtype' => HardeningException::class,
                    'label'    => HardeningException::getTypeName(2),
                    'field'    => HardeningException::RIGHTNAME,
                    'rights'   => (new HardeningException())->getRights(),
                ],
                [
                    'itemtype' => ImageScan::class,
                    'label'    => ImageScan::getTypeName(2),
                    'field'    => ImageScan::RIGHTNAME,
                    'rights'   => (new ImageScan())->getRights(),
                ],
            ],
        ]);

        return true;
    }

    // Droit « profile » du cœur et accès au plugin : on ne délègue pas un module qu'on ne voit pas.
    private static function canManage(CommonGLPI $item): bool
    {
        return $item instanceof \Profile && !$item->isNewItem()
            && \Profile::canView() && (bool) Session::haveRight(Service::RIGHTNAME, READ);
    }
}

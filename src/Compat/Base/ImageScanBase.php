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

namespace GlpiPlugin\Dockerinventory\Compat\Base;

use CommonDBTM;
use GlpiPlugin\Dockerinventory\Compat\GlpiVersion;
use GlpiPlugin\Dockerinventory\ImageScan;

/*
 * Parent de \GlpiPlugin\Dockerinventory\ImageScan : redéclare $rightname et $dohistory
 * (historique des analyses importées, preuve pour l'audit) sans type en GLPI 11, typés en GLPI 12 (voir
 * GlpiVersion). Le droit vient de la constante de la classe enfant.
 */
if (GlpiVersion::isAtLeast12()) {
    abstract class ImageScanBase extends CommonDBTM
    {
        public static string $rightname = ImageScan::RIGHTNAME;
        public bool $dohistory = true;
    }
} else {
    abstract class ImageScanBase extends CommonDBTM
    {
        public static $rightname = ImageScan::RIGHTNAME;
        public $dohistory = true;
    }
}

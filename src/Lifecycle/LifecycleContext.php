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

namespace GlpiPlugin\Dockerinventory\Lifecycle;

/**
 * Contexte des appels sortants vers la source des cycles de vie. GLPI 12 refuse par défaut les
 * appels vers un réseau privé ; pour un miroir interne de la source, un administrateur ajoute cette
 * classe à la constante GLPI_SERVERSIDE_URL_ALLOWED_PRIVATE_NETWORKS_CONTEXTS
 * (config/local_define.php), sans ouvrir les réseaux privés aux autres appels de GLPI.
 */
class LifecycleContext extends \CommonGLPI
{
    public static function getTypeName($nb = 0)
    {
        return 'endoflife.date';
    }
}

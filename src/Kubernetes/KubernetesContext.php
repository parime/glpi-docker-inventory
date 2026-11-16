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

namespace GlpiPlugin\Dockerinventory\Kubernetes;

/**
 * Contexte des appels sortants vers l'API d'un cluster Kubernetes. GLPI 12 refuse par défaut les
 * appels vers un réseau privé ; un administrateur dont le cluster est sur le réseau interne ajoute
 * cette classe à la constante GLPI_SERVERSIDE_URL_ALLOWED_PRIVATE_NETWORKS_CONTEXTS
 * (config/local_define.php), sans ouvrir les réseaux privés aux autres appels de GLPI :
 *
 *     define('GLPI_SERVERSIDE_URL_ALLOWED_PRIVATE_NETWORKS_CONTEXTS', [
 *         \GlpiPlugin\Dockerinventory\Kubernetes\KubernetesContext::class,
 *     ]);
 */
class KubernetesContext extends \CommonGLPI
{
    public static function getTypeName($nb = 0)
    {
        return 'Kubernetes';
    }
}

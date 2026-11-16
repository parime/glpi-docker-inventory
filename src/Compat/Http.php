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

namespace GlpiPlugin\Dockerinventory\Compat;

/**
 * Requête GET sortante par le client HTTP de GLPI lui-même, en GLPI 11 (Toolbox::getGuzzleClient())
 * comme en GLPI 12 (Glpi\Toolbox\HttpClient, qui l'a remplacé) : les deux appliquent le proxy
 * configuré dans GLPI. Les redirections ne sont jamais suivies (une redirection pourrait envoyer
 * la clé d'API vers un autre serveur). Un certificat d'autorité propre à la connexion (cluster
 * Kubernetes à autorité privée) peut être donné : il remplace alors les autorités du système pour
 * cette requête seulement.
 */
final class Http
{
    /**
     * @param array<string, scalar> $query
     * @param array<string, string> $headers
     * @param class-string<\CommonGLPI> $context GLPI 12 : classe qu'un administrateur peut ajouter à
     *        GLPI_SERVERSIDE_URL_ALLOWED_PRIVATE_NETWORKS_CONTEXTS pour autoriser un réseau privé.
     * @param string|null $caFile Fichier PEM des autorités acceptées (null : celles du système).
     * @return string Corps de la réponse.
     * @throws \Throwable En cas d'échec de transport ou de réponse hors 2xx.
     */
    public static function get(
        string $url,
        array $query,
        array $headers,
        string $context,
        int $timeout = 15,
        ?string $caFile = null
    ): string {
        if (class_exists(\Glpi\Toolbox\HttpClient::class)) {
            return (new \Glpi\Toolbox\HttpClient($context))->get($url, [
                'query'         => $query,
                'headers'       => $headers,
                'timeout'       => $timeout,
                'max_redirects' => 0,
            ] + ($caFile !== null ? ['cafile' => $caFile] : []))->getContent();
        }

        $response = \Toolbox::getGuzzleClient()->request('GET', $url, [
            'query'           => $query,
            'headers'         => $headers,
            'timeout'         => $timeout,
            'allow_redirects' => false,
            'http_errors'     => true,
        ] + ($caFile !== null ? ['verify' => $caFile] : []));
        // Redirection non suivie : même refus qu'en GLPI 12 (RedirectionException).
        if ($response->getStatusCode() >= 300) {
            throw new \RuntimeException(
                sprintf('HTTP %d returned (redirect not followed)', $response->getStatusCode())
            );
        }

        return (string) $response->getBody();
    }
}

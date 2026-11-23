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

    /**
     * Requête GET sans clé d'API, qui renvoie la réponse entière (composant commun « Version du
     * plugin », PluginVersion, #121). Un code HTTP (2xx à 5xx) est renvoyé tel quel, jamais levé en exception ; un
     * échec réseau (nom inconnu, connexion refusée, délai dépassé) lève une exception.
     *
     * @param array<string, string> $headers
     * @return array{status: int, body: string, headers: array<string, string>} en-têtes en minuscules
     */
    public static function response(string $url, array $headers = [], int $timeout = 10): array
    {
        $timeout = max(1, $timeout);

        return class_exists(\Glpi\Toolbox\HttpClient::class)
            ? self::responseWithGlpi12($url, $headers, $timeout)
            : self::responseWithGlpi11($url, $headers, $timeout);
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string, headers: array<string, string>}
     */
    private static function responseWithGlpi12(string $url, array $headers, int $timeout): array
    {
        $options = [
            'headers'       => $headers,
            'timeout'       => $timeout,
            'max_duration'  => $timeout,
            'max_redirects' => 0,
        ];
        try {
            $response = (new \Glpi\Toolbox\HttpClient(\GlpiPlugin\Dockerinventory\Menu::class))
                ->request('GET', $url, $options);
        } catch (\Throwable $e) {
            // Le client du cœur lève une exception pour toute réponse 3xx, 4xx ou 5xx, qui porte la
            // réponse (HttpExceptionInterface de Symfony) ; sans réponse, c'est un échec réseau.
            $response = method_exists($e, 'getResponse') ? $e->getResponse() : null;
            if (!is_object($response) || !method_exists($response, 'getStatusCode')) {
                throw $e;
            }
        }
        $flat = [];
        foreach ($response->getHeaders(false) as $name => $values) {
            $flat[strtolower((string) $name)] = (string) ($values[0] ?? '');
        }

        return [
            'status'  => (int) $response->getStatusCode(),
            'body'    => (string) $response->getContent(false),
            'headers' => $flat,
        ];
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string, headers: array<string, string>}
     */
    private static function responseWithGlpi11(string $url, array $headers, int $timeout): array
    {
        $response = \Toolbox::getGuzzleClient(['timeout' => $timeout, 'connect_timeout' => $timeout])
            ->request('GET', $url, [
                'headers'         => $headers,
                'timeout'         => $timeout,
                'allow_redirects' => false,
                'http_errors'     => false,
            ]);
        $flat = [];
        foreach ($response->getHeaders() as $name => $values) {
            $flat[strtolower((string) $name)] = (string) ($values[0] ?? '');
        }

        return [
            'status'  => $response->getStatusCode(),
            'body'    => (string) $response->getBody(),
            'headers' => $flat,
        ];
    }
}

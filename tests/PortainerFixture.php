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

namespace GlpiPlugin\Dockerinventory\Tests;

/**
 * Jeu de données fictif du faux Portainer (tests/mock-portainer/fixture.json), servi en mémoire
 * comme le fait tests/mock-portainer/server.py : environnement Swarm (1), hôte Docker autonome (2),
 * Kubernetes ignoré (3).
 */
final class PortainerFixture
{
    /**
     * @return array<string, mixed>
     */
    public static function load(): array
    {
        $data = json_decode((string) file_get_contents(__DIR__ . '/mock-portainer/fixture.json'), true);

        return is_array($data) ? $data : [];
    }

    /**
     * Corps de la réponse à un chemin de l'API Portainer. Les erreurs sont levées comme celles du
     * client HTTP de GLPI (« HTTP 503 returned... »), que PortainerException traduit.
     *
     * @param array<string, mixed> $fixture
     */
    public static function respond(array $fixture, string $path): string
    {
        if ($path === '/api/endpoints') {
            return (string) json_encode($fixture['endpoints']);
        }
        if ($path === '/api/stacks') {
            return (string) json_encode($fixture['stacks']);
        }
        if (preg_match('#^/api/endpoints/(\d+)/docker/(services|nodes|tasks|networks)$#', $path, $m) === 1) {
            $endpoint = $fixture['docker'][$m[1]] ?? null;
            if (!is_array($endpoint)) {
                throw new \RuntimeException('HTTP 404 returned for "' . $path . '".');
            }
            if (!($endpoint['swarm'] ?? false)) {
                // Docker répond 503 « This node is not a swarm manager » hors Swarm.
                throw new \RuntimeException('HTTP 503 returned for "' . $path . '".');
            }

            return (string) json_encode($endpoint[$m[2]]);
        }
        $standalone = '#^/api/endpoints/(\d+)/docker/(info|containers/json|images/json|containers/([0-9a-f]+)/json)$#';
        if (preg_match($standalone, $path, $m) === 1) {
            $endpoint = $fixture['docker'][$m[1]] ?? null;
            if (!is_array($endpoint)) {
                throw new \RuntimeException('HTTP 404 returned for "' . $path . '".');
            }
            if (($m[3] ?? '') !== '') {
                $detail = $endpoint['inspect'][$m[3]] ?? null;
                if ($detail === null) {
                    throw new \RuntimeException('HTTP 404 returned for "' . $path . '".');
                }

                return (string) json_encode($detail);
            }
            $key = explode('/', $m[2])[0];

            return (string) json_encode($endpoint[$key] ?? ($key === 'info' ? new \stdClass() : []));
        }

        throw new \RuntimeException('HTTP 404 returned for "' . $path . '".');
    }
}

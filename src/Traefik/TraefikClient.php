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

namespace GlpiPlugin\Dockerinventory\Traefik;

use GlpiPlugin\Dockerinventory\Compat\Http;

/**
 * Lecture seule de l'API de Traefik (v2 et v3) : uniquement des GET sur des chemins fixes
 * (/api/version, /api/entrypoints, /api/http/routers, /api/http/services, /api/http/middlewares,
 * /api/tcp/routers), toujours sous l'adresse enregistrée sur la connexion. Aucune autre adresse
 * n'est appelée (ni celles des serveurs des services, ni une redirection, jamais suivie).
 *
 * Authentification : basique si un utilisateur est donné (middleware basicAuth devant l'API),
 * sinon jeton porteur (Authorization: Bearer, par exemple derrière un forwardAuth) s'il y en a un,
 * sinon aucune. Le transport est injectable (tests sans réseau) ; par défaut Compat\Http::get().
 */
final class TraefikClient
{
    /** Nombre maximal d'objets demandés par liste (paramètre per_page de l'API). */
    public const PER_PAGE = 500;

    /** @var callable(string, array<string, scalar>, array<string, string>): string */
    private $transport;

    /**
     * @param (callable(string, array<string, scalar>, array<string, string>): string)|null $transport
     */
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $username = '',
        #[\SensitiveParameter]
        private readonly string $secret = '',
        ?callable $transport = null
    ) {
        $this->transport = $transport ?? static fn (string $url, array $query, array $headers): string
            => Http::get($url, $query, $headers, TraefikContext::class);
    }

    /**
     * Version de Traefik, chaîne vide si elle n'est pas donnée.
     */
    public function version(): string
    {
        $data = $this->get('/api/version', []);
        $version = $data['Version'] ?? ($data['version'] ?? '');

        return is_scalar($version) ? mb_substr(trim((string) $version), 0, 32) : '';
    }

    /**
     * Toutes les listes lues par le plugin.
     *
     * @return array{entrypoints: list<array<string, mixed>>, routers: list<array<string, mixed>>,
     *     services: list<array<string, mixed>>, middlewares: list<array<string, mixed>>,
     *     tcp_routers: list<array<string, mixed>>}
     */
    public function snapshot(): array
    {
        return [
            'entrypoints' => $this->listOf('/api/entrypoints'),
            'routers'     => $this->listOf('/api/http/routers'),
            'services'    => $this->listOf('/api/http/services'),
            'middlewares' => $this->listOf('/api/http/middlewares'),
            'tcp_routers' => $this->listOf('/api/tcp/routers'),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listOf(string $path): array
    {
        $data = $this->get($path, ['per_page' => self::PER_PAGE]);
        if (!array_is_list($data)) {
            throw new TraefikException(TraefikException::UNEXPECTED);
        }

        return array_values(array_filter($data, 'is_array'));
    }

    /**
     * @param array<string, scalar> $query
     * @return array<mixed>
     * @throws TraefikException
     */
    private function get(string $path, array $query): array
    {
        $headers = ['Accept' => 'application/json'];
        if ($this->username !== '') {
            $headers['Authorization'] = 'Basic ' . base64_encode($this->username . ':' . $this->secret);
        } elseif ($this->secret !== '') {
            $headers['Authorization'] = 'Bearer ' . $this->secret;
        }
        try {
            $body = ($this->transport)(rtrim($this->baseUrl, '/') . $path, $query, $headers);
        } catch (\Throwable $e) {
            throw TraefikException::fromThrowable($e);
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new TraefikException(TraefikException::UNEXPECTED);
        }

        return $data;
    }
}

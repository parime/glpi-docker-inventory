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

namespace GlpiPlugin\Dockerinventory\Harbor;

use GlpiPlugin\Dockerinventory\Compat\Http;
use GlpiPlugin\Dockerinventory\Registry\RegistryContext;
use GlpiPlugin\Dockerinventory\Registry\RegistryException;

/**
 * Lecture seule de l'API REST de Harbor 2.x (/api/v2.0), uniquement par des requêtes GET, avec le
 * compte robot de la connexion (authentification basique, facultative pour les projets publics) :
 * - GET /api/v2.0/projects : projets visibles par le compte ;
 * - GET /api/v2.0/projects/<projet>/repositories : dépôts d'un projet ;
 * - GET /api/v2.0/projects/<projet>/repositories/<dépôt>/artifacts?with_scan_overview=true :
 *   artefacts (empreinte, tags, date de publication, taille) et résumé de leur analyse de
 *   vulnérabilités. Le nom du dépôt est encodé deux fois (« a/b » => « a%252Fb »), comme Harbor
 *   l'exige.
 *
 * Pagination de Harbor (page, page_size de 100) : une page incomplète est la dernière ; au plus
 * MAX_PAGES pages par liste (au-delà, la liste est marquée incomplète et rien n'en est retiré).
 * Les erreurs sont des RegistryException (codes stables, jamais le texte d'origine ni le mot de
 * passe). Transport injectable (tests sans réseau) ; par défaut le client HTTP de GLPI, sans
 * redirection, contexte des registres (réseaux privés en GLPI 12).
 *
 * @phpstan-type Page array{items: list<array<mixed>>, complete: bool}
 */
final class HarborClient
{
    public const API = '/api/v2.0';

    public const PAGE_SIZE = 100;

    public const MAX_PAGES = 20;

    /**
     * Types de rapports d'analyse demandés (en-tête X-Accept-Vulnerabilities, comme l'interface de
     * Harbor) : format générique 1.1 et ancien format natif de Harbor.
     */
    public const REPORT_MIME_TYPES = 'application/vnd.security.vulnerability.report; version=1.1, '
        . 'application/vnd.scanner.adapter.vuln.report.harbor+json; version=1.0';

    /** @var callable(string, array<string, scalar>, array<string, string>): string */
    private $transport;

    private int $requests = 0;

    /**
     * @param (callable(string, array<string, scalar>, array<string, string>): string)|null $transport
     */
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $username = '',
        #[\SensitiveParameter]
        private readonly string $password = '',
        ?callable $transport = null,
        private readonly int $pageSize = self::PAGE_SIZE
    ) {
        $this->transport = $transport ?? static fn (string $url, array $query, array $headers): string
            => Http::get($url, $query, $headers, RegistryContext::class);
    }

    /**
     * Requêtes envoyées par ce client (compte rendu).
     */
    public function requests(): int
    {
        return $this->requests;
    }

    public function hasCredentials(): bool
    {
        return $this->username !== '';
    }

    /**
     * Projets visibles par le compte.
     *
     * @return Page
     * @throws RegistryException
     */
    public function projects(): array
    {
        return $this->paginate(self::API . '/projects', []);
    }

    /**
     * Dépôts d'un projet (« name » : « projet/dépôt »).
     *
     * @return Page
     * @throws RegistryException
     */
    public function repositories(string $project): array
    {
        return $this->paginate(self::API . '/projects/' . self::encodeProject($project) . '/repositories', []);
    }

    /**
     * Artefacts d'un dépôt, avec leurs tags et le résumé de leur analyse.
     *
     * @param string $repository Nom du dépôt dans le projet (« api », « anciens/portail »).
     * @return Page
     * @throws RegistryException
     */
    public function artifacts(string $project, string $repository): array
    {
        $path = self::API . '/projects/' . self::encodeProject($project)
            . '/repositories/' . self::encodeRepository($repository) . '/artifacts';

        return $this->paginate($path, [
            'with_tag'           => 'true',
            'with_scan_overview' => 'true',
            'with_label'         => 'false',
        ], ['X-Accept-Vulnerabilities' => self::REPORT_MIME_TYPES]);
    }

    public static function encodeProject(string $project): string
    {
        return rawurlencode($project);
    }

    /**
     * Nom de dépôt encodé deux fois : Harbor décode une fois le chemin, puis le nom du dépôt.
     */
    public static function encodeRepository(string $repository): string
    {
        return rawurlencode(rawurlencode($repository));
    }

    /**
     * @param array<string, scalar> $query
     * @param array<string, string> $headers
     * @return Page
     * @throws RegistryException
     */
    private function paginate(string $path, array $query, array $headers = []): array
    {
        $items = [];
        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $data = $this->get($path, ['page' => $page, 'page_size' => $this->pageSize] + $query, $headers);
            if (!array_is_list($data)) {
                throw new RegistryException(RegistryException::UNEXPECTED);
            }
            foreach ($data as $item) {
                if (is_array($item)) {
                    $items[] = $item;
                }
            }
            if (count($data) < $this->pageSize) {
                return ['items' => $items, 'complete' => true];
            }
        }

        return ['items' => $items, 'complete' => false];
    }

    /**
     * @param array<string, scalar> $query
     * @param array<string, string> $headers
     * @return array<mixed>
     * @throws RegistryException
     */
    private function get(string $path, array $query, array $headers): array
    {
        $headers['Accept'] = 'application/json';
        if ($this->username !== '') {
            $headers['Authorization'] = 'Basic ' . base64_encode($this->username . ':' . $this->password);
        }
        $this->requests++;
        try {
            $body = ($this->transport)(rtrim($this->baseUrl, '/') . $path, $query, $headers);
        } catch (\Throwable $e) {
            throw RegistryException::fromThrowable($e);
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            // Harbor répond « null » pour une liste vide dans certaines versions.
            if (trim($body) === 'null') {
                return [];
            }
            throw new RegistryException(RegistryException::UNEXPECTED);
        }

        return $data;
    }
}

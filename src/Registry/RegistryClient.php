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

namespace GlpiPlugin\Dockerinventory\Registry;

use GlpiPlugin\Dockerinventory\Compat\Http;

/**
 * Lecture seule de la liste des tags d'un dépôt d'images, uniquement par des requêtes GET :
 * - Docker Hub : GET /v2/repositories/<espace>/<dépôt>/tags (anonyme ; empreinte de chaque tag),
 *   au plus MAX_HUB_PAGES pages de 100 tags, les plus récents d'abord ;
 * - Harbor (API v2 des registres) : GET /v2/<projet>/<dépôt>/tags/list, authentification basique
 *   facultative (compte robot en lecture seule), et GET /v2/ pour « Tester la connexion ».
 *
 * Authentification par jeton du registre (https://distribution.github.io/distribution/spec/auth/token/) :
 * sur un 401 portant le défi « WWW-Authenticate: Bearer realm=…,service=…,scope=… », un jeton est
 * demandé au « realm » (GET avec service et scope, « repository:<dépôt>:pull » si le défi n'en donne
 * pas), anonymement sans compte (projets publics de Harbor), avec le compte robot sinon, puis la
 * requête est rejouée une seule fois avec « Authorization: Bearer <jeton> ». Le compte n'est envoyé
 * au « realm » que s'il est sur le même serveur (schéma, hôte, port) que le registre.
 *
 * L'adresse de Docker Hub se change par la constante PLUGIN_DOCKERINVENTORY_DOCKER_HUB_URL
 * (config/local_define.php : miroir, tests). Le transport est injectable (tests sans réseau) ; par
 * défaut Compat\Http::get(), qui passe par le client HTTP de GLPI (proxy, réseaux privés refusés en
 * GLPI 12 sauf contexte autorisé) et ne suit aucune redirection.
 */
final class RegistryClient
{
    public const KIND_DOCKER_HUB = 'dockerhub';
    public const KIND_HARBOR = 'harbor';

    public const DOCKER_HUB_URL = 'https://hub.docker.com';

    /** Pages de 100 tags lues au plus par image sur Docker Hub (limite de requêtes anonymes). */
    public const MAX_HUB_PAGES = 3;

    /** @var callable(string, array<string, scalar>, array<string, string>): string */
    private $transport;

    private int $requests = 0;

    /** @var array<string, string> Jetons Bearer obtenus, par « realm », service et portée. */
    private array $tokens = [];

    /**
     * @param (callable(string, array<string, scalar>, array<string, string>): string)|null $transport
     */
    private function __construct(
        private readonly string $kind,
        private readonly string $baseUrl,
        private readonly string $username = '',
        #[\SensitiveParameter]
        private readonly string $password = '',
        ?callable $transport = null
    ) {
        $this->transport = $transport ?? static fn (string $url, array $query, array $headers): string
            => Http::get($url, $query, $headers, RegistryContext::class);
    }

    /**
     * @param (callable(string, array<string, scalar>, array<string, string>): string)|null $transport
     */
    public static function dockerHub(?string $baseUrl = null, ?callable $transport = null): self
    {
        return new self(self::KIND_DOCKER_HUB, rtrim($baseUrl ?? self::dockerHubUrl(), '/'), '', '', $transport);
    }

    /**
     * @param (callable(string, array<string, scalar>, array<string, string>): string)|null $transport
     */
    public static function harbor(
        string $baseUrl,
        string $username = '',
        #[\SensitiveParameter]
        string $password = '',
        ?callable $transport = null
    ): self {
        return new self(self::KIND_HARBOR, rtrim($baseUrl, '/'), $username, $password, $transport);
    }

    /**
     * Adresse de Docker Hub : la constante PLUGIN_DOCKERINVENTORY_DOCKER_HUB_URL si elle est définie.
     */
    public static function dockerHubUrl(): string
    {
        $constant = 'PLUGIN_DOCKERINVENTORY_DOCKER_HUB_URL';
        $url = defined($constant) ? constant($constant) : '';

        return is_string($url) && $url !== '' ? $url : self::DOCKER_HUB_URL;
    }

    public function kind(): string
    {
        return $this->kind;
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
     * Harbor : GET /v2/ (le registre répond 200 si le compte est accepté, 401 sinon).
     */
    public function ping(): void
    {
        $this->get('/v2/', []);
    }

    /**
     * Tags du dépôt (« library/postgres », « boutique/api »), empreinte vide si le registre ne la
     * donne pas (Harbor).
     *
     * @return list<array{name: string, digest: string}>
     * @throws RegistryException
     */
    public function tags(string $repository): array
    {
        $path = self::encodePath($repository);
        if ($path === '') {
            throw new RegistryException(RegistryException::NOT_FOUND);
        }

        return $this->kind === self::KIND_DOCKER_HUB ? $this->hubTags($path) : $this->harborTags($path);
    }

    /**
     * @return list<array{name: string, digest: string}>
     */
    private function hubTags(string $path): array
    {
        $tags = [];
        for ($page = 1; $page <= self::MAX_HUB_PAGES; $page++) {
            $data = $this->get('/v2/repositories/' . $path . '/tags', [
                'page'      => $page,
                'page_size' => 100,
                'ordering'  => 'last_updated',
            ]);
            $results = $data['results'] ?? null;
            if (!is_array($results) || !array_is_list($results)) {
                throw new RegistryException(RegistryException::UNEXPECTED);
            }
            foreach ($results as $result) {
                if (!is_array($result) || !is_string($result['name'] ?? null) || $result['name'] === '') {
                    continue;
                }
                $digest = $result['digest'] ?? '';
                $tags[] = ['name' => $result['name'], 'digest' => is_string($digest) ? $digest : ''];
            }
            if ($results === [] || !is_string($data['next'] ?? null) || $data['next'] === '') {
                break;
            }
        }

        return $tags;
    }

    /**
     * @return list<array{name: string, digest: string}>
     */
    private function harborTags(string $path): array
    {
        $data = $this->get('/v2/' . $path . '/tags/list', [], $path);
        $list = $data['tags'] ?? null;
        if ($list === null) {
            // Dépôt sans tag : la spécification autorise « tags: null ».
            return array_key_exists('tags', $data) ? [] : throw new RegistryException(RegistryException::UNEXPECTED);
        }
        if (!is_array($list) || !array_is_list($list)) {
            throw new RegistryException(RegistryException::UNEXPECTED);
        }
        $tags = [];
        foreach ($list as $name) {
            if (is_string($name) && $name !== '') {
                $tags[] = ['name' => $name, 'digest' => ''];
            }
        }

        return $tags;
    }

    /**
     * « library/postgres » => « library/postgres », chaque segment encodé ; vide si le nom n'est
     * pas un nom de dépôt.
     */
    public static function encodePath(string $repository): string
    {
        $repository = strtolower(trim($repository, " /\t\n"));
        if ($repository === '' || preg_match('#^[a-z0-9]+(?:[._/-][a-z0-9]+|__[a-z0-9]+)*$#', $repository) !== 1) {
            return '';
        }

        return implode('/', array_map('rawurlencode', explode('/', $repository)));
    }

    /**
     * @param array<string, scalar> $query
     * @param string $repository Dépôt lu (portée du jeton Bearer), vide pour GET /v2/.
     * @return array<mixed>
     * @throws RegistryException
     */
    private function get(string $path, array $query, string $repository = ''): array
    {
        $url = $this->baseUrl . $path;
        $headers = ['Accept' => 'application/json'];
        if ($this->username !== '') {
            $headers['Authorization'] = 'Basic ' . base64_encode($this->username . ':' . $this->password);
        }
        try {
            $body = $this->send($url, $query, $headers);
        } catch (\Throwable $e) {
            $challenge = $this->kind === self::KIND_HARBOR
                ? self::bearerChallenge(Http::errorHeader($e, 'WWW-Authenticate'))
                : null;
            if ($challenge === null) {
                throw RegistryException::fromThrowable($e);
            }
            $headers['Authorization'] = 'Bearer ' . $this->token($challenge, $repository);
            try {
                $body = $this->send($url, $query, $headers);
            } catch (\Throwable $retry) {
                throw RegistryException::fromThrowable($retry);
            }
        }
        if (trim($body) === '' && $path === '/v2/') {
            return [];
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new RegistryException(RegistryException::UNEXPECTED);
        }

        return $data;
    }

    /**
     * @param array<string, scalar> $query
     * @param array<string, string> $headers
     */
    private function send(string $url, array $query, array $headers): string
    {
        $this->requests++;

        return ($this->transport)($url, $query, $headers);
    }

    /**
     * Paramètres d'un défi « Bearer realm="…",service="…",scope="…" » ; null si ce n'est pas un défi
     * Bearer ou si le « realm » n'est pas une adresse http(s).
     *
     * @return array{realm: string, service: string, scope: string}|null
     */
    public static function bearerChallenge(string $header): ?array
    {
        if (preg_match('/^\s*Bearer\s+(.+)$/is', $header, $m) !== 1) {
            return null;
        }
        preg_match_all('/([A-Za-z_]+)\s*=\s*(?:"([^"]*)"|([^\s,]+))/', $m[1], $pairs, PREG_SET_ORDER);
        $params = [];
        foreach ($pairs as $pair) {
            $params[strtolower($pair[1])] = ($pair[2] ?? '') !== '' ? $pair[2] : ($pair[3] ?? '');
        }
        $realm = $params['realm'] ?? '';
        $scheme = strtolower((string) parse_url($realm, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true) || (string) parse_url($realm, PHP_URL_HOST) === '') {
            return null;
        }

        return ['realm' => $realm, 'service' => $params['service'] ?? '', 'scope' => $params['scope'] ?? ''];
    }

    /**
     * Jeton Bearer demandé au « realm » du défi (réponse JSON « token » ou « access_token »).
     *
     * @param array{realm: string, service: string, scope: string} $challenge
     * @throws RegistryException
     */
    private function token(array $challenge, string $repository): string
    {
        $scope = $challenge['scope'] !== '' || $repository === ''
            ? $challenge['scope']
            : 'repository:' . $repository . ':pull';
        $key = $challenge['realm'] . "
" . $challenge['service'] . "
" . $scope;
        if (isset($this->tokens[$key])) {
            return $this->tokens[$key];
        }
        $query = array_filter(['service' => $challenge['service'], 'scope' => $scope], static fn (string $v): bool
            => $v !== '');
        $headers = ['Accept' => 'application/json'];
        if ($this->username !== '' && self::sameOrigin($challenge['realm'], $this->baseUrl)) {
            $headers['Authorization'] = 'Basic ' . base64_encode($this->username . ':' . $this->password);
        }
        try {
            $body = $this->send($challenge['realm'], $query, $headers);
        } catch (\Throwable $e) {
            throw RegistryException::fromThrowable($e);
        }
        $data = json_decode($body, true);
        $token = is_array($data) ? ($data['token'] ?? $data['access_token'] ?? null) : null;
        if (!is_string($token) || $token === '') {
            throw new RegistryException(RegistryException::UNEXPECTED);
        }

        return $this->tokens[$key] = $token;
    }

    private static function sameOrigin(string $a, string $b): bool
    {
        $origin = static function (string $url): string {
            $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
            $port = parse_url($url, PHP_URL_PORT) ?? ($scheme === 'https' ? 443 : 80);

            return $scheme . '://' . strtolower((string) parse_url($url, PHP_URL_HOST)) . ':' . $port;
        };

        return $origin($a) === $origin($b);
    }
}

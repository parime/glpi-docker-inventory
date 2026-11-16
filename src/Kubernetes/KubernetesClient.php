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

namespace GlpiPlugin\Dockerinventory\Kubernetes;

use GlpiPlugin\Dockerinventory\Compat\Http;

/**
 * Lecture seule de l'API d'un cluster Kubernetes, uniquement par des requêtes GET, avec le jeton
 * d'un compte de service (en-tête « Authorization: Bearer ») :
 * - GET /version : version du cluster ;
 * - GET /api/v1/{namespaces,nodes,pods} ;
 * - GET /apis/apps/v1/{deployments,statefulsets,daemonsets,replicasets} ;
 * - GET /apis/batch/v1/cronjobs.
 * Listes de tout le cluster (ClusterRole get/list/watch), paginées par l'API (limit, continue) : au
 * plus MAX_PAGES pages par liste, au-delà la liste est « incomplète » et rien n'en est retiré.
 *
 * Les erreurs sont des KubernetesException (codes stables, jamais le texte d'origine ni le jeton).
 * Transport injectable (tests sans réseau) ; par défaut le client HTTP de GLPI, sans redirection,
 * contexte Kubernetes (réseaux privés en GLPI 12), avec le certificat d'autorité de la connexion
 * s'il est renseigné. Aucun « watch » n'est ouvert : chaque lecture est une liste ponctuelle.
 *
 * @phpstan-type Page array{items: list<array<string, mixed>>, complete: bool}
 */
final class KubernetesClient
{
    public const PAGE_SIZE = 500;

    public const MAX_PAGES = 40;

    /** Listes lues par l'import, dans l'ordre : nom de la ressource => chemin de l'API. */
    public const RESOURCES = [
        'namespaces'   => '/api/v1/namespaces',
        'nodes'        => '/api/v1/nodes',
        'pods'         => '/api/v1/pods',
        'replicasets'  => '/apis/apps/v1/replicasets',
        'deployments'  => '/apis/apps/v1/deployments',
        'statefulsets' => '/apis/apps/v1/statefulsets',
        'daemonsets'   => '/apis/apps/v1/daemonsets',
        'cronjobs'     => '/apis/batch/v1/cronjobs',
    ];

    /** @var callable(string, array<string, scalar>, array<string, string>): string */
    private $transport;

    private int $requests = 0;

    /**
     * @param string $caCertificate Certificat d'autorité (PEM) du cluster, vide : autorités du système.
     * @param (callable(string, array<string, scalar>, array<string, string>): string)|null $transport
     */
    public function __construct(
        private readonly string $baseUrl,
        #[\SensitiveParameter]
        private readonly string $token,
        string $caCertificate = '',
        ?callable $transport = null,
        private readonly int $pageSize = self::PAGE_SIZE
    ) {
        $this->transport = $transport ?? static function (
            string $url,
            array $query,
            array $headers
        ) use (
            $caCertificate
        ): string {
            return Http::get($url, $query, $headers, KubernetesContext::class, 20, self::caFile($caCertificate));
        };
    }

    /**
     * Requêtes envoyées par ce client (compte rendu).
     */
    public function requests(): int
    {
        return $this->requests;
    }

    /**
     * Version du cluster (« v1.31.4 »), vide si la réponse n'en donne pas.
     *
     * @throws KubernetesException
     */
    public function version(): string
    {
        $data = $this->get('/version', []);

        return is_string($data['gitVersion'] ?? null) ? mb_substr($data['gitVersion'], 0, 64) : '';
    }

    /**
     * Liste complète d'une ressource du cluster (toutes les pages).
     *
     * @param key-of<self::RESOURCES> $resource
     * @return Page
     * @throws KubernetesException
     */
    public function list(string $resource): array
    {
        $path = self::RESOURCES[$resource];
        $items = [];
        $continue = '';
        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $query = ['limit' => $this->pageSize] + ($continue !== '' ? ['continue' => $continue] : []);
            $data = $this->get($path, $query);
            if (!array_key_exists('items', $data) || !is_array($data['items'] ?? [])) {
                throw new KubernetesException(KubernetesException::UNEXPECTED);
            }
            foreach ($data['items'] ?? [] as $item) {
                if (is_array($item)) {
                    $items[] = $item;
                }
            }
            $metadata = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
            $continue = is_string($metadata['continue'] ?? null) ? $metadata['continue'] : '';
            if ($continue === '') {
                return ['items' => $items, 'complete' => true];
            }
        }

        return ['items' => $items, 'complete' => false];
    }

    /**
     * Le compte peut-il lister cette ressource ? Une seule requête (limit=1).
     *
     * @param key-of<self::RESOURCES> $resource
     * @throws KubernetesException Toute erreur autre qu'un refus (403).
     */
    public function canList(string $resource): bool
    {
        try {
            $this->get(self::RESOURCES[$resource], ['limit' => 1]);
        } catch (KubernetesException $e) {
            if ($e->getMessage() === KubernetesException::FORBIDDEN) {
                return false;
            }
            throw $e;
        }

        return true;
    }

    /**
     * Fichier temporaire du certificat d'autorité (public, nommé d'après son empreinte), ou null.
     */
    public static function caFile(string $caCertificate): ?string
    {
        $pem = trim($caCertificate);
        if ($pem === '') {
            return null;
        }
        $dir = defined('GLPI_TMP_DIR') ? (string) constant('GLPI_TMP_DIR') : sys_get_temp_dir();
        $file = $dir . '/dockerinventory-k8s-ca-' . hash('sha256', $pem) . '.pem';
        if (!is_file($file)) {
            file_put_contents($file, $pem . "\n", LOCK_EX);
        }

        return $file;
    }

    /**
     * @param array<string, scalar> $query
     * @return array<string, mixed>
     * @throws KubernetesException
     */
    private function get(string $path, array $query): array
    {
        $headers = [
            'Accept'        => 'application/json',
            'Authorization' => 'Bearer ' . $this->token,
        ];
        $this->requests++;
        try {
            $body = ($this->transport)(rtrim($this->baseUrl, '/') . $path, $query, $headers);
        } catch (KubernetesException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw KubernetesException::fromThrowable($e);
        }
        $data = json_decode($body, true);
        if (!is_array($data) || (array_is_list($data) && $data !== [])) {
            throw new KubernetesException(KubernetesException::UNEXPECTED);
        }

        return $data;
    }
}

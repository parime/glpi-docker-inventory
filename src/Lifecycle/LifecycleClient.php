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

namespace GlpiPlugin\Dockerinventory\Lifecycle;

use GlpiPlugin\Dockerinventory\Compat\Http;

/**
 * Lecture seule des cycles de vie d'un produit dans une source publique ouverte (endoflife.date) :
 * un GET par produit, sans authentification ; seul le nom du produit est envoyé (jamais le nom
 * d'un service, d'une image privée ou d'un hôte).
 *
 * Formats lus (lecture défensive, champ inconnu ignoré) :
 * - API v1 : {"result": {"releases": [{"name", "codename", "isEol", "eolFrom", "latest": {"name"}}]}} ;
 * - ancienne API : [{"cycle", "codename", "eol" (date ou booléen), "latest"}].
 *
 * L'adresse est un modèle avec {product}, changeable par la constante
 * PLUGIN_DOCKERINVENTORY_LIFECYCLE_URL (config/local_define.php : miroir interne, tests). Le
 * transport est injectable (tests sans réseau) ; par défaut Compat\Http::get() (client HTTP de
 * GLPI : proxy, réseaux privés refusés en GLPI 12 sauf contexte autorisé, aucune redirection).
 *
 * @phpstan-type Cycle array{cycle: string, codename: string, eol: string, latest: string}
 */
final class LifecycleClient
{
    public const DEFAULT_URL = 'https://endoflife.date/api/v1/products/{product}/';

    /** Valeur de « eol » : fin de support atteinte, date non donnée par la source. */
    public const EOL_WITHOUT_DATE = 'yes';

    /** @var callable(string, array<string, scalar>, array<string, string>): string */
    private $transport;

    private int $requests = 0;

    /**
     * @param (callable(string, array<string, scalar>, array<string, string>): string)|null $transport
     */
    public function __construct(private readonly string $urlTemplate = '', ?callable $transport = null)
    {
        $this->transport = $transport ?? static fn (string $url, array $query, array $headers): string
            => Http::get($url, $query, $headers, LifecycleContext::class);
    }

    /**
     * Modèle d'adresse : la constante PLUGIN_DOCKERINVENTORY_LIFECYCLE_URL si elle est définie.
     */
    public static function configuredUrl(): string
    {
        $constant = 'PLUGIN_DOCKERINVENTORY_LIFECYCLE_URL';
        $url = defined($constant) ? constant($constant) : '';

        return is_string($url) && $url !== '' ? $url : self::DEFAULT_URL;
    }

    /**
     * Adresse lue pour un produit (« {product} » remplacé, ou « /<produit>.json » ajouté au modèle
     * qui n'en contient pas).
     */
    public static function productUrl(string $template, string $product): string
    {
        $template = $template === '' ? self::configuredUrl() : $template;
        $encoded = rawurlencode($product);

        return str_contains($template, '{product}')
            ? str_replace('{product}', $encoded, $template)
            : rtrim($template, '/') . '/' . $encoded . '.json';
    }

    /**
     * Requêtes envoyées par ce client (compte rendu).
     */
    public function requests(): int
    {
        return $this->requests;
    }

    /**
     * Cycles de vie du produit (« postgresql », « nodejs »), dans l'ordre de la source.
     *
     * @return list<Cycle>
     * @throws LifecycleException
     */
    public function cycles(string $product): array
    {
        if (!ProductMap::isValidProduct($product)) {
            throw new LifecycleException(LifecycleException::NOT_FOUND);
        }
        $this->requests++;
        try {
            $body = ($this->transport)(
                self::productUrl($this->urlTemplate, $product),
                [],
                ['Accept' => 'application/json']
            );
        } catch (\Throwable $e) {
            throw LifecycleException::fromThrowable($e);
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new LifecycleException(LifecycleException::UNEXPECTED);
        }

        return self::parse($data);
    }

    /**
     * @param array<mixed> $data Réponse décodée (API v1 ou ancienne API).
     * @return list<Cycle>
     * @throws LifecycleException
     */
    public static function parse(array $data): array
    {
        $isV1 = isset($data['result']) && is_array($data['result']);
        $releases = $isV1 ? ($data['result']['releases'] ?? null) : $data;
        if (!is_array($releases) || !array_is_list($releases)) {
            throw new LifecycleException(LifecycleException::UNEXPECTED);
        }
        $cycles = [];
        foreach ($releases as $release) {
            if (!is_array($release)) {
                continue;
            }
            $name = self::str($release[$isV1 ? 'name' : 'cycle'] ?? null);
            if ($name === '') {
                continue;
            }
            if ($isV1) {
                $eol = self::date($release['eolFrom'] ?? null);
                if ($eol === '' && ($release['isEol'] ?? false) === true) {
                    $eol = self::EOL_WITHOUT_DATE;
                }
                $latest = is_array($release['latest'] ?? null) ? self::str($release['latest']['name'] ?? null) : '';
            } else {
                $value = $release['eol'] ?? null;
                $eol = $value === true ? self::EOL_WITHOUT_DATE : self::date($value);
                $latest = self::str($release['latest'] ?? null);
            }
            $cycles[] = [
                'cycle'    => mb_substr($name, 0, 64),
                'codename' => mb_substr(self::str($release['codename'] ?? null), 0, 64),
                'eol'      => $eol,
                'latest'   => mb_substr($latest, 0, 64),
            ];
        }

        return $cycles;
    }

    private static function str(mixed $value): string
    {
        return is_string($value) || is_int($value) ? trim((string) $value) : '';
    }

    /**
     * « 2026-11-13 » (heure éventuelle ignorée), chaîne vide sinon.
     */
    private static function date(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^(\d{4}-\d{2}-\d{2})/', $value, $m) !== 1) {
            return '';
        }
        [$y, $mo, $d] = array_map('intval', explode('-', $m[1]));

        return checkdate($mo, $d, $y) ? $m[1] : '';
    }
}

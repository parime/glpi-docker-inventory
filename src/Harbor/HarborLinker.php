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

use GlpiPlugin\Dockerinventory\Wud\WudMapper;

/**
 * Rattachement d'une image déployée à un artefact Harbor, et provenance de l'image (registre
 * maîtrisé ou non). Fonctions pures, sans GLPI.
 *
 * Rattachement, parmi les artefacts actifs du dépôt de l'image (même connexion Harbor) :
 * 1. par l'empreinte : celle de l'image déployée est celle de l'artefact, ou de l'une de ses images
 *    par plateforme (index multi-architecture) ; c'est la seule preuve que l'image analysée est
 *    celle qui tourne ;
 * 2. sinon par le tag, si l'empreinte déployée est inconnue (service non épinglé) ;
 * 3. empreinte connue mais absente du dépôt, tag présent : « tag déplacé » (l'image a été
 *    reconstruite depuis le déploiement) ; l'analyse affichée est celle du tag actuel, pas celle de
 *    l'image qui tourne : elle ne déclenche aucun ticket.
 *
 * @phpstan-type Candidate array{id: int, digest: string, child_digests: list<string>, tags: list<string>}
 */
final class HarborLinker
{
    public const METHOD_DIGEST = 'digest';
    public const METHOD_TAG = 'tag';
    public const METHOD_TAG_MOVED = 'tag_moved';

    public const PROVENANCE_MANAGED = 'managed';
    public const PROVENANCE_UNMANAGED = 'unmanaged';
    /** Aucun registre maîtrisé déclaré : provenance non évaluée. */
    public const PROVENANCE_UNKNOWN = 'unknown';

    /**
     * @param list<Candidate> $artifacts Artefacts actifs du dépôt de l'image.
     * @return array{id: int, method: string}|null
     */
    public static function match(string $digest, string $tag, array $artifacts): ?array
    {
        $digest = strtolower(trim($digest));
        if ($digest !== '') {
            foreach ($artifacts as $artifact) {
                if ($artifact['digest'] === $digest || in_array($digest, $artifact['child_digests'], true)) {
                    return ['id' => $artifact['id'], 'method' => self::METHOD_DIGEST];
                }
            }
        }
        if ($tag === '') {
            return null;
        }
        foreach ($artifacts as $artifact) {
            if (in_array($tag, $artifact['tags'], true)) {
                return [
                    'id'     => $artifact['id'],
                    'method' => $digest === '' ? self::METHOD_TAG : self::METHOD_TAG_MOVED,
                ];
            }
        }

        return null;
    }

    /**
     * Hôte du registre d'une image (« docker.io » pour Docker Hub, « registry.exemple.test:5000 »).
     */
    public static function imageHost(string $image): string
    {
        $key = WudMapper::referenceKey($image);
        $slash = strpos($key, '/');

        return $slash === false ? '' : substr($key, 0, $slash);
    }

    /**
     * L'image vient-elle de l'un des registres maîtrisés ?
     *
     * @param list<string> $managedHosts Hôtes tels qu'écrits dans les images, en minuscules.
     */
    public static function provenance(string $image, array $managedHosts): string
    {
        if ($managedHosts === [] || trim($image) === '') {
            return self::PROVENANCE_UNKNOWN;
        }

        return in_array(self::imageHost($image), $managedHosts, true)
            ? self::PROVENANCE_MANAGED
            : self::PROVENANCE_UNMANAGED;
    }

    /**
     * Liste d'hôtes saisie (une par ligne, ou séparée par des virgules ou des espaces) => hôtes
     * valides, en minuscules, sans schéma ni chemin, sans doublon. « hub.docker.com » et
     * « index.docker.io » désignent Docker Hub (« docker.io »).
     *
     * @return list<string>
     */
    public static function parseHosts(string $text): array
    {
        $hosts = [];
        foreach (preg_split('/[\s,;]+/', strtolower($text)) ?: [] as $host) {
            $host = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $host);
            $host = explode('/', $host)[0];
            if ($host === '' || preg_match('#^[a-z0-9.-]+(:\d{1,5})?$#', $host) !== 1) {
                continue;
            }
            if (in_array($host, WudMapper::DOCKER_HUB_HOSTS, true) || $host === 'hub.docker.com') {
                $host = 'docker.io';
            }
            $hosts[$host] = true;
        }

        return array_keys($hosts);
    }
}

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

namespace GlpiPlugin\Dockerinventory\Version;

use GlpiPlugin\Dockerinventory\Wud\WudMapper;

/**
 * Rattachement des conteneurs vus par WUD aux services importés de Portainer (services Swarm,
 * services Compose et conteneurs des hôtes autonomes), puis une ligne de version par service.
 * Fonctions pures, sans GLPI.
 *
 * Ordre de rattachement d'un conteneur :
 * 0. identifiant ou nom du conteneur, connus pour les services des hôtes autonomes (un conteneur
 *    Swarm n'est pas lu un par un : ses services n'en ont pas) ;
 * 1. empreinte de l'image identique à celle du service (épinglée par Swarm) ;
 * 2. nom du service Swarm porté par le conteneur (étiquette com.docker.swarm.service.name, ou nom de
 *    tâche « service.emplacement.tâche ») ;
 * 3. image et tag identiques.
 * À chaque étape, plusieurs services possibles sont départagés par les critères suivants ; s'il en
 * reste plusieurs, le conteneur n'est rattaché à aucun (« ambigu ») plutôt qu'au mauvais.
 *
 * @phpstan-import-type WudContainer from WudMapper
 * @phpstan-type LinkService array{id: int, name: string, image: string, tag: string, digest: string,
 *     container_uids?: list<string>, container_names?: list<string>}
 */
final class VersionLinker
{
    public const METHOD_CONTAINER = 'container';
    public const METHOD_DIGEST = 'digest';
    public const METHOD_LABEL = 'label';
    public const METHOD_IMAGE = 'image';

    public const UNMATCHED_AMBIGUOUS = 'ambiguous';
    public const UNMATCHED_OUTSIDE_SWARM = 'outside_swarm';
    public const UNMATCHED_NO_SERVICE = 'no_service';

    /** Méthodes de la plus sûre à la moins sûre. */
    private const METHODS = [self::METHOD_CONTAINER, self::METHOD_DIGEST, self::METHOD_LABEL, self::METHOD_IMAGE];

    /**
     * @param list<WudContainer> $containers
     * @param list<LinkService> $services Services actifs que la connexion WUD peut couvrir.
     * @return array{linked: array<int, list<WudContainer>>,
     *     unmatched: list<WudContainer>}
     */
    public static function link(array $containers, array $services): array
    {
        $linked = [];
        $unmatched = [];
        $seen = [];
        foreach ($containers as $container) {
            if (isset($seen[$container['uid']])) {
                continue;
            }
            $seen[$container['uid']] = true;
            [$serviceId, $method, $ambiguous] = self::match($container, $services);
            if ($serviceId !== null) {
                $linked[$serviceId][] = $container + ['method' => $method];
                continue;
            }
            $reason = match (true) {
                $ambiguous                       => self::UNMATCHED_AMBIGUOUS,
                $container['is_swarm'] === false => self::UNMATCHED_OUTSIDE_SWARM,
                default                          => self::UNMATCHED_NO_SERVICE,
            };
            $unmatched[] = $container + ['reason' => $reason];
        }
        ksort($linked);

        return ['linked' => $linked, 'unmatched' => $unmatched];
    }

    /**
     * @param WudContainer $container
     * @param list<LinkService> $services
     * @return array{0: int|null, 1: string, 2: bool} Service, méthode, ambiguïté rencontrée.
     */
    private static function match(array $container, array $services): array
    {
        $ambiguous = false;
        $byContainer = array_filter(
            $services,
            static fn (array $s): bool => in_array($container['uid'], $s['container_uids'] ?? [], true)
                || in_array($container['name'], $s['container_names'] ?? [], true)
        );
        $byDigest = $container['digest'] === '' ? [] : array_filter(
            $services,
            static fn (array $s): bool => $s['digest'] !== '' && $s['digest'] === $container['digest']
        );
        $byName = $container['service_name'] === '' ? [] : array_filter(
            $services,
            static fn (array $s): bool => $s['name'] === $container['service_name']
        );
        $byImage = array_filter(
            $services,
            static fn (array $s): bool
                => WudMapper::sameImage(WudMapper::referenceKey($s['image']), $container['image_key'])
        );
        $byImageTag = array_filter(
            $byImage,
            static fn (array $s): bool => $s['tag'] === $container['tag']
        );

        $steps = [
            self::METHOD_CONTAINER => [$byContainer, [$byDigest, $byImageTag]],
            self::METHOD_DIGEST => [$byDigest, [$byName, $byImageTag]],
            self::METHOD_LABEL  => [$byName, [$byImage, $byImageTag]],
            self::METHOD_IMAGE  => [$byImageTag, []],
        ];
        foreach ($steps as $method => [$candidates, $tieBreakers]) {
            if (count($candidates) === 1) {
                return [(int) current($candidates)['id'], $method, $ambiguous];
            }
            if (count($candidates) < 2) {
                continue;
            }
            foreach ($tieBreakers as $tieBreaker) {
                $narrowed = array_intersect_key($candidates, $tieBreaker);
                if (count($narrowed) === 1) {
                    return [(int) current($narrowed)['id'], $method, $ambiguous];
                }
            }
            $ambiguous = true;
        }

        return [null, '', $ambiguous];
    }

    /**
     * Ligne de version d'un service à partir de ses conteneurs : celui qui correspond au service
     * (même empreinte, sinon même tag, sinon la version la plus répandue) donne les valeurs ; des
     * conteneurs dans des versions différentes (mise à jour en cours ou bloquée) sont signalés.
     *
     * @param LinkService $service
     * @param non-empty-list<WudContainer> $containers
     * @return array{deployed_tag: string, deployed_digest: string, latest_tag: string, latest_digest: string,
     *     gap: string, update_available: int, is_out_of_sync: int, deployed_versions: string,
     *     container_count: int, containers: string, match_method: string, image: string, registry_name: string,
     *     registry_url: string, error: string}
     */
    public static function summarize(array $service, array $containers): array
    {
        $versions = [];
        foreach ($containers as $container) {
            $key = $container['tag'] . '@' . $container['digest'];
            $versions[$key] = ($versions[$key] ?? 0) + 1;
        }
        arsort($versions);

        $representative = null;
        foreach ($containers as $container) {
            if ($service['digest'] !== '' && $container['digest'] === $service['digest']) {
                $representative = $container;
                break;
            }
        }
        if ($representative === null) {
            foreach ($containers as $container) {
                if ($container['tag'] === $service['tag']) {
                    $representative = $container;
                    break;
                }
            }
        }
        if ($representative === null) {
            $top = (string) array_key_first($versions);
            foreach ($containers as $container) {
                if ($container['tag'] . '@' . $container['digest'] === $top) {
                    $representative = $container;
                    break;
                }
            }
        }
        $representative ??= $containers[0];

        $methods = array_column($containers, 'method');
        $best = '';
        foreach (self::METHODS as $method) {
            if (in_array($method, $methods, true)) {
                $best = $method;
                break;
            }
        }
        $outOfSync = count($versions) > 1;
        $detail = [];
        if ($outOfSync) {
            foreach ($versions as $key => $count) {
                [$tag, $digest] = explode('@', $key, 2) + [1 => ''];
                $label = $tag !== '' ? $tag : '-';
                if ($digest !== '') {
                    $label .= ' (' . self::shortDigest($digest) . ')';
                }
                $detail[] = sprintf('%s x%d', $label, $count);
            }
        }
        $names = array_column($containers, 'name');
        sort($names);
        $gap = VersionComparator::classify($representative);

        return [
            'deployed_tag'      => $representative['tag'],
            'deployed_digest'   => $representative['digest'],
            'latest_tag'        => $representative['latest_tag'],
            'latest_digest'     => $representative['latest_digest'],
            'gap'               => $gap,
            'update_available'  => (int) ($representative['update_available']
                ?? !in_array($gap, [VersionComparator::GAP_NONE, VersionComparator::GAP_UNKNOWN], true)),
            'is_out_of_sync'    => (int) $outOfSync,
            'deployed_versions' => implode(', ', $detail),
            'container_count'   => count($containers),
            'containers'        => implode(', ', $names),
            'match_method'      => $best,
            'image'             => $representative['image'],
            'registry_name'     => $representative['registry_name'],
            'registry_url'      => $representative['registry_url'],
            'error'             => $representative['error'],
        ];
    }

    /**
     * « sha256:1f1e1d1c1b1a... » => « 1f1e1d1c1b1a ».
     */
    public static function shortDigest(string $digest): string
    {
        $hex = str_contains($digest, ':') ? substr($digest, (int) strpos($digest, ':') + 1) : $digest;

        return substr($hex, 0, 12);
    }
}

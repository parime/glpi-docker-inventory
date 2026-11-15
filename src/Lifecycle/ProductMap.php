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

use GlpiPlugin\Dockerinventory\Wud\WudMapper;

/**
 * Correspondance explicite entre une image et un produit de la source des cycles de vie. Fonctions
 * pures, sans GLPI.
 *
 * Jamais devinée : une image absente de la table (fournie à l'installation, modifiable dans la
 * configuration) reste « inconnue », jamais déclarée maintenue. Les images sont comparées par leur
 * nom normalisé : « postgres », « docker.io/library/postgres » et « library/postgres » donnent
 * « postgres » ; une image d'un autre registre garde son hôte (« registry.exemple.test/outils/api »).
 */
final class ProductMap
{
    /**
     * Correspondances fournies : images officielles courantes de Docker Hub => produit.
     *
     * @var array<string, string>
     */
    public const DEFAULTS = [
        'alpine'               => 'alpine-linux',
        'caddy'                => 'caddy',
        'debian'               => 'debian',
        'drupal'               => 'drupal',
        'eclipse-temurin'      => 'eclipse-temurin',
        'elasticsearch'        => 'elasticsearch',
        'golang'               => 'go',
        'grafana/grafana'      => 'grafana',
        'haproxy'              => 'haproxy',
        'httpd'                => 'apache-http-server',
        'influxdb'             => 'influxdb',
        'jenkins/jenkins'      => 'jenkins',
        'kibana'               => 'kibana',
        'mariadb'              => 'mariadb',
        'memcached'            => 'memcached',
        'mongo'                => 'mongodb',
        'mysql'                => 'mysql',
        'neo4j'                => 'neo4j',
        'nextcloud'            => 'nextcloud',
        'nginx'                => 'nginx',
        'node'                 => 'nodejs',
        'php'                  => 'php',
        'postgres'             => 'postgresql',
        'python'               => 'python',
        'rabbitmq'             => 'rabbitmq',
        'redis'                => 'redis',
        'ruby'                 => 'ruby',
        'solr'                 => 'solr',
        'tomcat'               => 'tomcat',
        'traefik'              => 'traefik',
        'ubuntu'               => 'ubuntu',
        'valkey/valkey'        => 'valkey',
        'wordpress'            => 'wordpress',
    ];

    private const IMAGE = '#^[a-z0-9]+(?:[._-][a-z0-9]+)*(?::\d+)?(?:/[a-z0-9]+(?:(?:[._]|__|-+)[a-z0-9]+)*)*$#';

    private const PRODUCT = '/^[a-z0-9][a-z0-9._-]{0,63}$/';

    /**
     * Nom normalisé d'une image (sans tag ni empreinte), chaîne vide si ce n'est pas un nom d'image.
     */
    public static function normalizeImage(string $image): string
    {
        $image = strtolower(trim($image));
        $image = (string) preg_replace('/@sha256:[0-9a-f]+$/', '', $image);
        $slash = strrpos($image, '/');
        $colon = strrpos($image, ':');
        if ($colon !== false && ($slash === false || $colon > $slash)) {
            // Tag dans la référence (« postgres:16 ») : retiré.
            $image = substr($image, 0, $colon);
        }
        $key = WudMapper::referenceKey($image);
        if (str_starts_with($key, 'docker.io/')) {
            $key = substr($key, strlen('docker.io/'));
            if (str_starts_with($key, 'library/')) {
                $key = substr($key, strlen('library/'));
            }
        }

        return $key !== '' && preg_match(self::IMAGE, $key) === 1 ? $key : '';
    }

    public static function isValidProduct(string $product): bool
    {
        return preg_match(self::PRODUCT, $product) === 1;
    }

    /**
     * Produit d'une image, chaîne vide sans correspondance.
     *
     * @param array<string, string> $map Image normalisée => produit.
     */
    public static function productFor(string $image, array $map): string
    {
        $key = self::normalizeImage($image);

        return $key === '' ? '' : ($map[$key] ?? '');
    }
}

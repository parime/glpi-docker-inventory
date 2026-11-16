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

/**
 * Changement de version d'un service entre deux imports Portainer (#10) : autre image, autre tag,
 * ou même tag avec une autre empreinte (image reconstruite, tag mobile redéployé). Fonctions pures.
 *
 * Une valeur inconnue (image jamais lue, empreinte absente d'un côté) n'est pas un changement : on
 * ne journalise que ce qui est observé des deux côtés.
 */
final class VersionChange
{
    /** Journal des changements, écrit par l'import Portainer (Importer). */
    public const TABLE = 'glpi_plugin_dockerinventory_versionchanges';

    public const KIND_IMAGE = 'image';
    public const KIND_TAG = 'tag';
    public const KIND_DIGEST = 'digest';

    public const KINDS = [self::KIND_IMAGE, self::KIND_TAG, self::KIND_DIGEST];

    /**
     * @param array{image: string, tag: string, digest: string} $old Valeurs du dernier import.
     * @param array{image: string, tag: string, digest: string} $new Valeurs lues maintenant.
     * @return string|null Nature du changement (KIND_*), null si rien n'a changé ou si c'est inconnu.
     */
    public static function detect(array $old, array $new): ?string
    {
        $oldImage = trim($old['image']);
        $newImage = trim($new['image']);
        if ($oldImage === '' || $newImage === '') {
            return null;
        }
        if (strtolower($oldImage) !== strtolower($newImage)) {
            return self::KIND_IMAGE;
        }
        if (trim($old['tag']) !== trim($new['tag'])) {
            return self::KIND_TAG;
        }
        $oldDigest = trim($old['digest']);
        $newDigest = trim($new['digest']);
        if ($oldDigest !== '' && $newDigest !== '' && $oldDigest !== $newDigest) {
            return self::KIND_DIGEST;
        }

        return null;
    }
}

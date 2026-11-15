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

use GlpiPlugin\Dockerinventory\Version\VersionComparator;

/**
 * Branche (cycle) d'un tag déployé et état de son support. Fonctions pures, sans GLPI.
 *
 * Règles :
 * - tag versionné (« 13.4 », « 1.24.0-alpine », « v18 ») : la branche la plus précise dont les
 *   nombres commencent la version (« 1.24 » pour 1.24.0, « 13 » pour 13.4) ; tag moins précis que
 *   les branches (« 7 » quand la source donne 7.2 et 7.4) : la plus récente de ces branches, celle
 *   que suit un tag mobile ;
 * - tag nommé (« bookworm », « bookworm-slim ») : la branche de ce nom de code ;
 * - sinon (« latest », tag inconnu de la source) : état inconnu, jamais « maintenue » ;
 * - fin de support passée ou atteinte aujourd'hui : « en fin de support » ; dans moins de N jours
 *   (réglage) : « bientôt » ; sinon « maintenue » (date future, ou aucune date annoncée).
 *
 * @phpstan-import-type Cycle from LifecycleClient
 * @phpstan-type Evaluation array{status: string, reason: string, product: string, cycle: string,
 *     eol: string, days: int|null, latest: string}
 */
final class CycleMatcher
{
    public const STATUS_EOL = 'eol';
    public const STATUS_SOON = 'soon';
    public const STATUS_SUPPORTED = 'supported';
    public const STATUS_UNKNOWN = 'unknown';

    /** Du plus grave au moins grave (ordre d'affichage des compteurs). */
    public const STATUSES = [self::STATUS_EOL, self::STATUS_SOON, self::STATUS_SUPPORTED, self::STATUS_UNKNOWN];

    /** Raisons d'un état inconnu. */
    public const REASON_DISABLED = 'disabled';
    public const REASON_NO_PRODUCT = 'no_product';
    public const REASON_UNVERSIONED = 'unversioned';
    public const REASON_NOT_CHECKED = 'not_checked';
    public const REASON_PRODUCT_NOT_FOUND = 'product_not_found';
    public const REASON_NO_CYCLE = 'no_cycle';

    /**
     * Branche du tag parmi les cycles du produit, null si aucune ne correspond.
     *
     * @param list<Cycle> $cycles
     * @return Cycle|null
     */
    public static function match(string $tag, array $cycles): ?array
    {
        $version = VersionComparator::parse($tag);
        if ($version === null) {
            return self::matchCodename($tag, $cycles);
        }
        $numbers = $version['numbers'];
        $best = null;
        $bestLength = 0;
        $floating = null;
        foreach ($cycles as $cycle) {
            $cycleNumbers = self::numbers($cycle['cycle']);
            if ($cycleNumbers === null) {
                continue;
            }
            $length = count($cycleNumbers);
            if ($length <= count($numbers) && array_slice($numbers, 0, $length) === $cycleNumbers) {
                if ($length > $bestLength) {
                    $best = $cycle;
                    $bestLength = $length;
                }
            } elseif ($length > count($numbers) && array_slice($cycleNumbers, 0, count($numbers)) === $numbers) {
                // Tag moins précis que la branche (« 7 » pour 7.4) : la plus récente.
                if ($floating === null || self::numbers($floating['cycle']) < $cycleNumbers) {
                    $floating = $cycle;
                }
            }
        }

        return $best ?? $floating;
    }

    /**
     * @param list<Cycle> $cycles
     * @return Cycle|null
     */
    private static function matchCodename(string $tag, array $cycles): ?array
    {
        $name = strtolower(trim($tag));
        $dash = strpos($name, '-');
        if ($dash !== false) {
            // « bookworm-slim » : variante retirée.
            $name = substr($name, 0, $dash);
        }
        if ($name === '' || in_array($name, ['latest', 'stable', 'edge', 'mainline', 'lts'], true)) {
            return null;
        }
        foreach ($cycles as $cycle) {
            if ($cycle['codename'] !== '' && strtolower($cycle['codename']) === $name) {
                return $cycle;
            }
        }

        return null;
    }

    /**
     * @return list<int>|null
     */
    private static function numbers(string $cycle): ?array
    {
        if (preg_match('/^\d+(?:\.\d+)*$/', trim($cycle)) !== 1) {
            return null;
        }

        return array_map('intval', explode('.', trim($cycle)));
    }

    /**
     * État du support d'un tag.
     *
     * @param list<Cycle>|null $cycles Cycles du produit ; null si jamais lus (source non vérifiée).
     * @param string $today « 2026-11-14 ».
     * @param int $warningDays Seuil de « bientôt en fin de support ».
     * @param bool $productNotFound La source ne connaît pas ce produit.
     * @return Evaluation
     */
    public static function evaluate(
        string $product,
        string $tag,
        ?array $cycles,
        string $today,
        int $warningDays,
        bool $productNotFound = false
    ): array {
        $result = ['status' => self::STATUS_UNKNOWN, 'reason' => '', 'product' => $product, 'cycle' => '',
            'eol' => '', 'days' => null, 'latest' => ''];
        if ($product === '') {
            return ['reason' => self::REASON_NO_PRODUCT] + $result;
        }
        if ($productNotFound) {
            return ['reason' => self::REASON_PRODUCT_NOT_FOUND] + $result;
        }
        if ($cycles === null) {
            return ['reason' => self::REASON_NOT_CHECKED] + $result;
        }
        $cycle = self::match($tag, $cycles);
        if ($cycle === null) {
            $reason = VersionComparator::isTrackable($tag) ? self::REASON_NO_CYCLE : self::REASON_UNVERSIONED;

            return ['reason' => $reason] + $result;
        }
        $result['cycle'] = $cycle['cycle'];
        $result['eol'] = $cycle['eol'];
        $result['latest'] = $cycle['latest'];
        if ($cycle['eol'] === LifecycleClient::EOL_WITHOUT_DATE) {
            return ['status' => self::STATUS_EOL] + $result;
        }
        if ($cycle['eol'] === '') {
            // Aucune fin de support annoncée.
            return ['status' => self::STATUS_SUPPORTED] + $result;
        }
        $days = self::daysBetween($today, $cycle['eol']);
        $result['days'] = $days;
        $status = match (true) {
            $days <= 0            => self::STATUS_EOL,
            $days <= $warningDays => self::STATUS_SOON,
            default               => self::STATUS_SUPPORTED,
        };

        return ['status' => $status] + $result;
    }

    /**
     * Jours de $from à $to (négatif si $to est passé), en dates calendaires.
     */
    public static function daysBetween(string $from, string $to): int
    {
        $a = new \DateTimeImmutable(substr($from, 0, 10), new \DateTimeZone('UTC'));
        $b = new \DateTimeImmutable(substr($to, 0, 10), new \DateTimeZone('UTC'));

        return (int) (($b->getTimestamp() - $a->getTimestamp()) / 86400);
    }

    /**
     * Rang de gravité (0 = le plus grave).
     */
    public static function severity(string $status): int
    {
        $rank = array_search($status, self::STATUSES, true);

        return $rank === false ? count(self::STATUSES) : (int) $rank;
    }
}

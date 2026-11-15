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

namespace GlpiPlugin\Dockerinventory\Ticket;

use GlpiPlugin\Dockerinventory\Harbor\HarborLinker;
use GlpiPlugin\Dockerinventory\Harbor\HarborMapper;

/**
 * Déclencheur « vulnérabilité » (TicketPolicy::TRIGGER_VULNERABILITY, lot 3) : faut-il un ticket
 * pour l'image déployée par un service, ou un suivi sur celui déjà ouvert ? Fonctions pures, sans
 * GLPI. Même principe que les tickets de mise à jour et de fin de support :
 * - un ticket seulement si l'analyse Harbor de l'image qui tourne (rattachée par l'empreinte, sinon
 *   par le tag, y compris quand l'empreinte déployée est celle d'un index multi-plateforme absent de
 *   Harbor) a réussi et compte des vulnérabilités critiques (seuil
 *   « critique ») ou critiques et hautes (seuil « haute ») ;
 * - un seul ticket par service : tant qu'il est ouvert, un suivi quand la situation change (autre
 *   image déployée, nombre de vulnérabilités critiques ou hautes différent), jamais un second ;
 * - ticket résolu, clos ou supprimé : nouveau ticket seulement pour une autre image (empreinte) ;
 * - image absente de Harbor, non analysée ou analyse en échec : rien ;
 * - jamais de changement automatique du ticket : seulement des suivis.
 *
 * @phpstan-type VulnSituation array{known: bool, digest: string, critical: int, high: int}
 * @phpstan-type VulnTracked array{digest: string, critical: int, high: int}
 */
final class VulnTicketPolicy
{
    public const THRESHOLD_CRITICAL = 'critical';
    public const THRESHOLD_HIGH = 'high';

    public const THRESHOLDS = [self::THRESHOLD_CRITICAL, self::THRESHOLD_HIGH];

    /**
     * Situation d'un service à partir de l'artefact rattaché (null : image absente de Harbor).
     *
     * @param array{scan_status: string, digest: string, critical: int, high: int}|null $artifact
     * @return VulnSituation
     */
    public static function situation(?array $artifact, string $method): array
    {
        $known = $artifact !== null
            && in_array(
                $method,
                [HarborLinker::METHOD_DIGEST, HarborLinker::METHOD_TAG, HarborLinker::METHOD_TAG_DIGEST_DIFFERS],
                true
            )
            && $artifact['scan_status'] === HarborMapper::STATUS_SUCCESS;

        return [
            'known'    => $known,
            'digest'   => $known ? $artifact['digest'] : '',
            'critical' => $known ? $artifact['critical'] : 0,
            'high'     => $known ? $artifact['high'] : 0,
        ];
    }

    /**
     * Déclencheurs atteints (TicketPolicy::TRIGGER_VULNERABILITY ou rien).
     *
     * @param VulnSituation $situation
     * @return list<string>
     */
    public static function triggers(array $situation, string $threshold): array
    {
        if (!$situation['known']) {
            return [];
        }
        $count = $situation['critical'] + ($threshold === self::THRESHOLD_HIGH ? $situation['high'] : 0);

        return $count > 0 ? [TicketPolicy::TRIGGER_VULNERABILITY] : [];
    }

    /**
     * @param VulnSituation $situation
     * @param VulnTracked|null $tracked Situation au dernier ticket ou suivi, null si jamais de ticket.
     * @param list<string> $triggers
     */
    public static function decide(array $situation, ?array $tracked, bool $ticketOpen, array $triggers): string
    {
        if (!$situation['known']) {
            return TicketPolicy::ACTION_NONE;
        }
        if ($tracked !== null && $ticketOpen) {
            return self::changed($situation, $tracked) ? TicketPolicy::ACTION_FOLLOWUP : TicketPolicy::ACTION_NONE;
        }
        if ($triggers === []) {
            return TicketPolicy::ACTION_NONE;
        }
        if ($tracked !== null && $tracked['digest'] === $situation['digest']) {
            // Ticket fermé pour cette même image : rien de nouveau à signaler.
            return TicketPolicy::ACTION_NONE;
        }

        return TicketPolicy::ACTION_CREATE;
    }

    /**
     * @param VulnSituation $situation
     * @param VulnTracked $tracked
     */
    public static function changed(array $situation, array $tracked): bool
    {
        return $situation['digest'] !== $tracked['digest']
            || $situation['critical'] !== $tracked['critical']
            || $situation['high'] !== $tracked['high'];
    }

    /**
     * Nature du suivi : encore au-delà du seuil, ou plus aucune vulnérabilité au seuil.
     *
     * @param list<string> $triggers
     */
    public static function followupKind(array $triggers): string
    {
        return $triggers !== [] ? 'still_vulnerable' : 'resolved';
    }
}

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

use GlpiPlugin\Dockerinventory\Lifecycle\CycleMatcher;

/**
 * Faut-il un ticket de fin de support pour un service, ou un suivi sur celui déjà ouvert ?
 * Fonctions pures, sans GLPI. Même principe que les tickets de mise à jour (TicketPolicy) :
 * - un ticket seulement si la fin de support de la branche déployée est passée, ou dans moins de N
 *   jours (réglage de la connexion Portainer) ;
 * - un seul ticket par service : tant qu'il est ouvert, un suivi quand la situation change (autre
 *   branche déployée, date de fin de support modifiée par la source), jamais un second ticket ;
 * - ticket résolu, clos ou supprimé : nouveau ticket seulement pour une autre branche ;
 * - état inconnu (pas de correspondance, source non vérifiée...) : rien ;
 * - jamais de changement automatique du ticket : seulement des suivis.
 *
 * @phpstan-type EolSituation array{status: string, product: string, cycle: string, eol: string,
 *     days: int|null}
 * @phpstan-type EolTracked array{product: string, cycle: string, eol: string}
 */
final class EolTicketPolicy
{
    public const DEFAULT_DAYS = 90;

    /**
     * La fin de support est-elle passée, ou dans $thresholdDays jours au plus ?
     *
     * @param EolSituation $situation
     */
    public static function triggered(array $situation, int $thresholdDays): bool
    {
        return match ($situation['status']) {
            CycleMatcher::STATUS_EOL                              => true,
            CycleMatcher::STATUS_SOON, CycleMatcher::STATUS_SUPPORTED
                => $situation['days'] !== null && $situation['days'] <= $thresholdDays,
            default                                               => false,
        };
    }

    /**
     * @param EolSituation $situation
     * @param EolTracked|null $tracked Situation au dernier ticket ou suivi, null si jamais de ticket.
     */
    public static function decide(array $situation, ?array $tracked, bool $ticketOpen, bool $triggered): string
    {
        if ($situation['status'] === CycleMatcher::STATUS_UNKNOWN) {
            return TicketPolicy::ACTION_NONE;
        }
        if ($tracked !== null && $ticketOpen) {
            return self::changed($situation, $tracked) ? TicketPolicy::ACTION_FOLLOWUP : TicketPolicy::ACTION_NONE;
        }
        if (!$triggered) {
            return TicketPolicy::ACTION_NONE;
        }
        if (
            $tracked !== null
            && $tracked['product'] === $situation['product']
            && $tracked['cycle'] === $situation['cycle']
        ) {
            // Ticket fermé pour cette même branche : rien de nouveau à signaler.
            return TicketPolicy::ACTION_NONE;
        }

        return TicketPolicy::ACTION_CREATE;
    }

    /**
     * @param EolSituation $situation
     * @param EolTracked $tracked
     */
    public static function changed(array $situation, array $tracked): bool
    {
        return $situation['product'] !== $tracked['product']
            || $situation['cycle'] !== $tracked['cycle']
            || $situation['eol'] !== $tracked['eol'];
    }

    /**
     * Nature du suivi : toujours en fin de support (ou bientôt), ou branche de nouveau maintenue.
     */
    public static function followupKind(bool $triggered): string
    {
        return $triggered ? 'still_eol' : 'resolved';
    }
}

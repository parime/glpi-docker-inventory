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

use GlpiPlugin\Dockerinventory\Version\VersionComparator;

/**
 * Faut-il un ticket de mise à jour pour un service, ou un suivi sur celui déjà ouvert ? Fonctions
 * pures, sans GLPI.
 *
 * Règles :
 * - un ticket seulement si un déclencheur est atteint (écart de version au-delà du seuil choisi ;
 *   les vulnérabilités ont leur propre ticket, voir TRIGGER_VULNERABILITY) ;
 * - un seul ticket par service : tant qu'il est ouvert, un suivi quand la situation change (nouvelle
 *   version, autre version déployée, écart résolu), jamais un second ticket ;
 * - ticket résolu, clos ou supprimé : nouveau ticket seulement pour une nouvelle dernière version
 *   (celle qui avait été signalée a été traitée, ou volontairement écartée) ;
 * - version non vérifiée (source indisponible) : rien, la situation connue reste la dernière ;
 * - jamais de changement automatique du ticket (statut, priorité, affectation) : seulement des suivis.
 *
 * @phpstan-type Situation array{gap: string, deployed_tag: string, latest_tag: string}
 */
final class TicketPolicy
{
    public const THRESHOLD_MAJOR = 'major';
    public const THRESHOLD_MINOR = 'minor';
    public const THRESHOLD_PATCH = 'patch';

    /** Du plus exigeant au plus large : « mineure » couvre aussi les versions majeures. */
    public const THRESHOLDS = [self::THRESHOLD_MAJOR, self::THRESHOLD_MINOR, self::THRESHOLD_PATCH];

    /** Déclencheur du lot 2 : écart de version au-delà du seuil. */
    public const TRIGGER_VERSION = 'version';

    /**
     * Déclencheur du lot 3 (Harbor, #9) : vulnérabilités critiques (ou critiques et hautes) dans
     * l'analyse de l'image qui tourne. Évalué par VulnTicketPolicy, avec son propre réglage sur la
     * connexion Portainer et son propre ticket par service (VulnTickets), comme la fin de support :
     * un ticket « mettre à jour 1.2 → 1.4 » et un ticket « image vulnérable » ne se remplacent pas.
     * Le paramètre $signals de triggers() permet encore de l'ajouter à un ticket de mise à jour.
     */
    public const TRIGGER_VULNERABILITY = 'vulnerability';

    public const ACTION_NONE = 'none';
    public const ACTION_CREATE = 'create';
    public const ACTION_FOLLOWUP = 'followup';

    /**
     * L'écart atteint-il le seuil ? Seuls majeure, mineure et correctif comptent (une préversion,
     * une empreinte seule, un tag non suivable ou non vérifié ne donnent jamais de ticket).
     */
    public static function reaches(string $gap, string $threshold): bool
    {
        $rank = array_search($threshold, self::THRESHOLDS, true);
        if ($rank === false) {
            return false;
        }

        return in_array($gap, array_slice(self::THRESHOLDS, 0, $rank + 1), true);
    }

    /**
     * Déclencheurs atteints pour une situation.
     *
     * @param Situation $situation
     * @param array<string, bool> $signals TRIGGER_VULNERABILITY => vulnérabilité au seuil connue
     *        (non utilisé par les tickets de mise à jour : voir VulnTicketPolicy).
     * @return list<string>
     */
    public static function triggers(array $situation, string $threshold, array $signals = []): array
    {
        $triggers = [];
        if (self::reaches($situation['gap'], $threshold) && $situation['latest_tag'] !== '') {
            $triggers[] = self::TRIGGER_VERSION;
        }
        if (!empty($signals[self::TRIGGER_VULNERABILITY])) {
            $triggers[] = self::TRIGGER_VULNERABILITY;
        }

        return $triggers;
    }

    /**
     * Action à mener.
     *
     * @param Situation $situation Situation actuelle du service.
     * @param Situation|null $tracked Situation au dernier ticket ou suivi, null si jamais de ticket.
     * @param bool $ticketOpen Le ticket suivi existe et n'est ni résolu, ni clos, ni supprimé.
     * @param list<string> $triggers Déclencheurs atteints (triggers()).
     */
    public static function decide(array $situation, ?array $tracked, bool $ticketOpen, array $triggers): string
    {
        if ($situation['gap'] === VersionComparator::GAP_UNKNOWN && $triggers === []) {
            // Version non vérifiée (registre ou WUD momentanément indisponible) : rien n'a changé
            // pour autant, ni ticket ni suivi.
            return self::ACTION_NONE;
        }
        if ($tracked !== null && $ticketOpen) {
            return self::changed($situation, $tracked) ? self::ACTION_FOLLOWUP : self::ACTION_NONE;
        }
        if ($triggers === []) {
            return self::ACTION_NONE;
        }
        if ($tracked !== null && $tracked['latest_tag'] === $situation['latest_tag']) {
            // Ticket fermé pour cette même version : rien de nouveau à signaler.
            return self::ACTION_NONE;
        }

        return self::ACTION_CREATE;
    }

    /**
     * @param Situation $a
     * @param Situation $b
     */
    public static function changed(array $a, array $b): bool
    {
        return $a['deployed_tag'] !== $b['deployed_tag']
            || $a['latest_tag'] !== $b['latest_tag']
            || $a['gap'] !== $b['gap'];
    }

    /**
     * Nature du suivi : nouvelle version encore au-delà du seuil, écart résolu (à jour), ou écart
     * passé sous le seuil.
     *
     * @param Situation $situation
     * @param list<string> $triggers
     */
    public static function followupKind(array $situation, array $triggers): string
    {
        return match (true) {
            $triggers !== []                                     => 'still_outdated',
            $situation['gap'] === VersionComparator::GAP_NONE    => 'resolved',
            default                                              => 'below_threshold',
        };
    }
}

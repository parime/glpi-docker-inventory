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

namespace GlpiPlugin\Dockerinventory\Tests\Unit;

use GlpiPlugin\Dockerinventory\Ticket\TicketPolicy;
use GlpiPlugin\Dockerinventory\Ticket\VulnTicketPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Décision des tickets de vulnérabilité (fonctions pures).
 */
final class VulnTicketPolicyTest extends TestCase
{
    /**
     * @return array{scan_status: string, digest: string, critical: int, high: int}
     */
    private static function artifact(
        int $critical,
        int $high,
        string $status = 'success',
        string $digest = 'sha256:aaa'
    ): array {
        return ['scan_status' => $status, 'digest' => $digest, 'critical' => $critical, 'high' => $high];
    }

    public function testSituationNeedsReliableLinkAndSuccessfulScan(): void
    {
        $this->assertTrue(VulnTicketPolicy::situation(self::artifact(1, 0), 'digest')['known']);
        $this->assertTrue(VulnTicketPolicy::situation(self::artifact(1, 0), 'tag')['known']);
        $this->assertTrue(VulnTicketPolicy::situation(self::artifact(1, 0), 'tag_digest_differs')['known']);
        $this->assertFalse(VulnTicketPolicy::situation(self::artifact(1, 0, 'running'), 'digest')['known']);
        $this->assertFalse(VulnTicketPolicy::situation(null, '')['known']);
    }

    public function testThresholds(): void
    {
        $highOnly = VulnTicketPolicy::situation(self::artifact(0, 2), 'digest');
        $this->assertSame([], VulnTicketPolicy::triggers($highOnly, VulnTicketPolicy::THRESHOLD_CRITICAL));
        $this->assertSame(
            [TicketPolicy::TRIGGER_VULNERABILITY],
            VulnTicketPolicy::triggers($highOnly, VulnTicketPolicy::THRESHOLD_HIGH)
        );
        $critical = VulnTicketPolicy::situation(self::artifact(1, 0), 'digest');
        $this->assertSame(
            [TicketPolicy::TRIGGER_VULNERABILITY],
            VulnTicketPolicy::triggers($critical, VulnTicketPolicy::THRESHOLD_CRITICAL)
        );
        $unknown = VulnTicketPolicy::situation(self::artifact(5, 5, 'error'), 'digest');
        $this->assertSame([], VulnTicketPolicy::triggers($unknown, VulnTicketPolicy::THRESHOLD_HIGH));
    }

    public function testDecisions(): void
    {
        $critical = VulnTicketPolicy::situation(self::artifact(2, 1), 'digest');
        $triggers = VulnTicketPolicy::triggers($critical, VulnTicketPolicy::THRESHOLD_CRITICAL);
        $same = ['digest' => 'sha256:aaa', 'critical' => 2, 'high' => 1];

        // Jamais de ticket : création.
        $this->assertSame(TicketPolicy::ACTION_CREATE, VulnTicketPolicy::decide($critical, null, false, $triggers));
        // Ticket ouvert, rien n'a changé : rien (pas de doublon).
        $this->assertSame(TicketPolicy::ACTION_NONE, VulnTicketPolicy::decide($critical, $same, true, $triggers));
        // Ticket ouvert, nombre changé : suivi.
        $this->assertSame(
            TicketPolicy::ACTION_FOLLOWUP,
            VulnTicketPolicy::decide($critical, ['critical' => 1] + $same, true, $triggers)
        );
        // Ticket fermé pour la même image : rien.
        $this->assertSame(TicketPolicy::ACTION_NONE, VulnTicketPolicy::decide($critical, $same, false, $triggers));
        // Ticket fermé, autre image toujours vulnérable : nouveau ticket.
        $other = VulnTicketPolicy::situation(self::artifact(1, 0, 'success', 'sha256:bbb'), 'digest');
        $this->assertSame(
            TicketPolicy::ACTION_CREATE,
            VulnTicketPolicy::decide($other, $same, false, VulnTicketPolicy::triggers($other, 'critical'))
        );
        // Image corrigée, ticket ouvert : suivi « résolu ».
        $fixed = VulnTicketPolicy::situation(self::artifact(0, 0, 'success', 'sha256:ccc'), 'digest');
        $this->assertSame(TicketPolicy::ACTION_FOLLOWUP, VulnTicketPolicy::decide($fixed, $same, true, []));
        $this->assertSame('resolved', VulnTicketPolicy::followupKind([]));
        $this->assertSame('still_vulnerable', VulnTicketPolicy::followupKind($triggers));
        // Analyse inconnue (en cours, image absente) : rien, même avec un ticket ouvert.
        $unknown = VulnTicketPolicy::situation(null, '');
        $this->assertSame(TicketPolicy::ACTION_NONE, VulnTicketPolicy::decide($unknown, $same, true, []));
        // Sous le seuil, jamais de ticket : rien.
        $low = VulnTicketPolicy::situation(self::artifact(0, 3), 'digest');
        $this->assertSame(TicketPolicy::ACTION_NONE, VulnTicketPolicy::decide($low, null, false, []));
    }
}

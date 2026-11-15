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

use GlpiPlugin\Dockerinventory\Harbor\HarborImporter;
use GlpiPlugin\Dockerinventory\Harbor\HarborMapper;
use PHPUnit\Framework\TestCase;

/**
 * Réponses de l'API de Harbor => valeurs enregistrées (données fictives au format de Harbor 2.x).
 */
final class HarborMapperTest extends TestCase
{
    private const DIGEST = 'sha256:dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd';

    public function testProject(): void
    {
        $this->assertSame(
            ['uid' => 1, 'name' => 'outils', 'is_public' => 1, 'repo_count' => 5],
            HarborMapper::project(['project_id' => 1, 'name' => 'outils', 'repo_count' => 5,
                'metadata' => ['public' => 'true'], 'inconnu' => 'ignoré'])
        );
        $private = HarborMapper::project(['name' => 'boutique', 'metadata' => ['public' => 'false']]);
        $this->assertSame(0, $private['is_public'] ?? null);
        $this->assertNull(HarborMapper::project(['project_id' => 3]));
    }

    public function testRepositoryNameWithinProject(): void
    {
        $this->assertSame('api', HarborMapper::repositoryName(['name' => 'boutique/api'], 'boutique'));
        $this->assertSame(
            'anciens/portail',
            HarborMapper::repositoryName(['name' => 'archives/anciens/portail'], 'archives')
        );
        $this->assertSame('', HarborMapper::repositoryName(['name' => 'autre/api'], 'boutique'));
        $this->assertSame('', HarborMapper::repositoryName([], 'boutique'));
    }

    public function testArtifact(): void
    {
        $artifact = HarborMapper::artifact([
            'digest'     => self::DIGEST,
            'type'       => 'IMAGE',
            'size'       => 187654321,
            'push_time'  => '2026-11-02T09:15:00.000Z',
            'tags'       => [['name' => '3.2.0'], ['name' => '3.2'], ['sans' => 'nom']],
            'references' => [['child_digest' => 'sha256:' . str_repeat('1', 64)]],
        ]);

        $this->assertNotNull($artifact);
        $this->assertSame(self::DIGEST, $artifact['digest']);
        $this->assertSame(['3.2.0', '3.2'], $artifact['tags']);
        $this->assertSame(['sha256:' . str_repeat('1', 64)], $artifact['child_digests']);
        $this->assertSame('image', $artifact['artifact_type']);
        $this->assertSame(187654321, $artifact['size']);
        $this->assertSame(
            (new \DateTimeImmutable('2026-11-02T09:15:00Z'))
                ->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s'),
            $artifact['push_time']
        );

        $untagged = HarborMapper::artifact(['digest' => self::DIGEST, 'tags' => null, 'references' => null]);
        $this->assertSame([], $untagged['tags'] ?? null);
        $this->assertNull(HarborMapper::artifact(['digest' => 'pas-une-empreinte']));
    }

    public function testSuccessfulScanOverview(): void
    {
        $scan = HarborMapper::scan([
            'application/vnd.security.vulnerability.report; version=1.1' => [
                'scan_status' => 'Success',
                'severity'    => 'Critical',
                'end_time'    => '2026-11-14T03:00:00.000Z',
                'scanner'     => ['name' => 'Trivy', 'vendor' => 'Aqua Security', 'version' => 'v0.57.0'],
                'summary'     => [
                    'total'   => 20,
                    'fixable' => 6,
                    'summary' => ['Critical' => 1, 'High' => 3, 'Medium' => 5, 'Low' => 9, 'Negligible' => 2],
                ],
            ],
        ]);

        $this->assertSame(HarborMapper::STATUS_SUCCESS, $scan['scan_status']);
        $this->assertSame(HarborMapper::SEVERITY_CRITICAL, $scan['severity']);
        $this->assertSame('Trivy v0.57.0', $scan['scanner']);
        $this->assertSame([1, 3, 5, 9, 2, 20, 6], [
            $scan['critical'], $scan['high'], $scan['medium'], $scan['low'], $scan['other'], $scan['total'],
            $scan['fixable'],
        ]);
        $this->assertNotNull($scan['scan_time']);
    }

    public function testCleanScanAndOtherStatuses(): void
    {
        $clean = HarborMapper::scan(['application/vnd.scanner.adapter.vuln.report.harbor+json; version=1.0' => [
            'scan_status' => 'Success', 'severity' => 'None', 'summary' => ['total' => 0, 'summary' => null],
        ]]);
        $this->assertSame(HarborMapper::SEVERITY_NONE, $clean['severity']);

        $running = HarborMapper::scan(['x' => ['scan_status' => 'Running', 'summary' => ['summary' => ['High' => 4]]]]);
        $this->assertSame(HarborMapper::STATUS_RUNNING, $running['scan_status']);
        $this->assertSame(HarborMapper::SEVERITY_UNKNOWN, $running['severity'], 'gravité : analyse réussie seulement');

        $error = HarborMapper::scan(['x' => ['scan_status' => 'Error']]);
        $this->assertSame(HarborMapper::STATUS_ERROR, $error['scan_status']);
        $scheduled = HarborMapper::scan(['x' => ['scan_status' => 'Scheduled']]);
        $this->assertSame(HarborMapper::STATUS_PENDING, $scheduled['scan_status']);
        foreach ([null, [], ['x' => 'pas un rapport'], 'texte'] as $overview) {
            $scan = HarborMapper::scan($overview);
            $this->assertSame(HarborMapper::STATUS_NOT_SCANNED, $scan['scan_status']);
            $this->assertSame(0, $scan['total']);
        }
    }

    public function testSeverityAndRank(): void
    {
        $this->assertSame('high', HarborMapper::severity(['critical' => 0, 'high' => 1, 'medium' => 9, 'low' => 0]));
        $this->assertSame('none', HarborMapper::severity(['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0]));
        $this->assertLessThan(HarborMapper::rank('high'), HarborMapper::rank('critical'));
        $this->assertGreaterThan(HarborMapper::rank('none'), HarborMapper::rank(''));
    }

    public function testDates(): void
    {
        $this->assertNull(HarborMapper::date('0001-01-01T00:00:00.000Z'));
        $this->assertNull(HarborMapper::date(''));
        $this->assertNull(HarborMapper::date('pas une date'));
        $this->assertNull(HarborMapper::date(null));
        $date = (string) HarborMapper::date('2026-11-14T03:00:00Z');
        $this->assertMatchesRegularExpression('/^2026-11-1[34] \d\d:\d\d:\d\d$/', $date);
    }

    public function testRetireOnlyWhenTheListWasReadCompletely(): void
    {
        $state = [
            'projects_complete'     => true,
            'projects'              => ['outils' => true, 'boutique' => true],
            'complete_projects'     => ['outils' => ['outils/wiki']],
            'complete_repositories' => ['outils/wiki' => true],
        ];
        // Dépôt lu en entier : un artefact non revu est retiré.
        $this->assertTrue(HarborImporter::shouldRetire('outils', 'outils/wiki', $state));
        // Dépôt disparu d'un projet lu en entier.
        $this->assertTrue(HarborImporter::shouldRetire('outils', 'outils/ancien', $state));
        // Projet disparu de la liste des projets lue en entier.
        $this->assertTrue(HarborImporter::shouldRetire('archives', 'archives/portail', $state));
        // Projet présent mais dépôts non lus (erreur) : rien n'est retiré.
        $this->assertFalse(HarborImporter::shouldRetire('boutique', 'boutique/api', $state));

        $state['projects_complete'] = false;
        $this->assertFalse(HarborImporter::shouldRetire('archives', 'archives/portail', $state));
    }
}

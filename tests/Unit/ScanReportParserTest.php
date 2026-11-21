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

use GlpiPlugin\Dockerinventory\ImageScan;
use GlpiPlugin\Dockerinventory\Scan\ScanReportException;
use GlpiPlugin\Dockerinventory\Scan\ScanReportParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Lecture des rapports de Trivy, Grype et Docker Scout (#73), fichiers piégés compris, et
 * rattachement d'une analyse aux services.
 */
final class ScanReportParserTest extends TestCase
{
    private static function fixture(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/fixtures/scans/' . $name);
    }

    public function testTrivyReport(): void
    {
        $report = ScanReportParser::parse(self::fixture('trivy.json'));

        $this->assertSame(ScanReportParser::SCANNER_TRIVY, $report['scanner']);
        $this->assertSame('0.58.1', $report['tool_version']);
        $this->assertSame('redis', $report['image']);
        $this->assertSame('7.4.1', $report['tag']);
        $this->assertSame('sha256:' . str_repeat('a', 64), $report['digest']);
        $this->assertNotNull($report['scan_date']);
        // CVE-2099-0001 vue deux fois pour le même paquet : comptée une fois.
        $this->assertSame(['critical' => 1, 'high' => 2, 'medium' => 1, 'low' => 1, 'other' => 1], $report['counts']);
        $this->assertSame(6, $report['total']);
        $this->assertSame(3, $report['fixable']);
        $this->assertSame('CVE-2099-0001', $report['findings'][0]['id']);
        $this->assertSame('critical', $report['findings'][0]['severity']);
        $this->assertSame('1.0.1', $report['findings'][0]['fixed']);
        $this->assertFalse($report['truncated']);
    }

    public function testGrypeReport(): void
    {
        $report = ScanReportParser::parse(self::fixture('grype.json'));

        $this->assertSame(ScanReportParser::SCANNER_GRYPE, $report['scanner']);
        $this->assertSame('0.84.0', $report['tool_version']);
        $this->assertSame('grafana/grafana', $report['image']);
        $this->assertSame('11.2.0', $report['tag']);
        $this->assertSame('sha256:' . str_repeat('b', 64), $report['digest']);
        $this->assertSame(['critical' => 0, 'high' => 1, 'medium' => 1, 'low' => 0, 'other' => 1], $report['counts']);
        $this->assertSame(1, $report['fixable']);
        $this->assertSame('3.1.2', $report['findings'][0]['fixed']);
    }

    public function testDockerScoutSarifNeedsTheImage(): void
    {
        try {
            ScanReportParser::parse(self::fixture('scout.sarif'));
            $this->fail('image absente acceptée');
        } catch (ScanReportException $e) {
            $this->assertSame(ScanReportException::NO_IMAGE, $e->reason);
        }
        $report = ScanReportParser::parse(self::fixture('scout.sarif'), 'postgres:16.4');

        $this->assertSame(ScanReportParser::SCANNER_SCOUT, $report['scanner']);
        $this->assertSame('1.15.0', $report['tool_version']);
        $this->assertSame('postgres', $report['image']);
        $this->assertSame('16.4', $report['tag']);
        $this->assertSame('', $report['digest']);
        // Score, gravité CVSS, étiquette, puis niveau du résultat sans règle.
        $this->assertSame(['critical' => 1, 'high' => 1, 'medium' => 1, 'low' => 1, 'other' => 0], $report['counts']);
        $this->assertSame(1, $report['fixable']);
        $this->assertSame('openssl-fictif', $report['findings'][0]['package']);
        $this->assertSame('3.0.0', $report['findings'][0]['installed']);
    }

    public function testEnteredReferenceOverridesTheReport(): void
    {
        $report = ScanReportParser::parse(self::fixture('trivy.json'), 'registry.exemple.test:5000/cache/redis:7.4');

        $this->assertSame('registry.exemple.test:5000/cache/redis', $report['image']);
        $this->assertSame('7.4', $report['tag']);
        $this->assertSame('', $report['digest']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function trappedFiles(): iterable
    {
        yield 'vide' => ['', ScanReportException::EMPTY];
        yield 'espaces' => ["  \n ", ScanReportException::EMPTY];
        yield 'pas du JSON' => ['<?php system("id"); ?>', ScanReportException::INVALID_JSON];
        yield 'JSON tronqué' => ['{"matches": [', ScanReportException::INVALID_JSON];
        yield 'liste' => ['[1, 2, 3]', ScanReportException::UNKNOWN_FORMAT];
        yield 'scalaire' => ['"texte"', ScanReportException::UNKNOWN_FORMAT];
        yield 'objet inconnu' => ['{"bom": {}}', ScanReportException::UNKNOWN_FORMAT];
        yield 'trop imbriqué' => [str_repeat('{"a":', 200) . '1' . str_repeat('}', 200), ScanReportException::TOO_DEEP];
        yield 'sans image' => ['{"matches": []}', ScanReportException::NO_IMAGE];
    }

    #[DataProvider('trappedFiles')]
    public function testTrappedFilesAreRefused(string $content, string $reason): void
    {
        try {
            ScanReportParser::parse($content);
            $this->fail('fichier accepté');
        } catch (ScanReportException $e) {
            $this->assertSame($reason, $e->reason);
            $this->assertArrayHasKey($reason, ImageScan::errorLabels());
        }
    }

    public function testTooLargeFileIsRefusedBeforeDecoding(): void
    {
        $this->expectExceptionObject(new ScanReportException(ScanReportException::TOO_LARGE));
        ScanReportParser::parse('{"matches":[' . str_repeat(' ', ScanReportParser::MAX_BYTES) . ']}');
    }

    public function testTooManyFindingsAreRefused(): void
    {
        $match = '{"vulnerability":{"id":"CVE-2099-%d"}}';
        $matches = [];
        for ($i = 0; $i <= ScanReportParser::MAX_FINDINGS; $i++) {
            $matches[] = sprintf($match, $i);
        }
        $this->expectExceptionObject(new ScanReportException(ScanReportException::TOO_MANY));
        ScanReportParser::parse(
            '{"source":{"target":{"userInput":"nginx:1"}},"matches":[' . implode(',', $matches) . ']}'
        );
    }

    public function testOnlyTheMostSevereFindingsAreKept(): void
    {
        $matches = [];
        for ($i = 0; $i < ScanReportParser::MAX_STORED + 5; $i++) {
            $matches[] = ['vulnerability' => ['id' => 'CVE-2099-' . $i, 'severity' => 'Low']];
        }
        $matches[] = ['vulnerability' => ['id' => 'CVE-2099-9999', 'severity' => 'Critical']];
        $report = ScanReportParser::parse((string) json_encode([
            'source'  => ['target' => ['userInput' => 'nginx:1.27']],
            'matches' => $matches,
        ]));

        $this->assertTrue($report['truncated']);
        $this->assertCount(ScanReportParser::MAX_STORED, $report['findings']);
        $this->assertSame('CVE-2099-9999', $report['findings'][0]['id']);
        $this->assertSame(ScanReportParser::MAX_STORED + 6, $report['total']);
    }

    public function testHostileValuesAreReadAsPlainBoundedText(): void
    {
        $report = ScanReportParser::parse((string) json_encode([
            'ArtifactName' => 'nginx:1.27',
            'CreatedAt'    => ['pas', 'une', 'date'],
            'Results'      => [
                'pas une liste',
                ['Vulnerabilities' => [
                    ['VulnerabilityID' => "CVE-2099-7\u{0000}<script>", 'Severity' => ['CRITICAL'],
                        'PkgName' => str_repeat('p', 1000), 'InstalledVersion' => 12, 'FixedVersion' => true],
                    ['Severity' => 'HIGH'],
                    'texte',
                ]],
            ],
        ]));

        $this->assertNull($report['scan_date']);
        $this->assertSame(1, $report['total']);
        $finding = $report['findings'][0];
        $this->assertSame('CVE-2099-7 <script>', $finding['id']);
        $this->assertSame(ScanReportParser::OTHER, $finding['severity']);
        $this->assertSame(128, mb_strlen($finding['package']));
        $this->assertSame('12', $finding['installed']);
        $this->assertSame('', $finding['fixed']);
    }

    public function testInvalidReferenceIsRefused(): void
    {
        $this->expectExceptionObject(new ScanReportException(ScanReportException::NO_IMAGE));
        ScanReportParser::parse(self::fixture('grype.json'), '<img src=x>');
    }

    /**
     * @return iterable<string, array{string, array{image: string, tag: string, digest: string}}>
     */
    public static function references(): iterable
    {
        $digest = 'sha256:' . str_repeat('c', 64);
        yield 'nom seul' => ['nginx', ['image' => 'nginx', 'tag' => '', 'digest' => '']];
        yield 'registre avec port' => [
            'registry.exemple.test:5000/boutique/api:1.9.0',
            ['image' => 'registry.exemple.test:5000/boutique/api', 'tag' => '1.9.0', 'digest' => ''],
        ];
        yield 'registre avec port sans tag' => [
            'registry.exemple.test:5000/boutique/api',
            ['image' => 'registry.exemple.test:5000/boutique/api', 'tag' => '', 'digest' => ''],
        ];
        yield 'tag et empreinte' => [
            'Boutique/Web:2.4.1@' . strtoupper($digest),
            ['image' => 'boutique/web', 'tag' => '2.4.1', 'digest' => $digest],
        ];
        yield 'empreinte invalide' => ['nginx@sha256:zz', ['image' => 'nginx', 'tag' => '', 'digest' => '']];
        yield 'nom invalide' => ['"; DROP', ['image' => '', 'tag' => '', 'digest' => '']];
    }

    /**
     * @param array{image: string, tag: string, digest: string} $expected
     */
    #[DataProvider('references')]
    public function testSplitReference(string $reference, array $expected): void
    {
        $this->assertSame($expected, ScanReportParser::splitReference($reference));
    }

    public function testScansAreLinkedByDigestThenTag(): void
    {
        $digest = 'sha256:' . str_repeat('a', 64);
        $scans = [
            ['id' => 1, 'image_key' => 'docker.io/library/redis', 'tag' => '7.4.1', 'digest' => $digest],
            ['id' => 2, 'image_key' => 'docker.io/library/redis', 'tag' => '7.2.0', 'digest' => ''],
        ];
        $services = [
            10 => ['image' => 'redis', 'tag' => '7.4.1', 'digest' => $digest],
            11 => ['image' => 'docker.io/library/redis', 'tag' => '7.2.0', 'digest' => ''],
            12 => ['image' => 'redis', 'tag' => '7.4.1', 'digest' => 'sha256:' . str_repeat('d', 64)],
            13 => ['image' => 'registry.exemple.test:5000/redis', 'tag' => '7.4.1', 'digest' => ''],
            14 => ['image' => 'redis', 'tag' => '6.0', 'digest' => ''],
        ];
        $links = ImageScan::link($scans, $services);

        $this->assertSame(['scan' => 1, 'method' => 'digest'], $links[10]);
        $this->assertSame(['scan' => 2, 'method' => 'tag'], $links[11]);
        $this->assertSame(['scan' => 1, 'method' => 'tag_digest_differs'], $links[12]);
        $this->assertArrayNotHasKey(13, $links);
        $this->assertArrayNotHasKey(14, $links);
    }
}

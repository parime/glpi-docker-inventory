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

use GlpiPlugin\Dockerinventory\Harbor\HarborClient;
use GlpiPlugin\Dockerinventory\Registry\RegistryException;
use PHPUnit\Framework\TestCase;

/**
 * Client de l'API REST de Harbor, sans réseau (adresse fictive, transport en mémoire).
 */
final class HarborClientTest extends TestCase
{
    private const URL = 'https://harbor.exemple.test';
    private const PASSWORD = 'robot_mot_de_passe_fictif';

    public function testProjectsAreReadPageByPageWithRobotAccount(): void
    {
        $calls = [];
        $client = new HarborClient(self::URL . '/', 'robot$lecture', self::PASSWORD, static function (
            string $url,
            array $query,
            array $headers
        ) use (&$calls): string {
            $calls[] = [$url, $query, $headers];
            $page = (int) $query['page'];
            $items = $page === 1
                ? [['name' => 'outils'], ['name' => 'boutique']]
                : ($page === 2 ? [['name' => 'archives']] : []);

            return (string) json_encode($items);
        }, 2);

        $result = $client->projects();

        $this->assertTrue($result['complete']);
        $this->assertSame(['outils', 'boutique', 'archives'], array_column($result['items'], 'name'));
        $this->assertCount(2, $calls, 'une page incomplète est la dernière');
        $this->assertSame(self::URL . '/api/v2.0/projects', $calls[0][0]);
        $this->assertSame(['page' => 2, 'page_size' => 2], $calls[1][1]);
        $this->assertSame('Basic ' . base64_encode('robot$lecture:' . self::PASSWORD), $calls[0][2]['Authorization']);
        $this->assertSame(2, $client->requests());
    }

    public function testFullLastPageNeedsAnEmptyPageToBeComplete(): void
    {
        $client = new HarborClient(self::URL, '', '', static fn (string $url, array $query): string => (string) json_encode(
            (int) $query['page'] === 1 ? [['name' => 'a'], ['name' => 'b']] : []
        ), 2);

        $result = $client->projects();

        $this->assertTrue($result['complete']);
        $this->assertCount(2, $result['items']);
        $this->assertSame(2, $client->requests());
    }

    public function testTooManyPagesMarksTheListIncomplete(): void
    {
        $client = new HarborClient(self::URL, '', '', static fn (): string => (string) json_encode([['name' => 'x']]), 1);

        $result = $client->projects();

        $this->assertFalse($result['complete']);
        $this->assertCount(HarborClient::MAX_PAGES, $result['items']);
        $this->assertSame(HarborClient::MAX_PAGES, $client->requests());
    }

    public function testArtifactsAskForScanOverviewAndDoubleEncodeRepository(): void
    {
        $calls = [];
        $client = new HarborClient(self::URL, '', '', static function (
            string $url,
            array $query,
            array $headers
        ) use (&$calls): string {
            $calls[] = [$url, $query, $headers];

            return 'null';
        });

        $result = $client->artifacts('archives', 'anciens/portail');

        $this->assertSame([], $result['items']);
        $this->assertSame(
            self::URL . '/api/v2.0/projects/archives/repositories/anciens%252Fportail/artifacts',
            $calls[0][0]
        );
        $this->assertSame('true', $calls[0][1]['with_scan_overview']);
        $this->assertSame('true', $calls[0][1]['with_tag']);
        $this->assertStringContainsString('vulnerability.report', $calls[0][2]['X-Accept-Vulnerabilities']);
        $this->assertArrayNotHasKey('Authorization', $calls[0][2], 'sans compte : projets publics seulement');
    }

    public function testRepositoriesOfAProject(): void
    {
        $urls = [];
        $client = new HarborClient(self::URL, '', '', static function (string $url) use (&$urls): string {
            $urls[] = $url;

            return '[{"name":"boutique/api"}]';
        });

        $this->assertSame([['name' => 'boutique/api']], $client->repositories('boutique')['items']);
        $this->assertSame([self::URL . '/api/v2.0/projects/boutique/repositories'], $urls);
    }

    public function testErrorsAreStableCodesWithoutPassword(): void
    {
        $failures = [
            'HTTP 401 returned for "https://harbor.exemple.test/api/v2.0/projects"' => RegistryException::UNAUTHORIZED,
            'HTTP 429 returned'                                                     => RegistryException::RATE_LIMITED,
            'HTTP 503 returned'                                                     => RegistryException::SERVER_ERROR,
            'Connection refused'                                                    => RegistryException::UNREACHABLE,
        ];
        foreach ($failures as $message => $code) {
            $client = new HarborClient(self::URL, 'robot$lecture', self::PASSWORD, static function () use ($message): string {
                throw new \RuntimeException($message . ' ' . self::PASSWORD);
            });
            try {
                $client->projects();
                $this->fail('exception attendue');
            } catch (RegistryException $e) {
                $this->assertSame($code, $e->getMessage());
                $this->assertStringNotContainsString(self::PASSWORD, $e->getMessage());
            }
        }
    }

    public function testUnexpectedAnswersAreRejected(): void
    {
        foreach (['<html>connexion</html>', '{"errors":[]}'] as $body) {
            $client = new HarborClient(self::URL, '', '', static fn (): string => $body);
            try {
                $client->projects();
                $this->fail('exception attendue pour ' . $body);
            } catch (RegistryException $e) {
                $this->assertSame(RegistryException::UNEXPECTED, $e->getMessage());
            }
        }
    }
}

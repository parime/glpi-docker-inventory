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

namespace GlpiPlugin\Dockerinventory\Tests\Integration;

use GlpiPlugin\Dockerinventory\Connection;
use GlpiPlugin\Dockerinventory\Exposure\ExposureMapper;
use GlpiPlugin\Dockerinventory\Service;
use GlpiPlugin\Dockerinventory\Tests\Unit\TraefikClientTest;
use GlpiPlugin\Dockerinventory\Traefik\TraefikClient;
use GlpiPlugin\Dockerinventory\Traefik\TraefikException;
use GlpiPlugin\Dockerinventory\Traefik\TraefikMapper;
use GlpiPlugin\Dockerinventory\Traefik\TraefikReader;
use PHPUnit\Framework\TestCase;

/**
 * Lecture sur clic d'une API Traefik (faux Traefik servi en mémoire) contre la vraie base GLPI :
 * secret chiffré et jamais réaffiché, compte rendu enregistré, comparaison avec les étiquettes
 * d'un service inventorié, page de la connexion sans injection de code.
 */
final class TraefikReaderTest extends TestCase
{
    private const SECRET = 'traefik_secret_fictif_integration';

    private int $entityId = 0;

    private int $connectionId = 0;

    private int $serviceId = 0;

    protected function setUp(): void
    {
        global $DB;

        $suffix = bin2hex(random_bytes(4));
        $this->entityId = (int) (new \Entity())->add(['name' => 'PHPUnit Traefik ' . $suffix, 'entities_id' => 0]);
        \Session::changeActiveEntities(0, true);
        $this->connectionId = (int) (new Connection())->add([
            'name'        => 'Traefik PHPUnit ' . $suffix,
            'type'        => Connection::TYPE_TRAEFIK,
            'entities_id' => $this->entityId,
            'url'         => 'https://traefik.exemple.test/',
            'username'    => 'lecteur',
            'api_key'     => self::SECRET,
        ]);
        $this->assertGreaterThan(0, $this->connectionId);
        $DB->insert(Service::getTable(), [
            'name'        => 'whoami-' . $suffix,
            'stack_name'  => 'demo',
            'entities_id' => $this->entityId,
            'routes'      => ExposureMapper::encode(ExposureMapper::traefikRoutes([
                'traefik.http.routers.whoami.rule' => 'Host(`whoami.exemple.test`)',
                'traefik.http.routers.oldapp.rule' => 'Host(`oldapp.exemple.test`)',
            ])),
        ]);
        $this->serviceId = (int) $DB->insertId();
    }

    protected function tearDown(): void
    {
        global $DB;

        $DB->delete(Service::getTable(), ['id' => $this->serviceId]);
        (new Connection())->delete(['id' => $this->connectionId], true);
        (new \Entity())->delete(['id' => $this->entityId], true);
        $_SESSION['MESSAGE_AFTER_REDIRECT'] = [];
    }

    private function connection(): Connection
    {
        $connection = new Connection();
        $this->assertTrue($connection->getFromDB($this->connectionId));

        return $connection;
    }

    public function testSecretIsEncryptedAndReadUsesOnlyTheConfiguredAddress(): void
    {
        $connection = $this->connection();
        $this->assertTrue($connection->isTraefik());
        $this->assertNotSame(self::SECRET, (string) $connection->fields['api_key']);
        $this->assertSame(self::SECRET, $connection->getDecryptedApiKey());

        $calls = [];
        $transport = TraefikClientTest::fakeTraefik($calls);
        $reader = new TraefikReader(static fn (Connection $c): TraefikClient => new TraefikClient(
            (string) $c->fields['url'],
            (string) $c->fields['username'],
            $c->getDecryptedApiKey(),
            $transport
        ));
        $report = $reader->read($connection);

        $this->assertSame(Connection::STATUS_OK, $report['status']);
        $this->assertSame('3.1.2', $report['traefik_version']);
        foreach (array_column($calls, 0) as $url) {
            $this->assertStringStartsWith('https://traefik.exemple.test/api/', $url);
        }
        $stored = json_decode((string) $this->connection()->fields['last_import_report'], true);
        $this->assertSame(8, $stored['counts']['routes']);
        $this->assertStringNotContainsString(self::SECRET, (string) $this->connection()->fields['last_import_report']);

        $matches = array_column($stored['routes'], 'match', 'name');
        $this->assertSame(TraefikMapper::MATCH_DECLARED, $matches['whoami']);
        $this->assertContains('oldapp', array_column($stored['not_applied'], 'name'));
        $this->assertSame((int) Connection::STATUS_OK, (int) $this->connection()->fields['last_import_status']);
    }

    public function testFailureIsRecordedAsErrorCode(): void
    {
        $reader = new TraefikReader(static fn (Connection $c): TraefikClient => new TraefikClient(
            'https://traefik.exemple.test',
            '',
            '',
            static function (): string {
                throw new \RuntimeException('HTTP 401 returned for "x".');
            }
        ));
        $report = $reader->read($this->connection());
        $this->assertSame(Connection::STATUS_ERROR, $report['status']);
        $this->assertSame(TraefikException::UNAUTHORIZED, $report['errors'][0]['code']);
    }

    public function testConnectionPageEscapesTraefikValuesAndNeverShowsTheSecret(): void
    {
        global $DB;

        $payload = '<script>alert(1)</script>';
        $snapshot = ['routers' => [[
            'name' => $payload . '@file', 'provider' => 'file', 'service' => $payload,
            'rule' => 'Host(`' . $payload . '.exemple.test`)', 'middlewares' => [$payload],
        ]]];
        $report = ['status' => Connection::STATUS_OK, 'traefik_version' => $payload, 'errors' => []]
            + TraefikMapper::map($snapshot, []);
        $DB->update(Connection::getTable(), [
            'last_import_status' => Connection::STATUS_OK,
            'last_import_report' => (string) json_encode($report),
        ], ['id' => $this->connectionId]);

        ob_start();
        $this->connection()->showForm($this->connectionId);
        $html = (string) ob_get_clean();

        $this->assertStringNotContainsString($payload, $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString(self::SECRET, $html);
    }
}

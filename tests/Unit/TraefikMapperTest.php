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

use GlpiPlugin\Dockerinventory\Exposure\ExposureMapper;
use GlpiPlugin\Dockerinventory\Traefik\TraefikMapper;
use PHPUnit\Framework\TestCase;

/**
 * Routes appliquées du faux Traefik comparées à des étiquettes fictives, et signalements.
 */
final class TraefikMapperTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function snapshot(): array
    {
        $data = json_decode((string) file_get_contents(dirname(__DIR__) . '/mock-traefik/fixture.json'), true);
        unset($data['version']);

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private static function mapped(): array
    {
        return TraefikMapper::map(self::snapshot(), [
            ['name' => 'whoami', 'host' => 'whoami.exemple.test', 'service_id' => 11, 'service' => 'demo / whoami'],
            ['name' => 'blog', 'host' => 'blog-old.exemple.test', 'service_id' => 12, 'service' => 'site / blog'],
            ['name' => 'oldapp', 'host' => 'oldapp.exemple.test', 'service_id' => 13, 'service' => 'site / oldapp'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function route(string $name): array
    {
        foreach (self::mapped()['routes'] as $route) {
            if ($route['name'] === $name) {
                return $route;
            }
        }
        self::fail('Route absente : ' . $name);
    }

    public function testComparesAppliedRoutesWithLabels(): void
    {
        $whoami = self::route('whoami');
        $this->assertSame(TraefikMapper::MATCH_DECLARED, $whoami['match']);
        $this->assertSame([['id' => 11, 'name' => 'demo / whoami']], $whoami['services']);
        $this->assertSame(['websecure (:443)'], $whoami['entrypoints']);
        $this->assertSame(2, $whoami['servers']);
        $this->assertTrue($whoami['tls']);
        $this->assertSame([], $whoami['checks']);

        $this->assertSame(TraefikMapper::MATCH_DIFFERS, self::route('blog')['match']);
        $this->assertSame(TraefikMapper::MATCH_NOT_INVENTORIED, self::route('portainer')['match']);
        $this->assertSame(TraefikMapper::MATCH_OTHER_PROVIDER, self::route('grafana')['match']);
        $this->assertSame(TraefikMapper::MATCH_INTERNAL, self::route('api')['match']);

        $mapped = self::mapped();
        $this->assertSame(['oldapp'], array_column($mapped['not_applied'], 'name'));
        $this->assertFalse($mapped['truncated']);
    }

    public function testSecurityChecks(): void
    {
        $this->assertSame(
            [TraefikMapper::CHECK_NO_TLS, TraefikMapper::CHECK_NO_HEADERS],
            self::route('blog')['checks']
        );
        $this->assertSame([TraefikMapper::CHECK_NO_AUTH], self::route('grafana')['checks']);
        // Authentification (middleware du même fournisseur, nom non qualifié) : rien à signaler.
        $this->assertSame([], self::route('portainer')['checks']);
        $this->assertSame(['admin-auth@docker', 'secure-headers@file'], self::route('portainer')['middlewares']);
        $this->assertSame([TraefikMapper::CHECK_DASHBOARD], self::route('dashboard')['checks']);
        // Redirection vers HTTPS : la route HTTP n'est pas signalée.
        $this->assertSame([], self::route('http-catchall')['checks']);
        // Routes TCP : pas de contrôle HTTP.
        $postgres = self::route('postgres');
        $this->assertSame('tcp', $postgres['protocol']);
        $this->assertSame(['db.exemple.test'], $postgres['hosts']);
        $this->assertSame([], $postgres['checks']);
    }

    public function testCountsAndOrder(): void
    {
        $mapped = self::mapped();
        $counts = $mapped['counts'];
        $this->assertSame(8, $counts['routes']);
        $this->assertSame(5, $counts['internet']);
        $this->assertSame(2, $counts[TraefikMapper::CHECK_DASHBOARD]);
        $this->assertSame(1, $counts[TraefikMapper::CHECK_NO_TLS]);
        $this->assertSame(1, $counts[TraefikMapper::CHECK_NO_AUTH]);
        $this->assertSame(3, $counts[TraefikMapper::MATCH_OTHER_PROVIDER]);
        $this->assertSame(1, $counts['not_applied']);
        // Routes signalées d'abord.
        $this->assertNotSame([], $mapped['routes'][0]['checks']);
        $this->assertSame(ExposureMapper::SCOPE_INTERNET, self::route('blog')['scope']);
    }

    public function testDashboardWithAuthenticationIsNotFlaggedAndTruncation(): void
    {
        $snapshot = [
            'middlewares' => [['name' => 'auth@file', 'type' => 'forwardauth']],
            'routers' => [[
                'name' => 'dash@file', 'provider' => 'file', 'service' => 'api@internal',
                'rule' => 'Host(`traefik.exemple.test`)', 'tls' => [], 'middlewares' => ['auth@file'],
            ]],
        ];
        $route = TraefikMapper::map($snapshot, [])['routes'][0];
        $this->assertNotContains(TraefikMapper::CHECK_DASHBOARD, $route['checks']);
        $this->assertNotContains(TraefikMapper::CHECK_NO_AUTH, $route['checks']);

        $many = ['routers' => array_map(
            static fn (int $i): array => ['name' => 'r' . $i . '@file', 'rule' => 'Host(`r' . $i . '.lan`)'],
            range(1, TraefikMapper::MAX_ROUTES + 5)
        )];
        $mapped = TraefikMapper::map($many, []);
        $this->assertTrue($mapped['truncated']);
        $this->assertCount(TraefikMapper::MAX_ROUTES, $mapped['routes']);
        $this->assertSame(TraefikMapper::MAX_ROUTES + 5, $mapped['counts']['routes']);
    }
}

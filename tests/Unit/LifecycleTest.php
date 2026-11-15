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

use GlpiPlugin\Dockerinventory\Lifecycle\CycleMatcher;
use GlpiPlugin\Dockerinventory\Lifecycle\LifecycleClient;
use GlpiPlugin\Dockerinventory\Lifecycle\LifecycleException;
use GlpiPlugin\Dockerinventory\Lifecycle\ProductMap;
use GlpiPlugin\Dockerinventory\Ticket\EolTicketPolicy;
use GlpiPlugin\Dockerinventory\Ticket\TicketPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Fin de support (#26) sans base ni réseau : correspondance image => produit, branche d'un tag,
 * « fin de support dans N jours », lecture de la source (transport en mémoire, adresse fictive) et
 * décision des tickets. Cycles fictifs.
 */
final class LifecycleTest extends TestCase
{
    private const SOURCE = 'https://cycles.exemple.test/api/v1/products/{product}/';

    /**
     * @return list<array{string, string}>
     */
    public static function images(): array
    {
        return [
            ['postgres', 'postgres'],
            ['postgres:16.4', 'postgres'],
            ['library/postgres', 'postgres'],
            ['docker.io/library/postgres:13@sha256:0123abcd', 'postgres'],
            ['index.docker.io/grafana/grafana', 'grafana/grafana'],
            ['Registry.Exemple.Test:5000/outils/wiki:3.2.0', 'registry.exemple.test:5000/outils/wiki'],
            ['', ''],
            ['pas une image !', ''],
        ];
    }

    #[DataProvider('images')]
    public function testImageNamesAreNormalized(string $image, string $expected): void
    {
        $this->assertSame($expected, ProductMap::normalizeImage($image));
    }

    public function testProductIsNeverGuessed(): void
    {
        $map = ['postgres' => 'postgresql', 'registry.exemple.test/miroir/postgres' => 'postgresql'];

        $this->assertSame('postgresql', ProductMap::productFor('docker.io/library/postgres', $map));
        $this->assertSame('postgresql', ProductMap::productFor('registry.exemple.test/miroir/postgres', $map));
        // Même dernier segment, autre dépôt : pas de correspondance devinée.
        $this->assertSame('', ProductMap::productFor('bitnami/postgres', $map));
        $this->assertSame('', ProductMap::productFor('outils/wiki', $map));
        $this->assertTrue(ProductMap::isValidProduct('apache-http-server'));
        $this->assertFalse(ProductMap::isValidProduct('../postgresql'));
        $this->assertFalse(ProductMap::isValidProduct('PostgreSQL'));
        foreach (ProductMap::DEFAULTS as $image => $product) {
            $this->assertSame($image, ProductMap::normalizeImage($image));
            $this->assertTrue(ProductMap::isValidProduct($product), $product);
        }
    }

    /**
     * @return list<array{cycle: string, codename: string, eol: string, latest: string}>
     */
    private static function cycles(): array
    {
        $cycle = static fn (string $name, string $eol, string $codename = ''): array
            => ['cycle' => $name, 'codename' => $codename, 'eol' => $eol, 'latest' => $name . '.9'];

        return [
            $cycle('7.4', '2027-09-11'),
            $cycle('7.2', '2026-12-04'),
            $cycle('7', '2026-01-01'),
            $cycle('6.2', LifecycleClient::EOL_WITHOUT_DATE),
            $cycle('12', '2027-06-01', 'Bookworm'),
            $cycle('1.24', '2025-04-23'),
            $cycle('2', ''),
        ];
    }

    /**
     * @return list<array{string, string|null}>
     */
    public static function tags(): array
    {
        return [
            ['7.4.1', '7.4'],
            ['7.2.10-alpine', '7.2'],
            ['v7.4', '7.4'],
            ['7.0.15', '7'],
            ['6.2', '6.2'],
            ['12.5-slim', '12'],
            ['bookworm', '12'],
            ['bookworm-slim', '12'],
            ['1.24.0', '1.24'],
            ['1', '1.24'],
            ['latest', null],
            ['stable', null],
            ['3.1', null],
        ];
    }

    #[DataProvider('tags')]
    public function testTagMatchesMostPreciseCycle(string $tag, ?string $expected): void
    {
        $this->assertSame($expected, CycleMatcher::match($tag, self::cycles())['cycle'] ?? null);
    }

    public function testFloatingTagFollowsNewestCycle(): void
    {
        $cycles = [
            ['cycle' => '8.0', 'codename' => '', 'eol' => '', 'latest' => ''],
            ['cycle' => '8.4', 'codename' => '', 'eol' => '', 'latest' => ''],
            ['cycle' => '8.10', 'codename' => '', 'eol' => '', 'latest' => ''],
        ];

        $this->assertSame('8.10', CycleMatcher::match('8', $cycles)['cycle'] ?? null);
    }

    /**
     * @return list<array{string, string, string, int|null}>
     */
    public static function statuses(): array
    {
        return [
            // Tag, état, raison, jours restants (date du jour fictive : 2026-11-14, seuil 90 jours).
            ['7.4.1', CycleMatcher::STATUS_SUPPORTED, '', 301],
            ['7.2.3', CycleMatcher::STATUS_SOON, '', 20],
            ['7.0.1', CycleMatcher::STATUS_EOL, '', -317],
            ['6.2.19', CycleMatcher::STATUS_EOL, '', null],
            ['2.8', CycleMatcher::STATUS_SUPPORTED, '', null],
            ['latest', CycleMatcher::STATUS_UNKNOWN, CycleMatcher::REASON_UNVERSIONED, null],
            ['3.1', CycleMatcher::STATUS_UNKNOWN, CycleMatcher::REASON_NO_CYCLE, null],
        ];
    }

    #[DataProvider('statuses')]
    public function testSupportStatusAndDaysLeft(string $tag, string $status, string $reason, ?int $days): void
    {
        $result = CycleMatcher::evaluate('redis', $tag, self::cycles(), '2026-11-14', 90);

        $this->assertSame($status, $result['status']);
        $this->assertSame($reason, $result['reason']);
        $this->assertSame($days, $result['days']);
    }

    public function testEndOfSupportDayItselfIsEndOfSupport(): void
    {
        $cycles = [['cycle' => '15', 'codename' => '', 'eol' => '2026-11-14', 'latest' => '']];

        $status = static fn (string $today, int $warning): string
            => CycleMatcher::evaluate('p', '15.1', $cycles, $today, $warning)['status'];
        $this->assertSame(CycleMatcher::STATUS_EOL, $status('2026-11-14', 90));
        $this->assertSame(CycleMatcher::STATUS_SOON, $status('2026-11-13', 90));
        $this->assertSame(1, CycleMatcher::evaluate('p', '15.1', $cycles, '2026-11-13', 0)['days']);
        $this->assertSame(
            CycleMatcher::STATUS_SUPPORTED,
            CycleMatcher::evaluate('p', '15.1', $cycles, '2026-11-13', 0)['status']
        );
    }

    public function testUnknownIsNeverSupported(): void
    {
        $noProduct = CycleMatcher::evaluate('', '16.4', self::cycles(), '2026-11-14', 90);
        $notChecked = CycleMatcher::evaluate('postgresql', '16.4', null, '2026-11-14', 90);
        $notFound = CycleMatcher::evaluate('postgresql', '16.4', [], '2026-11-14', 90, true);

        $this->assertSame([CycleMatcher::STATUS_UNKNOWN, CycleMatcher::REASON_NO_PRODUCT], [
            $noProduct['status'], $noProduct['reason'],
        ]);
        $this->assertSame([CycleMatcher::STATUS_UNKNOWN, CycleMatcher::REASON_NOT_CHECKED], [
            $notChecked['status'], $notChecked['reason'],
        ]);
        $this->assertSame([CycleMatcher::STATUS_UNKNOWN, CycleMatcher::REASON_PRODUCT_NOT_FOUND], [
            $notFound['status'], $notFound['reason'],
        ]);
    }

    public function testClientReadsApiV1(): void
    {
        $calls = [];
        $client = new LifecycleClient(self::SOURCE, static function (
            string $url,
            array $query,
            array $headers
        ) use (&$calls): string {
            $calls[] = [$url, $query, $headers];

            return (string) json_encode(['schema_version' => '1.2.0', 'result' => ['name' => 'fictif', 'releases' => [
                ['name' => '18', 'codename' => null, 'isEol' => false, 'eolFrom' => '2030-11-14',
                    'latest' => ['name' => '18.7']],
                ['name' => '9', 'isEol' => true, 'eolFrom' => null, 'latest' => null],
                ['name' => '8', 'isEol' => false, 'eolFrom' => '2026-02-30T00:00:00Z'],
                ['codename' => 'sans nom'],
            ]]]);
        });

        $this->assertSame([
            ['cycle' => '18', 'codename' => '', 'eol' => '2030-11-14', 'latest' => '18.7'],
            ['cycle' => '9', 'codename' => '', 'eol' => LifecycleClient::EOL_WITHOUT_DATE, 'latest' => ''],
            ['cycle' => '8', 'codename' => '', 'eol' => '', 'latest' => ''],
        ], $client->cycles('postgresql'));
        $this->assertSame('https://cycles.exemple.test/api/v1/products/postgresql/', $calls[0][0]);
        $this->assertSame([], $calls[0][1]);
        $this->assertSame(['Accept' => 'application/json'], $calls[0][2]);
        $this->assertSame(1, $client->requests());
    }

    public function testClientReadsLegacyApi(): void
    {
        $client = new LifecycleClient('https://cycles.exemple.test/api', static fn (string $url): string
            => $url === 'https://cycles.exemple.test/api/debian.json' ? (string) json_encode([
                ['cycle' => '12', 'codename' => 'Bookworm', 'eol' => '2026-06-10', 'latest' => '12.12'],
                ['cycle' => 11, 'eol' => true],
                ['cycle' => '13', 'eol' => false],
            ]) : throw new \RuntimeException('HTTP 404 returned for ' . $url));

        $this->assertSame([
            ['cycle' => '12', 'codename' => 'Bookworm', 'eol' => '2026-06-10', 'latest' => '12.12'],
            ['cycle' => '11', 'codename' => '', 'eol' => LifecycleClient::EOL_WITHOUT_DATE, 'latest' => ''],
            ['cycle' => '13', 'codename' => '', 'eol' => '', 'latest' => ''],
        ], $client->cycles('debian'));
    }

    /**
     * @return list<array{string, string}>
     */
    public static function failures(): array
    {
        return [
            ['HTTP 404 returned for "https://cycles.exemple.test/x"', LifecycleException::NOT_FOUND],
            ['HTTP 429 returned', LifecycleException::RATE_LIMITED],
            ['HTTP 503 returned', LifecycleException::SERVER_ERROR],
            ['Host "10.0.0.1" is blocked for this context', LifecycleException::PRIVATE_NETWORK],
            ['Could not resolve host', LifecycleException::UNREACHABLE],
            ['__json__', LifecycleException::UNEXPECTED],
        ];
    }

    #[DataProvider('failures')]
    public function testFailuresGiveStableCodes(string $message, string $code): void
    {
        $client = new LifecycleClient(self::SOURCE, static fn (): string => $message === '__json__'
            ? '<html>panne</html>' : throw new \RuntimeException($message));

        try {
            $client->cycles('postgresql');
            $this->fail('Exception attendue');
        } catch (LifecycleException $e) {
            $this->assertSame($code, $e->getMessage());
        }
    }

    public function testInvalidProductIsNeverRequested(): void
    {
        $client = new LifecycleClient(self::SOURCE, static fn (): string => throw new \LogicException('appel'));

        $this->expectException(LifecycleException::class);
        $client->cycles('../../etc');
    }

    public function testEolTicketDecision(): void
    {
        $soon = ['status' => CycleMatcher::STATUS_SOON, 'product' => 'postgresql', 'cycle' => '15',
            'eol' => '2026-12-30', 'days' => 45];
        $later = ['status' => CycleMatcher::STATUS_SUPPORTED, 'days' => 700, 'cycle' => '16',
            'eol' => '2028-10-15'] + $soon;
        $unknown = ['status' => CycleMatcher::STATUS_UNKNOWN, 'days' => null, 'cycle' => '', 'eol' => ''] + $soon;

        $this->assertTrue(EolTicketPolicy::triggered($soon, 60));
        $this->assertFalse(EolTicketPolicy::triggered($soon, 30));
        $this->assertTrue(EolTicketPolicy::triggered($later, 800));
        $this->assertFalse(EolTicketPolicy::triggered($unknown, 3650));
        $passed = ['status' => CycleMatcher::STATUS_EOL, 'days' => null] + $soon;
        $this->assertTrue(EolTicketPolicy::triggered($passed, 0));

        $this->assertSame(TicketPolicy::ACTION_CREATE, EolTicketPolicy::decide($soon, null, false, true));
        $this->assertSame(TicketPolicy::ACTION_NONE, EolTicketPolicy::decide($soon, null, false, false));
        $tracked = ['product' => 'postgresql', 'cycle' => '15', 'eol' => '2026-12-30'];
        // Ticket ouvert : rien tant que rien ne change, suivi quand la branche change.
        $this->assertSame(TicketPolicy::ACTION_NONE, EolTicketPolicy::decide($soon, $tracked, true, true));
        $this->assertSame(TicketPolicy::ACTION_FOLLOWUP, EolTicketPolicy::decide($later, $tracked, true, false));
        // Ticket fermé pour cette branche : pas de second ticket ; autre branche : nouveau ticket.
        $this->assertSame(TicketPolicy::ACTION_NONE, EolTicketPolicy::decide($soon, $tracked, false, true));
        $this->assertSame(
            TicketPolicy::ACTION_CREATE,
            EolTicketPolicy::decide(['cycle' => '14'] + $soon, $tracked, false, true)
        );
        $this->assertSame(TicketPolicy::ACTION_NONE, EolTicketPolicy::decide($unknown, $tracked, true, false));
        $this->assertSame('resolved', EolTicketPolicy::followupKind(false));
    }
}

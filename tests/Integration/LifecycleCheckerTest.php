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
use GlpiPlugin\Dockerinventory\ImageVersion;
use GlpiPlugin\Dockerinventory\Lifecycle\CycleMatcher;
use GlpiPlugin\Dockerinventory\Lifecycle\LifecycleChecker;
use GlpiPlugin\Dockerinventory\Lifecycle\LifecycleClient;
use GlpiPlugin\Dockerinventory\Lifecycle\LifecycleException;
use GlpiPlugin\Dockerinventory\Lifecycle\Settings;
use GlpiPlugin\Dockerinventory\LifecycleProduct;
use GlpiPlugin\Dockerinventory\Service;
use GlpiPlugin\Dockerinventory\Ticket\EolTickets;
use ITILFollowup;
use PHPUnit\Framework\TestCase;
use Ticket;

/**
 * Fin de support (#26) contre la vraie base GLPI : cache des cycles de vie, panne de la source
 * (données en cache gardées), produit inconnu, filtre de la page des versions et tickets de fin de
 * support. Source en mémoire (adresse fictive), jamais endoflife.date ; produits et images fictifs.
 */
final class LifecycleCheckerTest extends TestCase
{
    private const NOW = '2026-11-14 10:00:00';

    /** Images fictives => produit fictif. */
    private const MAP = [
        'phpunit-fictif/base'    => 'base-fictive',
        'phpunit-fictif/cache'   => 'cache-fictif',
        'phpunit-fictif/inconnu' => 'produit-absent',
    ];

    private int $entityId = 0;

    private int $connectionId = 0;

    /** @var array<string, int> */
    private array $services = [];

    /** @var list<array<string, mixed>> Cache des cycles de vie avant le test (rétabli ensuite). */
    private array $savedCache = [];

    /** @var array<string, mixed> */
    private array $savedSettings = [];

    /** @var list<string> Requêtes envoyées à la source en mémoire. */
    private array $calls = [];

    private string $failure = '';

    private mixed $savedTime = null;

    protected function setUp(): void
    {
        global $DB;

        $this->savedSettings = Settings::get();
        $this->savedTime = $_SESSION['glpi_currenttime'] ?? null;
        foreach ($DB->request(['FROM' => LifecycleChecker::CACHE_TABLE]) as $row) {
            unset($row['id']);
            $this->savedCache[] = $row;
        }
        $DB->delete(LifecycleChecker::CACHE_TABLE, ['id' => ['>', 0]]);
        foreach (self::MAP as $image => $product) {
            $DB->delete(LifecycleProduct::getTable(), ['image' => $image]);
            $added = (new LifecycleProduct())->add(['image' => $image, 'product' => $product]);
            $this->assertGreaterThan(0, (int) $added);
        }

        $suffix = bin2hex(random_bytes(4));
        $this->entityId = (int) (new \Entity())->add([
            'name'        => 'PHPUnit fin de support ' . $suffix,
            'entities_id' => 0,
        ]);
        \Session::changeActiveEntities(0, true);
        $this->connectionId = (int) (new Connection())->add([
            'name'        => 'Portainer fin de support PHPUnit ' . $suffix,
            'entities_id' => $this->entityId,
            'url'         => 'https://portainer.exemple.test:9443',
            'api_key'     => 'ptr_fictive_cle_fin_de_support',
        ]);
        $this->assertGreaterThan(0, $this->connectionId);
        foreach (
            [
                'base-ancienne'  => ['phpunit-fictif/base', '13.4'],
                'base-bientot'   => ['phpunit-fictif/base:15.8', '15.8'],
                'base-recente'   => ['docker.io/phpunit-fictif/base', '16.4-alpine'],
                'cache-flottant' => ['phpunit-fictif/cache', 'latest'],
                'inconnu'        => ['phpunit-fictif/inconnu', '1.0'],
                'sans-produit'   => ['phpunit-fictif/sans-correspondance', '2.0'],
            ] as $name => [$image, $tag]
        ) {
            $DB->insert(Service::getTable(), [
                'name'                                  => $name,
                'entities_id'                           => $this->entityId,
                'plugin_dockerinventory_connections_id' => $this->connectionId,
                'service_uid'                           => 'phpunit-eol-' . $name,
                'stack_name'                            => 'stack-fictive',
                'image'                                 => $image,
                'tag'                                   => $tag,
            ]);
            $this->services[$name] = (int) $DB->insertId();
            $DB->insert(ImageVersion::getTable(), [
                'entities_id'                           => $this->entityId,
                'plugin_dockerinventory_connections_id' => 0,
                'plugin_dockerinventory_services_id'    => $this->services[$name],
                'image'                                 => $image,
                'deployed_tag'                          => $tag,
                'gap'                                   => 'unknown',
                'source'                                => ImageVersion::SOURCE_REGISTRY,
            ]);
        }
        Settings::save(true, 90);
        LifecycleChecker::reset();
    }

    protected function tearDown(): void
    {
        global $DB;

        $ids = array_values($this->services);
        if ($ids !== []) {
            foreach (
                $DB->request(['SELECT' => ['tickets_id'], 'FROM' => EolTickets::TABLE,
                'WHERE' => ['plugin_dockerinventory_services_id' => $ids]]) as $row
            ) {
                (new Ticket())->delete(['id' => (int) $row['tickets_id']], true);
            }
            $DB->delete(EolTickets::TABLE, ['plugin_dockerinventory_services_id' => $ids]);
            $DB->delete(ImageVersion::getTable(), ['plugin_dockerinventory_services_id' => $ids]);
            $DB->delete(Service::getTable(), ['id' => $ids]);
        }
        foreach ((new Ticket())->find(['entities_id' => $this->entityId]) as $row) {
            (new Ticket())->delete(['id' => (int) $row['id']], true);
        }
        (new Connection())->delete(['id' => $this->connectionId], true);
        (new \Entity())->delete(['id' => $this->entityId], true);
        $DB->delete(LifecycleProduct::getTable(), ['image' => array_keys(self::MAP)]);
        $DB->delete(LifecycleChecker::CACHE_TABLE, ['id' => ['>', 0]]);
        foreach ($this->savedCache as $row) {
            $DB->insert(LifecycleChecker::CACHE_TABLE, $row);
        }
        Settings::save((bool) $this->savedSettings['enabled'], (int) $this->savedSettings['warning_days']);
        LifecycleChecker::reset();
        $_SESSION['glpi_currenttime'] = $this->savedTime ?? date('Y-m-d H:i:s');
        $_SESSION['MESSAGE_AFTER_REDIRECT'] = [];
    }

    private function checker(string $now = self::NOW): LifecycleChecker
    {
        return new LifecycleChecker(new LifecycleClient(
            'https://cycles.exemple.test/api/v1/products/{product}/',
            function (string $url): string {
                $this->calls[] = $url;
                if ($this->failure !== '') {
                    throw new \RuntimeException($this->failure);
                }
                $product = basename(rtrim($url, '/'));
                $releases = match ($product) {
                    'base-fictive' => [
                        ['name' => '16', 'isEol' => false, 'eolFrom' => '2028-10-15', 'latest' => ['name' => '16.10']],
                        ['name' => '15', 'isEol' => false, 'eolFrom' => '2026-12-29', 'latest' => ['name' => '15.14']],
                        ['name' => '13', 'isEol' => true, 'eolFrom' => '2025-11-13', 'latest' => ['name' => '13.22']],
                    ],
                    'cache-fictif' => [
                        ['name' => '7.4', 'isEol' => false, 'eolFrom' => null, 'latest' => ['name' => '7.4.5']],
                    ],
                    // Autres produits des images déjà présentes dans l'instance : aucun cycle.
                    'produit-absent' => throw new \RuntimeException('HTTP 404 returned'),
                    default          => [],
                };

                return (string) json_encode(['result' => ['name' => $product, 'releases' => $releases]]);
            }
        ), $now);
    }

    /**
     * @return array{status: string, reason: string, product: string, cycle: string, eol: string,
     *     days: int|null, latest: string}
     */
    private function evaluate(string $service, string $now = self::NOW): array
    {
        global $DB;

        $row = $DB->request(['FROM' => Service::getTable(), 'WHERE' => ['id' => $this->services[$service]]])->current();
        $this->assertIsArray($row);

        return (new LifecycleChecker(null, $now))->evaluate((string) $row['image'], (string) $row['tag']);
    }

    private function productCalls(string $product): int
    {
        return count(array_filter(
            $this->calls,
            static fn (string $url): bool => str_contains($url, '/' . $product . '/')
        ));
    }

    public function testDisabledSourceIsNeverCalled(): void
    {
        Settings::save(false, 90);

        $report = $this->checker()->refresh();

        $this->assertSame(LifecycleChecker::REPORT_DISABLED, $report['status']);
        $this->assertSame([], $this->calls);
        $evaluation = $this->evaluate('base-ancienne');
        $this->assertSame(CycleMatcher::STATUS_UNKNOWN, $evaluation['status']);
        $this->assertSame(CycleMatcher::REASON_DISABLED, $evaluation['reason']);
    }

    public function testStatusesFromCachedCycles(): void
    {
        $report = $this->checker()->refresh();

        $this->assertSame(LifecycleChecker::REPORT_OK, $report['status']);
        $this->assertSame(1, $this->productCalls('base-fictive'));
        $this->assertSame(1, $this->productCalls('produit-absent'));
        // L'image sans correspondance n'est jamais envoyée à la source.
        $this->assertSame([], array_filter($this->calls, static fn (string $u): bool => str_contains($u, 'sans')));

        $old = $this->evaluate('base-ancienne');
        $this->assertSame([CycleMatcher::STATUS_EOL, '13', -366], [$old['status'], $old['cycle'], $old['days']]);
        $soon = $this->evaluate('base-bientot');
        $this->assertSame([CycleMatcher::STATUS_SOON, '15', 45], [$soon['status'], $soon['cycle'], $soon['days']]);
        $recent = $this->evaluate('base-recente');
        $this->assertSame([CycleMatcher::STATUS_SUPPORTED, '16'], [$recent['status'], $recent['cycle']]);
        $floating = $this->evaluate('cache-flottant');
        $this->assertSame(CycleMatcher::REASON_UNVERSIONED, $floating['reason']);
        $this->assertSame(CycleMatcher::REASON_PRODUCT_NOT_FOUND, $this->evaluate('inconnu')['reason']);
        $this->assertSame(CycleMatcher::REASON_NO_PRODUCT, $this->evaluate('sans-produit')['reason']);
        // « Bientôt » suit le réglage : à 30 jours, la branche 15 est encore « maintenue ».
        Settings::save(true, 30);
        $this->assertSame(CycleMatcher::STATUS_SUPPORTED, $this->evaluate('base-bientot')['status']);
    }

    public function testCacheIsValidOneDay(): void
    {
        $this->checker()->refresh();
        $this->assertSame(1, $this->productCalls('base-fictive'));
        $before = count($this->calls);

        // 23 heures plus tard : rien n'est relu, produit inconnu de la source compris.
        $report = $this->checker('2026-11-15 09:00:00')->refresh();
        $this->assertSame($before, count($this->calls));
        $this->assertGreaterThanOrEqual(3, $report['cached']);
        $this->assertSame(0, $report['requests']);

        $this->checker('2026-11-15 10:30:00')->refresh();
        $this->assertSame(2, $this->productCalls('base-fictive'));
    }

    public function testFailureKeepsCachedDataAndNeverBlocks(): void
    {
        $this->checker()->refresh();
        $before = count($this->calls);

        // Une journée plus tard, la source est en panne : un seul appel, les autres sont sautés.
        $this->failure = 'HTTP 503 returned';
        $report = $this->checker('2026-11-15 11:00:00')->refresh();

        $this->assertSame(LifecycleChecker::REPORT_PARTIAL, $report['status']);
        $this->assertSame($before + 1, count($this->calls));
        $this->assertSame(LifecycleException::SERVER_ERROR, $report['errors'][0]['code']);
        $this->assertContains(LifecycleException::SKIPPED, array_column($report['errors'], 'code'));
        // Hors ligne : les cycles déjà lus restent utilisés, avec la date de la dernière lecture.
        $old = $this->evaluate('base-ancienne', '2026-11-15 11:00:00');
        $this->assertSame(CycleMatcher::STATUS_EOL, $old['status']);
        $state = (new LifecycleChecker(null, '2026-11-15 11:00:00'))->sourceState();
        $this->assertSame(self::NOW, $state['last_success']);
        $this->assertSame('2026-11-15 11:00:00', $state['last_attempt']);
        $this->assertNotEmpty($state['errors']);
    }

    public function testFailureWithoutCacheIsNotChecked(): void
    {
        $this->failure = 'Could not resolve host';

        $report = $this->checker()->refresh();

        $this->assertSame(LifecycleChecker::REPORT_PARTIAL, $report['status']);
        $evaluation = $this->evaluate('base-recente');
        $this->assertSame(CycleMatcher::STATUS_UNKNOWN, $evaluation['status']);
        $this->assertSame(CycleMatcher::REASON_NOT_CHECKED, $evaluation['reason']);
    }

    public function testOverviewFiltersBySupportStatus(): void
    {
        $_SESSION['glpi_currenttime'] = self::NOW;
        $this->checker()->refresh();
        LifecycleChecker::reset();

        $names = static fn (array $data): array => array_values(array_intersect(
            array_map(static fn (array $row): string => (string) ($row['service']['name'] ?? ''), $data['services']),
            ['base-ancienne', 'base-bientot', 'base-recente', 'cache-flottant', 'inconnu', 'sans-produit']
        ));

        $watch = ImageVersion::overview('', 0, ImageVersion::FILTER_EOL_WATCH);
        $this->assertEqualsCanonicalizing(['base-ancienne', 'base-bientot'], $names($watch));
        $this->assertGreaterThanOrEqual(1, $watch['eol_counts'][CycleMatcher::STATUS_EOL]);
        $unknown = ImageVersion::overview('', 0, CycleMatcher::STATUS_UNKNOWN);
        $this->assertEqualsCanonicalizing(['cache-flottant', 'inconnu', 'sans-produit'], $names($unknown));
        $all = ImageVersion::overview('', 0, '');
        $this->assertCount(6, $names($all));
        $row = array_values(array_filter(
            $all['services'],
            static fn (array $r): bool => ($r['service']['name'] ?? '') === 'base-bientot'
        ))[0];
        $this->assertSame(CycleMatcher::STATUS_SOON, $row['eol']['status_code']);
        $this->assertSame('15', $row['eol']['cycle']);
        $this->assertStringContainsString('45', $row['eol']['detail']);
    }

    public function testEolTicketsAboveThreshold(): void
    {
        global $DB;

        $_SESSION['glpi_currenttime'] = self::NOW;
        // Tickets désactivés (par défaut) : aucun ticket.
        $report = $this->checker()->refresh();
        $this->assertSame(0, $report['tickets']['created']);

        $connection = new Connection();
        $connection->getFromDB($this->connectionId);
        $this->assertTrue($connection->update([
            'id'                 => $this->connectionId,
            'ticket_eol_enabled' => 1,
            'ticket_eol_days'    => 60,
        ]));
        $report = (new EolTickets(new LifecycleChecker(null, self::NOW)))->process();

        // Branche 13 passée et branche 15 dans 45 jours (< 60) ; 16 et les inconnues : rien.
        $this->assertSame(2, $report['created']);
        $old = $this->ticketOf('base-ancienne');
        // Titre traduit dans la langue de la session (anglais en CI, français en développement).
        $this->assertSame(EolTickets::title(
            ['name' => 'base-ancienne', 'stack_name' => 'stack-fictive'],
            ['product' => 'base-fictive', 'cycle' => '13']
        ), $old->fields['name']);
        $this->assertSame($this->entityId, (int) $old->fields['entities_id']);
        $this->assertNull(EolTickets::ticketFor($this->services['base-recente']));

        // Relancé : pas de doublon.
        $again = (new EolTickets(new LifecycleChecker(null, self::NOW)))->process();
        $this->assertSame(0, $again['created']);
        $this->assertSame(0, $again['followups']);

        // Branche maintenue déployée : un suivi, sans changer le statut du ticket.
        $status = (int) $old->fields['status'];
        $DB->update(Service::getTable(), ['tag' => '16.4'], ['id' => $this->services['base-ancienne']]);
        $followup = (new EolTickets(new LifecycleChecker(null, self::NOW)))->process();
        $this->assertSame(1, $followup['followups']);
        $old->getFromDB($old->getID());
        $this->assertSame($status, (int) $old->fields['status']);
        $this->assertSame(1, countElementsInTable(
            ITILFollowup::getTable(),
            ['itemtype' => Ticket::class, 'items_id' => $old->getID()]
        ));

        // Ticket clos pour la branche 15 : pas de nouveau ticket pour la même branche.
        $soon = $this->ticketOf('base-bientot');
        $soon->update(['id' => $soon->getID(), 'status' => \CommonITILObject::CLOSED]);
        $closed = (new EolTickets(new LifecycleChecker(null, self::NOW)))->process();
        $this->assertSame(0, $closed['created']);
    }

    private function ticketOf(string $service): Ticket
    {
        global $DB;

        $row = $DB->request(['SELECT' => ['tickets_id'], 'FROM' => EolTickets::TABLE,
            'WHERE' => ['plugin_dockerinventory_services_id' => $this->services[$service]]])->current();
        $this->assertIsArray($row, $service);
        $ticket = new Ticket();
        $this->assertTrue($ticket->getFromDB((int) $row['tickets_id']));

        return $ticket;
    }
}

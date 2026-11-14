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

use Appliance;
use ApplianceEnvironment;
use GlpiPlugin\Dockerinventory\Connection;
use GlpiPlugin\Dockerinventory\ImageVersion;
use GlpiPlugin\Dockerinventory\Importer;
use GlpiPlugin\Dockerinventory\Portainer\PortainerClient;
use GlpiPlugin\Dockerinventory\Service;
use GlpiPlugin\Dockerinventory\Tests\PortainerFixture;
use GlpiPlugin\Dockerinventory\Wud\WudClient;
use GlpiPlugin\Dockerinventory\Wud\WudImporter;
use PHPUnit\Framework\TestCase;

/**
 * Import Portainer puis lecture de WUD contre la vraie base GLPI, avec les jeux fictifs des faux
 * serveurs (tests/mock-portainer, tests/mock-wud) servis par des transports en mémoire. Les
 * assertions portent sur des codes (écarts, raisons), jamais sur des libellés traduits.
 */
final class WudImporterTest extends TestCase
{
    private const PASSWORD = 'wud_mot_de_passe_fictif';

    /** @var array<string, mixed> */
    private array $portainer;

    /** @var array<string, mixed> */
    private array $wud;

    /** @var list<string> */
    private array $requested = [];

    private ?string $failure = null;

    private int $entityId = 0;

    private int $environmentId = 0;

    private int $portainerId = 0;

    private int $wudId = 0;

    protected function setUp(): void
    {
        $this->portainer = PortainerFixture::load();
        $this->wud = json_decode((string) file_get_contents(dirname(__DIR__) . '/mock-wud/fixture.json'), true);
        $suffix = bin2hex(random_bytes(4));
        $this->entityId = (int) (new \Entity())->add(['name' => 'PHPUnit WUD ' . $suffix, 'entities_id' => 0]);
        // Entités actives recalculées : la nouvelle entité est visible (onglets, page des versions).
        \Session::changeActiveEntities(0, true);
        $this->environmentId = (int) (new ApplianceEnvironment())->add(['name' => 'Production WUD ' . $suffix]);
        $this->portainerId = (int) (new Connection())->add([
            'name'                     => 'Portainer WUD PHPUnit ' . $suffix,
            'entities_id'              => $this->entityId,
            'url'                      => 'https://portainer.exemple.test:9443',
            'api_key'                  => 'ptr_fictive_cle_wud',
            'applianceenvironments_id' => $this->environmentId,
        ]);
        $this->wudId = (int) (new Connection())->add([
            'name'        => 'WUD PHPUnit ' . $suffix,
            'type'        => Connection::TYPE_WUD,
            'entities_id' => $this->entityId,
            'url'         => 'https://wud.exemple.test:3000/',
            'username'    => 'lecteur',
            'api_key'     => self::PASSWORD,
        ]);
        $this->assertGreaterThan(0, $this->portainerId);
        $this->assertGreaterThan(0, $this->wudId);
        $this->importPortainer();
    }

    protected function tearDown(): void
    {
        global $DB;

        $DB->delete(ImageVersion::getTable(), ['plugin_dockerinventory_connections_id' => $this->wudId]);
        $appliance = new Appliance();
        foreach (
            $DB->request(['SELECT' => ['appliances_id'], 'FROM' => 'glpi_plugin_dockerinventory_stacks',
            'WHERE' => ['plugin_dockerinventory_connections_id' => $this->portainerId]]) as $row
        ) {
            $appliance->delete(['id' => (int) $row['appliances_id']], true);
        }
        $serviceIds = array_column(iterator_to_array($DB->request(['SELECT' => ['id'], 'FROM' => Service::getTable(),
            'WHERE' => ['plugin_dockerinventory_connections_id' => $this->portainerId]]), false), 'id');
        if ($serviceIds !== []) {
            $DB->delete(
                'glpi_plugin_dockerinventory_services_nodes',
                ['plugin_dockerinventory_services_id' => $serviceIds]
            );
        }
        foreach (
            [Service::getTable(), 'glpi_plugin_dockerinventory_nodes', 'glpi_plugin_dockerinventory_stacks'] as $table
        ) {
            $DB->delete($table, ['plugin_dockerinventory_connections_id' => $this->portainerId]);
        }
        (new Connection())->delete(['id' => $this->wudId], true);
        (new Connection())->delete(['id' => $this->portainerId], true);
        (new ApplianceEnvironment())->delete(['id' => $this->environmentId], true);
        (new \Entity())->delete(['id' => $this->entityId], true);
        $_SESSION['MESSAGE_AFTER_REDIRECT'] = [];
    }

    private function connection(int $id): Connection
    {
        $connection = new Connection();
        $connection->getFromDB($id);

        return $connection;
    }

    private function importPortainer(): void
    {
        $fixture = $this->portainer;
        (new Importer(static fn (Connection $c): PortainerClient => new PortainerClient(
            'https://portainer.exemple.test:9443',
            'ptr_fictive_cle_wud',
            static function (string $url) use ($fixture): string {
                $path = (string) parse_url($url, PHP_URL_PATH);
                return PortainerFixture::respond($fixture, $path);
            }
        )))->import($this->connection($this->portainerId));
    }

    /**
     * @return array<string, mixed>
     */
    private function importWud(): array
    {
        return (new WudImporter(fn (Connection $connection): WudClient => new WudClient(
            (string) $connection->fields['url'],
            (string) $connection->fields['username'],
            $connection->getDecryptedApiKey(),
            function (string $url, array $query, array $headers): string {
                $this->assertSame(
                    'Basic ' . base64_encode('lecteur:' . self::PASSWORD),
                    $headers['Authorization'] ?? null
                );
                $path = (string) parse_url($url, PHP_URL_PATH);
                $this->requested[] = $path;
                if ($this->failure !== null) {
                    throw new \RuntimeException($this->failure);
                }

                return (string) json_encode($path === '/api/app' ? $this->wud['app'] : $this->wud['containers']);
            }
        )))->import($this->connection($this->wudId));
    }

    /**
     * @return array<string, array<string, mixed>> Lignes de version, par service (ou par conteneur).
     */
    private function versions(): array
    {
        $rows = [];
        foreach (ImageVersion::fetch(['v.plugin_dockerinventory_connections_id' => $this->wudId]) as $row) {
            $rows[(string) ($row['service_name'] ?? '') !== '' ? (string) $row['service_name']
                : 'conteneur:' . $row['containers']] = $row;
        }

        return $rows;
    }

    public function testWudConnectionStoresEncryptedPasswordAndKeepsType(): void
    {
        $wud = $this->connection($this->wudId);
        $this->assertSame(Connection::TYPE_WUD, $wud->fields['type']);
        $this->assertTrue($wud->isWud());
        $this->assertSame('https://wud.exemple.test:3000', $wud->fields['url']);
        $this->assertNotSame(self::PASSWORD, $wud->fields['api_key']);
        $this->assertSame(self::PASSWORD, $wud->getDecryptedApiKey());

        // Mot de passe laissé vide : conservé ; type jamais modifiable après la création.
        $wud->update(['id' => $this->wudId, 'api_key' => '', 'type' => Connection::TYPE_PORTAINER]);
        $wud = $this->connection($this->wudId);
        $this->assertSame(self::PASSWORD, $wud->getDecryptedApiKey());
        $this->assertSame(Connection::TYPE_WUD, $wud->fields['type']);

        // WUD sans authentification : mot de passe effacé explicitement.
        $wud->update(['id' => $this->wudId, '_clear_api_key' => 1]);
        $this->assertSame('', $this->connection($this->wudId)->getDecryptedApiKey());

        // Pas de mot de passe obligatoire pour WUD, toujours une clé pour Portainer.
        $other = new Connection();
        $id = (int) $other->add([
            'name' => 'WUD sans mot de passe', 'type' => Connection::TYPE_WUD, 'entities_id' => $this->entityId,
            'url'  => 'https://wud2.exemple.test',
        ]);
        $this->assertGreaterThan(0, $id);
        $other->delete(['id' => $id], true);
        $this->assertFalse((new Connection())->add([
            'name' => 'Portainer sans clé', 'entities_id' => $this->entityId, 'url' => 'https://p.exemple.test',
        ]));
        $this->assertFalse((new Connection())->add([
            'name' => 'Type inconnu', 'type' => 'kubernetes', 'entities_id' => $this->entityId,
            'url'  => 'https://h.exemple.test',
        ]));
    }

    public function testImportLinksServicesAndListsUnmatchedContainers(): void
    {
        $report = $this->importWud();

        $this->assertSame(Connection::STATUS_OK, $report['status'], (string) json_encode($report));
        $this->assertSame('8.1.1', $report['wud_version']);
        $this->assertSame(12, $report['containers']);
        // 7 services Swarm, le service Compose adminer et le conteneur autonome sauvegarde-nocturne (#25).
        $this->assertSame(9, $report['services']);
        $this->assertSame(0, $report['unmatched']);
        $this->assertSame(1, $report['out_of_sync']);
        $this->assertSame(['/api/app', '/api/containers'], $this->requested);

        $versions = $this->versions();
        $gaps = array_map(static fn (array $row): string => (string) $row['gap'], $versions);
        $this->assertSame('minor', $gaps['boutique_web']);
        $this->assertSame('patch', $gaps['boutique_api']);
        $this->assertSame('major', $gaps['boutique_db']);
        $this->assertSame('digest', $gaps['boutique_cache']);
        $this->assertSame('untrackable', $gaps['supervision_agent']);
        $this->assertSame('none', $gaps['supervision_grafana']);
        $this->assertSame('untrackable', $gaps['outil_isole']);

        $web = $versions['boutique_web'];
        $this->assertSame(1, (int) $web['is_out_of_sync']);
        $this->assertSame(3, (int) $web['container_count']);
        $this->assertSame('2.4.1', $web['deployed_tag']);
        $this->assertSame('2.5.0', $web['latest_tag']);
        $this->assertSame('wud', $web['source']);
        $this->assertSame($this->environmentId, (int) $web['environment_id']);
        $this->assertSame($this->entityId, (int) $web['entities_id']);

        // Conteneurs de l'hôte autonome : rattachés par leur identifiant.
        $adminer = $versions['adminer'];
        $this->assertGreaterThan(0, (int) $adminer['plugin_dockerinventory_services_id']);
        $this->assertSame('container', $adminer['match_method']);
        $this->assertSame('patch', $adminer['gap']);
        $this->assertSame('4.8.4', $adminer['latest_tag']);
        $this->assertSame('container', $versions['sauvegarde-nocturne']['match_method']);
        $this->assertSame('unknown', $versions['sauvegarde-nocturne']['gap']);

        // Rapport sans le mot de passe.
        $this->assertStringNotContainsString(
            self::PASSWORD,
            (string) $this->connection($this->wudId)->fields['last_import_report']
        );
    }

    public function testReimportIsIdempotentAndForgetsVanishedContainers(): void
    {
        $this->importWud();
        $before = $this->versions();

        $this->importWud();
        $after = $this->versions();
        $this->assertSame(array_keys($before), array_keys($after));
        foreach ($before as $key => $row) {
            $this->assertSame($row['id'], $after[$key]['id'], $key);
        }

        // La réplica en retard a été mise à jour : plus de désynchronisation.
        foreach ($this->wud['containers'] as $i => $container) {
            if ($container['name'] === 'boutique_web.3.t03') {
                $this->wud['containers'][$i]['image']['tag']['value'] = '2.4.1';
                $this->wud['containers'][$i]['image']['digest'] = $this->wud['containers'][0]['image']['digest'];
            }
        }
        // Conteneur Compose arrêté : il disparaît de WUD, et de la liste.
        $this->wud['containers'] = array_values(array_filter(
            $this->wud['containers'],
            static fn (array $container): bool => $container['name'] !== 'outils-recette-adminer-1'
        ));
        $report = $this->importWud();

        $this->assertSame(0, $report['out_of_sync']);
        $this->assertSame(8, $report['services']);
        $versions = $this->versions();
        $this->assertSame(0, (int) $versions['boutique_web']['is_out_of_sync']);
        $this->assertArrayNotHasKey('adminer', $versions);
    }

    public function testFailingWudChangesNothing(): void
    {
        $this->importWud();
        $before = $this->versions();
        $this->failure = 'HTTP 401 returned for "https://wud.exemple.test:3000/api/app".';

        $report = $this->importWud();

        $this->assertSame(Connection::STATUS_ERROR, $report['status']);
        $this->assertSame([['endpoint' => '', 'code' => 'wud_unauthorized']], $report['errors']);
        $this->assertSame(array_keys($before), array_keys($this->versions()));
        $status = (int) $this->connection($this->wudId)->fields['last_import_status'];
        $this->assertSame(Connection::STATUS_ERROR, $status);
    }

    public function testRetiredServiceIsNotLinked(): void
    {
        global $DB;

        $DB->update(Service::getTable(), ['is_retired' => 1], [
            'plugin_dockerinventory_connections_id' => $this->portainerId,
            'name'                                  => 'boutique_api',
        ]);

        $report = $this->importWud();

        $this->assertSame(8, $report['services']);
        $this->assertSame(1, $report['unmatched']);
        $this->assertSame('no_service', $this->versions()['conteneur:boutique_api.1.t04']['match_method']);
    }

    public function testOverviewFilters(): void
    {
        $this->importWud();

        $mine = static fn (array $rows): array => array_values(array_filter(
            $rows,
            static fn (array $row): bool => str_starts_with((string) $row['connection_name'], 'WUD PHPUnit')
        ));
        $names = static fn (array $row): string => $row['service']['name'];
        $all = ImageVersion::overview('', 0);
        $this->assertCount(9, $mine($all['services']));
        $this->assertCount(0, $mine($all['unmatched']));
        $this->assertArrayHasKey($this->environmentId, $all['environments']);

        $major = $mine(ImageVersion::overview('major', 0)['services']);
        $this->assertSame(['boutique_db'], array_map($names, $major));

        $outdated = $mine(ImageVersion::overview(ImageVersion::FILTER_OUTDATED, 0)['services']);
        // boutique_api et adminer (service Compose) : correctif.
        $this->assertSame(['major', 'minor', 'patch', 'patch', 'digest'], array_column($outdated, 'gap_code'));

        // Environnement des applications créées : tous les services des stacks (Swarm et Compose) ;
        // outil_isole et sauvegarde-nocturne (sans stack, donc sans application) seulement avec
        // « sans environnement ».
        $this->assertCount(7, $mine(ImageVersion::overview('', $this->environmentId)['services']));
        $without = $mine(ImageVersion::overview('', -1)['services']);
        $this->assertSame(['outil_isole', 'sauvegarde-nocturne'], array_map($names, $without));
        $this->assertSame([], $mine(ImageVersion::overview('', $this->environmentId)['unmatched']));
    }

    public function testTabsAndNativeSearch(): void
    {
        $this->importWud();
        $service = new Service();
        $this->assertTrue($service->getFromDBByCrit([
            'plugin_dockerinventory_connections_id' => $this->portainerId,
            'name'                                  => 'boutique_web',
        ]));
        $this->assertNotSame('', (new ImageVersion())->getTabNameForItem($service));
        $appliance = new Appliance();
        $this->assertTrue($appliance->getFromDB((int) $service->fields['appliances_id']));
        $this->assertNotSame('', (new ImageVersion())->getTabNameForItem($appliance));

        ob_start();
        $this->assertTrue(ImageVersion::displayTabContentForItem($service));
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('2.5.0', $html);
        $this->assertStringNotContainsString(self::PASSWORD, $html);

        // Recherche GLPI : jointures service, application, environnement et filtre sur l'écart.
        $data = \Search::getDatas(ImageVersion::class, [
            'criteria' => [
                ['field' => 16, 'searchtype' => 'contains', 'value' => 'WUD PHPUnit'],
                ['link' => 'AND', 'field' => 8, 'searchtype' => 'equals', 'value' => 'major'],
            ],
        ], [1, 3, 4, 5, 6, 7, 8]);
        $this->assertSame(1, (int) $data['data']['totalcount']);
    }
}

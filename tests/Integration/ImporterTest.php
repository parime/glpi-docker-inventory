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
use Computer;
use GlpiPlugin\Dockerinventory\Connection;
use GlpiPlugin\Dockerinventory\Importer;
use GlpiPlugin\Dockerinventory\Node;
use GlpiPlugin\Dockerinventory\Portainer\PortainerClient;
use GlpiPlugin\Dockerinventory\Service;
use GlpiPlugin\Dockerinventory\Tests\PortainerFixture;
use PHPUnit\Framework\TestCase;

/**
 * Import complet contre la vraie base GLPI, avec le jeu de données fictif du faux Portainer
 * (tests/mock-portainer/fixture.json) servi par un transport en mémoire.
 */
final class ImporterTest extends TestCase
{
    private const KEY = 'ptr_fictive_cle_0123456789abcdef';

    /** @var array<string, mixed> */
    private array $fixture;

    /** @var list<string> Chemins demandés (sans la clé). */
    private array $requested = [];

    private ?string $failingPath = null;

    private int $connectionId = 0;

    private int $environmentId = 0;

    /** Entité propre au test : ni applications ni ordinateurs de l'instance ne s'y trouvent. */
    private int $entityId = 0;

    /** @var list<int> */
    private array $computerIds = [];

    protected function setUp(): void
    {
        $this->fixture = PortainerFixture::load();
        $suffix = bin2hex(random_bytes(4));
        $this->entityId = (int) (new \Entity())->add(['name' => 'PHPUnit Docker ' . $suffix, 'entities_id' => 0]);
        $this->assertGreaterThan(0, $this->entityId);
        $this->environmentId = (int) (new ApplianceEnvironment())->add(['name' => 'Production PHPUnit ' . $suffix]);
        $computer = new Computer();
        foreach (['swarm-node-01', 'swarm-node-02.exemple.test', 'docker-recette-01'] as $name) {
            $this->computerIds[] = (int) $computer->add(['name' => $name, 'entities_id' => $this->entityId]);
        }
        $connection = new Connection();
        $this->connectionId = (int) $connection->add([
            'name'                     => 'Portainer PHPUnit ' . $suffix,
            'entities_id'              => $this->entityId,
            'url'                      => 'https://portainer.exemple.test:9443/',
            'api_key'                  => self::KEY,
            'applianceenvironments_id' => $this->environmentId,
        ]);
        $this->assertGreaterThan(0, $this->connectionId);
    }

    protected function tearDown(): void
    {
        global $DB;

        $appliance = new Appliance();
        foreach (
            $DB->request(['SELECT' => ['appliances_id'], 'FROM' => 'glpi_plugin_dockerinventory_stacks',
            'WHERE' => ['plugin_dockerinventory_connections_id' => $this->connectionId]]) as $row
        ) {
            $appliance->delete(['id' => (int) $row['appliances_id']], true);
        }
        $serviceIds = array_column(iterator_to_array($DB->request(['SELECT' => ['id'], 'FROM' => Service::getTable(),
            'WHERE' => ['plugin_dockerinventory_connections_id' => $this->connectionId]]), false), 'id');
        if ($serviceIds !== []) {
            $DB->delete(
                'glpi_plugin_dockerinventory_services_nodes',
                ['plugin_dockerinventory_services_id' => $serviceIds]
            );
        }
        foreach ([Service::getTable(), Node::getTable(), 'glpi_plugin_dockerinventory_stacks'] as $table) {
            $DB->delete($table, ['plugin_dockerinventory_connections_id' => $this->connectionId]);
        }
        (new Connection())->delete(['id' => $this->connectionId], true);
        foreach ($this->computerIds as $id) {
            (new Computer())->delete(['id' => $id], true);
        }
        (new ApplianceEnvironment())->delete(['id' => $this->environmentId], true);
        (new \Entity())->delete(['id' => $this->entityId], true);
    }

    private function importer(): Importer
    {
        return new Importer(fn (Connection $connection): PortainerClient => new PortainerClient(
            (string) $connection->fields['url'],
            $connection->getDecryptedApiKey(),
            function (string $url, array $query, array $headers): string {
                $this->assertSame(self::KEY, $headers['X-API-Key']);
                $path = (string) parse_url($url, PHP_URL_PATH);
                $this->requested[] = $path;
                if ($path === $this->failingPath) {
                    throw new \RuntimeException('HTTP 500 returned for "' . $url . '".');
                }
                return PortainerFixture::respond($this->fixture, $path);
            }
        ));
    }

    private function connection(): Connection
    {
        $connection = new Connection();
        $connection->getFromDB($this->connectionId);

        return $connection;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function services(): array
    {
        global $DB;

        $rows = [];
        foreach (
            $DB->request(['FROM' => Service::getTable(),
            'WHERE' => ['plugin_dockerinventory_connections_id' => $this->connectionId]]) as $row
        ) {
            $rows[$row['name']] = $row;
        }

        return $rows;
    }

    public function testApiKeyIsStoredEncryptedAndKeptWhenLeftEmpty(): void
    {
        $connection = $this->connection();
        $this->assertNotSame(self::KEY, $connection->fields['api_key']);
        $this->assertSame(self::KEY, $connection->getDecryptedApiKey());
        $this->assertSame('https://portainer.exemple.test:9443', $connection->fields['url']);

        $connection->update(['id' => $this->connectionId, 'api_key' => '', 'comment' => 'modifiée']);
        $this->assertSame(self::KEY, $this->connection()->getDecryptedApiKey());
        $this->assertContains('api_key', Connection::$undisclosedFields);
    }

    public function testHttpIsRefusedUnlessAllowed(): void
    {
        $connection = new Connection();
        $this->assertFalse($connection->add([
            'name' => 'HTTP refusé', 'entities_id' => 0, 'url' => 'http://portainer.exemple.test', 'api_key' => 'x',
        ]));
        $this->assertFalse(
            $this->connection()->update(['id' => $this->connectionId, 'url' => 'http://portainer.exemple.test'])
        );
        $this->assertTrue($this->connection()->update([
            'id' => $this->connectionId, 'url' => 'http://portainer.exemple.test', 'allow_http' => 1,
        ]));
        $_SESSION['MESSAGE_AFTER_REDIRECT'] = [];
    }

    public function testImportCreatesAppliancesServicesAndNodeLinks(): void
    {
        $report = $this->importer()->import($this->connection());

        $this->assertSame(Connection::STATUS_OK, $report['status'], json_encode($report) ?: '');
        // Swarm : 2 stacks, 7 services, 3 nœuds ; hôte autonome : 2 stacks Compose, 6 services, l'hôte.
        $this->assertSame(4, $report['stacks']);
        $this->assertSame(13, $report['services']);
        $this->assertSame(4, $report['nodes']);
        $this->assertSame(3, $report['linked_nodes']);
        $this->assertSame(4, $report['appliances_created']);
        // Environnement Kubernetes jamais lu, environnement Docker seul sans erreur.
        $this->assertNotContains('/api/endpoints/3/docker/services', $this->requested);

        $services = $this->services();
        $web = $services['boutique_web'];
        $this->assertSame('boutique/web', $web['image']);
        $this->assertSame('2.4.1', $web['tag']);
        $this->assertStringStartsWith('sha256:', $web['digest']);
        $this->assertSame(3, (int) $web['replicas']);
        $this->assertSame(3, (int) $web['running_replicas']);
        $this->assertSame('443:8443/tcp', $web['ports']);
        $this->assertSame('boutique_front', $web['networks']);
        $this->assertSame('boutique_db_password, boutique_tls_cert', $web['secrets']);
        $this->assertNull($services['supervision_agent']['replicas']);
        $this->assertSame(0, (int) $services['outil_isole']['appliances_id']);

        $appliance = new Appliance();
        $this->assertTrue($appliance->getFromDB((int) $web['appliances_id']));
        $this->assertSame('boutique', $appliance->fields['name']);
        $this->assertSame($this->environmentId, (int) $appliance->fields['applianceenvironments_id']);

        $nodes = [];
        foreach ((new Node())->find(['plugin_dockerinventory_connections_id' => $this->connectionId]) as $row) {
            $nodes[$row['hostname']] = (int) $row['computers_id'];
        }
        $this->assertSame($this->computerIds[0], $nodes['swarm-node-01.exemple.test']);
        $this->assertSame($this->computerIds[1], $nodes['swarm-node-02.exemple.test']);
        $this->assertSame(0, $nodes['swarm-node-03.exemple.test']);

        $report = json_decode((string) $this->connection()->fields['last_import_report'], true);
        $this->assertSame(13, $report['services']);
        $this->assertStringNotContainsString(self::KEY, (string) $this->connection()->fields['last_import_report']);
    }

    public function testReimportIsIdempotentAndNeverModifiesExistingAppliance(): void
    {
        $this->importer()->import($this->connection());
        $before = $this->services();
        $applianceId = (int) $before['boutique_web']['appliances_id'];
        (new Appliance())->update(['id' => $applianceId, 'comment' => 'complétée par un utilisateur']);
        $appliances = countElementsInTable(Appliance::getTable());

        $report = $this->importer()->import($this->connection());

        $this->assertSame(0, $report['appliances_created']);
        $this->assertSame(0, $report['retired']);
        $this->assertSame($appliances, countElementsInTable(Appliance::getTable()));
        $after = $this->services();
        $this->assertSame(array_keys($before), array_keys($after));
        foreach ($before as $name => $row) {
            $this->assertSame($row['id'], $after[$name]['id']);
        }
        $appliance = new Appliance();
        $appliance->getFromDB($applianceId);
        $this->assertSame('complétée par un utilisateur', $appliance->fields['comment']);
    }

    public function testVanishedServiceIsRetiredNeverDeletedAndComesBack(): void
    {
        $this->importer()->import($this->connection());
        $saved = $this->fixture['docker']['1']['services'];
        $this->fixture['docker']['1']['services'] = array_values(array_filter(
            $saved,
            static fn (array $service): bool => $service['ID'] !== 'svc-boutique-db'
        ));

        $report = $this->importer()->import($this->connection());

        $this->assertSame(1, $report['retired']);
        $db = $this->services()['boutique_db'];
        $this->assertSame(1, (int) $db['is_retired']);
        $this->assertNotNull($db['date_retired']);
        $this->assertSame(0, (int) $db['running_replicas']);
        $this->assertSame(0, countElementsInTable(
            'glpi_plugin_dockerinventory_services_nodes',
            ['plugin_dockerinventory_services_id' => (int) $db['id']]
        ));

        $this->fixture['docker']['1']['services'] = $saved;
        $this->importer()->import($this->connection());
        $this->assertSame(0, (int) $this->services()['boutique_db']['is_retired']);
    }

    public function testFailingEndpointRetiresNothing(): void
    {
        $this->importer()->import($this->connection());
        $this->fixture['docker']['1']['services'] = [];
        $this->failingPath = '/api/endpoints/1/docker/nodes';

        $report = $this->importer()->import($this->connection());

        // Les autres environnements (Docker seul, Kubernetes) ont été lus : import partiel.
        $this->assertSame(Connection::STATUS_PARTIAL, $report['status']);
        $this->assertSame([['endpoint' => 'swarm-production', 'code' => 'unreachable']], $report['errors']);
        $this->assertSame(0, $report['retired']);
        foreach ($this->services() as $row) {
            $this->assertSame(0, (int) $row['is_retired']);
        }
    }

    public function testAmbiguousHostnameLinksNoComputer(): void
    {
        // Deuxième ordinateur au même nom court : le nœud n'est rattaché à aucun des deux.
        $this->computerIds[] = (int) (new Computer())->add([
            'name'        => 'swarm-node-01',
            'entities_id' => $this->entityId,
        ]);

        $report = $this->importer()->import($this->connection());

        // swarm-node-02 et l'hôte autonome docker-recette-01 restent rattachés.
        $this->assertSame(2, $report['linked_nodes']);
        $node = current((new Node())->find([
            'plugin_dockerinventory_connections_id' => $this->connectionId,
            'hostname'                              => 'swarm-node-01.exemple.test',
        ]));
        $this->assertSame(0, (int) $node['computers_id']);
    }

    public function testStandaloneHostImportsComposeStacksAndContainers(): void
    {
        $report = $this->importer()->import($this->connection());
        $this->assertSame(Connection::STATUS_OK, $report['status'], json_encode($report) ?: '');

        // Lecture seule de l'hôte autonome : informations, conteneurs, images, détail des conteneurs.
        $standalone = array_values(array_filter(
            $this->requested,
            static fn (string $path): bool => str_starts_with($path, '/api/endpoints/2/')
        ));
        $this->assertSame('/api/endpoints/2/docker/services', $standalone[0]);
        $this->assertContains('/api/endpoints/2/docker/info', $standalone);
        $this->assertContains('/api/endpoints/2/docker/containers/json', $standalone);
        $this->assertContains('/api/endpoints/2/docker/images/json', $standalone);
        $this->assertCount(7, preg_grep('#^/api/endpoints/2/docker/containers/[0-9a-f]+/json$#', $standalone) ?: []);

        $stacks = [];
        foreach (
            (new \DbUtils())->getAllDataFromTable(
                'glpi_plugin_dockerinventory_stacks',
                ['plugin_dockerinventory_connections_id' => $this->connectionId, 'endpoint_uid' => 2]
            ) as $row
        ) {
            $stacks[$row['name']] = $row;
        }
        ksort($stacks);
        $this->assertSame(['outils-recette', 'wiki-interne'], array_keys($stacks));
        // Stack Compose de Portainer (identifiant 13), projet Compose déployé hors Portainer (0).
        $this->assertSame(13, (int) $stacks['outils-recette']['stack_uid']);
        $this->assertSame(0, (int) $stacks['wiki-interne']['stack_uid']);
        $this->assertSame('wiki-interne', $stacks['wiki-interne']['compose_project']);
        $this->assertSame('compose', $stacks['wiki-interne']['kind']);

        $services = $this->services();
        $wiki = $services['wiki'];
        $this->assertSame('compose', $wiki['kind']);
        $this->assertSame('wiki-interne', $wiki['stack_name']);
        $this->assertSame((int) $stacks['wiki-interne']['appliances_id'], (int) $wiki['appliances_id']);
        $this->assertSame('registry.exemple.test:5000/outils/wiki', $wiki['image']);
        $this->assertSame('3.2.0', $wiki['tag']);
        $this->assertSame('sha256:' . str_repeat('d', 64), $wiki['digest']);
        $this->assertSame('running', $wiki['state']);
        $this->assertSame('always', $wiki['restart_policy']);
        $this->assertSame('127.0.0.1:3000:3000/tcp', $wiki['ports']);
        $this->assertSame('proxy, wiki-interne_default', $wiki['networks']);
        $this->assertSame('wiki-interne_pages', $wiki['volumes']);
        $this->assertSame('wiki_db_password', $wiki['secrets']);
        $this->assertSame('docker-recette', $wiki['endpoint_name']);
        $this->assertSame('exited', $services['courrier']['state']);
        $this->assertSame(0, (int) $services['courrier']['running_replicas']);
        $this->assertSame('container', $services['sauvegarde-nocturne']['kind']);
        $this->assertSame(0, (int) $services['sauvegarde-nocturne']['appliances_id']);

        $appliance = new Appliance();
        $this->assertTrue($appliance->getFromDB((int) $wiki['appliances_id']));
        $this->assertSame('wiki-interne', $appliance->fields['name']);

        // Aucune valeur de variable d'environnement, aucun chemin de secret dans la base.
        $encoded = (string) json_encode($services);
        $this->assertStringNotContainsString('ne-doit-jamais-etre-importe', $encoded);
        $this->assertStringNotContainsString('/srv/wiki-interne/secrets', $encoded);

        // L'hôte : un nœud « standalone » rattaché à l'ordinateur docker-recette-01.
        $host = current((new Node())->find([
            'plugin_dockerinventory_connections_id' => $this->connectionId,
            'endpoint_uid'                          => 2,
        ]));
        $this->assertIsArray($host);
        $this->assertSame('docker-recette-01.exemple.test', $host['hostname']);
        $this->assertSame('standalone', $host['role']);
        $this->assertSame('27.3.1', $host['engine_version']);
        $this->assertSame($this->computerIds[2], (int) $host['computers_id']);
        // Un conteneur en cours pour 5 services ; le service arrêté (courrier) n'est sur aucun nœud.
        $this->assertSame(5, countElementsInTable(
            'glpi_plugin_dockerinventory_services_nodes',
            ['plugin_dockerinventory_nodes_id' => (int) $host['id'], 'running_tasks' => 1]
        ));
    }

    public function testVanishedComposeProjectIsRetiredNeverDeletedAndComesBack(): void
    {
        $this->importer()->import($this->connection());
        $saved = $this->fixture['docker']['2']['containers'];
        $this->fixture['docker']['2']['containers'] = array_values(array_filter(
            $saved,
            static fn (array $container): bool
                => ($container['Labels']['com.docker.compose.project'] ?? '') !== 'wiki-interne'
        ));

        $report = $this->importer()->import($this->connection());

        $this->assertSame(Connection::STATUS_OK, $report['status']);
        $this->assertSame(3, $report['retired']);
        $services = $this->services();
        foreach (['wiki', 'db', 'indexeur'] as $name) {
            $this->assertSame(1, (int) $services[$name]['is_retired'], $name);
            $this->assertNotNull($services[$name]['date_retired']);
        }
        $this->assertSame(0, (int) $services['adminer']['is_retired']);
        $stack = current((new \DbUtils())->getAllDataFromTable('glpi_plugin_dockerinventory_stacks', [
            'plugin_dockerinventory_connections_id' => $this->connectionId,
            'compose_project'                       => 'wiki-interne',
        ]));
        $this->assertSame(1, (int) $stack['is_retired']);
        // L'application reste : jamais supprimée par l'import.
        $this->assertTrue((new Appliance())->getFromDB((int) $stack['appliances_id']));

        $this->fixture['docker']['2']['containers'] = $saved;
        $this->importer()->import($this->connection());
        $this->assertSame(0, (int) $this->services()['wiki']['is_retired']);
        $this->assertSame($services['wiki']['id'], $this->services()['wiki']['id']);
    }

    public function testFailingStandaloneHostRetiresNothing(): void
    {
        $this->importer()->import($this->connection());
        $this->fixture['docker']['2']['containers'] = [];
        $this->failingPath = '/api/endpoints/2/docker/containers/json';

        $report = $this->importer()->import($this->connection());

        $this->assertSame(Connection::STATUS_PARTIAL, $report['status']);
        $this->assertSame([['endpoint' => 'docker-recette', 'code' => 'unreachable']], $report['errors']);
        $this->assertSame(0, $report['retired']);
        $this->assertSame(0, (int) $this->services()['wiki']['is_retired']);
    }

    public function testContainerRemovedDuringImportKeepsTheOthers(): void
    {
        // Conteneur supprimé entre la liste et son détail (404) : politique inconnue, import réussi.
        $backup = '0b000000000000000000000000000000000000000000000000000000000000b0';
        unset($this->fixture['docker']['2']['inspect'][$backup]);

        $report = $this->importer()->import($this->connection());

        $this->assertSame(Connection::STATUS_OK, $report['status']);
        $this->assertSame('', $this->services()['sauvegarde-nocturne']['restart_policy']);
        $this->assertSame('always', $this->services()['wiki']['restart_policy']);
    }

    public function testUnauthorizedKeyFailsWithoutChange(): void
    {
        $this->failingPath = '/api/endpoints';
        $report = $this->importer()->import($this->connection());

        $this->assertSame(Connection::STATUS_ERROR, $report['status']);
        $this->assertSame([], $this->services());
        $this->assertSame(Connection::STATUS_ERROR, (int) $this->connection()->fields['last_import_status']);
    }
}

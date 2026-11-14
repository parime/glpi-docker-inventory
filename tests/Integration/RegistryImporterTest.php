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
use GlpiPlugin\Dockerinventory\Connection;
use GlpiPlugin\Dockerinventory\ImageVersion;
use GlpiPlugin\Dockerinventory\Importer;
use GlpiPlugin\Dockerinventory\Portainer\PortainerClient;
use GlpiPlugin\Dockerinventory\Registry\RegistryClient;
use GlpiPlugin\Dockerinventory\Registry\RegistryException;
use GlpiPlugin\Dockerinventory\Registry\RegistryImporter;
use GlpiPlugin\Dockerinventory\Service;
use GlpiPlugin\Dockerinventory\Version\VersionComparator;
use GlpiPlugin\Dockerinventory\Tests\PortainerFixture;
use PHPUnit\Framework\TestCase;

/**
 * Import Portainer puis vérification des registres contre la vraie base GLPI, avec les jeux
 * fictifs des faux serveurs (tests/mock-portainer, tests/mock-registry) servis par des transports
 * en mémoire : le vrai Docker Hub n'est jamais appelé. Assertions sur des codes, jamais sur des
 * libellés traduits.
 */
final class RegistryImporterTest extends TestCase
{
    private const ROBOT = 'robot$lecture';
    private const PASSWORD = 'robot_mot_de_passe_fictif';

    /** @var array<string, mixed> */
    private array $registry;

    /** @var list<string> Requêtes reçues, « hub <dépôt> » ou « harbor <chemin> ». */
    private array $requested = [];

    private ?string $hubFailure = null;

    private int $entityId = 0;

    private int $portainerId = 0;

    private int $harborId = 0;

    /** @var list<int> Autres connexions Harbor actives de l'instance, suspendues pendant le test. */
    private array $otherHarbors = [];

    protected function setUp(): void
    {
        global $DB;

        $this->registry = json_decode(
            (string) file_get_contents(dirname(__DIR__) . '/mock-registry/fixture.json'),
            true
        );
        $suffix = bin2hex(random_bytes(4));
        $this->entityId = (int) (new \Entity())->add(['name' => 'PHPUnit registres ' . $suffix, 'entities_id' => 0]);
        \Session::changeActiveEntities(0, true);
        $this->portainerId = (int) (new Connection())->add([
            'name'        => 'Portainer registres PHPUnit ' . $suffix,
            'entities_id' => $this->entityId,
            'url'         => 'https://portainer.exemple.test:9443',
            'api_key'     => 'ptr_fictive_cle_registres',
        ]);
        $this->harborId = (int) (new Connection())->add([
            'name'          => 'Harbor PHPUnit ' . $suffix,
            'type'          => Connection::TYPE_HARBOR,
            'entities_id'   => $this->entityId,
            'url'           => 'https://harbor.exemple.test/',
            'registry_host' => 'HTTPS://Registry.Exemple.test:5000/',
            'username'      => self::ROBOT,
            'api_key'       => self::PASSWORD,
        ]);
        $this->assertGreaterThan(0, $this->portainerId);
        $this->assertGreaterThan(0, $this->harborId);
        // Instance de développement : ses propres connexions Harbor ne doivent pas répondre à la
        // place de celle du test (rétablies dans tearDown()).
        foreach (
            $DB->request(['SELECT' => ['id'], 'FROM' => Connection::getTable(), 'WHERE' => [
                'type' => Connection::TYPE_HARBOR, 'is_active' => 1, ['NOT' => ['id' => $this->harborId]],
            ]]) as $row
        ) {
            $this->otherHarbors[] = (int) $row['id'];
        }
        if ($this->otherHarbors !== []) {
            $DB->update(Connection::getTable(), ['is_active' => 0], ['id' => $this->otherHarbors]);
        }
        // Cache de Docker Hub vidé : chaque test part d'une vérification complète.
        $DB->delete(RegistryImporter::CACHE_TABLE, ['registry' => RegistryClient::KIND_DOCKER_HUB]);
        $this->importPortainer();
    }

    protected function tearDown(): void
    {
        global $DB;

        $serviceIds = $this->serviceIds();
        if ($serviceIds !== []) {
            $DB->delete(ImageVersion::getTable(), ['plugin_dockerinventory_services_id' => $serviceIds]);
            $DB->delete(
                'glpi_plugin_dockerinventory_services_nodes',
                ['plugin_dockerinventory_services_id' => $serviceIds]
            );
        }
        $appliance = new Appliance();
        foreach (
            $DB->request(['SELECT' => ['appliances_id'], 'FROM' => 'glpi_plugin_dockerinventory_stacks',
            'WHERE' => ['plugin_dockerinventory_connections_id' => $this->portainerId]]) as $row
        ) {
            $appliance->delete(['id' => (int) $row['appliances_id']], true);
        }
        foreach (
            [Service::getTable(), 'glpi_plugin_dockerinventory_nodes', 'glpi_plugin_dockerinventory_stacks'] as $table
        ) {
            $DB->delete($table, ['plugin_dockerinventory_connections_id' => $this->portainerId]);
        }
        $DB->delete(RegistryImporter::CACHE_TABLE, ['registry' => RegistryClient::KIND_DOCKER_HUB]);
        if ($this->otherHarbors !== []) {
            $DB->update(Connection::getTable(), ['is_active' => 1], ['id' => $this->otherHarbors]);
        }
        (new Connection())->delete(['id' => $this->harborId], true);
        (new Connection())->delete(['id' => $this->portainerId], true);
        (new \Entity())->delete(['id' => $this->entityId], true);
        $_SESSION['MESSAGE_AFTER_REDIRECT'] = [];
    }

    /**
     * @return list<int>
     */
    private function serviceIds(): array
    {
        global $DB;

        return array_map('intval', array_column(iterator_to_array($DB->request([
            'SELECT' => ['id'],
            'FROM'   => Service::getTable(),
            'WHERE'  => ['plugin_dockerinventory_connections_id' => $this->portainerId],
        ]), false), 'id'));
    }

    private function connection(int $id): Connection
    {
        $connection = new Connection();
        $connection->getFromDB($id);

        return $connection;
    }

    private function importPortainer(): void
    {
        $fixture = PortainerFixture::load();
        (new Importer(static fn (Connection $c): PortainerClient => new PortainerClient(
            'https://portainer.exemple.test:9443',
            'ptr_fictive_cle_registres',
            static function (string $url) use ($fixture): string {
                $path = (string) parse_url($url, PHP_URL_PATH);
                return PortainerFixture::respond($fixture, $path);
            }
        )))->import($this->connection($this->portainerId));
    }

    /**
     * Vérification des registres, transports en mémoire (faux Docker Hub et faux Harbor).
     *
     * @return array<string, mixed>
     */
    private function check(): array
    {
        return (new RegistryImporter(function (?Connection $harbor): RegistryClient {
            if ($harbor === null) {
                return RegistryClient::dockerHub('https://hub.exemple.test', $this->hubTransport(...));
            }

            return RegistryClient::harbor(
                (string) $harbor->fields['url'],
                (string) $harbor->fields['username'],
                $harbor->getDecryptedApiKey(),
                $this->harborTransport(...)
            );
        }))->run();
    }

    /**
     * @param array<string, scalar> $query
     * @param array<string, string> $headers
     */
    private function hubTransport(string $url, array $query, array $headers): string
    {
        $this->assertStringStartsWith('https://hub.exemple.test/v2/repositories/', $url);
        $this->assertArrayNotHasKey('Authorization', $headers);
        $repository = substr((string) parse_url($url, PHP_URL_PATH), strlen('/v2/repositories/'), -strlen('/tags'));
        $this->requested[] = 'hub ' . $repository;
        if ($this->hubFailure !== null) {
            throw new \RuntimeException($this->hubFailure);
        }
        $tags = $this->registry['hub'][$repository] ?? null;
        if ($tags === null) {
            throw new \RuntimeException('HTTP 404 returned for "' . $url . '"');
        }

        return (string) json_encode(['next' => null, 'results' => $tags]);
    }

    /**
     * @param array<string, scalar> $query
     * @param array<string, string> $headers
     */
    private function harborTransport(string $url, array $query, array $headers): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $this->requested[] = 'harbor ' . $path;
        $this->assertStringStartsWith('https://harbor.exemple.test/v2/', $url);
        if (($headers['Authorization'] ?? '') !== 'Basic ' . base64_encode(self::ROBOT . ':' . self::PASSWORD)) {
            throw new \RuntimeException('HTTP 401 returned');
        }
        preg_match('#^/v2/(.+)/tags/list$#', $path, $m);
        $tags = $this->registry['v2'][$m[1] ?? ''] ?? null;
        if ($tags === null) {
            throw new \RuntimeException('HTTP 404 returned');
        }

        return (string) json_encode(['name' => $m[1], 'tags' => $tags]);
    }

    /**
     * Requêtes envoyées au faux Docker Hub depuis la dernière remise à zéro.
     */
    private function hubCalls(): int
    {
        return count(array_filter($this->requested, static fn (string $r): bool => str_starts_with($r, 'hub ')));
    }

    /**
     * @return array<string, array<string, mixed>> Lignes de version des services de ce test, par nom.
     */
    private function versions(): array
    {
        $rows = [];
        foreach (ImageVersion::fetch(['v.plugin_dockerinventory_services_id' => $this->serviceIds()]) as $row) {
            $rows[(string) $row['service_name']] = $row;
        }
        ksort($rows);

        return $rows;
    }

    public function testHarborConnectionStoresEncryptedPasswordAndRegistryHost(): void
    {
        $harbor = $this->connection($this->harborId);
        $this->assertTrue($harbor->isHarbor());
        $this->assertSame('registry.exemple.test:5000', $harbor->fields['registry_host']);
        $this->assertNotSame(self::PASSWORD, $harbor->fields['api_key']);
        $this->assertSame(self::PASSWORD, $harbor->getDecryptedApiKey());

        $this->assertFalse($harbor->update(['id' => $this->harborId, 'registry_host' => 'registre invalide/x']));
        $harbor->update(['id' => $this->harborId, 'api_key' => '', 'name' => 'Harbor renommé']);
        $this->assertSame(self::PASSWORD, $this->connection($this->harborId)->getDecryptedApiKey());
        $harbor->update(['id' => $this->harborId, '_clear_api_key' => 1]);
        $this->assertSame('', $this->connection($this->harborId)->getDecryptedApiKey());
    }

    public function testRegistryCheckFillsVersionsOfServicesWithoutWud(): void
    {
        $report = $this->check();
        $this->assertSame(Connection::STATUS_OK, $report['status'], (string) json_encode($report['errors']));

        $rows = $this->versions();
        $this->assertSame(
            // Services Swarm et services de l'hôte autonome (#25).
            ['adminer', 'boutique_api', 'boutique_cache', 'boutique_db', 'boutique_web', 'courrier', 'db', 'indexeur',
                'outil_isole', 'sauvegarde-nocturne', 'supervision_agent', 'supervision_grafana', 'wiki'],
            array_keys($rows)
        );
        $expected = [
            'boutique_db'         => ['16.4', '17.2', VersionComparator::GAP_MAJOR],
            'boutique_cache'      => ['7.4.1', '7.4.2', VersionComparator::GAP_PATCH],
            'supervision_grafana' => ['11.2.0', '11.3.1', VersionComparator::GAP_MINOR],
            'boutique_api'        => ['1.9.0', '1.10.0', VersionComparator::GAP_MINOR],
            'supervision_agent'   => ['latest', '', VersionComparator::GAP_UNTRACKABLE],
            'outil_isole'         => ['latest', '', VersionComparator::GAP_UNTRACKABLE],
            'boutique_web'        => ['2.4.1', '', VersionComparator::GAP_UNKNOWN],
        ];
        foreach ($expected as $name => [$deployed, $latest, $gap]) {
            $this->assertSame($deployed, $rows[$name]['deployed_tag'], $name);
            $this->assertSame($latest, $rows[$name]['latest_tag'], $name);
            $this->assertSame($gap, $rows[$name]['gap'], $name);
            $this->assertSame(ImageVersion::SOURCE_REGISTRY, $rows[$name]['source'], $name);
        }
        // Image absente de Docker Hub (ou privée) : non vérifiée, sans erreur bloquante.
        $this->assertSame(RegistryException::NOT_FOUND, $rows['boutique_web']['error']);
        $this->assertSame($this->harborId, (int) $rows['boutique_api']['plugin_dockerinventory_connections_id']);
        $this->assertSame('registry.exemple.test:5000', $rows['boutique_api']['registry_name']);
        $this->assertSame(0, (int) $rows['boutique_db']['plugin_dockerinventory_connections_id']);
        $this->assertSame('library/postgres', $rows['boutique_db']['image']);
        $this->assertSame(
            'https://hub.docker.com/_/postgres',
            ImageVersion::present($rows['boutique_db'])['registry_link']
        );
        $this->assertSame($this->entityId, (int) $rows['boutique_db']['entities_id']);

        // Une seule lecture par dépôt ; jamais de requête autre que la liste des tags.
        $this->assertSame(count($this->requested), count(array_unique($this->requested)));
        $this->assertContains('harbor /v2/boutique/api/tags/list', $this->requested);
        $harbor = $this->connection($this->harborId);
        $this->assertSame(Connection::STATUS_OK, (int) $harbor->fields['last_import_status']);
        // boutique_api et les 4 services de l'hôte autonome dont l'image est sur ce registre (#25).
        $this->assertSame(5, json_decode((string) $harbor->fields['last_import_report'], true)['services']);

        // Idempotence : même nombre de lignes, mêmes identifiants.
        $ids = array_column($rows, 'id');
        $this->check();
        $this->assertSame($ids, array_column($this->versions(), 'id'));
    }

    public function testDockerHubIsReadAtMostOncePerDayPerImage(): void
    {
        global $DB;

        $this->check();
        $this->assertGreaterThan(0, $this->hubCalls());

        $this->requested = [];
        $report = $this->check();
        $this->assertSame(0, $this->hubCalls());
        $this->assertGreaterThan(0, $report['cached']);
        // Harbor (registre de l'entreprise, pas de limite anonyme) : relu à chaque vérification.
        $this->assertContains('harbor /v2/boutique/api/tags/list', $this->requested);
        $this->assertSame(VersionComparator::GAP_MAJOR, $this->versions()['boutique_db']['gap']);

        // Cache de plus d'un jour : relu.
        $DB->update(
            RegistryImporter::CACHE_TABLE,
            ['date_checked' => date('Y-m-d H:i:s', strtotime($_SESSION['glpi_currenttime']) - 2 * DAY_TIMESTAMP)],
            ['registry' => RegistryClient::KIND_DOCKER_HUB, 'repository' => 'library/postgres']
        );
        $this->requested = [];
        $this->check();
        $this->assertContains('hub library/postgres', $this->requested);
        $this->assertNotContains('hub library/redis', $this->requested);
    }

    public function testRateLimitStopsDockerHubCallsAndNothingIsCached(): void
    {
        $this->hubFailure = 'HTTP 429 returned';
        $report = $this->check();

        $this->assertSame(1, $this->hubCalls());
        $this->assertSame(Connection::STATUS_PARTIAL, $report['status']);
        $this->assertSame(RegistryException::RATE_LIMITED, $report['errors'][0]['code']);
        $codes = array_column($this->versions(), 'error', 'service_name');
        $this->assertContains(RegistryException::SKIPPED, $codes);
        $this->assertSame('', $codes['boutique_api']);
        $this->assertSame(0, countElementsInTable(RegistryImporter::CACHE_TABLE, ['registry' => 'dockerhub']));

        // Docker Hub de nouveau disponible : tout est relu.
        $this->hubFailure = null;
        $this->assertSame(Connection::STATUS_OK, $this->check()['status']);
        $this->assertSame(VersionComparator::GAP_PATCH, $this->versions()['boutique_cache']['gap']);
    }

    public function testUnknownRegistryOrMissingCredentialsAreNotChecked(): void
    {
        $harbor = $this->connection($this->harborId);
        $harbor->update(['id' => $this->harborId, '_clear_api_key' => 1, 'username' => '']);
        $this->check();
        $api = $this->versions()['boutique_api'];
        $this->assertSame(VersionComparator::GAP_UNKNOWN, $api['gap']);
        $this->assertSame(RegistryException::NO_CREDENTIALS, $api['error']);

        $harbor->update(['id' => $this->harborId, 'is_active' => 0]);
        $report = $this->check();
        $api = $this->versions()['boutique_api'];
        $this->assertSame(RegistryException::UNKNOWN_REGISTRY, $api['error']);
        $this->assertSame(0, (int) $api['plugin_dockerinventory_connections_id']);
        $this->assertSame(Connection::STATUS_OK, $report['status']);
        $this->assertNotEmpty(Connection::registryErrorLabels()[RegistryException::UNKNOWN_REGISTRY]);
        $this->assertNotSame(RegistryException::UNKNOWN_REGISTRY, ImageVersion::present($api)['error']);
    }

    public function testServicesFollowedByWudAreLeftToWud(): void
    {
        global $DB;

        $this->check();
        $db = $this->versions()['boutique_db'];
        // Une ligne WUD (connexion fictive) pour ce service : le repli ne le vérifie plus.
        $DB->insert(ImageVersion::getTable(), [
            'entities_id'                           => $this->entityId,
            'plugin_dockerinventory_connections_id' => $this->portainerId,
            'plugin_dockerinventory_services_id'    => (int) $db['plugin_dockerinventory_services_id'],
            'container_uid'                         => '',
            'deployed_tag'                          => '16.4',
            'gap'                                   => VersionComparator::GAP_NONE,
            'source'                                => ImageVersion::SOURCE_WUD,
        ]);
        $this->check();
        $serviceId = (int) $db['plugin_dockerinventory_services_id'];
        $sources = array_column(ImageVersion::fetch(['v.plugin_dockerinventory_services_id' => $serviceId]), 'source');
        $this->assertSame([ImageVersion::SOURCE_WUD], $sources);
    }

    public function testHarborTestConnection(): void
    {
        $harbor = $this->connection($this->harborId);
        $ok = $harbor->testHarborConnection(RegistryClient::harbor(
            'https://harbor.exemple.test',
            self::ROBOT,
            self::PASSWORD,
            static fn (string $url): string => str_ends_with($url, '/v2/') ? '{}' : throw new \RuntimeException('404')
        ));
        $this->assertTrue($ok['ok']);
        $refused = $harbor->testHarborConnection(RegistryClient::harbor(
            'https://harbor.exemple.test',
            self::ROBOT,
            'faux',
            static fn (): string => throw new \RuntimeException('HTTP 401 returned')
        ));
        $this->assertFalse($refused['ok']);
        $this->assertStringNotContainsString(self::PASSWORD, $refused['message']);
    }
}

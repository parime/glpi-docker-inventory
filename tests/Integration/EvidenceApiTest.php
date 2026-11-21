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

use GlpiPlugin\Dockerinventory\Api\Evidence;
use GlpiPlugin\Dockerinventory\Connection;
use GlpiPlugin\Dockerinventory\Exposure\ExposureMapper;
use GlpiPlugin\Dockerinventory\Hardening\HardeningMapper;
use GlpiPlugin\Dockerinventory\Harbor\HarborMapper;
use GlpiPlugin\Dockerinventory\HarborArtifact;
use GlpiPlugin\Dockerinventory\ImageVersion;
use GlpiPlugin\Dockerinventory\Node;
use GlpiPlugin\Dockerinventory\Service;
use GlpiPlugin\Dockerinventory\Version\VersionChange;
use GlpiPlugin\Dockerinventory\Version\VersionComparator;
use PHPUnit\Framework\TestCase;

/**
 * API de lecture des preuves (#10) : chaque méthode ne renvoie que ce que l'utilisateur voit dans le
 * plugin (droit de lecture des services, entités actives). Deux entités fictives A et B ; la session
 * ne voit que A. Assertions sur des noms et des codes, jamais sur des libellés traduits.
 */
final class EvidenceApiTest extends TestCase
{
    private string $suffix = '';

    /** @var array<string, int> Entité par lettre. */
    private array $entities = [];

    /** @var array<string, array<string, int>> Objets créés par lettre. */
    private array $created = [];

    /** @var array<string, mixed> */
    private array $profile = [];

    protected function setUp(): void
    {
        $this->suffix = bin2hex(random_bytes(4));
        $this->profile = $_SESSION['glpiactiveprofile'];
        \Session::changeActiveEntities(0, true);
        foreach (['a', 'b'] as $letter) {
            $this->createEntity($letter);
        }
        HarborArtifact::resetCache();
        \Session::changeActiveEntities($this->entities['a'], false);
    }

    /**
     * Une entité fictive avec une connexion Portainer, une connexion Harbor, un service, l'analyse
     * de son image, son écart de version, un nœud et un changement de version.
     */
    private function createEntity(string $letter): void
    {
        global $DB;

        $entity = (int) (new \Entity())->add([
            'name' => 'PHPUnit preuves ' . strtoupper($letter) . ' ' . $this->suffix, 'entities_id' => 0,
        ]);
        $this->assertGreaterThan(0, $entity);
        $this->entities[$letter] = $entity;
        $name = $letter . '-' . $this->suffix;
        $host = 'registry-' . $name . '.exemple.test';
        $digest = 'sha256:' . str_repeat($letter, 64);
        $harbor = (int) (new Connection())->add([
            'name'          => 'harbor-' . $name,
            'type'          => Connection::TYPE_HARBOR,
            'entities_id'   => $entity,
            'url'           => 'https://harbor-' . $letter . '.exemple.test',
            'registry_host' => $host,
            'username'      => 'robot$lecture',
            'api_key'       => 'robot_mot_de_passe_fictif',
        ]);
        $portainer = (int) (new Connection())->add([
            'name'        => 'portainer-' . $name,
            'entities_id' => $entity,
            'url'         => 'https://portainer-' . $letter . '.exemple.test:9443',
            'api_key'     => 'ptr_fictive_cle_preuves',
        ]);
        $DB->update(Connection::getTable(), [
            'last_success_date'  => $letter === 'a' ? '2026-03-01 10:00:00' : '2026-03-02 10:00:00',
            'last_import_status' => Connection::STATUS_OK,
        ], ['id' => $portainer]);
        $service = (int) (new Service())->add([
            'name'                                  => 'web-' . $name,
            'service_uid'                           => 'web-' . $name,
            'entities_id'                           => $entity,
            'plugin_dockerinventory_connections_id' => $portainer,
            'stack_name'                            => 'boutique-' . $letter,
            'image'                                 => $host . '/projet/web',
            'tag'                                   => '1.0.0',
            'digest'                                => $digest,
            'replicas'                              => 2,
            'running_replicas'                      => 2,
            'ports'                                 => '443:8443/tcp',
            // Configuration à risque (#71) : réseau de l'hôte et exécution en root.
            'hardening'                             => HardeningMapper::encode([
                ['code' => HardeningMapper::HOST_NETWORK, 'detail' => ''],
                ['code' => HardeningMapper::ROOT_USER, 'detail' => 'root'],
            ]),
        ]);
        $artifact = (int) (new HarborArtifact())->add([
            'entities_id'                           => $entity,
            'plugin_dockerinventory_connections_id' => $harbor,
            'project'                               => 'projet',
            'repository'                            => 'projet/web',
            'digest'                                => $digest,
            'tags'                                  => '1.0.0',
            'scan_status'                           => HarborMapper::STATUS_SUCCESS,
            'severity'                              => HarborMapper::SEVERITY_CRITICAL,
            'critical'                              => 3,
            'high'                                  => 1,
            'total'                                 => 4,
        ]);
        $version = (int) (new ImageVersion())->add([
            'entities_id'                           => $entity,
            'plugin_dockerinventory_connections_id' => $portainer,
            'plugin_dockerinventory_services_id'    => $service,
            'image'                                 => $host . '/projet/web',
            'deployed_tag'                          => '1.0.0',
            'latest_tag'                            => '2.0.0',
            'gap'                                   => VersionComparator::GAP_MAJOR,
        ]);
        $DB->insert(Node::getTable(), [
            'plugin_dockerinventory_connections_id' => $portainer,
            'node_uid'                              => 'node-' . $name,
            'hostname'                              => 'noeud-' . $letter . '.exemple.test',
        ]);
        $DB->insert(VersionChange::TABLE, [
            'entities_id'                           => $entity,
            'plugin_dockerinventory_connections_id' => $portainer,
            'plugin_dockerinventory_services_id'    => $service,
            'service_name'                          => 'web-' . $name,
            'kind'                                  => VersionChange::KIND_TAG,
            'old_image'                             => $host . '/projet/web',
            'new_image'                             => $host . '/projet/web',
            'old_tag'                               => '0.9.0',
            'new_tag'                               => '1.0.0',
            'date_change'                           => '2026-03-01 09:00:00',
        ]);
        foreach ([$harbor, $portainer, $service, $artifact, $version] as $id) {
            $this->assertGreaterThan(0, $id);
        }
        $this->created[$letter] = compact('harbor', 'portainer', 'service', 'artifact', 'version');
    }

    protected function tearDown(): void
    {
        global $DB;

        $_SESSION['glpiactiveprofile'] = $this->profile;
        \Session::changeActiveEntities(0, true);
        foreach ($this->created as $ids) {
            $DB->delete(VersionChange::TABLE, ['plugin_dockerinventory_services_id' => $ids['service']]);
            $DB->delete(Node::getTable(), ['plugin_dockerinventory_connections_id' => $ids['portainer']]);
            (new ImageVersion())->delete(['id' => $ids['version']], true);
            (new HarborArtifact())->delete(['id' => $ids['artifact']], true);
            (new Service())->delete(['id' => $ids['service']], true);
            (new Connection())->delete(['id' => $ids['portainer']], true);
            (new Connection())->delete(['id' => $ids['harbor']], true);
        }
        foreach ($this->entities as $entity) {
            (new \Entity())->delete(['id' => $entity], true);
        }
        HarborArtifact::resetCache();
    }

    /**
     * Lignes d'une liste qui concernent le service fictif de l'entité A.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function rowsOfA(array $rows): array
    {
        return array_values(array_filter(
            $rows,
            fn (array $row): bool => ($row['service'] ?? '') === 'web-a-' . $this->suffix
        ));
    }

    public function testEvidenceShowsOnlyTheActiveEntity(): void
    {
        $results = [
            'inventory'       => Evidence::inventory(),
            'versionGaps'     => Evidence::versionGaps(1000),
            'vulnerabilities' => Evidence::vulnerabilities(1000),
            'configuration'   => Evidence::configuration(1000),
            'versionChanges'  => Evidence::versionChanges('2026-01-01 00:00:00', 1000),
            'exposure'        => Evidence::exposure(1000),
            'hardening'       => Evidence::hardening(1000),
        ];
        foreach ($results as $name => $result) {
            $this->assertIsArray($result, $name);
            // Rien de l'entité B : ni service, ni connexion, ni registre.
            $this->assertStringNotContainsString('b-' . $this->suffix, (string) json_encode($result), $name);
        }

        $inventory = $results['inventory'];
        $this->assertGreaterThanOrEqual(1, $inventory['services']);
        $this->assertGreaterThanOrEqual(1, $inventory['nodes']);
        $this->assertTrue($inventory['can_view_connections']);
        $mine = array_values(array_filter(
            $inventory['connections'],
            fn (array $c): bool => $c['name'] === 'portainer-a-' . $this->suffix
        ));
        $this->assertCount(1, $mine);
        $this->assertSame('2026-03-01 10:00:00', $mine[0]['last_success_date']);
        $this->assertSame($this->entities['a'], $mine[0]['entities_id']);

        $gaps = $this->rowsOfA($results['versionGaps']['outdated']);
        $this->assertCount(1, $gaps);
        $this->assertSame(VersionComparator::GAP_MAJOR, $gaps[0]['gap']);
        $this->assertSame('2.0.0', $gaps[0]['latest_tag']);
        $this->assertSame($this->entities['a'], $gaps[0]['entities_id']);

        $vulns = $this->rowsOfA($results['vulnerabilities']['services']);
        $this->assertCount(1, $vulns);
        $this->assertSame(HarborMapper::SEVERITY_CRITICAL, $vulns[0]['severity']);
        $this->assertSame(3, $vulns[0]['critical']);
        $this->assertSame(1, $vulns[0]['high']);
        $this->assertTrue($results['vulnerabilities']['has_harbor']);

        $config = $this->rowsOfA($results['configuration']['services']);
        $this->assertCount(1, $config);
        $this->assertSame('1.0.0', $config[0]['tag']);
        $this->assertSame(str_repeat('a', 12), $config[0]['digest']);
        $this->assertSame(['443:8443/tcp'], $config[0]['ports']);
        $this->assertTrue($config[0]['replicas_ok']);

        // Exposition (#72) : le port publié sur toutes les adresses de l'hôte.
        $exposed = $this->rowsOfA($results['exposure']['services']);
        $this->assertCount(1, $exposed);
        $this->assertSame([ExposureMapper::SCOPE_HOST], array_column($exposed[0]['exposures'], 'scope'));
        $this->assertSame([443], array_column($exposed[0]['exposures'], 'port'));
        $this->assertFalse($exposed[0]['internet']);
        $this->assertSame($this->entities['a'], $exposed[0]['entities_id']);

        // Configuration à risque (#71, A.8.9) : écarts du service, gravité la plus haute.
        $hardening = $results['hardening'];
        $this->assertGreaterThanOrEqual(1, $hardening['analysed']);
        $this->assertIsInt($hardening['rate']);
        $this->assertGreaterThanOrEqual(1, $hardening['by_severity'][HardeningMapper::SEVERITY_HIGH]);
        $risky = $this->rowsOfA($hardening['services']);
        $this->assertCount(1, $risky);
        $this->assertSame(HardeningMapper::SEVERITY_HIGH, $risky[0]['worst']);
        $this->assertSame(2, $risky[0]['open']);
        $this->assertSame(
            [HardeningMapper::HOST_NETWORK, HardeningMapper::ROOT_USER],
            array_column($risky[0]['findings'], 'code')
        );
        $this->assertSame(['', ''], array_column($risky[0]['findings'], 'exception'));
        $this->assertSame($this->entities['a'], $risky[0]['entities_id']);

        $changes = $this->rowsOfA($results['versionChanges']['changes']);
        $this->assertCount(1, $changes);
        $this->assertSame('0.9.0', $changes[0]['old_tag']);
        $this->assertSame('1.0.0', $changes[0]['new_tag']);
        $this->assertSame(VersionChange::KIND_TAG, $changes[0]['kind']);

        // Hors période : le changement n'est plus cité.
        $later = Evidence::versionChanges('2026-04-01 00:00:00', 1000);
        $this->assertIsArray($later);
        $this->assertSame([], $this->rowsOfA($later['changes']));
    }

    public function testConnectionsNeedTheConfigurationRight(): void
    {
        $_SESSION['glpiactiveprofile'][Connection::RIGHTNAME] = READ;
        $inventory = Evidence::inventory();
        $this->assertIsArray($inventory);
        $this->assertFalse($inventory['can_view_connections']);
        $this->assertSame([], $inventory['connections']);
    }

    public function testWithoutTheServiceRightNothingIsReturned(): void
    {
        $_SESSION['glpiactiveprofile'][Service::RIGHTNAME] = 0;
        $this->assertFalse(Evidence::canRead());
        $this->assertNull(Evidence::inventory());
        $this->assertNull(Evidence::versionGaps());
        $this->assertNull(Evidence::vulnerabilities());
        $this->assertNull(Evidence::configuration());
        $this->assertNull(Evidence::versionChanges('2026-01-01 00:00:00'));
        $this->assertNull(Evidence::exposure());
        $this->assertNull(Evidence::hardening());
    }
}

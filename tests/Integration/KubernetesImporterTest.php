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
use GlpiPlugin\Dockerinventory\Exposure;
use GlpiPlugin\Dockerinventory\Exposure\ExposureMapper;
use GlpiPlugin\Dockerinventory\Harbor\HarborClient;
use GlpiPlugin\Dockerinventory\Harbor\HarborImporter;
use GlpiPlugin\Dockerinventory\Harbor\HarborLinker;
use GlpiPlugin\Dockerinventory\HarborArtifact;
use GlpiPlugin\Dockerinventory\ImageVersion;
use GlpiPlugin\Dockerinventory\InventoryStore;
use GlpiPlugin\Dockerinventory\Kubernetes\KubernetesClient;
use GlpiPlugin\Dockerinventory\Kubernetes\KubernetesException;
use GlpiPlugin\Dockerinventory\Kubernetes\KubernetesImporter;
use GlpiPlugin\Dockerinventory\Kubernetes\KubernetesMapper;
use GlpiPlugin\Dockerinventory\Node;
use GlpiPlugin\Dockerinventory\Registry\RegistryClient;
use GlpiPlugin\Dockerinventory\Registry\RegistryImporter;
use GlpiPlugin\Dockerinventory\Service;
use GlpiPlugin\Dockerinventory\Tests\HarborFixture;
use GlpiPlugin\Dockerinventory\Tests\KubernetesFixture;
use GlpiPlugin\Dockerinventory\Version\VersionChange;
use GlpiPlugin\Dockerinventory\Version\VersionComparator;
use PHPUnit\Framework\TestCase;

/**
 * Import complet d'un cluster Kubernetes contre la vraie base GLPI, avec le jeu fictif du faux
 * Kubernetes (tests/mock-kubernetes) servi en mémoire. Assertions sur des codes et des valeurs
 * importées, jamais sur des libellés traduits (CI en anglais, instance de développement en français).
 */
final class KubernetesImporterTest extends TestCase
{
    private const HOST = 'registry.exemple.test:5000';

    /** @var array<string, mixed> */
    private array $fixture;

    private int $entityId = 0;

    private int $connectionId = 0;

    private int $harborId = 0;

    private int $environmentId = 0;

    /** @var list<int> */
    private array $computerIds = [];

    private int $ipAddressId = 0;

    protected function setUp(): void
    {
        global $DB;

        $this->fixture = KubernetesFixture::load();
        $suffix = bin2hex(random_bytes(4));
        $this->entityId = (int) (new \Entity())->add(['name' => 'PHPUnit Kubernetes ' . $suffix, 'entities_id' => 0]);
        \Session::changeActiveEntities(0, true);
        $this->environmentId = (int) (new ApplianceEnvironment())->add(['name' => 'Production K8s ' . $suffix]);
        $computer = new Computer();
        // Premier nœud par son nom court, second par son adresse IP.
        $this->computerIds[] = (int) $computer->add(['name' => 'k8s-node-01', 'entities_id' => $this->entityId]);
        $this->computerIds[] = (int) $computer->add(['name' => 'serveur-' . $suffix, 'entities_id' => $this->entityId]);
        $DB->insert('glpi_ipaddresses', [
            'entities_id'  => $this->entityId,
            'items_id'     => 0,
            'itemtype'     => 'NetworkName',
            'version'      => 4,
            'name'         => '192.0.2.22',
            'is_deleted'   => 0,
            'is_dynamic'   => 0,
            'mainitems_id' => $this->computerIds[1],
            'mainitemtype' => 'Computer',
        ]);
        $this->ipAddressId = (int) $DB->insertId();
        $this->connectionId = (int) (new Connection())->add([
            'name'                     => 'Cluster PHPUnit ' . $suffix,
            'type'                     => Connection::TYPE_KUBERNETES,
            'entities_id'              => $this->entityId,
            'url'                      => 'https://kubernetes.exemple.test:6443/',
            'api_key'                  => KubernetesFixture::TOKEN,
            'applianceenvironments_id' => $this->environmentId,
        ]);
        $this->harborId = (int) (new Connection())->add([
            'name'          => 'Harbor K8s PHPUnit ' . $suffix,
            'type'          => Connection::TYPE_HARBOR,
            'entities_id'   => $this->entityId,
            'url'           => 'https://harbor.exemple.test',
            'registry_host' => self::HOST,
            'username'      => 'robot$lecture',
            'api_key'       => 'robot_mot_de_passe_fictif',
        ]);
        $this->assertGreaterThan(0, $this->connectionId);
        $this->assertGreaterThan(0, $this->harborId);
        HarborArtifact::resetCache();
    }

    protected function tearDown(): void
    {
        global $DB;

        $ids = array_column(iterator_to_array($DB->request(['SELECT' => ['id'], 'FROM' => Service::getTable(),
            'WHERE' => ['plugin_dockerinventory_connections_id' => $this->connectionId]]), false), 'id');
        if ($ids !== []) {
            $DB->delete(InventoryStore::PLACEMENT_TABLE, ['plugin_dockerinventory_services_id' => $ids]);
            $DB->delete(ImageVersion::getTable(), ['plugin_dockerinventory_services_id' => $ids]);
        }
        foreach (
            $DB->request(['SELECT' => ['appliances_id'], 'FROM' => InventoryStore::STACKS_TABLE,
            'WHERE' => ['plugin_dockerinventory_connections_id' => $this->connectionId]]) as $row
        ) {
            (new Appliance())->delete(['id' => (int) $row['appliances_id']], true);
        }
        foreach ([Service::getTable(), Node::getTable(), InventoryStore::STACKS_TABLE] as $table) {
            $DB->delete($table, ['plugin_dockerinventory_connections_id' => $this->connectionId]);
        }
        $DB->delete(HarborArtifact::getTable(), ['plugin_dockerinventory_connections_id' => $this->harborId]);
        $DB->delete(HarborImporter::PROJECTS_TABLE, ['plugin_dockerinventory_connections_id' => $this->harborId]);
        $DB->delete('glpi_ipaddresses', ['id' => $this->ipAddressId]);
        foreach ([$this->connectionId, $this->harborId] as $id) {
            (new Connection())->delete(['id' => $id], true);
        }
        foreach ($this->computerIds as $id) {
            (new Computer())->delete(['id' => $id], true);
        }
        (new ApplianceEnvironment())->delete(['id' => $this->environmentId], true);
        (new \Entity())->delete(['id' => $this->entityId], true);
        HarborArtifact::resetCache();
        $_SESSION['MESSAGE_AFTER_REDIRECT'] = [];
    }

    private function connection(): Connection
    {
        $connection = new Connection();
        $connection->getFromDB($this->connectionId);

        return $connection;
    }

    /**
     * @param array<string, int> $fail
     * @param list<string>|null $log
     * @return array<string, mixed>
     */
    private function import(array $fail = [], ?array &$log = null): array
    {
        $transport = KubernetesFixture::transport($this->fixture, $fail, $log);

        return (new KubernetesImporter(static fn (Connection $c): KubernetesClient => new KubernetesClient(
            (string) $c->fields['url'],
            $c->getDecryptedApiKey(),
            '',
            $transport
        )))->import($this->connection());
    }

    /**
     * @return array<string, array<string, mixed>> service_uid => ligne.
     */
    private function services(): array
    {
        global $DB;

        $rows = [];
        foreach (
            $DB->request(['FROM' => Service::getTable(),
            'WHERE' => ['plugin_dockerinventory_connections_id' => $this->connectionId]]) as $row
        ) {
            $rows[(string) $row['service_uid']] = $row;
        }

        return $rows;
    }

    public function testConnectionSettingsAndSecretToken(): void
    {
        $connection = $this->connection();
        $this->assertTrue($connection->isKubernetes());
        $this->assertSame(KubernetesMapper::DEFAULT_EXCLUDED, $connection->fields['k8s_excluded_namespaces']);
        $this->assertSame(0, (int) $connection->fields['allow_http']);
        $this->assertNotSame(KubernetesFixture::TOKEN, $connection->fields['api_key']);
        $this->assertSame(KubernetesFixture::TOKEN, $connection->getDecryptedApiKey());

        // HTTP refusé, jeton obligatoire, certificat invalide refusé.
        $refused = new Connection();
        $this->assertFalse($refused->add([
            'name' => 'refus', 'type' => Connection::TYPE_KUBERNETES, 'url' => 'http://kubernetes.exemple.test:6443',
            'api_key' => 'x', 'allow_http' => 1, 'entities_id' => $this->entityId,
        ]));
        $this->assertFalse($refused->add([
            'name' => 'refus', 'type' => Connection::TYPE_KUBERNETES, 'url' => 'https://kubernetes.exemple.test:6443',
            'entities_id' => $this->entityId,
        ]));
        $this->assertFalse($connection->update([
            'id' => $this->connectionId, 'ca_certificate' => 'pas un certificat',
        ]));
        $this->assertTrue($connection->update([
            'id' => $this->connectionId, 'k8s_excluded_namespaces' => "kube-*\n<script>",
        ]));
        $this->assertSame('kube-*', $this->connection()->fields['k8s_excluded_namespaces']);
        $_SESSION['MESSAGE_AFTER_REDIRECT'] = [];
    }

    public function testFullImport(): void
    {
        global $DB;

        $report = $this->import();
        $this->assertSame(Connection::STATUS_OK, $report['status'], (string) json_encode($report));
        $this->assertSame('v1.31.4', $report['version']);
        $this->assertSame(5, $report['namespaces']);
        $this->assertSame(3, $report['stacks']);
        $this->assertSame(7, $report['services']);
        $this->assertSame(2, $report['nodes']);
        $this->assertSame(2, $report['linked_nodes']);
        $this->assertSame(3, $report['appliances_created']);

        $services = $this->services();
        $this->assertCount(7, $services);
        $vitrine = $services['k8s/deployment/boutique/vitrine/api'];
        $this->assertSame('vitrine', $vitrine['name']);
        $this->assertSame(KubernetesMapper::KIND_DEPLOYMENT, $vitrine['kind']);
        $this->assertSame('sha256:' . str_repeat('a190', 16), $vitrine['digest']);
        $this->assertSame(2, (int) $vitrine['replicas']);
        $this->assertSame(2, (int) $vitrine['running_replicas']);
        $this->assertSame($this->entityId, (int) $vitrine['entities_id']);
        $this->assertSame((string) $this->connection()->fields['name'], $vitrine['endpoint_name']);

        // Namespace => application, créée avec l'environnement de la connexion.
        $appliance = new Appliance();
        $this->assertTrue($appliance->getFromDB((int) $vitrine['appliances_id']));
        $this->assertSame('boutique', $appliance->fields['name']);
        $this->assertSame($this->environmentId, (int) $appliance->fields['applianceenvironments_id']);
        $this->assertSame(
            (int) $vitrine['appliances_id'],
            (int) $services['k8s/statefulset/boutique/cache/redis']['appliances_id']
        );

        // Nœuds rattachés : par nom court, puis par adresse IP ; pods en cours répartis.
        $nodes = [];
        foreach (
            $DB->request(['FROM' => Node::getTable(), 'WHERE' => [
            'plugin_dockerinventory_connections_id' => $this->connectionId,
            ]]) as $row
        ) {
            $nodes[(string) $row['hostname']] = $row;
        }
        $this->assertSame($this->computerIds[0], (int) $nodes['k8s-node-01.exemple.test']['computers_id']);
        $this->assertSame($this->computerIds[1], (int) $nodes['k8s-node-02']['computers_id']);
        $this->assertSame(KubernetesMapper::ROLE_CONTROL_PLANE, $nodes['k8s-node-01.exemple.test']['role']);
        $this->assertSame(2, countElementsInTable(InventoryStore::PLACEMENT_TABLE, [
            'plugin_dockerinventory_services_id' => (int) $vitrine['id'],
        ]));

        // Second import : rien de nouveau, rien de retiré, aucune application de plus.
        $again = $this->import();
        $this->assertSame(0, $again['retired']);
        $this->assertSame(0, $again['appliances_created']);
        $this->assertCount(7, $this->services());
    }

    /**
     * Exposition (#72) : ingress et services NodePort rattachés aux charges par leurs sélecteurs ;
     * une liste refusée garde les routes du dernier import.
     */
    public function testIngressesAndNodePortsAreRecorded(): void
    {
        $this->import();
        $services = $this->services();
        $shop = ExposureMapper::decode((string) $services['k8s/deployment/boutique/vitrine/api']['routes']);
        $this->assertCount(1, $shop);
        $this->assertSame(ExposureMapper::SOURCE_INGRESS, $shop[0]['source']);
        $this->assertSame('boutique.exemple.test', $shop[0]['host']);
        $this->assertTrue($shop[0]['tls']);
        // Les deux conteneurs du portail reçoivent le port du nœud.
        foreach (['wiki', 'journal'] as $container) {
            $uid = 'k8s/deployment/intranet/portail/' . $container;
            $portal = ExposureMapper::decode((string) $services[$uid]['routes']);
            $this->assertSame([30080], array_column($portal, 'port'), $container);
        }
        $this->assertSame('', (string) $services['k8s/statefulset/boutique/cache/redis']['routes']);

        $exposures = Exposure::exposuresOf($services['k8s/deployment/boutique/vitrine/api']);
        $this->assertSame([ExposureMapper::SCOPE_INTERNET], array_column($exposures, 'scope'));
        // Ports des conteneurs Kubernetes : pas des ports publiés.
        $this->assertSame([], Exposure::exposuresOf(['kind' => 'deployment', 'ports' => '8080/tcp']));

        $this->fixture['ingresses'] = [];
        $report = $this->import(['ingresses' => 403]);
        $this->assertSame(Connection::STATUS_WARNING, $report['status']);
        $this->assertSame(
            [['endpoint' => 'ingresses', 'code' => KubernetesImporter::WARNING_FORBIDDEN]],
            $report['warnings']
        );
        $kept = ExposureMapper::decode((string) $this->services()['k8s/deployment/boutique/vitrine/api']['routes']);
        $this->assertSame('boutique.exemple.test', $kept[0]['host'] ?? '');

        $this->import();
        $this->assertSame('', (string) $this->services()['k8s/deployment/boutique/vitrine/api']['routes']);
    }

    public function testVanishedWorkloadIsRetiredThenRevived(): void
    {
        $this->import();
        $kept = $this->fixture;
        $this->fixture['statefulsets'] = [];
        $report = $this->import();
        $this->assertSame(1, $report['retired']);
        $cache = $this->services()['k8s/statefulset/boutique/cache/redis'];
        $this->assertSame(1, (int) $cache['is_retired']);
        $this->assertNotNull($cache['date_retired']);

        $this->fixture = $kept;
        $this->import();
        $this->assertSame(0, (int) $this->services()['k8s/statefulset/boutique/cache/redis']['is_retired']);
    }

    public function testNewTagIsRecordedInTheVersionJournal(): void
    {
        global $DB;

        $this->import();
        $this->fixture['deployments'][0]['spec']['template']['spec']['containers'][0]['image']
            = 'registry.exemple.test:5000/boutique/api:1.10.0';
        $this->import();
        $vitrine = $this->services()['k8s/deployment/boutique/vitrine/api'];
        $this->assertSame('1.10.0', $vitrine['tag']);
        $change = $DB->request(['FROM' => VersionChange::TABLE, 'WHERE' => [
            'plugin_dockerinventory_services_id' => (int) $vitrine['id'],
        ]])->current();
        $this->assertIsArray($change);
        $this->assertSame(VersionChange::KIND_TAG, $change['kind']);
        $this->assertSame('1.9.0', $change['old_tag']);
        $this->assertNotNull($this->connection()->fields['last_success_date']);
        $DB->delete(VersionChange::TABLE, ['plugin_dockerinventory_connections_id' => $this->connectionId]);
    }

    public function testForbiddenListWarnsAndRetiresNothingOfIt(): void
    {
        $this->import();
        $this->fixture['cronjobs'] = [];
        $report = $this->import(['cronjobs' => 403]);
        $this->assertSame(Connection::STATUS_WARNING, $report['status']);
        $this->assertSame(
            [['endpoint' => 'cronjobs', 'code' => KubernetesImporter::WARNING_FORBIDDEN]],
            $report['warnings']
        );
        $this->assertSame(0, $report['retired']);
        $this->assertSame(0, (int) $this->services()['k8s/cronjob/intranet/sauvegarde/sauvegarde']['is_retired']);
    }

    public function testRateLimitStopsCallsKeepsDigestsAndRetiresNothing(): void
    {
        $this->import();
        $log = [];
        $this->fixture['deployments'] = [];
        $report = $this->import(['pods' => 429], $log);
        $this->assertSame(Connection::STATUS_PARTIAL, $report['status']);
        $this->assertSame([['endpoint' => 'pods', 'code' => KubernetesException::RATE_LIMITED]], $report['errors']);
        // Après la limite : plus aucune liste demandée.
        $this->assertSame(['/version', '/api/v1/namespaces', '/api/v1/nodes', '/api/v1/pods'], $log);
        $this->assertSame(0, $report['retired']);
        $vitrine = $this->services()['k8s/deployment/boutique/vitrine/api'];
        $this->assertSame(0, (int) $vitrine['is_retired']);
        $this->assertSame('sha256:' . str_repeat('a190', 16), $vitrine['digest']);
    }

    public function testEmptyImportNeverEmptiesTheInventory(): void
    {
        $this->import();
        foreach (KubernetesMapper::WORKLOAD_RESOURCES as $resource) {
            $this->fixture[$resource] = [];
        }
        $report = $this->import();
        $this->assertSame(Connection::STATUS_WARNING, $report['status']);
        $this->assertContains(
            ['endpoint' => '', 'code' => KubernetesImporter::WARNING_EMPTY_KEPT],
            $report['warnings']
        );
        $this->assertSame(0, $report['retired']);
        foreach ($this->services() as $row) {
            $this->assertSame(0, (int) $row['is_retired']);
        }
    }

    public function testRefusedTokenChangesNothing(): void
    {
        $this->import();
        $connection = $this->connection();
        $connection->update(['id' => $this->connectionId, 'api_key' => 'jeton-revoque']);
        $report = $this->import();
        $this->assertSame(Connection::STATUS_ERROR, $report['status']);
        $this->assertSame(KubernetesException::UNAUTHORIZED, $report['errors'][0]['code']);
        $active = array_filter($this->services(), static fn (array $r): bool => (int) $r['is_retired'] === 0);
        $this->assertCount(7, $active);
        $this->assertSame(Connection::STATUS_ERROR, (int) $this->connection()->fields['last_import_status']);
    }

    public function testTestConnectionWarnsWhenSomethingIsUnreadable(): void
    {
        $connection = $this->connection();
        $ok = $connection->testKubernetesConnection(new KubernetesClient(
            'https://kubernetes.exemple.test:6443',
            KubernetesFixture::TOKEN,
            '',
            KubernetesFixture::transport($this->fixture)
        ));
        $this->assertTrue($ok['ok']);
        $this->assertFalse($ok['warning'] ?? true);
        $this->assertStringContainsString('v1.31.4', $ok['message']);

        $forbidden = $connection->testKubernetesConnection(new KubernetesClient(
            'https://kubernetes.exemple.test:6443',
            KubernetesFixture::TOKEN,
            '',
            KubernetesFixture::transport($this->fixture, ['replicasets' => 403])
        ));
        $this->assertTrue($forbidden['ok']);
        $this->assertTrue($forbidden['warning'] ?? false);
        $this->assertStringContainsString('replicasets', $forbidden['message']);

        $this->fixture['namespaces'] = [];
        $empty = $connection->testKubernetesConnection(new KubernetesClient(
            'https://kubernetes.exemple.test:6443',
            KubernetesFixture::TOKEN,
            '',
            KubernetesFixture::transport($this->fixture)
        ));
        $this->assertTrue($empty['warning'] ?? false);

        $refused = $connection->testKubernetesConnection(new KubernetesClient(
            'https://kubernetes.exemple.test:6443',
            'mauvais-jeton',
            '',
            KubernetesFixture::transport($this->fixture)
        ));
        $this->assertFalse($refused['ok']);
    }

    public function testVersionsAndHarborVulnerabilitiesOfDeployedImages(): void
    {
        $this->import();
        $harbor = new Connection();
        $harbor->getFromDB($this->harborId);
        $harborFixture = HarborFixture::load();
        (new HarborImporter(static fn (Connection $c): HarborClient => new HarborClient(
            'https://harbor.exemple.test',
            'robot$lecture',
            'robot_mot_de_passe_fictif',
            HarborFixture::transport($harborFixture)
        ), false))->import($harbor);
        HarborArtifact::resetCache();

        $services = $this->services();
        $service = new Service();
        // Empreinte lue dans le statut des pods : rattachée à l'artefact Harbor par l'empreinte.
        $this->assertTrue($service->getFromDB((int) $services['k8s/deployment/boutique/vitrine/api']['id']));
        $link = HarborArtifact::forService($service);
        $this->assertSame(HarborLinker::METHOD_DIGEST, $link['method']);
        $this->assertSame('critical', $link['artifact']['severity_code']);
        $this->assertTrue($service->getFromDB((int) $services['k8s/deployment/intranet/portail/wiki']['id']));
        $this->assertSame(HarborLinker::METHOD_DIGEST, HarborArtifact::forService($service)['method']);

        // Versions : dernière version lue dans Harbor (repli sans WUD), comme pour Portainer.
        (new RegistryImporter(static function (?Connection $c) use ($harborFixture): RegistryClient {
            if ($c === null) {
                return RegistryClient::dockerHub(
                    'https://hub.exemple.test',
                    static fn (string $url): string
                        => throw new \RuntimeException('HTTP 404 returned for "' . $url . '"')
                );
            }

            return RegistryClient::harbor(
                'https://harbor.exemple.test',
                'robot$lecture',
                'robot_mot_de_passe_fictif',
                static function (string $url) use ($harborFixture): string {
                    $path = (string) parse_url($url, PHP_URL_PATH);
                    if ($path === '/v2/boutique/api/tags/list') {
                        return (string) json_encode(
                            ['name' => 'boutique/api', 'tags' => ['1.9.0', '1.10.0', 'latest']]
                        );
                    }

                    return HarborFixture::respond($harborFixture, $path, []);
                }
            );
        }))->run();
        $version = new ImageVersion();
        $this->assertTrue($version->getFromDBByCrit([
            'plugin_dockerinventory_services_id' => (int) $services['k8s/deployment/boutique/vitrine/api']['id'],
        ]));
        $this->assertSame('1.9.0', $version->fields['deployed_tag']);
        $this->assertSame('1.10.0', $version->fields['latest_tag']);
        $this->assertSame(VersionComparator::GAP_MINOR, $version->fields['gap']);
    }
}

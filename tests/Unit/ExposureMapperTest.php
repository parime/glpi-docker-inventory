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
use GlpiPlugin\Dockerinventory\Portainer\PortainerMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Exposition (#72) : routes Traefik, ingress et services Kubernetes, ports publiés, portée et flux
 * proposé pour GRC Manager. Données fictives uniquement.
 */
final class ExposureMapperTest extends TestCase
{
    public function testTraefikRoutersAreRead(): void
    {
        $routes = ExposureMapper::traefikRoutes([
            'traefik.enable'                                           => 'true',
            'traefik.http.routers.vitrine.rule'                        => 'Host(`boutique.exemple.test`)'
                . ' && PathPrefix(`/api`)',
            'traefik.http.routers.vitrine.entrypoints'                 => 'websecure',
            'traefik.http.routers.vitrine.tls.certresolver'            => 'acme',
            'traefik.http.routers.admin.rule'                          => 'Host(`admin.exemple.intranet`)',
            'traefik.http.services.vitrine.loadbalancer.server.port'   => '8443',
            'com.docker.stack.namespace'                               => 'boutique',
        ]);

        $this->assertCount(2, $routes);
        [$admin, $shop] = $routes;
        $this->assertSame('admin.exemple.intranet', $admin['host']);
        $this->assertFalse($admin['tls']);
        $this->assertSame('boutique.exemple.test', $shop['host']);
        $this->assertSame('/api', $shop['path']);
        $this->assertTrue($shop['tls']);
        $this->assertSame(8443, $shop['port']);
        $this->assertSame('websecure', $shop['entrypoints']);
        $this->assertSame(ExposureMapper::SOURCE_TRAEFIK, $shop['source']);
    }

    public function testSeveralHostsGiveSeveralRoutes(): void
    {
        $routes = ExposureMapper::traefikRoutes([
            'traefik.http.routers.wiki.rule' => 'Host(`wiki.exemple.test`, `doc.exemple.test`)'
                . ' || Host("aide.exemple.test")',
        ]);

        $this->assertSame(
            ['wiki.exemple.test', 'doc.exemple.test', 'aide.exemple.test'],
            array_column($routes, 'host')
        );
    }

    public function testDisabledTraefikAndOtherLabelsGiveNothing(): void
    {
        $this->assertSame([], ExposureMapper::traefikRoutes([
            'traefik.enable'                 => 'false',
            'traefik.http.routers.app.rule' => 'Host(`app.exemple.test`)',
        ]));
        $this->assertSame([], ExposureMapper::traefikRoutes(['com.docker.compose.project' => 'wiki']));
    }

    public function testLegacyTraefikRule(): void
    {
        $routes = ExposureMapper::traefikRoutes([
            'traefik.frontend.rule' => 'Host:ancien.exemple.test;PathPrefix:/portail',
            'traefik.port'          => '8080',
        ]);

        $this->assertCount(1, $routes);
        $this->assertSame('ancien.exemple.test', $routes[0]['host']);
        $this->assertSame('/portail', $routes[0]['path']);
        $this->assertSame(8080, $routes[0]['port']);
    }

    public function testSwarmServiceAndComposeContainersCarryTheirRoutes(): void
    {
        $service = PortainerMapper::mapService([
            'ID'   => 'svc-1',
            'Spec' => [
                'Name'   => 'boutique_vitrine',
                'Labels' => ['traefik.http.routers.vitrine.rule' => 'Host(`boutique.exemple.test`)'],
            ],
        ]);
        $this->assertNotNull($service);
        $this->assertSame('boutique.exemple.test', ExposureMapper::decode($service['routes'])[0]['host']);

        $container = [
            'Id'     => 'c1',
            'Names'  => ['/wiki-wiki-1'],
            'Image'  => 'wiki:3.2.0',
            'State'  => 'running',
            'Labels' => [
                'com.docker.compose.project'  => 'wiki',
                'com.docker.compose.service'  => 'wiki',
                'traefik.http.routers.wiki.rule' => 'Host(`wiki.exemple.intranet`)',
            ],
        ];
        $mapped = PortainerMapper::mapContainer($container);
        $second = PortainerMapper::mapContainer(['Id' => 'c2', 'Names' => ['/wiki-wiki-2']] + $container);
        $this->assertNotNull($mapped);
        $this->assertNotNull($second);
        $grouped = PortainerMapper::groupContainers(2, [$mapped, $second]);
        $this->assertCount(1, $grouped);
        // Même route sur les deux conteneurs : une seule.
        $this->assertCount(1, ExposureMapper::decode($grouped[0]['routes']));
    }

    /**
     * @return iterable<string, array{string, string|null, int}>
     */
    public static function portProvider(): iterable
    {
        yield 'toutes les adresses' => ['8081:8080/tcp', ExposureMapper::SCOPE_HOST, 8081];
        yield 'mode host de Swarm' => ['8080:80/tcp (host)', ExposureMapper::SCOPE_HOST, 8080];
        yield 'bouclage' => ['127.0.0.1:3000:3000/tcp', ExposureMapper::SCOPE_LOCAL, 3000];
        yield 'adresse privée' => ['10.0.0.5:5432:5432/tcp', ExposureMapper::SCOPE_INTERNAL, 5432];
        yield 'adresse publique (documentation)' => ['203.0.113.10:443:8443/tcp', ExposureMapper::SCOPE_INTERNET, 443];
        yield 'illisible' => ['n/a', null, 0];
    }

    #[DataProvider('portProvider')]
    public function testPublishedPorts(string $entry, ?string $scope, int $port): void
    {
        $parsed = ExposureMapper::parsePort($entry);
        if ($scope === null) {
            $this->assertNull($parsed);

            return;
        }
        $this->assertNotNull($parsed);
        $this->assertSame($scope, $parsed['scope']);
        $this->assertSame($port, $parsed['port']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function hostProvider(): iterable
    {
        yield 'nom public' => ['boutique.exemple.test', ExposureMapper::SCOPE_INTERNET];
        yield 'intranet' => ['wiki.exemple.intranet', ExposureMapper::SCOPE_INTERNAL];
        yield 'nom court' => ['wiki', ExposureMapper::SCOPE_INTERNAL];
        yield 'cluster' => ['api.boutique.svc.cluster.local', ExposureMapper::SCOPE_INTERNAL];
        yield 'adresse privée' => ['192.168.10.4', ExposureMapper::SCOPE_INTERNAL];
        yield 'adresse publique (documentation)' => ['198.51.100.7', ExposureMapper::SCOPE_INTERNET];
        yield 'localhost' => ['localhost', ExposureMapper::SCOPE_LOCAL];
    }

    #[DataProvider('hostProvider')]
    public function testHostScope(string $host, string $scope): void
    {
        $this->assertSame($scope, ExposureMapper::hostScope($host));
    }

    public function testKubernetesIngressAndServicesAreLinkedToWorkloads(): void
    {
        $workloads = [
            'deployment' => [[
                'metadata' => ['name' => 'vitrine', 'namespace' => 'boutique'],
                'spec'     => ['template' => ['metadata' => ['labels' => ['app' => 'vitrine', 'tier' => 'web']]]],
            ], [
                'metadata' => ['name' => 'stock', 'namespace' => 'boutique'],
                'spec'     => ['template' => ['metadata' => ['labels' => ['app' => 'stock']]]],
            ]],
        ];
        $services = [[
            'metadata' => ['name' => 'vitrine', 'namespace' => 'boutique'],
            'spec'     => ['type' => 'ClusterIP', 'selector' => ['app' => 'vitrine']],
        ], [
            'metadata' => ['name' => 'stock-lb', 'namespace' => 'boutique'],
            'spec'     => [
                'type'     => 'LoadBalancer',
                'selector' => ['app' => 'stock'],
                'ports'    => [['port' => 443, 'nodePort' => 31443, 'protocol' => 'TCP']],
            ],
            'status'   => ['loadBalancer' => ['ingress' => [['ip' => '203.0.113.20']]]],
        ], [
            // Même nom dans un autre namespace : aucune charge.
            'metadata' => ['name' => 'vitrine', 'namespace' => 'autre'],
            'spec'     => ['selector' => ['app' => 'vitrine']],
        ]];
        $ingresses = [[
            'metadata' => ['name' => 'vitrine', 'namespace' => 'boutique'],
            'spec'     => [
                'ingressClassName' => 'traefik',
                'tls'              => [['hosts' => ['boutique.exemple.test']]],
                'rules'            => [[
                    'host' => 'boutique.exemple.test',
                    'http' => ['paths' => [['path' => '/', 'backend' => ['service' => ['name' => 'vitrine']]]]],
                ], [
                    'host' => 'recette.exemple.intranet',
                    'http' => ['paths' => [['path' => '/', 'backend' => ['service' => ['name' => 'vitrine']]]]],
                ]],
            ],
        ]];

        $routes = ExposureMapper::kubernetesRoutes($services, $ingresses, $workloads);

        $this->assertSame(['deployment/boutique/stock', 'deployment/boutique/vitrine'], array_keys($routes));
        $shop = $routes['deployment/boutique/vitrine'];
        $this->assertCount(2, $shop);
        $this->assertSame(ExposureMapper::SOURCE_INGRESS, $shop[0]['source']);
        $this->assertTrue($shop[0]['tls']);
        $this->assertFalse($shop[1]['tls']);
        $this->assertSame('traefik', $shop[0]['entrypoints']);

        $stock = $routes['deployment/boutique/stock'];
        $this->assertSame(['', '203.0.113.20'], array_column($stock, 'host'));
        $this->assertSame([31443, 443], array_column($stock, 'port'));

        $exposures = ExposureMapper::exposures('8080/tcp', ExposureMapper::encode($stock), true);
        $this->assertSame(
            [ExposureMapper::SCOPE_INTERNAL, ExposureMapper::SCOPE_INTERNET],
            array_column($exposures, 'scope')
        );
        $this->assertSame(
            'deployment/boutique/stock',
            ExposureMapper::workloadKey('k8s/deployment/boutique/stock/api')
        );
        $this->assertSame('', ExposureMapper::workloadKey('compose:2:wiki:wiki'));
    }

    public function testUnreadableListsGiveNoRoute(): void
    {
        $this->assertSame([], ExposureMapper::kubernetesRoutes(null, null, []));
    }

    public function testDecodeIsDefensive(): void
    {
        $this->assertSame([], ExposureMapper::decode(''));
        $this->assertSame([], ExposureMapper::decode('{pas du json'));
        $this->assertSame([], ExposureMapper::decode('[{"source":"inconnue","host":"x"}]'));
        $this->assertSame('', ExposureMapper::encode([]));
    }

    public function testProposalForAnInternetRouteAndAPublishedPort(): void
    {
        $route = ExposureMapper::exposures('', ExposureMapper::encode(ExposureMapper::traefikRoutes([
            'traefik.http.routers.vitrine.rule' => 'Host(`boutique.exemple.test`)',
            'traefik.http.routers.vitrine.tls'  => 'true',
        ])), false)[0];
        $proposal = ExposureMapper::proposal($route, 'vitrine', 'Internet', 'Internal network');
        $this->assertNotNull($proposal);
        $this->assertSame(ExposureMapper::FLOW_INTERNET, $proposal['flow_type']);
        $this->assertSame('Internet', $proposal['source_label']);
        $this->assertSame('boutique.exemple.test', $proposal['destination_label']);
        $this->assertSame('443', $proposal['ports']);
        $this->assertSame('tcp', $proposal['protocol']);

        $port = ExposureMapper::exposures('8081:8080/tcp, 127.0.0.1:3000:3000/tcp', '', false);
        $this->assertCount(2, $port);
        $internal = ExposureMapper::proposal($port[0], 'adminer', 'Internet', 'Internal network');
        $this->assertNotNull($internal);
        $this->assertSame(ExposureMapper::FLOW_INTERNAL, $internal['flow_type']);
        $this->assertSame('adminer', $internal['destination_label']);
        $this->assertSame('8081', $internal['ports']);
        // Joignable depuis l'hôte seul : aucun flux à documenter.
        $this->assertNull(ExposureMapper::proposal($port[1], 'wiki', 'Internet', 'Internal network'));
    }

    public function testDocumentedFlow(): void
    {
        $proposal = [
            'flow_type'         => ExposureMapper::FLOW_INTERNET,
            'name'              => 'Internet → boutique.exemple.test:443',
            'source_label'      => 'Internet',
            'destination_label' => 'boutique.exemple.test',
            'protocol'          => 'tcp',
            'ports'             => '443',
        ];
        $flow = [
            'destination_itemtype' => '',
            'destination_items_id' => 0,
            'destination_label'    => 'Reverse proxy boutique.exemple.test',
            'protocol'             => 'tcp',
            'ports'                => '80,443',
        ];

        $this->assertTrue(ExposureMapper::isDocumented($proposal, 0, 'boutique.exemple.test', 'vitrine', [$flow]));
        // Port non couvert, ou autre protocole : pas documenté.
        $this->assertFalse(ExposureMapper::isDocumented(
            $proposal,
            0,
            'boutique.exemple.test',
            'vitrine',
            [['ports' => '8000-8100'] + $flow]
        ));
        $this->assertFalse(ExposureMapper::isDocumented(
            $proposal,
            0,
            'boutique.exemple.test',
            'vitrine',
            [['protocol' => 'udp'] + $flow]
        ));
        // Application du service en destination, tous ports.
        $byAppliance = ['destination_itemtype' => 'Appliance', 'destination_items_id' => 7, 'destination_label' => '',
            'protocol' => 'any', 'ports' => 'any'];
        $this->assertTrue(ExposureMapper::isDocumented($proposal, 7, '', 'vitrine', [$byAppliance]));
        $this->assertFalse(ExposureMapper::isDocumented($proposal, 8, '', 'vitrine', [$byAppliance]));
        $this->assertFalse(ExposureMapper::isDocumented($proposal, 0, '', 'vitrine', []));
    }

    public function testPortCovered(): void
    {
        $this->assertTrue(ExposureMapper::portCovered(443, 'any'));
        $this->assertTrue(ExposureMapper::portCovered(8050, '80,8000-8100'));
        $this->assertFalse(ExposureMapper::portCovered(22, '80,443'));
    }
}

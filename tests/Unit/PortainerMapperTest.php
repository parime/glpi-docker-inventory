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

use GlpiPlugin\Dockerinventory\Portainer\PortainerMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PortainerMapperTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function imageProvider(): iterable
    {
        $digest = 'sha256:' . str_repeat('ab', 32);
        yield 'nom seul' => ['nginx', 'nginx', 'latest', ''];
        yield 'nom et tag' => ['postgres:16.4', 'postgres', '16.4', ''];
        yield 'registre avec port' => [
            'registry.exemple.test:5000/boutique/api:1.9.0',
            'registry.exemple.test:5000/boutique/api',
            '1.9.0',
            '',
        ];
        yield 'registre avec port sans tag' => [
            'registry.exemple.test:5000/boutique/api',
            'registry.exemple.test:5000/boutique/api',
            'latest',
            '',
        ];
        yield 'tag et empreinte' => ['boutique/web:2.4.1@' . $digest, 'boutique/web', '2.4.1', $digest];
        yield 'empreinte seule' => ['boutique/web@' . $digest, 'boutique/web', '', $digest];
        yield 'vide' => ['', '', '', ''];
    }

    #[DataProvider('imageProvider')]
    public function testParseImage(string $reference, string $image, string $tag, string $digest): void
    {
        $this->assertSame(
            ['image' => $image, 'tag' => $tag, 'digest' => $digest],
            PortainerMapper::parseImage($reference)
        );
    }

    public function testMapReplicatedServiceKeepsSecretNamesOnly(): void
    {
        $service = [
            'ID'       => 'svc-1',
            'Spec'     => [
                'Name'         => 'boutique_web',
                'Labels'       => [PortainerMapper::STACK_LABEL => 'boutique'],
                'Mode'         => ['Replicated' => ['Replicas' => 3]],
                'TaskTemplate' => [
                    'ContainerSpec' => [
                        'Image'   => 'boutique/web:2.4.1',
                        'Secrets' => [
                            ['SecretID' => 'sec-1', 'SecretName' => 'boutique_tls_cert', 'File' => ['Name' => 'tls']],
                            ['SecretID' => 'sec-2', 'SecretName' => 'boutique_db_password'],
                        ],
                    ],
                    'Networks' => [['Target' => 'net-1'], ['Target' => 'net-inconnu']],
                ],
            ],
            'Endpoint' => ['Ports' => [
                ['Protocol' => 'tcp', 'TargetPort' => 8443, 'PublishedPort' => 443, 'PublishMode' => 'ingress'],
                ['Protocol' => 'udp', 'TargetPort' => 53, 'PublishedPort' => 5353, 'PublishMode' => 'host'],
                ['Protocol' => 'tcp', 'TargetPort' => 9000],
            ]],
        ];

        $mapped = PortainerMapper::mapService($service, ['net-1' => 'boutique_front'], ['svc-1' => 2]);

        $this->assertNotNull($mapped);
        $this->assertSame('boutique_web', $mapped['name']);
        $this->assertSame('boutique', $mapped['stack_name']);
        $this->assertSame('replicated', $mapped['mode']);
        $this->assertSame(3, $mapped['replicas']);
        $this->assertSame(2, $mapped['running_replicas']);
        $this->assertSame('443:8443/tcp, 5353:53/udp (host)', $mapped['ports']);
        $this->assertSame('boutique_front, net-inconnu', $mapped['networks']);
        $this->assertSame('boutique_db_password, boutique_tls_cert', $mapped['secrets']);
        $this->assertStringNotContainsString('sec-1', json_encode($mapped) ?: '');
    }

    public function testMapGlobalServiceWithoutStack(): void
    {
        $mapped = PortainerMapper::mapService([
            'ID'   => 'svc-2',
            'Spec' => ['Name' => 'agent', 'Mode' => ['Global' => []], 'TaskTemplate' => ['ContainerSpec' => [
                'Image' => 'grafana/alloy:latest',
            ]]],
        ]);

        $this->assertNotNull($mapped);
        $this->assertSame('global', $mapped['mode']);
        $this->assertNull($mapped['replicas']);
        $this->assertSame('', $mapped['stack_name']);
        $this->assertSame('', $mapped['ports']);
        $this->assertSame(0, $mapped['running_replicas']);
    }

    public function testServiceWithoutIdIsIgnored(): void
    {
        $this->assertNull(PortainerMapper::mapService(['Spec' => ['Name' => 'sans-id']]));
        $this->assertNull(PortainerMapper::mapNode(['Description' => ['Hostname' => 'sans-id']]));
    }

    public function testMapNode(): void
    {
        $this->assertSame([
            'node_uid'       => 'node-1',
            'hostname'       => 'swarm-node-01.exemple.test',
            'role'           => 'manager',
            'availability'   => 'active',
            'state'          => 'ready',
            'engine_version' => '27.3.1',
            'address'        => '192.0.2.11',
        ], PortainerMapper::mapNode([
            'ID'          => 'node-1',
            'Description' => ['Hostname' => 'swarm-node-01.exemple.test', 'Engine' => ['EngineVersion' => '27.3.1']],
            'Spec'        => ['Role' => 'manager', 'Availability' => 'active'],
            'Status'      => ['State' => 'ready', 'Addr' => '192.0.2.11'],
        ]));
    }

    public function testRunningTasksCountsOnlyRunningOnes(): void
    {
        $result = PortainerMapper::runningTasks([
            ['ServiceID' => 's1', 'NodeID' => 'n1', 'Status' => ['State' => 'running']],
            ['ServiceID' => 's1', 'NodeID' => 'n1', 'Status' => ['State' => 'running']],
            ['ServiceID' => 's1', 'NodeID' => 'n2', 'Status' => ['State' => 'starting']],
            ['ServiceID' => 's2', 'NodeID' => 'n2', 'Status' => ['State' => 'running']],
            'pas un tableau',
        ]);

        $this->assertSame(['s1' => 2, 's2' => 1], $result['services']);
        $this->assertSame(['s1' => ['n1' => 2], 's2' => ['n2' => 1]], $result['placement']);
    }

    public function testNetworkNamesAndShortHostname(): void
    {
        $this->assertSame(['n1' => 'front'], PortainerMapper::networkNames([['Id' => 'n1', 'Name' => 'front'], []]));
        $this->assertSame('swarm-node-01', PortainerMapper::shortHostname('swarm-node-01.exemple.test'));
        $this->assertSame('swarm-node-01', PortainerMapper::shortHostname('swarm-node-01'));
    }

    public function testSnapshotCountsReadTheFirstSnapshotOnly(): void
    {
        $this->assertNull(PortainerMapper::snapshotCounts(['Id' => 1]));
        $this->assertNull(PortainerMapper::snapshotCounts(['Id' => 1, 'Snapshots' => []]));
        $this->assertSame(
            ['swarm' => true, 'services' => 7, 'containers' => 9],
            PortainerMapper::snapshotCounts(['Snapshots' => [
                ['Swarm' => true, 'ServiceCount' => 7, 'ContainerCount' => 9, 'DockerSnapshotRaw' => []],
                ['Swarm' => false, 'ServiceCount' => 0, 'ContainerCount' => 0],
            ]])
        );
        $this->assertSame(
            ['swarm' => false, 'services' => 0, 'containers' => 0],
            PortainerMapper::snapshotCounts(['Snapshots' => [['Swarm' => 'oui', 'ContainerCount' => -3]]])
        );
    }

    public function testResourcesHiddenWhenTheSnapshotCountsWhatTheAccountCannotSee(): void
    {
        $swarm = ['Snapshots' => [['Swarm' => true, 'ServiceCount' => 7, 'ContainerCount' => 9]]];
        $standalone = ['Snapshots' => [['Swarm' => false, 'ServiceCount' => 0, 'ContainerCount' => 7]]];
        $empty = ['Snapshots' => [['Swarm' => false, 'ServiceCount' => 0, 'ContainerCount' => 0]]];

        // Réponses vides d'un compte sans droits, instantané peuplé : ressources cachées.
        $this->assertTrue(PortainerMapper::resourcesHidden($swarm, true, 0));
        $this->assertTrue(PortainerMapper::resourcesHidden($standalone, false, 0));
        // Quelque chose de visible, environnement vide, ou pas d'instantané : rien à signaler.
        $this->assertFalse(PortainerMapper::resourcesHidden($swarm, true, 2));
        $this->assertFalse(PortainerMapper::resourcesHidden($empty, false, 0));
        $this->assertFalse(PortainerMapper::resourcesHidden(['Id' => 2], false, 0));
    }

    public function testAnonymousVolumeIsSixtyFourHexadecimalCharacters(): void
    {
        $this->assertTrue(PortainerMapper::isAnonymousVolume(str_repeat('5b91a7f5', 8)));
        $this->assertFalse(PortainerMapper::isAnonymousVolume('wiki-interne_pages'));
        $this->assertFalse(PortainerMapper::isAnonymousVolume(str_repeat('5b91a7f5', 7)));
        $this->assertFalse(PortainerMapper::isAnonymousVolume(strtoupper(str_repeat('5b91a7f5', 8))));
    }
}

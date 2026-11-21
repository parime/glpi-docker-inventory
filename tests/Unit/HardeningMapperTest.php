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

use GlpiPlugin\Dockerinventory\Hardening\HardeningMapper as M;
use GlpiPlugin\Dockerinventory\HardeningException;
use GlpiPlugin\Dockerinventory\Kubernetes\KubernetesMapper;
use GlpiPlugin\Dockerinventory\Portainer\PortainerMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Configuration à risque (#71) : lecture des réglages de sécurité de Docker, Swarm et Kubernetes,
 * gravités, exceptions. Données fictives uniquement.
 */
final class HardeningMapperTest extends TestCase
{
    /**
     * @param list<array{code: string, detail: string}> $findings
     * @return list<string>
     */
    private static function codes(array $findings): array
    {
        return array_column($findings, 'code');
    }

    public function testRiskyStandaloneContainerIsFullyReported(): void
    {
        $findings = M::fromContainerInspect([
            'Config'     => ['User' => '', 'Env' => ['MOT_DE_PASSE=secret-fictif']],
            'HostConfig' => [
                'Privileged'  => true,
                'NetworkMode' => 'host',
                'PidMode'     => 'host',
                'IpcMode'     => 'host',
                'CapAdd'      => ['CAP_SYS_ADMIN', 'NET_BIND_SERVICE', 'net_admin'],
                'Memory'      => 0,
                'NanoCpus'    => 0,
            ],
            'Mounts'     => [
                ['Type' => 'bind', 'Source' => '/var/run/docker.sock', 'Destination' => '/var/run/docker.sock'],
                ['Type' => 'bind', 'Source' => '/etc/', 'Destination' => '/host/etc'],
                ['Type' => 'bind', 'Source' => '/srv/donnees', 'Destination' => '/data'],
                ['Type' => 'volume', 'Source' => '/var/lib/docker/volumes/x/_data', 'Destination' => '/x'],
            ],
        ]);

        $this->assertSame([
            M::PRIVILEGED, M::DOCKER_SOCKET, M::HOST_NETWORK, M::HOST_PID, M::DANGEROUS_CAPABILITIES,
            M::SENSITIVE_MOUNT, M::HOST_IPC, M::ADDED_CAPABILITIES, M::ROOT_USER, M::NO_MEMORY_LIMIT,
            M::NO_CPU_LIMIT, M::WRITABLE_ROOTFS,
        ], self::codes($findings));
        $details = array_column($findings, 'detail', 'code');
        $this->assertSame('/var/run/docker.sock', $details[M::DOCKER_SOCKET]);
        $this->assertSame('/etc', $details[M::SENSITIVE_MOUNT]);
        $this->assertSame('SYS_ADMIN, NET_ADMIN', $details[M::DANGEROUS_CAPABILITIES]);
        $this->assertSame('NET_BIND_SERVICE', $details[M::ADDED_CAPABILITIES]);
        // Les variables d'environnement ne sont jamais lues.
        $this->assertStringNotContainsString('secret-fictif', M::encode($findings));
    }

    public function testHardenedContainerHasNoFinding(): void
    {
        $this->assertSame([], M::fromContainerInspect([
            'Config'     => ['User' => '1000:1000'],
            'HostConfig' => [
                'NetworkMode'    => 'bridge',
                'Memory'         => 268435456,
                'NanoCpus'       => 500000000,
                'ReadonlyRootfs' => true,
                'CapAdd'         => null,
            ],
            'Mounts'     => [['Type' => 'volume', 'Source' => '/var/lib/docker/volumes/x/_data']],
        ]));
    }

    public function testCpuQuotaAndOldBindsAreRead(): void
    {
        $findings = M::fromContainerInspect([
            'Config'     => ['User' => 'appli'],
            'HostConfig' => [
                'Memory'         => 1,
                'CpuQuota'       => 50000,
                'ReadonlyRootfs' => true,
                'Binds'          => ['/run/containerd/containerd.sock:/run/c.sock:ro', 'volume-nomme:/data'],
            ],
        ]);
        $this->assertSame([M::DOCKER_SOCKET], self::codes($findings));
    }

    public function testSwarmServiceIsRead(): void
    {
        $findings = M::fromSwarmService([
            'Spec' => [
                'TaskTemplate' => [
                    'ContainerSpec' => [
                        'User'          => 'root',
                        'CapabilityAdd' => ['CAP_NET_RAW'],
                        'Mounts'        => [['Type' => 'bind', 'Source' => '/']],
                    ],
                    'Resources'     => ['Limits' => ['MemoryBytes' => 134217728]],
                    'Networks'      => [['Target' => 'reseau-hote']],
                ],
            ],
        ], ['reseau-hote' => 'host']);

        $this->assertSame(
            [M::HOST_NETWORK, M::SENSITIVE_MOUNT, M::ADDED_CAPABILITIES, M::ROOT_USER, M::NO_CPU_LIMIT, M::WRITABLE_ROOTFS],
            self::codes($findings)
        );
        $this->assertSame('root', array_column($findings, 'detail', 'code')[M::ROOT_USER]);
    }

    public function testPodSpecIsRead(): void
    {
        $podSpec = [
            'hostNetwork'     => true,
            'securityContext' => ['runAsNonRoot' => true],
            'volumes'         => [
                ['name' => 'sock', 'hostPath' => ['path' => '/var/run/docker.sock']],
                ['name' => 'journaux', 'hostPath' => ['path' => '/var/log/appli']],
            ],
        ];
        $container = [
            'name'            => 'web',
            'securityContext' => ['privileged' => true, 'readOnlyRootFilesystem' => true],
            'resources'       => ['limits' => ['memory' => '256Mi', 'cpu' => '500m']],
            'volumeMounts'    => [['name' => 'sock'], ['name' => 'journaux']],
        ];
        $this->assertSame([M::PRIVILEGED, M::DOCKER_SOCKET, M::HOST_NETWORK], self::codes(M::fromPodSpec($podSpec, $container)));

        // runAsUser: 0 l'emporte sur runAsNonRoot du pod ; aucune limite, écriture permise.
        $root = M::fromPodSpec(['securityContext' => ['runAsNonRoot' => true]], ['securityContext' => ['runAsUser' => 0]]);
        $this->assertSame([M::ROOT_USER, M::NO_MEMORY_LIMIT, M::NO_CPU_LIMIT, M::WRITABLE_ROOTFS], self::codes($root));
        $this->assertNotContains(
            M::ROOT_USER,
            self::codes(M::fromPodSpec(['securityContext' => ['runAsUser' => 1000]], []))
        );
    }

    public function testKubernetesWorkloadCarriesItsFindings(): void
    {
        $workloads = KubernetesMapper::mapWorkloads(KubernetesMapper::KIND_DEPLOYMENT, [[
            'metadata' => ['name' => 'vitrine', 'namespace' => 'boutique'],
            'spec'     => ['replicas' => 1, 'template' => ['spec' => [
                'hostPID'    => true,
                'containers' => [['name' => 'web', 'image' => 'nginx:1.27']],
            ]]],
        ]], []);
        $this->assertCount(1, $workloads);
        $decoded = M::decode($workloads[0]['hardening']);
        $this->assertNotNull($decoded);
        $this->assertContains(M::HOST_PID, self::codes($decoded));
    }

    public function testComposeContainersAreMerged(): void
    {
        $containers = [];
        foreach (['un', 'deux'] as $suffix) {
            $containers[] = [
                'uid' => 'c-' . $suffix, 'name' => 'web-' . $suffix, 'project' => 'boutique', 'compose_service' => 'web',
                'image' => 'nginx', 'tag' => '1.27', 'digest' => '', 'state' => 'running', 'ports' => [],
                'networks' => [], 'volumes' => [], 'secrets' => [],
            ];
        }
        $services = PortainerMapper::groupContainers(3, $containers, [], [
            'c-un'   => [['code' => M::ADDED_CAPABILITIES, 'detail' => 'NET_RAW']],
            'c-deux' => [['code' => M::ADDED_CAPABILITIES, 'detail' => 'CHOWN, NET_RAW']],
        ]);
        $this->assertCount(1, $services);
        $this->assertSame(
            [['code' => M::ADDED_CAPABILITIES, 'detail' => 'CHOWN, NET_RAW']],
            M::decode($services[0]['hardening'] ?? null)
        );
        // Sans détail lu, l'analyse précédente est gardée : pas de clé.
        $this->assertArrayNotHasKey('hardening', PortainerMapper::groupContainers(3, $containers)[0]);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function paths(): iterable
    {
        yield 'racine' => ['/', true];
        yield 'etc' => ['/etc', true];
        yield 'sous-dossier de etc' => ['/etc/ssl/certs', true];
        yield 'var seul' => ['/var', true];
        yield 'sous-dossier de var' => ['/var/log/appli', false];
        yield 'données de docker' => ['/var/lib/docker/volumes', true];
        yield 'home seul' => ['/home/', true];
        yield 'dossier personnel' => ['/home/appli/donnees', false];
        yield 'dossier applicatif' => ['/srv/donnees', false];
        yield 'chemin relatif' => ['etc', false];
        yield 'barres multiples' => ['//proc//1', true];
    }

    #[DataProvider('paths')]
    public function testSensitivePaths(string $path, bool $sensitive): void
    {
        $this->assertSame($sensitive, M::isSensitivePath($path));
    }

    public function testRootUser(): void
    {
        foreach (['', 'root', '0', '0:0', 'ROOT:wheel'] as $user) {
            $this->assertTrue(M::isRootUser($user), $user);
        }
        foreach (['1000', 'appli', 'nobody:nogroup'] as $user) {
            $this->assertFalse(M::isRootUser($user), $user);
        }
    }

    public function testSeverityAndWorst(): void
    {
        $this->assertSame(M::SEVERITY_CRITICAL, M::severity(M::DOCKER_SOCKET));
        $this->assertSame(M::SEVERITY_LOW, M::severity('inconnu'));
        $this->assertSame(M::SEVERITY_HIGH, M::worst([M::NO_CPU_LIMIT, M::HOST_NETWORK, M::ROOT_USER]));
        $this->assertSame('', M::worst([]));
    }

    public function testEncodeDecode(): void
    {
        $this->assertNull(M::decode(null));
        $this->assertNull(M::decode(''));
        $this->assertNull(M::decode('pas du json'));
        $this->assertSame([], M::decode('[]'));
        // Code inconnu ignoré, détail limité, ordre d'affichage rétabli.
        $this->assertSame(
            [['code' => M::PRIVILEGED, 'detail' => ''], ['code' => M::ROOT_USER, 'detail' => 'root']],
            M::decode('[{"code":"root_user","detail":"root"},{"code":"pirate"},{"code":"privileged"}]')
        );
    }

    public function testExceptionsCoverUntilTheirEndDate(): void
    {
        $findings = [['code' => M::PRIVILEGED, 'detail' => ''], ['code' => M::ROOT_USER, 'detail' => '']];
        $rows = M::evaluate($findings, [M::PRIVILEGED => '2030-01-15', M::ROOT_USER => '2030-01-14'], '2030-01-15 08:00:00');
        $this->assertSame(['active', 'expired'], array_column($rows, 'exception'));
        $this->assertSame([M::SEVERITY_CRITICAL, M::SEVERITY_MEDIUM], array_column($rows, 'severity'));
        $this->assertSame([''], array_column(M::evaluate([$findings[0]], [], '2030-01-15'), 'exception'));
    }

    public function testExceptionEndDateIsBounded(): void
    {
        $today = '2030-01-15 10:00:00';
        $this->assertTrue(HardeningException::isValidEndDate('2030-01-15', $today));
        $this->assertTrue(HardeningException::isValidEndDate('2031-01-16', $today));
        $this->assertFalse(HardeningException::isValidEndDate('2031-01-17', $today));
        $this->assertFalse(HardeningException::isValidEndDate('2030-01-14', $today));
        $this->assertFalse(HardeningException::isValidEndDate('2030-02-30', $today));
        $this->assertFalse(HardeningException::isValidEndDate('15/02/2030', $today));
    }
}

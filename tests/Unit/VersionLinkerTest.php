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
use GlpiPlugin\Dockerinventory\Version\VersionLinker;
use GlpiPlugin\Dockerinventory\Wud\WudMapper;
use PHPUnit\Framework\TestCase;

/**
 * Rattachement des conteneurs du faux WUD aux services du faux Portainer (jeux fictifs).
 *
 * @phpstan-import-type WudContainer from WudMapper
 */
final class VersionLinkerTest extends TestCase
{
    /**
     * @return list<array{id: int, name: string, image: string, tag: string, digest: string}>
     */
    private static function services(): array
    {
        $data = json_decode((string) file_get_contents(dirname(__DIR__) . '/mock-portainer/fixture.json'), true);
        $services = [];
        foreach ($data['docker']['1']['services'] as $i => $service) {
            $mapped = PortainerMapper::mapService($service);
            if ($mapped !== null) {
                $services[] = ['id' => $i + 1, 'name' => $mapped['name'], 'image' => $mapped['image'],
                    'tag' => $mapped['tag'], 'digest' => $mapped['digest']];
            }
        }

        return $services;
    }

    /**
     * @return list<WudContainer>
     */
    private static function containers(): array
    {
        $data = json_decode((string) file_get_contents(dirname(__DIR__) . '/mock-wud/fixture.json'), true);
        $containers = [];
        foreach ($data['containers'] as $container) {
            $mapped = WudMapper::mapContainer($container);
            if ($mapped !== null) {
                $containers[] = $mapped;
            }
        }

        return $containers;
    }

    /**
     * @return array<string, int> Nom du service => identifiant.
     */
    private static function ids(): array
    {
        return array_column(self::services(), 'id', 'name');
    }

    public function testLinksFixtureContainersToServices(): void
    {
        $result = VersionLinker::link(self::containers(), self::services());
        $ids = self::ids();

        $web = $result['linked'][$ids['boutique_web']];
        $this->assertCount(3, $web);
        $methods = array_column($web, 'method', 'name');
        // Deux réplicas à l'empreinte du service, la troisième (ancienne image) par son étiquette.
        $this->assertSame('digest', $methods['boutique_web.1.t01']);
        $this->assertSame('label', $methods['boutique_web.3.t03']);
        $this->assertSame('label', $result['linked'][$ids['boutique_api']][0]['method']);
        $this->assertSame('image', $result['linked'][$ids['boutique_db']][0]['method']);
        $this->assertCount(2, $result['linked'][$ids['supervision_agent']]);
        $this->assertArrayHasKey($ids['boutique_cache'], $result['linked']);
        $this->assertArrayHasKey($ids['supervision_grafana'], $result['linked']);
        $this->assertArrayHasKey($ids['outil_isole'], $result['linked']);

        $reasons = array_column($result['unmatched'], 'reason', 'name');
        $this->assertSame([
            'outils-recette-adminer-1' => 'outside_swarm',
            'sauvegarde-nocturne'      => 'no_service',
        ], $reasons);
    }

    public function testOneRowPerServiceAndOutOfSyncReplicas(): void
    {
        $result = VersionLinker::link(self::containers(), self::services());
        $services = array_column(self::services(), null, 'name');

        $web = VersionLinker::summarize($services['boutique_web'], $result['linked'][$services['boutique_web']['id']]);
        $this->assertSame('2.4.1', $web['deployed_tag']);
        $this->assertSame('2.5.0', $web['latest_tag']);
        $this->assertSame('minor', $web['gap']);
        $this->assertSame(3, $web['container_count']);
        $this->assertSame(1, $web['is_out_of_sync']);
        $this->assertStringContainsString('2.4.1 (1f1e1d1c1b1a) x2', $web['deployed_versions']);
        $this->assertStringContainsString('2.4.0', $web['deployed_versions']);
        $this->assertSame('digest', $web['match_method']);

        $expected = [
            'boutique_api'        => 'patch',
            'boutique_db'         => 'major',
            'boutique_cache'      => 'digest',
            'supervision_agent'   => 'untrackable',
            'supervision_grafana' => 'none',
            'outil_isole'         => 'untrackable',
        ];
        foreach ($expected as $name => $gap) {
            $row = VersionLinker::summarize($services[$name], $result['linked'][$services[$name]['id']]);
            $this->assertSame($gap, $row['gap'], $name);
            $this->assertSame(0, $row['is_out_of_sync'], $name);
        }
    }

    public function testAmbiguousContainerIsNotLinked(): void
    {
        // Deux services du même nom (deux Swarm) et même image : aucun rattachement plutôt qu'un faux.
        $services = [
            ['id' => 1, 'name' => 'web', 'image' => 'nginx', 'tag' => '1.27.2', 'digest' => ''],
            ['id' => 2, 'name' => 'web', 'image' => 'nginx', 'tag' => '1.27.2', 'digest' => ''],
        ];
        $container = WudMapper::mapContainer([
            'id'     => 'c1',
            'name'   => 'web.1.abc',
            'image'  => ['registry' => ['name' => 'hub'], 'name' => 'library/nginx', 'tag' => ['value' => '1.27.2']],
            'labels' => [WudMapper::SERVICE_LABEL => 'web'],
        ]);
        $this->assertNotNull($container);

        $result = VersionLinker::link([$container], $services);

        $this->assertSame([], $result['linked']);
        $this->assertSame('ambiguous', $result['unmatched'][0]['reason'] ?? null);

        // Le tag départage : le service en 1.27.2 seul est choisi.
        $services[1]['tag'] = '1.26.0';
        $this->assertSame([1], array_keys(VersionLinker::link([$container], $services)['linked']));
    }

    public function testComposeContainersAreLinkedByContainerIdOrName(): void
    {
        $services = self::services();
        // Services de l'hôte autonome : conteneurs connus par leur identifiant et leur nom.
        $services[] = ['id' => 101, 'name' => 'adminer', 'image' => 'adminer', 'tag' => '4.8.1', 'digest' => '',
            'container_uids' => ['0a000000000000000000000000000000000000000000000000000000000000a0'],
            'container_names' => ['outils-recette-adminer-1']];
        $services[] = ['id' => 102, 'name' => 'sauvegarde-nocturne',
            'image' => 'registry.exemple.test:5000/outils/sauvegarde', 'tag' => '0.17.1', 'digest' => '',
            'container_uids' => [], 'container_names' => ['sauvegarde-nocturne']];

        $result = VersionLinker::link(self::containers(), $services);

        $this->assertSame('container', $result['linked'][101][0]['method'] ?? null);
        $this->assertSame('container', $result['linked'][102][0]['method'] ?? null);
        $this->assertSame([], $result['unmatched']);
        $row = VersionLinker::summarize($services[7], $result['linked'][101]);
        $this->assertSame('patch', $row['gap']);
        $this->assertSame('4.8.4', $row['latest_tag']);
        $this->assertSame('container', $row['match_method']);

        // Même nom de conteneur sur deux hôtes : départagé par l'image et le tag, sinon ambigu.
        $twin = $services[8];
        $twin['id'] = 103;
        $twin['tag'] = '0.16.0';
        $linked = VersionLinker::link(self::containers(), [...$services, $twin])['linked'];
        $this->assertArrayHasKey(102, $linked);
        $this->assertArrayNotHasKey(103, $linked);
        $twin['tag'] = '0.17.1';
        $result = VersionLinker::link(self::containers(), [...$services, $twin]);
        $this->assertArrayNotHasKey(102, $result['linked']);
        $this->assertSame(['sauvegarde-nocturne' => 'ambiguous'], array_column($result['unmatched'], 'reason', 'name'));
    }

    public function testShortDigest(): void
    {
        $this->assertSame('1f1e1d1c1b1a', VersionLinker::shortDigest('sha256:1f1e1d1c1b1a1918'));
        $this->assertSame('', VersionLinker::shortDigest(''));
    }
}

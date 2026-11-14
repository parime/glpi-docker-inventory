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

use GlpiPlugin\Dockerinventory\Portainer\PortainerClient;
use GlpiPlugin\Dockerinventory\Portainer\PortainerException;
use GlpiPlugin\Dockerinventory\Portainer\PortainerMapper;
use GlpiPlugin\Dockerinventory\Tests\PortainerFixture;
use PHPUnit\Framework\TestCase;

/**
 * Hôtes Docker autonomes (#25) : conteneurs, regroupement par projet Compose, hôte, avec le jeu de
 * données fictif du faux Portainer (environnement 2, docker-recette).
 */
final class ComposeMapperTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function host(): array
    {
        return PortainerFixture::load()['docker']['2'];
    }

    /**
     * @return array<string, array<string, mixed>> Services par nom.
     */
    private static function services(): array
    {
        $host = self::host();
        $images = PortainerMapper::imageIndex($host['images']);
        $containers = [];
        $policies = [];
        foreach ($host['containers'] as $raw) {
            $container = PortainerMapper::mapContainer($raw, $images);
            if ($container !== null) {
                $containers[] = $container;
                $policies[$container['uid']] = PortainerMapper::restartPolicy($host['inspect'][$container['uid']]);
            }
        }

        return array_column(PortainerMapper::groupContainers(2, $containers, $policies), null, 'name');
    }

    public function testComposeContainerIsMapped(): void
    {
        $host = self::host();
        $container = PortainerMapper::mapContainer(
            $host['containers'][2],
            PortainerMapper::imageIndex($host['images'])
        );

        $this->assertNotNull($container);
        $this->assertSame('wiki-interne-wiki-1', $container['name']);
        $this->assertSame('wiki-interne', $container['project']);
        $this->assertSame('wiki', $container['compose_service']);
        $this->assertSame('registry.exemple.test:5000/outils/wiki', $container['image']);
        $this->assertSame('3.2.0', $container['tag']);
        // Empreinte du dépôt, retrouvée dans les images de l'hôte.
        $this->assertSame('sha256:' . str_repeat('d', 64), $container['digest']);
        $this->assertSame('running', $container['state']);
        $this->assertSame(['127.0.0.1:3000:3000/tcp'], $container['ports']);
        $this->assertSame(['wiki-interne_default', 'proxy'], $container['networks']);
        $this->assertSame(['wiki-interne_pages'], $container['volumes']);
        // Secret Compose : son nom seulement ; un montage ordinaire n'est pas un secret.
        $this->assertSame(['wiki_db_password'], $container['secrets']);
    }

    public function testServicesAreGroupedByComposeProjectAndService(): void
    {
        $services = self::services();

        $this->assertSame(
            ['adminer', 'courrier', 'db', 'indexeur', 'sauvegarde-nocturne', 'wiki'],
            self::sorted(array_keys($services))
        );
        $indexer = $services['indexeur'];
        $this->assertSame('compose:2:wiki-interne:indexeur', $indexer['service_uid']);
        $this->assertSame(PortainerMapper::KIND_COMPOSE, $indexer['kind']);
        $this->assertSame('wiki-interne', $indexer['stack_name']);
        $this->assertSame(2, $indexer['replicas']);
        $this->assertSame(1, $indexer['running_replicas']);
        $this->assertSame('restarting, running', $indexer['state']);
        $this->assertSame('on-failure:5', $indexer['restart_policy']);
        $this->assertSame('wiki-interne-indexeur-1, wiki-interne-indexeur-2', $indexer['container_names']);
        $this->assertStringContainsString(
            '0f000000000000000000000000000000000000000000000000000000000000f1',
            $indexer['container_uids']
        );

        $adminer = $services['adminer'];
        $this->assertSame('outils-recette', $adminer['stack_name']);
        $this->assertSame('adminer', $adminer['image']);
        $this->assertSame('4.8.1', $adminer['tag']);
        // Ports IPv4 et IPv6 identiques : une seule ligne.
        $this->assertSame('8081:8080/tcp', $adminer['ports']);
        $this->assertSame('unless-stopped', $adminer['restart_policy']);

        $mail = $services['courrier'];
        $this->assertSame(0, $mail['running_replicas']);
        $this->assertSame('exited', $mail['state']);
        $this->assertSame('', $mail['digest']);
        $this->assertSame('outils-recette_courrier', $mail['volumes']);

        $backup = $services['sauvegarde-nocturne'];
        $this->assertSame(PortainerMapper::KIND_CONTAINER, $backup['kind']);
        $this->assertSame('container:2:sauvegarde-nocturne', $backup['service_uid']);
        $this->assertSame('', $backup['stack_name']);
        $this->assertSame('always', $backup['restart_policy']);
    }

    public function testEnvironmentValuesAndSecretContentsAreNeverKept(): void
    {
        $encoded = (string) json_encode(self::services());

        $this->assertStringNotContainsString('MOT_DE_PASSE_FICTIF', $encoded);
        $this->assertStringNotContainsString('ne-doit-jamais-etre-importe', $encoded);
        // Ni le chemin du fichier du secret sur l'hôte.
        $this->assertStringNotContainsString('/srv/wiki-interne/secrets', $encoded);
        $this->assertStringNotContainsString('/var/lib/docker/volumes', $encoded);
    }

    public function testSwarmTaskContainersAndContainersWithoutIdAreIgnored(): void
    {
        $this->assertNull(PortainerMapper::mapContainer(['Names' => ['/sans-id']]));
        $this->assertNull(PortainerMapper::mapContainer([
            'Id'     => 'c1',
            'Names'  => ['/boutique_web.1.t01'],
            'Labels' => ['com.docker.swarm.task.id' => 't01', 'com.docker.swarm.service.name' => 'boutique_web'],
        ]));
    }

    public function testImageReferenceFallsBackOnRepoTags(): void
    {
        $container = PortainerMapper::mapContainer(
            ['Id' => 'c2', 'Names' => ['/outil'], 'Image' => 'sha256:0123', 'ImageID' => 'sha256:img-outil'],
            ['sha256:img-outil' => ['tags' => ['outils/outil:2.0.1'], 'digests' => ['outils/outil@sha256:99']]]
        );

        $this->assertNotNull($container);
        $this->assertSame('outils/outil', $container['image']);
        $this->assertSame('2.0.1', $container['tag']);
        $this->assertSame('sha256:99', $container['digest']);
    }

    public function testRestartPolicy(): void
    {
        $this->assertSame('always', PortainerMapper::restartPolicy(['HostConfig' => ['RestartPolicy' => [
            'Name' => 'always',
        ]]]));
        $this->assertSame('on-failure:3', PortainerMapper::restartPolicy(['HostConfig' => ['RestartPolicy' => [
            'Name' => 'on-failure', 'MaximumRetryCount' => 3,
        ]]]));
        $this->assertSame('no', PortainerMapper::restartPolicy(['HostConfig' => ['RestartPolicy' => ['Name' => '']]]));
        $this->assertSame('', PortainerMapper::restartPolicy([]));
    }

    public function testHostIsMappedAsStandaloneNode(): void
    {
        $node = PortainerMapper::mapHost(self::host()['info'], 2, true, 'docker-recette-01.exemple.test');

        $this->assertSame('moteur-recette-0001', $node['node_uid']);
        $this->assertSame('docker-recette-01.exemple.test', $node['hostname']);
        $this->assertSame(PortainerMapper::ROLE_STANDALONE, $node['role']);
        $this->assertSame('ready', $node['state']);
        $this->assertSame('27.3.1', $node['engine_version']);
        $this->assertSame('endpoint:9', PortainerMapper::mapHost([], 9, false)['node_uid']);
        $this->assertSame('down', PortainerMapper::mapHost([], 9, false)['state']);
        $this->assertSame('docker-01.exemple.test', PortainerMapper::endpointHost('tcp://docker-01.exemple.test:2376'));
        $this->assertSame('', PortainerMapper::endpointHost('unix:///var/run/docker.sock'));
    }

    public function testClientReadsStandaloneEndpointsWithGetOnly(): void
    {
        $fixture = PortainerFixture::load();
        $paths = [];
        $client = new PortainerClient(
            'https://portainer.exemple.test',
            'ptr_fictive_cle',
            static function (string $url, array $query) use ($fixture, &$paths): string {
                $path = (string) parse_url($url, PHP_URL_PATH);
                $paths[] = [$path, $query];

                return PortainerFixture::respond($fixture, $path);
            }
        );

        $this->assertSame('docker-recette-01.exemple.test', $client->info(2)['Name']);
        $this->assertCount(7, $client->containers(2));
        $this->assertCount(6, $client->images(2));
        $detail = $client->container(2, '0a000000000000000000000000000000000000000000000000000000000000a0');
        $this->assertSame('unless-stopped', PortainerMapper::restartPolicy($detail));
        $this->assertSame(['all' => 1], $paths[1][1]);
        $this->assertSame('/api/endpoints/2/docker/containers/json', $paths[1][0]);

        // Un objet attendu, une liste reçue (ou l'inverse) : réponse inattendue.
        $list = new PortainerClient('https://portainer.exemple.test', 'k', static fn (): string => '[{"a": 1}]');
        $this->expectException(PortainerException::class);
        $list->info(2);
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private static function sorted(array $values): array
    {
        sort($values);

        return $values;
    }
}

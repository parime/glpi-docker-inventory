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

use GlpiPlugin\Dockerinventory\Harbor\HarborLinker;
use PHPUnit\Framework\TestCase;

/**
 * Rattachement d'une image déployée à un artefact Harbor et provenance de l'image.
 */
final class HarborLinkerTest extends TestCase
{
    /**
     * @return list<array{id: int, digest: string, child_digests: list<string>, tags: list<string>}>
     */
    private static function artifacts(): array
    {
        return [
            ['id' => 1, 'digest' => 'sha256:aaa', 'child_digests' => ['sha256:aaa-arm64'], 'tags' => ['3.2.0', '3.2']],
            ['id' => 2, 'digest' => 'sha256:bbb', 'child_digests' => [], 'tags' => []],
            ['id' => 3, 'digest' => 'sha256:ccc', 'child_digests' => [], 'tags' => ['3.1.0']],
        ];
    }

    public function testDigestFirst(): void
    {
        $artifacts = self::artifacts();
        $this->assertSame(['id' => 2, 'method' => 'digest'], HarborLinker::match('sha256:bbb', '3.2.0', $artifacts));
        $this->assertSame(['id' => 1, 'method' => 'digest'], HarborLinker::match('SHA256:AAA-ARM64', '', $artifacts));
    }

    public function testTagWhenDigestUnknown(): void
    {
        $this->assertSame(['id' => 3, 'method' => 'tag'], HarborLinker::match('', '3.1.0', self::artifacts()));
    }

    /**
     * Vrai Harbor 2.14 et Docker 29 : l'hôte garde l'empreinte de l'index multi-plateforme, Harbor
     * n'a que le manifeste d'une plateforme ; rattaché par le tag, pas « reconstruit ».
     */
    public function testDigestDiffersWhenDeployedDigestIsNotInHarbor(): void
    {
        $this->assertSame(
            ['id' => 1, 'method' => HarborLinker::METHOD_TAG_DIGEST_DIFFERS],
            HarborLinker::match('sha256:ancienne', '3.2.0', self::artifacts())
        );
    }

    public function testNoMatch(): void
    {
        $this->assertNull(HarborLinker::match('', '9.9.9', self::artifacts()));
        $this->assertNull(HarborLinker::match('sha256:zzz', '', self::artifacts()));
        $this->assertNull(HarborLinker::match('', '', []));
    }

    public function testProvenance(): void
    {
        $managed = ['registry.exemple.test:5000'];
        $this->assertSame('managed', HarborLinker::provenance('registry.exemple.test:5000/outils/wiki', $managed));
        $this->assertSame('unmanaged', HarborLinker::provenance('postgres', $managed));
        $this->assertSame('unmanaged', HarborLinker::provenance('ghcr.io/exemple/outil', $managed));
        $this->assertSame('managed', HarborLinker::provenance('library/postgres', ['docker.io']));
        $this->assertSame('unknown', HarborLinker::provenance('postgres', []));
        $this->assertSame('unknown', HarborLinker::provenance('', $managed));
        $this->assertSame('docker.io', HarborLinker::imageHost('grafana/grafana'));
    }

    public function testParseHosts(): void
    {
        $this->assertSame(
            ['registry.exemple.test:5000', 'harbor.exemple.test', 'docker.io'],
            HarborLinker::parseHosts(
                "Registry.Exemple.Test:5000\nhttps://harbor.exemple.test/projets, hub.docker.com ; index.docker.io\n"
                . "pas_un_hôte!\n\nregistry.exemple.test:5000"
            )
        );
        $this->assertSame([], HarborLinker::parseHosts(''));
    }
}

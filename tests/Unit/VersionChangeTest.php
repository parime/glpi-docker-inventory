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

use GlpiPlugin\Dockerinventory\Version\VersionChange;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * #10 : ce qui est un changement de version d'un service entre deux imports (journal A.8.32).
 */
final class VersionChangeTest extends TestCase
{
    /**
     * @return array<string, array{array{image: string, tag: string, digest: string},
     *     array{image: string, tag: string, digest: string}, string|null}>
     */
    public static function cases(): array
    {
        $a = 'sha256:' . str_repeat('a', 64);
        $b = 'sha256:' . str_repeat('b', 64);

        return [
            'identique'                => [self::v('nginx', '1.27.1', $a), self::v('nginx', '1.27.1', $a), null],
            'nouveau tag'              => [self::v('nginx', '1.27.1', $a), self::v('nginx', '1.27.2', $b),
                VersionChange::KIND_TAG],
            'autre image'              => [self::v('nginx', '1.27', $a), self::v('caddy', '2.8', $b),
                VersionChange::KIND_IMAGE],
            'même image, casse'        => [self::v('Nginx', '1.27', $a), self::v('nginx', '1.27', $a), null],
            'reconstruite (même tag)'  => [self::v('nginx', 'latest', $a), self::v('nginx', 'latest', $b),
                VersionChange::KIND_DIGEST],
            'empreinte apparue'        => [self::v('nginx', 'latest', ''), self::v('nginx', 'latest', $b), null],
            'empreinte disparue'       => [self::v('nginx', 'latest', $a), self::v('nginx', 'latest', ''), null],
            'première lecture'         => [self::v('', '', ''), self::v('nginx', '1.27', $a), null],
            'image illisible'          => [self::v('nginx', '1.27', $a), self::v('', '', ''), null],
        ];
    }

    /**
     * @param array{image: string, tag: string, digest: string} $old
     * @param array{image: string, tag: string, digest: string} $new
     */
    #[DataProvider('cases')]
    public function testDetect(array $old, array $new, ?string $expected): void
    {
        $this->assertSame($expected, VersionChange::detect($old, $new));
    }

    /**
     * @return array{image: string, tag: string, digest: string}
     */
    private static function v(string $image, string $tag, string $digest): array
    {
        return ['image' => $image, 'tag' => $tag, 'digest' => $digest];
    }
}

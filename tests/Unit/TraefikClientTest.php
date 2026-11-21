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

use GlpiPlugin\Dockerinventory\Traefik\TraefikClient;
use GlpiPlugin\Dockerinventory\Traefik\TraefikException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Client de l'API de Traefik contre le faux Traefik (tests/mock-traefik/fixture.json) servi en
 * mémoire : chemins fixes sous l'adresse enregistrée, authentification, erreurs sans secret.
 */
final class TraefikClientTest extends TestCase
{
    private const SECRET = 'traefik_secret_fictif';

    /**
     * Transport qui répond comme tests/mock-traefik/server.py et note les appels.
     *
     * @param list<array{string, array<string, scalar>, array<string, string>}> $calls
     */
    public static function fakeTraefik(array &$calls): \Closure
    {
        $data = json_decode((string) file_get_contents(dirname(__DIR__) . '/mock-traefik/fixture.json'), true);
        $routes = ['/api/version' => 'version', '/api/entrypoints' => 'entrypoints',
            '/api/http/routers' => 'routers', '/api/http/services' => 'services',
            '/api/http/middlewares' => 'middlewares', '/api/tcp/routers' => 'tcp_routers'];

        return static function (string $url, array $query, array $headers) use (&$calls, $data, $routes): string {
            $calls[] = [$url, $query, $headers];
            $path = (string) parse_url($url, PHP_URL_PATH);
            if (!isset($routes[$path])) {
                throw new \RuntimeException('HTTP 404 returned for "' . $url . '".');
            }

            return (string) json_encode($data[$routes[$path]]);
        };
    }

    public function testReadsOnlyFixedPathsUnderTheConfiguredAddressWithBasicAuth(): void
    {
        $calls = [];
        $client = new TraefikClient(
            'https://traefik.exemple.test/',
            'lecteur',
            self::SECRET,
            self::fakeTraefik($calls)
        );

        $this->assertSame('3.1.2', $client->version());
        $snapshot = $client->snapshot();
        $this->assertCount(7, $snapshot['routers']);
        $this->assertCount(1, $snapshot['tcp_routers']);
        $this->assertCount(3, $snapshot['entrypoints']);

        $urls = array_column($calls, 0);
        $this->assertSame([
            'https://traefik.exemple.test/api/version',
            'https://traefik.exemple.test/api/entrypoints',
            'https://traefik.exemple.test/api/http/routers',
            'https://traefik.exemple.test/api/http/services',
            'https://traefik.exemple.test/api/http/middlewares',
            'https://traefik.exemple.test/api/tcp/routers',
        ], $urls);
        $this->assertSame(['per_page' => TraefikClient::PER_PAGE], $calls[2][1]);
        foreach ($calls as $call) {
            $this->assertSame('Basic ' . base64_encode('lecteur:' . self::SECRET), $call[2]['Authorization']);
        }
    }

    public function testTokenWithoutUserIsSentAsBearer(): void
    {
        $calls = [];
        $client = new TraefikClient('https://traefik.exemple.test', '', self::SECRET, self::fakeTraefik($calls));
        $client->version();
        $this->assertSame('Bearer ' . self::SECRET, $calls[0][2]['Authorization']);

        $calls = [];
        (new TraefikClient('https://traefik.exemple.test', '', '', self::fakeTraefik($calls)))->version();
        $this->assertArrayNotHasKey('Authorization', $calls[0][2]);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function errorProvider(): iterable
    {
        yield '401' => ['HTTP 401 returned for "x".', TraefikException::UNAUTHORIZED];
        yield '403' => ['HTTP 403 returned for "x".', TraefikException::FORBIDDEN];
        yield '404' => ['HTTP 404 returned for "x".', TraefikException::NOT_FOUND];
        yield '500' => ['HTTP 500 returned for "x".', TraefikException::SERVER_ERROR];
        yield 'réseau privé' => ['Host "10.0.0.5" is blocked for "x".', TraefikException::PRIVATE_NETWORK];
        yield 'redirection' => ['HTTP 302 returned (redirect not followed)', TraefikException::UNREACHABLE];
    }

    #[DataProvider('errorProvider')]
    public function testErrorsBecomeStableCodesWithoutSecret(string $message, string $code): void
    {
        $transport = static function () use ($message): string {
            throw new \RuntimeException($message . ' ' . self::SECRET);
        };
        $client = new TraefikClient('https://traefik.exemple.test', 'lecteur', self::SECRET, $transport);

        try {
            $client->snapshot();
            $this->fail('Exception attendue');
        } catch (TraefikException $e) {
            $this->assertSame($code, $e->getMessage());
            $this->assertStringNotContainsString(self::SECRET, $e->getMessage());
        }
    }

    public function testUnexpectedAnswers(): void
    {
        $html = new TraefikClient('https://traefik.exemple.test', '', '', static fn (): string => '<html>');
        try {
            $html->version();
            $this->fail('Exception attendue');
        } catch (TraefikException $e) {
            $this->assertSame(TraefikException::UNEXPECTED, $e->getMessage());
        }

        $object = new TraefikClient('https://traefik.exemple.test', '', '', static fn (): string => '{"name": "x"}');
        $this->expectException(TraefikException::class);
        $this->expectExceptionMessage(TraefikException::UNEXPECTED);
        $object->listOf('/api/http/routers');
    }
}

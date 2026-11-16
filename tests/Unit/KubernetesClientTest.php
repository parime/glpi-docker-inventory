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

use GlpiPlugin\Dockerinventory\Kubernetes\KubernetesClient;
use GlpiPlugin\Dockerinventory\Kubernetes\KubernetesException;
use GlpiPlugin\Dockerinventory\Tests\KubernetesFixture;
use PHPUnit\Framework\TestCase;

/**
 * Client de l'API Kubernetes : lecture seule, jeton, pagination, erreurs sans le texte d'origine.
 */
final class KubernetesClientTest extends TestCase
{
    /**
     * @param array<string, int> $fail
     * @param list<string>|null $log
     * @param list<array<string, string>>|null $headers
     */
    private function client(
        array $fail = [],
        ?array &$log = null,
        ?array &$headers = null,
        int $pageSize = 2
    ): KubernetesClient {
        return new KubernetesClient(
            'https://kubernetes.exemple.test:6443/',
            KubernetesFixture::TOKEN,
            '',
            KubernetesFixture::transport(KubernetesFixture::load(), $fail, $log, $headers),
            $pageSize
        );
    }

    public function testVersionAndPaginatedList(): void
    {
        $log = [];
        $headers = [];
        $client = $this->client([], $log, $headers);
        $this->assertSame('v1.31.4', $client->version());
        $pods = $client->list('pods');
        $this->assertTrue($pods['complete']);
        $this->assertCount(7, $pods['items']);
        // 7 pods par pages de 2 : 4 requêtes, plus /version.
        $this->assertSame(['/version', '/api/v1/pods', '/api/v1/pods', '/api/v1/pods', '/api/v1/pods'], $log);
        $this->assertSame(5, $client->requests());
        foreach ($headers as $sent) {
            $this->assertSame('Bearer ' . KubernetesFixture::TOKEN, $sent['Authorization']);
            $this->assertSame('application/json', $sent['Accept']);
        }
    }

    public function testCanListOnlyForbiddenIsFalse(): void
    {
        $client = $this->client(['cronjobs' => 403, 'pods' => 500]);
        $this->assertTrue($client->canList('deployments'));
        $this->assertFalse($client->canList('cronjobs'));
        $this->expectException(KubernetesException::class);
        $this->expectExceptionMessage(KubernetesException::SERVER_ERROR);
        $client->canList('pods');
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function errors(): iterable
    {
        yield '401' => [401, KubernetesException::UNAUTHORIZED];
        yield '403' => [403, KubernetesException::FORBIDDEN];
        yield '404' => [404, KubernetesException::NOT_FOUND];
        yield '410' => [410, KubernetesException::EXPIRED];
        yield '429' => [429, KubernetesException::RATE_LIMITED];
        yield '503' => [503, KubernetesException::SERVER_ERROR];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('errors')]
    public function testErrorCodesNeverCarryTheOriginalText(int $status, string $code): void
    {
        try {
            $this->client(['deployments' => $status])->list('deployments');
            $this->fail('exception attendue');
        } catch (KubernetesException $e) {
            // « limit=500 » dans le message d'origine ne donne jamais une erreur 5xx.
            $this->assertSame($code, $e->getMessage());
        }
    }

    public function testWrongTokenAndTransportErrors(): void
    {
        $client = new KubernetesClient(
            'https://kubernetes.exemple.test:6443',
            'mauvais-jeton',
            '',
            KubernetesFixture::transport(KubernetesFixture::load())
        );
        try {
            $client->version();
            $this->fail('exception attendue');
        } catch (KubernetesException $e) {
            $this->assertSame(KubernetesException::UNAUTHORIZED, $e->getMessage());
        }
        $this->assertSame(
            KubernetesException::CERTIFICATE,
            KubernetesException::fromThrowable(
                new \RuntimeException('SSL certificate problem: self-signed certificate')
            )->getMessage()
        );
        $this->assertSame(
            KubernetesException::UNREACHABLE,
            KubernetesException::fromThrowable(new \RuntimeException('Could not resolve host: k8s?limit=500'))
                ->getMessage()
        );
        $this->assertSame(
            KubernetesException::FORBIDDEN,
            KubernetesException::fromThrowable(new \RuntimeException(
                'Client error: `GET https://k8s/api/v1/pods?limit=500` resulted in a `403 Forbidden` response'
            ))->getMessage()
        );
    }

    public function testUnexpectedBodyAndIncompleteList(): void
    {
        $client = new KubernetesClient(
            'https://kubernetes.exemple.test:6443',
            KubernetesFixture::TOKEN,
            '',
            static fn (): string => '<html>portail</html>'
        );
        try {
            $client->list('nodes');
            $this->fail('exception attendue');
        } catch (KubernetesException $e) {
            $this->assertSame(KubernetesException::UNEXPECTED, $e->getMessage());
        }

        $endless = new KubernetesClient(
            'https://kubernetes.exemple.test:6443',
            KubernetesFixture::TOKEN,
            '',
            static fn (): string => (string) json_encode(
                ['metadata' => ['continue' => 'encore'], 'items' => [['a' => 1]]]
            )
        );
        $page = $endless->list('pods');
        $this->assertFalse($page['complete']);
        $this->assertCount(KubernetesClient::MAX_PAGES, $page['items']);
    }

    public function testCaFileIsWrittenOnceFromThePem(): void
    {
        $this->assertNull(KubernetesClient::caFile('  '));
        $pem = "-----BEGIN CERTIFICATE-----\nZmljdGlm\n-----END CERTIFICATE-----";
        $file = KubernetesClient::caFile($pem);
        $this->assertNotNull($file);
        $this->assertFileExists($file);
        $this->assertSame($pem . "\n", file_get_contents($file));
        $this->assertSame($file, KubernetesClient::caFile($pem));
        unlink($file);
    }
}

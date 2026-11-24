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

use GlpiPlugin\Dockerinventory\Tls\CertificateReader;
use PHPUnit\Framework\TestCase;

/**
 * Lecture du certificat d'une route HTTPS (#78) : certificats auto-signés générés pendant le test
 * (aucune clé versionnée), vraie poignée de main TLS contre un serveur local lancé pour le test,
 * hôtes refusés sans connexion, erreurs par codes stables.
 */
final class CertificateReaderTest extends TestCase
{
    /**
     * Certificat auto-signé fictif et sa clé, au format PEM.
     *
     * @return array{0: string, 1: string}
     */
    private static function selfSigned(string $commonName, int $days): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);
        $csr = openssl_csr_new(['commonName' => $commonName, 'organizationName' => 'Exemple fictif'], $key);
        self::assertNotFalse($csr);
        self::assertNotTrue($csr);
        $certificate = openssl_csr_sign($csr, null, $key, $days, ['digest_alg' => 'sha256'], 4242);
        self::assertNotFalse($certificate);
        $pem = '';
        $keyPem = '';
        self::assertTrue(openssl_x509_export($certificate, $pem));
        self::assertTrue(openssl_pkey_export($key, $keyPem));

        return [$pem, $keyPem];
    }

    public function testParseReadsIssuerDatesAndSelfSigned(): void
    {
        [$pem] = self::selfSigned('app.exemple.test', 20);

        $parsed = CertificateReader::parse($pem);

        $this->assertNotNull($parsed);
        $this->assertSame('app.exemple.test (Exemple fictif)', $parsed['subject']);
        $this->assertSame($parsed['subject'], $parsed['issuer']);
        $this->assertTrue($parsed['self_signed']);
        $this->assertSame('1092', strtoupper($parsed['serial']));
        $days = ($parsed['not_after'] - time()) / 86400;
        $this->assertGreaterThan(19, $days);
        $this->assertLessThanOrEqual(20, $days);
        $this->assertNull(CertificateReader::parse('pas un certificat'));
    }

    public function testInvalidHostsAreRefusedWithoutConnection(): void
    {
        $calls = 0;
        $reader = new CertificateReader(static function () use (&$calls): ?string {
            $calls++;

            return null;
        });
        foreach (['', '*.exemple.test', 'a b.exemple.test', 'exemple.test:443', '-x.exemple.test', 'x/y'] as $host) {
            $this->assertSame(CertificateReader::ERROR_HOST, $reader->read($host, 443)['error'], $host);
        }
        $this->assertSame(CertificateReader::ERROR_HOST, $reader->read('app.exemple.test', 0)['error']);
        $this->assertSame(0, $calls);
        $this->assertTrue(CertificateReader::isValidHost('app.exemple.test'));
        $this->assertTrue(CertificateReader::isValidHost('192.0.2.10'));
        $this->assertTrue(CertificateReader::isValidHost('2001:db8::1'));
    }

    public function testTransportFailuresGiveStableCodes(): void
    {
        $cases = [
            CertificateReader::ERROR_CONNECT        => null,
            CertificateReader::ERROR_NO_CERTIFICATE => '',
            CertificateReader::ERROR_PARSE          => "-----BEGIN CERTIFICATE-----\nAAAA\n-----END CERTIFICATE-----\n",
        ];
        foreach ($cases as $code => $answer) {
            $reader = new CertificateReader(static fn (): ?string => $answer);
            $result = $reader->read('app.exemple.test', 443);
            $this->assertFalse($result['ok']);
            $this->assertSame($code, $result['error']);
            $this->assertNull($result['certificate']);
        }
        [$pem] = self::selfSigned('ok.exemple.test', 5);
        $seen = [];
        $reader = new CertificateReader(static function (string $host, int $port) use ($pem, &$seen): string {
            $seen[] = $host . ':' . $port;

            return $pem;
        });
        $result = $reader->read('ok.exemple.test', 443);
        $this->assertTrue($result['ok']);
        $this->assertSame(['ok.exemple.test:443'], $seen);
    }

    /**
     * Vraie poignée de main TLS : un serveur local présente un certificat auto-signé généré pour le
     * test ; le lecteur en relève le sujet et la date d'expiration, sans le valider.
     */
    public function testRealHandshakeReadsTheSelfSignedCertificate(): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open indisponible.');
        }
        [$pem, $keyPem] = self::selfSigned('local.exemple.test', 3);
        $file = tempnam(sys_get_temp_dir(), 'dkcert');
        $this->assertIsString($file);
        file_put_contents($file, $pem . $keyPem);
        $server = <<<'PHP'
            $context = stream_context_create(['ssl' => ['local_cert' => $argv[1], 'verify_peer' => false]]);
            $flags = STREAM_SERVER_BIND | STREAM_SERVER_LISTEN;
            $socket = stream_socket_server('tls://127.0.0.1:0', $errno, $errstr, $flags, $context);
            if ($socket === false) { fwrite(STDOUT, "0\n"); exit(1); }
            $name = (string) stream_socket_get_name($socket, false);
            fwrite(STDOUT, substr($name, strrpos($name, ':') + 1) . "\n");
            fflush(STDOUT);
            $client = @stream_socket_accept($socket, 10);
            if ($client !== false) { fclose($client); }
            PHP;
        $process = proc_open(
            [PHP_BINARY, '-r', $server, $file],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $this->assertIsResource($process);
        try {
            $port = (int) trim((string) fgets($pipes[1]));
            // Pas de lecture de la sortie d'erreur ici : elle attendrait la fin du serveur.
            $this->assertGreaterThan(0, $port);

            $result = (new CertificateReader(null, 5.0))->read('127.0.0.1', $port);

            $this->assertTrue($result['ok'], $result['error']);
            $this->assertNotNull($result['certificate']);
            $this->assertSame('local.exemple.test (Exemple fictif)', $result['certificate']['subject']);
            $this->assertTrue($result['certificate']['self_signed']);
            $this->assertGreaterThan(time() + 2 * 86400, $result['certificate']['not_after']);
        } finally {
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            proc_terminate($process);
            proc_close($process);
            unlink($file);
        }

        // Plus personne n'écoute sur ce port : connexion impossible, code stable.
        $closed = (new CertificateReader(null, 1.0))->read('127.0.0.1', $port);
        $this->assertSame(CertificateReader::ERROR_CONNECT, $closed['error']);
    }
}

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

namespace GlpiPlugin\Dockerinventory\Tls;

/**
 * Lecture du certificat présenté par une route d'entrée HTTPS (#78) : poignée de main TLS sur
 * l'hôte et le port de la route, avec l'indication du nom (SNI), puis fermeture. Seul le
 * certificat public, que le serveur envoie à tout client (message Certificate, RFC 8446, section
 * 4.4.2), est lu : aucune requête HTTP, aucun identifiant, aucune clé privée.
 *
 * Pourquoi pas l'API de Traefik ni les secrets Kubernetes :
 * - l'API de Traefik (https://doc.traefik.io/traefik/operations/api/) expose routeurs, services,
 *   intergiciels et points d'entrée, mais aucun point d'accès ne donne les certificats ni leur date
 *   d'expiration ;
 * - un secret Kubernetes de type kubernetes.io/tls
 *   (https://kubernetes.io/docs/concepts/configuration/secret/#tls-secrets) contient la clé
 *   privée (tls.key) à côté du certificat : le lire demanderait le droit « get secrets », contraire
 *   au principe du plugin (droits minimaux, aucun secret lu).
 *
 * Le certificat est lu sans être validé (autorité, nom) : il s'agit de relever émetteur et dates,
 * y compris d'un certificat auto-signé ou expiré. Transport injectable (tests).
 *
 * @phpstan-type Parsed array{subject: string, issuer: string, sans: list<string>, not_before: int,
 *     not_after: int, self_signed: bool, serial: string}
 * @phpstan-type Result array{ok: bool, error: string, certificate: Parsed|null}
 */
final class CertificateReader
{
    public const ERROR_HOST = 'invalid_host';
    public const ERROR_CONNECT = 'connect';
    public const ERROR_NO_CERTIFICATE = 'no_certificate';
    public const ERROR_PARSE = 'parse';

    public const ERRORS = [self::ERROR_HOST, self::ERROR_CONNECT, self::ERROR_NO_CERTIFICATE, self::ERROR_PARSE];

    /** Délai de connexion et de poignée de main, en secondes. */
    public const TIMEOUT = 5.0;

    /** @var \Closure(string, int, float): ?string Hôte, port, délai => certificat PEM ou null. */
    private \Closure $fetcher;

    /**
     * @param (callable(string, int, float): ?string)|null $fetcher Transport (tests) ; par défaut, TLS.
     */
    public function __construct(?callable $fetcher = null, private float $timeout = self::TIMEOUT)
    {
        $this->fetcher = $fetcher === null
            ? \Closure::fromCallable([self::class, 'fetch'])
            : \Closure::fromCallable($fetcher);
    }

    /**
     * Certificat présenté par host:port.
     *
     * @return Result
     */
    public function read(string $host, int $port): array
    {
        if (!self::isValidHost($host) || $port < 1 || $port > 65535) {
            return ['ok' => false, 'error' => self::ERROR_HOST, 'certificate' => null];
        }
        $pem = ($this->fetcher)($host, $port, $this->timeout);
        if ($pem === null) {
            return ['ok' => false, 'error' => self::ERROR_CONNECT, 'certificate' => null];
        }
        if ($pem === '') {
            return ['ok' => false, 'error' => self::ERROR_NO_CERTIFICATE, 'certificate' => null];
        }
        $parsed = self::parse($pem);

        return $parsed === null
            ? ['ok' => false, 'error' => self::ERROR_PARSE, 'certificate' => null]
            : ['ok' => true, 'error' => '', 'certificate' => $parsed];
    }

    /**
     * Nom d'hôte DNS (sans joker ni port) ou adresse IP : seuls ces hôtes sont contactés.
     */
    public static function isValidHost(string $host): bool
    {
        if ($host === '' || strlen($host) > 253) {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        foreach (explode('.', $host) as $label) {
            if (preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i', $label) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * Valeurs lues dans un certificat PEM, null s'il est illisible.
     *
     * @return Parsed|null
     */
    public static function parse(string $pem): ?array
    {
        $data = @openssl_x509_parse($pem);
        if (!is_array($data) || !isset($data['validTo_time_t'], $data['validFrom_time_t'])) {
            return null;
        }
        $subject = is_array($data['subject'] ?? null) ? $data['subject'] : [];
        $issuer = is_array($data['issuer'] ?? null) ? $data['issuer'] : [];
        $sans = [];
        $extensions = is_array($data['extensions'] ?? null) ? $data['extensions'] : [];
        foreach (explode(',', (string) ($extensions['subjectAltName'] ?? '')) as $entry) {
            $entry = trim($entry);
            if (str_starts_with($entry, 'DNS:')) {
                $sans[] = mb_substr(substr($entry, 4), 0, 253);
            } elseif (str_starts_with($entry, 'IP Address:')) {
                $sans[] = mb_substr(substr($entry, 11), 0, 64);
            }
        }

        return [
            'subject'     => self::name($subject),
            'issuer'      => self::name($issuer),
            'sans'        => array_slice(array_values(array_unique($sans)), 0, 50),
            'not_before'  => (int) $data['validFrom_time_t'],
            'not_after'   => (int) $data['validTo_time_t'],
            'self_signed' => $subject !== [] && $subject == $issuer,
            'serial'      => mb_substr((string) ($data['serialNumberHex'] ?? ''), 0, 128),
        ];
    }

    /**
     * Nom distinctif lisible : nom commun, organisation entre parenthèses.
     *
     * @param array<mixed> $dn
     */
    public static function name(array $dn): string
    {
        $value = static function (mixed $part): string {
            return is_array($part) ? implode(', ', array_map('strval', $part)) : (string) $part;
        };
        $cn = $value($dn['CN'] ?? '');
        $org = $value($dn['O'] ?? '');
        $name = match (true) {
            $cn !== '' && $org !== '' && $org !== $cn => $cn . ' (' . $org . ')',
            $cn !== ''                                => $cn,
            default                                   => $org,
        };

        return mb_substr($name, 0, 255);
    }

    /**
     * Poignée de main TLS et certificat du serveur (PEM) ; null si la connexion échoue, '' si
     * aucun certificat n'est présenté. Aucune donnée applicative n'est envoyée.
     */
    public static function fetch(string $host, int $port, float $timeout): ?string
    {
        $context = stream_context_create(['ssl' => [
            'capture_peer_cert' => true,
            // Relevé, pas validation : un certificat auto-signé ou expiré doit être lu.
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true,
            'SNI_enabled'       => true,
            'peer_name'         => $host,
        ]]);
        $address = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? '[' . $host . ']' : $host;
        $errno = 0;
        $errstr = '';
        $stream = @stream_socket_client(
            'tls://' . $address . ':' . $port,
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if ($stream === false) {
            return null;
        }
        $params = stream_context_get_params($stream);
        fclose($stream);
        $certificate = $params['options']['ssl']['peer_certificate'] ?? null;
        if (!$certificate instanceof \OpenSSLCertificate) {
            return '';
        }
        $pem = '';

        return openssl_x509_export($certificate, $pem) ? $pem : '';
    }
}

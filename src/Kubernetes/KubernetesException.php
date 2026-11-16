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

namespace GlpiPlugin\Dockerinventory\Kubernetes;

/**
 * Échec d'un appel à l'API d'un cluster Kubernetes. Le message est un code stable (constantes
 * ci-dessous), jamais le texte de l'exception d'origine : il ne peut contenir ni le jeton du compte
 * de service ni une réponse arbitraire du serveur.
 */
final class KubernetesException extends \RuntimeException
{
    public const UNAUTHORIZED = 'k8s_unauthorized';
    public const FORBIDDEN = 'k8s_forbidden';
    public const NOT_FOUND = 'k8s_not_found';
    /** Jeton de pagination expiré (410) : la liste sera relue en entier à l'import suivant. */
    public const EXPIRED = 'k8s_expired';
    public const RATE_LIMITED = 'k8s_rate_limited';
    public const SERVER_ERROR = 'k8s_server_error';
    public const PRIVATE_NETWORK = 'k8s_private_network';
    public const CERTIFICATE = 'k8s_certificate';
    public const UNEXPECTED = 'k8s_unexpected';
    public const UNREACHABLE = 'k8s_unreachable';

    public static function fromThrowable(\Throwable $e): self
    {
        $message = $e->getMessage();
        $status = self::statusOf($e);
        $code = match (true) {
            // GLPI 12 : appel vers un réseau privé refusé (voir KubernetesContext).
            str_contains($message, 'is blocked for') => self::PRIVATE_NETWORK,
            $status === 401 => self::UNAUTHORIZED,
            $status === 403 => self::FORBIDDEN,
            $status === 404 => self::NOT_FOUND,
            $status === 410 => self::EXPIRED,
            $status === 429 => self::RATE_LIMITED,
            $status >= 500 && $status < 600 => self::SERVER_ERROR,
            preg_match('/certificate|SSL|TLS|self[- ]signed|issuer/i', $message) === 1 => self::CERTIFICATE,
            default => self::UNREACHABLE,
        };

        return new self($code);
    }

    /**
     * Code HTTP de la réponse en erreur : celui de la réponse elle-même (Symfony, Guzzle), sinon lu
     * dans le message du client (« HTTP 403 returned for », « resulted in a `403 Forbidden` »), jamais
     * un nombre pris au hasard dans l'adresse (« limit=500 »). Zéro sans réponse.
     */
    private static function statusOf(\Throwable $e): int
    {
        if (method_exists($e, 'getResponse')) {
            try {
                $response = $e->getResponse();
                if (is_object($response) && method_exists($response, 'getStatusCode')) {
                    return (int) $response->getStatusCode();
                }
            } catch (\Throwable) {
                // Réponse illisible : le message est lu ci-dessous.
            }
        }
        $message = $e->getMessage();
        if (preg_match('/HTTP\/?[\d.]* (\d{3}) returned|resulted in a `(\d{3})/', $message, $m) === 1) {
            return (int) ($m[1] !== '' ? $m[1] : $m[2]);
        }

        return 0;
    }
}

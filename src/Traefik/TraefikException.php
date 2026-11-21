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

namespace GlpiPlugin\Dockerinventory\Traefik;

/**
 * Échec d'une lecture de l'API de Traefik : le message est un code (libellé dans
 * Connection::errorLabels()), jamais le texte brut de la réponse ni le secret.
 */
final class TraefikException extends \RuntimeException
{
    public const UNAUTHORIZED = 'traefik_unauthorized';
    public const FORBIDDEN = 'traefik_forbidden';
    public const NOT_FOUND = 'traefik_not_found';
    public const SERVER_ERROR = 'traefik_server_error';
    public const PRIVATE_NETWORK = 'traefik_private_network';
    public const UNEXPECTED = 'traefik_unexpected';
    public const UNREACHABLE = 'traefik_unreachable';

    public static function fromThrowable(\Throwable $e): self
    {
        $message = $e->getMessage();
        $code = match (true) {
            // GLPI 12 : appel vers un réseau privé refusé (voir TraefikContext).
            str_contains($message, 'is blocked for') => self::PRIVATE_NETWORK,
            preg_match('/\b401\b/', $message) === 1 => self::UNAUTHORIZED,
            preg_match('/\b403\b/', $message) === 1 => self::FORBIDDEN,
            preg_match('/\b404\b/', $message) === 1 => self::NOT_FOUND,
            preg_match('/\b5\d\d\b/', $message) === 1 => self::SERVER_ERROR,
            default => self::UNREACHABLE,
        };

        return new self($code);
    }
}

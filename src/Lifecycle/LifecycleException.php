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

namespace GlpiPlugin\Dockerinventory\Lifecycle;

/**
 * Échec de la lecture des cycles de vie d'un produit. Le message est un code stable (constantes
 * ci-dessous), jamais le texte de l'exception d'origine ni une réponse arbitraire du serveur.
 */
final class LifecycleException extends \RuntimeException
{
    /** Produit inconnu de la source (404) : correspondance à corriger, résultat gardé un jour. */
    public const NOT_FOUND = 'lifecycle_not_found';
    public const RATE_LIMITED = 'lifecycle_rate_limited';
    public const SERVER_ERROR = 'lifecycle_server_error';
    public const PRIVATE_NETWORK = 'lifecycle_private_network';
    public const UNEXPECTED = 'lifecycle_unexpected';
    public const UNREACHABLE = 'lifecycle_unreachable';

    /** Pas une erreur d'appel : source non interrogée (limite atteinte ou injoignable plus tôt). */
    public const SKIPPED = 'lifecycle_skipped';

    public static function fromThrowable(\Throwable $e): self
    {
        $message = $e->getMessage();
        $code = match (true) {
            // GLPI 12 : appel vers un réseau privé refusé (voir LifecycleContext).
            str_contains($message, 'is blocked for') => self::PRIVATE_NETWORK,
            preg_match('/\b404\b/', $message) === 1 => self::NOT_FOUND,
            preg_match('/\b429\b/', $message) === 1 => self::RATE_LIMITED,
            preg_match('/\b5\d\d\b/', $message) === 1 => self::SERVER_ERROR,
            default => self::UNREACHABLE,
        };

        return new self($code);
    }
}

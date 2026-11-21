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

namespace GlpiPlugin\Dockerinventory\Scan;

/**
 * Rapport d'analyse refusé (#73) : code stable, jamais le contenu du fichier.
 */
final class ScanReportException extends \RuntimeException
{
    public const EMPTY = 'empty';
    public const TOO_LARGE = 'too_large';
    public const INVALID_JSON = 'invalid_json';
    public const TOO_DEEP = 'too_deep';
    public const UNKNOWN_FORMAT = 'unknown_format';
    public const NO_IMAGE = 'no_image';
    public const TOO_MANY = 'too_many';

    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}

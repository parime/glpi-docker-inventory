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

namespace GlpiPlugin\Dockerinventory;

use CommonDBTM;
use GlpiPlugin\Dockerinventory\Hardening\HardeningMapper;
use Session;

/**
 * Exception justifiée et datée sur un écart de configuration d'un service (#71), comme une
 * dérogation de GRC Manager : la personne explique pourquoi l'écart est accepté et jusqu'à quand.
 * Une exception expirée ne couvre plus l'écart. Rien n'est jamais corrigé sur la plateforme.
 *
 * Une exception par service et par écart ; l'entité est celle du service. Droit propre au
 * plugin : enregistrer (CREATE) et supprimer (PURGE), en plus de la lecture des services.
 */
class HardeningException extends CommonDBTM
{
    public const RIGHTNAME = 'plugin_dockerinventory_hardeningexception';

    /** Durée maximale d'une exception, en jours (à renouveler ensuite). */
    public const MAX_DAYS = 366;

    public static $rightname = self::RIGHTNAME;

    public $dohistory = true;

    public static function getTypeName($nb = 0)
    {
        return $nb > 1
            ? __('Exceptions de configuration', 'dockerinventory')
            : __('Exception de configuration', 'dockerinventory');
    }

    public static function getIcon()
    {
        return 'ti ti-shield-half';
    }

    public static function canView(): bool
    {
        return Service::canView();
    }

    public static function canCreate(): bool
    {
        return Service::canView() && (bool) Session::haveRight(self::RIGHTNAME, CREATE);
    }

    public static function canUpdate(): bool
    {
        return self::canCreate();
    }

    public static function canDelete(): bool
    {
        return false;
    }

    public static function canPurge(): bool
    {
        return Service::canView() && (bool) Session::haveRight(self::RIGHTNAME, PURGE);
    }

    /**
     * @return array<int, string>
     */
    public function getRights($interface = 'central')
    {
        return [
            CREATE => __('Enregistrer une exception', 'dockerinventory'),
            PURGE  => __('Supprimer une exception', 'dockerinventory'),
        ];
    }

    /**
     * Date de fin acceptée : AAAA-MM-JJ valide, aujourd'hui au plus tôt, MAX_DAYS jours au plus tard.
     */
    public static function isValidEndDate(string $date, string $today): bool
    {
        $end = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($today, 0, 10));
        if ($end === false || $start === false || $end->format('Y-m-d') !== $date) {
            return false;
        }

        return $end >= $start && $end <= $start->modify('+' . self::MAX_DAYS . ' days');
    }

    /**
     * Enregistre (ou renouvelle) l'exception d'un écart d'un service que la personne voit.
     *
     * @return string '' si l'exception est enregistrée, sinon le motif du refus (traduit).
     */
    public static function record(int $serviceId, string $code, string $justification, string $endDate): string
    {
        $service = new Service();
        if (!self::canCreate() || !$service->can($serviceId, READ)) {
            return __('Vous n’avez pas le droit d’enregistrer cette exception.', 'dockerinventory');
        }
        if (!isset(HardeningMapper::RULES[$code])) {
            return __('Écart inconnu.', 'dockerinventory');
        }
        $justification = trim($justification);
        if ($justification === '') {
            return __('La justification est obligatoire.', 'dockerinventory');
        }
        if (!self::isValidEndDate($endDate, (string) $_SESSION['glpi_currenttime'])) {
            return sprintf(
                __('La date de fin doit être comprise entre aujourd’hui et dans %d jours.', 'dockerinventory'),
                self::MAX_DAYS
            );
        }
        $values = [
            'justification' => mb_substr($justification, 0, 2000),
            'date_end'      => $endDate,
            'users_id'      => (int) Session::getLoginUserID(),
        ];
        $exception = new self();
        if ($exception->getFromDBByCrit(['plugin_dockerinventory_services_id' => $serviceId, 'code' => $code])) {
            $ok = $exception->update(['id' => $exception->getID()] + $values);
        } else {
            $ok = $exception->add($values + [
                'plugin_dockerinventory_services_id' => $serviceId,
                'code'                               => $code,
                'entities_id'                        => (int) $service->fields['entities_id'],
            ]) !== false;
        }

        return $ok ? '' : __('L’exception n’a pas pu être enregistrée.', 'dockerinventory');
    }

    /**
     * Supprime une exception d'un service que la personne voit.
     */
    public static function remove(int $id): bool
    {
        $exception = new self();
        if (!self::canPurge() || !$exception->getFromDB($id)) {
            return false;
        }
        $service = new Service();
        if (!$service->can((int) $exception->fields['plugin_dockerinventory_services_id'], READ)) {
            return false;
        }

        return $exception->delete(['id' => $id], true);
    }

    /**
     * Exceptions des services donnés, par service puis par écart.
     *
     * @param list<int> $serviceIds
     * @return array<int, array<string, array{id: int, justification: string, date_end: string,
     *     users_id: int, user: string, date_mod: string}>>
     */
    public static function forServices(array $serviceIds): array
    {
        global $DB;

        $serviceIds = array_values(array_filter(
            array_map('intval', $serviceIds),
            static fn (int $id): bool => $id > 0
        ));
        if ($serviceIds === []) {
            return [];
        }
        $byService = [];
        foreach (
            $DB->request([
                'FROM'  => self::getTable(),
                'WHERE' => ['plugin_dockerinventory_services_id' => $serviceIds],
            ]) as $row
        ) {
            $byService[(int) $row['plugin_dockerinventory_services_id']][(string) $row['code']] = [
                'id'            => (int) $row['id'],
                'justification' => (string) $row['justification'],
                'date_end'      => substr((string) $row['date_end'], 0, 10),
                'users_id'      => (int) $row['users_id'],
                'user'          => (int) $row['users_id'] > 0 ? getUserName((int) $row['users_id']) : '',
                'date_mod'      => (string) ($row['date_mod'] ?? ''),
            ];
        }

        return $byService;
    }
}

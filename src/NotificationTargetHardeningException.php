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

use Dropdown;
use Entity;
use NotificationTarget;
use User;

/**
 * Rappel avant l'expiration d'une exception de configuration (#99) : envoyé par la tâche
 * automatique `hardeningexpiry` (HardeningException::remindExpiring()) à l'auteur de l'exception
 * et aux cibles choisies dans Configuration > Notifications (profils, groupes, administrateurs).
 *
 * Un destinataire ne reçoit le contenu que s'il peut lire les services dans l'entité de
 * l'exception (HardeningException::canReceive()), quelle que soit la cible.
 *
 * @extends NotificationTarget<HardeningException>
 */
class NotificationTargetHardeningException extends NotificationTarget
{
    // Hors de la plage des cibles du cœur : sinon GLPI n'appelle jamais addSpecificTargets().
    public const TARGET_AUTHOR = 1099;

    public function getEvents()
    {
        return [
            HardeningException::EVENT_EXPIRING => __(
                'Exception de configuration qui expire bientôt',
                'dockerinventory'
            ),
        ];
    }

    public function addAdditionalTargets($event = '')
    {
        $this->addTarget(self::TARGET_AUTHOR, __('Auteur de l’exception', 'dockerinventory'));
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $options
     */
    public function addSpecificTargets($data, $options)
    {
        if ((int) ($data['items_id'] ?? 0) !== self::TARGET_AUTHOR) {
            return;
        }
        $usersId = (int) ($this->obj->fields['users_id'] ?? 0);
        $user = new User();
        if ($usersId > 0 && $user->getFromDB($usersId)) {
            $this->addToRecipientsList([
                'users_id' => $usersId,
                'language' => (string) ($user->fields['language'] ?: ($GLOBALS['CFG_GLPI']['language'] ?? '')),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $infos
     */
    public function validateSendTo($event, array $infos, $notify_me = false, $emitter = null)
    {
        if (
            !$this->obj instanceof HardeningException
            || !HardeningException::canReceive((int) ($infos['users_id'] ?? 0), $this->obj)
        ) {
            return false;
        }

        return parent::validateSendTo($event, $infos, $notify_me, $emitter);
    }

    public function addDataForTemplate($event, $options = [])
    {
        $events = $this->getAllEvents();
        /** @var HardeningException $exception */
        $exception = $this->obj;
        $fields = $exception->fields;
        $serviceId = (int) ($fields['plugin_dockerinventory_services_id'] ?? 0);
        $service = new Service();
        $hasService = $serviceId > 0 && $service->getFromDB($serviceId);
        $code = (string) ($fields['code'] ?? '');
        $severities = Hardening::severityInfos();
        $severity = Hardening\HardeningMapper::severity($code);
        $end = substr((string) ($fields['date_end'] ?? ''), 0, 10);
        $authorId = (int) ($fields['users_id'] ?? 0);
        $days = HardeningException::daysLeft($end, (string) ($_SESSION['glpi_currenttime'] ?? date('Y-m-d')));

        $this->data['##exception.action##']        = $events[$event] ?? '';
        $this->data['##exception.service##']       = $hasService ? (string) $service->fields['name'] : '';
        $this->data['##exception.stack##']         = $hasService ? (string) $service->fields['stack_name'] : '';
        $this->data['##exception.finding##']       = Hardening::infos()[$code]['label'] ?? $code;
        $this->data['##exception.severity##']      = $severities[$severity]['label'] ?? '';
        $this->data['##exception.enddate##']       = (string) \Html::convDate($end);
        $this->data['##exception.days##']          = $days === null ? '' : (string) max(0, $days);
        $this->data['##exception.justification##'] = (string) ($fields['justification'] ?? '');
        $this->data['##exception.author##']        = $authorId > 0 ? (string) getUserName($authorId) : '';
        $this->data['##exception.entity##']        = (string) Dropdown::getDropdownName(
            Entity::getTable(),
            (int) ($fields['entities_id'] ?? 0)
        );
        $this->data['##exception.url##']           = $hasService
            ? $this->formatURL(
                $options['additionnaloption']['usertype'] ?? self::GLPI_USER,
                Service::class . '_' . $serviceId
            )
            : '';

        $this->getTags();
        foreach ($this->tag_descriptions[NotificationTarget::TAG_LANGUAGE] as $tag => $values) {
            if (!isset($this->data[$tag])) {
                $this->data[$tag] = $values['label'];
            }
        }
    }

    public function getTags()
    {
        $tags = [
            'exception.action'        => _n('Event', 'Events', 1),
            'exception.service'       => __('Service', 'dockerinventory'),
            'exception.stack'         => __('Stack', 'dockerinventory'),
            'exception.finding'       => __('Écart', 'dockerinventory'),
            'exception.severity'      => __('Gravité', 'dockerinventory'),
            'exception.enddate'       => __('Date de fin', 'dockerinventory'),
            'exception.days'          => __('Jours restants', 'dockerinventory'),
            'exception.justification' => __('Justification', 'dockerinventory'),
            'exception.author'        => __('Auteur de l’exception', 'dockerinventory'),
            'exception.entity'        => Entity::getTypeName(1),
            'exception.url'           => __('URL'),
        ];
        foreach ($tags as $tag => $label) {
            $this->addTagToList(['tag' => $tag, 'label' => $label, 'value' => true]);
        }

        asort($this->tag_descriptions);
    }
}

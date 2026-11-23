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

/**
 * Rappel avant l'expiration d'un certificat TLS (#78) : envoyé par la tâche automatique
 * `certificatecheck` (Certificate::remindExpiring()) aux cibles choisies dans Configuration >
 * Notifications (profils, groupes, utilisateurs). À l'installation : les profils qui administrent la
 * configuration de GLPI.
 *
 * Un destinataire ne reçoit le contenu que s'il peut lire les services dans l'entité du certificat
 * (Certificate::canReceive()), quelle que soit la cible.
 *
 * @extends NotificationTarget<Certificate>
 */
class NotificationTargetCertificate extends NotificationTarget
{
    public function getEvents()
    {
        return [
            Certificate::EVENT_EXPIRING => __('Certificat TLS qui expire bientôt', 'dockerinventory'),
        ];
    }

    /**
     * @param array<string, mixed> $infos
     */
    public function validateSendTo($event, array $infos, $notify_me = false, $emitter = null)
    {
        if (
            !$this->obj instanceof Certificate
            || !Certificate::canReceive((int) ($infos['users_id'] ?? 0), $this->obj)
        ) {
            return false;
        }

        return parent::validateSendTo($event, $infos, $notify_me, $emitter);
    }

    public function addDataForTemplate($event, $options = [])
    {
        $events = $this->getAllEvents();
        /** @var Certificate $certificate */
        $certificate = $this->obj;
        $fields = $certificate->fields;
        $notAfter = (string) ($fields['date_not_after'] ?? '');
        $days = Certificate::daysLeft($notAfter, (string) ($_SESSION['glpi_currenttime'] ?? date('Y-m-d')));

        $this->data['##certificate.action##']   = $events[$event] ?? '';
        $this->data['##certificate.host##']     = (string) ($fields['host'] ?? '');
        $this->data['##certificate.port##']     = (string) ($fields['port'] ?? '');
        $this->data['##certificate.subject##']  = (string) ($fields['subject'] ?? '');
        $this->data['##certificate.issuer##']   = (string) ($fields['issuer'] ?? '');
        $this->data['##certificate.enddate##']  = (string) \Html::convDateTime($notAfter);
        $this->data['##certificate.days##']     = $days === null ? '' : (string) max(0, $days);
        $this->data['##certificate.services##'] = implode(', ', $certificate->serviceNames());
        $this->data['##certificate.entity##']   = (string) Dropdown::getDropdownName(
            Entity::getTable(),
            (int) ($fields['entities_id'] ?? 0)
        );
        $this->data['##certificate.advice##']   = self::advice();
        $this->data['##certificate.url##']      = Exposure::getPageURL();

        $this->getTags();
        foreach ($this->tag_descriptions[NotificationTarget::TAG_LANGUAGE] as $tag => $values) {
            if (!isset($this->data[$tag])) {
                $this->data[$tag] = $values['label'];
            }
        }
    }

    /**
     * Conduite à tenir, dans la langue du destinataire (calculée à l'envoi).
     */
    public static function advice(): string
    {
        return __(
            'Renouvelez le certificat sur le point d’entrée (Traefik, ingress) : le plugin ne le fait pas.',
            'dockerinventory'
        );
    }

    public function getTags()
    {
        $tags = [
            'certificate.action'   => _n('Event', 'Events', 1),
            'certificate.host'     => __('Nom d’hôte', 'dockerinventory'),
            'certificate.port'     => __('Port', 'dockerinventory'),
            'certificate.subject'  => __('Sujet', 'dockerinventory'),
            'certificate.issuer'   => __('Émetteur', 'dockerinventory'),
            'certificate.enddate'  => __('Date d’expiration', 'dockerinventory'),
            'certificate.days'     => __('Jours restants', 'dockerinventory'),
            'certificate.services' => __('Services', 'dockerinventory'),
            'certificate.entity'   => Entity::getTypeName(1),
            'certificate.advice'   => __('Conduite à tenir', 'dockerinventory'),
            'certificate.url'      => __('URL'),
        ];
        foreach ($tags as $tag => $label) {
            $this->addTagToList(['tag' => $tag, 'label' => $label, 'value' => true]);
        }

        asort($this->tag_descriptions);
    }
}

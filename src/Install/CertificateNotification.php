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

namespace GlpiPlugin\Dockerinventory\Install;

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Dockerinventory\Certificate;
use Notification;
use Notification_NotificationTemplate;
use NotificationTarget;
use NotificationTemplate;
use NotificationTemplateTranslation;

/**
 * Rappel avant l'expiration d'un certificat TLS (#78) : modèle de notification et notification
 * active, adressée aux profils qui administrent la configuration de GLPI, créés une seule fois (un
 * modèle ou une notification modifiés par l'administrateur ne sont jamais réécrits). L'envoi suit le
 * réglage général des notifications de GLPI. La désinstallation supprime notification, modèle,
 * traductions et cibles.
 */
final class CertificateNotification
{
    public const NAME = 'Docker Inventory - TLS certificate expiring';

    /** Balises affichées avec leur libellé (##lang.certificate.x## : x), dans cet ordre. */
    public const FIELDS = ['host', 'port', 'subject', 'issuer', 'enddate', 'days', 'services', 'entity'];

    public static function install(): void
    {
        global $DB;

        $itemtype = Certificate::class;
        $template = new NotificationTemplate();
        if ($template->getFromDBByCrit(['itemtype' => $itemtype, 'name' => self::NAME])) {
            $templateId = (int) $template->getID();
        } else {
            $templateId = (int) $template->add(['name' => self::NAME, 'itemtype' => $itemtype]);
            if ($templateId <= 0) {
                return;
            }
            (new NotificationTemplateTranslation())->add(['notificationtemplates_id' => $templateId] + self::content());
        }

        $notification = new Notification();
        if ($notification->getFromDBByCrit(['itemtype' => $itemtype, 'event' => Certificate::EVENT_EXPIRING])) {
            return;
        }
        $notificationId = (int) $notification->add([
            'name'         => self::NAME,
            'entities_id'  => 0,
            'is_recursive' => 1,
            'is_active'    => 1,
            'itemtype'     => $itemtype,
            'event'        => Certificate::EVENT_EXPIRING,
        ]);
        if ($notificationId <= 0) {
            return;
        }
        (new Notification_NotificationTemplate())->add([
            'notifications_id'         => $notificationId,
            'notificationtemplates_id' => $templateId,
            'mode'                     => Notification_NotificationTemplate::MODE_MAIL,
        ]);
        // Profils qui modifient la configuration de GLPI (ceux qui reçoivent les droits du plugin).
        foreach ($DB->request(['FROM' => 'glpi_profilerights', 'WHERE' => ['name' => 'config']]) as $row) {
            if (((int) $row['rights'] & UPDATE) === UPDATE) {
                (new NotificationTarget())->add([
                    'notifications_id' => $notificationId,
                    'items_id'         => (int) $row['profiles_id'],
                    'type'             => Notification::PROFILE_TYPE,
                ]);
            }
        }
    }

    /**
     * Notifications et modèles du rappel, avec leurs liens, cibles et traductions (cleanDBonPurge()).
     */
    public static function uninstall(): void
    {
        global $DB;

        $where = ['itemtype' => Certificate::class];
        foreach ([new Notification(), new NotificationTemplate()] as $item) {
            foreach ($DB->request(['SELECT' => 'id', 'FROM' => $item::getTable(), 'WHERE' => $where]) as $row) {
                $item->delete(['id' => (int) $row['id']], true);
            }
        }
    }

    /**
     * Sujet et contenus du modèle. Libellés et conduite à tenir sont des balises, traduites à
     * l'envoi dans la langue de chaque destinataire.
     *
     * @return array{language: string, subject: string, content_text: string, content_html: string}
     */
    public static function content(): array
    {
        $text = ['##certificate.action##', ''];
        foreach (self::FIELDS as $field) {
            $text[] = '##lang.certificate.' . $field . '## : ##certificate.' . $field . '##';
        }
        $text = array_merge($text, ['', '##certificate.advice##', '##certificate.url##']);

        return [
            'language'     => '',
            'subject'      => '##certificate.action## : ##certificate.host## (##certificate.enddate##)',
            'content_text' => implode("\n", $text),
            // Gabarit lu comme une chaîne : pendant l'installation, l'espace Twig @dockerinventory
            // n'est pas encore monté (GLPI ne le monte que pour les plugins actifs).
            'content_html' => TemplateRenderer::getInstance()->renderFromStringTemplate(
                (string) file_get_contents(dirname(__DIR__, 2) . '/templates/notification_certificate.html.twig'),
                ['fields' => self::FIELDS]
            ),
        ];
    }
}

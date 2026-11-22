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
use GlpiPlugin\Dockerinventory\HardeningException;
use GlpiPlugin\Dockerinventory\NotificationTargetHardeningException;
use Notification;
use Notification_NotificationTemplate;
use NotificationTarget;
use NotificationTemplate;
use NotificationTemplateTranslation;

/**
 * Rappel avant l'expiration d'une exception (#99) : modèle de notification, notification active
 * adressée à l'auteur de l'exception, créés une seule fois (un modèle ou une notification modifiés
 * par l'administrateur ne sont jamais réécrits). L'envoi suit le réglage général des
 * notifications de GLPI. La désinstallation supprime notification, modèle, traductions et cibles.
 */
final class ExpiryNotification
{
    public const NAME = 'Docker Inventory - Exception expiring';

    /** Balises affichées avec leur libellé (##lang.exception.x## : x), dans cet ordre. */
    public const FIELDS = ['service', 'finding', 'severity', 'enddate', 'days', 'justification', 'author', 'entity'];

    public static function install(): void
    {
        $itemtype = HardeningException::class;
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
        if (
            $notification->getFromDBByCrit([
                'itemtype' => $itemtype,
                'event'    => HardeningException::EVENT_EXPIRING,
            ])
        ) {
            return;
        }
        $notificationId = (int) $notification->add([
            'name'         => self::NAME,
            'entities_id'  => 0,
            'is_recursive' => 1,
            'is_active'    => 1,
            'itemtype'     => $itemtype,
            'event'        => HardeningException::EVENT_EXPIRING,
        ]);
        if ($notificationId <= 0) {
            return;
        }
        (new Notification_NotificationTemplate())->add([
            'notifications_id'         => $notificationId,
            'notificationtemplates_id' => $templateId,
            'mode'                     => Notification_NotificationTemplate::MODE_MAIL,
        ]);
        (new NotificationTarget())->add([
            'notifications_id' => $notificationId,
            'items_id'         => NotificationTargetHardeningException::TARGET_AUTHOR,
            'type'             => Notification::USER_TYPE,
        ]);
    }

    /**
     * Notifications et modèles du plugin, avec leurs liens, cibles et traductions (cleanDBonPurge()).
     */
    public static function uninstall(): void
    {
        global $DB;

        $where = ['itemtype' => HardeningException::class];
        foreach ([new Notification(), new NotificationTemplate()] as $item) {
            foreach ($DB->request(['SELECT' => 'id', 'FROM' => $item::getTable(), 'WHERE' => $where]) as $row) {
                $item->delete(['id' => (int) $row['id']], true);
            }
        }
    }

    /**
     * Sujet et contenus du modèle. Libellés et conduite à tenir sont des balises, traduites à
     * l'envoi dans la langue de chaque destinataire : le modèle ne dépend pas de la langue de
     * l'installation.
     *
     * @return array{language: string, subject: string, content_text: string, content_html: string}
     */
    public static function content(): array
    {
        $text = ['##exception.action##', ''];
        foreach (self::FIELDS as $field) {
            $text[] = '##lang.exception.' . $field . '## : ##exception.' . $field . '##';
        }
        $text = array_merge($text, ['', '##exception.advice##', '##exception.url##']);

        return [
            'language'     => '',
            'subject'      => '##exception.action## : ##exception.service## (##exception.finding##)',
            'content_text' => implode("
", $text),
            // Gabarit lu comme une chaîne : pendant l'installation, l'espace Twig @dockerinventory
            // n'est pas encore monté (GLPI ne le monte que pour les plugins actifs).
            'content_html' => TemplateRenderer::getInstance()->renderFromStringTemplate(
                (string) file_get_contents(dirname(__DIR__, 2) . '/templates/notification_expiring.html.twig'),
                ['fields' => self::FIELDS]
            ),
        ];
    }
}

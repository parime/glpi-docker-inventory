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

namespace GlpiPlugin\Dockerinventory\Ticket;

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Dockerinventory\Connection;
use GlpiPlugin\Dockerinventory\Lifecycle\LifecycleChecker;
use GlpiPlugin\Dockerinventory\LifecycleProduct;
use GlpiPlugin\Dockerinventory\Service;
use ITILFollowup;

/**
 * Tickets de fin de support, après chaque mise à jour des cycles de vie (désactivé par défaut,
 * réglé sur chaque connexion Portainer : délai en jours ; catégorie, groupe et modèle des tickets
 * de mise à jour).
 *
 * Un ticket par service (table glpi_plugin_dockerinventory_eoltickets), distinct du ticket de mise
 * à jour ; s'il est encore ouvert, un suivi quand la situation change, jamais un doublon. Un ticket
 * existant n'est jamais modifié (ni statut, ni priorité, ni affectation).
 *
 * @phpstan-import-type EolSituation from EolTicketPolicy
 * @phpstan-import-type EolTracked from EolTicketPolicy
 * @phpstan-import-type TicketReport from UpdateTickets
 * @phpstan-type EolService array{id: int, name: string, stack_name: string, appliances_id: int,
 *     entities_id: int, image: string, tag: string, days: int, itilcategories_id: int, groups_id: int,
 *     tickettemplates_id: int}
 */
final class EolTickets
{
    public const TABLE = 'glpi_plugin_dockerinventory_eoltickets';

    private string $now;

    public function __construct(private readonly LifecycleChecker $checker)
    {
        $this->now = (string) ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'));
    }

    /**
     * @return TicketReport
     */
    public function process(): array
    {
        $report = UpdateTickets::emptyReport();
        foreach ($this->services() as $service) {
            $evaluation = $this->checker->evaluate($service['image'], $service['tag']);
            $situation = [
                'status'  => $evaluation['status'],
                'product' => $evaluation['product'],
                'cycle'   => $evaluation['cycle'],
                'eol'     => $evaluation['eol'],
                'days'    => $evaluation['days'],
            ];
            $tracked = $this->tracked($service['id']);
            $ticketOpen = $tracked !== null && UpdateTickets::isOpen($tracked['tickets_id']);
            $triggered = EolTicketPolicy::triggered($situation, $service['days']);
            $action = EolTicketPolicy::decide($situation, $tracked, $ticketOpen, $triggered);
            if ($action === TicketPolicy::ACTION_CREATE) {
                $ticketId = $this->createTicket($service, $situation);
                if ($ticketId > 0) {
                    $this->track($service['id'], $ticketId, $situation);
                    $report['created']++;
                } else {
                    $report['errors']++;
                }
            } elseif ($action === TicketPolicy::ACTION_FOLLOWUP && $tracked !== null) {
                if ($this->addFollowup($tracked, $service, $situation, $triggered)) {
                    $this->track($service['id'], $tracked['tickets_id'], $situation);
                    $report['followups']++;
                } else {
                    $report['errors']++;
                }
            } else {
                $report['unchanged']++;
            }
        }

        return $report;
    }

    /**
     * Services actifs dont la connexion Portainer (active) a les tickets de fin de support activés.
     *
     * @return list<EolService>
     */
    private function services(): array
    {
        global $DB;

        $iterator = $DB->request([
            'SELECT'     => [
                's.id', 's.name', 's.stack_name', 's.appliances_id', 's.entities_id', 's.image', 's.tag',
                'c.ticket_eol_days', 'c.ticket_itilcategories_id', 'c.ticket_groups_id',
                'c.ticket_tickettemplates_id',
            ],
            'FROM'       => Service::getTable() . ' AS s',
            'INNER JOIN' => [
                Connection::getTable() . ' AS c' => [
                    'ON' => ['s' => 'plugin_dockerinventory_connections_id', 'c' => 'id'],
                ],
            ],
            'WHERE'      => [
                's.is_retired'         => 0,
                ['NOT' => ['s.image' => '']],
                'c.type'               => Connection::TYPE_PORTAINER,
                'c.is_active'          => 1,
                'c.ticket_eol_enabled' => 1,
            ],
            'ORDER'      => 's.id',
        ]);
        $services = [];
        foreach ($iterator as $row) {
            $services[] = [
                'id'                 => (int) $row['id'],
                'name'               => (string) $row['name'],
                'stack_name'         => (string) $row['stack_name'],
                'appliances_id'      => (int) $row['appliances_id'],
                'entities_id'        => (int) $row['entities_id'],
                'image'              => (string) $row['image'],
                'tag'                => (string) $row['tag'],
                'days'               => (int) $row['ticket_eol_days'],
                'itilcategories_id'  => (int) $row['ticket_itilcategories_id'],
                'groups_id'          => (int) $row['ticket_groups_id'],
                'tickettemplates_id' => (int) $row['ticket_tickettemplates_id'],
            ];
        }

        return $services;
    }

    /**
     * @return (EolTracked&array{tickets_id: int})|null
     */
    private function tracked(int $serviceId): ?array
    {
        global $DB;

        $row = $DB->request([
            'FROM'  => self::TABLE,
            'WHERE' => ['plugin_dockerinventory_services_id' => $serviceId],
            'LIMIT' => 1,
        ])->current();
        if (!is_array($row)) {
            return null;
        }

        return [
            'tickets_id' => (int) $row['tickets_id'],
            'product'    => (string) $row['product'],
            'cycle'      => (string) $row['cycle'],
            'eol'        => (string) $row['eol'],
        ];
    }

    /**
     * Ticket de fin de support d'un service (onglet Versions), null s'il n'y en a pas ou s'il a disparu.
     *
     * @return array{id: int, name: string, status: string, is_open: bool}|null
     */
    public static function ticketFor(int $serviceId): ?array
    {
        global $DB;

        $row = $DB->request([
            'SELECT' => ['tickets_id'],
            'FROM'   => self::TABLE,
            'WHERE'  => ['plugin_dockerinventory_services_id' => $serviceId],
            'LIMIT'  => 1,
        ])->current();
        $ticket = new \Ticket();
        if (!is_array($row) || !$ticket->getFromDB((int) $row['tickets_id'])) {
            return null;
        }

        return [
            'id'      => (int) $ticket->getID(),
            'name'    => (string) $ticket->fields['name'],
            'status'  => (string) \Ticket::getStatus((int) $ticket->fields['status']),
            'is_open' => UpdateTickets::isOpen((int) $ticket->getID()),
        ];
    }

    /**
     * @param EolService $service
     * @param EolSituation $situation
     */
    private function createTicket(array $service, array $situation): int
    {
        $input = UpdateTickets::templateFields($service['tickettemplates_id']);
        $input['name'] = self::title($service, $situation);
        $input['content'] = TemplateRenderer::getInstance()->render(
            '@dockerinventory/eol_ticket_content.html.twig',
            $this->contentVariables($service, $situation)
        );
        $input['entities_id'] = $service['entities_id'];
        $input['type'] = (int) ($input['type'] ?? \Ticket::DEMAND_TYPE);
        if ($service['itilcategories_id'] > 0) {
            $input['itilcategories_id'] = $service['itilcategories_id'];
        }
        if ($service['groups_id'] > 0) {
            $input['_groups_id_assign'] = $service['groups_id'];
        }
        if ($service['appliances_id'] > 0) {
            $input['items_id'] = ['Appliance' => [$service['appliances_id']]];
        }
        $ticketId = (new \Ticket())->add($input);

        return $ticketId === false ? 0 : (int) $ticketId;
    }

    /**
     * @param array{name: string, stack_name: string} $service
     * @param array{product: string, cycle: string} $situation
     */
    public static function title(array $service, array $situation): string
    {
        if ($service['stack_name'] === '') {
            return sprintf(
                __('Fin de support : %1$s %2$s (service %3$s)', 'dockerinventory'),
                $situation['product'],
                $situation['cycle'],
                $service['name']
            );
        }

        return sprintf(
            __('Fin de support : %1$s %2$s (service %3$s de la stack %4$s)', 'dockerinventory'),
            $situation['product'],
            $situation['cycle'],
            $service['name'],
            $service['stack_name']
        );
    }

    /**
     * @param EolService $service
     * @param EolSituation $situation
     * @return array<string, mixed>
     */
    private function contentVariables(array $service, array $situation): array
    {
        global $CFG_GLPI;

        $base = rtrim((string) ($CFG_GLPI['url_base'] ?? ''), '/');
        $appliance = new \Appliance();

        return [
            'service'     => $service['name'],
            'stack'       => $service['stack_name'],
            'appliance'   => $service['appliances_id'] > 0 && $appliance->getFromDB($service['appliances_id'])
                ? (string) $appliance->fields['name'] : '',
            'image'       => $service['image'],
            'tag'         => $service['tag'],
            'product'     => $situation['product'],
            'cycle'       => $situation['cycle'],
            'eol'         => $situation['eol'],
            'days'        => $situation['days'],
            'status'      => LifecycleProduct::statusInfo($situation['status']),
            'threshold'   => $service['days'],
            'service_url' => $base . '/plugins/dockerinventory/front/service.form.php?id=' . $service['id'],
        ];
    }

    /**
     * @param EolTracked&array{tickets_id: int} $tracked
     * @param EolService $service
     * @param EolSituation $situation
     */
    private function addFollowup(array $tracked, array $service, array $situation, bool $triggered): bool
    {
        $content = TemplateRenderer::getInstance()->render(
            '@dockerinventory/eol_ticket_followup.html.twig',
            $this->contentVariables($service, $situation) + [
                'kind'     => EolTicketPolicy::followupKind($triggered),
                'previous' => $tracked,
            ]
        );
        $followupId = (new ITILFollowup())->add([
            'itemtype'   => \Ticket::class,
            'items_id'   => $tracked['tickets_id'],
            'content'    => $content,
            'is_private' => 0,
            '_no_reopen'                       => 1,
            '_do_not_compute_status'           => 1,
            '_do_not_compute_takeintoaccount'  => 1,
        ]);

        return $followupId !== false && (int) $followupId > 0;
    }

    /**
     * @param EolSituation $situation
     */
    private function track(int $serviceId, int $ticketId, array $situation): void
    {
        global $DB;

        $values = [
            'tickets_id' => $ticketId,
            'product'    => mb_substr($situation['product'], 0, 64),
            'cycle'      => mb_substr($situation['cycle'], 0, 64),
            'eol'        => mb_substr($situation['eol'], 0, 16),
            'date_mod'   => $this->now,
        ];
        $where = ['plugin_dockerinventory_services_id' => $serviceId];
        $existing = $DB->request(['SELECT' => ['id'], 'FROM' => self::TABLE, 'WHERE' => $where])->current();
        if (is_array($existing)) {
            $DB->update(self::TABLE, $values, ['id' => (int) $existing['id']]);
        } else {
            $DB->insert(self::TABLE, $where + $values + ['date_creation' => $this->now]);
        }
    }
}

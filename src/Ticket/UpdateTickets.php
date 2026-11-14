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
use GlpiPlugin\Dockerinventory\ImageVersion;
use GlpiPlugin\Dockerinventory\Service;
use GlpiPlugin\Dockerinventory\Version\VersionComparator;
use ITILFollowup;
use TicketTemplate;

/**
 * Tickets de mise à jour, après chaque lecture de WUD ou des registres (désactivé par défaut,
 * réglé sur chaque connexion Portainer : seuil, catégorie, groupe, modèle).
 *
 * « Mettre à jour le service X de la stack Y : 1.2 → 1.4 » seulement au-delà du seuil ; un ticket
 * par service (table glpi_plugin_dockerinventory_tickets) ; s'il est encore ouvert, un suivi quand
 * la situation change, jamais un doublon. Un ticket existant n'est jamais modifié (ni statut, ni
 * priorité, ni affectation) : les suivis sont ajoutés sans réouverture ni recalcul du statut.
 *
 * @phpstan-import-type Situation from TicketPolicy
 * @phpstan-type Current array{gap: string, deployed_tag: string, latest_tag: string, image: string,
 *     source: string}
 * @phpstan-type Tracked array{tickets_id: int, gap: string, deployed_tag: string, latest_tag: string}
 * @phpstan-type TicketReport array{created: int, followups: int, unchanged: int, errors: int}
 */
final class UpdateTickets
{
    public const TABLE = 'glpi_plugin_dockerinventory_tickets';

    private string $now = '';

    /**
     * Rapport vide (aucun service à traiter, ou tickets désactivés).
     *
     * @return TicketReport
     */
    public static function emptyReport(): array
    {
        return ['created' => 0, 'followups' => 0, 'unchanged' => 0, 'errors' => 0];
    }

    /**
     * @param list<int> $serviceIds Services dont la version vient d'être lue.
     * @return TicketReport
     */
    public function process(array $serviceIds): array
    {
        $report = self::emptyReport();
        if ($serviceIds === []) {
            return $report;
        }
        $this->now = (string) ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'));
        foreach ($this->services($serviceIds) as $service) {
            $situation = $this->situation($service['id']);
            if ($situation === null) {
                continue;
            }
            $tracked = $this->tracked($service['id']);
            $ticketOpen = $tracked !== null && self::isOpen($tracked['tickets_id']);
            $triggers = TicketPolicy::triggers($situation, $service['threshold']);
            $action = TicketPolicy::decide($situation, $tracked, $ticketOpen, $triggers);
            if ($action === TicketPolicy::ACTION_CREATE) {
                $ticketId = $this->createTicket($service, $situation);
                if ($ticketId > 0) {
                    $this->track($service['id'], $ticketId, $situation);
                    $report['created']++;
                } else {
                    $report['errors']++;
                }
            } elseif ($action === TicketPolicy::ACTION_FOLLOWUP && $tracked !== null) {
                if ($this->addFollowup($tracked, $service, $situation, $triggers)) {
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
     * Services actifs dont la connexion Portainer (active) a les tickets activés, avec ses réglages.
     *
     * @param list<int> $serviceIds
     * @return list<array{id: int, name: string, stack_name: string, appliances_id: int, entities_id: int,
     *     threshold: string, itilcategories_id: int, groups_id: int, tickettemplates_id: int}>
     */
    private function services(array $serviceIds): array
    {
        global $DB;

        $iterator = $DB->request([
            'SELECT'     => [
                's.id', 's.name', 's.stack_name', 's.appliances_id', 's.entities_id',
                'c.ticket_threshold', 'c.ticket_itilcategories_id', 'c.ticket_groups_id',
                'c.ticket_tickettemplates_id',
            ],
            'FROM'       => Service::getTable() . ' AS s',
            'INNER JOIN' => [
                Connection::getTable() . ' AS c' => [
                    'ON' => ['s' => 'plugin_dockerinventory_connections_id', 'c' => 'id'],
                ],
            ],
            'WHERE'      => [
                's.id'             => $serviceIds,
                's.is_retired'     => 0,
                'c.type'           => Connection::TYPE_PORTAINER,
                'c.is_active'      => 1,
                'c.ticket_enabled' => 1,
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
                'threshold'          => (string) $row['ticket_threshold'],
                'itilcategories_id'  => (int) $row['ticket_itilcategories_id'],
                'groups_id'          => (int) $row['ticket_groups_id'],
                'tickettemplates_id' => (int) $row['ticket_tickettemplates_id'],
            ];
        }

        return $services;
    }

    /**
     * Situation d'un service : sa ligne de version la plus grave (WUD ou registre).
     *
     * @return Current|null
     */
    private function situation(int $serviceId): ?array
    {
        global $DB;

        $best = null;
        $iterator = $DB->request([
            'SELECT' => ['gap', 'deployed_tag', 'latest_tag', 'image', 'source'],
            'FROM'   => ImageVersion::getTable(),
            'WHERE'  => ['plugin_dockerinventory_services_id' => $serviceId],
        ]);
        foreach ($iterator as $row) {
            if (
                $best === null
                || VersionComparator::severity((string) $row['gap']) < VersionComparator::severity($best['gap'])
            ) {
                $best = [
                    'gap'          => (string) $row['gap'],
                    'deployed_tag' => (string) $row['deployed_tag'],
                    'latest_tag'   => (string) $row['latest_tag'],
                    'image'        => (string) $row['image'],
                    'source'       => (string) $row['source'],
                ];
            }
        }

        return $best;
    }

    /**
     * @return Tracked|null
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
            'tickets_id'   => (int) $row['tickets_id'],
            'gap'          => (string) $row['gap'],
            'deployed_tag' => (string) $row['deployed_tag'],
            'latest_tag'   => (string) $row['latest_tag'],
        ];
    }

    /**
     * Ticket encore ouvert : existe, pas dans la corbeille, ni résolu ni clos.
     */
    public static function isOpen(int $ticketId): bool
    {
        $ticket = new \Ticket();
        if ($ticketId <= 0 || !$ticket->getFromDB($ticketId) || (int) $ticket->fields['is_deleted'] === 1) {
            return false;
        }
        $done = array_merge(\Ticket::getSolvedStatusArray(), \Ticket::getClosedStatusArray());

        return !in_array((int) $ticket->fields['status'], $done, true);
    }

    /**
     * Ticket suivi pour un service (onglet Versions), null s'il n'y en a pas ou s'il a disparu.
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
            'is_open' => self::isOpen((int) $ticket->getID()),
        ];
    }

    /**
     * @param array{id: int, name: string, stack_name: string, appliances_id: int, entities_id: int,
     *     threshold: string, itilcategories_id: int, groups_id: int, tickettemplates_id: int} $service
     * @param Current $situation
     */
    private function createTicket(array $service, array $situation): int
    {
        $input = self::templateFields($service['tickettemplates_id']);
        $input['name'] = self::title($service, $situation);
        $input['content'] = TemplateRenderer::getInstance()->render(
            '@dockerinventory/ticket_content.html.twig',
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
            // Ticket rattaché à l'application de la stack (analyse d'impact).
            $input['items_id'] = ['Appliance' => [$service['appliances_id']]];
        }
        $ticketId = (new \Ticket())->add($input);

        return $ticketId === false ? 0 : (int) $ticketId;
    }

    /**
     * Champs prédéfinis du modèle de ticket choisi (type, urgence, demandeur, observateur...),
     * appliqués comme valeurs de départ : le titre, la description, la catégorie et le groupe
     * choisis sur la connexion passent devant.
     *
     * @return array<string, mixed>
     */
    public static function templateFields(int $templateId): array
    {
        $template = new TicketTemplate();
        if ($templateId <= 0 || !$template->getFromDBWithData($templateId, false)) {
            return [];
        }
        $fields = [];
        foreach ($template->predefined as $field => $value) {
            if (in_array($field, ['name', 'content', 'items_id', 'itemtype', 'entities_id'], true)) {
                continue;
            }
            $fields[(string) $field] = $value;
        }

        return $fields;
    }

    /**
     * @param array{id: int, name: string, stack_name: string} $service
     * @param Situation $situation
     */
    public static function title(array $service, array $situation): string
    {
        if ($service['stack_name'] === '') {
            return sprintf(
                __('Mettre à jour le service %1$s : %2$s → %3$s', 'dockerinventory'),
                $service['name'],
                $situation['deployed_tag'],
                $situation['latest_tag']
            );
        }

        return sprintf(
            __('Mettre à jour le service %1$s de la stack %2$s : %3$s → %4$s', 'dockerinventory'),
            $service['name'],
            $service['stack_name'],
            $situation['deployed_tag'],
            $situation['latest_tag']
        );
    }

    /**
     * @param array{id: int, name: string, stack_name: string, appliances_id: int, threshold: string} $service
     * @param Current $situation
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
            'image'       => $situation['image'],
            'deployed'    => $situation['deployed_tag'],
            'latest'      => $situation['latest_tag'],
            'gap'         => ImageVersion::gapInfo($situation['gap']),
            'source'      => ImageVersion::sourceLabels()[$situation['source']] ?? $situation['source'],
            'threshold'   => Connection::ticketThresholdLabels()[$service['threshold']] ?? $service['threshold'],
            'service_url' => $base . '/plugins/dockerinventory/front/service.form.php?id=' . $service['id'],
        ];
    }

    /**
     * @param Tracked $tracked
     * @param array{id: int, name: string, stack_name: string, appliances_id: int, threshold: string} $service
     * @param Current $situation
     * @param list<string> $triggers
     */
    private function addFollowup(array $tracked, array $service, array $situation, array $triggers): bool
    {
        $content = TemplateRenderer::getInstance()->render(
            '@dockerinventory/ticket_followup.html.twig',
            $this->contentVariables($service, $situation) + [
                'kind'          => TicketPolicy::followupKind($situation, $triggers),
                'previous'      => $tracked,
                'previous_gap'  => ImageVersion::gapInfo($tracked['gap']),
            ]
        );
        $followupId = (new ITILFollowup())->add([
            'itemtype'   => \Ticket::class,
            'items_id'   => $tracked['tickets_id'],
            'content'    => $content,
            'is_private' => 0,
            // Le suivi ne rouvre pas le ticket et ne recalcule ni statut ni prise en compte.
            '_no_reopen'                       => 1,
            '_do_not_compute_status'           => 1,
            '_do_not_compute_takeintoaccount'  => 1,
        ]);

        return $followupId !== false && (int) $followupId > 0;
    }

    /**
     * @param Situation $situation
     */
    private function track(int $serviceId, int $ticketId, array $situation): void
    {
        global $DB;

        $values = [
            'tickets_id'   => $ticketId,
            'gap'          => $situation['gap'],
            'deployed_tag' => mb_substr($situation['deployed_tag'], 0, 255),
            'latest_tag'   => mb_substr($situation['latest_tag'], 0, 255),
            'date_mod'     => $this->now,
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

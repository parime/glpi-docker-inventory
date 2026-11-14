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

namespace GlpiPlugin\Dockerinventory\Tests\Integration;

use Appliance;
use CommonITILObject;
use GlpiPlugin\Dockerinventory\Connection;
use GlpiPlugin\Dockerinventory\ImageVersion;
use GlpiPlugin\Dockerinventory\Importer;
use GlpiPlugin\Dockerinventory\Portainer\PortainerClient;
use GlpiPlugin\Dockerinventory\Registry\RegistryClient;
use GlpiPlugin\Dockerinventory\Registry\RegistryImporter;
use GlpiPlugin\Dockerinventory\Service;
use GlpiPlugin\Dockerinventory\Tests\PortainerFixture;
use GlpiPlugin\Dockerinventory\Ticket\TicketPolicy;
use GlpiPlugin\Dockerinventory\Ticket\UpdateTickets;
use ITILFollowup;
use PHPUnit\Framework\TestCase;
use Ticket;

/**
 * Tickets de mise à jour contre la vraie base GLPI : services du faux Portainer, lignes de version
 * écrites directement (ou par la vérification des registres, transports en mémoire).
 */
final class UpdateTicketsTest extends TestCase
{
    private int $entityId = 0;

    private int $portainerId = 0;

    private int $categoryId = 0;

    private int $groupId = 0;

    private int $templateId = 0;

    /** @var array<string, int> Identifiants des services, par nom. */
    private array $services = [];

    protected function setUp(): void
    {
        global $DB;

        $suffix = bin2hex(random_bytes(4));
        $this->entityId = (int) (new \Entity())->add(['name' => 'PHPUnit tickets ' . $suffix, 'entities_id' => 0]);
        \Session::changeActiveEntities(0, true);
        $this->categoryId = (int) (new \ITILCategory())->add(['name' => 'Mises à jour PHPUnit ' . $suffix]);
        $this->groupId = (int) (new \Group())->add(['name' => 'Exploitation PHPUnit ' . $suffix, 'is_assign' => 1]);
        $this->portainerId = (int) (new Connection())->add([
            'name'        => 'Portainer tickets PHPUnit ' . $suffix,
            'entities_id' => $this->entityId,
            'url'         => 'https://portainer.exemple.test:9443',
            'api_key'     => 'ptr_fictive_cle_tickets',
        ]);
        $this->assertGreaterThan(0, $this->portainerId);
        $fixture = PortainerFixture::load();
        $connection = new Connection();
        $connection->getFromDB($this->portainerId);
        (new Importer(static fn (Connection $c): PortainerClient => new PortainerClient(
            'https://portainer.exemple.test:9443',
            'ptr_fictive_cle_tickets',
            static function (string $url) use ($fixture): string {
                $path = (string) parse_url($url, PHP_URL_PATH);
                return PortainerFixture::respond($fixture, $path);
            }
        )))->import($connection);
        foreach (
            $DB->request(['SELECT' => ['id', 'name'], 'FROM' => Service::getTable(),
            'WHERE' => ['plugin_dockerinventory_connections_id' => $this->portainerId]]) as $row
        ) {
            $this->services[(string) $row['name']] = (int) $row['id'];
        }
    }

    protected function tearDown(): void
    {
        global $DB;

        $ids = array_values($this->services);
        if ($ids !== []) {
            foreach (
                $DB->request(['SELECT' => ['tickets_id'], 'FROM' => UpdateTickets::TABLE,
                'WHERE' => ['plugin_dockerinventory_services_id' => $ids]]) as $row
            ) {
                (new Ticket())->delete(['id' => (int) $row['tickets_id']], true);
            }
            $DB->delete(UpdateTickets::TABLE, ['plugin_dockerinventory_services_id' => $ids]);
            $DB->delete(ImageVersion::getTable(), ['plugin_dockerinventory_services_id' => $ids]);
            $DB->delete(
                'glpi_plugin_dockerinventory_services_nodes',
                ['plugin_dockerinventory_services_id' => $ids]
            );
        }
        // Tickets créés puis dé-suivis (fermés puis remplacés) : retrouvés par leur titre.
        foreach ((new Ticket())->find(['entities_id' => $this->entityId]) as $row) {
            (new Ticket())->delete(['id' => (int) $row['id']], true);
        }
        foreach (
            $DB->request(['SELECT' => ['appliances_id'], 'FROM' => 'glpi_plugin_dockerinventory_stacks',
            'WHERE' => ['plugin_dockerinventory_connections_id' => $this->portainerId]]) as $row
        ) {
            (new Appliance())->delete(['id' => (int) $row['appliances_id']], true);
        }
        foreach (
            [Service::getTable(), 'glpi_plugin_dockerinventory_nodes', 'glpi_plugin_dockerinventory_stacks'] as $table
        ) {
            $DB->delete($table, ['plugin_dockerinventory_connections_id' => $this->portainerId]);
        }
        (new Connection())->delete(['id' => $this->portainerId], true);
        if ($this->templateId > 0) {
            (new \TicketTemplate())->delete(['id' => $this->templateId], true);
        }
        (new \ITILCategory())->delete(['id' => $this->categoryId], true);
        (new \Group())->delete(['id' => $this->groupId], true);
        (new \Entity())->delete(['id' => $this->entityId], true);
        $_SESSION['MESSAGE_AFTER_REDIRECT'] = [];
    }

    /**
     * @param array<string, int> $extra
     */
    private function enableTickets(string $threshold, array $extra = []): void
    {
        $connection = new Connection();
        $connection->getFromDB($this->portainerId);
        $this->assertTrue($connection->update([
            'id'                       => $this->portainerId,
            'ticket_enabled'           => 1,
            'ticket_threshold'         => $threshold,
            'ticket_itilcategories_id' => $this->categoryId,
            'ticket_groups_id'         => $this->groupId,
        ] + $extra));
    }

    private function setVersion(string $service, string $deployed, string $latest, string $gap): void
    {
        global $DB;

        $where = [
            'plugin_dockerinventory_connections_id' => 0,
            'plugin_dockerinventory_services_id'    => $this->services[$service],
            'container_uid'                         => '',
        ];
        $values = [
            'entities_id'  => $this->entityId,
            'image'        => 'library/' . $service,
            'deployed_tag' => $deployed,
            'latest_tag'   => $latest,
            'gap'          => $gap,
            'source'       => ImageVersion::SOURCE_REGISTRY,
        ];
        $DB->delete(ImageVersion::getTable(), $where);
        $DB->insert(ImageVersion::getTable(), $where + $values);
    }

    /**
     * @return array<string, mixed>
     */
    private function process(): array
    {
        return (new UpdateTickets())->process(array_values($this->services));
    }

    private function ticketOf(string $service): Ticket
    {
        global $DB;

        $row = $DB->request(['SELECT' => ['tickets_id'], 'FROM' => UpdateTickets::TABLE,
            'WHERE' => ['plugin_dockerinventory_services_id' => $this->services[$service]]])->current();
        $this->assertIsArray($row, $service);
        $ticket = new Ticket();
        $this->assertTrue($ticket->getFromDB((int) $row['tickets_id']));

        return $ticket;
    }

    private function followups(Ticket $ticket): int
    {
        return countElementsInTable(
            ITILFollowup::getTable(),
            ['itemtype' => Ticket::class, 'items_id' => $ticket->getID()]
        );
    }

    public function testDisabledByDefault(): void
    {
        $connection = new Connection();
        $connection->getFromDB($this->portainerId);
        $this->assertSame(0, (int) $connection->fields['ticket_enabled']);
        $this->assertSame(TicketPolicy::THRESHOLD_MAJOR, $connection->fields['ticket_threshold']);

        $this->setVersion('boutique_db', '16.4', '17.2', 'major');
        $this->assertSame(0, $this->process()['created']);
        $this->assertSame(0, countElementsInTable(Ticket::getTable(), ['entities_id' => $this->entityId]));
    }

    public function testTicketsAboveThresholdOnlyAndNoDuplicate(): void
    {
        $this->enableTickets(TicketPolicy::THRESHOLD_MINOR);
        $this->setVersion('boutique_db', '16.4', '17.2', 'major');
        $this->setVersion('supervision_grafana', '11.2.0', '11.3.1', 'minor');
        $this->setVersion('boutique_cache', '7.4.1', '7.4.2', 'patch');

        $report = $this->process();
        $this->assertSame(2, $report['created']);
        $this->assertSame(0, $report['errors']);

        $ticket = $this->ticketOf('boutique_db');
        $this->assertStringContainsString('boutique_db', $ticket->fields['name']);
        $this->assertStringContainsString('boutique', $ticket->fields['name']);
        $this->assertStringContainsString('16.4 → 17.2', $ticket->fields['name']);
        $this->assertStringContainsString('17.2', (string) $ticket->fields['content']);
        $this->assertSame($this->categoryId, (int) $ticket->fields['itilcategories_id']);
        $this->assertSame($this->entityId, (int) $ticket->fields['entities_id']);
        $this->assertSame(1, countElementsInTable('glpi_groups_tickets', [
            'tickets_id' => $ticket->getID(), 'groups_id' => $this->groupId, 'type' => \CommonITILActor::ASSIGN,
        ]));
        $this->assertSame(1, countElementsInTable('glpi_items_tickets', [
            'tickets_id' => $ticket->getID(), 'itemtype' => Appliance::class,
        ]));
        $this->assertNull(UpdateTickets::ticketFor($this->services['boutique_cache']));

        // Nouvelle lecture, même situation : ni ticket, ni suivi.
        $again = $this->process();
        $this->assertSame(0, $again['created']);
        $this->assertSame(0, $again['followups']);
        $this->assertSame(0, $this->followups($ticket));
    }

    public function testFollowupWhenSituationChangesWithoutTouchingTheTicket(): void
    {
        $this->enableTickets(TicketPolicy::THRESHOLD_MAJOR);
        $this->setVersion('boutique_db', '16.4', '17.2', 'major');
        $this->process();
        $ticket = $this->ticketOf('boutique_db');
        // Un technicien prend le ticket en charge et change sa priorité.
        $ticket->update(['id' => $ticket->getID(), 'status' => CommonITILObject::WAITING, 'priority' => 5]);
        $ticket->getFromDB($ticket->getID());
        $before = [$ticket->fields['status'], $ticket->fields['priority'], $ticket->fields['urgency']];
        $assigned = countElementsInTable('glpi_groups_tickets', ['tickets_id' => $ticket->getID()]);

        // Nouvelle version : suivi, pas de second ticket.
        $this->setVersion('boutique_db', '16.4', '18.0', 'major');
        $report = $this->process();
        $this->assertSame(0, $report['created']);
        $this->assertSame(1, $report['followups']);
        $this->assertSame(1, $this->followups($ticket));
        $this->assertSame($ticket->getID(), $this->ticketOf('boutique_db')->getID());

        // Écart résolu : suivi « résolu », le ticket reste tel quel (pas de clôture automatique).
        $this->setVersion('boutique_db', '18.0', '18.0', 'none');
        $this->assertSame(1, $this->process()['followups']);
        $this->assertSame(2, $this->followups($ticket));
        // Registre indisponible : rien.
        $this->setVersion('boutique_db', '18.0', '', 'unknown');
        $this->assertSame(0, $this->process()['followups']);

        $ticket->getFromDB($ticket->getID());
        $after = [$ticket->fields['status'], $ticket->fields['priority'], $ticket->fields['urgency']];
        $this->assertSame($before, $after);
        $this->assertSame($assigned, countElementsInTable('glpi_groups_tickets', ['tickets_id' => $ticket->getID()]));
        $this->assertSame(1, countElementsInTable(Ticket::getTable(), ['entities_id' => $this->entityId]));
    }

    public function testClosedTicketIsReplacedOnlyForANewVersion(): void
    {
        $this->enableTickets(TicketPolicy::THRESHOLD_MAJOR);
        $this->setVersion('boutique_db', '16.4', '17.2', 'major');
        $this->process();
        $first = $this->ticketOf('boutique_db');
        $first->update(['id' => $first->getID(), 'status' => CommonITILObject::CLOSED]);

        $this->assertSame(0, $this->process()['created']);
        $this->setVersion('boutique_db', '16.4', '18.0', 'major');
        $this->assertSame(1, $this->process()['created']);
        $this->assertNotSame($first->getID(), $this->ticketOf('boutique_db')->getID());
        $this->assertSame(0, $this->followups($first));
    }

    public function testTemplatePredefinedFieldsAreApplied(): void
    {
        $this->templateId = (int) (new \TicketTemplate())->add(['name' => 'Modèle PHPUnit mises à jour']);
        (new \TicketTemplatePredefinedField())->add([
            'tickettemplates_id' => $this->templateId,
            'num'                => 10, // urgence
            'value'              => 4,
        ]);
        $this->enableTickets(TicketPolicy::THRESHOLD_MAJOR, ['ticket_tickettemplates_id' => $this->templateId]);
        $this->setVersion('boutique_db', '16.4', '17.2', 'major');
        $this->process();
        $this->assertSame(4, (int) $this->ticketOf('boutique_db')->fields['urgency']);
    }

    public function testRegistryCheckCreatesTicketsAndReportsThem(): void
    {
        $this->enableTickets(TicketPolicy::THRESHOLD_MAJOR);
        $registry = json_decode(
            (string) file_get_contents(dirname(__DIR__) . '/mock-registry/fixture.json'),
            true
        );
        $hub = static function (string $url) use ($registry): string {
            preg_match('#/v2/repositories/(.+)/tags$#', (string) parse_url($url, PHP_URL_PATH), $m);
            $tags = $registry['hub'][$m[1] ?? ''] ?? throw new \RuntimeException('HTTP 404 returned');

            return (string) json_encode(['next' => null, 'results' => $tags]);
        };
        $report = (new RegistryImporter(static fn (?Connection $harbor): RegistryClient => RegistryClient::dockerHub(
            'https://hub.exemple.test',
            $hub
        )))->run();
        $this->assertGreaterThanOrEqual(1, $report['tickets']['created']);
        $this->assertStringContainsString('17.2', $this->ticketOf('boutique_db')->fields['name']);
        $this->assertNotSame('', Connection::ticketsMessage($report['tickets']));
    }

    public function testTicketSettingsOnlyOnPortainerConnections(): void
    {
        $connection = new Connection();
        $connection->getFromDB($this->portainerId);
        $connection->update(['id' => $this->portainerId, 'ticket_threshold' => 'nimporte', 'ticket_groups_id' => -3]);
        $connection->getFromDB($this->portainerId);
        $this->assertSame(TicketPolicy::THRESHOLD_MAJOR, $connection->fields['ticket_threshold']);
        $this->assertSame(0, (int) $connection->fields['ticket_groups_id']);

        $wudId = (int) (new Connection())->add([
            'name' => 'WUD tickets', 'type' => Connection::TYPE_WUD, 'entities_id' => $this->entityId,
            'url'  => 'https://wud.exemple.test', 'ticket_enabled' => 1,
        ]);
        $wud = new Connection();
        $wud->getFromDB($wudId);
        $this->assertSame(0, (int) $wud->fields['ticket_enabled']);
        $wud->delete(['id' => $wudId], true);
    }
}

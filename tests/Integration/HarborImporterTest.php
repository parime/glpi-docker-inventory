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
use GlpiPlugin\Dockerinventory\Connection;
use GlpiPlugin\Dockerinventory\Harbor\HarborClient;
use GlpiPlugin\Dockerinventory\Harbor\HarborImporter;
use GlpiPlugin\Dockerinventory\Harbor\HarborLinker;
use GlpiPlugin\Dockerinventory\Harbor\HarborSettings;
use GlpiPlugin\Dockerinventory\HarborArtifact;
use GlpiPlugin\Dockerinventory\Importer;
use GlpiPlugin\Dockerinventory\Portainer\PortainerClient;
use GlpiPlugin\Dockerinventory\Registry\RegistryException;
use GlpiPlugin\Dockerinventory\Service;
use GlpiPlugin\Dockerinventory\Tests\HarborFixture;
use GlpiPlugin\Dockerinventory\Tests\PortainerFixture;
use GlpiPlugin\Dockerinventory\Ticket\VulnTickets;
use ITILFollowup;
use PHPUnit\Framework\TestCase;
use Ticket;

/**
 * Lecture de Harbor contre la vraie base GLPI : services du faux Portainer, artefacts et analyses
 * du faux Harbor (tests/mock-harbor/fixture.json) servis en mémoire, tickets de vulnérabilité.
 */
final class HarborImporterTest extends TestCase
{
    private const HOST = 'registry.exemple.test:5000';

    private int $entityId = 0;

    private int $portainerId = 0;

    private int $harborId = 0;

    /** @var array<string, int> */
    private array $services = [];

    /** @var array<string, mixed> */
    private array $fixture = [];

    protected function setUp(): void
    {
        global $DB;

        $suffix = bin2hex(random_bytes(4));
        $this->entityId = (int) (new \Entity())->add(['name' => 'PHPUnit Harbor ' . $suffix, 'entities_id' => 0]);
        \Session::changeActiveEntities(0, true);
        $this->portainerId = (int) (new Connection())->add([
            'name'        => 'Portainer Harbor PHPUnit ' . $suffix,
            'entities_id' => $this->entityId,
            'url'         => 'https://portainer.exemple.test:9443',
            'api_key'     => 'ptr_fictive_cle_harbor',
        ]);
        $this->harborId = (int) (new Connection())->add([
            'name'          => 'Harbor PHPUnit ' . $suffix,
            'type'          => Connection::TYPE_HARBOR,
            'entities_id'   => $this->entityId,
            'url'           => 'https://harbor.exemple.test',
            'registry_host' => self::HOST,
            'username'      => 'robot$lecture',
            'api_key'       => 'robot_mot_de_passe_fictif',
        ]);
        $this->assertGreaterThan(0, $this->harborId);
        $fixture = PortainerFixture::load();
        $connection = new Connection();
        $connection->getFromDB($this->portainerId);
        (new Importer(static fn (Connection $c): PortainerClient => new PortainerClient(
            'https://portainer.exemple.test:9443',
            'ptr_fictive_cle_harbor',
            static fn (string $url): string => PortainerFixture::respond($fixture, (string) parse_url($url, PHP_URL_PATH))
        )))->import($connection);
        foreach (
            $DB->request(['SELECT' => ['id', 'name'], 'FROM' => Service::getTable(),
            'WHERE' => ['plugin_dockerinventory_connections_id' => $this->portainerId]]) as $row
        ) {
            $this->services[(string) $row['name']] = (int) $row['id'];
        }
        $this->fixture = HarborFixture::load();
        HarborArtifact::resetCache();
    }

    protected function tearDown(): void
    {
        global $DB;

        $ids = array_values($this->services);
        if ($ids !== []) {
            $DB->delete(VulnTickets::TABLE, ['plugin_dockerinventory_services_id' => $ids]);
            $DB->delete('glpi_plugin_dockerinventory_services_nodes', ['plugin_dockerinventory_services_id' => $ids]);
        }
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
        $DB->delete(HarborArtifact::getTable(), ['plugin_dockerinventory_connections_id' => $this->harborId]);
        $DB->delete(HarborImporter::PROJECTS_TABLE, ['plugin_dockerinventory_connections_id' => $this->harborId]);
        (new Connection())->delete(['id' => $this->portainerId], true);
        (new Connection())->delete(['id' => $this->harborId], true);
        (new \Entity())->delete(['id' => $this->entityId], true);
        HarborArtifact::resetCache();
        $_SESSION['MESSAGE_AFTER_REDIRECT'] = [];
    }

    /**
     * @param array<string, string> $fail
     * @param list<string>|null $log
     * @return array<string, mixed>
     */
    private function import(array $fail = [], ?array &$log = null, bool $tickets = false): array
    {
        $harbor = new Connection();
        $harbor->getFromDB($this->harborId);
        // Transport créé ici : la fonction fléchée ne garderait pas la référence au journal.
        $transport = HarborFixture::transport($this->fixture, $fail, $log);
        $report = (new HarborImporter(
            static fn (Connection $c): HarborClient => new HarborClient(
                'https://harbor.exemple.test',
                'robot$lecture',
                'robot_mot_de_passe_fictif',
                $transport
            ),
            $tickets
        ))->import($harbor);
        HarborArtifact::resetCache();

        return $report;
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(string $repository, string $digestStart): array
    {
        global $DB;

        $row = $DB->request(['FROM' => HarborArtifact::getTable(), 'WHERE' => [
            'plugin_dockerinventory_connections_id' => $this->harborId,
            'repository'                            => $repository,
            'digest'                                => ['LIKE', 'sha256:' . $digestStart . '%'],
        ]])->current();
        $this->assertIsArray($row, $repository . ' ' . $digestStart);

        return $row;
    }

    private function countArtifacts(bool $retired): int
    {
        return countElementsInTable(HarborArtifact::getTable(), [
            'plugin_dockerinventory_connections_id' => $this->harborId,
            'is_retired'                            => $retired ? 1 : 0,
        ]);
    }

    public function testImportProjectsArtifactsAndScans(): void
    {
        $log = [];
        $report = $this->import([], $log);

        $this->assertSame(Connection::STATUS_OK, $report['status']);
        $this->assertSame(3, $report['projects']);
        $this->assertSame(7, $report['repositories']);
        $this->assertSame(9 + 130, $report['artifacts']);
        $this->assertSame(6, $report['scanned']);
        $this->assertSame(139, $this->countArtifacts(false));
        // Pagination : 130 artefacts générés => deux pages de 100.
        $this->assertSame(2, count(array_filter($log, static fn (string $p): bool => str_contains($p, 'ci-cache'))));
        $this->assertContains('/api/v2.0/projects/archives/repositories/anciens%252Fportail/artifacts', $log);

        $wiki = $this->artifact('outils/wiki', 'dddd');
        $this->assertSame('outils', $wiki['project']);
        $this->assertSame('3.2.0, 3.2', $wiki['tags']);
        $this->assertSame('success', $wiki['scan_status']);
        $this->assertSame('critical', $wiki['severity']);
        $this->assertSame([1, 3, 5, 9, 6], [(int) $wiki['critical'], (int) $wiki['high'], (int) $wiki['medium'],
            (int) $wiki['low'], (int) $wiki['fixable']]);
        $this->assertSame(187654321, (int) $wiki['size']);
        $this->assertStringStartsWith('Trivy', $wiki['scanner']);
        $this->assertSame('not_scanned', $this->artifact('outils/courrier-test', 'c120')['scan_status']);
        $this->assertSame('running', $this->artifact('boutique/api', 'a1a0')['scan_status']);
        $this->assertSame('', $this->artifact('boutique/api', 'a1a0')['severity']);
        $this->assertSame('error', $this->artifact('archives/anciens/portail', '0a0a')['scan_status']);

        $connection = new Connection();
        $connection->getFromDB($this->harborId);
        $this->assertSame(Connection::STATUS_OK, (int) $connection->fields['last_scan_status']);
        $this->assertStringNotContainsString('robot_mot_de_passe_fictif', (string) $connection->fields['last_scan_report']);

        // Idempotence : aucune ligne en plus, rien de retiré.
        $again = $this->import();
        $this->assertSame(0, $again['retired']);
        $this->assertSame(139, $this->countArtifacts(false));
        $this->assertSame(0, $this->countArtifacts(true));
        $this->assertSame(3, countElementsInTable(HarborImporter::PROJECTS_TABLE, [
            'plugin_dockerinventory_connections_id' => $this->harborId,
        ]));
    }

    public function testServicesAreLinkedByDigestThenTag(): void
    {
        $this->import();
        $service = new Service();

        $service->getFromDB($this->services['wiki']);
        $wiki = HarborArtifact::forService($service);
        $this->assertSame(HarborLinker::METHOD_DIGEST, $wiki['method']);
        $this->assertSame('critical', $wiki['artifact']['severity_code']);
        $this->assertSame('managed', $wiki['provenance_code']);

        // Empreinte déployée sans tag dans Harbor (le tag 3.2.0 a été reconstruit).
        $service->getFromDB($this->services['indexeur']);
        $indexer = HarborArtifact::forService($service);
        $this->assertSame(HarborLinker::METHOD_DIGEST, $indexer['method']);
        $this->assertSame('high', $indexer['artifact']['severity_code']);

        // Service Swarm non épinglé : par le tag.
        $service->getFromDB($this->services['boutique_api']);
        $this->assertSame(HarborLinker::METHOD_TAG, HarborArtifact::forService($service)['method']);

        // Image de Docker Hub : hors registre maîtrisé, sans artefact.
        $service->getFromDB($this->services['boutique_db']);
        $postgres = HarborArtifact::forService($service);
        $this->assertNull($postgres['artifact']);
        $this->assertSame('unmanaged', $postgres['provenance_code']);

        // Liste saisie : Docker Hub déclaré maîtrisé.
        $text = HarborSettings::text();
        try {
            HarborSettings::save("docker.io\n" . self::HOST);
            HarborArtifact::resetCache();
            $this->assertSame('managed', HarborArtifact::forService($service)['provenance_code']);
        } finally {
            HarborSettings::save($text);
            HarborArtifact::resetCache();
        }
    }

    public function testRetiredArtifactsComeBack(): void
    {
        $this->import();
        $original = $this->fixture;
        // Artefact 3.1.0 supprimé de Harbor, projet « archives » supprimé.
        array_pop($this->fixture['projects'][0]['repositories']['wiki']);
        array_pop($this->fixture['projects']);

        $report = $this->import();

        $this->assertSame(2, $report['retired']);
        $this->assertSame(1, (int) $this->artifact('outils/wiki', 'd310')['is_retired']);
        $this->assertSame(1, (int) $this->artifact('archives/anciens/portail', '0a0a')['is_retired']);
        $this->assertSame(1, countElementsInTable(HarborImporter::PROJECTS_TABLE, [
            'plugin_dockerinventory_connections_id' => $this->harborId,
            'name'                                  => 'archives',
            'is_retired'                            => 1,
        ]));

        $this->fixture = $original;
        $this->import();
        $this->assertSame(0, (int) $this->artifact('outils/wiki', 'd310')['is_retired']);
        $this->assertSame(0, $this->countArtifacts(true));
    }

    public function testErrorsDoNotRetireAndRateLimitStops(): void
    {
        $this->import();
        array_pop($this->fixture['projects'][0]['repositories']['wiki']);

        // Erreur sur le dépôt wiki : lecture partielle, l'artefact absent n'est pas retiré.
        $partial = $this->import(['/api/v2.0/projects/outils/repositories/wiki/' => 'HTTP 500 returned']);
        $this->assertSame(Connection::STATUS_PARTIAL, $partial['status']);
        $this->assertSame([['endpoint' => 'outils/wiki', 'code' => RegistryException::SERVER_ERROR]], $partial['errors']);
        $this->assertSame(0, (int) $this->artifact('outils/wiki', 'd310')['is_retired']);
        $this->assertGreaterThan(100, $partial['artifacts'], 'les autres dépôts sont lus');

        // Limite de requêtes dès les dépôts du premier projet : plus aucun appel, rien de retiré.
        $log = [];
        $limited = $this->import(['/api/v2.0/projects/outils/repositories' => 'HTTP 429 returned'], $log);
        $this->assertSame(Connection::STATUS_PARTIAL, $limited['status']);
        $this->assertCount(2, $log, 'projets, puis dépôts du premier projet seulement');
        $this->assertSame(0, $limited['retired']);
        $this->assertSame(0, $this->countArtifacts(true));

        // Liste des projets illisible : échec, rien de modifié.
        $failed = $this->import(['/api/v2.0/projects' => 'HTTP 401 returned']);
        $this->assertSame(Connection::STATUS_ERROR, $failed['status']);
        $this->assertSame(139, $this->countArtifacts(false));
    }

    public function testVulnerabilityTicketsWithoutDuplicates(): void
    {
        $portainer = new Connection();
        $portainer->getFromDB($this->portainerId);
        $this->assertTrue($portainer->update([
            'id'                   => $this->portainerId,
            'ticket_vuln_enabled'  => 1,
            'ticket_vuln_severity' => 'critique ou rien', // valeur invalide => « critique »
        ]));
        $portainer->getFromDB($this->portainerId);
        $this->assertSame('critical', $portainer->fields['ticket_vuln_severity']);

        $report = $this->import([], $log, true);

        // Critiques : wiki (empreinte) et boutique_api (tag) ; indexeur n'a que des hautes.
        $this->assertSame(2, $report['tickets']['created']);
        $wikiTicket = VulnTickets::ticketFor($this->services['wiki']);
        $this->assertNotNull($wikiTicket);
        // Titre traduit selon la langue de l'instance : l'image y figure toujours.
        $this->assertStringContainsString('outils/wiki:3.2.0', $wikiTicket['name']);
        $this->assertSame(1, countElementsInTable(VulnTickets::TABLE, [
            'plugin_dockerinventory_services_id' => $this->services['wiki'],
            'tickets_id'                         => $wikiTicket['id'],
            'critical'                           => 1,
            'high'                               => 3,
        ]));
        $this->assertNotNull(VulnTickets::ticketFor($this->services['boutique_api']));
        $this->assertNull(VulnTickets::ticketFor($this->services['indexeur']));

        // Deuxième lecture : aucun doublon, aucun suivi.
        $again = $this->import([], $log, true);
        $this->assertSame(0, $again['tickets']['created']);
        $this->assertSame(0, $again['tickets']['followups']);

        // Le ticket est pris en charge par quelqu'un : la nouvelle analyse donne un suivi, sans
        // changer son statut.
        $ticket = new Ticket();
        $ticket->update(['id' => $wikiTicket['id'], 'status' => \CommonITILObject::ASSIGNED]);
        $this->fixture['projects'][0]['repositories']['wiki'][0]['scan']['critical'] = 3;
        $followup = $this->import([], $log, true);
        $this->assertSame(1, $followup['tickets']['followups']);
        $ticket->getFromDB($wikiTicket['id']);
        $this->assertSame(\CommonITILObject::ASSIGNED, (int) $ticket->fields['status']);
        $this->assertSame(1, countElementsInTable(ITILFollowup::getTable(), [
            'itemtype' => Ticket::class,
            'items_id' => $wikiTicket['id'],
        ]));

        // Seuil « haute » : l'indexeur reçoit son ticket.
        $portainer->update(['id' => $this->portainerId, 'ticket_vuln_severity' => 'high']);
        $high = $this->import([], $log, true);
        $this->assertSame(1, $high['tickets']['created']);
        $this->assertNotNull(VulnTickets::ticketFor($this->services['indexeur']));

        // Ticket clos pour la même image : pas recréé.
        $ticket->update(['id' => $wikiTicket['id'], 'status' => \CommonITILObject::CLOSED]);
        $closed = $this->import([], $log, true);
        $this->assertSame(0, $closed['tickets']['created']);
        // wiki, boutique_api et indexeur : trois tickets en tout, jamais de doublon.
        $this->assertSame(3, countElementsInTable(Ticket::getTable(), ['entities_id' => $this->entityId]));
    }
}

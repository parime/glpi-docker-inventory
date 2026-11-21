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
use GlpiPlugin\Dockerinventory\Exposure;
use GlpiPlugin\Dockerinventory\Harbor\HarborClient;
use GlpiPlugin\Dockerinventory\Harbor\HarborImporter;
use GlpiPlugin\Dockerinventory\HarborArtifact;
use GlpiPlugin\Dockerinventory\Hardening;
use GlpiPlugin\Dockerinventory\Hardening\HardeningMapper;
use GlpiPlugin\Dockerinventory\HardeningException;
use GlpiPlugin\Dockerinventory\ImageScan;
use GlpiPlugin\Dockerinventory\ImageVersion;
use GlpiPlugin\Dockerinventory\Importer;
use GlpiPlugin\Dockerinventory\Kubernetes\KubernetesClient;
use GlpiPlugin\Dockerinventory\Kubernetes\KubernetesImporter;
use GlpiPlugin\Dockerinventory\Portainer\PortainerClient;
use GlpiPlugin\Dockerinventory\Service;
use GlpiPlugin\Dockerinventory\Tests\HarborFixture;
use GlpiPlugin\Dockerinventory\Tests\KubernetesFixture;
use GlpiPlugin\Dockerinventory\Tests\PortainerFixture;
use GlpiPlugin\Dockerinventory\Wud\WudClient;
use GlpiPlugin\Dockerinventory\Wud\WudImporter;
use PHPUnit\Framework\TestCase;

/**
 * Données piégées importées de Portainer, WUD et Harbor (issues #38 et #43) : noms de service, de
 * stack, de nœud, de secret, de conteneur, étiquettes et analyseur Harbor. Chaque page et chaque
 * onglet qui les affiche doit les restituer échappés (Twig autoescape, aucun |raw). Les charges
 * passent par les vrais importeurs, avec les jeux fictifs des faux serveurs (tests/mock-*).
 */
final class XssTest extends TestCase
{
    private const SCRIPT = '<script>alert("di43")</script>';

    private const IMG = '"><img src=x onerror=alert("di43")>';

    /** Formes actives des charges : jamais dans le HTML produit. */
    private const ACTIVE = ['<script>alert("di43")', '<img src=x onerror'];

    private const HOST = 'registry.exemple.test:5000';

    private int $entityId = 0;

    private int $portainerId = 0;

    private int $wudId = 0;

    private int $harborId = 0;

    private int $kubernetesId = 0;

    /** Session de connexion enregistrée par le test (GLPI 12), supprimée au tearDown. */
    private ?string $trackedSession = null;

    /**
     * GLPI 12 n'accepte une session (Session::checkValidSessionId()) que si elle figure dans
     * glpi_users_sessions, ce qu'il ne fait pas pour la ligne de commande hors environnement de test :
     * la session du test y est enregistrée comme le ferait une connexion web. Sans effet sur GLPI 11.
     * Un refus reste un refus : seule l'expiration de session, sans rapport avec les droits, est évitée.
     */
    private function trackSession(): void
    {
        global $DB;

        $uid = $_SESSION['login_session_uid'] ?? null;
        if (
            !is_string($uid) || !$DB->tableExists('glpi_users_sessions')
            || countElementsInTable('glpi_users_sessions', ['login_session_uid' => $uid]) > 0
        ) {
            return;
        }
        $DB->insert('glpi_users_sessions', [
            'users_id'          => (int) \Session::getLoginUserID(),
            'login_session_uid' => $uid,
            'session_file'      => 'sess_' . session_id(),
            'ip_address'        => '::1',
            'user_agent'        => 'PHPUnit',
            'auth_type'         => \Auth::DB_GLPI,
            'created_at'        => \Session::getCurrentTime(),
            'last_activity_at'  => \Session::getCurrentTime(),
        ]);
        $this->trackedSession = $uid;
    }

    private function untrackSession(): void
    {
        global $DB;

        if ($this->trackedSession !== null) {
            $DB->delete('glpi_users_sessions', ['login_session_uid' => $this->trackedSession]);
            $this->trackedSession = null;
        }
    }

    protected function setUp(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $this->trackSession();
        $this->entityId = (int) (new \Entity())->add(['name' => 'PHPUnit XSS ' . $suffix, 'entities_id' => 0]);
        \Session::changeActiveEntities(0, true);
        $this->portainerId = (int) (new Connection())->add([
            'name'        => 'Portainer XSS ' . $suffix,
            'entities_id' => $this->entityId,
            'url'         => 'https://portainer.exemple.test:9443',
            'api_key'     => 'ptr_fictive_cle_xss',
        ]);
        $this->wudId = (int) (new Connection())->add([
            'name'        => 'WUD XSS ' . $suffix,
            'type'        => Connection::TYPE_WUD,
            'entities_id' => $this->entityId,
            'url'         => 'https://wud.exemple.test:3000/',
        ]);
        $this->harborId = (int) (new Connection())->add([
            'name'          => 'Harbor XSS ' . $suffix,
            'type'          => Connection::TYPE_HARBOR,
            'entities_id'   => $this->entityId,
            'url'           => 'https://harbor.exemple.test',
            'registry_host' => self::HOST,
            'username'      => 'robot$lecture',
            'api_key'       => 'robot_mot_de_passe_fictif',
        ]);
        $this->kubernetesId = (int) (new Connection())->add([
            'name'        => 'Kubernetes XSS ' . $suffix,
            'type'        => Connection::TYPE_KUBERNETES,
            'entities_id' => $this->entityId,
            'url'         => 'https://kubernetes.exemple.test:6443',
            'api_key'     => KubernetesFixture::TOKEN,
        ]);
        foreach ([$this->portainerId, $this->wudId, $this->harborId, $this->kubernetesId] as $id) {
            $this->assertGreaterThan(0, $id);
        }
        HarborArtifact::resetCache();
    }

    protected function tearDown(): void
    {
        global $DB;

        $sources = [$this->portainerId, $this->kubernetesId];
        $serviceIds = array_column(iterator_to_array($DB->request(['SELECT' => ['id'], 'FROM' => Service::getTable(),
            'WHERE' => ['plugin_dockerinventory_connections_id' => $sources]]), false), 'id');
        if ($serviceIds !== []) {
            $DB->delete(
                'glpi_plugin_dockerinventory_services_nodes',
                ['plugin_dockerinventory_services_id' => $serviceIds]
            );
            $DB->delete(HardeningException::getTable(), ['plugin_dockerinventory_services_id' => $serviceIds]);
        }
        $DB->delete(ImageScan::getTable(), ['filename' => 'xss-di73.json']);
        foreach (
            $DB->request(['SELECT' => ['appliances_id'], 'FROM' => 'glpi_plugin_dockerinventory_stacks',
            'WHERE' => ['plugin_dockerinventory_connections_id' => $sources]]) as $row
        ) {
            (new Appliance())->delete(['id' => (int) $row['appliances_id']], true);
        }
        foreach (
            [Service::getTable(), 'glpi_plugin_dockerinventory_nodes', 'glpi_plugin_dockerinventory_stacks'] as $table
        ) {
            $DB->delete($table, ['plugin_dockerinventory_connections_id' => $sources]);
        }
        $DB->delete(ImageVersion::getTable(), ['plugin_dockerinventory_connections_id' => $this->wudId]);
        $DB->delete(HarborArtifact::getTable(), ['plugin_dockerinventory_connections_id' => $this->harborId]);
        $DB->delete(HarborImporter::PROJECTS_TABLE, ['plugin_dockerinventory_connections_id' => $this->harborId]);
        foreach ([$this->portainerId, $this->wudId, $this->harborId, $this->kubernetesId] as $id) {
            (new Connection())->delete(['id' => $id], true);
        }
        (new \Entity())->delete(['id' => $this->entityId], true);
        HarborArtifact::resetCache();
        $_GET = $_POST = $_REQUEST = [];
        $_SESSION['MESSAGE_AFTER_REDIRECT'] = [];
        $this->untrackSession();
    }

    private function connection(int $id): Connection
    {
        $connection = new Connection();
        $connection->getFromDB($id);

        return $connection;
    }

    /**
     * Imports Portainer, WUD et Harbor depuis les jeux fictifs piégés.
     */
    private function importPoisoned(): void
    {
        $portainer = PortainerFixture::load();
        foreach ($portainer['docker']['1']['services'] as $i => $service) {
            if ($service['Spec']['Name'] === 'boutique_cache') {
                $portainer['docker']['1']['services'][$i]['Spec']['Name'] = 'boutique_cache' . self::SCRIPT;
                $portainer['docker']['1']['services'][$i]['Spec']['Labels']['com.docker.stack.namespace']
                    = 'boutique' . self::IMG;
                // Route Traefik piégée (#72) : nom du routeur et point d'entrée.
                $router = 'traefik.http.routers.cache' . self::SCRIPT;
                $portainer['docker']['1']['services'][$i]['Spec']['Labels'][$router . '.rule']
                    = 'Host(`cache.exemple.test`)';
                $portainer['docker']['1']['services'][$i]['Spec']['Labels'][$router . '.entrypoints']
                    = 'web' . self::IMG;
            }
            foreach ($service['Spec']['TaskTemplate']['ContainerSpec']['Secrets'] ?? [] as $j => $secret) {
                $portainer['docker']['1']['services'][$i]['Spec']['TaskTemplate']['ContainerSpec']['Secrets'][$j]
                    ['SecretName'] = $secret['SecretName'] . self::IMG;
            }
        }
        $portainer['docker']['1']['nodes'][0]['Description']['Hostname'] = 'swarm-node-01' . self::SCRIPT;
        (new Importer(static fn (Connection $c): PortainerClient => new PortainerClient(
            'https://portainer.exemple.test:9443',
            'ptr_fictive_cle_xss',
            static fn (string $url): string
                => PortainerFixture::respond($portainer, (string) parse_url($url, PHP_URL_PATH))
        )))->import($this->connection($this->portainerId));

        $wud = json_decode((string) file_get_contents(dirname(__DIR__) . '/mock-wud/fixture.json'), true);
        foreach ($wud['containers'] as $i => $container) {
            if ($container['name'] === 'outils-recette-adminer-1') {
                $wud['containers'][$i]['name'] = 'outils-recette-adminer-1' . self::SCRIPT;
            }
        }
        (new WudImporter(static fn (Connection $c): WudClient => new WudClient(
            'https://wud.exemple.test:3000',
            '',
            '',
            static fn (string $url, array $query, array $headers): string => (string) json_encode(
                (string) parse_url($url, PHP_URL_PATH) === '/api/app' ? $wud['app'] : $wud['containers']
            )
        )))->import($this->connection($this->wudId));

        $harbor = HarborFixture::load();
        foreach ($harbor['projects'] as $p => $project) {
            foreach ($project['repositories'] as $repository => $artifacts) {
                foreach ($artifacts as $a => $artifact) {
                    $harbor['projects'][$p]['repositories'][$repository][$a]['tags'][] = 'v1' . self::IMG;
                    if (isset($artifact['scan'])) {
                        $harbor['projects'][$p]['repositories'][$repository][$a]['scan']['scanner']
                            = 'Trivy' . self::SCRIPT;
                    }
                }
            }
        }
        $transport = HarborFixture::transport($harbor);
        (new HarborImporter(static fn (Connection $c): HarborClient => new HarborClient(
            'https://harbor.exemple.test',
            'robot$lecture',
            'robot_mot_de_passe_fictif',
            $transport
        ), false))->import($this->connection($this->harborId));
        HarborArtifact::resetCache();
    }

    /**
     * @param array<string, mixed> $get
     */
    private function open(string $page, array $get = []): string
    {
        $_GET = $get;
        $_REQUEST = $get;
        $level = ob_get_level();
        ob_start();
        try {
            (static function (string $file): void {
                include $file;
            })(dirname(__DIR__, 2) . '/' . $page);
        } finally {
            $output = '';
            while (ob_get_level() > $level) {
                $output = (string) ob_get_clean() . $output;
            }
            $_GET = $_REQUEST = [];
        }

        return $output;
    }

    /**
     * @param callable(): mixed $display
     */
    private function capture(callable $display): string
    {
        ob_start();
        try {
            $display();
        } finally {
            $output = (string) ob_get_clean();
        }

        return $output;
    }

    private function assertEscaped(string $html, string $where, bool $shown = true): void
    {
        foreach (self::ACTIVE as $active) {
            $this->assertStringNotContainsString($active, $html, $where . ' : charge restituée sans échappement');
        }
        if ($shown) {
            // La charge est bien affichée, sous sa forme échappée : le test porte sur un vrai rendu.
            $this->assertMatchesRegularExpression('/&lt;script&gt;alert\(|&lt;img src=x onerror/', $html, $where);
        }
    }

    public function testImportedNamesAreEscapedOnEveryPage(): void
    {
        global $DB;

        $this->importPoisoned();

        // Les charges ont traversé les importeurs tels quels : l'échappement est bien celui du rendu.
        $service = $DB->request(['FROM' => Service::getTable(), 'WHERE' => [
            'plugin_dockerinventory_connections_id' => $this->portainerId,
            'name'                                  => ['LIKE', '%' . self::SCRIPT],
        ]])->current();
        $this->assertIsArray($service);
        $this->assertGreaterThan(0, countElementsInTable(ImageVersion::getTable(), [
            'plugin_dockerinventory_connections_id' => $this->wudId,
            'containers'                            => ['LIKE', '%' . self::SCRIPT . '%'],
        ]));
        $this->assertGreaterThan(0, countElementsInTable(HarborArtifact::getTable(), [
            'plugin_dockerinventory_connections_id' => $this->harborId,
            'tags'                                  => ['LIKE', '%' . self::IMG . '%'],
        ]));

        $this->assertEscaped($this->open('front/version.php'), 'page des versions');
        $this->assertEscaped(
            $this->open('front/version.php', ['view' => 'harbor', 'artifacts' => 'all']),
            'images Harbor'
        );
        $this->assertEscaped($this->open('front/service.form.php', ['id' => (int) $service['id']]), 'fiche service');
        $this->assertEscaped($this->open('front/version.php', ['view' => 'exposure']), 'exposition');
        // Configuration à risque (#71) : détail d'un écart (utilisateur lu sur la plateforme) et
        // justification d'une exception saisie par une personne.
        $DB->update(Service::getTable(), ['hardening' => HardeningMapper::encode([
            ['code' => HardeningMapper::ROOT_USER, 'detail' => self::SCRIPT],
            ['code' => HardeningMapper::PRIVILEGED, 'detail' => ''],
        ])], ['id' => (int) $service['id']]);
        $_SESSION['glpiactiveprofile'][HardeningException::RIGHTNAME] = CREATE | PURGE;
        $this->assertSame('', HardeningException::record(
            (int) $service['id'],
            HardeningMapper::PRIVILEGED,
            self::IMG,
            substr((string) $_SESSION['glpi_currenttime'], 0, 10)
        ));
        $hardening = $this->open('front/version.php', ['view' => 'hardening']);
        $this->assertEscaped($hardening, 'configuration à risque');
        $this->assertStringContainsString('&lt;img src=x onerror', $hardening);
        // Analyse importée (#73) : identifiant, paquet et versions venus d'un fichier déposé.
        $_SESSION['glpiactiveprofile'][ImageScan::RIGHTNAME] = CREATE | PURGE;
        $imported = ImageScan::import((string) json_encode([
            'ArtifactName' => 'nginx:1.27-di73',
            'Results'      => [['Vulnerabilities' => [[
                'VulnerabilityID'  => 'CVE-2099-' . self::SCRIPT,
                'PkgName'          => self::IMG,
                'InstalledVersion' => self::SCRIPT,
                'FixedVersion'     => self::IMG,
                'Severity'         => 'CRITICAL',
            ]]]],
        ]), 'xss-di73.json');
        $this->assertTrue($imported['ok'], $imported['message']);
        $this->assertEscaped($this->open('front/version.php', ['view' => 'scans']), 'analyses importées');
        $this->assertEscaped($this->open('front/service.php'), 'liste des services', false);
        $this->assertEscaped($this->open('front/imageversion.php'), 'recherche des versions', false);
        $this->assertEscaped(
            $this->open('front/version.php', ['view' => 'eol', 'eol' => 'all']),
            'fin de support',
            false
        );
        // Page de configuration du plugin, chaque onglet (#35).
        foreach (['connections', 'registries', 'lifecycle'] as $tab) {
            $this->assertEscaped($this->open('front/config.php', ['tab' => $tab]), 'configuration : ' . $tab, false);
        }

        $item = new Service();
        $this->assertTrue($item->getFromDB((int) $service['id']));
        $this->assertEscaped(
            $this->capture(static fn () => ImageVersion::displayTabContentForItem($item)),
            'onglet Versions du service',
            false
        );
        $this->assertEscaped(
            $this->capture(static fn () => Exposure::displayTabContentForItem($item)),
            'onglet Exposition du service'
        );
        $this->assertEscaped(
            $this->capture(static fn () => Hardening::displayTabContentForItem($item)),
            'onglet Configuration à risque du service'
        );
        $appliance = new Appliance();
        if ((int) $service['appliances_id'] > 0 && $appliance->getFromDB((int) $service['appliances_id'])) {
            $this->assertEscaped(
                $this->capture(static fn () => Service::displayTabContentForItem($appliance)),
                'onglet Services de l\'application'
            );
            $this->assertEscaped(
                $this->capture(static fn () => ImageVersion::displayTabContentForItem($appliance)),
                'onglet Versions de l\'application',
                false
            );
        }
    }

    /**
     * Noms piégés lus dans un cluster Kubernetes : namespace, charge, conteneur, nœud, secret.
     */
    public function testKubernetesNamesAreEscaped(): void
    {
        global $DB;

        $k8s = KubernetesFixture::load();
        foreach ($k8s['deployments'] as $i => $deployment) {
            if ($deployment['metadata']['name'] === 'vitrine') {
                $k8s['deployments'][$i]['metadata']['name'] = 'vitrine' . self::SCRIPT;
                $k8s['deployments'][$i]['metadata']['namespace'] = 'boutique' . self::IMG;
                $k8s['deployments'][$i]['spec']['template']['spec']['imagePullSecrets'][0]['name']
                    = 'registre' . self::IMG;
            }
        }
        $k8s['nodes'][0]['metadata']['name'] = 'k8s-node-01' . self::SCRIPT;
        $k8s['namespaces'][] = ['metadata' => ['name' => 'boutique' . self::IMG]];
        $transport = KubernetesFixture::transport($k8s);
        $report = (new KubernetesImporter(static fn (Connection $c): KubernetesClient => new KubernetesClient(
            'https://kubernetes.exemple.test:6443',
            KubernetesFixture::TOKEN,
            '',
            $transport
        )))->import($this->connection($this->kubernetesId));
        $this->assertGreaterThan(0, $report['services']);

        $service = $DB->request(['FROM' => Service::getTable(), 'WHERE' => [
            'plugin_dockerinventory_connections_id' => $this->kubernetesId,
            'name'                                  => ['LIKE', '%' . self::SCRIPT],
        ]])->current();
        $this->assertIsArray($service);
        $this->assertEscaped(
            $this->open('front/service.form.php', ['id' => (int) $service['id']]),
            'fiche service k8s'
        );
        $this->assertEscaped($this->open('front/version.php'), 'page des versions (k8s)', false);
        $this->assertEscaped($this->open('front/service.php'), 'liste des services (k8s)', false);
        $this->assertEscaped(
            $this->open('front/connection.form.php', ['id' => $this->kubernetesId]),
            'connexion Kubernetes',
            false
        );
        $appliance = new Appliance();
        $this->assertTrue($appliance->getFromDB((int) $service['appliances_id']));
        $this->assertEscaped(
            $this->capture(static fn () => Service::displayTabContentForItem($appliance)),
            'onglet Services du namespace'
        );
    }

    public function testTemplatesNeverDisableAutoescape(): void
    {
        foreach (glob(dirname(__DIR__, 2) . '/templates/*.twig') ?: [] as $template) {
            $source = (string) file_get_contents($template);
            $this->assertDoesNotMatchRegularExpression(
                '/\|\s*raw\b|\{%\s*autoescape\s+false/',
                $source,
                basename($template) . ' : |raw ou autoescape désactivé'
            );
        }
    }
}

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
use Glpi\Exception\RedirectException;
use Glpi\Plugin\Hooks;
use GlpiPlugin\Dockerinventory\ConfigPage;
use GlpiPlugin\Dockerinventory\Connection;
use GlpiPlugin\Dockerinventory\Exposure;
use GlpiPlugin\Dockerinventory\Harbor\HarborSettings;
use GlpiPlugin\Dockerinventory\HarborArtifact;
use GlpiPlugin\Dockerinventory\ImageVersion;
use GlpiPlugin\Dockerinventory\Lifecycle\Settings;
use GlpiPlugin\Dockerinventory\LifecycleProduct;
use GlpiPlugin\Dockerinventory\Menu;
use GlpiPlugin\Dockerinventory\Service;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Matrice d'accès des pages du plugin (issues #38 et #43) : chaque page de front/ est ouverte dans
 * le processus de test (comme GLPI la sert), avec un profil sans droit, un profil en lecture seule
 * et un objet d'une autre entité. Un refus est l'exception HTTP 403/404 que GLPI lève dans
 * Session::checkRight() et CommonDBTM::check(). Assertions sur des codes et des marqueurs, jamais
 * sur des libellés traduits (CI en anglais, instance de développement en français).
 *
 * Toute page de front/ doit figurer dans PAGES : le contrôle commun check-security-matrix-coverage.sh
 * le vérifie en CI, testEveryFrontPageIsInTheMatrix() aussi.
 */
final class AccessControlTest extends TestCase
{
    /** Pages du plugin, toutes exercées ci-dessous. */
    private const PAGES = [
        'front/config.php',
        'front/connection.form.php',
        'front/connection.php',
        'front/imageversion.php',
        'front/lifecycle.php',
        'front/service.form.php',
        'front/service.php',
        'front/version.php',
    ];

    /** Pages réservées aux administrateurs de la configuration (droit config, UPDATE). */
    private const CONFIG_PAGES = ['front/config.php', 'front/connection.form.php', 'front/connection.php'];

    private string $suffix = '';

    private int $entityA = 0;

    private int $entityB = 0;

    private int $serviceA = 0;

    private int $serviceB = 0;

    private int $applianceB = 0;

    private int $connectionB = 0;

    private int $kubernetesB = 0;

    /** @var array<string, int> Connexions Harbor des entités A et B, par lettre. */
    private array $harbors = [];

    /** @var list<int> */
    private array $versions = [];

    /** @var list<int> */
    private array $artifacts = [];

    /** @var array<string, mixed> */
    private array $profile = [];

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
        $this->suffix = bin2hex(random_bytes(4));
        $this->trackSession();
        $this->profile = $_SESSION['glpiactiveprofile'];
        $entity = new \Entity();
        $this->entityA = (int) $entity->add(['name' => 'PHPUnit accès A ' . $this->suffix, 'entities_id' => 0]);
        $this->entityB = (int) $entity->add(['name' => 'PHPUnit accès B ' . $this->suffix, 'entities_id' => 0]);
        \Session::changeActiveEntities(0, true);
        $this->applianceB = (int) (new Appliance())->add([
            'name' => 'appliance-b-' . $this->suffix, 'entities_id' => $this->entityB,
        ]);
        $this->serviceA = (int) (new Service())->add([
            'name' => 'service-a-' . $this->suffix, 'service_uid' => 'a-' . $this->suffix,
            'entities_id' => $this->entityA, 'image' => 'nginx', 'ports' => '8443:443/tcp',
        ]);
        $this->serviceB = (int) (new Service())->add([
            'name'          => 'service-b-' . $this->suffix,
            'service_uid'   => 'b-' . $this->suffix,
            'entities_id'   => $this->entityB,
            'appliances_id' => $this->applianceB,
            'image'         => 'nginx',
            'ports'         => '8443:443/tcp',
        ]);
        $this->connectionB = (int) (new Connection())->add([
            'name'        => 'portainer-b-' . $this->suffix,
            'entities_id' => $this->entityB,
            'url'         => 'https://portainer-b.exemple.test:9443',
            'api_key'     => 'ptr_fictive_cle_acces',
        ]);
        foreach ([$this->entityA => $this->serviceA, $this->entityB => $this->serviceB] as $entity => $service) {
            $letter = $entity === $this->entityA ? 'a' : 'b';
            $this->versions[] = (int) (new ImageVersion())->add([
                'entities_id'                        => $entity,
                'plugin_dockerinventory_services_id' => $service,
                'image'                              => 'image-' . $letter . '-' . $this->suffix,
                'deployed_tag'                       => '1.0.0',
            ]);
            $this->artifacts[] = (int) (new HarborArtifact())->add([
                'entities_id' => $entity,
                'project'     => 'projet-' . $letter . '-' . $this->suffix,
                'repository'  => 'projet-' . $letter . '-' . $this->suffix . '/depot',
                'digest'      => 'sha256:' . str_repeat($letter, 64),
            ]);
        }
        $this->kubernetesB = (int) (new Connection())->add([
            'name'        => 'kubernetes-b-' . $this->suffix,
            'type'        => Connection::TYPE_KUBERNETES,
            'entities_id' => $this->entityB,
            'url'         => 'https://kubernetes-b.exemple.test:6443',
            'api_key'     => 'jeton_fictif_kubernetes',
        ]);
        foreach ([$this->serviceA, $this->serviceB, $this->connectionB, $this->kubernetesB, $this->applianceB] as $id) {
            $this->assertGreaterThan(0, $id);
        }
        foreach (['a' => $this->entityA, 'b' => $this->entityB] as $letter => $entity) {
            $this->harbors[$letter] = (int) (new Connection())->add([
                'name'          => 'harbor-' . $letter . '-' . $this->suffix,
                'type'          => Connection::TYPE_HARBOR,
                'entities_id'   => $entity,
                'url'           => 'https://harbor-' . $letter . '.exemple.test',
                'registry_host' => $this->harborHost($letter),
                'username'      => 'robot$lecture',
                'api_key'       => 'robot_mot_de_passe_fictif',
            ]);
            $this->assertGreaterThan(0, $this->harbors[$letter]);
        }
        HarborArtifact::resetCache();
    }

    private function harborHost(string $letter): string
    {
        return 'harbor-' . $letter . '-' . $this->suffix . '.exemple.test';
    }

    protected function tearDown(): void
    {
        $_SESSION['glpiactiveprofile'] = $this->profile;
        \Session::changeActiveEntities(0, true);
        $_GET = $_POST = $_REQUEST = [];
        foreach ($this->versions as $id) {
            (new ImageVersion())->delete(['id' => $id], true);
        }
        foreach ($this->artifacts as $id) {
            (new HarborArtifact())->delete(['id' => $id], true);
        }
        (new Service())->delete(['id' => $this->serviceA], true);
        (new Service())->delete(['id' => $this->serviceB], true);
        (new Connection())->delete(['id' => $this->connectionB], true);
        (new Connection())->delete(['id' => $this->kubernetesB], true);
        foreach ($this->harbors as $id) {
            (new Connection())->delete(['id' => $id], true);
        }
        (new Appliance())->delete(['id' => $this->applianceB], true);
        (new \Entity())->delete(['id' => $this->entityA], true);
        (new \Entity())->delete(['id' => $this->entityB], true);
        HarborArtifact::resetCache();
        $_SESSION['MESSAGE_AFTER_REDIRECT'] = [];
        $this->untrackSession();
    }

    /**
     * Ouvre une page du plugin comme GLPI la sert et renvoie le HTML produit.
     *
     * @param array<string, mixed> $get
     * @param array<string, mixed> $post
     */
    private function open(string $page, array $get = [], array $post = []): string
    {
        $_GET = $get;
        $_POST = $post;
        $_REQUEST = $post + $get;
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
            $_GET = $_POST = $_REQUEST = [];
        }

        return $output;
    }

    /**
     * @param array<string, mixed> $get
     * @param array<string, mixed> $post
     */
    private function assertRefused(string $page, array $get = [], array $post = []): void
    {
        try {
            $this->open($page, $get, $post);
        } catch (HttpExceptionInterface $e) {
            $this->assertContains($e->getStatusCode(), [403, 404], $page);

            return;
        }
        $this->fail($page . ' ' . json_encode($get + $post) . ' : page ouverte au lieu d\'être refusée');
    }

    /**
     * Adresse vers laquelle une page redirige (Html::redirect() lève RedirectException).
     *
     * @param array<string, mixed> $get
     * @param array<string, mixed> $post
     */
    private function redirectionOf(string $page, array $get = [], array $post = []): string
    {
        try {
            $this->open($page, $get, $post);
        } catch (RedirectException $e) {
            return $e->getResponse()->getTargetUrl();
        }
        $this->fail($page . ' : pas de redirection');
    }

    private function setRights(int $service, int $config): void
    {
        $_SESSION['glpiactiveprofile'][Service::RIGHTNAME] = $service;
        $_SESSION['glpiactiveprofile'][Connection::RIGHTNAME] = $config;
    }

    public function testEveryFrontPageIsInTheMatrix(): void
    {
        $root = dirname(__DIR__, 2);
        $pages = array_map(
            static fn (string $file): string => substr($file, strlen($root) + 1),
            array_merge(glob($root . '/front/*.php') ?: [], glob($root . '/ajax/*.php') ?: [])
        );
        sort($pages);
        $this->assertSame(self::PAGES, $pages);
    }

    /**
     * Chaque page, et chaque action POST, avec ses paramètres.
     *
     * @return iterable<string, array{string, array<string, mixed>, array<string, mixed>}>
     */
    public static function requests(): iterable
    {
        foreach (['', 'connections', 'registries', 'lifecycle', 'inconnu'] as $tab) {
            yield 'configuration : onglet ' . $tab => ['front/config.php', ['tab' => $tab], []];
        }
        yield 'configuration : registres maîtrisés' => [
            'front/config.php', [], ['save_managed_registries' => 1, 'managed_registries' => 'pirate.exemple.test'],
        ];
        foreach (['save_settings', 'refresh', 'save_mappings'] as $action) {
            yield 'configuration : ' . $action => ['front/config.php', [], [$action => 1, 'enabled' => 1]];
        }
        yield 'ancienne liste des connexions' => ['front/connection.php', [], []];
        yield 'ancienne liste des connexions (POST)' => [
            'front/connection.php', [], ['save_managed_registries' => 1, 'managed_registries' => 'pirate.exemple.test'],
        ];
        yield 'nouvelle connexion' => ['front/connection.form.php', [], []];
        yield 'nouvelle connexion Kubernetes' => ['front/connection.form.php', ['type' => 'kubernetes'], []];
        foreach (['add', 'update', 'purge', 'test', 'import', 'harbor_import', 'registry_check'] as $action) {
            yield 'connexion : ' . $action => ['front/connection.form.php', [], [$action => 1, 'id' => 1]];
        }
        yield 'recherche des versions' => ['front/imageversion.php', [], []];
        yield 'ancienne fin de support' => ['front/lifecycle.php', [], []];
        foreach (['save_settings', 'refresh', 'save_mappings'] as $action) {
            yield 'ancienne fin de support : ' . $action => ['front/lifecycle.php', [], [$action => 1, 'enabled' => 1]];
        }
        yield 'fiche service' => ['front/service.form.php', ['id' => 1], []];
        yield 'liste des services' => ['front/service.php', [], []];
        yield 'page des versions' => ['front/version.php', [], []];
        yield 'images Harbor' => ['front/version.php', ['view' => 'harbor'], []];
        yield 'fin de support' => ['front/version.php', ['view' => 'eol'], []];
        yield 'fin de support, tous les états' => ['front/version.php', ['view' => 'eol', 'eol' => 'all'], []];
        yield 'exposition' => ['front/version.php', ['view' => 'exposure'], []];
        yield 'exposition sur Internet' => ['front/version.php', ['view' => 'exposure', 'filter' => 'internet'], []];
    }

    /**
     * @param array<string, mixed> $get
     * @param array<string, mixed> $post
     */
    #[DataProvider('requests')]
    public function testProfileWithoutRightIsRefused(string $page, array $get, array $post): void
    {
        $settings = \Config::getConfigurationValues(Settings::CONTEXT);
        $this->setRights(0, 0);
        $this->assertRefused($page, $get, $post);
        // Aucune action n'a eu lieu (réglages de la fin de support inchangés).
        $this->assertSame($settings, \Config::getConfigurationValues(Settings::CONTEXT));
    }

    public function testReadOnlyProfileSeesInventoryButNotConfiguration(): void
    {
        $this->setRights(READ, 0);
        foreach (self::CONFIG_PAGES as $page) {
            $this->assertRefused($page);
        }
        $this->assertRefused('front/connection.form.php', [], ['import' => 1, 'id' => $this->connectionB]);
        $this->assertRefused('front/connection.form.php', [], ['test' => 1, 'id' => $this->kubernetesB]);
        $this->assertRefused('front/connection.form.php', ['type' => 'kubernetes']);
        $this->assertFalse(Connection::canView());
        $this->assertFalse(LifecycleProduct::canView());
        foreach (ConfigPage::TABS as $tab) {
            $this->assertRefused('front/config.php', ['tab' => $tab]);
        }
        // Ancien favori de la fin de support : la sous-page de lecture, sans aucune action (#35).
        $settings = \Config::getConfigurationValues(Settings::CONTEXT);
        $this->assertSame(ImageVersion::getEolURL(), $this->redirectionOf('front/lifecycle.php'));
        $this->assertSame(
            ImageVersion::getEolURL(),
            $this->redirectionOf('front/lifecycle.php', [], ['save_settings' => 1, 'enabled' => 1])
        );
        $this->assertSame($settings, \Config::getConfigurationValues(Settings::CONTEXT));
        // Une seule entrée de menu ; aucun lien vers la configuration pour ce profil.
        $menu = Menu::getMenuContent();
        $this->assertIsArray($menu);
        $this->assertStringEndsWith('/front/service.php', $menu['page']);
        $eol = $this->open('front/version.php', ['view' => 'eol']);
        $this->assertStringContainsString('ti-calendar-x', $eol);
        $this->assertStringNotContainsString('front/config.php', $eol);
        $this->assertStringNotContainsString('front/config.php', $this->open('front/version.php'));

        $this->assertStringContainsString('service-a-' . $this->suffix, $this->open('front/version.php'));
        $this->assertStringContainsString(
            'projet-a-' . $this->suffix,
            $this->open('front/version.php', ['view' => 'harbor', 'artifacts' => 'all'])
        );
        $this->assertStringContainsString(
            'service-a-' . $this->suffix,
            $this->open('front/service.form.php', ['id' => $this->serviceA])
        );
        $this->assertNotSame('', $this->open('front/service.php'));
        $this->assertNotSame('', $this->open('front/imageversion.php'));
        // Exposition (#72) : lecture seule, sans lien vers la configuration.
        $exposure = $this->open('front/version.php', ['view' => 'exposure']);
        $this->assertStringContainsString('service-a-' . $this->suffix, $exposure);
        $this->assertStringContainsString('ti-world-share', $exposure);
        $this->assertStringNotContainsString('front/config.php', $exposure);
    }

    /**
     * Charte commune, section 9 (#35) : une seule entrée de menu d'usage, réglages dans la page de
     * configuration du plugin, anciennes adresses redirigées (favoris).
     */
    public function testSingleMenuEntryAndOldAddressesRedirect(): void
    {
        global $PLUGIN_HOOKS;

        $this->assertSame(['assets' => [Menu::class]], $PLUGIN_HOOKS[Hooks::MENU_TOADD]['dockerinventory']);
        $this->assertSame('front/config.php', $PLUGIN_HOOKS[Hooks::CONFIG_PAGE]['dockerinventory']);

        $this->setRights(READ, READ | UPDATE);
        $this->assertSame(ConfigPage::url(), $this->redirectionOf('front/connection.php'));
        $this->assertSame(
            ConfigPage::url() . '?is_deleted=0&start=20',
            $this->redirectionOf('front/connection.php', ['is_deleted' => '0', 'start' => '20', 'tab' => 'x'])
        );
        $this->assertSame(LifecycleProduct::getPageURL(), $this->redirectionOf('front/lifecycle.php'));
        $this->assertSame(
            LifecycleProduct::getPageURL(true, ['add' => 'postgres']),
            $this->redirectionOf('front/lifecycle.php', ['add' => 'postgres'])
        );
        $this->assertStringContainsString('tab=lifecycle', LifecycleProduct::getPageURL());
        $this->assertSame(ConfigPage::url(), Connection::getSearchURL());

        // Page de configuration : chaque onglet s'ouvre, avec la barre d'onglets.
        foreach (ConfigPage::TABS as $tab) {
            $html = $this->open('front/config.php', ['tab' => $tab]);
            $this->assertStringContainsString('aria-current="page"', $html, $tab);
            foreach (ConfigPage::TABS as $other) {
                $this->assertStringContainsString(htmlescape(ConfigPage::url($other)), $html, $tab);
            }
        }

        // Sous-pages de l'entrée Parc > Inventaire Docker : la même barre d'onglets partout.
        $pages = [
            ['front/service.php', []],
            ['front/version.php', []],
            ['front/version.php', ['view' => 'harbor']],
            ['front/version.php', ['view' => 'eol']],
            ['front/version.php', ['view' => 'exposure']],
        ];
        foreach ($pages as [$page, $get]) {
            $html = $this->open($page, $get);
            foreach (Menu::tabs('') as $tab) {
                $this->assertStringContainsString(htmlescape($tab['url']), $html, $page . json_encode($get));
            }
        }

        // Administrateur sans droit sur l'inventaire : pas d'entrée Parc, mais la configuration.
        $this->setRights(0, READ | UPDATE);
        $this->assertFalse(Menu::getMenuContent());
        $this->assertSame(LifecycleProduct::getPageURL(), $this->redirectionOf('front/lifecycle.php'));
    }

    public function testObjectOfAnotherEntityIsRefused(): void
    {
        \Session::changeActiveEntities($this->entityA, false);
        // Profil administrateur : seule l'entité arrête l'accès.
        $this->setRights(READ, READ | UPDATE);

        $this->assertTrue((new Service())->can($this->serviceA, READ));
        $this->assertFalse((new Service())->can($this->serviceB, READ));
        $this->assertFalse((new Connection())->can($this->connectionB, READ));

        $this->assertRefused('front/service.form.php', ['id' => $this->serviceB]);
        $this->assertRefused('front/connection.form.php', ['id' => $this->connectionB]);
        $renamed = 'renomme-' . $this->suffix;
        foreach (['update', 'test', 'import', 'harbor_import', 'purge'] as $action) {
            $this->assertRefused(
                'front/connection.form.php',
                [],
                [$action => 1, 'id' => $this->connectionB, 'name' => $renamed]
            );
        }
        $connection = new Connection();
        $this->assertTrue($connection->getFromDB($this->connectionB));
        $this->assertSame('portainer-b-' . $this->suffix, $connection->fields['name']);

        // Connexion Kubernetes d'une autre entité : ni fiche, ni test, ni import (#31).
        $this->assertFalse((new Connection())->can($this->kubernetesB, READ));
        $this->assertRefused('front/connection.form.php', ['id' => $this->kubernetesB]);
        foreach (['update', 'test', 'import', 'purge'] as $action) {
            $this->assertRefused(
                'front/connection.form.php',
                [],
                [$action => 1, 'id' => $this->kubernetesB, 'name' => $renamed]
            );
        }
        $this->assertTrue($connection->getFromDB($this->kubernetesB));
        $this->assertSame('kubernetes-b-' . $this->suffix, $connection->fields['name']);
        $this->assertNull($connection->fields['last_import_date']);

        // Listes et onglets : rien de l'entité B.
        $versions = $this->open('front/version.php');
        $this->assertStringContainsString('service-a-' . $this->suffix, $versions);
        $this->assertStringNotContainsString('service-b-' . $this->suffix, $versions);
        $this->assertStringNotContainsString('image-b-' . $this->suffix, $versions);
        $exposure = $this->open('front/version.php', ['view' => 'exposure']);
        $this->assertStringContainsString('service-a-' . $this->suffix, $exposure);
        $this->assertStringNotContainsString('service-b-' . $this->suffix, $exposure);
        $this->assertSame([$this->serviceA], array_column(Exposure::overview()['services'], 'id'));
        $serviceB = new Service();
        $this->assertTrue($serviceB->getFromDB($this->serviceB));
        $this->assertFalse(Exposure::displayTabContentForItem($serviceB));
        $harbor = $this->open('front/version.php', ['view' => 'harbor', 'artifacts' => 'all']);
        $this->assertStringContainsString('projet-a-' . $this->suffix, $harbor);
        $this->assertStringNotContainsString('projet-b-' . $this->suffix, $harbor);
        // Registres maîtrisés (#48) : seuls les hôtes des connexions Harbor de l'entité A sont
        // affichés ; la provenance des images reste évaluée avec tous les hôtes.
        $this->assertStringNotContainsString($this->harborHost('b'), $harbor);
        $this->assertStringNotContainsString(
            $this->harborHost('b'),
            $this->open('front/config.php', ['tab' => ConfigPage::TAB_REGISTRIES])
        );
        $this->assertContains($this->harborHost('a'), HarborArtifact::visibleHarborHosts());
        $this->assertNotContains($this->harborHost('b'), HarborArtifact::visibleHarborHosts());
        $this->assertNotContains($this->harborHost('b'), HarborArtifact::visibleManagedHosts());
        $overview = HarborArtifact::overview('', false, '', false);
        $this->assertNotContains($this->harborHost('b'), $overview['managed_hosts']);
        if (HarborSettings::configuredHosts() === []) {
            $this->assertContains($this->harborHost('b'), HarborArtifact::managedHosts());
            $this->assertStringContainsString($this->harborHost('a'), $harbor);
        }
        $images = array_column(ImageVersion::fetch([]), 'image');
        $this->assertContains('image-a-' . $this->suffix, $images);
        $this->assertNotContains('image-b-' . $this->suffix, $images);

        $appliance = new Appliance();
        $this->assertTrue($appliance->getFromDB($this->applianceB));
        $serviceB = new Service();
        $this->assertTrue($serviceB->getFromDB($this->serviceB));
        ob_start();
        try {
            $this->assertFalse(Service::displayTabContentForItem($appliance));
            $this->assertFalse(ImageVersion::displayTabContentForItem($appliance));
            $this->assertFalse(ImageVersion::displayTabContentForItem($serviceB));
        } finally {
            $this->assertSame('', ob_get_clean());
        }
    }
}

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
use GlpiPlugin\Dockerinventory\HarborArtifact;
use GlpiPlugin\Dockerinventory\ImageVersion;
use GlpiPlugin\Dockerinventory\Lifecycle\Settings;
use GlpiPlugin\Dockerinventory\LifecycleProduct;
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
 * Toute page de front/ doit figurer dans PAGES : .github/scripts/check-security-matrix-coverage.sh
 * le vérifie en CI, testEveryFrontPageIsInTheMatrix() aussi.
 */
final class AccessControlTest extends TestCase
{
    /** Pages du plugin, toutes exercées ci-dessous. */
    private const PAGES = [
        'front/connection.form.php',
        'front/connection.php',
        'front/imageversion.php',
        'front/lifecycle.php',
        'front/service.form.php',
        'front/service.php',
        'front/version.php',
    ];

    /** Pages réservées aux administrateurs de la configuration (droit config, UPDATE). */
    private const CONFIG_PAGES = ['front/connection.form.php', 'front/connection.php', 'front/lifecycle.php'];

    private string $suffix = '';

    private int $entityA = 0;

    private int $entityB = 0;

    private int $serviceA = 0;

    private int $serviceB = 0;

    private int $applianceB = 0;

    private int $connectionB = 0;

    /** @var list<int> */
    private array $versions = [];

    /** @var list<int> */
    private array $artifacts = [];

    /** @var array<string, mixed> */
    private array $profile = [];

    protected function setUp(): void
    {
        $this->suffix = bin2hex(random_bytes(4));
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
            'entities_id' => $this->entityA, 'image' => 'nginx',
        ]);
        $this->serviceB = (int) (new Service())->add([
            'name'          => 'service-b-' . $this->suffix,
            'service_uid'   => 'b-' . $this->suffix,
            'entities_id'   => $this->entityB,
            'appliances_id' => $this->applianceB,
            'image'         => 'nginx',
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
        foreach ([$this->serviceA, $this->serviceB, $this->connectionB, $this->applianceB] as $id) {
            $this->assertGreaterThan(0, $id);
        }
        HarborArtifact::resetCache();
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
        (new Appliance())->delete(['id' => $this->applianceB], true);
        (new \Entity())->delete(['id' => $this->entityA], true);
        (new \Entity())->delete(['id' => $this->entityB], true);
        HarborArtifact::resetCache();
        $_SESSION['MESSAGE_AFTER_REDIRECT'] = [];
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
        yield 'liste des connexions' => ['front/connection.php', [], []];
        yield 'registres maîtrisés' => [
            'front/connection.php', [], ['save_managed_registries' => 1, 'managed_registries' => 'pirate.exemple.test'],
        ];
        yield 'nouvelle connexion' => ['front/connection.form.php', [], []];
        foreach (['add', 'update', 'purge', 'test', 'import', 'harbor_import', 'registry_check'] as $action) {
            yield 'connexion : ' . $action => ['front/connection.form.php', [], [$action => 1, 'id' => 1]];
        }
        yield 'recherche des versions' => ['front/imageversion.php', [], []];
        yield 'fin de support' => ['front/lifecycle.php', [], []];
        foreach (['save_settings', 'refresh', 'save_mappings'] as $action) {
            yield 'fin de support : ' . $action => ['front/lifecycle.php', [], [$action => 1, 'enabled' => 1]];
        }
        yield 'fiche service' => ['front/service.form.php', ['id' => 1], []];
        yield 'liste des services' => ['front/service.php', [], []];
        yield 'page des versions' => ['front/version.php', [], []];
        yield 'images Harbor' => ['front/version.php', ['view' => 'harbor'], []];
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
        $this->assertFalse(Connection::canView());
        $this->assertFalse(LifecycleProduct::canView());

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

        // Listes et onglets : rien de l'entité B.
        $versions = $this->open('front/version.php');
        $this->assertStringContainsString('service-a-' . $this->suffix, $versions);
        $this->assertStringNotContainsString('service-b-' . $this->suffix, $versions);
        $this->assertStringNotContainsString('image-b-' . $this->suffix, $versions);
        $harbor = $this->open('front/version.php', ['view' => 'harbor', 'artifacts' => 'all']);
        $this->assertStringContainsString('projet-a-' . $this->suffix, $harbor);
        $this->assertStringNotContainsString('projet-b-' . $this->suffix, $harbor);
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

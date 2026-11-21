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

/**
 * Jeu de données des contrôles de sécurité communs (parime/glpi-plugin-security), lancé avant la
 * désinstallation pour que le contrôle des restes porte sur de vraies données : une connexion
 * Portainer, une connexion WUD et une connexion Harbor, un import complet depuis les jeux fictifs
 * des faux Portainer, WUD et registre (versions des images, cache de Docker Hub), des recherches
 * enregistrées et des préférences d'affichage personnelles sur les services et les versions.
 * Données fictives uniquement ; le vrai Docker Hub n'est jamais appelé.
 *
 * Usage (dossier de GLPI, utilisateur www-data) : php plugins/dockerinventory/tests/security/setup.php
 */

use GlpiPlugin\Dockerinventory\Connection;
use GlpiPlugin\Dockerinventory\ImageScan;
use GlpiPlugin\Dockerinventory\ImageVersion;
use GlpiPlugin\Dockerinventory\Importer;
use GlpiPlugin\Dockerinventory\Portainer\PortainerClient;
use GlpiPlugin\Dockerinventory\Registry\RegistryClient;
use GlpiPlugin\Dockerinventory\Registry\RegistryImporter;
use GlpiPlugin\Dockerinventory\Service;
use GlpiPlugin\Dockerinventory\Wud\WudClient;
use GlpiPlugin\Dockerinventory\Wud\WudImporter;

require '/var/www/glpi/vendor/autoload.php';
$kernel = new \Glpi\Kernel\Kernel();
$GLOBALS['kernel'] = $kernel;
$kernel->boot();
Session::start();
if (!(new Auth())->login('glpi', 'glpi', false)) {
    fwrite(STDERR, "Connexion glpi/glpi impossible\n");
    exit(1);
}

$fixture = json_decode((string) file_get_contents(dirname(__DIR__) . '/mock-portainer/fixture.json'), true);
$connection = new Connection();
$id = (int) $connection->add([
    'name'        => 'Portainer fictif (sécurité)',
    'entities_id' => 0,
    'url'         => 'https://portainer.exemple.test:9443',
    'api_key'     => 'ptr_fictive_cle_securite',
]);
$connection->getFromDB($id);
$report = (new Importer(static fn (Connection $c): PortainerClient => new PortainerClient(
    'https://portainer.exemple.test:9443',
    'ptr_fictive_cle_securite',
    static function (string $url) use ($fixture): string {
        $path = (string) parse_url($url, PHP_URL_PATH);
        if ($path === '/api/endpoints' || $path === '/api/stacks') {
            return (string) json_encode($fixture[$path === '/api/stacks' ? 'stacks' : 'endpoints']);
        }
        preg_match('#^/api/endpoints/(\d+)/docker/(\w+)$#', $path, $m);
        $endpoint = $fixture['docker'][$m[1] ?? ''] ?? ['swarm' => false];
        if (!$endpoint['swarm']) {
            throw new RuntimeException('HTTP 503 returned');
        }

        return (string) json_encode($endpoint[$m[2]]);
    }
)))->import($connection);

$userId = (int) Session::getLoginUserID();
(new SavedSearch())->add([
    'name'        => 'Services retirés (sécurité)',
    'type'        => SavedSearch::SEARCH,
    'itemtype'    => Service::class,
    'users_id'    => $userId,
    'entities_id' => 0,
    'query'       => 'criteria[0][field]=10&criteria[0][searchtype]=equals&criteria[0][value]=1',
]);
global $DB;
$DB->insert(DisplayPreference::getTable(), [
    'itemtype' => Service::class,
    'num'      => 14,
    'rank'     => 1,
    'users_id' => $userId,
] + ($DB->fieldExists(DisplayPreference::getTable(), 'interface') ? ['interface' => 'central'] : []));

$wudFixture = json_decode((string) file_get_contents(dirname(__DIR__) . '/mock-wud/fixture.json'), true);
$wud = new Connection();
$wudId = (int) $wud->add([
    'name'        => 'WUD fictif (sécurité)',
    'type'        => Connection::TYPE_WUD,
    'entities_id' => 0,
    'url'         => 'https://wud.exemple.test:3000',
    'username'    => 'lecteur',
    'api_key'     => 'wud_mot_de_passe_securite',
]);
$wud->getFromDB($wudId);
$wudReport = (new WudImporter(static fn (Connection $c): WudClient => new WudClient(
    'https://wud.exemple.test:3000',
    'lecteur',
    'wud_mot_de_passe_securite',
    static fn (string $url): string => (string) json_encode(
        str_ends_with($url, '/api/app') ? $wudFixture['app'] : $wudFixture['containers']
    )
)))->import($wud);
(new SavedSearch())->add([
    'name'        => 'Versions majeures (sécurité)',
    'type'        => SavedSearch::SEARCH,
    'itemtype'    => ImageVersion::class,
    'users_id'    => $userId,
    'entities_id' => 0,
    'query'       => 'criteria[0][field]=8&criteria[0][searchtype]=equals&criteria[0][value]=major',
]);
$DB->insert(DisplayPreference::getTable(), [
    'itemtype' => ImageVersion::class,
    'num'      => 13,
    'rank'     => 1,
    'users_id' => $userId,
] + ($DB->fieldExists(DisplayPreference::getTable(), 'interface') ? ['interface' => 'central'] : []));

// Harbor (compte robot fictif) et Docker Hub, servis en mémoire par le jeu du faux registre.
$registryFixture = json_decode((string) file_get_contents(dirname(__DIR__) . '/mock-registry/fixture.json'), true);
$harborId = (int) (new Connection())->add([
    'name'          => 'Harbor fictif (sécurité)',
    'type'          => Connection::TYPE_HARBOR,
    'entities_id'   => 0,
    'url'           => 'https://harbor.exemple.test',
    'registry_host' => 'registry.exemple.test:5000',
    'username'      => 'robot$lecture',
    'api_key'       => 'robot_mot_de_passe_securite',
]);
$registryReport = (new RegistryImporter(static function (?Connection $harbor) use ($registryFixture): RegistryClient {
    $transport = static function (string $url) use ($registryFixture): string {
        $path = (string) parse_url($url, PHP_URL_PATH);
        if (preg_match('#^/v2/repositories/(.+)/tags$#', $path, $m) === 1) {
            $tags = $registryFixture['hub'][$m[1]] ?? throw new RuntimeException('HTTP 404 returned');

            return (string) json_encode(['next' => null, 'results' => $tags]);
        }
        preg_match('#^/v2/(.+)/tags/list$#', $path, $m);
        $tags = $registryFixture['v2'][$m[1] ?? ''] ?? throw new RuntimeException('HTTP 404 returned');

        return (string) json_encode(['name' => $m[1], 'tags' => $tags]);
    };

    return $harbor === null
        ? RegistryClient::dockerHub('https://hub.exemple.test', $transport)
        : RegistryClient::harbor(
            'https://harbor.exemple.test',
            'robot$lecture',
            'robot_mot_de_passe_securite',
            $transport
        );
}))->run();

// Analyse d'image importée (#73) : rapport Trivy fictif, rattaché au service redis.
$scan = ImageScan::import(
    (string) file_get_contents(dirname(__DIR__) . '/fixtures/scans/trivy.json'),
    'trivy.json'
);
if (!$scan['ok']) {
    fwrite(STDERR, $scan['message'] . "
");
}

echo sprintf(
    "connexion %d, %d services importés ; connexion WUD %d, %d versions de services ; "
        . "connexion Harbor %d, %d versions depuis les registres\n",
    $id,
    $report['services'],
    $wudId,
    $wudReport['services'],
    $harborId,
    $registryReport['services']
);

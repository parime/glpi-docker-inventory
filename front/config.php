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

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Dockerinventory\ConfigPage;
use GlpiPlugin\Dockerinventory\Connection;
use GlpiPlugin\Dockerinventory\Harbor\HarborSettings;
use GlpiPlugin\Dockerinventory\HarborArtifact;
use GlpiPlugin\Dockerinventory\ImageVersion;
use GlpiPlugin\Dockerinventory\Lifecycle\LifecycleChecker;
use GlpiPlugin\Dockerinventory\Lifecycle\LifecycleClient;
use GlpiPlugin\Dockerinventory\Lifecycle\ProductMap;
use GlpiPlugin\Dockerinventory\Lifecycle\Settings;
use GlpiPlugin\Dockerinventory\LifecycleProduct;
use GlpiPlugin\Dockerinventory\Menu;

// Page de configuration du plugin (Configuration > Plugins > Inventaire Docker) : le seul endroit où
// le plugin se règle (charte commune, section 9 ; #35). Onglets : connexions (Portainer, Kubernetes,
// WUD, Harbor), registres maîtrisés, fin de support des images. Droit de configuration de GLPI.
Session::checkRight(Connection::RIGHTNAME, UPDATE);

if (isset($_POST['save_managed_registries'])) {
    $hosts = HarborSettings::save((string) ($_POST['managed_registries'] ?? ''));
    Session::addMessageAfterRedirect(htmlescape(sprintf(
        __('Registres maîtrisés enregistrés : %s', 'dockerinventory'),
        $hosts === [] ? __('ceux des connexions Harbor', 'dockerinventory') : implode(', ', $hosts)
    )), false, INFO);
    Html::redirect(ConfigPage::url(ConfigPage::TAB_REGISTRIES));
} elseif (isset($_POST['save_settings'])) {
    Settings::save(!empty($_POST['enabled']), (int) ($_POST['warning_days'] ?? Settings::DEFAULT_WARNING_DAYS));
    Session::addMessageAfterRedirect(htmlescape(__('Réglages enregistrés.', 'dockerinventory')), false, INFO);
    Html::redirect(LifecycleProduct::getPageURL());
} elseif (isset($_POST['refresh'])) {
    $report = (new LifecycleChecker())->refresh();
    Session::addMessageAfterRedirect(
        htmlescape(LifecycleProduct::refreshMessage($report)),
        false,
        $report['status'] === LifecycleChecker::REPORT_PARTIAL ? WARNING : INFO
    );
    Html::redirect(LifecycleProduct::getPageURL());
} elseif (isset($_POST['save_mappings'])) {
    $mapping = new LifecycleProduct();
    $rows = is_array($_POST['rows'] ?? null) ? $_POST['rows'] : [];
    $failed = false;
    foreach ($rows as $id => $row) {
        if (!is_array($row) || !$mapping->getFromDB((int) $id)) {
            continue;
        }
        if (!empty($row['delete'])) {
            $mapping->delete(['id' => (int) $id], true);
            continue;
        }
        $image = trim((string) ($row['image'] ?? ''));
        $product = trim((string) ($row['product'] ?? ''));
        if ($image !== $mapping->fields['image'] || $product !== $mapping->fields['product']) {
            $values = ['id' => (int) $id, 'image' => $image, 'product' => $product, 'is_default' => 0];
            $failed = !$mapping->update($values) || $failed;
        }
    }
    $newImage = trim((string) ($_POST['new_image'] ?? ''));
    $newProduct = trim((string) ($_POST['new_product'] ?? ''));
    $keep = '';
    if ($newImage !== '' || $newProduct !== '') {
        if ((new LifecycleProduct())->add(['image' => $newImage, 'product' => $newProduct]) === false) {
            $failed = true;
            // Nouvelle ligne refusée : l'image saisie reste dans le formulaire.
            $keep = $newImage;
        }
    }
    if (!$failed) {
        Session::addMessageAfterRedirect(
            htmlescape(__('Correspondances enregistrées.', 'dockerinventory')),
            false,
            INFO
        );
    }
    Html::redirect(LifecycleProduct::getPageURL(true, $keep !== '' ? ['add' => $keep] : []));
}

$tab = ConfigPage::tab((string) ($_GET['tab'] ?? ''));
$renderer = TemplateRenderer::getInstance();
Html::header(Menu::getTypeName(), '', 'config', 'plugin');
$tabs = ConfigPage::tabs($tab);
$navTitle = sprintf('%s : %s', Menu::getTypeName(), __('Setup'));

if ($tab === ConfigPage::TAB_CONNECTIONS) {
    $renderer->display('@dockerinventory/config_connections.html.twig', [
        'tabs'      => $tabs,
        'nav_title' => $navTitle,
        'add_url'   => Connection::getFormURL(),
    ]);
    Search::show(Connection::class);
} elseif ($tab === ConfigPage::TAB_REGISTRIES) {
    $renderer->display('@dockerinventory/managed_registries.html.twig', [
        'tabs'         => $tabs,
        'nav_title'    => $navTitle,
        'text'         => HarborSettings::text(),
        // Hôtes des connexions Harbor des entités actives seulement (#48).
        'harbor_hosts' => HarborArtifact::visibleHarborHosts(),
        'page_url'     => ConfigPage::url(ConfigPage::TAB_REGISTRIES),
        'harbor_url'   => HarborArtifact::getPageURL(),
    ]);
} else {
    $checker = LifecycleChecker::current();
    $map = $checker->map();
    $images = $checker->imagesInUse();
    $cache = $checker->cache();
    $mappings = [];
    foreach ((new LifecycleProduct())->find([], ['image']) as $row) {
        $product = (string) $row['product'];
        $mappings[] = [
            'id'         => (int) $row['id'],
            'image'      => (string) $row['image'],
            'product'    => $product,
            'is_default' => (bool) $row['is_default'],
            'in_use'     => $images[(string) $row['image']] ?? 0,
            'checked'    => $cache[$product]['date_success'] ?? '',
            'error'      => $cache[$product]['error'] ?? '',
        ];
    }
    // Images en service d'abord, puis les autres (ordre alphabétique conservé dans chaque groupe).
    usort(
        $mappings,
        static fn (array $a, array $b): int => [$b['in_use'] > 0, $a['image']] <=> [$a['in_use'] > 0, $b['image']]
    );
    $unmapped = [];
    foreach ($images as $image => $count) {
        if (!isset($map[$image])) {
            $unmapped[] = ['image' => $image, 'count' => $count];
        }
    }
    $renderer->display('@dockerinventory/lifecycle.html.twig', [
        'tabs'         => $tabs,
        'nav_title'    => $navTitle,
        'settings'     => Settings::get(),
        'source_url'   => LifecycleClient::configuredUrl(),
        'is_default_source' => LifecycleClient::configuredUrl() === LifecycleClient::DEFAULT_URL,
        'source'       => $checker->sourceState(),
        'error_labels' => LifecycleProduct::errorLabels(),
        'mappings'     => $mappings,
        'unmapped'     => $unmapped,
        'add_image'    => ProductMap::normalizeImage((string) ($_GET['add'] ?? '')),
        'page_url'     => LifecycleProduct::getPageURL(),
        'versions_url' => ImageVersion::getEolURL(),
        'max_days'     => Settings::MAX_DAYS,
    ]);
}
Html::footer();

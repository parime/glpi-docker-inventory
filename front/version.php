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
use GlpiPlugin\Dockerinventory\Exploit\ExploitCatalog;
use GlpiPlugin\Dockerinventory\ExploitSignal;
use GlpiPlugin\Dockerinventory\Exposure;
use GlpiPlugin\Dockerinventory\Harbor\HarborMapper;
use GlpiPlugin\Dockerinventory\HarborArtifact;
use GlpiPlugin\Dockerinventory\Hardening;
use GlpiPlugin\Dockerinventory\Hardening\HardeningMapper;
use GlpiPlugin\Dockerinventory\HardeningException;
use GlpiPlugin\Dockerinventory\ImageScan;
use GlpiPlugin\Dockerinventory\ImageVersion;
use GlpiPlugin\Dockerinventory\Lifecycle\CycleMatcher;
use GlpiPlugin\Dockerinventory\Lifecycle\LifecycleChecker;
use GlpiPlugin\Dockerinventory\Lifecycle\LifecycleClient;
use GlpiPlugin\Dockerinventory\Lifecycle\Settings;
use GlpiPlugin\Dockerinventory\LifecycleProduct;
use GlpiPlugin\Dockerinventory\Menu;
use GlpiPlugin\Dockerinventory\Scan\ScanReportParser;
use GlpiPlugin\Dockerinventory\Service;
use GlpiPlugin\Dockerinventory\Version\VersionComparator;

// Sous-pages « Versions des images » (par défaut), « Images Harbor et vulnérabilités »
// (?view=harbor), « Analyses importées » (?view=scans, #73), « Fin de support des images »
// (?view=eol), « Exposition » (?view=exposure, #72) et « Configuration à risque » (?view=hardening,
// #71) de l'entrée Parc > Inventaire Docker : lecture seule, droit de lecture du plugin, entités actives.
Session::checkRight(Service::RIGHTNAME, READ);

$hasHarbor = countElementsInTable(Connection::getTable(), ['type' => Connection::TYPE_HARBOR]) > 0;
$view = (string) ($_GET['view'] ?? '');
$tabs = [
    'versions_url' => ImageVersion::getOverviewURL(),
    'harbor_url'   => HarborArtifact::getPageURL(),
    'tabs'         => Menu::tabs(
        in_array(
            $view,
            [Menu::TAB_HARBOR, Menu::TAB_SCANS, Menu::TAB_EOL, Menu::TAB_EXPOSURE, Menu::TAB_HARDENING],
            true
        )
            ? $view
            : Menu::TAB_VERSIONS
    ),
];

if ($view === Menu::TAB_EXPOSURE) {
    // Exposition : tous les services exposés, ou seulement ceux exposés sur Internet ou sans flux
    // documenté dans GRC Manager. Aucune écriture, ni ici ni dans GRC.
    $filter = (string) ($_GET['filter'] ?? '');
    if (!in_array($filter, [Exposure::FILTER_INTERNET, Exposure::FILTER_UNDOCUMENTED], true)) {
        $filter = '';
    }
    $grc = Exposure::grcState();
    if (!$grc['available'] && $filter === Exposure::FILTER_UNDOCUMENTED) {
        $filter = '';
    }
    $data = Exposure::overview($filter);

    Html::header(Exposure::getTypeName(1), '', 'assets', Menu::class, Menu::TAB_EXPOSURE);
    TemplateRenderer::getInstance()->display('@dockerinventory/exposure.html.twig', $tabs + [
        'services'     => $data['services'],
        'counts'       => $data['counts'],
        'filter'       => $filter,
        'internet'     => Exposure::FILTER_INTERNET,
        'undocumented' => Exposure::FILTER_UNDOCUMENTED,
        'infos'        => Exposure::infos(),
        'grc'          => $grc,
        'page_url'     => Exposure::getPageURL(),
    ]);
    Html::footer();

    return;
}
if ($view === Menu::TAB_SCANS) {
    // Analyses importées (#73) : rapports de Trivy, Grype ou Docker Scout déposés par
    // front/imagescan.form.php, rattachés aux services ; rien n'est lancé sur les plateformes.
    $filter = (string) ($_GET['filter'] ?? '');
    if (!in_array($filter, ImageScan::FILTERS, true)) {
        $filter = '';
    }
    $data = ImageScan::overview($filter);

    Html::header(ImageScan::getTypeName(2), '', 'assets', Menu::class, Menu::TAB_SCANS);
    TemplateRenderer::getInstance()->display('@dockerinventory/image_scans.html.twig', $tabs + [
        'scans'         => $data['scans'],
        'counts'        => $data['counts'],
        'coverage'      => $data['coverage'],
        'filter'        => $filter,
        'critical_high' => ImageScan::FILTER_CRITICAL_HIGH,
        'stale_filter'  => ImageScan::FILTER_STALE,
        'kev_filter'    => ImageScan::FILTER_KEV,
        // Exploitation connue (#84) : date du catalogue, lien vers ses réglages (configuration de GLPI).
        'exploits'      => [
            'loaded'     => ExploitSignal::isLoaded(),
            'date'       => ExploitSignal::state(ExploitCatalog::KIND_KEV)['date'],
            'config_url' => (bool) Session::haveRight(Connection::RIGHTNAME, UPDATE)
                ? ConfigPage::url(ConfigPage::TAB_EXPLOITS) : '',
        ],
        'stale_days'    => ImageScan::STALE_DAYS,
        'max_mb'        => intdiv(ScanReportParser::MAX_BYTES, 1024 * 1024),
        'page_url'      => ImageScan::getPageURL(),
        'form_url'      => ImageScan::getFormURLForActions(),
        'can_create'    => ImageScan::canCreate(),
        'can_purge'     => ImageScan::canPurge(),
    ]);
    Html::footer();

    return;
}
if ($view === Menu::TAB_HARDENING) {
    // Configuration à risque : services analysés avec leurs écarts, filtrés par gravité la plus
    // haute ou par exception. Les exceptions s'enregistrent par front/hardening.form.php.
    $filter = (string) ($_GET['filter'] ?? '');
    $filters = array_merge(HardeningMapper::SEVERITIES, [Hardening::FILTER_EXCEPTION, Hardening::FILTER_EXPIRING]);
    if (!in_array($filter, $filters, true)) {
        $filter = '';
    }
    $data = Hardening::overview($filter);

    Html::header(Hardening::getTypeName(1), '', 'assets', Menu::class, Menu::TAB_HARDENING);
    TemplateRenderer::getInstance()->display('@dockerinventory/hardening.html.twig', $tabs + [
        'services'         => $data['services'],
        'counts'           => $data['counts'],
        'rate'             => $data['rate'],
        'rate_serious'     => $data['rate_serious'],
        'filter'           => $filter,
        'exception_filter' => Hardening::FILTER_EXCEPTION,
        'expiring_filter'  => Hardening::FILTER_EXPIRING,
        'remind_days'      => HardeningException::REMIND_DAYS,
    ] + Hardening::viewContext());
    Html::footer();

    return;
}
$eolSource = [
    'eol_statuses'   => LifecycleProduct::statusInfos(),
    'eol_watch'      => ImageVersion::FILTER_EOL_WATCH,
    'eol_source'     => LifecycleChecker::current()->sourceState(),
    'eol_source_host' => (string) parse_url(LifecycleClient::configuredUrl(), PHP_URL_HOST),
    'eol_errors'     => LifecycleProduct::errorLabels(),
    'lifecycle_url'  => LifecycleProduct::getPageURL(),
    'can_configure_lifecycle' => LifecycleProduct::canView(),
];

if ($view === Menu::TAB_EOL) {
    // Fin de support : « À surveiller » par défaut, « all » pour tout voir.
    $eol = (string) ($_GET['eol'] ?? ImageVersion::FILTER_EOL_WATCH);
    if ($eol !== ImageVersion::FILTER_EOL_WATCH && !in_array($eol, CycleMatcher::STATUSES, true)) {
        $eol = '';
    }
    $eolEnabled = Settings::enabled();
    $data = $eolEnabled ? ImageVersion::overview('', 0, $eol) : ['services' => [], 'eol_counts' => []];

    Html::header(__('Fin de support des images', 'dockerinventory'), '', 'assets', Menu::class, Menu::TAB_EOL);
    TemplateRenderer::getInstance()->display('@dockerinventory/eol.html.twig', $tabs + $eolSource + [
        'eol_enabled' => $eolEnabled,
        'eol'         => $eol,
        'eol_all'     => 'all',
        'rows'        => $data['services'],
        'eol_counts'  => $data['eol_counts'],
        'page_url'    => ImageVersion::getEolURL(),
    ]);
    Html::footer();

    return;
}

if ($view === Menu::TAB_HARBOR) {
    $severity = (string) ($_GET['severity'] ?? '');
    $known = array_merge(
        HarborMapper::SEVERITIES,
        [HarborArtifact::FILTER_CRITICAL_HIGH, HarborArtifact::FILTER_UNKNOWN]
    );
    if (!in_array($severity, $known, true)) {
        $severity = '';
    }
    $unmanagedOnly = !empty($_GET['unmanaged']);
    $project = (string) ($_GET['project'] ?? '');
    // Artefacts : par défaut, seulement ceux qui tournent (un dépôt peut en compter des centaines).
    $deployedOnly = ($_GET['artifacts'] ?? 'deployed') !== 'all';
    $data = HarborArtifact::overview($severity, $unmanagedOnly, $project, $deployedOnly);
    if (!in_array($project, $data['projects'], true)) {
        $project = '';
    }

    Html::header(HarborArtifact::getTypeName(2), '', 'assets', Menu::class, Menu::TAB_HARBOR);
    TemplateRenderer::getInstance()->display('@dockerinventory/harbor_images.html.twig', $tabs + [
        'deployed'       => $data['deployed'],
        'artifacts'      => $data['artifacts'],
        'counts'         => $data['counts'],
        'deployed_counts' => $data['deployed_counts'],
        'projects'       => $data['projects'],
        'unmanaged_count' => $data['unmanaged'],
        'managed_hosts'  => $data['managed_hosts'],
        'managed_hidden' => $data['managed_hidden'],
        'severities'     => HarborArtifact::severityInfos(),
        'severity'       => $severity,
        'critical_high'  => HarborArtifact::FILTER_CRITICAL_HIGH,
        'unknown_filter' => HarborArtifact::FILTER_UNKNOWN,
        'unmanaged_only' => $unmanagedOnly,
        'project'        => $project,
        'deployed_only'  => $deployedOnly,
        'has_harbor'     => $hasHarbor,
        'can_configure'  => Connection::canView(),
        'connection_url' => Connection::getSearchURL(),
        'connection_form_url' => Connection::getFormURL(),
    ]);
    Html::footer();

    return;
}

$gap = (string) ($_GET['gap'] ?? '');
if ($gap !== ImageVersion::FILTER_OUTDATED && !in_array($gap, VersionComparator::GAPS, true)) {
    $gap = '';
}
$environment = (int) ($_GET['environment'] ?? 0);
$eol = (string) ($_GET['eol'] ?? '');
if ($eol !== ImageVersion::FILTER_EOL_WATCH && !in_array($eol, CycleMatcher::STATUSES, true)) {
    $eol = '';
}
$eolEnabled = Settings::enabled();
if (!$eolEnabled) {
    $eol = '';
}
$data = ImageVersion::overview($gap, $environment, $eol);

Html::header(ImageVersion::getTypeName(2), '', 'assets', Menu::class, Menu::TAB_VERSIONS);
TemplateRenderer::getInstance()->display('@dockerinventory/versions.html.twig', $tabs + $eolSource + [
    'rows'           => $data['services'],
    'unmatched'      => $data['unmatched'],
    'counts'         => $data['counts'],
    'environments'   => $data['environments'],
    'gaps'           => ImageVersion::gapInfos(),
    'gap'            => $gap,
    'outdated'       => ImageVersion::FILTER_OUTDATED,
    'environment'    => $environment,
    'page_url'       => ImageVersion::getOverviewURL(),
    'search_url'     => ImageVersion::getSearchURL(),
    'has_wud'        => countElementsInTable(Connection::getTable(), ['type' => Connection::TYPE_WUD]) > 0,
    'has_registry'   => countElementsInTable(ImageVersion::getTable(), ['source' => ImageVersion::SOURCE_REGISTRY]) > 0,
    'connection_form_url' => Connection::getFormURL(),
    'can_configure'  => Connection::canView(),
    'connection_url' => Connection::getSearchURL(),
    'eol_enabled'    => $eolEnabled,
    'eol'            => $eol,
    'eol_counts'     => $data['eol_counts'],
]);
Html::footer();

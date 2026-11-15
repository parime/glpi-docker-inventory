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
use GlpiPlugin\Dockerinventory\Connection;
use GlpiPlugin\Dockerinventory\Harbor\HarborMapper;
use GlpiPlugin\Dockerinventory\HarborArtifact;
use GlpiPlugin\Dockerinventory\ImageVersion;
use GlpiPlugin\Dockerinventory\Lifecycle\CycleMatcher;
use GlpiPlugin\Dockerinventory\Lifecycle\LifecycleChecker;
use GlpiPlugin\Dockerinventory\Lifecycle\LifecycleClient;
use GlpiPlugin\Dockerinventory\Lifecycle\Settings;
use GlpiPlugin\Dockerinventory\LifecycleProduct;
use GlpiPlugin\Dockerinventory\Service;
use GlpiPlugin\Dockerinventory\Version\VersionComparator;

// Page « Versions des images » : lecture seule, droit de lecture du plugin, entités actives.
// Onglets : versions (par défaut) et images Harbor (?view=harbor), sans entrée de menu à part.
Session::checkRight(Service::RIGHTNAME, READ);

$hasHarbor = countElementsInTable(Connection::getTable(), ['type' => Connection::TYPE_HARBOR]) > 0;
$tabs = [
    'versions_url' => ImageVersion::getOverviewURL(),
    'harbor_url'   => HarborArtifact::getPageURL(),
];

if (($_GET['view'] ?? '') === 'harbor') {
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

    Html::header(ImageVersion::getTypeName(2), '', 'assets', ImageVersion::class);
    TemplateRenderer::getInstance()->display('@dockerinventory/harbor_images.html.twig', $tabs + [
        'active_tab'     => 'harbor',
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

Html::header(ImageVersion::getTypeName(2), '', 'assets', ImageVersion::class);
TemplateRenderer::getInstance()->display('@dockerinventory/versions.html.twig', $tabs + [
    'active_tab'     => 'versions',
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
    'eol_statuses'   => LifecycleProduct::statusInfos(),
    'eol_watch'      => ImageVersion::FILTER_EOL_WATCH,
    'eol_source'     => LifecycleChecker::current()->sourceState(),
    'eol_source_host' => (string) parse_url(LifecycleClient::configuredUrl(), PHP_URL_HOST),
    'eol_errors'     => LifecycleProduct::errorLabels(),
    'lifecycle_url'  => LifecycleProduct::getPageURL(),
    'can_configure_lifecycle' => LifecycleProduct::canView(),
]);
Html::footer();

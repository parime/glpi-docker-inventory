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
use GlpiPlugin\Dockerinventory\ImageVersion;
use GlpiPlugin\Dockerinventory\Lifecycle\CycleMatcher;
use GlpiPlugin\Dockerinventory\Lifecycle\LifecycleChecker;
use GlpiPlugin\Dockerinventory\Lifecycle\Settings;
use GlpiPlugin\Dockerinventory\LifecycleProduct;
use GlpiPlugin\Dockerinventory\Service;
use GlpiPlugin\Dockerinventory\Version\VersionComparator;

// Page « Versions des images » : lecture seule, droit de lecture du plugin, entités actives.
Session::checkRight(Service::RIGHTNAME, READ);

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
TemplateRenderer::getInstance()->display('@dockerinventory/versions.html.twig', [
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
    'eol_errors'     => LifecycleProduct::errorLabels(),
    'lifecycle_url'  => LifecycleProduct::getPageURL(),
    'can_configure_lifecycle' => LifecycleProduct::canView(),
]);
Html::footer();

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

namespace GlpiPlugin\Dockerinventory;

use CommonGLPI;
use DbUtils;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Dockerinventory\Exposure\ExposureMapper;
use GlpiPlugin\Dockerinventory\Kubernetes\KubernetesMapper;
use Plugin;

/**
 * Exposition des services (#72) : ports publiés, routes Traefik, ingress et services Kubernetes
 * exposés, relevés par l'import (lecture seule). Onglet « Exposition » des services et sous-page
 * « Exposition » de l'entrée Parc > Inventaire Docker.
 *
 * Avec GRC Manager : pour chaque exposition, un flux est **proposé** (lien vers le formulaire d'un
 * nouveau flux de la matrice, valeurs en paramètres) et le plugin signale une exposition sans flux
 * documenté. Rien n'est jamais créé dans GRC : la personne relit et enregistre elle-même. Sans GRC
 * Manager (ou sans droit sur sa matrice des flux), l'onglet fonctionne seul.
 *
 * @phpstan-import-type Exposure from ExposureMapper as ExposureRow
 * @phpstan-type GrcFlow array{destination_itemtype: string, destination_items_id: int,
 *     destination_label: string, protocol: string, ports: string}
 */
class Exposure extends CommonGLPI
{
    public const GRC_PLUGIN = 'grcmanager';

    public const GRC_FLOW_CLASS = 'GlpiPlugin\\Grcmanager\\NetworkFlow';

    /** Filtre de la sous-page : expositions sur Internet seulement. */
    public const FILTER_INTERNET = 'internet';

    /** Filtre de la sous-page : expositions sans flux documenté dans GRC. */
    public const FILTER_UNDOCUMENTED = 'undocumented';

    /** @var list<GrcFlow>|null */
    private static ?array $grcFlows = null;

    public static function getTypeName($nb = 0)
    {
        return __('Exposition', 'dockerinventory');
    }

    /**
     * @return string
     */
    public static function getIcon()
    {
        return 'ti ti-world-share';
    }

    public static function canView(): bool
    {
        return Service::canView();
    }

    public static function getPageURL(bool $full = true): string
    {
        return ImageVersion::getOverviewURL($full) . '?view=' . Menu::TAB_EXPOSURE;
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof Service || $item->isNewItem() || !self::canView()) {
            return '';
        }
        $count = 0;
        if ($_SESSION['glpishow_count_on_tabs'] ?? false) {
            $count = count(self::exposuresOf($item->fields));
        }

        return self::createTabEntry(self::getTypeName(1), $count, static::class, self::getIcon());
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        // ajax/common.tabs.php sert le contenu sans passer par getTabNameForItem().
        if (!$item instanceof Service || !self::canView() || !$item->can($item->getID(), READ)) {
            return false;
        }
        TemplateRenderer::getInstance()->display('@dockerinventory/exposure_tab.html.twig', [
            'service' => self::describe($item->fields),
            'infos'   => self::infos(),
            'grc'     => self::grcState(),
            'page_url' => self::getPageURL(),
        ]);

        return true;
    }

    /**
     * Libellés, couleurs et icônes des portées et des sources.
     *
     * @return array{scopes: array<string, array{label: string, color: string, icon: string, help: string}>,
     *     sources: array<string, array{label: string, icon: string}>,
     *     certificates: array<string, array{label: string, color: string, icon: string}>,
     *     certificate_errors: array<string, string>}
     */
    public static function infos(): array
    {
        return [
            // #78 : états et motifs d'échec de lecture des certificats des routes HTTPS.
            'certificates'       => Certificate::statusInfos(),
            'certificate_errors' => Certificate::errorLabels(),
            'scopes'  => [
                ExposureMapper::SCOPE_INTERNET => [
                    'label' => __('Internet (probable)', 'dockerinventory'),
                    'color' => 'danger',
                    'icon'  => 'ti ti-world',
                    'help'  => __(
                        'Nom d’hôte public ou adresse publique : sans doute joignable depuis Internet.',
                        'dockerinventory'
                    ),
                ],
                ExposureMapper::SCOPE_HOST => [
                    'label' => __('Réseau de l’hôte', 'dockerinventory'),
                    'color' => 'warning',
                    'icon'  => 'ti ti-server',
                    'help'  => __(
                        'Port publié sur toutes les adresses de l’hôte : joignable par tout réseau qui atteint l’hôte.',
                        'dockerinventory'
                    ),
                ],
                ExposureMapper::SCOPE_INTERNAL => [
                    'label' => __('Réseau interne', 'dockerinventory'),
                    'color' => 'info',
                    'icon'  => 'ti ti-building',
                    'help'  => __(
                        'Nom ou adresse d’un réseau interne, ou port des nœuds Kubernetes.',
                        'dockerinventory'
                    ),
                ],
                ExposureMapper::SCOPE_LOCAL => [
                    'label' => __('Hôte seul', 'dockerinventory'),
                    'color' => 'success',
                    'icon'  => 'ti ti-lock',
                    'help'  => __(
                        'Port publié sur l’adresse de bouclage : joignable depuis l’hôte seulement.',
                        'dockerinventory'
                    ),
                ],
            ],
            'sources' => [
                ExposureMapper::SOURCE_PORT        => [
                    'label' => __('Port publié', 'dockerinventory'),
                    'icon'  => 'ti ti-plug',
                ],
                ExposureMapper::SOURCE_TRAEFIK     => ['label' => 'Traefik', 'icon' => 'ti ti-route'],
                ExposureMapper::SOURCE_INGRESS     => ['label' => 'Ingress', 'icon' => 'ti ti-route'],
                ExposureMapper::SOURCE_K8S_SERVICE => [
                    'label' => __('Service Kubernetes', 'dockerinventory'),
                    'icon'  => 'ti ti-brand-kubernetes',
                ],
            ],
        ];
    }

    /**
     * Expositions d'un service (ligne de la table des services).
     *
     * @param array<string, mixed> $row
     * @return list<ExposureRow>
     */
    public static function exposuresOf(array $row): array
    {
        $kind = (string) ($row['kind'] ?? '');

        return ExposureMapper::exposures(
            (string) ($row['ports'] ?? ''),
            (string) ($row['routes'] ?? ''),
            in_array($kind, KubernetesMapper::workloadKinds(), true)
        );
    }

    /**
     * Service, ses expositions et, si GRC Manager est disponible, le flux proposé pour chacune et
     * s'il est déjà documenté.
     *
     * @param array<string, mixed> $row
     * @return array{id: int, name: string, url: string, stack_name: string, entities_id: int,
     *     is_retired: bool, exposures: list<array<string, mixed>>, internet: int, undocumented: int}
     */
    public static function describe(array $row): array
    {
        $grc = self::grcState();
        $name = (string) ($row['name'] ?? '');
        $applianceId = (int) ($row['appliances_id'] ?? 0);
        $internetLabel = __('Internet', 'dockerinventory');
        $internalLabel = __('Réseau interne', 'dockerinventory');
        $exposures = [];
        $internet = 0;
        $undocumented = 0;
        $today = substr((string) ($_SESSION['glpi_currenttime'] ?? date('Y-m-d')), 0, 10);
        $warningDays = Certificate::warningDays();
        foreach (self::exposuresOf($row) as $exposure) {
            $proposal = ExposureMapper::proposal($exposure, $name, $internetLabel, $internalLabel);
            $documented = null;
            $proposeUrl = '';
            if ($proposal !== null && $grc['available']) {
                $documented = ExposureMapper::isDocumented(
                    $proposal,
                    $applianceId,
                    $exposure['source'] === ExposureMapper::SOURCE_PORT ? '' : $exposure['host'],
                    $name,
                    self::grcFlows()
                );
                if (!$documented && $grc['can_create']) {
                    $proposeUrl = self::proposalUrl($proposal, $applianceId);
                }
                if (!$documented) {
                    $undocumented++;
                }
            }
            if ($exposure['scope'] === ExposureMapper::SCOPE_INTERNET) {
                $internet++;
            }
            $exposures[] = $exposure + [
                'proposal'    => $proposal,
                'documented'  => $documented,
                'propose_url' => $proposeUrl,
                // #78 : certificat présenté par la route HTTPS, d'après la dernière lecture.
                'certificate' => Certificate::isTarget($exposure)
                    ? Certificate::state(
                        Certificate::lookup($exposure['host'], Certificate::PORT),
                        $today,
                        $warningDays
                    )
                    : null,
            ];
        }

        return [
            'id'           => (int) ($row['id'] ?? 0),
            'name'         => $name,
            'url'          => Service::getFormURLWithID((int) ($row['id'] ?? 0)),
            'stack_name'   => (string) ($row['stack_name'] ?? ''),
            'entities_id'  => (int) ($row['entities_id'] ?? 0),
            'is_retired'   => (bool) ($row['is_retired'] ?? false),
            'exposures'    => $exposures,
            'internet'     => $internet,
            'undocumented' => $undocumented,
        ];
    }

    /**
     * Services actifs exposés, visibles dans les entités actives (sous-page « Exposition »).
     *
     * @return array{services: list<array<string, mixed>>, counts: array{exposed: int, internet: int,
     *     undocumented: int}}
     */
    public static function overview(string $filter = ''): array
    {
        global $DB;

        $services = [];
        $counts = ['exposed' => 0, 'internet' => 0, 'undocumented' => 0];
        foreach (
            $DB->request([
                'FROM'  => Service::getTable(),
                'WHERE' => ['is_retired' => 0]
                    + (new DbUtils())->getEntitiesRestrictCriteria(Service::getTable(), '', '', true),
                'ORDER' => ['stack_name', 'name'],
            ]) as $row
        ) {
            $service = self::describe($row);
            if ($service['exposures'] === []) {
                continue;
            }
            $counts['exposed']++;
            $counts['internet'] += $service['internet'] > 0 ? 1 : 0;
            $counts['undocumented'] += $service['undocumented'] > 0 ? 1 : 0;
            if (
                ($filter === self::FILTER_INTERNET && $service['internet'] === 0)
                || ($filter === self::FILTER_UNDOCUMENTED && $service['undocumented'] === 0)
            ) {
                continue;
            }
            $services[] = $service;
        }

        return ['services' => $services, 'counts' => $counts];
    }

    /**
     * GRC Manager actif, avec sa matrice des flux lisible par la personne connectée.
     *
     * @return array{available: bool, can_create: bool, matrix_url: string}
     */
    public static function grcState(): array
    {
        $class = self::GRC_FLOW_CLASS;
        if (
            !Plugin::isPluginActive(self::GRC_PLUGIN)
            || !class_exists($class)
            || !is_subclass_of($class, \CommonDBTM::class)
        ) {
            return ['available' => false, 'can_create' => false, 'matrix_url' => ''];
        }
        $available = (bool) $class::canView();

        return [
            'available'  => $available,
            'can_create' => $available && (bool) $class::canCreate(),
            'matrix_url' => $available ? $class::getSearchURL() : '',
        ];
    }

    /**
     * Adresse du formulaire d'un nouveau flux de GRC, avec les valeurs proposées en paramètres.
     * Le formulaire s'ouvre vide si la version de GRC ne lit pas ces paramètres : les valeurs sont
     * aussi affichées à côté du bouton.
     *
     * @param array{flow_type: string, name: string, source_label: string, destination_label: string,
     *     protocol: string, ports: string} $proposal
     */
    public static function proposalUrl(array $proposal, int $applianceId): string
    {
        $class = self::GRC_FLOW_CLASS;
        if (!class_exists($class) || !is_subclass_of($class, \CommonDBTM::class)) {
            return '';
        }
        $query = $proposal + (
            $applianceId > 0 ? ['destination_itemtype' => 'Appliance', 'destination_items_id' => $applianceId] : []
        );

        return $class::getFormURL() . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Flux actifs de la matrice de GRC (lecture seule), une fois par requête.
     *
     * @return list<GrcFlow>
     */
    public static function grcFlows(): array
    {
        global $DB;

        if (self::$grcFlows !== null) {
            return self::$grcFlows;
        }
        self::$grcFlows = [];
        $class = self::GRC_FLOW_CLASS;
        if (!self::grcState()['available'] || !is_subclass_of($class, \CommonDBTM::class)) {
            return self::$grcFlows;
        }
        $table = $class::getTable();
        if (!$DB->tableExists($table)) {
            return self::$grcFlows;
        }
        foreach (
            $DB->request([
                'SELECT' => ['destination_itemtype', 'destination_items_id', 'destination_label', 'protocol', 'ports'],
                'FROM'   => $table,
                'WHERE'  => ['is_active' => 1],
            ]) as $flow
        ) {
            self::$grcFlows[] = [
                'destination_itemtype' => (string) $flow['destination_itemtype'],
                'destination_items_id' => (int) $flow['destination_items_id'],
                'destination_label'    => (string) $flow['destination_label'],
                'protocol'             => (string) $flow['protocol'],
                'ports'                => (string) $flow['ports'],
            ];
        }

        return self::$grcFlows;
    }

    /**
     * Oublie les flux lus (tests).
     */
    public static function resetCache(): void
    {
        self::$grcFlows = null;
    }
}

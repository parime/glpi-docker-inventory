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

use Appliance;
use ApplianceEnvironment;
use CommonDBTM;
use CommonGLPI;
use DbUtils;
use Dropdown;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Dockerinventory\Lifecycle\CycleMatcher;
use GlpiPlugin\Dockerinventory\Lifecycle\LifecycleChecker;
use GlpiPlugin\Dockerinventory\Lifecycle\Settings;
use GlpiPlugin\Dockerinventory\Ticket\EolTickets;
use GlpiPlugin\Dockerinventory\Ticket\UpdateTickets;
use GlpiPlugin\Dockerinventory\Version\VersionComparator;
use GlpiPlugin\Dockerinventory\Version\VersionLinker;
use GlpiPlugin\Dockerinventory\Wud\WudMapper;

/**
 * Version déployée et dernière version disponible de l'image d'un service (ou d'un conteneur
 * suivi par WUD sans service correspondant). Données recalculées à chaque lecture de WUD, ou à
 * chaque vérification des registres pour les services sans WUD (source « registry »), en lecture
 * seule : aucune page ne les modifie.
 *
 * Affichage : onglet « Versions » des services et des applications, page « Versions des images »
 * (front/version.php) et recherche GLPI sur les colonnes (front/imageversion.php). Lisible avec le
 * droit de lecture des services du plugin.
 */
class ImageVersion extends CommonDBTM
{
    public const SOURCE_WUD = 'wud';
    public const SOURCE_REGISTRY = 'registry';

    /** Filtre « à mettre à jour » de la page des versions : tous les écarts connus. */
    public const FILTER_OUTDATED = 'outdated';

    /** Filtre de fin de support « à surveiller » : en fin de support ou bientôt. */
    public const FILTER_EOL_WATCH = 'watch';

    private const OUTDATED = [
        VersionComparator::GAP_MAJOR,
        VersionComparator::GAP_MINOR,
        VersionComparator::GAP_PATCH,
        VersionComparator::GAP_PRERELEASE,
        VersionComparator::GAP_DIGEST,
    ];

    public static function getTypeName($nb = 0)
    {
        return $nb > 1 ? __('Versions des images', 'dockerinventory') : __("Version d'image", 'dockerinventory');
    }

    public static function getIcon()
    {
        return 'ti ti-tags';
    }

    public static function canView(): bool
    {
        return Service::canView();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canUpdate(): bool
    {
        return false;
    }

    public static function canDelete(): bool
    {
        return false;
    }

    public static function canPurge(): bool
    {
        return false;
    }

    /**
     * Menu Parc : la page des versions (filtres, badges) ; la loupe mène à la recherche GLPI.
     *
     * @return array<string, mixed>|false
     */
    public static function getMenuContent()
    {
        $menu = parent::getMenuContent();
        if (is_array($menu) && isset($menu['page'])) {
            $menu['page'] = self::getOverviewURL(false);
        }

        return $menu;
    }

    public static function getOverviewURL(bool $full = true): string
    {
        global $CFG_GLPI;

        return ($full ? (string) $CFG_GLPI['root_doc'] : '') . '/plugins/dockerinventory/front/version.php';
    }

    /**
     * Libellé, couleur, icône et aide de chaque écart.
     *
     * @return array<string, array{label: string, color: string, icon: string, help: string}>
     */
    public static function gapInfos(): array
    {
        return [
            VersionComparator::GAP_MAJOR => [
                'label' => __('Version majeure', 'dockerinventory'),
                'color' => 'danger',
                'icon'  => 'ti ti-alert-triangle',
                'help'  => __('Nouvelle version majeure : changements possiblement incompatibles.', 'dockerinventory'),
            ],
            VersionComparator::GAP_MINOR => [
                'label' => __('Version mineure', 'dockerinventory'),
                'color' => 'warning',
                'icon'  => 'ti ti-arrow-up',
                'help'  => __(
                    'Nouvelle version mineure : nouvelles fonctions, en principe compatibles.',
                    'dockerinventory'
                ),
            ],
            VersionComparator::GAP_PATCH => [
                'label' => __('Correctif', 'dockerinventory'),
                'color' => 'info',
                'icon'  => 'ti ti-bandage',
                'help'  => __('Correctif disponible (souvent des corrections de sécurité).', 'dockerinventory'),
            ],
            VersionComparator::GAP_PRERELEASE => [
                'label' => __('Préversion', 'dockerinventory'),
                'color' => 'indigo',
                'icon'  => 'ti ti-flask',
                'help'  => __('Seule une préversion (ou une variante) plus récente existe.', 'dockerinventory'),
            ],
            VersionComparator::GAP_DIGEST => [
                'label' => __('Empreinte seule', 'dockerinventory'),
                'color' => 'purple',
                'icon'  => 'ti ti-fingerprint',
                'help'  => __('Même tag, image reconstruite : seule son empreinte a changé.', 'dockerinventory'),
            ],
            VersionComparator::GAP_UNTRACKABLE => [
                'label' => __('Non suivable', 'dockerinventory'),
                'color' => 'orange',
                'icon'  => 'ti ti-eye-off',
                'help'  => __(
                    'Tag mobile (latest...) ou sans numéro de version : seule l’empreinte dit si l’image a changé.',
                    'dockerinventory'
                ),
            ],
            VersionComparator::GAP_UNKNOWN => [
                'label' => __('Non vérifié', 'dockerinventory'),
                'color' => 'secondary',
                'icon'  => 'ti ti-help-circle',
                'help'  => __(
                    'Aucune version trouvée pour cette image (WUD ou registre), ou registre non pris en charge.',
                    'dockerinventory'
                ),
            ],
            VersionComparator::GAP_NONE => [
                'label' => __('À jour', 'dockerinventory'),
                'color' => 'success',
                'icon'  => 'ti ti-circle-check',
                'help'  => __('Aucune version plus récente connue.', 'dockerinventory'),
            ],
        ];
    }

    /**
     * @return array{label: string, color: string, icon: string, help: string}
     */
    public static function gapInfo(string $gap): array
    {
        return self::gapInfos()[$gap] ?? self::gapInfos()[VersionComparator::GAP_UNKNOWN];
    }

    /**
     * @return array<string, string>
     */
    public static function matchLabels(): array
    {
        return [
            VersionLinker::METHOD_CONTAINER         => __('Par identifiant ou nom du conteneur', 'dockerinventory'),
            VersionLinker::METHOD_DIGEST            => __('Par empreinte de l’image', 'dockerinventory'),
            VersionLinker::METHOD_LABEL             => __('Par nom du service Swarm', 'dockerinventory'),
            VersionLinker::METHOD_IMAGE             => __('Par image et tag', 'dockerinventory'),
            VersionLinker::UNMATCHED_AMBIGUOUS      => __('Plusieurs services possibles', 'dockerinventory'),
            VersionLinker::UNMATCHED_OUTSIDE_SWARM  => __('Conteneur hors Swarm non importé', 'dockerinventory'),
            VersionLinker::UNMATCHED_NO_SERVICE     => __('Aucun service importé correspondant', 'dockerinventory'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function sourceLabels(): array
    {
        return [
            self::SOURCE_WUD      => "What's Up Docker",
            self::SOURCE_REGISTRY => __("Registre de l'image", 'dockerinventory'),
        ];
    }

    public function rawSearchOptions()
    {
        $table = static::getTable();
        $services = Service::getTable();
        $options = parent::rawSearchOptions();
        $options[] = [
            'id'            => 1,
            'table'         => $services,
            'field'         => 'name',
            'name'          => Service::getTypeName(1),
            'datatype'      => 'itemlink',
            'massiveaction' => false,
        ];
        $options[] = [
            'id'            => 3,
            'table'         => $services,
            'field'         => 'stack_name',
            'name'          => __('Stack', 'dockerinventory'),
            'datatype'      => 'string',
            'massiveaction' => false,
        ];
        $options[] = [
            'id'            => 4,
            'table'         => Appliance::getTable(),
            'field'         => 'name',
            'linkfield'     => 'appliances_id',
            'name'          => Appliance::getTypeName(1),
            'datatype'      => 'itemlink',
            'massiveaction' => false,
            'joinparams'    => ['beforejoin' => ['table' => $services]],
        ];
        $options[] = [
            'id'            => 5,
            'table'         => ApplianceEnvironment::getTable(),
            'field'         => 'name',
            'linkfield'     => 'applianceenvironments_id',
            'name'          => ApplianceEnvironment::getTypeName(1),
            'datatype'      => 'dropdown',
            'massiveaction' => false,
            'joinparams'    => ['beforejoin' => [
                'table'      => Appliance::getTable(),
                'linkfield'  => 'appliances_id',
                'joinparams' => ['beforejoin' => ['table' => $services]],
            ]],
        ];
        $own = [
            2  => ['id', __('ID'), 'number'],
            6  => ['deployed_tag', __('Version déployée', 'dockerinventory'), 'string'],
            7  => ['latest_tag', __('Dernière version', 'dockerinventory'), 'string'],
            8  => ['gap', __('Écart', 'dockerinventory'), 'specific'],
            9  => ['source', __('Source', 'dockerinventory'), 'specific'],
            10 => ['date_checked', __('Vérifié le', 'dockerinventory'), 'datetime'],
            11 => ['image', __('Image', 'dockerinventory'), 'string'],
            12 => ['is_out_of_sync', __('Réplicas désynchronisés', 'dockerinventory'), 'bool'],
            13 => ['containers', __('Conteneurs WUD', 'dockerinventory'), 'text'],
            14 => ['deployed_digest', __('Empreinte déployée', 'dockerinventory'), 'string'],
            15 => ['latest_digest', __('Dernière empreinte', 'dockerinventory'), 'string'],
            17 => ['match_method', __('Rattachement', 'dockerinventory'), 'specific'],
            18 => ['registry_name', __('Registre', 'dockerinventory'), 'string'],
            19 => ['update_available', __('Mise à jour disponible', 'dockerinventory'), 'bool'],
        ];
        foreach ($own as $id => [$field, $name, $datatype]) {
            $options[] = [
                'id'            => $id,
                'table'         => $table,
                'field'         => $field,
                'name'          => $name,
                'datatype'      => $datatype,
                'massiveaction' => false,
            ] + ($datatype === 'specific' ? ['searchtype' => ['equals', 'notequals']] : []);
        }
        $options[] = [
            'id'            => 16,
            'table'         => Connection::getTable(),
            'field'         => 'name',
            'name'          => Connection::getTypeName(1),
            'datatype'      => 'itemlink',
            'massiveaction' => false,
        ];
        $options[] = [
            'id'            => 80,
            'table'         => 'glpi_entities',
            'field'         => 'completename',
            'name'          => \Entity::getTypeName(1),
            'datatype'      => 'dropdown',
            'massiveaction' => false,
        ];

        return $options;
    }

    /**
     * @param array<string, mixed>|string $values
     * @param array<string, mixed> $options
     */
    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        $value = is_array($values) ? (string) ($values[$field] ?? '') : (string) $values;

        return match ($field) {
            'gap' => TemplateRenderer::getInstance()->render('@dockerinventory/_version_badge.html.twig', [
                'gap' => self::gapInfo($value),
            ]),
            'source'       => htmlescape(self::sourceLabels()[$value] ?? $value),
            'match_method' => htmlescape(self::matchLabels()[$value] ?? $value),
            default        => parent::getSpecificValueToDisplay($field, $values, $options),
        };
    }

    /**
     * @param array<string, mixed>|string $values
     * @param array<string, mixed> $options
     */
    public static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = [])
    {
        $choices = match ($field) {
            'gap'          => array_map(static fn (array $info): string => $info['label'], self::gapInfos()),
            'source'       => self::sourceLabels(),
            'match_method' => self::matchLabels(),
            default        => null,
        };
        if ($choices === null) {
            return parent::getSpecificValueToSelect($field, $name, $values, $options);
        }
        $value = is_array($values) ? (string) ($values[$field] ?? '') : (string) $values;

        return Dropdown::showFromArray((string) $name, $choices, ['display' => false, 'value' => $value] + $options);
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ((!$item instanceof Service && !$item instanceof Appliance) || $item->isNewItem() || !self::canView()) {
            return '';
        }
        $count = 0;
        if ($_SESSION['glpishow_count_on_tabs'] ?? false) {
            $count = count(self::rowsFor($item));
        }

        return self::createTabEntry(__('Versions', 'dockerinventory'), $count, static::class, self::getIcon());
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        // ajax/common.tabs.php sert le contenu sans passer par getTabNameForItem().
        if (
            (!$item instanceof Service && !$item instanceof Appliance)
            || !self::canView()
            || !$item->can($item->getID(), READ)
        ) {
            return false;
        }
        $rows = array_map(self::present(...), self::rowsFor($item));
        TemplateRenderer::getInstance()->display('@dockerinventory/version_tab.html.twig', [
            'rows'          => $rows,
            'for_service'   => $item instanceof Service,
            'overview_url'  => self::getOverviewURL(),
            'has_wud'       => countElementsInTable(Connection::getTable(), ['type' => Connection::TYPE_WUD]) > 0,
            'has_registry'  => countElementsInTable(static::getTable(), ['source' => self::SOURCE_REGISTRY]) > 0,
            'can_configure' => Connection::canView(),
            'connection_url' => Connection::getSearchURL(),
            'update_ticket' => self::updateTicket($item),
            'eol_ticket'    => self::eolTicket($item),
            'eol_enabled'   => Settings::enabled(),
            'lifecycle_url' => LifecycleProduct::getPageURL(),
            'can_configure_lifecycle' => LifecycleProduct::canView(),
        ]);

        return true;
    }

    /**
     * Ticket de mise à jour d'un service (lien dans l'onglet), si l'utilisateur peut voir les tickets.
     *
     * @return array{id: int, name: string, status: string, is_open: bool, url: string}|null
     */
    private static function updateTicket(CommonGLPI $item): ?array
    {
        if (!$item instanceof Service || !\Ticket::canView()) {
            return null;
        }
        $ticket = UpdateTickets::ticketFor((int) $item->getID());

        return $ticket === null ? null : $ticket + ['url' => \Ticket::getFormURLWithID($ticket['id'])];
    }

    /**
     * Ticket de fin de support d'un service (lien dans l'onglet), si l'utilisateur peut voir les tickets.
     *
     * @return array{id: int, name: string, status: string, is_open: bool, url: string}|null
     */
    private static function eolTicket(CommonGLPI $item): ?array
    {
        if (!$item instanceof Service || !\Ticket::canView()) {
            return null;
        }
        $ticket = EolTickets::ticketFor((int) $item->getID());

        return $ticket === null ? null : $ticket + ['url' => \Ticket::getFormURLWithID($ticket['id'])];
    }

    /**
     * Lignes de version d'un service, ou des services d'une application.
     *
     * @return list<array<string, mixed>>
     */
    private static function rowsFor(CommonDBTM $item): array
    {
        $where = $item instanceof Service
            ? ['v.plugin_dockerinventory_services_id' => (int) $item->getID()]
            : ['s.appliances_id' => (int) $item->getID(), 'v.plugin_dockerinventory_services_id' => ['>', 0]];

        return self::fetch($where);
    }

    /**
     * Lignes visibles (entités actives), avec le service, l'application et l'environnement.
     *
     * @param array<int|string, mixed> $where
     * @return list<array<string, mixed>>
     */
    public static function fetch(array $where): array
    {
        global $DB;

        $iterator = $DB->request([
            'SELECT'    => [
                'v.*',
                's.name AS service_name',
                's.stack_name',
                's.appliances_id',
                's.is_retired AS service_retired',
                'a.name AS appliance_name',
                'e.id AS environment_id',
                'e.name AS environment_name',
                'c.name AS connection_name',
            ],
            'FROM'      => static::getTable() . ' AS v',
            'LEFT JOIN' => [
                Service::getTable() . ' AS s' => ['ON' => ['v' => 'plugin_dockerinventory_services_id', 's' => 'id']],
                Appliance::getTable() . ' AS a' => ['ON' => ['s' => 'appliances_id', 'a' => 'id']],
                ApplianceEnvironment::getTable() . ' AS e' => [
                    'ON' => ['a' => 'applianceenvironments_id', 'e' => 'id'],
                ],
                Connection::getTable() . ' AS c' => [
                    'ON' => ['v' => 'plugin_dockerinventory_connections_id', 'c' => 'id'],
                ],
            ],
            'WHERE'     => $where + (new DbUtils())->getEntitiesRestrictCriteria('v', '', '', true),
            'ORDER'     => ['s.stack_name', 's.name', 'v.containers'],
        ]);
        $rows = [];
        foreach ($iterator as $row) {
            $rows[] = $row;
        }
        // Du plus grave au moins grave, puis par stack et service (ordre de la requête).
        usort(
            $rows,
            static fn (array $a, array $b): int => VersionComparator::severity((string) $a['gap'])
                <=> VersionComparator::severity((string) $b['gap'])
        );

        return $rows;
    }

    /**
     * Page des versions : lignes des services et des conteneurs sans service, filtrées par écart,
     * par environnement de l'application et par état du support (fin de support).
     *
     * @param string $gap Écart, FILTER_OUTDATED ou chaîne vide (tous).
     * @param int $environmentId Environnement, 0 (tous) ou -1 (sans environnement).
     * @param string $eol État du support (CycleMatcher::STATUS_*), FILTER_EOL_WATCH ou chaîne vide (tous).
     * @return array{services: list<array<string, mixed>>, unmatched: list<array<string, mixed>>,
     *     counts: array<string, int>, eol_counts: array<string, int>, environments: array<int, string>}
     */
    public static function overview(string $gap, int $environmentId, string $eol = ''): array
    {
        $all = [];
        foreach (self::fetch([]) as $row) {
            $row['_eol'] = LifecycleChecker::current()->evaluate(
                (string) $row['image'],
                (string) $row['deployed_tag']
            )['status'];
            $all[] = $row;
        }
        $counts = array_fill_keys(VersionComparator::GAPS, 0);
        $eolCounts = array_fill_keys(CycleMatcher::STATUSES, 0);
        $environments = [];
        foreach ($all as $row) {
            if ((int) $row['plugin_dockerinventory_services_id'] > 0) {
                $counts[(string) $row['gap']] = ($counts[(string) $row['gap']] ?? 0) + 1;
                $eolCounts[$row['_eol']] = ($eolCounts[$row['_eol']] ?? 0) + 1;
                if ((int) $row['environment_id'] > 0) {
                    $environments[(int) $row['environment_id']] = (string) $row['environment_name'];
                }
            }
        }
        asort($environments);

        $services = [];
        $unmatched = [];
        foreach ($all as $row) {
            $isService = (int) $row['plugin_dockerinventory_services_id'] > 0;
            if (!self::matchesGap((string) $row['gap'], $gap) || !self::matchesEol($row['_eol'], $eol)) {
                continue;
            }
            if (!$isService) {
                // Conteneurs sans service : pas d'application, donc pas d'environnement.
                if ($environmentId === 0) {
                    $unmatched[] = self::present($row);
                }
                continue;
            }
            $rowEnvironment = (int) $row['environment_id'];
            if ($environmentId > 0 && $rowEnvironment !== $environmentId) {
                continue;
            }
            if ($environmentId === -1 && $rowEnvironment > 0) {
                continue;
            }
            $services[] = self::present($row);
        }

        return [
            'services'     => $services,
            'unmatched'    => $unmatched,
            'counts'       => $counts,
            'eol_counts'   => $eolCounts,
            'environments' => $environments,
        ];
    }

    private static function matchesEol(string $status, string $filter): bool
    {
        return match ($filter) {
            ''                     => true,
            self::FILTER_EOL_WATCH => in_array($status, [CycleMatcher::STATUS_EOL, CycleMatcher::STATUS_SOON], true),
            default                => $status === $filter,
        };
    }

    private static function matchesGap(string $rowGap, string $filter): bool
    {
        return match ($filter) {
            ''                    => true,
            self::FILTER_OUTDATED => in_array($rowGap, self::OUTDATED, true),
            default               => $rowGap === $filter,
        };
    }

    /**
     * Valeurs prêtes à afficher (échappées par Twig).
     *
     * @param array<string, mixed> $row Ligne de fetch().
     * @return array<string, mixed>
     */
    public static function present(array $row): array
    {
        $serviceId = (int) ($row['plugin_dockerinventory_services_id'] ?? 0);
        $applianceId = (int) ($row['appliances_id'] ?? 0);
        $method = (string) ($row['match_method'] ?? '');
        $deployedDigest = (string) ($row['deployed_digest'] ?? '');
        $latestDigest = (string) ($row['latest_digest'] ?? '');
        $source = (string) ($row['source'] ?? '');
        $error = (string) ($row['error'] ?? '');
        if ($source === self::SOURCE_REGISTRY && $error !== '') {
            // Code stable enregistré par la vérification des registres : libellé traduit.
            $error = Connection::registryErrorLabels()[$error] ?? $error;
        }

        return [
            'id'               => (int) ($row['id'] ?? 0),
            'service'          => $serviceId > 0 ? [
                'name'       => (string) ($row['service_name'] ?? ''),
                'url'        => Service::getFormURLWithID($serviceId),
                'is_retired' => (bool) ($row['service_retired'] ?? false),
            ] : null,
            'stack_name'       => (string) ($row['stack_name'] ?? ''),
            'appliance'        => $applianceId > 0 && ($row['appliance_name'] ?? null) !== null ? [
                'name' => (string) $row['appliance_name'],
                'url'  => Appliance::canView() ? Appliance::getFormURLWithID($applianceId) : '',
            ] : null,
            'environment'      => (string) ($row['environment_name'] ?? ''),
            'image'            => (string) ($row['image'] ?? ''),
            'registry_name'    => (string) ($row['registry_name'] ?? ''),
            'registry_link'    => self::registryLink(
                (string) ($row['registry_name'] ?? ''),
                (string) ($row['registry_url'] ?? ''),
                (string) ($row['image'] ?? '')
            ),
            'deployed_tag'     => (string) ($row['deployed_tag'] ?? ''),
            'deployed_digest'  => $deployedDigest,
            'deployed_short'   => VersionLinker::shortDigest($deployedDigest),
            'latest_tag'       => (string) ($row['latest_tag'] ?? ''),
            'latest_digest'    => $latestDigest,
            'latest_short'     => VersionLinker::shortDigest($latestDigest),
            'digest_changed'   => $latestDigest !== '' && $deployedDigest !== '' && $latestDigest !== $deployedDigest,
            'gap'              => self::gapInfo((string) ($row['gap'] ?? '')),
            'gap_code'         => (string) ($row['gap'] ?? ''),
            'update_available' => (bool) ($row['update_available'] ?? false),
            'is_out_of_sync'   => (bool) ($row['is_out_of_sync'] ?? false),
            'deployed_versions' => (string) ($row['deployed_versions'] ?? ''),
            'containers'       => array_values(array_filter(explode(', ', (string) ($row['containers'] ?? '')))),
            'container_count'  => (int) ($row['container_count'] ?? 0),
            'match'            => self::matchLabels()[$method] ?? $method,
            'match_code'       => $method,
            'source'           => self::sourceLabels()[$source] ?? $source,
            'source_code'      => $source,
            'connection_name'  => (string) ($row['connection_name'] ?? ''),
            'error'            => $error,
            'date_checked'     => (string) ($row['date_checked'] ?? ''),
            'eol'              => LifecycleProduct::present(LifecycleChecker::current()->evaluate(
                (string) ($row['image'] ?? ''),
                (string) ($row['deployed_tag'] ?? '')
            )),
        ];
    }

    /**
     * Page de l'image dans le registre, quand on la connaît (Docker Hub). Chaîne vide sinon.
     */
    public static function registryLink(string $registryName, string $registryUrl, string $image): string
    {
        if ($image === '') {
            return '';
        }
        $host = strtolower((string) parse_url($registryUrl, PHP_URL_HOST));
        $isHub = in_array($host, WudMapper::DOCKER_HUB_HOSTS, true)
            || ($host === '' && strtolower($registryName) === 'hub');
        if (!$isHub) {
            return '';
        }
        $path = implode('/', array_map('rawurlencode', explode('/', $image)));

        return str_starts_with($image, 'library/')
            ? 'https://hub.docker.com/_/' . rawurlencode(substr($image, 8))
            : 'https://hub.docker.com/r/' . $path;
    }
}

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
use GlpiPlugin\Dockerinventory\Hardening\HardeningMapper;

/**
 * Configuration à risque des services (#71) : mode privilégié, root, socket Docker, réseau ou PID
 * de l'hôte, capacités ajoutées, absence de limites, système de fichiers en écriture, montages
 * sensibles de l'hôte, relevés par l'import (lecture seule). Onglet « Configuration à risque » des
 * services et sous-page du même nom de l'entrée Parc > Inventaire Docker.
 *
 * Le plugin constate ; la personne corrige sur la plateforme ou enregistre une exception justifiée
 * et datée (HardeningException). Rien n'est jamais modifié sur les plateformes.
 */
class Hardening extends CommonGLPI
{
    /** Filtre de la sous-page : services avec au moins une exception. */
    public const FILTER_EXCEPTION = 'exception';

    public static function getTypeName($nb = 0)
    {
        return __('Configuration à risque', 'dockerinventory');
    }

    /**
     * @return string
     */
    public static function getIcon()
    {
        return 'ti ti-shield-exclamation';
    }

    public static function canView(): bool
    {
        return Service::canView();
    }

    public static function getPageURL(bool $full = true): string
    {
        return ImageVersion::getOverviewURL($full) . '?view=' . Menu::TAB_HARDENING;
    }

    public static function getFormURLForActions(): string
    {
        return \Plugin::getWebDir('dockerinventory') . '/front/hardening.form.php';
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof Service || $item->isNewItem() || !self::canView()) {
            return '';
        }
        $count = 0;
        if ($_SESSION['glpishow_count_on_tabs'] ?? false) {
            $count = self::describe($item->fields)['open'];
        }

        return self::createTabEntry(self::getTypeName(1), $count, static::class, self::getIcon());
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        // ajax/common.tabs.php sert le contenu sans passer par getTabNameForItem().
        if (!$item instanceof Service || !self::canView() || !$item->can($item->getID(), READ)) {
            return false;
        }
        TemplateRenderer::getInstance()->display('@dockerinventory/hardening_tab.html.twig', [
            'service'  => self::describe($item->fields),
        ] + self::viewContext());

        return true;
    }

    /**
     * Variables communes de l'onglet et de la sous-page.
     *
     * @return array<string, mixed>
     */
    public static function viewContext(): array
    {
        $today = substr((string) $_SESSION['glpi_currenttime'], 0, 10);

        return [
            'infos'       => self::infos(),
            'severities'  => self::severityInfos(),
            'page_url'    => self::getPageURL(),
            'action_url'  => self::getFormURLForActions(),
            'can_create'  => HardeningException::canCreate(),
            'can_purge'   => HardeningException::canPurge(),
            'today'       => $today,
            'max_date'    => date('Y-m-d', (int) strtotime($today . ' +' . HardeningException::MAX_DAYS . ' days')),
            'default_end' => date('Y-m-d', (int) strtotime($today . ' +90 days')),
        ];
    }

    /**
     * Gravités : libellé, couleur, icône.
     *
     * @return array<string, array{label: string, color: string, icon: string, help: string}>
     */
    public static function severityInfos(): array
    {
        return [
            HardeningMapper::SEVERITY_CRITICAL => [
                'label' => __('Critique', 'dockerinventory'),
                'color' => 'danger',
                'icon'  => 'ti ti-alert-octagon',
                'help'  => __('Donne la main sur l’hôte : à corriger en priorité.', 'dockerinventory'),
            ],
            HardeningMapper::SEVERITY_HIGH => [
                'label' => __('Haute', 'dockerinventory'),
                'color' => 'orange',
                'icon'  => 'ti ti-alert-triangle',
                'help'  => __('Affaiblit fortement l’isolement du conteneur.', 'dockerinventory'),
            ],
            HardeningMapper::SEVERITY_MEDIUM => [
                'label' => __('Moyenne', 'dockerinventory'),
                'color' => 'warning',
                'icon'  => 'ti ti-alert-circle',
                'help'  => __('Aggrave les conséquences d’une compromission.', 'dockerinventory'),
            ],
            HardeningMapper::SEVERITY_LOW => [
                'label' => __('Basse', 'dockerinventory'),
                'color' => 'info',
                'icon'  => 'ti ti-info-circle',
                'help'  => __('Bonne pratique de durcissement non appliquée.', 'dockerinventory'),
            ],
        ];
    }

    /**
     * Écarts : libellé, explication et correction conseillée.
     *
     * @return array<string, array{label: string, help: string, fix: string, icon: string}>
     */
    public static function infos(): array
    {
        return [
            HardeningMapper::PRIVILEGED => [
                'label' => __('Mode privilégié', 'dockerinventory'),
                'icon'  => 'ti ti-crown',
                'help'  => __('Le conteneur a tous les droits sur l’hôte et ses périphériques.', 'dockerinventory'),
                'fix'   => __('Retirer « privileged » et n’ajouter que les capacités nécessaires.', 'dockerinventory'),
            ],
            HardeningMapper::DOCKER_SOCKET => [
                'label' => __('Socket du moteur de conteneurs monté', 'dockerinventory'),
                'icon'  => 'ti ti-plug-connected',
                'help'  => __(
                    'Qui accède à ce socket pilote le moteur, donc l’hôte entier.',
                    'dockerinventory'
                ),
                'fix'   => __(
                    'Retirer le montage, ou passer par un proxy du socket en lecture seule limité aux appels utiles.',
                    'dockerinventory'
                ),
            ],
            HardeningMapper::HOST_NETWORK => [
                'label' => __('Réseau de l’hôte', 'dockerinventory'),
                'icon'  => 'ti ti-network',
                'help'  => __(
                    'Le conteneur partage les interfaces réseau de l’hôte : aucun isolement réseau.',
                    'dockerinventory'
                ),
                'fix'   => __('Utiliser un réseau dédié et publier seulement les ports utiles.', 'dockerinventory'),
            ],
            HardeningMapper::HOST_PID => [
                'label' => __('Processus de l’hôte visibles', 'dockerinventory'),
                'icon'  => 'ti ti-list-details',
                'help'  => __('Le conteneur voit et peut viser les processus de l’hôte.', 'dockerinventory'),
                'fix'   => __('Retirer le partage de l’espace des processus de l’hôte.', 'dockerinventory'),
            ],
            HardeningMapper::DANGEROUS_CAPABILITIES => [
                'label' => __('Capacités dangereuses ajoutées', 'dockerinventory'),
                'icon'  => 'ti ti-key',
                'help'  => __(
                    'Ces capacités permettent de sortir du conteneur ou d’agir sur l’hôte.',
                    'dockerinventory'
                ),
                'fix'   => __(
                    'Retirer ces capacités ou les remplacer par une permission plus étroite.',
                    'dockerinventory'
                ),
            ],
            HardeningMapper::SENSITIVE_MOUNT => [
                'label' => __('Répertoire sensible de l’hôte monté', 'dockerinventory'),
                'icon'  => 'ti ti-folder-exclamation',
                'help'  => __(
                    'Le conteneur lit ou modifie des fichiers système de l’hôte.',
                    'dockerinventory'
                ),
                'fix'   => __(
                    'Monter un volume ou un sous-répertoire dédié, en lecture seule si possible.',
                    'dockerinventory'
                ),
            ],
            HardeningMapper::HOST_IPC => [
                'label' => __('Mémoire partagée de l’hôte', 'dockerinventory'),
                'icon'  => 'ti ti-arrows-exchange',
                'help'  => __('Le conteneur partage les communications entre processus de l’hôte.', 'dockerinventory'),
                'fix'   => __('Retirer le partage de l’espace IPC de l’hôte.', 'dockerinventory'),
            ],
            HardeningMapper::ADDED_CAPABILITIES => [
                'label' => __('Capacités ajoutées', 'dockerinventory'),
                'icon'  => 'ti ti-key',
                'help'  => __('Droits du noyau en plus de ceux accordés par défaut.', 'dockerinventory'),
                'fix'   => __('Vérifier que chaque capacité est nécessaire.', 'dockerinventory'),
            ],
            HardeningMapper::ROOT_USER => [
                'label' => __('Exécution en root', 'dockerinventory'),
                'icon'  => 'ti ti-user-exclamation',
                'help'  => __(
                    'Aucun utilisateur non privilégié n’est défini : le processus tourne sans doute en root.',
                    'dockerinventory'
                ),
                'fix'   => __('Définir un utilisateur non privilégié (user, runAsNonRoot).', 'dockerinventory'),
            ],
            HardeningMapper::NO_MEMORY_LIMIT => [
                'label' => __('Aucune limite de mémoire', 'dockerinventory'),
                'icon'  => 'ti ti-database',
                'help'  => __('Le conteneur peut épuiser la mémoire de l’hôte.', 'dockerinventory'),
                'fix'   => __('Fixer une limite de mémoire adaptée.', 'dockerinventory'),
            ],
            HardeningMapper::NO_CPU_LIMIT => [
                'label' => __('Aucune limite de processeur', 'dockerinventory'),
                'icon'  => 'ti ti-cpu',
                'help'  => __('Le conteneur peut accaparer le processeur de l’hôte.', 'dockerinventory'),
                'fix'   => __('Fixer une limite de processeur adaptée.', 'dockerinventory'),
            ],
            HardeningMapper::WRITABLE_ROOTFS => [
                'label' => __('Système de fichiers en écriture', 'dockerinventory'),
                'icon'  => 'ti ti-pencil',
                'help'  => __(
                    'Un attaquant peut modifier les fichiers de l’image dans le conteneur.',
                    'dockerinventory'
                ),
                'fix'   => __(
                    'Passer le système de fichiers en lecture seule, avec des volumes pour les données.',
                    'dockerinventory'
                ),
            ],
        ];
    }

    /**
     * Service, ses écarts et leurs exceptions.
     *
     * @param array<string, mixed> $row Ligne de la table des services.
     * @param array<string, array<string, mixed>>|null $exceptions Exceptions du service par écart
     *     (HardeningException::forServices()) ; null : lues ici.
     * @return array{id: int, name: string, url: string, stack_name: string, entities_id: int,
     *     is_retired: bool, analysed: bool, findings: list<array<string, mixed>>, open: int,
     *     worst: string, excepted: int, expired: int}
     */
    public static function describe(array $row, ?array $exceptions = null): array
    {
        $id = (int) ($row['id'] ?? 0);
        $findings = HardeningMapper::decode(isset($row['hardening']) ? (string) $row['hardening'] : null);
        $exceptions ??= HardeningException::forServices([$id])[$id] ?? [];
        $ends = array_map(static fn (array $e): string => (string) $e['date_end'], $exceptions);
        $rows = [];
        $open = [];
        $excepted = 0;
        $expired = 0;
        $today = (string) $_SESSION['glpi_currenttime'];
        foreach (HardeningMapper::evaluate($findings ?? [], $ends, $today) as $finding) {
            if ($finding['exception'] === 'active') {
                $excepted++;
            } else {
                $open[] = $finding['code'];
                $expired += $finding['exception'] === 'expired' ? 1 : 0;
            }
            $rows[] = $finding + ['exception_row' => $exceptions[$finding['code']] ?? null];
        }

        return [
            'id'          => $id,
            'name'        => (string) ($row['name'] ?? ''),
            'url'         => Service::getFormURLWithID($id),
            'stack_name'  => (string) ($row['stack_name'] ?? ''),
            'entities_id' => (int) ($row['entities_id'] ?? 0),
            'is_retired'  => (bool) ($row['is_retired'] ?? false),
            'analysed'    => $findings !== null,
            'findings'    => $rows,
            'open'        => count($open),
            'worst'       => HardeningMapper::worst($open),
            'excepted'    => $excepted,
            'expired'     => $expired,
        ];
    }

    /**
     * Services actifs analysés, visibles dans les entités actives, avec leurs écarts.
     *
     * @return array{services: list<array<string, mixed>>, counts: array{analysed: int, compliant: int,
     *     not_analysed: int, with_exception: int, critical: int, high: int, medium: int, low: int},
     *     rate: int|null, rate_serious: int|null}
     *     `rate` : part des services analysés sans écart ouvert (pour cent), null si aucun ;
     *     `rate_serious` : part sans écart ouvert critique ni haut.
     */
    public static function overview(string $filter = ''): array
    {
        global $DB;

        $rows = [];
        foreach (
            $DB->request([
                'FROM'  => Service::getTable(),
                'WHERE' => ['is_retired' => 0]
                    + (new DbUtils())->getEntitiesRestrictCriteria(Service::getTable(), '', '', true),
                'ORDER' => ['stack_name', 'name'],
            ]) as $row
        ) {
            $rows[] = $row;
        }
        $exceptions = HardeningException::forServices(array_map(static fn (array $r): int => (int) $r['id'], $rows));

        $counts = [
            'analysed' => 0, 'compliant' => 0, 'not_analysed' => 0, 'with_exception' => 0,
            HardeningMapper::SEVERITY_CRITICAL => 0, HardeningMapper::SEVERITY_HIGH => 0,
            HardeningMapper::SEVERITY_MEDIUM => 0, HardeningMapper::SEVERITY_LOW => 0,
        ];
        $services = [];
        foreach ($rows as $row) {
            $service = self::describe($row, $exceptions[(int) $row['id']] ?? []);
            if (!$service['analysed']) {
                $counts['not_analysed']++;
                continue;
            }
            $counts['analysed']++;
            if ($service['open'] === 0) {
                $counts['compliant']++;
            } else {
                $counts[$service['worst']]++;
            }
            $hasException = $service['excepted'] + $service['expired'] > 0;
            $counts['with_exception'] += $hasException ? 1 : 0;
            if ($service['findings'] === []) {
                continue;
            }
            if (
                ($filter === self::FILTER_EXCEPTION && !$hasException)
                || (in_array($filter, HardeningMapper::SEVERITIES, true) && $service['worst'] !== $filter)
            ) {
                continue;
            }
            $services[] = $service;
        }
        // Les plus graves d'abord, puis le plus d'écarts ouverts.
        $rank = array_flip(array_merge(HardeningMapper::SEVERITIES, ['']));
        usort(
            $services,
            static fn (array $a, array $b): int
                => [$rank[$a['worst']], -$a['open']] <=> [$rank[$b['worst']], -$b['open']]
        );

        $serious = $counts[HardeningMapper::SEVERITY_CRITICAL] + $counts[HardeningMapper::SEVERITY_HIGH];

        return [
            'services'     => $services,
            'counts'       => $counts,
            'rate'         => self::rate($counts['compliant'], $counts['analysed']),
            'rate_serious' => self::rate($counts['analysed'] - $serious, $counts['analysed']),
        ];
    }

    /**
     * Pourcentage arrondi à l'inférieur (100 seulement si tout est conforme), null sans total.
     */
    public static function rate(int $part, int $total): ?int
    {
        return $total > 0 ? (int) floor(100 * $part / $total) : null;
    }
}

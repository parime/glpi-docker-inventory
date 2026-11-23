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
use GlpiPlugin\Dockerinventory\Compat\Base\VolumeBackupBase;
use GlpiPlugin\Dockerinventory\Kubernetes\KubernetesMapper;
use GlpiPlugin\Dockerinventory\Portainer\PortainerMapper;
use Session;

/**
 * Sauvegarde déclarée d'un volume (#78) : pour chaque volume nommé (Docker, Swarm) ou volume
 * persistant (Kubernetes) d'un service, la personne indique ce qui le sauvegarde (outil, tâche,
 * procédure) et la date de la dernière vérification (restauration testée, rapport relu). Un volume
 * sans déclaration est signalé. Le plugin ne lance ni ne contrôle aucune sauvegarde : c'est une
 * déclaration, tenue à jour par la personne, avec historique.
 *
 * Une déclaration par service et par volume ; l'entité est celle du service. Droit propre au
 * plugin : enregistrer (CREATE) et supprimer (PURGE), en plus de la lecture des services.
 * La colonne `volumes_unbacked` des services (volumes sans déclaration) est recalculée à chaque
 * import et à chaque déclaration : elle sert au filtre de la liste des services.
 */
class VolumeBackup extends VolumeBackupBase
{
    public const RIGHTNAME = 'plugin_dockerinventory_volumebackup';

    /** Option de recherche des services : nombre de volumes sans sauvegarde déclarée. */
    public const SEARCH_OPTION = 22;

    /** Longueur maximale du texte « Sauvegardé par ». */
    public const MAX_TEXT = 255;

    public static function getTypeName($nb = 0)
    {
        return $nb > 1
            ? __('Sauvegardes des volumes', 'dockerinventory')
            : __('Sauvegarde de volume', 'dockerinventory');
    }

    public static function getIcon()
    {
        return 'ti ti-database-export';
    }

    public static function canView(): bool
    {
        return Service::canView();
    }

    public static function canCreate(): bool
    {
        return Service::canView() && (bool) Session::haveRight(self::RIGHTNAME, CREATE);
    }

    public static function canUpdate(): bool
    {
        return self::canCreate();
    }

    public static function canDelete(): bool
    {
        return false;
    }

    public static function canPurge(): bool
    {
        return Service::canView() && (bool) Session::haveRight(self::RIGHTNAME, PURGE);
    }

    /**
     * @return array<int, string>
     */
    public function getRights($interface = 'central')
    {
        return [
            CREATE => __('Déclarer une sauvegarde', 'dockerinventory'),
            PURGE  => __('Supprimer une déclaration de sauvegarde', 'dockerinventory'),
        ];
    }

    public static function getFormURLForActions(): string
    {
        global $CFG_GLPI;

        return (string) $CFG_GLPI['root_doc'] . '/plugins/dockerinventory/front/volumebackup.form.php';
    }

    /**
     * Liste des services actifs qui ont au moins un volume sans sauvegarde déclarée (recherche GLPI).
     */
    public static function unbackedSearchURL(): string
    {
        return Service::getSearchURL() . '?' . http_build_query([
            'criteria' => [
                ['field' => self::SEARCH_OPTION, 'searchtype' => 'contains', 'value' => '>0'],
                ['link' => 'AND', 'field' => 10, 'searchtype' => 'equals', 'value' => 0],
            ],
            'reset'    => 'reset',
        ]);
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof Service || $item->isNewItem() || !self::canView()) {
            return '';
        }
        $count = 0;
        if ($_SESSION['glpishow_count_on_tabs'] ?? false) {
            $count = (int) ($item->fields['volumes_unbacked'] ?? 0);
        }

        return self::createTabEntry(
            __('Volumes et sauvegarde', 'dockerinventory'),
            $count,
            static::class,
            self::getIcon()
        );
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        // ajax/common.tabs.php sert le contenu sans passer par getTabNameForItem().
        if (!$item instanceof Service || !self::canView() || !$item->can($item->getID(), READ)) {
            return false;
        }
        TemplateRenderer::getInstance()->display('@dockerinventory/volume_backups_tab.html.twig', [
            'service'    => self::describe($item->fields),
            'action_url' => self::getFormURLForActions(),
            'can_create' => self::canCreate(),
            'can_purge'  => self::canPurge(),
            'today'      => substr((string) $_SESSION['glpi_currenttime'], 0, 10),
            'list_url'   => self::unbackedSearchURL(),
        ]);

        return true;
    }

    /**
     * Volumes d'un service (ligne de la table des services), avec leur déclaration de sauvegarde.
     *
     * @param array<string, mixed> $row
     * @return array{id: int, name: string, is_kubernetes: bool, volumes: list<array{name: string,
     *     label: string, anonymous: bool, backup: array{id: int, backed_up_by: string, date_verified: string,
     *     user: string, date_mod: string}|null}>, unbacked: int}
     */
    public static function describe(array $row): array
    {
        $serviceId = (int) ($row['id'] ?? 0);
        $declared = self::forServices([$serviceId])[$serviceId] ?? [];
        $volumes = [];
        $unbacked = 0;
        foreach (self::volumeNames((string) ($row['volumes'] ?? '')) as $name) {
            $backup = $declared[$name] ?? null;
            $unbacked += $backup === null ? 1 : 0;
            $volumes[] = [
                'name'      => $name,
                'label'     => self::label($name),
                'anonymous' => PortainerMapper::isAnonymousVolume($name),
                'backup'    => $backup,
            ];
        }

        return [
            'id'            => $serviceId,
            'name'          => (string) ($row['name'] ?? ''),
            'is_kubernetes' => in_array((string) ($row['kind'] ?? ''), KubernetesMapper::workloadKinds(), true),
            'volumes'       => $volumes,
            'unbacked'      => $unbacked,
        ];
    }

    /**
     * Noms des volumes de la colonne `volumes` d'un service (séparés par « , »).
     *
     * @return list<string>
     */
    public static function volumeNames(string $volumes): array
    {
        return array_values(array_filter(
            array_map('trim', explode(', ', $volumes)),
            static fn (string $name): bool => $name !== ''
        ));
    }

    /**
     * Nombre de volumes sans déclaration, parmi les volumes donnés.
     *
     * @param list<string> $volumes
     * @param array<string, mixed> $declared Déclarations, par nom de volume.
     */
    public static function unbackedCount(array $volumes, array $declared): int
    {
        return count(array_filter($volumes, static fn (string $name): bool => !isset($declared[$name])));
    }

    /**
     * Date de vérification acceptée : vide, ou AAAA-MM-JJ valide qui n'est pas dans le futur.
     */
    public static function isValidVerifiedDate(string $date, string $today): bool
    {
        if ($date === '') {
            return true;
        }
        $verified = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $now = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($today, 0, 10));

        return $verified !== false && $now !== false && $verified->format('Y-m-d') === $date && $verified <= $now;
    }

    /**
     * Enregistre (ou met à jour) la sauvegarde déclarée d'un volume d'un service que la personne voit.
     *
     * @return string '' si la déclaration est enregistrée, sinon le motif du refus (traduit).
     */
    public static function record(int $serviceId, string $volume, string $backedUpBy, string $dateVerified): string
    {
        $service = new Service();
        if (!self::canCreate() || !$service->can($serviceId, READ)) {
            return __('Vous n’avez pas le droit de déclarer cette sauvegarde.', 'dockerinventory');
        }
        if (!in_array($volume, self::volumeNames((string) $service->fields['volumes']), true)) {
            return __('Ce volume n’est pas monté par ce service.', 'dockerinventory');
        }
        $backedUpBy = trim($backedUpBy);
        if ($backedUpBy === '') {
            return __('Indiquez ce qui sauvegarde ce volume.', 'dockerinventory');
        }
        $dateVerified = trim($dateVerified);
        if (!self::isValidVerifiedDate($dateVerified, (string) $_SESSION['glpi_currenttime'])) {
            return __('La date de vérification doit être une date passée ou aujourd’hui.', 'dockerinventory');
        }
        $values = [
            'backed_up_by'  => mb_substr($backedUpBy, 0, self::MAX_TEXT),
            'date_verified' => $dateVerified === '' ? 'NULL' : $dateVerified,
            'users_id'      => (int) Session::getLoginUserID(),
        ];
        $backup = new self();
        if ($backup->getFromDBByCrit(['plugin_dockerinventory_services_id' => $serviceId, 'volume' => $volume])) {
            $ok = $backup->update(['id' => $backup->getID()] + $values);
        } else {
            $ok = $backup->add($values + [
                'plugin_dockerinventory_services_id' => $serviceId,
                'volume'                             => $volume,
                'entities_id'                        => (int) $service->fields['entities_id'],
            ]) !== false;
        }
        self::refreshService($serviceId);

        return $ok ? '' : __('La déclaration n’a pas pu être enregistrée.', 'dockerinventory');
    }

    /**
     * Supprime une déclaration d'un service que la personne voit.
     */
    public static function remove(int $id): bool
    {
        $backup = new self();
        if (!self::canPurge() || !$backup->getFromDB($id)) {
            return false;
        }
        $serviceId = (int) $backup->fields['plugin_dockerinventory_services_id'];
        if (!(new Service())->can($serviceId, READ)) {
            return false;
        }
        $ok = $backup->delete(['id' => $id], true);
        self::refreshService($serviceId);

        return $ok;
    }

    /**
     * Recalcule la colonne `volumes_unbacked` d'un service (après un import ou une déclaration).
     * Écriture directe : ce compteur n'est pas une modification du service.
     */
    public static function refreshService(int $serviceId): void
    {
        global $DB;

        if ($serviceId <= 0 || !$DB->fieldExists(Service::getTable(), 'volumes_unbacked')) {
            return;
        }
        $row = $DB->request([
            'SELECT' => ['volumes'],
            'FROM'   => Service::getTable(),
            'WHERE'  => ['id' => $serviceId],
            'LIMIT'  => 1,
        ])->current();
        if (!is_array($row)) {
            return;
        }
        $count = self::unbackedCount(
            self::volumeNames((string) ($row['volumes'] ?? '')),
            self::forServices([$serviceId])[$serviceId] ?? []
        );
        $DB->update(Service::getTable(), ['volumes_unbacked' => $count], ['id' => $serviceId]);
    }

    /**
     * Déclarations des services donnés, par service puis par volume.
     *
     * @param list<int> $serviceIds
     * @return array<int, array<string, array{id: int, backed_up_by: string, date_verified: string,
     *     user: string, date_mod: string}>>
     */
    public static function forServices(array $serviceIds): array
    {
        global $DB;

        $serviceIds = array_values(array_filter(
            array_map('intval', $serviceIds),
            static fn (int $id): bool => $id > 0
        ));
        if ($serviceIds === [] || !$DB->tableExists(self::getTable())) {
            return [];
        }
        $byService = [];
        foreach (
            $DB->request([
                'FROM'  => self::getTable(),
                'WHERE' => ['plugin_dockerinventory_services_id' => $serviceIds],
            ]) as $row
        ) {
            $byService[(int) $row['plugin_dockerinventory_services_id']][(string) $row['volume']] = [
                'id'            => (int) $row['id'],
                'backed_up_by'  => (string) $row['backed_up_by'],
                'date_verified' => substr((string) ($row['date_verified'] ?? ''), 0, 10),
                'user'          => self::userName((int) $row['users_id']),
                'date_mod'      => (string) ($row['date_mod'] ?? ''),
            ];
        }

        return $byService;
    }

    /**
     * Synthèse des volumes des services actifs visibles (entités actives) : volumes, volumes
     * déclarés sauvegardés, services qui ont au moins un volume sans déclaration.
     *
     * @return array{volumes: int, backed_up: int, services_with_volumes: int, services_unbacked: int,
     *     services: list<array{service: string, url: string, stack: string, entities_id: int,
     *     volumes: list<array{name: string, label: string, backed_up_by: string, date_verified: string}>,
     *     unbacked: int}>}
     */
    public static function overview(): array
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
            $rows[(int) $row['id']] = $row;
        }
        $declared = self::forServices(array_keys($rows));
        $result = [
            'volumes'               => 0,
            'backed_up'             => 0,
            'services_with_volumes' => 0,
            'services_unbacked'     => 0,
            'services'              => [],
        ];
        foreach ($rows as $id => $row) {
            $volumes = [];
            $unbacked = 0;
            foreach (self::volumeNames((string) $row['volumes']) as $name) {
                $backup = $declared[$id][$name] ?? null;
                $unbacked += $backup === null ? 1 : 0;
                $volumes[] = [
                    'name'          => $name,
                    'label'         => self::label($name),
                    'backed_up_by'  => $backup['backed_up_by'] ?? '',
                    'date_verified' => $backup['date_verified'] ?? '',
                ];
            }
            if ($volumes === []) {
                continue;
            }
            $result['volumes'] += count($volumes);
            $result['backed_up'] += count($volumes) - $unbacked;
            $result['services_with_volumes']++;
            $result['services_unbacked'] += $unbacked > 0 ? 1 : 0;
            $result['services'][] = [
                'service'     => (string) $row['name'],
                'url'         => Service::getFormURLWithID($id),
                'stack'       => (string) $row['stack_name'],
                'entities_id' => (int) $row['entities_id'],
                'volumes'     => $volumes,
                'unbacked'    => $unbacked,
            ];
        }

        return $result;
    }

    /**
     * Libellé d'un volume : son nom, ou « volume anonyme » et le début de son identifiant.
     */
    public static function label(string $name): string
    {
        return PortainerMapper::isAnonymousVolume($name)
            ? sprintf(__('volume anonyme %s', 'dockerinventory'), substr($name, 0, 12))
            : $name;
    }

    /**
     * Nom affiché de l'auteur d'une déclaration, '' s'il est inconnu.
     */
    private static function userName(int $userId): string
    {
        if ($userId <= 0) {
            return '';
        }
        $user = new \User();

        return $user->getFromDB($userId) ? (string) $user->getFriendlyName() : '';
    }
}

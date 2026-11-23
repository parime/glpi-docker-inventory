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

use GlpiPlugin\Dockerinventory\Exploit\ExploitCatalog;
use GlpiPlugin\Dockerinventory\Lifecycle\LifecycleChecker;
use GlpiPlugin\Dockerinventory\Registry\RegistryImporter;
use Session;

/**
 * Lectures manuelles (« … maintenant ») des écrans d'usage de Parc > Inventaire Docker (#118) :
 * sources Portainer, Kubernetes, WUD, Traefik et registres (onglet Services), Harbor (onglet Images
 * Harbor), cycles de vie (onglet Fin de support), catalogues KEV et EPSS (onglet Analyses importées),
 * certificats TLS des routes d'entrée (onglet Exposition, #78).
 * Envoyées à front/source.form.php ; même droit qu'avant le déplacement (mise à jour de la
 * configuration de GLPI). La page de configuration garde les réglages et « Tester la connexion ».
 *
 * Chaque action renvoie un message et son niveau (INFO, WARNING, ERROR), affiché après redirection.
 */
final class ManualRead
{
    public const ACTION_READ = 'read';
    public const ACTION_HARBOR = 'harbor_read';
    public const ACTION_REGISTRIES = 'registry_check';
    public const ACTION_LIFECYCLE = 'eol_refresh';
    public const ACTION_EXPLOITS_DOWNLOAD = 'download_exploits';
    public const ACTION_EXPLOITS_FILE = 'import_exploits';
    public const ACTION_CERTIFICATES = 'certificate_read';

    /**
     * Seul l'administrateur de la configuration lance une lecture (contrôle d'avant #118).
     */
    public static function canRun(): bool
    {
        return (bool) Session::haveRight(Connection::RIGHTNAME, UPDATE);
    }

    /**
     * Adresse de traitement des formulaires de lecture.
     */
    public static function formURL(): string
    {
        global $CFG_GLPI;

        return (string) $CFG_GLPI['root_doc'] . '/plugins/dockerinventory/front/source.form.php';
    }

    /**
     * Lecture d'une source : import (Portainer, Kubernetes, WUD), lecture (Traefik), vérification
     * des registres (Harbor) ; avec $harbor, lecture des projets, artefacts et analyses de Harbor.
     * Connexion d'une autre entité : refusée par Connection::check().
     *
     * @return array{0: string, 1: int}
     */
    public static function connection(int $id, bool $harbor = false): array
    {
        $connection = new Connection();
        $connection->check($id, UPDATE);
        if ($harbor) {
            if (!$connection->isHarbor()) {
                return [__('Cette connexion n’est pas un registre Harbor.', 'dockerinventory'), ERROR];
            }
            $report = $connection->runHarborImport();

            return [
                Connection::harborMessage($report),
                (int) $report['status'] === Connection::STATUS_ERROR ? ERROR : INFO,
            ];
        }
        $report = $connection->runImport();

        return [
            $connection->importMessage($report),
            match ((int) $report['status']) {
                Connection::STATUS_ERROR => ERROR,
                Connection::STATUS_WARNING, Connection::STATUS_PARTIAL => WARNING,
                default => INFO,
            },
        ];
    }

    /**
     * Vérification de tous les registres (Docker Hub, Harbor), sans attendre la tâche automatique.
     *
     * @return array{0: string, 1: int}
     */
    public static function registries(): array
    {
        return [Connection::registryMessage((new RegistryImporter())->run()), INFO];
    }

    /**
     * Mise à jour des cycles de vie (endoflife.date), chaque produit au plus une fois par jour.
     *
     * @return array{0: string, 1: int}
     */
    public static function lifecycle(): array
    {
        $report = (new LifecycleChecker())->refresh();

        return [
            LifecycleProduct::refreshMessage($report),
            $report['status'] === LifecycleChecker::REPORT_PARTIAL ? WARNING : INFO,
        ];
    }

    /**
     * Téléchargement des fichiers publics complets KEV (et EPSS si retenu) : rien n'est envoyé.
     *
     * @return array{0: string, 1: int}
     */
    public static function exploitsDownload(): array
    {
        $report = ExploitSignal::download();

        return [
            ExploitSignal::downloadMessage($report),
            in_array(false, array_column($report, 'ok'), true) ? WARNING : INFO,
        ];
    }

    /**
     * Certificats TLS des routes HTTPS des services des entités actives (#78) : poignée de main TLS
     * sur chaque hôte, certificat public seulement.
     *
     * @return array{0: string, 1: int}
     */
    public static function certificates(): array
    {
        $report = Certificate::check(true);

        return [Certificate::checkMessage($report), $report['errors'] > 0 ? WARNING : INFO];
    }

    /**
     * Mode hors ligne : fichier KEV (JSON) ou EPSS (CSV, compressé ou non) déposé.
     *
     * @param mixed $file Entrée de $_FILES.
     * @return array{0: string, 1: int}
     */
    public static function exploitsFile(mixed $file): array
    {
        $result = ['ok' => false, 'error' => ExploitCatalog::ERROR_UNKNOWN, 'count' => 0, 'kind' => ''];
        $code = is_array($file) ? (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
        if (in_array($code, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            $result['error'] = ExploitCatalog::ERROR_TOO_LARGE;
        } elseif (
            is_array($file) && $code === UPLOAD_ERR_OK
            && is_string($file['tmp_name'] ?? null) && is_uploaded_file($file['tmp_name'])
        ) {
            $result = (int) filesize($file['tmp_name']) > ExploitCatalog::MAX_BYTES
                ? ['ok' => false, 'error' => ExploitCatalog::ERROR_TOO_LARGE, 'count' => 0, 'kind' => '']
                : ExploitSignal::importFile((string) file_get_contents($file['tmp_name']));
        }
        if ($result['ok']) {
            return [sprintf(
                __('%1$s importé : %2$d CVE.', 'dockerinventory'),
                ExploitSignal::kindLabels()[$result['kind']] ?? '',
                $result['count']
            ), INFO];
        }

        return [ExploitSignal::errorLabels()[$result['error']] ?? (string) $result['error'], ERROR];
    }

    /**
     * Lance l'action demandée par un formulaire, null si aucune.
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     * @return array{0: string, 1: int}|null
     */
    public static function run(array $post, array $files): ?array
    {
        return match (true) {
            isset($post[self::ACTION_HARBOR])  => self::connection((int) ($post['id'] ?? 0), true),
            isset($post[self::ACTION_READ])    => self::connection((int) ($post['id'] ?? 0)),
            isset($post[self::ACTION_REGISTRIES]) => self::registries(),
            isset($post[self::ACTION_LIFECYCLE]) => self::lifecycle(),
            isset($post[self::ACTION_EXPLOITS_DOWNLOAD]) => self::exploitsDownload(),
            isset($post[self::ACTION_EXPLOITS_FILE]) => self::exploitsFile($files['catalog'] ?? null),
            isset($post[self::ACTION_CERTIFICATES]) => self::certificates(),
            default => null,
        };
    }

    /**
     * Message affiché après la lecture.
     *
     * @param array{0: string, 1: int} $result
     */
    public static function flash(array $result): void
    {
        Session::addMessageAfterRedirect(htmlescape($result[0]), false, $result[1]);
    }

    /**
     * Anciens boutons de la page de configuration et de la fiche d'une connexion (jusqu'à la
     * version 0.16.0) : encore acceptés une version (#118). La lecture est faite, puis l'utilisateur
     * va à l'écran d'usage qui la propose désormais, avec un message qui l'indique. Adresse de cet
     * écran, ou null si le formulaire n'est pas un ancien bouton de lecture.
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     */
    public static function legacy(array $post, array $files): ?string
    {
        $legacy = [
            // Fiche d'une connexion : « Importer maintenant », « Lire … maintenant ».
            'import'        => [self::ACTION_READ, Service::getSearchURL(), __('Services', 'dockerinventory')],
            'harbor_import' => [
                self::ACTION_HARBOR,
                HarborArtifact::getPageURL(),
                __('Images Harbor et vulnérabilités', 'dockerinventory'),
            ],
            // Onglets de la configuration : « Mettre à jour maintenant », « Télécharger maintenant ».
            'refresh'       => [
                self::ACTION_LIFECYCLE,
                ImageVersion::getEolURL(),
                __('Fin de support des images', 'dockerinventory'),
            ],
            self::ACTION_EXPLOITS_DOWNLOAD => [
                self::ACTION_EXPLOITS_DOWNLOAD,
                ImageScan::getPageURL(),
                __('Analyses importées', 'dockerinventory'),
            ],
            self::ACTION_EXPLOITS_FILE => [
                self::ACTION_EXPLOITS_FILE,
                ImageScan::getPageURL(),
                __('Analyses importées', 'dockerinventory'),
            ],
        ];
        foreach ($legacy as $old => [$action, $url, $screen]) {
            if (!isset($post[$old])) {
                continue;
            }
            $result = self::run([$action => 1, 'id' => $post['id'] ?? 0], $files);
            if ($result !== null) {
                self::flash($result);
            }
            Session::addMessageAfterRedirect(htmlescape(sprintf(
                __('Cette lecture se lance désormais depuis Parc > Inventaire Docker > %s.', 'dockerinventory'),
                $screen
            )), false, INFO);

            return $url;
        }

        return null;
    }
}

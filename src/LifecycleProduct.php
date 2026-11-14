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

use CommonDBTM;
use CronTask;
use GlpiPlugin\Dockerinventory\Lifecycle\CycleMatcher;
use GlpiPlugin\Dockerinventory\Lifecycle\LifecycleChecker;
use GlpiPlugin\Dockerinventory\Lifecycle\LifecycleClient;
use GlpiPlugin\Dockerinventory\Lifecycle\LifecycleException;
use GlpiPlugin\Dockerinventory\Lifecycle\ProductMap;
use Html;
use Session;

/**
 * Correspondance entre une image et un produit de la source des cycles de vie (table fournie à
 * l'installation, modifiable), et page « Fin de support des images » de la configuration : réglages
 * de la source, correspondances, images en service sans correspondance.
 *
 * Réservé aux administrateurs de la configuration de GLPI (droit « Configuration » en
 * modification), comme les connexions.
 *
 * @phpstan-import-type Evaluation from CycleMatcher
 */
class LifecycleProduct extends CommonDBTM
{
    public const RIGHTNAME = 'config';

    public const CRON_NAME = 'lifecyclecheck';

    public static function getTypeName($nb = 0)
    {
        return __('Fin de support des images', 'dockerinventory');
    }

    public static function getIcon()
    {
        return 'ti ti-calendar-x';
    }

    public static function canView(): bool
    {
        return (bool) Session::haveRight(self::RIGHTNAME, UPDATE);
    }

    public static function canCreate(): bool
    {
        return self::canView();
    }

    public static function canUpdate(): bool
    {
        return self::canView();
    }

    public static function canDelete(): bool
    {
        return self::canView();
    }

    public static function canPurge(): bool
    {
        return self::canView();
    }

    public static function getPageURL(bool $full = true): string
    {
        global $CFG_GLPI;

        return ($full ? (string) $CFG_GLPI['root_doc'] : '') . '/plugins/dockerinventory/front/lifecycle.php';
    }

    /**
     * Menu Configuration : la page de réglage (pas de recherche GLPI sur cette petite table).
     *
     * @return array<string, mixed>|false
     */
    public static function getMenuContent()
    {
        if (!self::canView()) {
            return false;
        }

        return [
            'title' => self::getTypeName(2),
            'page'  => self::getPageURL(false),
            'icon'  => self::getIcon(),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    public function prepareInputForAdd($input)
    {
        return $this->prepareMapping($input);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    public function prepareInputForUpdate($input)
    {
        return $this->prepareMapping($input);
    }

    /**
     * Image normalisée (« postgres », « registry.exemple.test/outils/api »), produit valide, une seule
     * correspondance par image.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    private function prepareMapping(array $input): array|false
    {
        if (array_key_exists('image', $input)) {
            $image = ProductMap::normalizeImage((string) $input['image']);
            if ($image === '') {
                Session::addMessageAfterRedirect(
                    __('Nom d’image invalide (exemples : postgres, bitnami/redis, registry.exemple.test/outils/api).', 'dockerinventory'),
                    false,
                    ERROR
                );

                return false;
            }
            $others = ['image' => $image];
            if (!$this->isNewItem()) {
                $others['NOT'] = ['id' => (int) $this->getID()];
            }
            if (countElementsInTable(static::getTable(), $others) > 0) {
                Session::addMessageAfterRedirect(
                    sprintf(__('L’image %s a déjà une correspondance.', 'dockerinventory'), $image),
                    false,
                    ERROR
                );

                return false;
            }
            $input['image'] = $image;
        }
        if (array_key_exists('product', $input)) {
            $product = strtolower(trim((string) $input['product']));
            if (!ProductMap::isValidProduct($product)) {
                Session::addMessageAfterRedirect(
                    __('Produit invalide : nom de la source en minuscules (exemples : postgresql, nodejs).', 'dockerinventory'),
                    false,
                    ERROR
                );

                return false;
            }
            $input['product'] = $product;
        }

        return $input;
    }

    /**
     * Correspondances fournies, ajoutées à l'installation (une image déjà présente est gardée).
     */
    public static function installDefaults(): void
    {
        global $DB;

        $now = date('Y-m-d H:i:s');
        foreach (ProductMap::DEFAULTS as $image => $product) {
            if (countElementsInTable(static::getTable(), ['image' => $image]) === 0) {
                $DB->insert(static::getTable(), [
                    'image'         => $image,
                    'product'       => $product,
                    'is_default'    => 1,
                    'date_mod'      => $now,
                    'date_creation' => $now,
                ]);
            }
        }
    }

    /**
     * Libellé, couleur, icône et aide de chaque état du support.
     *
     * @return array<string, array{label: string, color: string, icon: string, help: string}>
     */
    public static function statusInfos(): array
    {
        return [
            CycleMatcher::STATUS_EOL => [
                'label' => __('Fin de support', 'dockerinventory'),
                'color' => 'danger',
                'icon'  => 'ti ti-calendar-x',
                'help'  => __('La branche déployée ne reçoit plus de correctifs de sécurité.', 'dockerinventory'),
            ],
            CycleMatcher::STATUS_SOON => [
                'label' => __('Bientôt en fin de support', 'dockerinventory'),
                'color' => 'warning',
                'icon'  => 'ti ti-hourglass-high',
                'help'  => __('Prévoir le passage à une branche encore maintenue.', 'dockerinventory'),
            ],
            CycleMatcher::STATUS_SUPPORTED => [
                'label' => __('Maintenue', 'dockerinventory'),
                'color' => 'success',
                'icon'  => 'ti ti-shield-check',
                'help'  => __('La branche déployée reçoit encore des correctifs.', 'dockerinventory'),
            ],
            CycleMatcher::STATUS_UNKNOWN => [
                'label' => __('Support inconnu', 'dockerinventory'),
                'color' => 'secondary',
                'icon'  => 'ti ti-help-circle',
                'help'  => __('Fin de support non déterminée : jamais considérée comme maintenue.', 'dockerinventory'),
            ],
        ];
    }

    /**
     * @return array{label: string, color: string, icon: string, help: string}
     */
    public static function statusInfo(string $status): array
    {
        return self::statusInfos()[$status] ?? self::statusInfos()[CycleMatcher::STATUS_UNKNOWN];
    }

    /**
     * Raisons d'un état inconnu.
     *
     * @return array<string, string>
     */
    public static function reasonLabels(): array
    {
        return [
            CycleMatcher::REASON_DISABLED          => __('Source des cycles de vie désactivée.', 'dockerinventory'),
            CycleMatcher::REASON_NO_PRODUCT        => __('Image sans correspondance avec un produit.', 'dockerinventory'),
            CycleMatcher::REASON_UNVERSIONED       => __('Tag sans numéro de version (latest...).', 'dockerinventory'),
            CycleMatcher::REASON_NOT_CHECKED       => __(
                'Cycles de vie pas encore lus (source pas encore interrogée ou injoignable).',
                'dockerinventory'
            ),
            CycleMatcher::REASON_PRODUCT_NOT_FOUND => __(
                'Produit inconnu de la source : correspondance à corriger.',
                'dockerinventory'
            ),
            CycleMatcher::REASON_NO_CYCLE          => __('Branche de cette version absente de la source.', 'dockerinventory'),
        ];
    }

    /**
     * Erreurs de lecture de la source.
     *
     * @return array<string, string>
     */
    public static function errorLabels(): array
    {
        return [
            LifecycleException::NOT_FOUND       => __('Produit inconnu de la source', 'dockerinventory'),
            LifecycleException::RATE_LIMITED    => __('Limite de requêtes atteinte', 'dockerinventory'),
            LifecycleException::SERVER_ERROR    => __('Erreur du serveur de la source', 'dockerinventory'),
            LifecycleException::PRIVATE_NETWORK => __(
                'Adresse sur un réseau privé refusée par GLPI (contexte LifecycleContext à autoriser)',
                'dockerinventory'
            ),
            LifecycleException::UNEXPECTED      => __('Réponse inattendue de la source', 'dockerinventory'),
            LifecycleException::UNREACHABLE     => __('Source injoignable', 'dockerinventory'),
            LifecycleException::SKIPPED         => __('Non interrogé (erreur précédente)', 'dockerinventory'),
        ];
    }

    /**
     * Valeurs prêtes à afficher (badge, branche, date, raison).
     *
     * @param Evaluation $evaluation
     * @return array<string, mixed>
     */
    public static function present(array $evaluation): array
    {
        $status = $evaluation['status'];
        $days = $evaluation['days'];
        $eol = $evaluation['eol'];
        $detail = match (true) {
            $eol === LifecycleClient::EOL_WITHOUT_DATE => __('Fin de support atteinte (date non publiée).', 'dockerinventory'),
            $eol === '' && $status === CycleMatcher::STATUS_SUPPORTED
                => __('Aucune fin de support annoncée.', 'dockerinventory'),
            $days !== null && $days <= 0 => sprintf(
                __('Fin de support depuis le %s.', 'dockerinventory'),
                Html::convDate($eol)
            ),
            $days !== null => sprintf(
                _n('Fin de support le %1$s (dans %2$d jour).', 'Fin de support le %1$s (dans %2$d jours).', $days, 'dockerinventory'),
                Html::convDate($eol),
                $days
            ),
            default => self::reasonLabels()[$evaluation['reason']] ?? '',
        };
        $template = LifecycleClient::configuredUrl();

        return [
            'status'      => self::statusInfo($status),
            'status_code' => $status,
            'product'     => $evaluation['product'],
            'cycle'       => $evaluation['cycle'],
            'latest'      => $evaluation['latest'],
            'eol'         => $eol,
            'days'        => $days,
            'detail'      => $detail,
            'reason'      => $evaluation['reason'],
            // Page publique du produit, seulement pour la source par défaut.
            'product_url' => $evaluation['product'] !== '' && $template === LifecycleClient::DEFAULT_URL
                ? 'https://endoflife.date/' . rawurlencode($evaluation['product']) : '',
        ];
    }

    /**
     * Message du compte rendu d'une mise à jour des cycles de vie.
     *
     * @param array<string, mixed> $report
     */
    public static function refreshMessage(array $report): string
    {
        if (($report['status'] ?? '') === LifecycleChecker::REPORT_DISABLED) {
            return __('Source des cycles de vie désactivée : rien n’a été lu.', 'dockerinventory');
        }
        $message = sprintf(
            __(
                'Cycles de vie : %1$d produits en service, %2$d lus, %3$d déjà à jour (moins d’un jour), %4$d inconnus de la source, %5$d requêtes.',
                'dockerinventory'
            ),
            (int) ($report['products'] ?? 0),
            (int) ($report['fetched'] ?? 0),
            (int) ($report['cached'] ?? 0),
            (int) ($report['not_found'] ?? 0),
            (int) ($report['requests'] ?? 0)
        );
        $errors = is_array($report['errors'] ?? null) ? $report['errors'] : [];
        if ($errors !== []) {
            $message .= ' ' . sprintf(
                __('%d produit(s) non lu(s) : les données déjà en cache restent utilisées.', 'dockerinventory'),
                count($errors)
            );
        }
        $tickets = is_array($report['tickets'] ?? null) ? $report['tickets'] : [];
        if ((int) ($tickets['created'] ?? 0) > 0 || (int) ($tickets['followups'] ?? 0) > 0) {
            $message .= ' ' . sprintf(
                __('Tickets de fin de support : %1$d créé(s), %2$d suivi(s) ajouté(s).', 'dockerinventory'),
                (int) $tickets['created'],
                (int) $tickets['followups']
            );
        }

        return $message;
    }

    /**
     * @return array{description: string}
     */
    public static function cronInfo(string $name): array
    {
        return ['description' => __(
            'Fin de support des images : cycles de vie (une fois par jour) et tickets de fin de support',
            'dockerinventory'
        )];
    }

    /**
     * Tâche automatique (une fois par jour) : rien tant que la source est désactivée.
     */
    public static function cronLifecyclecheck(CronTask $task): int
    {
        $report = (new LifecycleChecker())->refresh();
        if ($report['status'] === LifecycleChecker::REPORT_DISABLED) {
            return 0;
        }
        $task->addVolume($report['products']);
        $task->log(self::refreshMessage($report));
        foreach ($report['errors'] as $error) {
            $task->log($error['product'] . ' : ' . (self::errorLabels()[$error['code']] ?? $error['code']));
        }

        return 1;
    }
}

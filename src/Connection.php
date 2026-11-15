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

use CronTask;
use Glpi\Application\View\TemplateRenderer;
use GLPIKey;
use GlpiPlugin\Dockerinventory\Compat\Base\ConnectionBase;
use GlpiPlugin\Dockerinventory\Harbor\HarborClient;
use GlpiPlugin\Dockerinventory\Harbor\HarborImporter;
use GlpiPlugin\Dockerinventory\Portainer\PortainerClient;
use GlpiPlugin\Dockerinventory\Portainer\PortainerException;
use GlpiPlugin\Dockerinventory\Portainer\PortainerMapper;
use GlpiPlugin\Dockerinventory\Portainer\UrlPolicy;
use GlpiPlugin\Dockerinventory\Registry\RegistryClient;
use GlpiPlugin\Dockerinventory\Registry\RegistryContext;
use GlpiPlugin\Dockerinventory\Registry\RegistryException;
use GlpiPlugin\Dockerinventory\Registry\RegistryImporter;
use GlpiPlugin\Dockerinventory\Registry\RegistryResolver;
use GlpiPlugin\Dockerinventory\Ticket\TicketPolicy;
use GlpiPlugin\Dockerinventory\Ticket\VulnTicketPolicy;
use GlpiPlugin\Dockerinventory\Wud\WudClient;
use GlpiPlugin\Dockerinventory\Wud\WudContext;
use GlpiPlugin\Dockerinventory\Wud\WudException;
use GlpiPlugin\Dockerinventory\Wud\WudImporter;
use Session;

/**
 * Connexion à une source de l'inventaire, de l'un des types :
 * - Portainer : adresse, clé d'API (chiffrée avec la clé de GLPI, jamais réaffichée),
 *   environnement GLPI des applications créées pour ses stacks, tickets de mise à jour de ses
 *   services (désactivés par défaut : seuil, catégorie, groupe, modèle de ticket ; fin de support ;
 *   vulnérabilités des images analysées par Harbor) ;
 * - What's Up Docker (WUD) : adresse, utilisateur et mot de passe facultatifs (authentification
 *   basique ; mot de passe chiffré dans la même colonne que la clé Portainer, jamais réaffiché) ;
 * - Harbor : adresse, compte robot en lecture seule facultatif (mot de passe chiffré de la même
 *   façon), hôte du registre dans les images s'il diffère de l'adresse. Sert au repli sans WUD
 *   (liste des tags par l'API v2 des registres) et à la lecture des projets, dépôts, artefacts et
 *   analyses de vulnérabilités par l'API REST de Harbor (#9).
 * Le type se choisit à la création et ne change plus.
 *
 * Réservé aux administrateurs de la configuration de GLPI (droit « Configuration » en
 * modification), pour la lecture comme pour l'écriture : une connexion donne accès à toute
 * l'infrastructure de conteneurs.
 */
class Connection extends ConnectionBase
{
    public const RIGHTNAME = 'config';
    public const UNDISCLOSED_FIELDS = ['api_key'];

    public const STATUS_NEVER = 0;
    public const STATUS_OK = 1;
    public const STATUS_PARTIAL = 2;
    public const STATUS_ERROR = 3;
    /** Lu sans erreur, mais à vérifier : par exemple un compte Portainer qui ne voit rien. */
    public const STATUS_WARNING = 4;

    public const TYPE_PORTAINER = 'portainer';
    public const TYPE_WUD = 'wud';
    public const TYPE_HARBOR = 'harbor';

    public static function getTypeName($nb = 0)
    {
        return $nb > 1 ? __('Connexions Docker', 'dockerinventory') : __('Connexion Docker', 'dockerinventory');
    }

    /**
     * @return array<string, string>
     */
    public static function typeLabels(): array
    {
        return [
            self::TYPE_PORTAINER => 'Portainer',
            self::TYPE_WUD       => "What's Up Docker (WUD)",
            self::TYPE_HARBOR    => 'Harbor',
        ];
    }

    public function isWud(): bool
    {
        return ($this->fields['type'] ?? self::TYPE_PORTAINER) === self::TYPE_WUD;
    }

    /**
     * Seuils des tickets de mise à jour : écart minimal qui donne un ticket.
     *
     * @return array<string, string>
     */
    public static function ticketThresholdLabels(): array
    {
        return [
            TicketPolicy::THRESHOLD_MAJOR => __('Version majeure seulement', 'dockerinventory'),
            TicketPolicy::THRESHOLD_MINOR => __('Version mineure ou majeure', 'dockerinventory'),
            TicketPolicy::THRESHOLD_PATCH => __('Tout écart de version, correctifs compris', 'dockerinventory'),
        ];
    }

    /**
     * Seuils des tickets de vulnérabilité : gravité minimale qui donne un ticket.
     *
     * @return array<string, string>
     */
    public static function vulnThresholdLabels(): array
    {
        return [
            VulnTicketPolicy::THRESHOLD_CRITICAL => __('Vulnérabilités critiques', 'dockerinventory'),
            VulnTicketPolicy::THRESHOLD_HIGH     => __('Vulnérabilités critiques ou hautes', 'dockerinventory'),
        ];
    }

    public function isHarbor(): bool
    {
        return ($this->fields['type'] ?? self::TYPE_PORTAINER) === self::TYPE_HARBOR;
    }

    public static function getIcon()
    {
        return 'ti ti-plug-connected';
    }

    public static function canView(): bool
    {
        return (bool) Session::haveRight(self::RIGHTNAME, UPDATE);
    }

    public static function canCreate(): bool
    {
        return (bool) Session::haveRight(self::RIGHTNAME, UPDATE);
    }

    public static function canUpdate(): bool
    {
        return (bool) Session::haveRight(self::RIGHTNAME, UPDATE);
    }

    public static function canDelete(): bool
    {
        return (bool) Session::haveRight(self::RIGHTNAME, UPDATE);
    }

    public static function canPurge(): bool
    {
        return (bool) Session::haveRight(self::RIGHTNAME, UPDATE);
    }

    public function defineTabs($options = [])
    {
        $tabs = [];
        $this->addDefaultFormTab($tabs);

        return $tabs;
    }

    public function rawSearchOptions()
    {
        $options = parent::rawSearchOptions();
        $options[] = [
            'id'         => 3,
            'table'      => static::getTable(),
            'field'      => 'url',
            'name'       => __('Adresse', 'dockerinventory'),
            'datatype'   => 'string',
        ];
        $options[] = [
            'id'            => 7,
            'table'         => static::getTable(),
            'field'         => 'type',
            'name'          => __('Type', 'dockerinventory'),
            'datatype'      => 'specific',
            'searchtype'    => ['equals', 'notequals'],
            'massiveaction' => false,
        ];
        $options[] = [
            'id'         => 4,
            'table'      => static::getTable(),
            'field'      => 'is_active',
            'name'       => __('Active'),
            'datatype'   => 'bool',
        ];
        $options[] = [
            'id'         => 5,
            'table'      => static::getTable(),
            'field'      => 'last_import_date',
            'name'       => __('Dernier import', 'dockerinventory'),
            'datatype'   => 'datetime',
            'massiveaction' => false,
        ];
        $options[] = [
            'id'         => 6,
            'table'      => 'glpi_applianceenvironments',
            'field'      => 'name',
            'name'       => __('Environnement des applications créées', 'dockerinventory'),
            'datatype'   => 'dropdown',
        ];
        $options[] = [
            'id'            => 8,
            'table'         => static::getTable(),
            'field'         => 'ticket_enabled',
            'name'          => __('Tickets de mise à jour', 'dockerinventory'),
            'datatype'      => 'bool',
            'massiveaction' => false,
        ];
        $options[] = [
            'id'            => 9,
            'table'         => static::getTable(),
            'field'         => 'ticket_vuln_enabled',
            'name'          => __('Tickets de vulnérabilité', 'dockerinventory'),
            'datatype'      => 'bool',
            'massiveaction' => false,
        ];
        $options[] = [
            'id'         => 80,
            'table'      => 'glpi_entities',
            'field'      => 'completename',
            'name'       => \Entity::getTypeName(1),
            'datatype'   => 'dropdown',
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
        if ($field === 'type') {
            $value = is_array($values) ? (string) ($values[$field] ?? '') : (string) $values;

            return htmlescape(self::typeLabels()[$value] ?? $value);
        }

        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    /**
     * @param array<string, mixed>|string $values
     * @param array<string, mixed> $options
     */
    public static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = [])
    {
        if ($field === 'type') {
            $value = is_array($values) ? (string) ($values[$field] ?? '') : (string) $values;

            return \Dropdown::showFromArray(
                (string) $name,
                self::typeLabels(),
                ['display' => false, 'value' => $value] + $options
            );
        }

        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }

    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);
        $renderer = TemplateRenderer::getInstance();
        if ($this->isNewItem()) {
            $type = (string) ($options['type'] ?? '');
            if (!isset(self::typeLabels()[$type])) {
                // Nouvelle connexion : d'abord le type (les champs en dépendent).
                $renderer->display('@dockerinventory/connection_type_choice.html.twig', [
                    'form_url' => self::getFormURL(),
                ]);

                return true;
            }
            $this->fields['type'] = $type;
        }
        $report = json_decode((string) ($this->fields['last_import_report'] ?? ''), true);
        $actions = match (true) {
            $this->isWud()    => 'wud_actions',
            $this->isHarbor() => 'harbor_actions',
            default           => 'connection_actions',
        };

        $renderer->display('@dockerinventory/connection_form.html.twig', [
            'item'        => $this,
            'params'      => $options,
            'is_wud'      => $this->isWud(),
            'is_harbor'   => $this->isHarbor(),
            'type_label'  => self::typeLabels()[(string) $this->fields['type']] ?? '',
            'has_api_key' => trim((string) ($this->fields['api_key'] ?? '')) !== '',
            'thresholds'  => self::ticketThresholdLabels(),
            'vuln_thresholds' => self::vulnThresholdLabels(),
        ]);
        if (!$this->isNewItem()) {
            $scanReport = json_decode((string) ($this->fields['last_scan_report'] ?? ''), true);
            $renderer->display('@dockerinventory/' . $actions . '.html.twig', [
                'item'          => $this,
                'scan_status_label' => self::statusLabel((int) ($this->fields['last_scan_status'] ?? 0)),
                'scan_report'   => is_array($scanReport) ? $scanReport : null,
                'harbor_url'    => HarborArtifact::getPageURL(),
                'status_label'  => self::statusLabel((int) ($this->fields['last_import_status'] ?? 0)),
                'report'        => is_array($report) ? $report : null,
                'error_labels'  => self::errorLabels(),
                'warning_labels' => self::warningLabels(),
                'gaps'          => ImageVersion::gapInfos(),
                'versions_url'  => ImageVersion::getOverviewURL(),
                'context_class' => match (true) {
                    $this->isWud()    => WudContext::class,
                    $this->isHarbor() => RegistryContext::class,
                    default           => Portainer\PortainerContext::class,
                },
                'registry_host' => $this->isHarbor() ? RegistryResolver::harborHost(
                    (string) $this->fields['url'],
                    (string) ($this->fields['registry_host'] ?? '')
                ) : '',
            ]);
        }

        return true;
    }

    public function prepareInputForAdd($input)
    {
        $input['type'] = (string) ($input['type'] ?? self::TYPE_PORTAINER);
        if (!isset(self::typeLabels()[$input['type']])) {
            return false;
        }
        $this->fields['type'] = $input['type'];
        if ($input['type'] === self::TYPE_PORTAINER && trim((string) ($input['api_key'] ?? '')) === '') {
            Session::addMessageAfterRedirect(
                htmlescape(__("La clé d'API Portainer est obligatoire.", 'dockerinventory')),
                false,
                ERROR
            );
            return false;
        }

        return $this->prepareInput($input, true);
    }

    public function prepareInputForUpdate($input)
    {
        return $this->prepareInput($input, false);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    private function prepareInput(array $input, bool $isNew): array|false
    {
        if (!$isNew) {
            // Le type ne change pas après la création (les champs en dépendent).
            unset($input['type']);
        }
        if (array_key_exists('username', $input)) {
            $input['username'] = mb_substr(trim((string) $input['username']), 0, 255);
        }
        if (array_key_exists('registry_host', $input)) {
            // « harbor.exemple.test » ou « registry.exemple.test:5000 », tel qu'écrit dans les images.
            $host = strtolower(trim((string) $input['registry_host']));
            $host = rtrim((string) preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $host), '/');
            $input['registry_host'] = mb_substr($host, 0, 255);
            if ($host !== '' && preg_match('#^[a-z0-9.-]+(:\d+)?$#', $host) !== 1) {
                Session::addMessageAfterRedirect(
                    htmlescape(
                        __('Hôte du registre invalide (exemple : registry.exemple.test:5000).', 'dockerinventory')
                    ),
                    false,
                    ERROR
                );
                return false;
            }
        }
        if (array_key_exists('url', $input) || $isNew) {
            $input['url'] = UrlPolicy::normalize((string) ($input['url'] ?? ''));
            $allowHttp = (bool) ($input['allow_http'] ?? ($this->fields['allow_http'] ?? false));
            $error = UrlPolicy::check($input['url'], $allowHttp);
            if ($error !== null) {
                Session::addMessageAfterRedirect(htmlescape($this->urlErrorLabel($error)), false, ERROR);
                return false;
            }
        } elseif (array_key_exists('allow_http', $input) && !(bool) $input['allow_http']) {
            // HTTP retiré : l'adresse enregistrée doit alors être en HTTPS.
            $error = UrlPolicy::check((string) ($this->fields['url'] ?? ''), false);
            if ($error !== null) {
                Session::addMessageAfterRedirect(htmlescape($this->urlErrorLabel($error)), false, ERROR);
                return false;
            }
        }

        // Clé (ou mot de passe WUD) laissée vide en modification : celle enregistrée est conservée.
        // Case « Effacer le mot de passe » (WUD sans authentification) : vidée explicitement.
        $key = trim((string) ($input['api_key'] ?? ''));
        if (!empty($input['_clear_api_key']) && ($this->isWud() || $this->isHarbor())) {
            $input['api_key'] = '';
        } elseif ($key === '') {
            unset($input['api_key']);
        } else {
            $input['api_key'] = (new GLPIKey())->encrypt($key);
        }
        $isPortainer = ($isNew ? (string) ($input['type'] ?? '') : (string) ($this->fields['type'] ?? ''))
            === self::TYPE_PORTAINER;
        $ticketFields = ['ticket_enabled', 'ticket_threshold', 'ticket_itilcategories_id', 'ticket_groups_id',
            'ticket_tickettemplates_id', 'ticket_eol_enabled', 'ticket_eol_days', 'ticket_vuln_enabled',
            'ticket_vuln_severity'];
        foreach ($ticketFields as $field) {
            if (!array_key_exists($field, $input)) {
                continue;
            }
            if (!$isPortainer) {
                // Tickets réglés sur la connexion Portainer des services seulement.
                unset($input[$field]);
            } elseif ($field === 'ticket_threshold') {
                $value = (string) $input[$field];
                $valid = in_array($value, TicketPolicy::THRESHOLDS, true);
                $input[$field] = $valid ? $value : TicketPolicy::THRESHOLD_MAJOR;
            } elseif ($field === 'ticket_vuln_severity') {
                $value = (string) $input[$field];
                $valid = in_array($value, VulnTicketPolicy::THRESHOLDS, true);
                $input[$field] = $valid ? $value : VulnTicketPolicy::THRESHOLD_CRITICAL;
            } elseif ($field === 'ticket_eol_days') {
                $input[$field] = Lifecycle\Settings::clampDays((int) $input[$field]);
            } else {
                $input[$field] = max(0, (int) $input[$field]);
            }
        }
        // Champs tenus par l'import, jamais par le formulaire.
        unset($input['last_import_date'], $input['last_import_status'], $input['last_import_report']);
        unset($input['last_scan_date'], $input['last_scan_status'], $input['last_scan_report']);

        return $input;
    }

    /**
     * Connexion Harbor supprimée : ses projets et artefacts (données lues dans Harbor) avec elle.
     */
    public function cleanDBonPurge()
    {
        global $DB;

        $where = ['plugin_dockerinventory_connections_id' => (int) $this->getID()];
        $DB->delete(HarborArtifact::getTable(), $where);
        $DB->delete(HarborImporter::PROJECTS_TABLE, $where);
    }

    public function getDecryptedApiKey(): string
    {
        $stored = (string) ($this->fields['api_key'] ?? '');

        return $stored === '' ? '' : (string) (new GLPIKey())->decrypt($stored);
    }

    /**
     * Client de lecture de ce serveur WUD.
     */
    public function getWudClient(): WudClient
    {
        return new WudClient(
            (string) $this->fields['url'],
            (string) ($this->fields['username'] ?? ''),
            $this->getDecryptedApiKey()
        );
    }

    /**
     * Client de lecture de ce registre Harbor (API v2 des registres).
     */
    public function getRegistryClient(): RegistryClient
    {
        return RegistryClient::harbor(
            (string) $this->fields['url'],
            (string) ($this->fields['username'] ?? ''),
            $this->getDecryptedApiKey()
        );
    }

    /**
     * Client de lecture de l'API REST de ce Harbor (projets, dépôts, artefacts, analyses).
     */
    public function getHarborClient(): HarborClient
    {
        return new HarborClient(
            (string) $this->fields['url'],
            (string) ($this->fields['username'] ?? ''),
            $this->getDecryptedApiKey()
        );
    }

    /**
     * Lecture de l'API de Harbor (projets, artefacts, analyses), puis tickets de vulnérabilité.
     *
     * @return array<string, mixed>
     */
    public function runHarborImport(): array
    {
        return (new HarborImporter())->import($this);
    }

    /**
     * Message du compte rendu d'une lecture de Harbor.
     *
     * @param array<string, mixed> $report
     */
    public static function harborMessage(array $report): string
    {
        $errors = is_array($report['errors'] ?? null) ? $report['errors'] : [];
        if ((int) $report['status'] === self::STATUS_ERROR && $errors !== []) {
            $code = (string) ($errors[0]['code'] ?? '');

            return sprintf(__('Échec : %s', 'dockerinventory'), self::errorLabels()[$code] ?? $code);
        }
        $tickets = is_array($report['tickets'] ?? null) ? $report['tickets'] : [];
        $created = (int) ($tickets['created'] ?? 0);
        $followups = (int) ($tickets['followups'] ?? 0);

        return sprintf(
            __(
                'Harbor lu (%1$s) : %2$d projets, %3$d dépôts, %4$d artefacts dont %5$d analysés, %6$d retirés.',
                'dockerinventory'
            ),
            self::statusLabel((int) $report['status']),
            (int) ($report['projects'] ?? 0),
            (int) ($report['repositories'] ?? 0),
            (int) ($report['artifacts'] ?? 0),
            (int) ($report['scanned'] ?? 0),
            (int) ($report['retired'] ?? 0)
        ) . ($created === 0 && $followups === 0 ? '' : ' ' . sprintf(
            __('Tickets de vulnérabilité : %1$d créé(s), %2$d suivi(s) ajouté(s).', 'dockerinventory'),
            $created,
            $followups
        ));
    }

    /**
     * Client de lecture de ce serveur Portainer.
     */
    public function getClient(): PortainerClient
    {
        return new PortainerClient((string) $this->fields['url'], $this->getDecryptedApiKey());
    }

    /**
     * Import de cette connexion, selon son type.
     *
     * @return array<string, mixed> Compte rendu (Importer ou WudImporter).
     */
    public function runImport(): array
    {
        return match (true) {
            $this->isWud()    => (new WudImporter())->import($this),
            // Harbor : vérification de tous les registres (Docker Hub compris).
            $this->isHarbor() => (new RegistryImporter())->run(),
            default           => (new Importer())->import($this),
        };
    }

    /**
     * Message du compte rendu d'un import, selon le type de connexion.
     *
     * @param array<string, mixed> $report
     */
    public function importMessage(array $report): string
    {
        $errors = is_array($report['errors'] ?? null) ? $report['errors'] : [];
        if ((int) $report['status'] === self::STATUS_ERROR && $errors !== []) {
            $code = (string) ($errors[0]['code'] ?? '');

            return sprintf(__('Échec : %s', 'dockerinventory'), self::errorLabels()[$code] ?? $code);
        }
        if ($this->isHarbor()) {
            return self::registryMessage($report);
        }
        if ($this->isWud()) {
            return sprintf(
                '%s%s',
                $this->wudMessage($report),
                self::ticketsMessage(is_array($report['tickets'] ?? null) ? $report['tickets'] : [])
            );
        }

        return sprintf(
            __(
                'Import terminé (%1$s) : %2$d stacks, %3$d services, %4$d retirés, %5$d nœuds ou hôtes.',
                'dockerinventory'
            ),
            self::statusLabel((int) $report['status']),
            (int) ($report['stacks'] ?? 0),
            (int) ($report['services'] ?? 0),
            (int) ($report['retired'] ?? 0),
            (int) ($report['nodes'] ?? 0)
        ) . self::warningsMessage($report);
    }

    /**
     * Fin du message d'un import Portainer : ses avertissements (compte sans droits...), s'il y en a.
     *
     * @param array<string, mixed> $report
     */
    public static function warningsMessage(array $report): string
    {
        $message = '';
        foreach (is_array($report['warnings'] ?? null) ? $report['warnings'] : [] as $warning) {
            $code = is_array($warning) ? (string) ($warning['code'] ?? '') : '';
            $endpoint = is_array($warning) ? (string) ($warning['endpoint'] ?? '') : '';
            $label = self::warningLabels()[$code] ?? $code;
            $message .= ' ' . ($endpoint !== '' ? $endpoint . ' : ' . $label : $label);
        }

        return $message;
    }

    /**
     * Avertissements d'un import Portainer (Importer::WARNING_*).
     *
     * @return array<string, string>
     */
    public static function warningLabels(): array
    {
        return [
            Importer::WARNING_NO_ENDPOINT => __(
                'Le compte ne voit aucun environnement : vérifiez ses accès dans Portainer (voir README).',
                'dockerinventory'
            ),
            Importer::WARNING_HIDDEN => __(
                'Portainer annonce des services ou des conteneurs que ce compte ne voit pas.',
                'dockerinventory'
            ) . ' ' . __(
                "Rien n'a été retiré ici : vérifiez les accès du compte dans Portainer (voir README).",
                'dockerinventory'
            ),
            Importer::WARNING_EMPTY_KEPT => __(
                "Aucun élément trouvé alors que le dernier import en trouvait : rien n'a été retiré.",
                'dockerinventory'
            ) . ' ' . __('Vérifiez les accès du compte dans Portainer (voir README).', 'dockerinventory'),
        ];
    }

    /**
     * Fin du message d'une lecture : tickets créés et suivis ajoutés, s'il y en a.
     *
     * @param array<string, mixed> $tickets
     */
    public static function ticketsMessage(array $tickets): string
    {
        $created = (int) ($tickets['created'] ?? 0);
        $followups = (int) ($tickets['followups'] ?? 0);
        if ($created === 0 && $followups === 0) {
            return '';
        }

        return ' ' . sprintf(
            __('Tickets de mise à jour : %1$d créé(s), %2$d suivi(s) ajouté(s).', 'dockerinventory'),
            $created,
            $followups
        );
    }

    /**
     * @param array<string, mixed> $report
     */
    private function wudMessage(array $report): string
    {
        return sprintf(
            __(
                'Lecture de WUD terminée (%1$s) : %2$d conteneurs, %3$d services suivis, %4$d sans service.',
                'dockerinventory'
            ),
            self::statusLabel((int) $report['status']),
            (int) ($report['containers'] ?? 0),
            (int) ($report['services'] ?? 0),
            (int) ($report['unmatched'] ?? 0)
        );
    }

    /**
     * Message du compte rendu de la vérification des registres.
     *
     * @param array<string, mixed> $report
     */
    public static function registryMessage(array $report): string
    {
        return sprintf(
            __(
                'Registres lus (%1$s) : %2$d versions trouvées, %3$d non suivables, %4$d non vérifiées, %5$d requêtes.',
                'dockerinventory'
            ),
            self::statusLabel((int) $report['status']),
            (int) ($report['checked'] ?? 0),
            (int) ($report['untrackable'] ?? 0),
            (int) ($report['not_checked'] ?? 0),
            (int) ($report['requests'] ?? 0)
        ) . self::ticketsMessage(is_array($report['tickets'] ?? null) ? $report['tickets'] : []);
    }

    /**
     * « Tester la connexion » Harbor : GET /v2/ avec le compte robot.
     *
     * @return array{ok: bool, message: string}
     */
    public function testHarborConnection(?RegistryClient $client = null, ?HarborClient $api = null): array
    {
        // Client du registre fourni (tests) sans client de l'API : l'API n'est pas appelée.
        $api ??= $client === null ? $this->getHarborClient() : null;
        $client ??= $this->getRegistryClient();
        try {
            $client->ping();
        } catch (RegistryException $e) {
            return ['ok' => false, 'message' => self::errorLabels()[$e->getMessage()] ?? $e->getMessage()];
        }
        $message = $client->hasCredentials()
            ? __('Connexion réussie : le registre Harbor accepte ce compte.', 'dockerinventory')
            : __(
                'Connexion réussie : registre joignable sans compte (projets publics seulement).',
                'dockerinventory'
            );
        if ($api !== null) {
            try {
                $message .= ' ' . sprintf(
                    __('API de Harbor : %d projet(s) visible(s) avec ce compte.', 'dockerinventory'),
                    count($api->projects()['items'])
                );
            } catch (RegistryException $e) {
                $message .= ' ' . sprintf(
                    __('API de Harbor non lue (projets, analyses) : %s', 'dockerinventory'),
                    self::errorLabels()[$e->getMessage()] ?? $e->getMessage()
                );
            }
        }

        return ['ok' => true, 'message' => $message];
    }

    /**
     * « Tester la connexion » WUD : version de WUD et nombre de conteneurs suivis.
     *
     * @return array{ok: bool, message: string}
     */
    public function testWudConnection(?WudClient $client = null): array
    {
        $client ??= $this->getWudClient();
        try {
            $version = $client->version();
            $containers = $client->containers();
        } catch (WudException $e) {
            return ['ok' => false, 'message' => self::errorLabels()[$e->getMessage()] ?? $e->getMessage()];
        }

        return [
            'ok'      => true,
            'message' => sprintf(
                __('Connexion réussie : WUD %1$s, %2$d conteneur(s) suivi(s).', 'dockerinventory'),
                $version !== '' ? $version : '?',
                count($containers)
            ),
        ];
    }

    /**
     * « Tester la connexion » : liste des environnements Portainer (ou appel à WUD). Avertissement
     * (warning) si le compte ne voit aucun environnement, ou rien d'un environnement que Portainer
     * annonce peuplé.
     *
     * @return array{ok: bool, message: string, warning?: bool}
     */
    public function testConnection(?PortainerClient $client = null): array
    {
        if ($this->isWud()) {
            return $this->testWudConnection();
        }
        if ($this->isHarbor()) {
            return $this->testHarborConnection();
        }
        $client ??= $this->getClient();
        try {
            $endpoints = $client->endpoints();
        } catch (PortainerException $e) {
            return ['ok' => false, 'message' => self::errorLabels()[$e->getMessage()] ?? $e->getMessage()];
        }
        if ($endpoints === []) {
            return [
                'ok'      => true,
                'warning' => true,
                'message' => sprintf(
                    __('Connexion établie, mais à vérifier : %s', 'dockerinventory'),
                    self::warningLabels()[Importer::WARNING_NO_ENDPOINT]
                ),
            ];
        }

        $hidden = [];
        foreach ($endpoints as $endpoint) {
            $counts = PortainerMapper::snapshotCounts($endpoint);
            $id = (int) ($endpoint['Id'] ?? 0);
            if (
                $counts === null
                || $id <= 0
                || !in_array((int) ($endpoint['Type'] ?? 0), PortainerMapper::DOCKER_ENDPOINT_TYPES, true)
            ) {
                continue;
            }
            try {
                $visible = count($counts['swarm'] ? $client->services($id) : $client->containers($id));
            } catch (PortainerException) {
                // Environnement injoignable : signalé par l'import, pas par ce test.
                continue;
            }
            if (PortainerMapper::resourcesHidden($endpoint, $counts['swarm'], $visible)) {
                $hidden[] = is_scalar($endpoint['Name'] ?? null) ? (string) $endpoint['Name'] : (string) $id;
            }
        }
        $message = sprintf(
            __('Connexion réussie : %d environnement(s) Portainer visible(s) avec cette clé.', 'dockerinventory'),
            count($endpoints)
        );
        if ($hidden === []) {
            return ['ok' => true, 'warning' => false, 'message' => $message];
        }

        return [
            'ok'      => true,
            'warning' => true,
            'message' => $message . ' ' . sprintf(
                __('À vérifier (%1$s) : %2$s', 'dockerinventory'),
                implode(', ', $hidden),
                self::warningLabels()[Importer::WARNING_HIDDEN]
            ),
        ];
    }

    public static function statusLabel(int $status): string
    {
        return match ($status) {
            self::STATUS_OK      => __('Réussi', 'dockerinventory'),
            self::STATUS_PARTIAL => __('Partiel', 'dockerinventory'),
            self::STATUS_ERROR   => __('Échec', 'dockerinventory'),
            self::STATUS_WARNING => __('Avertissement', 'dockerinventory'),
            default              => __('Jamais lancé', 'dockerinventory'),
        };
    }

    /**
     * @return array<string, string>
     */
    public static function errorLabels(): array
    {
        return [
            PortainerException::UNAUTHORIZED => __("Clé d'API refusée par Portainer (401).", 'dockerinventory'),
            PortainerException::FORBIDDEN => __('Accès refusé par Portainer (403) pour cette clé.', 'dockerinventory'),
            PortainerException::NOT_FOUND => __("Adresse introuvable (404) : vérifier l'adresse.", 'dockerinventory'),
            PortainerException::NOT_SWARM => __("Cet environnement n'est pas un cluster Swarm.", 'dockerinventory'),
            PortainerException::PRIVATE_NETWORK => __('Réseau privé refusé par GLPI (voir README).', 'dockerinventory'),
            PortainerException::UNEXPECTED => __("Réponse inattendue : est-ce bien Portainer ?", 'dockerinventory'),
            PortainerException::UNREACHABLE => __('Portainer injoignable (réseau, certificat...).', 'dockerinventory'),
            WudException::UNAUTHORIZED => __('Identifiants refusés par WUD (401).', 'dockerinventory'),
            WudException::FORBIDDEN => __('Accès refusé par WUD (403).', 'dockerinventory'),
            WudException::NOT_FOUND => __("Adresse introuvable (404) : vérifier l'adresse.", 'dockerinventory'),
            WudException::SERVER_ERROR => __('WUD a répondu par une erreur interne (5xx).', 'dockerinventory'),
            WudException::PRIVATE_NETWORK => __('Réseau privé refusé par GLPI (voir README).', 'dockerinventory'),
            WudException::UNEXPECTED => __("Réponse inattendue : est-ce bien What's Up Docker ?", 'dockerinventory'),
            WudException::UNREACHABLE => __('WUD injoignable (réseau, certificat, délai...).', 'dockerinventory'),
        ] + self::registryErrorLabels();
    }

    /**
     * Raisons d'une version non vérifiée par un registre (et erreurs du test de Harbor).
     *
     * @return array<string, string>
     */
    public static function registryErrorLabels(): array
    {
        return [
            RegistryException::UNAUTHORIZED => __('Compte refusé par le registre (401).', 'dockerinventory'),
            RegistryException::FORBIDDEN => __('Accès refusé par le registre (403).', 'dockerinventory'),
            RegistryException::NOT_FOUND => __('Image introuvable dans le registre, ou privée.', 'dockerinventory'),
            RegistryException::RATE_LIMITED => __(
                'Limite de requêtes du registre atteinte (429) : nouvel essai plus tard.',
                'dockerinventory'
            ),
            RegistryException::SERVER_ERROR => __(
                'Le registre a répondu par une erreur interne (5xx).',
                'dockerinventory'
            ),
            RegistryException::PRIVATE_NETWORK => __('Réseau privé refusé par GLPI (voir README).', 'dockerinventory'),
            RegistryException::UNEXPECTED => __('Réponse inattendue du registre.', 'dockerinventory'),
            RegistryException::UNREACHABLE => __(
                'Registre injoignable (réseau, certificat, délai...).',
                'dockerinventory'
            ),
            RegistryException::UNKNOWN_REGISTRY => __(
                'Registre non pris en charge : ni Docker Hub, ni un Harbor déclaré.',
                'dockerinventory'
            ),
            RegistryException::NO_CREDENTIALS => __(
                'Registre privé : renseigner un compte robot sur la connexion Harbor.',
                'dockerinventory'
            ),
            RegistryException::SKIPPED => __(
                'Docker Hub non interrogé cette fois (limite ou réseau) : nouvel essai plus tard.',
                'dockerinventory'
            ),
        ];
    }

    private function urlErrorLabel(string $error): string
    {
        return match ($error) {
            UrlPolicy::ERROR_HTTPS_REQUIRED => __('HTTPS obligatoire (sauf « Autoriser HTTP »).', 'dockerinventory'),
            UrlPolicy::ERROR_CREDENTIALS => __("L'adresse ne doit pas contenir d'identifiants.", 'dockerinventory'),
            default => sprintf(__('Adresse invalide (exemple : %s).', 'dockerinventory'), $this->exampleUrl()),
        };
    }

    private function exampleUrl(): string
    {
        return match (true) {
            $this->isWud()    => 'https://wud.exemple.test:3000',
            $this->isHarbor() => 'https://harbor.exemple.test',
            default           => 'https://portainer.exemple.test:9443',
        };
    }

    /**
     * @return array{description: string}
     */
    public static function cronInfo(string $name): array
    {
        if ($name === Install\Installer::CRON_REGISTRY) {
            return ['description' => __(
                'Dernières versions dans les registres (Docker Hub, Harbor) pour les services sans WUD',
                'dockerinventory'
            )];
        }
        if ($name === Install\Installer::CRON_HARBOR) {
            return ['description' => __(
                'Projets, artefacts et analyses de vulnérabilités depuis Harbor',
                'dockerinventory'
            )];
        }
        if ($name === Install\Installer::CRON_WUD) {
            return ['description' => __("Lecture des versions des images depuis What's Up Docker", 'dockerinventory')];
        }

        return ['description' => __(
            'Import des stacks et services Docker (Swarm et Compose) depuis Portainer',
            'dockerinventory'
        )];
    }

    /**
     * Tâche automatique (toutes les heures) : registres des services qu'aucune connexion WUD ne
     * couvre (Docker Hub au plus une fois par jour et par image).
     */
    public static function cronRegistrycheck(CronTask $task): int
    {
        $report = (new RegistryImporter())->run();
        $task->addVolume($report['services']);
        $task->log(self::registryMessage($report));
        foreach ($report['errors'] as $error) {
            $task->log($error['endpoint'] . ' : ' . (self::errorLabels()[$error['code']] ?? $error['code']));
        }

        return $report['services'] > 0 ? 1 : 0;
    }

    /**
     * Tâche automatique (toutes les heures) : lecture de l'API de chaque connexion Harbor active.
     */
    public static function cronHarborimport(CronTask $task): int
    {
        global $DB;

        $done = 0;
        $where = ['is_active' => 1, 'type' => self::TYPE_HARBOR];
        foreach ($DB->request(['FROM' => static::getTable(), 'WHERE' => $where]) as $row) {
            $connection = new self();
            if (!$connection->getFromDB((int) $row['id'])) {
                continue;
            }
            $report = $connection->runHarborImport();
            $task->addVolume((int) $report['artifacts']);
            $task->log($connection->fields['name'] . ' : ' . self::harborMessage($report));
            foreach ($report['errors'] as $error) {
                $task->log($error['endpoint'] . ' : ' . (self::errorLabels()[$error['code']] ?? $error['code']));
            }
            $done++;
        }

        return $done > 0 ? 1 : 0;
    }

    /**
     * Tâche automatique (toutes les heures) : lecture de chaque connexion WUD active.
     */
    public static function cronWudimport(CronTask $task): int
    {
        global $DB;

        $done = 0;
        $where = ['is_active' => 1, 'type' => self::TYPE_WUD];
        foreach ($DB->request(['FROM' => static::getTable(), 'WHERE' => $where]) as $row) {
            $connection = new self();
            if (!$connection->getFromDB((int) $row['id'])) {
                continue;
            }
            $report = (new WudImporter())->import($connection);
            $task->addVolume($report['containers']);
            $task->log(sprintf(
                __('%1$s : %2$s (%3$d conteneurs, %4$d services suivis, %5$d sans service)', 'dockerinventory'),
                $connection->fields['name'],
                self::statusLabel($report['status']),
                $report['containers'],
                $report['services'],
                $report['unmatched']
            ) . self::ticketsMessage($report['tickets']));
            $done++;
        }

        return $done > 0 ? 1 : 0;
    }

    /**
     * Tâche automatique (toutes les heures) : import de chaque connexion active.
     */
    public static function cronPortainerimport(CronTask $task): int
    {
        global $DB;

        $done = 0;
        $where = ['is_active' => 1, 'type' => self::TYPE_PORTAINER];
        foreach ($DB->request(['FROM' => static::getTable(), 'WHERE' => $where]) as $row) {
            $connection = new self();
            if (!$connection->getFromDB((int) $row['id'])) {
                continue;
            }
            $report = (new Importer())->import($connection);
            $task->addVolume($report['services']);
            $task->log(sprintf(
                __('%1$s : %2$s (%3$d services, %4$d retirés, %5$d nœuds ou hôtes)', 'dockerinventory'),
                $connection->fields['name'],
                self::statusLabel($report['status']),
                $report['services'],
                $report['retired'],
                $report['nodes']
            ) . self::warningsMessage($report));
            $done++;
        }

        return $done > 0 ? 1 : 0;
    }
}

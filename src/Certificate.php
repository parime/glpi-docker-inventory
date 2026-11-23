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
use Config;
use CronTask;
use DbUtils;
use GlpiPlugin\Dockerinventory\Exposure\ExposureMapper;
use GlpiPlugin\Dockerinventory\Lifecycle\Settings;
use GlpiPlugin\Dockerinventory\Tls\CertificateReader;
use NotificationEvent;
use Profile_User;

/**
 * Certificat TLS d'une route d'entrée HTTPS (#78) : routes Traefik avec TLS et ingress dont l'hôte
 * figure dans spec.tls, relevées par l'import (voir Exposure). Pour chaque hôte, le certificat
 * présenté sur le port 443 est lu (CertificateReader : poignée de main TLS, certificat public
 * seulement), sur clic depuis Parc > Inventaire Docker > Exposition ou par la tâche automatique
 * quotidienne `certificatecheck` (désactivée à l'installation).
 *
 * Une ligne par hôte et par port ; l'entité est celle du premier service qui déclare la route (elle
 * sert aux destinataires du rappel). L'affichage ne montre un certificat qu'à travers les services
 * visibles. Le plugin ne renouvelle rien : avant l'échéance (délai réglé dans la configuration du
 * plugin), une notification est envoyée une fois par date d'expiration.
 */
class Certificate extends CommonDBTM
{
    public const CRON_NAME = 'certificatecheck';

    /** Événement de notification du rappel (NotificationTargetCertificate). */
    public const EVENT_EXPIRING = 'expiring';

    /** Port lu : celui des routes HTTP avec TLS (voir ExposureMapper::exposures()). */
    public const PORT = 443;

    /** Hôtes lus au plus par lecture (les autres attendent la suivante). */
    public const MAX_TARGETS = 100;

    /** Réglage : délai d'alerte avant l'expiration, en jours. */
    public const SETTING_WARNING_DAYS = 'certificate_warning_days';

    public const DEFAULT_WARNING_DAYS = 30;
    public const MAX_WARNING_DAYS = 365;

    public const STATUS_VALID = 'valid';
    public const STATUS_EXPIRING = 'expiring';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_ERROR = 'error';
    public const STATUS_UNCHECKED = 'unchecked';

    /** @var array<string, array<string, mixed>>|null Lignes lues pendant la requête, par « hôte:port ». */
    private static ?array $rows = null;

    public static function getTypeName($nb = 0)
    {
        return $nb > 1
            ? __('Certificats TLS', 'dockerinventory')
            : __('Certificat TLS', 'dockerinventory');
    }

    public static function getIcon()
    {
        return 'ti ti-certificate';
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
     * Délai d'alerte avant l'expiration (configuration du plugin).
     */
    public static function warningDays(): int
    {
        $values = Config::getConfigurationValues(Settings::CONTEXT, [self::SETTING_WARNING_DAYS]);
        $days = $values[self::SETTING_WARNING_DAYS] ?? null;

        return is_numeric($days) ? self::clampDays((int) $days) : self::DEFAULT_WARNING_DAYS;
    }

    public static function saveWarningDays(int $days): int
    {
        $days = self::clampDays($days);
        Config::setConfigurationValues(Settings::CONTEXT, [self::SETTING_WARNING_DAYS => $days]);

        return $days;
    }

    public static function clampDays(int $days): int
    {
        return max(1, min(self::MAX_WARNING_DAYS, $days));
    }

    /**
     * Désinstallation : réglage retiré de la configuration de GLPI.
     */
    public static function uninstallSettings(): void
    {
        Config::deleteConfigurationValues(Settings::CONTEXT, [self::SETTING_WARNING_DAYS]);
    }

    /**
     * Exposition chiffrée dont le certificat peut être lu : route Traefik ou ingress avec TLS et
     * un nom d'hôte (ni joker, ni règle sans hôte).
     *
     * @param array<string, mixed> $exposure Ligne d'ExposureMapper::exposures().
     */
    public static function isTarget(array $exposure): bool
    {
        $routes = [ExposureMapper::SOURCE_TRAEFIK, ExposureMapper::SOURCE_INGRESS];

        return in_array($exposure['source'] ?? '', $routes, true)
            && ($exposure['tls'] ?? false) === true
            && CertificateReader::isValidHost((string) ($exposure['host'] ?? ''));
    }

    public static function key(string $host, int $port): string
    {
        return strtolower($host) . ':' . $port;
    }

    /**
     * Hôtes à lire : routes HTTPS des services actifs. Avec $restrict, seulement les services des
     * entités actives (lecture sur clic) ; sans, tous (tâche automatique).
     *
     * @return array<string, array{host: string, port: int, entities_id: int}>
     */
    public static function targets(bool $restrict): array
    {
        global $DB;

        $where = ['is_retired' => 0];
        if ($restrict) {
            $where += (new DbUtils())->getEntitiesRestrictCriteria(Service::getTable(), '', '', true);
        }
        $targets = [];
        foreach ($DB->request(['FROM' => Service::getTable(), 'WHERE' => $where, 'ORDER' => 'id']) as $row) {
            foreach (Exposure::exposuresOf($row) as $exposure) {
                if (!self::isTarget($exposure)) {
                    continue;
                }
                $key = self::key($exposure['host'], self::PORT);
                $targets[$key] ??= [
                    'host'        => strtolower($exposure['host']),
                    'port'        => self::PORT,
                    'entities_id' => (int) $row['entities_id'],
                ];
            }
        }
        ksort($targets);

        return array_slice($targets, 0, self::MAX_TARGETS, true);
    }

    /**
     * Lit le certificat de chaque hôte et enregistre ce qui est relevé (ou le code d'erreur ; un
     * certificat lu auparavant est gardé, avec la date du dernier essai).
     *
     * @return array{checked: int, ok: int, errors: int}
     */
    public static function check(bool $restrict, ?CertificateReader $reader = null): array
    {
        global $DB;

        $reader ??= new CertificateReader();
        $now = (string) ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'));
        $report = ['checked' => 0, 'ok' => 0, 'errors' => 0];
        foreach (self::targets($restrict) as $target) {
            $result = $reader->read($target['host'], $target['port']);
            $report['checked']++;
            $values = [
                'entities_id' => $target['entities_id'],
                'error'       => $result['error'],
                'date_check'  => $now,
                'date_mod'    => $now,
            ];
            $certificate = $result['certificate'];
            if ($certificate !== null) {
                $report['ok']++;
                $values += [
                    'subject'         => $certificate['subject'],
                    'issuer'          => $certificate['issuer'],
                    'sans'            => implode(', ', $certificate['sans']),
                    'serial'          => $certificate['serial'],
                    'date_not_before' => date('Y-m-d H:i:s', $certificate['not_before']),
                    'date_not_after'  => date('Y-m-d H:i:s', $certificate['not_after']),
                    'is_self_signed'  => $certificate['self_signed'] ? 1 : 0,
                    'date_success'    => $now,
                ];
            } else {
                $report['errors']++;
            }
            $where = ['host' => $target['host'], 'port' => $target['port']];
            $existing = $DB->request(['SELECT' => ['id'], 'FROM' => self::getTable(), 'WHERE' => $where, 'LIMIT' => 1])
                ->current();
            if (is_array($existing)) {
                $DB->update(self::getTable(), $values, ['id' => (int) $existing['id']]);
            } else {
                $DB->insert(self::getTable(), $where + $values + ['date_creation' => $now]);
            }
        }
        self::$rows = null;

        return $report;
    }

    /**
     * Message de la lecture sur clic.
     *
     * @param array{checked: int, ok: int, errors: int} $report
     */
    public static function checkMessage(array $report): string
    {
        if ($report['checked'] === 0) {
            return __('Aucune route HTTPS avec un nom d’hôte : aucun certificat à lire.', 'dockerinventory');
        }

        return sprintf(
            __('Certificats lus : %1$d sur %2$d (%3$d en erreur).', 'dockerinventory'),
            $report['ok'],
            $report['checked'],
            $report['errors']
        );
    }

    /**
     * Ligne enregistrée pour un hôte et un port, null si jamais lue.
     *
     * @return array<string, mixed>|null
     */
    public static function lookup(string $host, int $port): ?array
    {
        global $DB;

        if (self::$rows === null) {
            self::$rows = [];
            if ($DB->tableExists(self::getTable())) {
                foreach ($DB->request(['FROM' => self::getTable()]) as $row) {
                    self::$rows[self::key((string) $row['host'], (int) $row['port'])] = $row;
                }
            }
        }

        return self::$rows[self::key($host, $port)] ?? null;
    }

    /**
     * Oublie les lignes lues (tests, après une lecture).
     */
    public static function resetCache(): void
    {
        self::$rows = null;
    }

    /**
     * Jours restants jusqu'à l'expiration (0 le dernier jour, négatif une fois expiré).
     */
    public static function daysLeft(string $notAfter, string $today): ?int
    {
        $end = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($notAfter, 0, 10));
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($today, 0, 10));
        if ($end === false || $start === false) {
            return null;
        }

        return (int) $start->diff($end)->format('%r%a');
    }

    /**
     * État affiché d'une ligne (ou de son absence).
     *
     * @param array<string, mixed>|null $row
     * @return array{status: string, days: int|null, subject: string, issuer: string, not_after: string,
     *     self_signed: bool, error: string, date_check: string, sans: string}
     */
    public static function state(?array $row, string $today, int $warningDays): array
    {
        $notAfter = (string) ($row['date_not_after'] ?? '');
        $days = $notAfter === '' ? null : self::daysLeft($notAfter, $today);
        $error = (string) ($row['error'] ?? '');
        $status = match (true) {
            $row === null                       => self::STATUS_UNCHECKED,
            $days === null                      => self::STATUS_ERROR,
            $days < 0                           => self::STATUS_EXPIRED,
            $days <= $warningDays               => self::STATUS_EXPIRING,
            default                             => self::STATUS_VALID,
        };

        return [
            'status'      => $status,
            'days'        => $days,
            'subject'     => (string) ($row['subject'] ?? ''),
            'issuer'      => (string) ($row['issuer'] ?? ''),
            'not_after'   => $notAfter,
            'self_signed' => (bool) ($row['is_self_signed'] ?? false),
            'error'       => $error,
            'date_check'  => (string) ($row['date_check'] ?? ''),
            'sans'        => (string) ($row['sans'] ?? ''),
        ];
    }

    /**
     * Libellés, couleurs et icônes des états.
     *
     * @return array<string, array{label: string, color: string, icon: string}>
     */
    public static function statusInfos(): array
    {
        return [
            self::STATUS_EXPIRED   => [
                'label' => __('Certificat expiré', 'dockerinventory'),
                'color' => 'danger',
                'icon'  => 'ti ti-certificate-off',
            ],
            self::STATUS_EXPIRING  => [
                'label' => __('Expire bientôt', 'dockerinventory'),
                'color' => 'warning',
                'icon'  => 'ti ti-alarm',
            ],
            self::STATUS_VALID     => [
                'label' => __('Certificat valide', 'dockerinventory'),
                'color' => 'success',
                'icon'  => 'ti ti-certificate',
            ],
            self::STATUS_ERROR     => [
                'label' => __('Certificat non lu', 'dockerinventory'),
                'color' => 'secondary',
                'icon'  => 'ti ti-plug-connected-x',
            ],
            self::STATUS_UNCHECKED => [
                'label' => __('Pas encore lu', 'dockerinventory'),
                'color' => 'secondary',
                'icon'  => 'ti ti-hourglass',
            ],
        ];
    }

    /**
     * Motifs d'un échec de lecture (codes de CertificateReader).
     *
     * @return array<string, string>
     */
    public static function errorLabels(): array
    {
        return [
            CertificateReader::ERROR_HOST           => __('Nom d’hôte non lisible.', 'dockerinventory'),
            CertificateReader::ERROR_CONNECT        => __(
                'Connexion TLS impossible (hôte injoignable depuis GLPI, port fermé ou délai dépassé).',
                'dockerinventory'
            ),
            CertificateReader::ERROR_NO_CERTIFICATE => __(
                'Le serveur n’a présenté aucun certificat.',
                'dockerinventory'
            ),
            CertificateReader::ERROR_PARSE          => __('Certificat présenté illisible.', 'dockerinventory'),
        ];
    }

    /**
     * Certificats des routes HTTPS des services actifs visibles (entités actives), les plus proches
     * de l'expiration d'abord, avec les services qui les utilisent.
     *
     * @return array{certificates: list<array<string, mixed>>, counts: array{total: int, expiring: int,
     *     expired: int, errors: int, unchecked: int}, warning_days: int}
     */
    public static function overview(): array
    {
        global $DB;

        $today = substr((string) ($_SESSION['glpi_currenttime'] ?? date('Y-m-d')), 0, 10);
        $warningDays = self::warningDays();
        $byKey = [];
        foreach (
            $DB->request([
                'FROM'  => Service::getTable(),
                'WHERE' => ['is_retired' => 0]
                    + (new DbUtils())->getEntitiesRestrictCriteria(Service::getTable(), '', '', true),
                'ORDER' => ['stack_name', 'name'],
            ]) as $row
        ) {
            foreach (Exposure::exposuresOf($row) as $exposure) {
                if (!self::isTarget($exposure)) {
                    continue;
                }
                $key = self::key($exposure['host'], self::PORT);
                $byKey[$key] ??= [
                    'host'     => strtolower($exposure['host']),
                    'port'     => self::PORT,
                    'services' => [],
                ] + self::state(self::lookup($exposure['host'], self::PORT), $today, $warningDays);
                $byKey[$key]['services'][(int) $row['id']] = [
                    'name' => (string) $row['name'],
                    'url'  => Service::getFormURLWithID((int) $row['id']),
                ];
            }
        }
        $counts = ['total' => count($byKey), 'expiring' => 0, 'expired' => 0, 'errors' => 0, 'unchecked' => 0];
        foreach ($byKey as $certificate) {
            match ($certificate['status']) {
                self::STATUS_EXPIRING  => $counts['expiring']++,
                self::STATUS_EXPIRED   => $counts['expired']++,
                self::STATUS_ERROR     => $counts['errors']++,
                self::STATUS_UNCHECKED => $counts['unchecked']++,
                default                => null,
            };
        }
        $certificates = array_map(
            static fn (array $certificate): array => ['services' => array_values($certificate['services'])]
                + $certificate,
            array_values($byKey)
        );
        usort($certificates, static fn (array $a, array $b): int => [
            $a['days'] === null,
            $a['days'] ?? 0,
            $a['host'],
        ] <=> [
            $b['days'] === null,
            $b['days'] ?? 0,
            $b['host'],
        ]);

        return ['certificates' => $certificates, 'counts' => $counts, 'warning_days' => $warningDays];
    }

    /**
     * @return array{description: string}
     */
    public static function cronInfo(string $name): array
    {
        return ['description' => __(
            'Certificats TLS des routes d’entrée : lecture et rappel avant l’expiration',
            'dockerinventory'
        )];
    }

    /**
     * Tâche automatique quotidienne (#78), désactivée à l'installation : lecture des certificats
     * de toutes les routes HTTPS, puis rappel des certificats qui expirent bientôt.
     */
    public static function cronCertificatecheck(CronTask $task): int
    {
        $report = self::check(false);
        $sent = self::remindExpiring((string) ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s')));
        $task->addVolume($report['checked']);
        $task->log(self::checkMessage($report));
        $task->log(sprintf(
            _n('%d rappel d’expiration envoyé', '%d rappels d’expiration envoyés', $sent, 'dockerinventory'),
            $sent
        ));

        return $report['checked'] > 0 ? 1 : 0;
    }

    /**
     * Rappel avant l'expiration : pour chaque certificat encore valide qui expire dans le délai
     * d'alerte, et toujours utilisé par une route d'un service actif, l'événement EVENT_EXPIRING est
     * levé une seule fois par date d'expiration (`reminded_not_after`) ; un certificat renouvelé
     * sera rappelé à nouveau avant sa propre échéance. Rien n'est renouvelé.
     *
     * @return int Nombre de certificats rappelés.
     */
    public static function remindExpiring(string $today): int
    {
        global $DB;

        $start = substr($today, 0, 10);
        $limit = date('Y-m-d', (int) strtotime($start . ' +' . self::warningDays() . ' days')) . ' 23:59:59';
        $targets = self::targets(false);
        $sent = 0;
        foreach (
            $DB->request([
                'FROM'  => self::getTable(),
                'WHERE' => [
                    ['date_not_after' => ['>=', $start . ' 00:00:00']],
                    ['date_not_after' => ['<=', $limit]],
                ],
            ]) as $row
        ) {
            $notAfter = (string) $row['date_not_after'];
            if ((string) ($row['reminded_not_after'] ?? '') === $notAfter) {
                continue;
            }
            if (!isset($targets[self::key((string) $row['host'], (int) $row['port'])])) {
                // Route retirée depuis : plus rien à rappeler.
                continue;
            }
            $certificate = new self();
            if (!$certificate->getFromDB((int) $row['id'])) {
                continue;
            }
            NotificationEvent::raiseEvent(self::EVENT_EXPIRING, $certificate);
            // Écriture directe : la trace du rappel n'est pas une modification du certificat.
            $DB->update(self::getTable(), ['reminded_not_after' => $notAfter], ['id' => (int) $row['id']]);
            $sent++;
        }

        return $sent;
    }

    /**
     * Noms des services actifs dont une route utilise ce certificat (texte du rappel).
     *
     * @return list<string>
     */
    public function serviceNames(): array
    {
        global $DB;

        $names = [];
        $key = self::key((string) ($this->fields['host'] ?? ''), (int) ($this->fields['port'] ?? 0));
        foreach ($DB->request(['FROM' => Service::getTable(), 'WHERE' => ['is_retired' => 0]]) as $row) {
            foreach (Exposure::exposuresOf($row) as $exposure) {
                if (self::isTarget($exposure) && self::key($exposure['host'], self::PORT) === $key) {
                    $names[] = (string) $row['name'];
                    break;
                }
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Le compte peut-il recevoir le rappel ? Il lui faut, sur un profil valable dans l'entité du
     * certificat, le droit de lecture des services du plugin. Une adresse sans compte ne reçoit rien.
     */
    public static function canReceive(int $usersId, self $certificate): bool
    {
        if ($usersId <= 0) {
            return false;
        }
        $entity = max(0, (int) ($certificate->fields['entities_id'] ?? 0));
        $profiles = array_map('intval', array_keys(Profile_User::getUserProfiles(
            $usersId,
            getEntitiesRestrictCriteria('glpi_profiles_users', '', $entity, true)
        )));
        if ($profiles === []) {
            return false;
        }

        return countElementsInTable('glpi_profilerights', [
            'profiles_id' => $profiles,
            'name'        => Service::RIGHTNAME,
            'rights'      => ['&', READ],
        ]) > 0;
    }
}

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

namespace GlpiPlugin\Dockerinventory\Lifecycle;

use GlpiPlugin\Dockerinventory\ImageVersion;
use GlpiPlugin\Dockerinventory\Service;
use GlpiPlugin\Dockerinventory\Ticket\EolTickets;
use GlpiPlugin\Dockerinventory\Ticket\UpdateTickets;

/**
 * Fin de support des images déployées : cache des cycles de vie (table
 * glpi_plugin_dockerinventory_lifecycles) et évaluation de chaque image à l'affichage.
 *
 * Règles :
 * - rien n'est appelé tant que la source n'est pas activée (réglage, désactivé par défaut) ;
 * - seuls les produits des images en service (correspondance explicite) sont lus, au plus une fois
 *   par jour chacun, y compris « produit inconnu de la source » ;
 * - un refus (429) ou une panne arrête les appels pour la mise à jour en cours ; les cycles déjà
 *   connus restent utilisés (fonctionnement hors ligne, date de la dernière mise à jour affichée) ;
 * - sans cycles connus, l'état est « non vérifié », jamais « maintenue » ; jamais bloquant ;
 * - aucun objet GLPI n'est modifié ; seuls des tickets de fin de support peuvent être créés, si
 *   une connexion Portainer les a activés (EolTickets).
 *
 * @phpstan-import-type Cycle from LifecycleClient
 * @phpstan-import-type Evaluation from CycleMatcher
 * @phpstan-type LifecycleReport array{status: string, products: int, fetched: int, cached: int,
 *     not_found: int, requests: int, errors: list<array{product: string, code: string}>,
 *     tickets: array{created: int, followups: int, unchanged: int, errors: int}}
 */
final class LifecycleChecker
{
    public const CACHE_TABLE = 'glpi_plugin_dockerinventory_lifecycles';
    public const MAP_TABLE = 'glpi_plugin_dockerinventory_lifecycleproducts';

    /** Durée de validité du cache d'un produit. */
    public const CACHE_TTL = DAY_TIMESTAMP;

    public const REPORT_OK = 'ok';
    public const REPORT_PARTIAL = 'partial';
    public const REPORT_DISABLED = 'disabled';

    /** Données lues une fois par requête pour l'affichage. */
    private static ?self $current = null;

    private LifecycleClient $client;

    private string $now;

    /** @var array<string, string>|null */
    private ?array $map = null;

    /** @var array<string, array{cycles: list<Cycle>|null, error: string, date_checked: string,
     *     date_success: string}>|null */
    private ?array $cache = null;

    public function __construct(?LifecycleClient $client = null, ?string $now = null)
    {
        $this->client = $client ?? new LifecycleClient();
        $this->now = $now ?? (string) ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'));
    }

    /**
     * Instance partagée pendant la requête (affichage).
     */
    public static function current(): self
    {
        return self::$current ??= new self();
    }

    /**
     * Oublier les données lues (tests, après une mise à jour).
     */
    public static function reset(): void
    {
        self::$current = null;
        Settings::reset();
    }

    /**
     * Mise à jour des cycles de vie des produits des images en service, puis tickets de fin de
     * support. Rien si la source est désactivée.
     *
     * @return LifecycleReport
     */
    public function refresh(): array
    {
        $report = [
            'status'    => self::REPORT_OK,
            'products'  => 0,
            'fetched'   => 0,
            'cached'    => 0,
            'not_found' => 0,
            'requests'  => 0,
            'errors'    => [],
            'tickets'   => UpdateTickets::emptyReport(),
        ];
        if (!Settings::enabled()) {
            $report['status'] = self::REPORT_DISABLED;

            return $report;
        }
        $blocked = false;
        foreach ($this->productsInUse() as $product) {
            $report['products']++;
            $cached = $this->cache()[$product] ?? null;
            if ($cached !== null && $this->isFresh($cached['date_success'])) {
                $report['cached']++;
                if ($cached['error'] === LifecycleException::NOT_FOUND) {
                    $report['not_found']++;
                }
                continue;
            }
            if ($blocked) {
                $report['errors'][] = ['product' => $product, 'code' => LifecycleException::SKIPPED];
                continue;
            }
            try {
                $cycles = $this->client->cycles($product);
                $this->write($product, $cycles, '', true);
                $report['fetched']++;
            } catch (LifecycleException $e) {
                $code = $e->getMessage();
                if ($code === LifecycleException::NOT_FOUND) {
                    // Produit inconnu de la source : résultat valable un jour (correspondance à revoir).
                    $this->write($product, [], $code, true);
                    $report['not_found']++;
                } else {
                    // Limite ou panne : cycles déjà connus gardés, plus d'appel pour cette mise à jour.
                    $this->write($product, null, $code, false);
                    $report['errors'][] = ['product' => $product, 'code' => $code];
                    $blocked = true;
                }
            }
        }
        $report['requests'] = $this->client->requests();
        if ($report['errors'] !== []) {
            $report['status'] = self::REPORT_PARTIAL;
        }
        $this->cache = null;
        self::reset();
        $report['tickets'] = (new EolTickets($this))->process();

        return $report;
    }

    private function isFresh(string $date): bool
    {
        return $date !== '' && strtotime($date) > strtotime($this->now) - self::CACHE_TTL;
    }

    /**
     * Fin de support d'une image déployée.
     *
     * @return Evaluation
     */
    public function evaluate(string $image, string $tag): array
    {
        $product = ProductMap::productFor($image, $this->map());
        if (!Settings::enabled()) {
            return ['reason' => CycleMatcher::REASON_DISABLED]
                + CycleMatcher::evaluate($product, $tag, null, $this->today(), Settings::warningDays());
        }
        $cached = $product === '' ? null : ($this->cache()[$product] ?? null);

        return CycleMatcher::evaluate(
            $product,
            $tag,
            $cached['cycles'] ?? null,
            $this->today(),
            Settings::warningDays(),
            $cached !== null && $cached['error'] === LifecycleException::NOT_FOUND
        );
    }

    public function today(): string
    {
        return substr($this->now, 0, 10);
    }

    /**
     * Table de correspondance : image normalisée => produit.
     *
     * @return array<string, string>
     */
    public function map(): array
    {
        global $DB;

        if ($this->map === null) {
            $this->map = [];
            foreach ($DB->request(['FROM' => self::MAP_TABLE, 'ORDER' => 'image']) as $row) {
                $this->map[(string) $row['image']] = (string) $row['product'];
            }
        }

        return $this->map;
    }

    /**
     * Cycles connus de chaque produit (null : jamais lus avec succès).
     *
     * @return array<string, array{cycles: list<Cycle>|null, error: string, date_checked: string,
     *     date_success: string}>
     */
    public function cache(): array
    {
        global $DB;

        if ($this->cache === null) {
            $this->cache = [];
            foreach ($DB->request(['FROM' => self::CACHE_TABLE]) as $row) {
                $cycles = null;
                if (($row['date_success'] ?? null) !== null) {
                    $decoded = json_decode((string) $row['cycles'], true);
                    $cycles = self::cyclesFrom(is_array($decoded) ? $decoded : []);
                }
                $this->cache[(string) $row['product']] = [
                    'cycles'       => $cycles,
                    'error'        => (string) $row['error'],
                    'date_checked' => (string) ($row['date_checked'] ?? ''),
                    'date_success' => (string) ($row['date_success'] ?? ''),
                ];
            }
        }

        return $this->cache;
    }

    /**
     * @param array<mixed> $decoded
     * @return list<Cycle>
     */
    private static function cyclesFrom(array $decoded): array
    {
        $cycles = [];
        foreach ($decoded as $cycle) {
            if (!is_array($cycle) || !is_string($cycle['cycle'] ?? null)) {
                continue;
            }
            $cycles[] = [
                'cycle'    => $cycle['cycle'],
                'codename' => is_string($cycle['codename'] ?? null) ? $cycle['codename'] : '',
                'eol'      => is_string($cycle['eol'] ?? null) ? $cycle['eol'] : '',
                'latest'   => is_string($cycle['latest'] ?? null) ? $cycle['latest'] : '',
            ];
        }

        return $cycles;
    }

    /**
     * Enregistre le résultat d'une lecture (cycles null : lecture échouée, cycles connus gardés).
     *
     * @param list<Cycle>|null $cycles
     */
    private function write(string $product, ?array $cycles, string $error, bool $success): void
    {
        global $DB;

        $values = ['error' => $error, 'date_checked' => $this->now];
        if ($cycles !== null) {
            $values['cycles'] = (string) json_encode($cycles);
        }
        if ($success) {
            $values['date_success'] = $this->now;
        }
        $where = ['product' => $product];
        $existing = $DB->request(['SELECT' => ['id'], 'FROM' => self::CACHE_TABLE, 'WHERE' => $where])->current();
        if (is_array($existing)) {
            $DB->update(self::CACHE_TABLE, $values, ['id' => (int) $existing['id']]);
        } else {
            $DB->insert(self::CACHE_TABLE, $where + $values + ($cycles === null ? ['cycles' => '[]'] : []));
        }
    }

    /**
     * Produits des images en service (services actifs et lignes de version), triés.
     *
     * @return list<string>
     */
    public function productsInUse(): array
    {
        $products = [];
        foreach ($this->imagesInUse() as $image => $count) {
            $product = ProductMap::productFor($image, $this->map());
            if ($product !== '') {
                $products[$product] = true;
            }
        }
        $list = array_keys($products);
        sort($list);

        return $list;
    }

    /**
     * Images en service (nom normalisé => nombre de services ou conteneurs), avec ou sans
     * correspondance.
     *
     * @return array<string, int>
     */
    public function imagesInUse(): array
    {
        global $DB;

        $images = [];
        $iterator = $DB->request([
            'SELECT' => ['image'],
            'FROM'   => Service::getTable(),
            'WHERE'  => ['is_retired' => 0, ['NOT' => ['image' => '']]],
        ]);
        foreach ($iterator as $row) {
            $key = ProductMap::normalizeImage((string) $row['image']);
            if ($key !== '') {
                $images[$key] = ($images[$key] ?? 0) + 1;
            }
        }
        // Conteneurs suivis par WUD sans service importé.
        $iterator = $DB->request([
            'SELECT' => ['image'],
            'FROM'   => ImageVersion::getTable(),
            'WHERE'  => ['plugin_dockerinventory_services_id' => 0, ['NOT' => ['image' => '']]],
        ]);
        foreach ($iterator as $row) {
            $key = ProductMap::normalizeImage((string) $row['image']);
            if ($key !== '') {
                $images[$key] = ($images[$key] ?? 0) + 1;
            }
        }
        ksort($images);

        return $images;
    }

    /**
     * État de la source pour l'affichage : dernière mise à jour réussie, dernier essai, produits en
     * erreur (données en cache utilisées).
     *
     * @return array{enabled: bool, last_success: string, last_attempt: string,
     *     errors: list<array{product: string, code: string, date_success: string}>}
     */
    public function sourceState(): array
    {
        $success = '';
        $attempt = '';
        $errors = [];
        foreach ($this->cache() as $product => $row) {
            $success = max($success, $row['date_success']);
            $attempt = max($attempt, $row['date_checked']);
            if ($row['error'] !== '') {
                $errors[] = ['product' => $product, 'code' => $row['error'], 'date_success' => $row['date_success']];
            }
        }

        return [
            'enabled'      => Settings::enabled(),
            'last_success' => $success,
            'last_attempt' => $attempt,
            'errors'       => $errors,
        ];
    }
}

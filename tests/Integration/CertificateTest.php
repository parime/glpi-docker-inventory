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

namespace GlpiPlugin\Dockerinventory\Tests\Integration;

use CronTask;
use GlpiPlugin\Dockerinventory\Api\Evidence;
use GlpiPlugin\Dockerinventory\Certificate;
use GlpiPlugin\Dockerinventory\Exposure;
use GlpiPlugin\Dockerinventory\Exposure\ExposureMapper;
use GlpiPlugin\Dockerinventory\Install\CertificateNotification;
use GlpiPlugin\Dockerinventory\NotificationTargetCertificate;
use GlpiPlugin\Dockerinventory\Service;
use GlpiPlugin\Dockerinventory\Tls\CertificateReader;
use GlpiPlugin\Dockerinventory\VolumeBackup;
use Notification;
use NotificationTemplate;
use PHPUnit\Framework\TestCase;

/**
 * Certificats TLS des routes d'entrée (#78) : hôtes lus (routes HTTPS des services actifs),
 * certificat enregistré, état « expire bientôt », rappel envoyé une fois par date d'expiration,
 * notification installée, preuves de l'API. Le transport TLS est remplacé par des certificats
 * auto-signés générés pendant le test (la vraie poignée de main est testée dans
 * CertificateReaderTest). Assertions sur des valeurs et des marqueurs, jamais sur des libellés
 * traduits (CI en anglais, instance de développement en français).
 */
final class CertificateTest extends TestCase
{
    private string $suffix = '';

    private int $entity = 0;

    /** @var list<int> */
    private array $services = [];

    private string $today = '';

    /** @var array<string, string> Valeurs de configuration du plugin avant le test. */
    private array $config = [];

    protected function setUp(): void
    {
        $this->suffix = bin2hex(random_bytes(4));
        $this->today = substr((string) $_SESSION['glpi_currenttime'], 0, 10);
        $this->config = \Config::getConfigurationValues('plugin:dockerinventory', [Certificate::SETTING_WARNING_DAYS]);
        $this->entity = (int) (new \Entity())->add([
            'name'        => 'PHPUnit certificats ' . $this->suffix,
            'entities_id' => 0,
        ]);
        $this->assertGreaterThan(0, $this->entity);
        \Session::changeActiveEntities(0, true);
        Certificate::resetCache();
    }

    protected function tearDown(): void
    {
        global $DB;

        foreach ($this->services as $id) {
            (new Service())->delete(['id' => $id], true);
        }
        $DB->delete(Certificate::getTable(), ['host' => ['LIKE', '%' . $this->suffix . '%']]);
        (new \Entity())->delete(['id' => $this->entity], true);
        if ($this->config === []) {
            \Config::deleteConfigurationValues('plugin:dockerinventory', [Certificate::SETTING_WARNING_DAYS]);
        } else {
            \Config::setConfigurationValues('plugin:dockerinventory', $this->config);
        }
        Certificate::resetCache();
        \Session::changeActiveEntities(0, true);
    }

    private function host(string $name): string
    {
        return $name . '-' . $this->suffix . '.exemple.test';
    }

    /**
     * Service dont une route Traefik HTTPS (ou HTTP) déclare l'hôte donné.
     */
    private function service(string $name, string $host, bool $tls = true, bool $retired = false): int
    {
        $id = (int) (new Service())->add([
            'name'        => $name . '-' . $this->suffix,
            'service_uid' => $name . '-' . $this->suffix,
            'entities_id' => $this->entity,
            'image'       => 'nginx',
            'is_retired'  => $retired ? 1 : 0,
            'routes'      => ExposureMapper::encode([[
                'source'      => ExposureMapper::SOURCE_TRAEFIK,
                'host'        => $host,
                'path'        => '/',
                'tls'         => $tls,
                'port'        => 8080,
                'protocol'    => 'tcp',
                'name'        => 'web',
                'entrypoints' => 'websecure',
            ]]),
        ]);
        $this->assertGreaterThan(0, $id);
        $this->services[] = $id;

        return $id;
    }

    /**
     * Certificat auto-signé fictif (PEM) valable le nombre de jours donné.
     */
    private static function certificate(string $commonName, int $days): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);
        $csr = openssl_csr_new(['commonName' => $commonName, 'organizationName' => 'Exemple fictif'], $key);
        self::assertNotFalse($csr);
        self::assertNotTrue($csr);
        $x509 = openssl_csr_sign($csr, null, $key, $days, ['digest_alg' => 'sha256']);
        self::assertNotFalse($x509);
        $pem = '';
        self::assertTrue(openssl_x509_export($x509, $pem));

        return $pem;
    }

    /**
     * Lecteur dont le transport renvoie, pour chaque hôte, le certificat donné (null : injoignable).
     *
     * @param array<string, string|null> $byHost
     */
    private static function reader(array $byHost): CertificateReader
    {
        return new CertificateReader(static fn (string $host): ?string => $byHost[$host] ?? null);
    }

    /**
     * Ligne enregistrée pour un hôte.
     *
     * @return array<string, mixed>|null
     */
    private function row(string $host): ?array
    {
        $certificate = new Certificate();

        return $certificate->getFromDBByCrit(['host' => $host, 'port' => Certificate::PORT])
            ? $certificate->fields
            : null;
    }

    public function testTargetsAreHttpsRoutesOfActiveServices(): void
    {
        $this->service('https', $this->host('app'));
        $this->service('http', $this->host('clair'), false);
        $this->service('retire', $this->host('ancien'), true, true);

        $targets = Certificate::targets(false);

        $this->assertArrayHasKey(Certificate::key($this->host('app'), 443), $targets);
        $this->assertSame($this->entity, $targets[Certificate::key($this->host('app'), 443)]['entities_id']);
        $this->assertArrayNotHasKey(Certificate::key($this->host('clair'), 443), $targets);
        $this->assertArrayNotHasKey(Certificate::key($this->host('ancien'), 443), $targets);
        $this->assertFalse(Certificate::isTarget([
            'source' => ExposureMapper::SOURCE_PORT,
            'tls'    => true,
            'host'   => 'x.exemple.test',
        ]));
    }

    public function testCertificateIsReadThenKeptWhenTheHostBecomesUnreachable(): void
    {
        $serviceId = $this->service('https', $this->host('app'));
        $pem = self::certificate($this->host('app'), 10);

        $report = Certificate::check(false, self::reader([$this->host('app') => $pem]));

        $this->assertGreaterThanOrEqual(1, $report['ok']);
        $row = $this->row($this->host('app'));
        $this->assertNotNull($row);
        $this->assertSame($this->host('app') . ' (Exemple fictif)', $row['subject']);
        $this->assertSame(1, (int) $row['is_self_signed']);
        $this->assertSame('', $row['error']);
        $this->assertSame($this->entity, (int) $row['entities_id']);
        $state = Certificate::state($row, $this->today, Certificate::warningDays());
        $this->assertSame(Certificate::STATUS_EXPIRING, $state['status']);
        $this->assertContains($state['days'], [9, 10]);

        // Exposition du service : certificat attaché à la route HTTPS.
        $service = new Service();
        $this->assertTrue($service->getFromDB($serviceId));
        $exposure = Exposure::describe($service->fields)['exposures'][0];
        $this->assertIsArray($exposure['certificate']);
        $this->assertSame(Certificate::STATUS_EXPIRING, $exposure['certificate']['status']);
        $overview = Certificate::overview();
        $hosts = array_column($overview['certificates'], 'host');
        $this->assertContains($this->host('app'), $hosts);

        // Hôte devenu injoignable : code d'erreur, certificat précédent gardé.
        Certificate::check(false, self::reader([]));
        $row = $this->row($this->host('app'));
        $this->assertNotNull($row);
        $this->assertSame(CertificateReader::ERROR_CONNECT, $row['error']);
        $this->assertNotSame('', (string) $row['date_not_after']);

        // Preuve A.8.24.
        $evidence = Evidence::certificates();
        $this->assertIsArray($evidence);
        $this->assertContains($this->host('app'), array_column($evidence['certificates'], 'host'));
    }

    public function testStatesAndWarningDelay(): void
    {
        $row = ['date_not_after' => '2026-03-20 12:00:00', 'error' => ''];
        $this->assertSame(Certificate::STATUS_EXPIRING, Certificate::state($row, '2026-03-10', 30)['status']);
        $this->assertSame(Certificate::STATUS_VALID, Certificate::state($row, '2026-03-10', 5)['status']);
        $this->assertSame(Certificate::STATUS_EXPIRED, Certificate::state($row, '2026-03-21', 30)['status']);
        $this->assertSame(Certificate::STATUS_UNCHECKED, Certificate::state(null, '2026-03-10', 30)['status']);
        $this->assertSame(
            Certificate::STATUS_ERROR,
            Certificate::state(['date_not_after' => null, 'error' => 'connect'], '2026-03-10', 30)['status']
        );
        $this->assertSame(Certificate::MAX_WARNING_DAYS, Certificate::saveWarningDays(5000));
        $this->assertSame(1, Certificate::saveWarningDays(0));
        $this->assertSame(1, Certificate::warningDays());
    }

    public function testReminderIsSentOncePerExpiryDate(): void
    {
        $this->service('bientot', $this->host('bientot'));
        $this->service('loin', $this->host('loin'));
        Certificate::saveWarningDays(30);
        Certificate::check(false, self::reader([
            $this->host('bientot') => self::certificate($this->host('bientot'), 5),
            $this->host('loin')    => self::certificate($this->host('loin'), 200),
        ]));

        Certificate::remindExpiring($this->today);
        $soon = $this->row($this->host('bientot'));
        $far = $this->row($this->host('loin'));
        $this->assertNotNull($soon);
        $this->assertNotNull($far);
        $this->assertSame($soon['date_not_after'], $soon['reminded_not_after']);
        $this->assertNull($far['reminded_not_after']);

        // Deuxième passage : rien de nouveau ; certificat renouvelé : nouveau rappel à sa date.
        $before = $soon['reminded_not_after'];
        Certificate::remindExpiring($this->today);
        $this->assertSame($before, $this->row($this->host('bientot'))['reminded_not_after'] ?? null);
    }

    public function testNotificationTemplateDataAndRecipients(): void
    {
        $this->service('donnees', $this->host('donnees'));
        $pem = self::certificate($this->host('donnees'), 7);
        Certificate::check(false, self::reader([$this->host('donnees') => $pem]));
        $certificate = new Certificate();
        $this->assertTrue($certificate->getFromDBByCrit(['host' => $this->host('donnees')]));

        $target = new NotificationTargetCertificate($this->entity, Certificate::EVENT_EXPIRING, $certificate);
        $this->assertArrayHasKey(Certificate::EVENT_EXPIRING, $target->getEvents());
        $target->addDataForTemplate(Certificate::EVENT_EXPIRING);
        $this->assertSame($this->host('donnees'), $target->data['##certificate.host##']);
        $this->assertContains($target->data['##certificate.days##'], ['6', '7']);
        $this->assertSame('donnees-' . $this->suffix, $target->data['##certificate.services##']);
        $this->assertSame(NotificationTargetCertificate::advice(), $target->data['##certificate.advice##']);
        $this->assertNotSame('', $target->data['##lang.certificate.enddate##']);

        $this->assertTrue(Certificate::canReceive((int) \Session::getLoginUserID(), $certificate));
        $this->assertFalse(Certificate::canReceive(0, $certificate));
    }

    public function testNotificationTaskAndEvidenceAreInstalled(): void
    {
        $this->assertSame(1, countElementsInTable(Notification::getTable(), [
            'itemtype' => Certificate::class,
            'event'    => Certificate::EVENT_EXPIRING,
        ]));
        $this->assertSame(1, countElementsInTable(NotificationTemplate::getTable(), [
            'itemtype' => Certificate::class,
            'name'     => CertificateNotification::NAME,
        ]));
        $task = new CronTask();
        $this->assertTrue($task->getFromDBbyName(Certificate::class, Certificate::CRON_NAME));
        $content = CertificateNotification::content();
        $this->assertStringContainsString('##certificate.host##', $content['content_html']);
        $this->assertStringNotContainsString('{{', $content['content_html']);
        $this->assertStringContainsString('##lang.certificate.enddate##', $content['content_text']);

        // Preuve A.8.13 : structure stable, même sans volume.
        $backups = Evidence::backups();
        $this->assertIsArray($backups);
        $this->assertSame(VolumeBackup::unbackedSearchURL(), $backups['unbacked_url']);
        $this->assertArrayHasKey('rate', $backups);
    }
}

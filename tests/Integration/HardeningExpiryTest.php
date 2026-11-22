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
use GlpiPlugin\Dockerinventory\Hardening;
use GlpiPlugin\Dockerinventory\Hardening\HardeningMapper;
use GlpiPlugin\Dockerinventory\HardeningException;
use GlpiPlugin\Dockerinventory\Install\ExpiryNotification;
use GlpiPlugin\Dockerinventory\NotificationTargetHardeningException;
use GlpiPlugin\Dockerinventory\Service;
use Notification;
use NotificationTemplate;
use PHPUnit\Framework\TestCase;

/**
 * Rappel avant l'expiration d'une exception (#99) : exceptions qui expirent bientôt dans la
 * synthèse, rappel envoyé une fois par date de fin, notification installée, destinataires
 * limités aux comptes qui lisent les services. Assertions sur des valeurs et des marqueurs,
 * jamais sur des libellés traduits (CI en anglais, instance de développement en français).
 */
final class HardeningExpiryTest extends TestCase
{
    private string $suffix = '';

    private int $entity = 0;

    /** @var list<int> */
    private array $services = [];

    private string $today = '';

    protected function setUp(): void
    {
        $this->suffix = bin2hex(random_bytes(4));
        $this->today = substr((string) $_SESSION['glpi_currenttime'], 0, 10);
        $this->entity = (int) (new \Entity())->add(['name' => 'PHPUnit rappel ' . $this->suffix, 'entities_id' => 0]);
        $this->assertGreaterThan(0, $this->entity);
        \Session::changeActiveEntities(0, true);
    }

    protected function tearDown(): void
    {
        global $DB;

        foreach ($this->services as $id) {
            $DB->delete(HardeningException::getTable(), ['plugin_dockerinventory_services_id' => $id]);
            (new Service())->delete(['id' => $id], true);
        }
        (new \Entity())->delete(['id' => $this->entity], true);
        \Session::changeActiveEntities(0, true);
    }

    private function service(string $name, bool $retired = false): int
    {
        $id = (int) (new Service())->add([
            'name'        => $name . '-' . $this->suffix,
            'service_uid' => $name . '-' . $this->suffix,
            'entities_id' => $this->entity,
            'image'       => 'nginx',
            'is_retired'  => $retired ? 1 : 0,
            'hardening'   => HardeningMapper::encode([
                ['code' => HardeningMapper::DOCKER_SOCKET, 'detail' => '/var/run/docker.sock'],
                ['code' => HardeningMapper::ROOT_USER, 'detail' => ''],
            ]),
        ]);
        $this->assertGreaterThan(0, $id);
        $this->services[] = $id;

        return $id;
    }

    private function except(int $serviceId, string $code, int $days): HardeningException
    {
        $end = date('Y-m-d', (int) strtotime($this->today . ' ' . ($days >= 0 ? '+' : '') . $days . ' days'));
        $this->assertSame('', HardeningException::record($serviceId, $code, 'Motif fictif', $end));
        $exception = new HardeningException();
        $this->assertTrue($exception->getFromDBByCrit([
            'plugin_dockerinventory_services_id' => $serviceId,
            'code'                               => $code,
        ]));

        return $exception;
    }

    public function testDaysLeftAndExpiringWindow(): void
    {
        $this->assertSame(0, HardeningException::daysLeft('2026-03-10', '2026-03-10 08:00:00'));
        $this->assertSame(30, HardeningException::daysLeft('2026-04-09', '2026-03-10'));
        $this->assertSame(-1, HardeningException::daysLeft('2026-03-09', '2026-03-10'));
        $this->assertNull(HardeningException::daysLeft('', '2026-03-10'));
        $this->assertTrue(HardeningException::isExpiringSoon('2026-03-10', '2026-03-10'));
        $this->assertTrue(HardeningException::isExpiringSoon('2026-04-09', '2026-03-10'));
        $this->assertFalse(HardeningException::isExpiringSoon('2026-04-10', '2026-03-10'));
        $this->assertFalse(HardeningException::isExpiringSoon('2026-03-09', '2026-03-10'));
    }

    public function testExpiringExceptionsAreCountedAndFiltered(): void
    {
        $soon = $this->service('bientot');
        $later = $this->service('plus-tard');
        $this->except($soon, HardeningMapper::DOCKER_SOCKET, 10);
        $this->except($later, HardeningMapper::DOCKER_SOCKET, HardeningException::REMIND_DAYS + 30);

        $service = new Service();
        $this->assertTrue($service->getFromDB($soon));
        $described = Hardening::describe($service->fields);
        $this->assertSame(1, $described['expiring']);
        $finding = array_values(array_filter(
            $described['findings'],
            static fn (array $f): bool => $f['code'] === HardeningMapper::DOCKER_SOCKET
        ))[0];
        $this->assertTrue($finding['expiring']);
        $this->assertSame(10, $finding['days_left']);

        $overview = Hardening::overview(Hardening::FILTER_EXPIRING);
        $ids = array_column($overview['services'], 'id');
        $this->assertContains($soon, $ids);
        $this->assertNotContains($later, $ids);
        $this->assertGreaterThanOrEqual(1, $overview['counts']['expiring']);
    }

    public function testReminderIsSentOncePerEndDate(): void
    {
        global $DB;

        $active = $this->service('actif');
        $retired = $this->service('retire', true);
        $exception = $this->except($active, HardeningMapper::DOCKER_SOCKET, 5);
        $this->except($active, HardeningMapper::ROOT_USER, HardeningException::REMIND_DAYS + 10);
        $this->except($retired, HardeningMapper::DOCKER_SOCKET, 5);

        $reminded = fn (): array => array_column(iterator_to_array($DB->request([
            'SELECT' => ['plugin_dockerinventory_services_id', 'code', 'reminded_date_end'],
            'FROM'   => HardeningException::getTable(),
            'WHERE'  => [
                'plugin_dockerinventory_services_id' => $this->services,
                'NOT'                                => ['reminded_date_end' => null],
            ],
        ]), false), 'reminded_date_end', 'code');

        HardeningException::remindExpiring($this->today);
        $this->assertSame(
            [HardeningMapper::DOCKER_SOCKET => substr((string) $exception->fields['date_end'], 0, 10)],
            $reminded()
        );

        // Deuxième passage : rien de nouveau pour ces services.
        $before = $reminded();
        HardeningException::remindExpiring($this->today);
        $this->assertSame($before, $reminded());

        // Renouvelée avec une nouvelle date de fin encore proche : nouveau rappel.
        $renewed = $this->except($active, HardeningMapper::DOCKER_SOCKET, 20);
        HardeningException::remindExpiring($this->today);
        $this->assertSame(
            [HardeningMapper::DOCKER_SOCKET => substr((string) $renewed->fields['date_end'], 0, 10)],
            $reminded()
        );
    }

    public function testFixedFindingIsNotReminded(): void
    {
        $id = $this->service('corrige');
        $exception = $this->except($id, HardeningMapper::DOCKER_SOCKET, 3);
        (new Service())->update([
            'id'        => $id,
            'hardening' => HardeningMapper::encode([['code' => HardeningMapper::ROOT_USER, 'detail' => '']]),
        ]);

        HardeningException::remindExpiring($this->today);
        $this->assertTrue($exception->getFromDB($exception->getID()));
        $this->assertNull($exception->fields['reminded_date_end']);
    }

    public function testNotificationTemplateDataAndRecipients(): void
    {
        $id = $this->service('donnees');
        $exception = $this->except($id, HardeningMapper::DOCKER_SOCKET, 7);

        $target = new NotificationTargetHardeningException(
            $this->entity,
            HardeningException::EVENT_EXPIRING,
            $exception
        );
        $this->assertArrayHasKey(HardeningException::EVENT_EXPIRING, $target->getEvents());
        $target->addDataForTemplate(HardeningException::EVENT_EXPIRING);
        $this->assertSame('donnees-' . $this->suffix, $target->data['##exception.service##']);
        $this->assertSame('7', $target->data['##exception.days##']);
        $this->assertSame('Motif fictif', $target->data['##exception.justification##']);
        $this->assertSame(NotificationTargetHardeningException::advice(), $target->data['##exception.advice##']);
        $this->assertNotSame('', $target->data['##lang.exception.enddate##']);
        $this->assertStringContainsString('Service_' . $id, rawurldecode($target->data['##exception.url##']));

        // Le compte de test (super-administrateur, racine récursive) lit les services ; un compte
        // inconnu ou sans profil ne reçoit rien.
        $this->assertTrue(HardeningException::canReceive((int) \Session::getLoginUserID(), $exception));
        $this->assertFalse(HardeningException::canReceive(0, $exception));
        $userId = (int) (new \User())->add(['name' => 'phpunit-rappel-' . $this->suffix]);
        $this->assertGreaterThan(0, $userId);
        global $DB;
        $DB->delete('glpi_profiles_users', ['users_id' => $userId]);
        $this->assertFalse(HardeningException::canReceive($userId, $exception));
        (new \User())->delete(['id' => $userId], true);
    }

    public function testNotificationAndTaskAreInstalled(): void
    {
        $this->assertSame(1, countElementsInTable(Notification::getTable(), [
            'itemtype' => HardeningException::class,
            'event'    => HardeningException::EVENT_EXPIRING,
        ]));
        $this->assertSame(1, countElementsInTable(NotificationTemplate::getTable(), [
            'itemtype' => HardeningException::class,
            'name'     => ExpiryNotification::NAME,
        ]));
        $task = new CronTask();
        $this->assertTrue($task->getFromDBbyName(HardeningException::class, HardeningException::CRON_NAME));

        $content = ExpiryNotification::content();
        $this->assertStringContainsString('##exception.url##', $content['content_html']);
        $this->assertStringContainsString('##exception.days##', $content['content_text']);
        $this->assertStringNotContainsString('{{', $content['content_html']);
        $this->assertStringContainsString('##lang.exception.enddate##', $content['content_html']);
        // Libellés et conduite à tenir traduits à l'envoi, dans la langue du destinataire.
        $target = new NotificationTargetHardeningException(0, HardeningException::EVENT_EXPIRING);
        $target->getTags();
        $this->assertArrayHasKey(
            '##lang.exception.enddate##',
            $target->tag_descriptions[\NotificationTarget::TAG_LANGUAGE]
        );
    }
}

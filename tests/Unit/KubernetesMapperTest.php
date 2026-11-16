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

namespace GlpiPlugin\Dockerinventory\Tests\Unit;

use GlpiPlugin\Dockerinventory\Kubernetes\KubernetesMapper;
use GlpiPlugin\Dockerinventory\Tests\KubernetesFixture;
use PHPUnit\Framework\TestCase;

/**
 * Réponses de l'API Kubernetes => valeurs enregistrées (jeu fictif tests/mock-kubernetes).
 */
final class KubernetesMapperTest extends TestCase
{
    /**
     * @return array<string, array<string, mixed>> service_uid => valeurs.
     */
    private function workloads(string $excluded = KubernetesMapper::DEFAULT_EXCLUDED): array
    {
        $f = KubernetesFixture::load();
        $index = KubernetesMapper::podIndex($f['pods'], $f['replicasets'], KubernetesMapper::cronJobNames($f['cronjobs']));
        $patterns = KubernetesMapper::excludedPatterns($excluded);
        $all = [];
        foreach (KubernetesMapper::WORKLOAD_RESOURCES as $kind => $resource) {
            foreach (KubernetesMapper::mapWorkloads($kind, $f[$resource], $index, $patterns) as $workload) {
                $all[$workload['service_uid']] = $workload;
            }
        }

        return $all;
    }

    public function testWorkloadsOnePerContainerSystemNamespacesIgnored(): void
    {
        $all = $this->workloads();
        $this->assertSame([
            'k8s/cronjob/intranet/sauvegarde/sauvegarde',
            'k8s/daemonset/supervision/collecteur/collecteur',
            'k8s/deployment/boutique/ancienne-api/api',
            'k8s/deployment/boutique/vitrine/api',
            'k8s/deployment/intranet/portail/journal',
            'k8s/deployment/intranet/portail/wiki',
            'k8s/statefulset/boutique/cache/redis',
        ], $this->sorted(array_keys($all)));

        $this->assertArrayHasKey('k8s/deployment/kube-system/coredns/coredns', $this->workloads(''));
    }

    public function testDeploymentValuesAndDigestFromPods(): void
    {
        $vitrine = $this->workloads()['k8s/deployment/boutique/vitrine/api'];
        $this->assertSame('vitrine', $vitrine['name']);
        $this->assertSame('boutique', $vitrine['stack_name']);
        $this->assertSame('registry.exemple.test:5000/boutique/api', $vitrine['image']);
        $this->assertSame('1.9.0', $vitrine['tag']);
        $this->assertSame('sha256:' . str_repeat('a190', 16), $vitrine['digest']);
        $this->assertSame(2, $vitrine['replicas']);
        $this->assertSame(2, $vitrine['running_replicas']);
        $this->assertSame(KubernetesMapper::STATE_AVAILABLE, $vitrine['state']);
        $this->assertSame('8080/tcp', $vitrine['ports']);
        // Noms des secrets seulement : ni les valeurs, ni les variables d'environnement.
        $this->assertSame('registre-boutique, vitrine-base', $vitrine['secrets']);
        $this->assertStringNotContainsString('valeur-fictive', (string) json_encode($vitrine));
        $this->assertSame(['k8s-node-01.exemple.test' => 1, 'k8s-node-02' => 1], $vitrine['placement']);
    }

    public function testStatesAndReplicasOfEachKind(): void
    {
        $all = $this->workloads();
        $old = $all['k8s/deployment/boutique/ancienne-api/api'];
        $this->assertSame(KubernetesMapper::STATE_SCALED_DOWN, $old['state']);
        $this->assertSame(0, $old['replicas']);
        $this->assertSame('', $old['digest']);

        $cache = $all['k8s/statefulset/boutique/cache/redis'];
        $this->assertSame(KubernetesMapper::STATE_UNAVAILABLE, $cache['state']);
        $this->assertSame('donnees-cache', $cache['volumes']);
        $this->assertSame([], $cache['placement']);

        $collector = $all['k8s/daemonset/supervision/collecteur/collecteur'];
        $this->assertSame([2, 1, 'global'], [$collector['replicas'], $collector['running_replicas'], $collector['mode']]);
        $this->assertSame(KubernetesMapper::STATE_DEGRADED, $collector['state']);
        $this->assertSame('9100:9100/tcp', $collector['ports']);

        $backup = $all['k8s/cronjob/intranet/sauvegarde/sauvegarde'];
        $this->assertNull($backup['replicas']);
        $this->assertSame(1, $backup['running_replicas']);
        $this->assertSame('0 2 * * *', $backup['mode']);
        $this->assertSame(KubernetesMapper::STATE_ACTIVE, $backup['state']);
        $this->assertSame('sha256:' . str_repeat('b', 64), $backup['digest']);
        $this->assertSame('OnFailure', $backup['restart_policy']);
    }

    public function testSeveralContainersGiveNamedServices(): void
    {
        $all = $this->workloads();
        $wiki = $all['k8s/deployment/intranet/portail/wiki'];
        $journal = $all['k8s/deployment/intranet/portail/journal'];
        $this->assertSame('portail/wiki', $wiki['name']);
        $this->assertSame('portail/journal', $journal['name']);
        $this->assertSame('busybox', $journal['image']);
        $this->assertSame('portail-donnees', $wiki['volumes']);
        $this->assertSame('portail-tls', $wiki['secrets']);
        // Deployment sans « replicas » : 1, valeur par défaut de Kubernetes.
        $this->assertSame(1, $wiki['replicas']);
    }

    public function testNodes(): void
    {
        $f = KubernetesFixture::load();
        $first = KubernetesMapper::mapNode($f['nodes'][0]);
        $second = KubernetesMapper::mapNode($f['nodes'][1]);
        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame(KubernetesMapper::ROLE_CONTROL_PLANE, $first['role']);
        $this->assertSame('ready', $first['state']);
        $this->assertSame('active', $first['availability']);
        $this->assertSame('v1.31.4 (containerd://1.7.23)', $first['engine_version']);
        $this->assertSame(['192.0.2.21'], $first['addresses']);
        $this->assertSame(KubernetesMapper::ROLE_WORKER, $second['role']);
        $this->assertSame('unknown', $second['state']);
        $this->assertSame('cordoned', $second['availability']);
        $this->assertNull(KubernetesMapper::mapNode(['metadata' => []]));
    }

    public function testDigestAndExclusionHelpers(): void
    {
        $digest = 'sha256:' . str_repeat('0f', 32);
        $this->assertSame($digest, KubernetesMapper::digestOf('docker-pullable://nginx@' . $digest));
        $this->assertSame($digest, KubernetesMapper::digestOf('docker.io/library/nginx@' . $digest));
        $this->assertSame('', KubernetesMapper::digestOf($digest));
        $this->assertSame('', KubernetesMapper::digestOf('nginx@sha256:court'));

        $this->assertSame('kube-system, cattle-*', KubernetesMapper::normalizeExcluded(" kube-system,\ncattle-* ; <b>"));
        $patterns = KubernetesMapper::excludedPatterns('kube-*');
        $this->assertTrue(KubernetesMapper::isExcluded('kube-public', $patterns));
        $this->assertFalse(KubernetesMapper::isExcluded('boutique', $patterns));
    }

    public function testDeploymentFoundWithoutItsReplicaSet(): void
    {
        $f = KubernetesFixture::load();
        $index = KubernetesMapper::podIndex($f['pods'], [], []);
        $this->assertArrayHasKey('deployment/boutique/vitrine', $index);
        // Pod sans propriétaire, Job sans CronJob connu : ignorés.
        $this->assertArrayNotHasKey('cronjob/intranet/sauvegarde', $index);
        $this->assertCount(4, $index);
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }
}

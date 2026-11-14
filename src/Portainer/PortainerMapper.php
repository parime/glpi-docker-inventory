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

namespace GlpiPlugin\Dockerinventory\Portainer;

/**
 * Traduction des réponses de l'API Portainer (et de l'API Docker qu'elle relaie) en valeurs à
 * enregistrer. Fonctions pures, sans GLPI : testées sans base de données.
 *
 * Formes lues (API Docker Engine v1.41+, relayée par /api/endpoints/{id}/docker/...) :
 * - service : ID, Spec.Name, Spec.Labels, Spec.Mode.{Replicated.Replicas|Global},
 *   Spec.TaskTemplate.ContainerSpec.{Image, Secrets[].SecretName}, Spec.TaskTemplate.Networks[].Target,
 *   Endpoint.Ports[] / Spec.EndpointSpec.Ports[] ;
 * - nœud : ID, Description.{Hostname, Engine.EngineVersion}, Spec.{Role, Availability}, Status.{State, Addr} ;
 * - tâche : ServiceID, NodeID, Status.State ;
 * - hôte autonome (/docker/info) : ID, Name, ServerVersion ;
 * - conteneur (/docker/containers/json?all=1) : Id, Names[], Image, ImageID, State, Labels (étiquettes
 *   Compose seulement), Ports[].{IP, PrivatePort, PublicPort, Type}, NetworkSettings.Networks (noms),
 *   Mounts[].{Type, Name, Destination} ;
 * - image (/docker/images/json) : Id, RepoTags[], RepoDigests[] ;
 * - détail d'un conteneur : HostConfig.RestartPolicy.{Name, MaximumRetryCount} uniquement. Les
 *   variables d'environnement (Config.Env) et le contenu des secrets ne sont jamais lus.
 */
final class PortainerMapper
{
    /** Étiquette posée par « docker stack deploy » (et par Portainer) sur chaque service d'une stack. */
    public const STACK_LABEL = 'com.docker.stack.namespace';

    /** Environnements Portainer de type Docker (1 local, 2 agent, 4 agent Edge) ; les autres : Kubernetes, Azure. */
    public const DOCKER_ENDPOINT_TYPES = [1, 2, 4];

    /** Stack Portainer de type Swarm (2 = Compose sur un hôte seul, 3 = Kubernetes). */
    public const STACK_TYPE_SWARM = 1;

    /** Stack Portainer de type Compose (hôte Docker autonome). */
    public const STACK_TYPE_COMPOSE = 2;

    /** Étiquettes posées par Docker Compose sur chaque conteneur d'un projet. */
    public const COMPOSE_PROJECT_LABEL = 'com.docker.compose.project';
    public const COMPOSE_SERVICE_LABEL = 'com.docker.compose.service';

    /** Nature d'un service importé : service Swarm, service Compose, conteneur hors Compose. */
    public const KIND_SWARM = 'swarm';
    public const KIND_COMPOSE = 'compose';
    public const KIND_CONTAINER = 'container';

    /** Rôle enregistré pour l'hôte d'un environnement Docker autonome (nœuds Swarm : manager, worker). */
    public const ROLE_STANDALONE = 'standalone';

    /** Point de montage des secrets d'un fichier Compose hors Swarm (seul le nom est gardé). */
    private const SECRETS_DIR = '/run/secrets/';

    /** Longueur de la colonne service_uid. */
    private const UID_MAX = 255;

    /**
     * Découpe une référence d'image : « registre:port/projet/image:tag@sha256:… ».
     * Sans tag ni empreinte, Docker utilise « latest » : il est alors noté tel quel.
     *
     * @return array{image: string, tag: string, digest: string}
     */
    public static function parseImage(string $reference): array
    {
        $reference = trim($reference);
        $digest = '';
        $at = strpos($reference, '@');
        if ($at !== false) {
            $digest = substr($reference, $at + 1);
            $reference = substr($reference, 0, $at);
        }
        $tag = '';
        $slash = strrpos($reference, '/');
        $colon = strrpos($reference, ':');
        if ($colon !== false && ($slash === false || $colon > $slash)) {
            $tag = substr($reference, $colon + 1);
            $reference = substr($reference, 0, $colon);
        }
        if ($tag === '' && $digest === '' && $reference !== '') {
            $tag = 'latest';
        }

        return ['image' => $reference, 'tag' => $tag, 'digest' => $digest];
    }

    /**
     * @param array<string, mixed> $service Service tel que renvoyé par /docker/services.
     * @param array<string, string> $networkNames Identifiant de réseau => nom.
     * @param array<string, int> $runningByService Identifiant de service => tâches en cours.
     * @return array{service_uid: string, name: string, stack_name: string, image: string, tag: string,
     *     digest: string, mode: string, replicas: int|null, running_replicas: int, ports: string,
     *     networks: string, secrets: string}|null Null si le service n'a pas d'identifiant.
     */
    public static function mapService(array $service, array $networkNames = [], array $runningByService = []): ?array
    {
        $uid = self::str($service['ID'] ?? null);
        if ($uid === '') {
            return null;
        }
        $spec = self::arr($service['Spec'] ?? null);
        $template = self::arr($spec['TaskTemplate'] ?? null);
        $container = self::arr($template['ContainerSpec'] ?? null);
        $labels = self::arr($spec['Labels'] ?? null);
        $mode = self::arr($spec['Mode'] ?? null);
        $image = self::parseImage(self::str($container['Image'] ?? null));

        $modeName = '';
        $replicas = null;
        if (array_key_exists('Replicated', $mode)) {
            $modeName = 'replicated';
            $replicas = (int) (self::arr($mode['Replicated'])['Replicas'] ?? 0);
        } elseif (array_key_exists('Global', $mode)) {
            $modeName = 'global';
        } elseif ($mode !== []) {
            $modeName = strtolower((string) array_key_first($mode));
        }

        $endpoint = self::arr($service['Endpoint'] ?? null);
        $ports = self::arr($endpoint['Ports'] ?? null)
            ?: self::arr(self::arr($spec['EndpointSpec'] ?? null)['Ports'] ?? null);
        $portList = [];
        foreach ($ports as $port) {
            $port = self::arr($port);
            $published = (int) ($port['PublishedPort'] ?? 0);
            if ($published <= 0) {
                continue;
            }
            $entry = sprintf(
                '%d:%d/%s',
                $published,
                (int) ($port['TargetPort'] ?? 0),
                self::str($port['Protocol'] ?? null) ?: 'tcp'
            );
            if (self::str($port['PublishMode'] ?? null) === 'host') {
                $entry .= ' (host)';
            }
            $portList[] = $entry;
        }

        $networks = self::arr($template['Networks'] ?? null) ?: self::arr($spec['Networks'] ?? null);
        $networkList = [];
        foreach ($networks as $network) {
            $target = self::str(self::arr($network)['Target'] ?? null);
            if ($target !== '') {
                $networkList[] = $networkNames[$target] ?? $target;
            }
        }

        // Noms des secrets seulement : l'API Docker n'expose jamais leur contenu, et rien d'autre
        // que SecretName n'est lu ici.
        $secretList = [];
        foreach (self::arr($container['Secrets'] ?? null) as $secret) {
            $name = self::str(self::arr($secret)['SecretName'] ?? null);
            if ($name !== '') {
                $secretList[] = $name;
            }
        }

        return [
            'service_uid'      => $uid,
            'name'             => self::str($spec['Name'] ?? null) ?: $uid,
            'stack_name'       => self::str($labels[self::STACK_LABEL] ?? null),
            'image'            => $image['image'],
            'tag'              => $image['tag'],
            'digest'           => $image['digest'],
            'mode'             => $modeName,
            'replicas'         => $replicas,
            'running_replicas' => $runningByService[$uid] ?? 0,
            'ports'            => self::join($portList),
            'networks'         => self::join($networkList),
            'secrets'          => self::join($secretList),
        ];
    }

    /**
     * @param array<string, mixed> $node Nœud tel que renvoyé par /docker/nodes.
     * @return array{node_uid: string, hostname: string, role: string, availability: string, state: string,
     *     engine_version: string, address: string}|null
     */
    public static function mapNode(array $node): ?array
    {
        $uid = self::str($node['ID'] ?? null);
        if ($uid === '') {
            return null;
        }
        $description = self::arr($node['Description'] ?? null);
        $spec = self::arr($node['Spec'] ?? null);
        $status = self::arr($node['Status'] ?? null);

        return [
            'node_uid'       => $uid,
            'hostname'       => self::str($description['Hostname'] ?? null),
            'role'           => self::str($spec['Role'] ?? null),
            'availability'   => self::str($spec['Availability'] ?? null),
            'state'          => self::str($status['State'] ?? null),
            'engine_version' => self::str(self::arr($description['Engine'] ?? null)['EngineVersion'] ?? null),
            'address'        => self::str($status['Addr'] ?? null),
        ];
    }

    /**
     * Tâches en cours, par service et par nœud.
     *
     * @param list<mixed> $tasks Tâches renvoyées par /docker/tasks.
     * @return array{services: array<string, int>, placement: array<string, array<string, int>>}
     */
    public static function runningTasks(array $tasks): array
    {
        $services = [];
        $placement = [];
        foreach ($tasks as $task) {
            $task = self::arr($task);
            if (self::str(self::arr($task['Status'] ?? null)['State'] ?? null) !== 'running') {
                continue;
            }
            $service = self::str($task['ServiceID'] ?? null);
            $node = self::str($task['NodeID'] ?? null);
            if ($service === '') {
                continue;
            }
            $services[$service] = ($services[$service] ?? 0) + 1;
            if ($node !== '') {
                $placement[$service][$node] = ($placement[$service][$node] ?? 0) + 1;
            }
        }

        return ['services' => $services, 'placement' => $placement];
    }

    /**
     * @param list<mixed> $networks Réseaux renvoyés par /docker/networks.
     * @return array<string, string> Identifiant => nom.
     */
    public static function networkNames(array $networks): array
    {
        $names = [];
        foreach ($networks as $network) {
            $network = self::arr($network);
            $id = self::str($network['Id'] ?? null);
            $name = self::str($network['Name'] ?? null);
            if ($id !== '' && $name !== '') {
                $names[$id] = $name;
            }
        }

        return $names;
    }

    /**
     * Images d'un hôte autonome : identifiant => tags et empreintes du dépôt.
     *
     * @param list<mixed> $images Images renvoyées par /docker/images/json.
     * @return array<string, array{tags: list<string>, digests: list<string>}>
     */
    public static function imageIndex(array $images): array
    {
        $index = [];
        foreach ($images as $image) {
            $image = self::arr($image);
            $id = self::str($image['Id'] ?? null);
            if ($id === '') {
                continue;
            }
            $index[$id] = [
                'tags'    => self::strings(self::arr($image['RepoTags'] ?? null)),
                'digests' => self::strings(self::arr($image['RepoDigests'] ?? null)),
            ];
        }

        return $index;
    }

    /**
     * Politique de redémarrage d'un conteneur (« unless-stopped », « on-failure:5 »...), d'après
     * son détail. Seul HostConfig.RestartPolicy est lu : ni Config.Env ni rien d'autre.
     *
     * @param array<string, mixed> $inspect Réponse de /docker/containers/{id}/json.
     */
    public static function restartPolicy(array $inspect): string
    {
        $policy = self::arr(self::arr($inspect['HostConfig'] ?? null)['RestartPolicy'] ?? null);
        $name = self::str($policy['Name'] ?? null);
        if ($name === '') {
            // Docker renvoie un nom vide quand aucune politique n'est définie.
            return array_key_exists('Name', $policy) ? 'no' : '';
        }
        $retries = (int) ($policy['MaximumRetryCount'] ?? 0);

        return $name === 'on-failure' && $retries > 0 ? sprintf('%s:%d', $name, $retries) : $name;
    }

    /**
     * Conteneur d'un hôte autonome. Null sans identifiant, ou pour une tâche Swarm (lue par le
     * manager du cluster, pas ici).
     *
     * @param array<string, mixed> $container Conteneur renvoyé par /docker/containers/json.
     * @param array<string, array{tags: list<string>, digests: list<string>}> $images imageIndex().
     * @return array{uid: string, name: string, project: string, compose_service: string, image: string,
     *     tag: string, digest: string, state: string, ports: list<string>, networks: list<string>,
     *     volumes: list<string>, secrets: list<string>}|null
     */
    public static function mapContainer(array $container, array $images = []): ?array
    {
        $uid = self::str($container['Id'] ?? null);
        if ($uid === '') {
            return null;
        }
        $labels = self::arr($container['Labels'] ?? null);
        if (isset($labels['com.docker.swarm.task.id']) || isset($labels['com.docker.swarm.service.name'])) {
            return null;
        }
        $names = self::strings(self::arr($container['Names'] ?? null));
        $name = $names === [] ? '' : ltrim($names[0], '/');

        $imageId = self::str($container['ImageID'] ?? null);
        $known = $images[$imageId] ?? ['tags' => [], 'digests' => []];
        $reference = self::str($container['Image'] ?? null);
        if ($reference === '' || str_starts_with($reference, 'sha256:')) {
            // Image retaguée depuis le démarrage : Docker ne donne plus que son identifiant.
            $reference = $known['tags'][0] ?? '';
        }
        $image = self::parseImage($reference);
        $digest = $image['digest'] !== '' ? $image['digest'] : self::repoDigest($image['image'], $known['digests']);

        $ports = [];
        foreach (self::arr($container['Ports'] ?? null) as $port) {
            $port = self::arr($port);
            $public = (int) ($port['PublicPort'] ?? 0);
            if ($public <= 0) {
                continue;
            }
            $ip = self::str($port['IP'] ?? null);
            $ports[] = sprintf(
                '%s%d:%d/%s',
                in_array($ip, ['', '0.0.0.0', '::'], true) ? '' : $ip . ':',
                $public,
                (int) ($port['PrivatePort'] ?? 0),
                self::str($port['Type'] ?? null) ?: 'tcp'
            );
        }

        $networks = array_map(
            'strval',
            array_keys(self::arr(self::arr($container['NetworkSettings'] ?? null)['Networks'] ?? null))
        );

        // Volumes nommés et noms des secrets Compose (montés dans /run/secrets/) : ni le chemin sur
        // l'hôte ni le contenu ne sont lus.
        $volumes = [];
        $secrets = [];
        foreach (self::arr($container['Mounts'] ?? null) as $mount) {
            $mount = self::arr($mount);
            $type = self::str($mount['Type'] ?? null);
            $destination = self::str($mount['Destination'] ?? null);
            if ($type === 'volume' && self::str($mount['Name'] ?? null) !== '') {
                $volumes[] = self::str($mount['Name']);
            } elseif ($type === 'bind' && str_starts_with($destination, self::SECRETS_DIR)) {
                $secret = basename($destination);
                if ($secret !== '') {
                    $secrets[] = $secret;
                }
            }
        }

        $project = self::str($labels[self::COMPOSE_PROJECT_LABEL] ?? null);

        return [
            'uid'             => $uid,
            'name'            => $name !== '' ? $name : $uid,
            'project'         => $project,
            'compose_service' => $project === '' ? '' : self::str($labels[self::COMPOSE_SERVICE_LABEL] ?? null),
            'image'           => $image['image'],
            'tag'             => $image['tag'],
            'digest'          => $digest,
            'state'           => strtolower(self::str($container['State'] ?? null)),
            'ports'           => $ports,
            'networks'        => $networks,
            'volumes'         => $volumes,
            'secrets'         => $secrets,
        ];
    }

    /**
     * Services d'un hôte autonome : un par service Compose (projet et service), un par conteneur
     * hors Compose. Les valeurs (image, tag, empreinte) sont celles du premier conteneur en cours.
     *
     * @param list<array{uid: string, name: string, project: string, compose_service: string, image: string,
     *     tag: string, digest: string, state: string, ports: list<string>, networks: list<string>,
     *     volumes: list<string>, secrets: list<string>}> $containers mapContainer().
     * @param array<string, string> $restartPolicies Identifiant du conteneur => restartPolicy().
     * @return list<array{service_uid: string, name: string, stack_name: string, kind: string, image: string,
     *     tag: string, digest: string, mode: string, replicas: int, running_replicas: int, ports: string,
     *     networks: string, secrets: string, volumes: string, state: string, restart_policy: string,
     *     container_uids: string, container_names: string}>
     */
    public static function groupContainers(int $endpointId, array $containers, array $restartPolicies = []): array
    {
        $groups = [];
        foreach ($containers as $container) {
            if ($container['project'] !== '') {
                $service = $container['compose_service'] !== '' ? $container['compose_service'] : $container['name'];
                $key = sprintf('compose:%d:%s:%s', $endpointId, $container['project'], $service);
                $groups[$key]['meta'] = [$service, $container['project'], self::KIND_COMPOSE];
            } else {
                $key = sprintf('container:%d:%s', $endpointId, $container['name']);
                $groups[$key]['meta'] = [$container['name'], '', self::KIND_CONTAINER];
            }
            $groups[$key]['containers'][] = $container;
        }
        ksort($groups);

        $services = [];
        foreach ($groups as $key => $group) {
            [$name, $stack, $kind] = $group['meta'];
            $members = $group['containers'];
            usort($members, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
            $running = array_values(array_filter($members, static fn (array $c): bool => $c['state'] === 'running'));
            $first = $running[0] ?? $members[0];
            $collect = static function (string $field) use ($members): string {
                $values = [];
                foreach ($members as $member) {
                    $values = array_merge($values, $member[$field]);
                }

                return self::join($values);
            };
            $policies = [];
            foreach ($members as $member) {
                if (($restartPolicies[$member['uid']] ?? '') !== '') {
                    $policies[] = $restartPolicies[$member['uid']];
                }
            }
            $uid = strlen($key) > self::UID_MAX ? substr($key, 0, self::UID_MAX - 41) . ':' . sha1($key) : $key;

            $services[] = [
                'service_uid'      => $uid,
                'name'             => $name,
                'stack_name'       => $stack,
                'kind'             => $kind,
                'image'            => $first['image'],
                'tag'              => $first['tag'],
                'digest'           => $first['digest'],
                'mode'             => $kind,
                'replicas'         => count($members),
                'running_replicas' => count($running),
                'ports'            => $collect('ports'),
                'networks'         => $collect('networks'),
                'secrets'          => $collect('secrets'),
                'volumes'          => $collect('volumes'),
                'state'            => self::join(array_column($members, 'state')),
                'restart_policy'   => self::join($policies),
                'container_uids'   => self::join(array_column($members, 'uid')),
                'container_names'  => self::join(array_column($members, 'name')),
            ];
        }

        return $services;
    }

    /**
     * Hôte d'un environnement Docker autonome, enregistré comme un nœud (rôle « standalone »).
     *
     * @param array<string, mixed> $info Réponse de /docker/info.
     * @param bool $up Environnement joignable selon Portainer (Status 1).
     * @return array{node_uid: string, hostname: string, role: string, availability: string, state: string,
     *     engine_version: string, address: string}
     */
    public static function mapHost(array $info, int $endpointId, bool $up, string $address = ''): array
    {
        $id = self::str($info['ID'] ?? null);

        return [
            // Identifiant du moteur Docker ; à défaut, celui de l'environnement Portainer.
            'node_uid'       => $id !== '' ? substr($id, 0, 64) : sprintf('endpoint:%d', $endpointId),
            'hostname'       => self::str($info['Name'] ?? null),
            'role'           => self::ROLE_STANDALONE,
            'availability'   => '',
            'state'          => $up ? 'ready' : 'down',
            'engine_version' => self::str($info['ServerVersion'] ?? null),
            'address'        => $address,
        ];
    }

    /**
     * Hôte de l'adresse d'un environnement Portainer (« tcp://docker-01.exemple.test:2375 »), vide
     * pour un socket local.
     */
    public static function endpointHost(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? $host : '';
    }

    /**
     * Empreinte du dépôt de l'image, parmi les RepoDigests (« adminer@sha256:… ») de l'hôte.
     *
     * @param list<string> $digests
     */
    private static function repoDigest(string $image, array $digests): string
    {
        $wanted = self::normalizeRepository($image);
        foreach ($digests as $entry) {
            $at = strpos($entry, '@');
            if ($at !== false && self::normalizeRepository(substr($entry, 0, $at)) === $wanted) {
                return substr($entry, $at + 1);
            }
        }
        if (count($digests) === 1 && str_contains($digests[0], '@')) {
            return substr($digests[0], (int) strpos($digests[0], '@') + 1);
        }

        return '';
    }

    private static function normalizeRepository(string $repository): string
    {
        $repository = strtolower($repository);
        foreach (['docker.io/', 'index.docker.io/', 'registry-1.docker.io/'] as $prefix) {
            if (str_starts_with($repository, $prefix)) {
                $repository = substr($repository, strlen($prefix));
            }
        }

        return str_starts_with($repository, 'library/') ? substr($repository, 8) : $repository;
    }

    /**
     * @param array<mixed> $values
     * @return list<string>
     */
    private static function strings(array $values): array
    {
        $strings = [];
        foreach ($values as $value) {
            $value = self::str($value);
            if ($value !== '' && $value !== '<none>:<none>' && $value !== '<none>@<none>') {
                $strings[] = $value;
            }
        }

        return $strings;
    }

    /**
     * Nom court d'un nom d'hôte (« swarm-node-01.exemple.test » => « swarm-node-01 »).
     */
    public static function shortHostname(string $hostname): string
    {
        $dot = strpos($hostname, '.');

        return $dot === false ? $hostname : substr($hostname, 0, $dot);
    }

    /**
     * @return array<mixed>
     */
    private static function arr(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * @param list<string> $values
     */
    private static function join(array $values): string
    {
        $values = array_values(array_unique($values));
        sort($values);

        return implode(', ', $values);
    }
}

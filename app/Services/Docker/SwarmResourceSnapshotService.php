<?php

declare(strict_types=1);
/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */

namespace App\Services\Docker;

use App\Model\Cluster;
use App\Model\SwarmResourceSnapshot;
use GuzzleHttp\Client;
use Hyperf\Coroutine\Parallel;
use Hyperf\DbConnection\Db;

final class SwarmResourceSnapshotService
{
    public function __construct(private SwarmApiClient $docker) {}

    public function collectAll(): array
    {
        $result = ['clusters' => 0, 'resources' => 0, 'failed_clusters' => 0];
        $clusters = Cluster::where('orchestrator_type', Cluster::ORCHESTRATOR_DOCKER_SWARM)
            ->orderBy('id')->get();
        foreach ($clusters as $cluster) {
            try {
                $rows = $this->collectCluster($cluster);
                $this->persistCluster($cluster, $rows);
                ++$result['clusters'];
                $result['resources'] += count($rows);
            } catch (\Throwable) {
                ++$result['failed_clusters'];
            }
        }
        return $result;
    }

    public function collectCluster(Cluster $cluster): array
    {
        $inventory = $this->docker->withCluster(
            $cluster,
            function (Client $client, SwarmApiClient $api): array {
                return [
                    'services' => $api->request($client, 'GET', '/services'),
                    'tasks' => $api->request($client, 'GET', '/tasks'),
                    'nodes' => $api->request($client, 'GET', '/nodes'),
                ];
            },
            30
        );
        $nodeResults = new Parallel(8);
        foreach ((array) $inventory['nodes'] as $node) {
            $nodeId = (string) ($node['ID'] ?? '');
            if ($nodeId === '') {
                continue;
            }
            $nodeResults->add(
                fn (): array => $this->collectNode($cluster, $nodeId),
                $nodeId
            );
        }
        $nodeMetrics = $nodeResults->count() > 0 ? $nodeResults->wait(false) : [];
        $containersByService = [];
        foreach ($nodeMetrics as $nodeResult) {
            foreach ((array) ($nodeResult['containers'] ?? []) as $container) {
                $serviceId = (string) ($container['service_id'] ?? '');
                if ($serviceId !== '') {
                    $containersByService[$serviceId][] = $container;
                }
            }
        }

        $collectedAt = time();
        $tasksByService = [];
        foreach ((array) $inventory['tasks'] as $task) {
            $tasksByService[(string) ($task['ServiceID'] ?? '')][] = $task;
        }
        $rows = [];
        foreach ((array) $inventory['services'] as $service) {
            $serviceId = (string) ($service['ID'] ?? '');
            if ($serviceId === '') {
                continue;
            }
            $rows[] = $this->serviceRow(
                $cluster,
                $service,
                $tasksByService[$serviceId] ?? [],
                $containersByService[$serviceId] ?? [],
                $collectedAt
            );
        }
        foreach ((array) $inventory['nodes'] as $node) {
            $nodeId = (string) ($node['ID'] ?? '');
            if ($nodeId !== '') {
                $rows[] = $this->nodeRow(
                    $cluster,
                    $node,
                    (array) ($nodeMetrics[$nodeId] ?? []),
                    $collectedAt
                );
            }
        }
        return $rows;
    }

    private function collectNode(Cluster $cluster, string $nodeId): array
    {
        try {
            return $this->docker->withNode(
                $cluster,
                $nodeId,
                function (Client $client, SwarmApiClient $api): array {
                    $info = $api->request($client, 'GET', '/info');
                    $containers = array_values(array_filter(
                        $api->request($client, 'GET', '/containers/json', [
                            'query' => ['all' => 1],
                        ]),
                        static fn (array $container): bool => ($container['State'] ?? '') === 'running'
                    ));
                    $stats = new Parallel(8);
                    $containerMap = [];
                    foreach ($containers as $container) {
                        $containerId = (string) ($container['Id'] ?? '');
                        if ($containerId !== '') {
                            $containerMap[$containerId] = $container;
                            $stats->add(
                                fn (): array => $api->request(
                                    $client,
                                    'GET',
                                    '/containers/' . rawurlencode($containerId) . '/stats',
                                    ['query' => ['stream' => 'false', 'one-shot' => 'true']]
                                ),
                                $containerId
                            );
                        }
                    }
                    $metricRows = [];
                    foreach ($stats->count() > 0 ? $stats->wait(false) : [] as $containerId => $stat) {
                        $container = $containerMap[$containerId] ?? null;
                        if ($container === null || ! is_array($stat)) {
                            continue;
                        }
                        $metricRows[] = $this->containerMetric($container, $stat);
                    }
                    return [
                        'available' => true,
                        'memory_total' => (int) ($info['MemTotal'] ?? 0),
                        'containers' => $metricRows,
                    ];
                },
                30
            );
        } catch (\Throwable) {
            return ['available' => false, 'memory_total' => 0, 'containers' => []];
        }
    }

    private function containerMetric(array $container, array $stat): array
    {
        $memory = max(
            0,
            (int) ($stat['memory_stats']['usage'] ?? 0)
            - (int) ($stat['memory_stats']['stats']['inactive_file'] ?? 0)
        );
        $disk = $this->blkioBytes((array) ($stat['blkio_stats'] ?? []));
        return [
            'service_id' => (string) ($container['Labels']['com.docker.swarm.service.id'] ?? ''),
            'cpu_percent' => $this->cpuPercent($stat),
            'memory_usage' => $memory,
            'network_rx' => array_sum(array_column((array) ($stat['networks'] ?? []), 'rx_bytes')),
            'network_tx' => array_sum(array_column((array) ($stat['networks'] ?? []), 'tx_bytes')),
            'disk_read' => $disk['read'],
            'disk_write' => $disk['write'],
        ];
    }

    private function serviceRow(
        Cluster $cluster,
        array $service,
        array $tasks,
        array $containers,
        int $collectedAt
    ): array {
        $active = array_values(array_filter(
            $tasks,
            static fn (array $task): bool => ($task['DesiredState'] ?? '') === 'running'
        ));
        $running = count(array_filter(
            $active,
            static fn (array $task): bool => ($task['Status']['State'] ?? '') === 'running'
        ));
        $desired = isset($service['Spec']['Mode']['Replicated']['Replicas'])
            ? (int) $service['Spec']['Mode']['Replicated']['Replicas']
            : count($active);
        $limit = (int) ($service['Spec']['TaskTemplate']['Resources']['Limits']['MemoryBytes'] ?? 0);
        $metrics = $this->sumMetrics($containers);
        return $this->baseRow($cluster, SwarmResourceSnapshot::TYPE_SERVICE, [
            'resource_uid' => (string) ($service['ID'] ?? ''),
            'name' => (string) ($service['Spec']['Name'] ?? ''),
            'node_name' => '',
            'phase' => $desired === $running ? 'running' : ($running > 0 ? 'degraded' : 'stopped'),
            'desired_tasks' => $desired,
            'running_tasks' => $running,
            'container_count' => count($containers),
            'memory_limit' => $limit > 0 ? $limit * max($desired, 1) : 0,
            'metric_available' => count($containers) > 0,
        ] + $metrics, $collectedAt);
    }

    private function nodeRow(Cluster $cluster, array $node, array $nodeMetric, int $collectedAt): array
    {
        $metrics = $this->sumMetrics((array) ($nodeMetric['containers'] ?? []));
        $availability = (string) ($node['Spec']['Availability'] ?? '');
        $state = (string) ($node['Status']['State'] ?? '');
        return $this->baseRow($cluster, SwarmResourceSnapshot::TYPE_NODE, [
            'resource_uid' => (string) ($node['ID'] ?? ''),
            'name' => (string) ($node['Description']['Hostname'] ?? $node['ID'] ?? ''),
            'node_name' => (string) ($node['Description']['Hostname'] ?? ''),
            'phase' => $availability === 'active' ? $state : $availability,
            'desired_tasks' => 0,
            'running_tasks' => 0,
            'container_count' => count((array) ($nodeMetric['containers'] ?? [])),
            'memory_limit' => (int) ($nodeMetric['memory_total'] ?? 0),
            'metric_available' => (bool) ($nodeMetric['available'] ?? false),
        ] + $metrics, $collectedAt);
    }

    private function baseRow(Cluster $cluster, string $type, array $row, int $collectedAt): array
    {
        return $row + [
            'org_id' => (int) $cluster->org_id,
            'cluster_id' => (int) $cluster->id,
            'resource_type' => $type,
            'network_rx_bps' => 0,
            'network_tx_bps' => 0,
            'disk_read_bps' => 0,
            'disk_write_bps' => 0,
            'rate_available' => false,
            'collected_at' => $collectedAt,
        ];
    }

    private function sumMetrics(array $containers): array
    {
        $result = [
            'cpu_percent' => 0.0,
            'memory_usage' => 0,
            'network_rx' => 0,
            'network_tx' => 0,
            'disk_read' => 0,
            'disk_write' => 0,
        ];
        foreach ($containers as $container) {
            foreach (array_keys($result) as $field) {
                $result[$field] += $container[$field] ?? 0;
            }
        }
        $result['cpu_percent'] = round($result['cpu_percent'], 3);
        return $result;
    }

    private function persistCluster(Cluster $cluster, array $rows): void
    {
        $previous = SwarmResourceSnapshot::where('cluster_id', (int) $cluster->id)
            ->get()->keyBy(static fn (SwarmResourceSnapshot $row): string => $row->resource_type . ':' . $row->resource_uid);
        foreach ($rows as &$row) {
            $old = $previous->get($row['resource_type'] . ':' . $row['resource_uid']);
            $seconds = $old === null ? 0 : max(0, $row['collected_at'] - (int) $old->collected_at);
            $row['rate_available'] = $row['metric_available'] && $old !== null
                && (bool) $old->metric_available && $seconds > 0;
            foreach (['network_rx', 'network_tx', 'disk_read', 'disk_write'] as $field) {
                $row[$field . '_bps'] = $row['rate_available']
                    ? round(max(0, $row[$field] - (int) $old->{$field}) / $seconds, 2)
                    : 0;
            }
        }
        unset($row);
        $collectedAt = $rows === [] ? time() : (int) $rows[0]['collected_at'];
        Db::transaction(function () use ($cluster, $rows, $collectedAt): void {
            foreach (array_chunk($rows, 500) as $chunk) {
                Db::table('swarm_resource_snapshot')->upsert(
                    $chunk,
                    ['cluster_id', 'resource_type', 'resource_uid'],
                    [
                        'org_id', 'name', 'node_name', 'phase', 'desired_tasks',
                        'running_tasks', 'container_count', 'cpu_percent', 'memory_usage',
                        'memory_limit', 'network_rx', 'network_tx', 'disk_read', 'disk_write',
                        'network_rx_bps', 'network_tx_bps', 'disk_read_bps', 'disk_write_bps',
                        'metric_available', 'rate_available', 'collected_at',
                    ]
                );
            }
            Db::table('swarm_resource_snapshot')
                ->where('cluster_id', (int) $cluster->id)
                ->where('collected_at', '<', $collectedAt)
                ->delete();
        });
    }

    private function cpuPercent(array $stats): float
    {
        $cpuDelta = (int) ($stats['cpu_stats']['cpu_usage']['total_usage'] ?? 0)
            - (int) ($stats['precpu_stats']['cpu_usage']['total_usage'] ?? 0);
        $systemDelta = (int) ($stats['cpu_stats']['system_cpu_usage'] ?? 0)
            - (int) ($stats['precpu_stats']['system_cpu_usage'] ?? 0);
        $cpus = (int) ($stats['cpu_stats']['online_cpus']
            ?? count($stats['cpu_stats']['cpu_usage']['percpu_usage'] ?? []));
        return $cpuDelta > 0 && $systemDelta > 0 && $cpus > 0
            ? round($cpuDelta / $systemDelta * $cpus * 100, 3)
            : 0.0;
    }

    private function blkioBytes(array $blkioStats): array
    {
        $result = ['read' => 0, 'write' => 0];
        foreach ((array) ($blkioStats['io_service_bytes_recursive'] ?? []) as $entry) {
            $operation = strtolower((string) ($entry['op'] ?? ''));
            if (isset($result[$operation])) {
                $result[$operation] += (int) ($entry['value'] ?? 0);
            }
        }
        return $result;
    }
}

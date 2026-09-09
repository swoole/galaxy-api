<?php

namespace App\Services\Project\Monitoring;

use App\Model\Cluster;
use App\Model\ProjectRuntime;
use App\Services\Docker\SwarmApiClient;
use GuzzleHttp\Client;
use Hyperf\Coroutine\Parallel;

final class SwarmRuntimeMonitoringProvider implements ProjectRuntimeMonitoringProvider
{
    public function __construct(private SwarmApiClient $docker) {}

    public function orchestratorType(): string
    {
        return Cluster::ORCHESTRATOR_DOCKER_SWARM;
    }

    public function collect(Cluster $cluster, array $runtimes): array
    {
        $inventory = $this->docker->withCluster($cluster, function (Client $client, SwarmApiClient $api) use ($runtimes): array {
            $serviceRefs = array_values(array_filter(array_map(
                static fn (ProjectRuntime $runtime): string => (string) $runtime->runtime_ref,
                $runtimes
            )));
            $services = $api->request($client, 'GET', '/services');
            $tasks = $api->request($client, 'GET', '/tasks');
            $serviceMap = [];
            $serviceIds = [];
            foreach ($services as $service) {
                $id = (string) ($service['ID'] ?? '');
                $name = (string) ($service['Spec']['Name'] ?? '');
                if (in_array($id, $serviceRefs, true) || in_array($name, $serviceRefs, true)) {
                    $serviceMap[$id] = $service;
                    $serviceMap[$name] = $service;
                    $serviceIds[$id] = true;
                }
            }
            return ['services' => $serviceMap, 'service_ids' => $serviceIds, 'tasks' => $tasks];
        }, 30);

        $targetNodes = [];
        foreach ($inventory['tasks'] as $task) {
            $serviceId = (string) ($task['ServiceID'] ?? '');
            $nodeId = (string) ($task['NodeID'] ?? '');
            if (isset($inventory['service_ids'][$serviceId])
                && ($task['DesiredState'] ?? '') === 'running'
                && $nodeId !== '') {
                $targetNodes[$nodeId] = true;
            }
        }

        $nodes = new Parallel(8);
        foreach (array_keys($targetNodes) as $nodeId) {
            $nodes->add(function () use ($cluster, $nodeId, $inventory): array {
                try {
                    return $this->docker->withNode(
                        $cluster,
                        $nodeId,
                        function (Client $client, SwarmApiClient $api) use ($inventory): array {
                            $containers = $api->request($client, 'GET', '/containers/json', [
                                'query' => ['all' => 1],
                            ]);
                            $containers = array_values(array_filter(
                                $containers,
                                static fn (array $container): bool =>
                                    ($container['State'] ?? '') === 'running'
                                    && isset($inventory['service_ids'][
                                        (string) ($container['Labels']['com.docker.swarm.service.id'] ?? '')
                                    ])
                            ));
                            $parallel = new Parallel(8);
                            foreach ($containers as $container) {
                                $containerId = (string) ($container['Id'] ?? '');
                                if ($containerId === '') {
                                    continue;
                                }
                                $parallel->add(fn (): array => $api->request(
                                    $client,
                                    'GET',
                                    '/containers/' . rawurlencode($containerId) . '/stats',
                                    ['query' => ['stream' => 'false', 'one-shot' => 'true']]
                                ), $containerId);
                            }
                            return [
                                'containers' => $containers,
                                'stats' => $parallel->count() > 0 ? $parallel->wait(false) : [],
                            ];
                        },
                        20
                    );
                } catch (\Throwable) {
                    // Missing node metrics reduce coverage, but cluster-wide
                    // workload health remains available from the Manager.
                    return ['containers' => [], 'stats' => []];
                }
            }, $nodeId);
        }

        $containerMap = [];
        $stats = [];
        foreach ($nodes->count() > 0 ? $nodes->wait() : [] as $nodeResult) {
            foreach ((array) ($nodeResult['containers'] ?? []) as $container) {
                $serviceId = (string) ($container['Labels']['com.docker.swarm.service.id'] ?? '');
                if ($serviceId !== '') {
                    $containerMap[$serviceId][] = $container;
                }
            }
            $stats += (array) ($nodeResult['stats'] ?? []);
        }

        $result = [];
        foreach ($runtimes as $runtime) {
            $service = $inventory['services'][(string) $runtime->runtime_ref] ?? null;
            if ($service === null) {
                $result[(int) $runtime->id] = $this->emptySnapshot($runtime, 'Docker Service 不存在');
                continue;
            }
            $serviceId = (string) ($service['ID'] ?? '');
            $serviceTasks = array_values(array_filter(
                $inventory['tasks'],
                static fn (array $task): bool => ($task['ServiceID'] ?? '') === $serviceId
            ));
            $result[(int) $runtime->id] = $this->snapshot(
                $runtime,
                $service,
                $serviceTasks,
                $containerMap[$serviceId] ?? [],
                $stats
            );
        }
        return $result;
    }

    private function snapshot(
        ProjectRuntime $runtime,
        array $service,
        array $tasks,
        array $containers,
        array $stats
    ): array {
        $desired = (int) ($service['Spec']['Mode']['Replicated']['Replicas'] ?? 0);
        $active = array_values(array_filter(
            $tasks,
            static fn (array $task): bool => ($task['DesiredState'] ?? '') === 'running'
        ));
        $running = count(array_filter(
            $active,
            static fn (array $task): bool => ($task['Status']['State'] ?? '') === 'running'
        ));
        $failed = count(array_filter(
            $tasks,
            static fn (array $task): bool => in_array(
                $task['Status']['State'] ?? '',
                ['failed', 'rejected', 'orphaned'],
                true
            )
        ));
        $metrics = ['cpu_percent' => 0.0, 'memory_usage' => 0, 'memory_limit' => 0,
            'network_rx' => 0, 'network_tx' => 0, 'disk_read' => 0, 'disk_write' => 0, 'pids' => 0];
        $visibleContainers = 0;
        foreach ($containers as $container) {
            $stat = is_array($stats[$container['Id'] ?? ''] ?? null) ? $stats[$container['Id']] : [];
            $memory = max(
                0,
                (int) ($stat['memory_stats']['usage'] ?? 0)
                - (int) ($stat['memory_stats']['stats']['inactive_file'] ?? 0)
            );
            $diskIo = $this->blkioBytes($stat['blkio_stats'] ?? []);
            $row = [
                'cpu_percent' => $this->cpuPercent($stat),
                'memory_usage' => $memory,
                'memory_limit' => (int) ($stat['memory_stats']['limit'] ?? 0),
                'network_rx' => array_sum(array_column($stat['networks'] ?? [], 'rx_bytes')),
                'network_tx' => array_sum(array_column($stat['networks'] ?? [], 'tx_bytes')),
                'disk_read' => $diskIo['read'],
                'disk_write' => $diskIo['write'],
                'pids' => (int) ($stat['pids_stats']['current'] ?? 0),
            ];
            foreach (array_keys($metrics) as $key) {
                $metrics[$key] += $row[$key];
            }
            ++$visibleContainers;
        }
        $health = $desired === $running ? 'healthy' : ($running > 0 ? 'degraded' : 'unhealthy');
        return $this->base($runtime, (string) ($service['ID'] ?? ''), $metrics) + [
            'image' => (string) ($service['Spec']['TaskTemplate']['ContainerSpec']['Image'] ?? ''),
            'desired_tasks' => $desired,
            'running_tasks' => $running,
            'failed_tasks' => $failed,
            'local_containers' => $visibleContainers,
            'metric_coverage' => $running > 0 ? round($visibleContainers / $running * 100, 2) : 0,
            'disk_io_coverage' => $running > 0 ? round($visibleContainers / $running * 100, 2) : 0,
            'health' => $health,
            'update_state' => (string) ($service['UpdateStatus']['State'] ?? ''),
            'update_message' => (string) ($service['UpdateStatus']['Message'] ?? ''),
            'error' => '',
        ];
    }

    private function emptySnapshot(ProjectRuntime $runtime, string $error): array
    {
        return $this->base($runtime, (string) $runtime->runtime_ref, [
            'cpu_percent' => 0.0, 'memory_usage' => 0, 'memory_limit' => 0,
            'network_rx' => 0, 'network_tx' => 0, 'disk_read' => 0, 'disk_write' => 0, 'pids' => 0,
        ]) + [
            'image' => '', 'desired_tasks' => 0, 'running_tasks' => 0, 'failed_tasks' => 0,
            'local_containers' => 0, 'metric_coverage' => 0, 'disk_io_coverage' => 0, 'health' => 'unhealthy',
            'update_state' => '', 'update_message' => '', 'error' => $error,
        ];
    }

    private function base(ProjectRuntime $runtime, string $reference, array $metrics): array
    {
        return $metrics + [
            'org_id' => (int) $runtime->org_id, 'group_id' => (int) $runtime->group_id,
            'project_id' => (int) $runtime->project_id, 'runtime_id' => (int) $runtime->id,
            'name' => (string) $runtime->name, 'cluster_id' => (int) $runtime->cluster_id,
            'cluster' => $runtime->cluster, 'env_id' => (int) $runtime->env_id, 'env' => $runtime->env,
            'orchestrator_type' => (string) $runtime->orchestrator_type,
            'workload_kind' => (string) $runtime->workload_kind,
            'workload_name' => (string) $runtime->service_name,
            'workload_ref' => $reference, 'service_id' => $reference,
            'runtime_namespace' => (string) $runtime->runtime_namespace,
        ];
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
            ? round($cpuDelta / $systemDelta * $cpus * 100, 2)
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

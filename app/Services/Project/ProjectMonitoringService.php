<?php

namespace App\Services\Project;

use App\Exception\AppException;
use App\Model\Project;
use App\Model\ProjectRuntime;
use App\Model\ProjectRuntimeMetric;
use App\Model\Cluster;
use App\Services\Project\Monitoring\KubernetesRuntimeMonitoringProvider;
use App\Services\Project\Monitoring\ProjectRuntimeMonitoringProvider;
use App\Services\Project\Monitoring\SwarmRuntimeMonitoringProvider;
use Hyperf\DbConnection\Db;
use Hyperf\Redis\Redis;
use Throwable;

class ProjectMonitoringService
{
    public function __construct(
        private SwarmRuntimeMonitoringProvider $swarmProvider,
        private KubernetesRuntimeMonitoringProvider $kubernetesProvider,
        private ProjectMutationLock $projectMutationLock,
        private Redis $redis
    ) {}

    public function overview(
        int $orgId,
        int $groupId,
        int $projectId,
        int $hours = 24,
        ?int $runtimeId = null
    ): array
    {
        $hours = max(1, min(168, $hours));
        try {
            $cached = $this->redis->get($this->snapshotKey($orgId, $groupId, $projectId));
        } catch (Throwable) {
            $cached = null;
        }
        $rows = is_string($cached) && $cached !== '' ? json_decode($cached, true) : null;
        $cacheHit = is_array($rows);
        if (! $cacheHit) {
            $rows = $this->storedRows($orgId, $groupId, $projectId);
        }
        if ($runtimeId !== null) {
            $rows = array_values(array_filter(
                $rows,
                static fn (array $row): bool => (int) ($row['runtime_id'] ?? 0) === $runtimeId
            ));
            if ($rows === []) {
                throw new AppException(404, '所选运行实例不存在或不属于当前项目');
            }
        }

        $summary = [
            'runtime_count' => count($rows),
            'swarm_runtime_count' => count(array_filter(
                $rows,
                static fn (array $row): bool => ($row['orchestrator_type'] ?? '') === Cluster::ORCHESTRATOR_DOCKER_SWARM
            )),
            'kubernetes_runtime_count' => count(array_filter(
                $rows,
                static fn (array $row): bool => ($row['orchestrator_type'] ?? '') === Cluster::ORCHESTRATOR_KUBERNETES
            )),
            'healthy_count' => count(array_filter($rows, static fn (array $row): bool => $row['health'] === 'healthy')),
            'degraded_count' => count(array_filter($rows, static fn (array $row): bool => in_array($row['health'], ['degraded', 'unhealthy'], true))),
            'desired_tasks' => array_sum(array_column($rows, 'desired_tasks')),
            'running_tasks' => array_sum(array_column($rows, 'running_tasks')),
            'failed_tasks' => array_sum(array_column($rows, 'failed_tasks')),
            'cpu_percent' => round(array_sum(array_column($rows, 'cpu_percent')), 2),
            'memory_usage' => array_sum(array_column($rows, 'memory_usage')),
            'memory_limit' => array_sum(array_column($rows, 'memory_limit')),
            'network_rx' => array_sum(array_column($rows, 'network_rx')),
            'network_tx' => array_sum(array_column($rows, 'network_tx')),
            'disk_read' => array_sum(array_column($rows, 'disk_read')),
            'disk_write' => array_sum(array_column($rows, 'disk_write')),
            'disk_io_runtime_count' => count(array_filter(
                $rows,
                static fn (array $row): bool => (float) ($row['disk_io_coverage'] ?? 0) > 0
            )),
            'pids' => array_sum(array_column($rows, 'pids')),
        ];

        return [
            'summary' => $summary,
            'runtimes' => $rows,
            'trend' => $this->trend($orgId, $groupId, $projectId, $hours, $runtimeId),
            'selected_runtime_id' => $runtimeId,
            'collected_at' => time(),
            'snapshot_source' => $cacheHit ? 'observer-cache' : 'database',
            'metric_note' => '副本健康状态由对应编排 Provider 采集；Swarm CPU、内存、网络和磁盘 I/O 来自节点 Agent（Docker blkio），Kubernetes CPU、内存来自 Metrics API，磁盘 I/O 需另行接入 cAdvisor/Prometheus。',
        ];
    }

    /** Collect and persist all project runtimes without requiring a page request. */
    public function collectAll(): array
    {
        $runtimes = ProjectRuntime::with('env')->with('cluster')->orderBy('id')->get();
        $rows = [];
        foreach ($runtimes->groupBy('cluster_id') as $clusterId => $clusterRuntimes) {
            $cluster = Cluster::where('id', (int) $clusterId)->first();
            if ($cluster === null) {
                continue;
            }
            $provider = $this->provider((string) $cluster->orchestrator_type);
            try {
                $snapshots = $provider->collect($cluster, $clusterRuntimes->all());
                foreach ($clusterRuntimes as $runtime) {
                    $snapshot = $snapshots[(int) $runtime->id] ?? $this->emptySnapshot($runtime);
                    $this->persist($runtime, $snapshot);
                    $rows[] = $snapshot;
                }
            } catch (Throwable $e) {
                foreach ($clusterRuntimes as $runtime) {
                    $rows[] = $this->failedRuntime($runtime, $e->getMessage());
                }
            }
        }
        $this->publishSnapshots($rows);
        return $rows;
    }

    private function provider(string $orchestratorType): ProjectRuntimeMonitoringProvider
    {
        return match ($orchestratorType) {
            Cluster::ORCHESTRATOR_DOCKER_SWARM => $this->swarmProvider,
            Cluster::ORCHESTRATOR_KUBERNETES => $this->kubernetesProvider,
            default => throw new AppException(422, '不支持的运行时监控 Provider：' . $orchestratorType),
        };
    }

    private function publishSnapshots(array $rows): void
    {
        $groups = [];
        foreach ($rows as $row) {
            $key = sprintf('%d:%d:%d', (int) $row['org_id'], (int) $row['group_id'], (int) $row['project_id']);
            $groups[$key][] = $row;
        }
        $ttl = max(180, (int) config('project-monitoring.interval', 60) * 4);
        foreach ($groups as $group) {
            $first = $group[0];
            try {
                $this->redis->setex(
                    $this->snapshotKey((int) $first['org_id'], (int) $first['group_id'], (int) $first['project_id']),
                    $ttl,
                    json_encode($group, JSON_THROW_ON_ERROR)
                );
            } catch (Throwable) {
                // Metrics are already persisted in MySQL; Redis is only the rich, short-lived page snapshot.
            }
        }
    }

    private function storedRows(int $orgId, int $groupId, int $projectId): array
    {
        $runtimes = ProjectRuntime::where('org_id', $orgId)->where('group_id', $groupId)
            ->where('project_id', $projectId)->with('env')->with('cluster')->orderBy('id')->get();
        if ($runtimes->isEmpty()) {
            return [];
        }
        $metrics = ProjectRuntimeMetric::whereIn('runtime_id', $runtimes->pluck('id')->all())
            ->orderByDesc('collected_at')->get()->groupBy('runtime_id');
        $rows = [];
        foreach ($runtimes as $runtime) {
            $row = $this->emptySnapshot($runtime, (string) ($runtime->last_error ?? ''));
            $metric = $metrics->get((int) $runtime->id)?->first();
            if ($metric !== null) {
                foreach (['cpu_percent', 'memory_usage', 'memory_limit', 'network_rx', 'network_tx', 'pids',
                    'disk_read', 'disk_write', 'disk_io_coverage', 'desired_tasks', 'running_tasks',
                    'failed_tasks', 'local_containers', 'metric_coverage'] as $field) {
                    $row[$field] = $metric->{$field};
                }
                $row['collected_at'] = (int) $metric->collected_at;
            } else {
                $row['desired_tasks'] = (int) $runtime->desired_count;
                $row['running_tasks'] = (int) $runtime->running_count;
                $row['collected_at'] = (int) $runtime->last_synced_at;
            }
            $row['health'] = (string) ($runtime->health ?: 'unknown');
            $row['snapshot_stale'] = $row['collected_at'] <= 0 || $row['collected_at'] < time() - 300;
            $rows[] = $row;
        }
        return $rows;
    }

    private function snapshotKey(int $orgId, int $groupId, int $projectId): string
    {
        return sprintf('cg:project-monitor:snapshot:%d:%d:%d', $orgId, $groupId, $projectId);
    }

    private function persist(ProjectRuntime $runtime, array $snapshot): void
    {
        try {
            $this->projectMutationLock->synchronized(
                (int) $runtime->org_id,
                (int) $runtime->project_id,
                function () use ($runtime, $snapshot): void {
                    if (! Project::where('id', (int) $runtime->project_id)->where('org_id', (int) $runtime->org_id)
                        ->where('group_id', (int) $runtime->group_id)->exists()
                        || ! ProjectRuntime::where('id', (int) $runtime->id)->exists()) {
                        return;
                    }
                    $this->persistUnlocked($runtime, $snapshot);
                },
                0,
                120
            );
        } catch (AppException $e) {
            if ($e->getCode() !== 409) {
                throw $e;
            }
            // A foreground mutation owns the app; the next observer cycle resamples it.
        }
    }

    private function persistUnlocked(ProjectRuntime $runtime, array $snapshot): void
    {
        $runtime->desired_count = $snapshot['desired_tasks'];
        $runtime->running_count = $snapshot['running_tasks'];
        $runtime->status = in_array($snapshot['update_state'], ['updating', 'paused', 'rollback_started', 'rollback_paused'], true)
            ? $snapshot['update_state']
            : ($snapshot['health'] === 'healthy'
                ? ((int) $snapshot['desired_tasks'] === 0 ? 'stopped' : 'running')
                : 'degraded');
        $runtime->health = $snapshot['health'];
        $runtime->last_error = $snapshot['error'];
        $runtime->last_synced_at = time();
        $runtime->updated_at = time();
        $runtime->save();
        $minute = intdiv(time(), 60) * 60;
        ProjectRuntimeMetric::updateOrCreate(['runtime_id' => (int) $runtime->id, 'collected_at' => $minute], [
            'org_id' => (int) $runtime->org_id, 'group_id' => (int) $runtime->group_id,
            'project_id' => (int) $runtime->project_id, 'cluster_id' => (int) $runtime->cluster_id,
            'cpu_percent' => $snapshot['cpu_percent'], 'memory_usage' => $snapshot['memory_usage'],
            'memory_limit' => $snapshot['memory_limit'], 'network_rx' => $snapshot['network_rx'],
            'network_tx' => $snapshot['network_tx'], 'disk_read' => $snapshot['disk_read'],
            'disk_write' => $snapshot['disk_write'], 'disk_io_coverage' => $snapshot['disk_io_coverage'],
            'pids' => $snapshot['pids'],
            'desired_tasks' => $snapshot['desired_tasks'], 'running_tasks' => $snapshot['running_tasks'],
            'failed_tasks' => $snapshot['failed_tasks'], 'local_containers' => $snapshot['local_containers'],
            'metric_coverage' => $snapshot['metric_coverage'],
        ]);
    }

    private function trend(
        int $orgId,
        int $groupId,
        int $projectId,
        int $hours,
        ?int $runtimeId = null
    ): array
    {
        $query = ProjectRuntimeMetric::where('org_id', $orgId)->where('group_id', $groupId)
            ->where('project_id', $projectId)->where('collected_at', '>=', time() - $hours * 3600);
        if ($runtimeId !== null) {
            $query->where('runtime_id', $runtimeId);
        }
        return $query
            ->select('collected_at', Db::raw('SUM(cpu_percent) AS cpu_percent'), Db::raw('SUM(memory_usage) AS memory_usage'),
                Db::raw('SUM(network_rx) AS network_rx'), Db::raw('SUM(network_tx) AS network_tx'),
                Db::raw('SUM(disk_read) AS disk_read'), Db::raw('SUM(disk_write) AS disk_write'),
                Db::raw('SUM(running_tasks) AS running_tasks'), Db::raw('SUM(desired_tasks) AS desired_tasks'))
            ->groupBy('collected_at')->orderBy('collected_at')->get()->toArray();
    }

    private function failedRuntime(ProjectRuntime $runtime, string $error): array
    {
        try {
            $this->projectMutationLock->synchronized(
                (int) $runtime->org_id,
                (int) $runtime->project_id,
                function () use ($runtime, $error): void {
                    if (! Project::where('id', (int) $runtime->project_id)->where('org_id', (int) $runtime->org_id)
                        ->where('group_id', (int) $runtime->group_id)->exists()
                        || ! ProjectRuntime::where('id', (int) $runtime->id)->exists()) {
                        return;
                    }
                    $runtime->last_error = mb_substr($error, 0, 2000);
                    $runtime->health = 'unknown';
                    $runtime->last_synced_at = time();
                    $runtime->save();
                },
                0,
                120
            );
        } catch (AppException $e) {
            if ($e->getCode() !== 409) {
                throw $e;
            }
        }
        return $this->emptySnapshot($runtime, $error);
    }

    private function emptySnapshot(ProjectRuntime $runtime, string $error = ''): array
    {
        return [
            'org_id' => (int) $runtime->org_id, 'group_id' => (int) $runtime->group_id,
            'project_id' => (int) $runtime->project_id,
            'runtime_id' => (int) $runtime->id, 'name' => (string) $runtime->name,
            'cluster_id' => (int) $runtime->cluster_id, 'cluster' => $runtime->cluster,
            'env_id' => (int) $runtime->env_id, 'env' => $runtime->env,
            'orchestrator_type' => (string) $runtime->orchestrator_type,
            'workload_kind' => (string) $runtime->workload_kind,
            'workload_name' => (string) $runtime->service_name,
            'workload_ref' => (string) $runtime->runtime_ref,
            'service_id' => (string) $runtime->runtime_ref,
            'runtime_namespace' => (string) $runtime->runtime_namespace,
            'image' => '',
            'desired_tasks' => 0, 'running_tasks' => 0, 'failed_tasks' => 0,
            'local_containers' => 0, 'metric_coverage' => 0, 'cpu_percent' => 0,
            'memory_usage' => 0, 'memory_limit' => 0, 'network_rx' => 0, 'network_tx' => 0, 'pids' => 0,
            'disk_read' => 0, 'disk_write' => 0, 'disk_io_coverage' => 0,
            'health' => $error === '' ? 'unknown' : 'unhealthy', 'update_state' => '', 'update_message' => '',
            'error' => $error,
        ];
    }

}

<?php

declare(strict_types=1);
/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */

namespace App\Services;

use App\Model\Cluster;
use App\Model\KubernetesResourceSnapshot;
use App\Model\ProjectRuntime;
use App\Model\ProjectRuntimeMetric;
use App\Model\SwarmResourceSnapshot;
use Hyperf\Collection\Collection;
use Hyperf\Database\Schema\Schema;

final class ResourceUsageRankingService
{
    private const METRICS = ['cpu', 'memory', 'network', 'disk'];

    private const SCOPES = ['project', 'runtime', 'pod', 'workload', 'namespace', 'service', 'node'];

    private const INFRASTRUCTURE_SCOPES = ['pod', 'workload', 'namespace', 'service', 'node'];

    public function ranking(
        int $orgId,
        string $metric = 'cpu',
        string $scope = 'project',
        ?int $clusterId = null,
        ?string $orchestratorType = null,
        int $limit = 50
    ): array {
        $metric = in_array($metric, self::METRICS, true) ? $metric : 'cpu';
        $scope = in_array($scope, self::SCOPES, true) ? $scope : 'project';
        $limit = max(1, min(100, $limit));

        if (in_array($scope, self::INFRASTRUCTURE_SCOPES, true)) {
            $resolvedOrchestrator = $this->resolveInfrastructureOrchestrator(
                $orgId,
                $scope,
                $clusterId,
                $orchestratorType
            );
            if ($resolvedOrchestrator === Cluster::ORCHESTRATOR_DOCKER_SWARM) {
                return $this->swarmRanking($orgId, $metric, $scope, $clusterId, $limit);
            }
            return $this->kubernetesRanking(
                $orgId,
                $metric,
                $scope,
                $clusterId,
                $resolvedOrchestrator,
                $limit
            );
        }

        $query = ProjectRuntime::where('org_id', $orgId)
            ->with('project')->with('group')->with('env')->with('cluster')
            ->orderBy('id');
        if ($clusterId !== null) {
            $query->where('cluster_id', $clusterId);
        }
        if ($orchestratorType !== null && in_array($orchestratorType, Cluster::knownOrchestrators(), true)) {
            $query->where('orchestrator_type', $orchestratorType);
        }
        $runtimes = $query->get();
        $runtimeIds = $runtimes->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $metricGroups = [];
        if ($runtimeIds !== []) {
            // One hour bounds the query while still tolerating a delayed observer.
            $metricGroups = ProjectRuntimeMetric::whereIn('runtime_id', $runtimeIds)
                ->where('collected_at', '>=', time() - 3600)
                ->orderBy('runtime_id')->orderByDesc('collected_at')
                ->get()->groupBy('runtime_id');
        }

        $runtimeRows = [];
        foreach ($runtimes as $runtime) {
            $samples = $metricGroups[$runtime->id] ?? null;
            $runtimeRows[] = $this->runtimeRow(
                $runtime,
                $samples?->get(0),
                $samples?->get(1)
            );
        }

        $rows = $scope === 'project'
            ? $this->projectRows($runtimeRows)
            : $runtimeRows;
        foreach ($rows as &$row) {
            $row['value'] = $this->metricValue($row, $metric);
            $row['available'] = $this->metricAvailable($row, $metric);
        }
        unset($row);
        usort($rows, static function (array $left, array $right): int {
            if ($left['available'] !== $right['available']) {
                return $left['available'] ? -1 : 1;
            }
            return $right['value'] <=> $left['value'];
        });
        $rows = array_slice($rows, 0, $limit);

        $clusterQuery = Cluster::where('org_id', $orgId)->orderBy('id');
        if ($orchestratorType !== null && in_array($orchestratorType, Cluster::knownOrchestrators(), true)) {
            $clusterQuery->where('orchestrator_type', $orchestratorType);
        }
        $clusters = $clusterQuery->get(['id', 'title', 'orchestrator_type']);

        return [
            'metric' => $metric,
            'scope' => $scope,
            'unit' => $this->unit($metric),
            'rows' => $rows,
            'clusters' => $this->clusterRows($clusters),
            'collected_at' => time(),
            'note' => 'CPU、内存使用最新监控快照；网络和磁盘 I/O 使用最近两次快照计算每秒速率。Kubernetes 基础版暂不提供网络和磁盘 I/O。',
        ];
    }

    private function resolveInfrastructureOrchestrator(
        int $orgId,
        string $scope,
        ?int $clusterId,
        ?string $orchestratorType
    ): ?string {
        if ($orchestratorType !== null) {
            return $orchestratorType;
        }
        if ($clusterId !== null) {
            $value = Cluster::where('org_id', $orgId)->where('id', $clusterId)->value('orchestrator_type');
            return is_string($value) ? $value : null;
        }
        return $scope === 'service'
            ? Cluster::ORCHESTRATOR_DOCKER_SWARM
            : (in_array($scope, ['pod', 'workload', 'namespace'], true)
                ? Cluster::ORCHESTRATOR_KUBERNETES
                : null);
    }

    private function swarmRanking(
        int $orgId,
        string $metric,
        string $scope,
        ?int $clusterId,
        int $limit
    ): array {
        $clusterQuery = Cluster::where('org_id', $orgId)
            ->where('orchestrator_type', Cluster::ORCHESTRATOR_DOCKER_SWARM)
            ->orderBy('id');
        $clusters = $clusterQuery->get(['id', 'title', 'orchestrator_type']);
        if (! in_array($scope, ['service', 'node'], true)) {
            return $this->emptyInfrastructureRanking(
                $metric,
                $scope,
                $clusters,
                'Docker Swarm 仅支持 Service 和 Node 基础设施排行。'
            );
        }
        if (! Schema::hasTable('swarm_resource_snapshot')) {
            return $this->emptyInfrastructureRanking(
                $metric,
                $scope,
                $clusters,
                'Swarm 资源快照表尚未初始化，请执行数据库迁移 0136。'
            );
        }
        $clusterMap = $clusters->keyBy('id');
        $clusterIds = $clusterId === null
            ? $clusters->pluck('id')->map(static fn ($id): int => (int) $id)->all()
            : [$clusterId];
        $resourceType = $scope === 'service'
            ? SwarmResourceSnapshot::TYPE_SERVICE
            : SwarmResourceSnapshot::TYPE_NODE;
        $snapshots = $clusterIds === []
            ? new Collection()
            : SwarmResourceSnapshot::where('org_id', $orgId)
                ->whereIn('cluster_id', $clusterIds)
                ->where('resource_type', $resourceType)
                ->orderBy('cluster_id')->orderBy('id')->get();
        $rows = $snapshots->map(function (SwarmResourceSnapshot $snapshot) use ($clusterMap, $scope): array {
            $cluster = $clusterMap->get((int) $snapshot->cluster_id);
            $fresh = (int) $snapshot->collected_at >= time() - 300;
            $covered = $fresh && (bool) $snapshot->metric_available;
            $rateCovered = $covered && (bool) $snapshot->rate_available;
            return [
                'id' => (string) $snapshot->resource_uid,
                'resource_type' => $scope,
                'title' => (string) $snapshot->name,
                'cluster_id' => (int) $snapshot->cluster_id,
                'cluster_title' => (string) ($cluster->title ?? ('集群 #' . $snapshot->cluster_id)),
                'orchestrator_type' => Cluster::ORCHESTRATOR_DOCKER_SWARM,
                'namespace' => '',
                'node_name' => (string) $snapshot->node_name,
                'workload_kind' => $scope,
                'workload_name' => (string) $snapshot->name,
                'phase' => (string) $snapshot->phase,
                'resource_count' => 1,
                'service_count' => $scope === 'service' ? 1 : 0,
                'node_count' => $scope === 'node' ? 1 : 0,
                'desired_tasks' => (int) $snapshot->desired_tasks,
                'running_tasks' => (int) $snapshot->running_tasks,
                'container_count' => (int) $snapshot->container_count,
                'covered_resource_count' => $covered ? 1 : 0,
                'cpu_percent' => $covered ? round((float) $snapshot->cpu_percent, 3) : 0.0,
                'memory_usage' => $covered ? (int) $snapshot->memory_usage : 0,
                'memory_limit' => (int) $snapshot->memory_limit,
                'network_rx_bps' => $rateCovered ? (float) $snapshot->network_rx_bps : 0.0,
                'network_tx_bps' => $rateCovered ? (float) $snapshot->network_tx_bps : 0.0,
                'disk_read_bps' => $rateCovered ? (float) $snapshot->disk_read_bps : 0.0,
                'disk_write_bps' => $rateCovered ? (float) $snapshot->disk_write_bps : 0.0,
                'cpu_available' => $covered,
                'memory_available' => $covered,
                'network_available' => $rateCovered,
                'disk_available' => $rateCovered,
                'collected_at' => (int) $snapshot->collected_at,
                'stale' => ! $fresh,
            ];
        })->all();
        foreach ($rows as &$row) {
            $row['value'] = $this->metricValue($row, $metric);
            $row['available'] = $this->metricAvailable($row, $metric);
        }
        unset($row);
        $this->sortRows($rows);
        return [
            'metric' => $metric,
            'scope' => $scope,
            'unit' => $this->unit($metric),
            'rows' => array_slice($rows, 0, $limit),
            'clusters' => $this->clusterRows($clusters),
            'collected_at' => time(),
            'note' => $scope === 'node'
                ? 'Swarm Node 指标为节点上全部运行容器的合计；网络和磁盘 I/O 使用连续快照计算每秒速率。'
                : 'Swarm Service 指标覆盖集群中的全部 Service；网络和磁盘 I/O 使用连续快照计算每秒速率。',
        ];
    }

    private function kubernetesRanking(
        int $orgId,
        string $metric,
        string $scope,
        ?int $clusterId,
        ?string $orchestratorType,
        int $limit
    ): array {
        $clusterQuery = Cluster::where('org_id', $orgId)
            ->where('orchestrator_type', Cluster::ORCHESTRATOR_KUBERNETES)
            ->orderBy('id');
        if ($orchestratorType !== null && $orchestratorType !== Cluster::ORCHESTRATOR_KUBERNETES) {
            $clusters = new Collection();
        } else {
            $clusters = $clusterQuery->get(['id', 'title', 'orchestrator_type']);
        }
        $clusterMap = $clusters->keyBy('id');
        $clusterIds = $clusterId === null
            ? $clusters->pluck('id')->map(static fn ($id): int => (int) $id)->all()
            : [$clusterId];
        if (! Schema::hasTable('kubernetes_resource_snapshot')) {
            return [
                'metric' => $metric,
                'scope' => $scope,
                'unit' => $this->unit($metric),
                'rows' => [],
                'clusters' => $clusters->map(static fn (Cluster $cluster): array => [
                    'id' => (int) $cluster->id,
                    'title' => (string) $cluster->title,
                    'orchestrator_type' => (string) $cluster->orchestrator_type,
                ])->values()->all(),
                'collected_at' => time(),
                'note' => 'Kubernetes 资源快照表尚未初始化，请执行数据库迁移 0135。',
            ];
        }
        $snapshots = $clusterIds === []
            ? new Collection()
            : KubernetesResourceSnapshot::where('org_id', $orgId)
                ->whereIn('cluster_id', $clusterIds)
                ->orderBy('cluster_id')->orderBy('id')->get();
        $pods = $snapshots->where('resource_type', KubernetesResourceSnapshot::TYPE_POD)->values()->all();
        $nodes = $snapshots->where('resource_type', KubernetesResourceSnapshot::TYPE_NODE)->values()->all();

        $rows = match ($scope) {
            'workload' => $this->aggregateKubernetesRows($pods, $clusterMap, 'workload'),
            'namespace' => $this->aggregateKubernetesRows($pods, $clusterMap, 'namespace'),
            'node' => $this->kubernetesNodeRows($nodes, $pods, $clusterMap),
            default => array_map(
                fn (KubernetesResourceSnapshot $pod): array => $this->kubernetesPodRow($pod, $clusterMap),
                $pods
            ),
        };
        foreach ($rows as &$row) {
            $row['value'] = $this->metricValue($row, $metric);
            $row['available'] = $this->metricAvailable($row, $metric);
        }
        unset($row);
        usort($rows, static function (array $left, array $right): int {
            if ($left['available'] !== $right['available']) {
                return $left['available'] ? -1 : 1;
            }
            return $right['value'] <=> $left['value'];
        });

        return [
            'metric' => $metric,
            'scope' => $scope,
            'unit' => $this->unit($metric),
            'rows' => array_slice($rows, 0, $limit),
            'clusters' => $this->clusterRows($clusters),
            'collected_at' => time(),
            'note' => in_array($metric, ['cpu', 'memory'], true)
                ? 'Kubernetes Metrics API 最新快照；包含平台外工作负载及系统命名空间。CPU 以单核 100% 计。'
                : 'Kubernetes Metrics API 不提供网络和磁盘 I/O；这两个维度需接入 Prometheus/cAdvisor 后才能排行。',
        ];
    }

    private function kubernetesPodRow(KubernetesResourceSnapshot $pod, $clusterMap): array
    {
        $cluster = $clusterMap->get((int) $pod->cluster_id);
        $fresh = (int) $pod->collected_at >= time() - 300;
        $covered = $fresh && (bool) $pod->metric_available;
        return [
            'id' => (string) $pod->resource_uid,
            'resource_type' => 'pod',
            'title' => (string) $pod->name,
            'cluster_id' => (int) $pod->cluster_id,
            'cluster_title' => (string) ($cluster->title ?? ('集群 #' . $pod->cluster_id)),
            'orchestrator_type' => Cluster::ORCHESTRATOR_KUBERNETES,
            'namespace' => (string) $pod->namespace,
            'node_name' => (string) $pod->node_name,
            'workload_kind' => (string) $pod->workload_kind,
            'workload_name' => (string) $pod->workload_name,
            'phase' => (string) $pod->phase,
            'resource_count' => 1,
            'pod_count' => 1,
            'node_count' => $pod->node_name === '' ? 0 : 1,
            'workload_count' => 1,
            'covered_resource_count' => $covered ? 1 : 0,
            'cpu_percent' => $covered ? round((float) $pod->cpu_percent, 3) : 0.0,
            'memory_usage' => $covered ? (int) $pod->memory_usage : 0,
            'memory_limit' => (int) $pod->memory_limit,
            'network_rx_bps' => 0.0,
            'network_tx_bps' => 0.0,
            'disk_read_bps' => 0.0,
            'disk_write_bps' => 0.0,
            'cpu_available' => $covered,
            'memory_available' => $covered,
            'network_available' => false,
            'disk_available' => false,
            'collected_at' => (int) $pod->collected_at,
            'stale' => ! $fresh,
        ];
    }

    private function emptyInfrastructureRanking(
        string $metric,
        string $scope,
        $clusters,
        string $note
    ): array {
        return [
            'metric' => $metric,
            'scope' => $scope,
            'unit' => $this->unit($metric),
            'rows' => [],
            'clusters' => $this->clusterRows($clusters),
            'collected_at' => time(),
            'note' => $note,
        ];
    }

    private function clusterRows($clusters): array
    {
        return $clusters->map(static fn (Cluster $cluster): array => [
            'id' => (int) $cluster->id,
            'title' => (string) $cluster->title,
            'orchestrator_type' => (string) $cluster->orchestrator_type,
        ])->values()->all();
    }

    private function sortRows(array &$rows): void
    {
        usort($rows, static function (array $left, array $right): int {
            if ($left['available'] !== $right['available']) {
                return $left['available'] ? -1 : 1;
            }
            return $right['value'] <=> $left['value'];
        });
    }

    private function aggregateKubernetesRows(array $pods, $clusterMap, string $scope): array
    {
        $groups = [];
        foreach ($pods as $pod) {
            $row = $this->kubernetesPodRow($pod, $clusterMap);
            $key = $scope === 'namespace'
                ? sprintf('%d:%s', $row['cluster_id'], $row['namespace'])
                : sprintf(
                    '%d:%s:%s:%s',
                    $row['cluster_id'],
                    $row['namespace'],
                    $row['workload_kind'],
                    $row['workload_name']
                );
            if (! isset($groups[$key])) {
                $groups[$key] = $row + [
                    'nodes' => [],
                    'workloads' => [],
                ];
                $groups[$key]['id'] = $key;
                $groups[$key]['resource_type'] = $scope;
                $groups[$key]['title'] = $scope === 'namespace'
                    ? ($row['namespace'] ?: 'default')
                    : ($row['workload_name'] ?: $row['title']);
                $groups[$key]['resource_count'] = 0;
                $groups[$key]['pod_count'] = 0;
                $groups[$key]['node_count'] = 0;
                $groups[$key]['workload_count'] = 0;
                $groups[$key]['covered_resource_count'] = 0;
                $groups[$key]['cpu_percent'] = 0.0;
                $groups[$key]['memory_usage'] = 0;
                $groups[$key]['memory_limit'] = 0;
                $groups[$key]['collected_at'] = 0;
                $groups[$key]['stale'] = false;
            }
            $group = &$groups[$key];
            ++$group['resource_count'];
            ++$group['pod_count'];
            $group['covered_resource_count'] += $row['covered_resource_count'];
            $group['cpu_percent'] += $row['cpu_percent'];
            $group['memory_usage'] += $row['memory_usage'];
            $group['memory_limit'] += $row['memory_limit'];
            $group['collected_at'] = max($group['collected_at'], $row['collected_at']);
            $group['stale'] = $group['stale'] || $row['stale'];
            if ($row['node_name'] !== '') {
                $group['nodes'][$row['node_name']] = $row['node_name'];
            }
            $workloadKey = $row['workload_kind'] . ':' . $row['workload_name'];
            $group['workloads'][$workloadKey] = $workloadKey;
            unset($group);
        }
        foreach ($groups as &$group) {
            $group['nodes'] = array_values($group['nodes']);
            $group['node_count'] = count($group['nodes']);
            $group['workload_count'] = count($group['workloads']);
            unset($group['workloads']);
            $group['cpu_percent'] = round($group['cpu_percent'], 3);
            $group['cpu_available'] = $group['covered_resource_count'] > 0;
            $group['memory_available'] = $group['covered_resource_count'] > 0;
        }
        unset($group);
        return array_values($groups);
    }

    private function kubernetesNodeRows(array $nodes, array $pods, $clusterMap): array
    {
        $podCounts = [];
        foreach ($pods as $pod) {
            $key = (int) $pod->cluster_id . ':' . (string) $pod->node_name;
            $podCounts[$key] = ($podCounts[$key] ?? 0) + 1;
        }
        $rows = [];
        foreach ($nodes as $node) {
            $row = $this->kubernetesPodRow($node, $clusterMap);
            $key = (int) $node->cluster_id . ':' . (string) $node->name;
            $row['resource_type'] = 'node';
            $row['title'] = (string) $node->name;
            $row['namespace'] = '';
            $row['node_name'] = (string) $node->name;
            $row['workload_kind'] = '';
            $row['workload_name'] = '';
            $row['pod_count'] = $podCounts[$key] ?? 0;
            $row['node_count'] = 1;
            $row['workload_count'] = 0;
            $rows[] = $row;
        }
        return $rows;
    }

    private function runtimeRow(ProjectRuntime $runtime, ?ProjectRuntimeMetric $latest, ?ProjectRuntimeMetric $previous): array
    {
        $seconds = $latest !== null && $previous !== null
            ? max(0, (int) $latest->collected_at - (int) $previous->collected_at)
            : 0;
        $isSwarm = $runtime->orchestrator_type === Cluster::ORCHESTRATOR_DOCKER_SWARM;
        $stale = $latest === null || (int) $latest->collected_at < time() - 300;
        $metricCovered = ! $stale && (float) $latest->metric_coverage > 0;
        $diskCovered = ! $stale && (float) $latest->disk_io_coverage > 0;
        $rate = static function (string $field) use ($latest, $previous, $seconds): float {
            if ($latest === null || $previous === null || $seconds <= 0) {
                return 0.0;
            }
            return round(max(0, (int) $latest->{$field} - (int) $previous->{$field}) / $seconds, 2);
        };

        return [
            'id' => (int) $runtime->id,
            'project_id' => (int) $runtime->project_id,
            'project_title' => (string) ($runtime->project->title ?? ('项目 #' . $runtime->project_id)),
            'project_alias' => (string) ($runtime->project->alias ?? ''),
            'group_id' => (int) $runtime->group_id,
            'group_title' => (string) ($runtime->group->title ?? ('项目组 #' . $runtime->group_id)),
            'group_alias' => (string) ($runtime->group->alias ?? ''),
            'title' => (string) ($runtime->name ?: ('实例 #' . $runtime->id)),
            'cluster_id' => (int) $runtime->cluster_id,
            'cluster_title' => (string) ($runtime->cluster->title ?? ('集群 #' . $runtime->cluster_id)),
            'orchestrator_type' => (string) $runtime->orchestrator_type,
            'workload_kind' => (string) $runtime->workload_kind,
            'workload_name' => (string) $runtime->service_name,
            'runtime_namespace' => (string) $runtime->runtime_namespace,
            'runtime_count' => 1,
            'cpu_percent' => round((float) ($latest->cpu_percent ?? 0), 2),
            'memory_usage' => (int) ($latest->memory_usage ?? 0),
            'memory_limit' => (int) ($latest->memory_limit ?? 0),
            'network_rx_bps' => $rate('network_rx'),
            'network_tx_bps' => $rate('network_tx'),
            'disk_read_bps' => $rate('disk_read'),
            'disk_write_bps' => $rate('disk_write'),
            'cpu_available' => $metricCovered,
            'memory_available' => $metricCovered,
            'network_available' => $isSwarm && $metricCovered && $seconds > 0,
            'disk_available' => $isSwarm && $diskCovered && $seconds > 0,
            'metric_coverage' => (float) ($latest->metric_coverage ?? 0),
            'disk_io_coverage' => (float) ($latest->disk_io_coverage ?? 0),
            'covered_runtime_count' => $metricCovered ? 1 : 0,
            'disk_covered_runtime_count' => $diskCovered ? 1 : 0,
            'collected_at' => (int) ($latest->collected_at ?? 0),
            'stale' => $stale,
        ];
    }

    private function projectRows(array $runtimeRows): array
    {
        $projects = [];
        foreach ($runtimeRows as $runtime) {
            $id = $runtime['project_id'];
            if (! isset($projects[$id])) {
                $projects[$id] = [
                    'id' => $id,
                    'project_id' => $id,
                    'project_title' => $runtime['project_title'],
                    'project_alias' => $runtime['project_alias'],
                    'group_id' => $runtime['group_id'],
                    'group_title' => $runtime['group_title'],
                    'group_alias' => $runtime['group_alias'],
                    'title' => $runtime['project_title'],
                    'runtime_count' => 0,
                    'cluster_ids' => [],
                    'cluster_titles' => [],
                    'orchestrator_types' => [],
                    'cpu_percent' => 0.0,
                    'memory_usage' => 0,
                    'memory_limit' => 0,
                    'network_rx_bps' => 0.0,
                    'network_tx_bps' => 0.0,
                    'disk_read_bps' => 0.0,
                    'disk_write_bps' => 0.0,
                    'covered_runtime_count' => 0,
                    'network_covered_runtime_count' => 0,
                    'disk_covered_runtime_count' => 0,
                    'collected_at' => 0,
                    'stale' => false,
                ];
            }
            $project = &$projects[$id];
            ++$project['runtime_count'];
            $project['cluster_ids'][$runtime['cluster_id']] = $runtime['cluster_id'];
            $project['cluster_titles'][$runtime['cluster_id']] = $runtime['cluster_title'];
            $project['orchestrator_types'][$runtime['orchestrator_type']] = $runtime['orchestrator_type'];
            if ($runtime['cpu_available']) {
                foreach (['cpu_percent', 'memory_usage', 'memory_limit'] as $field) {
                    $project[$field] += $runtime[$field];
                }
            }
            if ($runtime['network_available']) {
                foreach (['network_rx_bps', 'network_tx_bps'] as $field) {
                    $project[$field] += $runtime[$field];
                }
            }
            if ($runtime['disk_available']) {
                foreach (['disk_read_bps', 'disk_write_bps'] as $field) {
                    $project[$field] += $runtime[$field];
                }
            }
            $project['covered_runtime_count'] += $runtime['cpu_available'] ? 1 : 0;
            $project['network_covered_runtime_count'] += $runtime['network_available'] ? 1 : 0;
            $project['disk_covered_runtime_count'] += $runtime['disk_available'] ? 1 : 0;
            $project['collected_at'] = max($project['collected_at'], $runtime['collected_at']);
            $project['stale'] = $project['stale'] || $runtime['stale'];
            unset($project);
        }

        foreach ($projects as &$project) {
            $project['cluster_ids'] = array_values($project['cluster_ids']);
            $project['cluster_titles'] = array_values($project['cluster_titles']);
            $project['orchestrator_types'] = array_values($project['orchestrator_types']);
            $project['cpu_available'] = $project['covered_runtime_count'] > 0;
            $project['memory_available'] = $project['covered_runtime_count'] > 0;
            $project['network_available'] = $project['network_covered_runtime_count'] > 0;
            $project['disk_available'] = $project['disk_covered_runtime_count'] > 0;
            $project['metric_coverage'] = $project['runtime_count'] > 0
                ? round($project['covered_runtime_count'] / $project['runtime_count'] * 100, 2)
                : 0;
            $project['disk_io_coverage'] = $project['runtime_count'] > 0
                ? round($project['disk_covered_runtime_count'] / $project['runtime_count'] * 100, 2)
                : 0;
        }
        unset($project);
        return array_values($projects);
    }

    private function metricValue(array $row, string $metric): float
    {
        return match ($metric) {
            'memory' => (float) $row['memory_usage'],
            'network' => round((float) $row['network_rx_bps'] + (float) $row['network_tx_bps'], 2),
            'disk' => round((float) $row['disk_read_bps'] + (float) $row['disk_write_bps'], 2),
            default => (float) $row['cpu_percent'],
        };
    }

    private function metricAvailable(array $row, string $metric): bool
    {
        return (bool) ($row[$metric . '_available'] ?? false);
    }

    private function unit(string $metric): string
    {
        return match ($metric) {
            'memory' => 'bytes',
            'network', 'disk' => 'bytes_per_second',
            default => 'percent',
        };
    }
}

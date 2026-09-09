<?php

declare(strict_types=1);
/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */

namespace App\Services\Kubernetes;

use App\Model\Cluster;
use App\Model\KubernetesResourceSnapshot;
use Hyperf\DbConnection\Db;

final class KubernetesResourceSnapshotService
{
    public function __construct(
        private KubernetesClusterService $clusters,
        private KubernetesApiClient $api
    ) {}

    /**
     * Collects current Pod and Node usage for every Kubernetes cluster.
     *
     * A failed cluster keeps its previous rows so the ranking can mark them
     * stale instead of briefly presenting an empty cluster.
     */
    public function collectAll(): array
    {
        $result = ['clusters' => 0, 'resources' => 0, 'failed_clusters' => 0];
        $clusters = Cluster::where('orchestrator_type', Cluster::ORCHESTRATOR_KUBERNETES)
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

    /** @return array<int, array<string, bool|float|int|string>> */
    public function collectCluster(Cluster $cluster): array
    {
        [, , $credential] = $this->clusters->connectionWithCredential(
            (int) $cluster->org_id,
            (int) $cluster->id
        );
        $pods = (array) ($this->api->get($credential, '/api/v1/pods')['items'] ?? []);
        $nodes = (array) ($this->api->get($credential, '/api/v1/nodes')['items'] ?? []);
        try {
            $replicaSets = (array) ($this->api->get(
                $credential,
                '/apis/apps/v1/replicasets'
            )['items'] ?? []);
        } catch (\Throwable) {
            $replicaSets = [];
        }
        try {
            $podMetrics = (array) ($this->api->get(
                $credential,
                '/apis/metrics.k8s.io/v1beta1/pods'
            )['items'] ?? []);
        } catch (\Throwable) {
            $podMetrics = [];
        }
        try {
            $nodeMetrics = (array) ($this->api->get(
                $credential,
                '/apis/metrics.k8s.io/v1beta1/nodes'
            )['items'] ?? []);
        } catch (\Throwable) {
            $nodeMetrics = [];
        }

        $metricMap = $this->byNamespacedName($podMetrics);
        $nodeMetricMap = $this->byName($nodeMetrics);
        $replicaSetOwners = $this->replicaSetOwners($replicaSets);
        $collectedAt = time();
        $rows = [];
        foreach ($pods as $pod) {
            $rows[] = $this->podRow(
                $cluster,
                $pod,
                $metricMap,
                $replicaSetOwners,
                $collectedAt
            );
        }
        foreach ($nodes as $node) {
            $rows[] = $this->nodeRow($cluster, $node, $nodeMetricMap, $collectedAt);
        }
        return $rows;
    }

    private function persistCluster(Cluster $cluster, array $rows): void
    {
        $collectedAt = $rows === [] ? time() : (int) $rows[0]['collected_at'];
        Db::transaction(function () use ($cluster, $rows, $collectedAt): void {
            foreach (array_chunk($rows, 500) as $chunk) {
                Db::table('kubernetes_resource_snapshot')->upsert(
                    $chunk,
                    ['cluster_id', 'resource_type', 'resource_uid'],
                    [
                        'org_id', 'name', 'namespace', 'node_name', 'workload_kind',
                        'workload_name', 'phase', 'container_count', 'cpu_percent',
                        'memory_usage', 'memory_limit', 'metric_available', 'collected_at',
                    ]
                );
            }
            Db::table('kubernetes_resource_snapshot')
                ->where('cluster_id', (int) $cluster->id)
                ->where('collected_at', '<', $collectedAt)
                ->delete();
        });
    }

    private function podRow(
        Cluster $cluster,
        array $pod,
        array $metricMap,
        array $replicaSetOwners,
        int $collectedAt
    ): array {
        $metadata = (array) ($pod['metadata'] ?? []);
        $spec = (array) ($pod['spec'] ?? []);
        $status = (array) ($pod['status'] ?? []);
        $namespace = (string) ($metadata['namespace'] ?? 'default');
        $name = (string) ($metadata['name'] ?? '');
        $metric = $metricMap[$namespace . ':' . $name] ?? null;
        $cpuPercent = 0.0;
        $memoryUsage = 0;
        if (is_array($metric)) {
            foreach ((array) ($metric['containers'] ?? []) as $containerMetric) {
                $usage = (array) ($containerMetric['usage'] ?? []);
                $cpuPercent += $this->cpuCores((string) ($usage['cpu'] ?? '0')) * 100;
                $memoryUsage += $this->bytes((string) ($usage['memory'] ?? '0'));
            }
        }
        $memoryLimit = 0;
        foreach ((array) ($spec['containers'] ?? []) as $container) {
            $memoryLimit += $this->bytes((string) ($container['resources']['limits']['memory'] ?? '0'));
        }
        [$workloadKind, $workloadName] = $this->workloadOwner($metadata, $replicaSetOwners);
        return [
            'org_id' => (int) $cluster->org_id,
            'cluster_id' => (int) $cluster->id,
            'resource_type' => KubernetesResourceSnapshot::TYPE_POD,
            'resource_uid' => (string) ($metadata['uid'] ?? ($namespace . ':' . $name)),
            'name' => $name,
            'namespace' => $namespace,
            'node_name' => (string) ($spec['nodeName'] ?? ''),
            'workload_kind' => $workloadKind,
            'workload_name' => $workloadName,
            'phase' => (string) ($status['phase'] ?? ''),
            'container_count' => count((array) ($spec['containers'] ?? [])),
            'cpu_percent' => round($cpuPercent, 3),
            'memory_usage' => $memoryUsage,
            'memory_limit' => $memoryLimit,
            'metric_available' => is_array($metric),
            'collected_at' => $collectedAt,
        ];
    }

    private function nodeRow(Cluster $cluster, array $node, array $metricMap, int $collectedAt): array
    {
        $metadata = (array) ($node['metadata'] ?? []);
        $status = (array) ($node['status'] ?? []);
        $name = (string) ($metadata['name'] ?? '');
        $metric = $metricMap[$name] ?? null;
        $usage = is_array($metric) ? (array) ($metric['usage'] ?? []) : [];
        return [
            'org_id' => (int) $cluster->org_id,
            'cluster_id' => (int) $cluster->id,
            'resource_type' => KubernetesResourceSnapshot::TYPE_NODE,
            'resource_uid' => (string) ($metadata['uid'] ?? $name),
            'name' => $name,
            'namespace' => '',
            'node_name' => $name,
            'workload_kind' => '',
            'workload_name' => '',
            'phase' => $this->nodeReady($status) ? 'Ready' : 'NotReady',
            'container_count' => 0,
            'cpu_percent' => round($this->cpuCores((string) ($usage['cpu'] ?? '0')) * 100, 3),
            'memory_usage' => $this->bytes((string) ($usage['memory'] ?? '0')),
            'memory_limit' => $this->bytes((string) ($status['allocatable']['memory'] ?? '0')),
            'metric_available' => is_array($metric),
            'collected_at' => $collectedAt,
        ];
    }

    private function workloadOwner(array $metadata, array $replicaSetOwners): array
    {
        $owner = $this->controllerOwner((array) ($metadata['ownerReferences'] ?? []));
        if ($owner === null) {
            return ['Pod', (string) ($metadata['name'] ?? '')];
        }
        if (($owner['kind'] ?? '') === 'ReplicaSet') {
            $resolved = $replicaSetOwners[(string) ($owner['uid'] ?? '')] ?? null;
            if ($resolved !== null) {
                return [(string) $resolved['kind'], (string) $resolved['name']];
            }
        }
        return [(string) ($owner['kind'] ?? 'Pod'), (string) ($owner['name'] ?? '')];
    }

    private function replicaSetOwners(array $replicaSets): array
    {
        $map = [];
        foreach ($replicaSets as $replicaSet) {
            $metadata = (array) ($replicaSet['metadata'] ?? []);
            $uid = (string) ($metadata['uid'] ?? '');
            $owner = $this->controllerOwner((array) ($metadata['ownerReferences'] ?? []));
            if ($uid !== '' && $owner !== null) {
                $map[$uid] = $owner;
            }
        }
        return $map;
    }

    private function controllerOwner(array $owners): ?array
    {
        foreach ($owners as $owner) {
            if (($owner['controller'] ?? false) === true) {
                return $owner;
            }
        }
        return $owners[0] ?? null;
    }

    private function byNamespacedName(array $items): array
    {
        $map = [];
        foreach ($items as $item) {
            $metadata = (array) ($item['metadata'] ?? []);
            $map[(string) ($metadata['namespace'] ?? '') . ':' . (string) ($metadata['name'] ?? '')] = $item;
        }
        return $map;
    }

    private function byName(array $items): array
    {
        $map = [];
        foreach ($items as $item) {
            $name = (string) ($item['metadata']['name'] ?? '');
            if ($name !== '') {
                $map[$name] = $item;
            }
        }
        return $map;
    }

    private function nodeReady(array $status): bool
    {
        foreach ((array) ($status['conditions'] ?? []) as $condition) {
            if (($condition['type'] ?? '') === 'Ready') {
                return ($condition['status'] ?? '') === 'True';
            }
        }
        return false;
    }

    private function cpuCores(string $quantity): float
    {
        if (preg_match('/^([0-9.]+)(n|u|m)?$/', trim($quantity), $matches) !== 1) {
            return 0.0;
        }
        return (float) $matches[1] * match ($matches[2] ?? '') {
            'n' => 0.000000001,
            'u' => 0.000001,
            'm' => 0.001,
            default => 1.0,
        };
    }

    private function bytes(string $quantity): int
    {
        if (preg_match('/^([0-9.]+)(Ki|Mi|Gi|Ti|Pi|K|M|G|T|P)?$/', trim($quantity), $matches) !== 1) {
            return 0;
        }
        $factor = match ($matches[2] ?? '') {
            'Ki' => 1024,
            'Mi' => 1024 ** 2,
            'Gi' => 1024 ** 3,
            'Ti' => 1024 ** 4,
            'Pi' => 1024 ** 5,
            'K' => 1000,
            'M' => 1000 ** 2,
            'G' => 1000 ** 3,
            'T' => 1000 ** 4,
            'P' => 1000 ** 5,
            default => 1,
        };
        return (int) round((float) $matches[1] * $factor);
    }
}

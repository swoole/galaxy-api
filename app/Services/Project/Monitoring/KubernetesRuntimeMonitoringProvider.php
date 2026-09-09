<?php

namespace App\Services\Project\Monitoring;

use App\Model\Cluster;
use App\Model\ProjectRuntime;
use App\Services\Kubernetes\KubernetesApiClient;
use App\Services\Kubernetes\KubernetesClusterService;
use Throwable;

final class KubernetesRuntimeMonitoringProvider implements ProjectRuntimeMonitoringProvider
{
    public function __construct(
        private KubernetesClusterService $clusters,
        private KubernetesApiClient $api
    ) {}

    public function orchestratorType(): string
    {
        return Cluster::ORCHESTRATOR_KUBERNETES;
    }

    public function collect(Cluster $cluster, array $runtimes): array
    {
        [, , $credential] = $this->clusters->connectionWithCredential(
            (int) $cluster->org_id,
            (int) $cluster->id
        );
        $deployments = (array) ($this->api->get($credential, '/apis/apps/v1/deployments')['items'] ?? []);
        $pods = (array) ($this->api->get($credential, '/api/v1/pods')['items'] ?? []);
        try {
            $podMetrics = (array) ($this->api->get(
                $credential,
                '/apis/metrics.k8s.io/v1beta1/pods'
            )['items'] ?? []);
        } catch (Throwable) {
            $podMetrics = [];
        }
        $deploymentMap = [];
        foreach ($deployments as $deployment) {
            $metadata = (array) ($deployment['metadata'] ?? []);
            $uid = (string) ($metadata['uid'] ?? '');
            $key = (string) ($metadata['namespace'] ?? '') . ':' . (string) ($metadata['name'] ?? '');
            if ($uid !== '') {
                $deploymentMap[$uid] = $deployment;
            }
            $deploymentMap[$key] = $deployment;
        }
        $metricMap = [];
        foreach ($podMetrics as $metric) {
            $metadata = (array) ($metric['metadata'] ?? []);
            $metricMap[(string) ($metadata['namespace'] ?? '') . ':' . (string) ($metadata['name'] ?? '')] = $metric;
        }

        $result = [];
        foreach ($runtimes as $runtime) {
            $namespace = (string) ($runtime->runtime_namespace ?: 'galaxy-o' . (int) $runtime->org_id);
            $name = (string) $runtime->service_name;
            $deployment = $deploymentMap[(string) $runtime->runtime_ref]
                ?? $deploymentMap[$namespace . ':' . $name]
                ?? null;
            if (! is_array($deployment)) {
                $result[(int) $runtime->id] = $this->emptySnapshot($runtime, 'Kubernetes Deployment 不存在');
                continue;
            }
            $runtimePods = array_values(array_filter(
                $pods,
                static fn (array $pod): bool =>
                    (string) ($pod['metadata']['namespace'] ?? '') === $namespace
                    && (string) ($pod['metadata']['labels']['codegalaxy.com/runtime'] ?? '') === $name
            ));
            $result[(int) $runtime->id] = $this->snapshot(
                $runtime,
                $deployment,
                $runtimePods,
                $metricMap
            );
        }
        return $result;
    }

    private function snapshot(
        ProjectRuntime $runtime,
        array $deployment,
        array $pods,
        array $metricMap
    ): array {
        $metadata = (array) ($deployment['metadata'] ?? []);
        $spec = (array) ($deployment['spec'] ?? []);
        $status = (array) ($deployment['status'] ?? []);
        $desired = (int) ($spec['replicas'] ?? 0);
        $running = (int) ($status['readyReplicas'] ?? 0);
        $failed = 0;
        $problem = '';
        $metrics = ['cpu_percent' => 0.0, 'memory_usage' => 0, 'memory_limit' => 0,
            'network_rx' => 0, 'network_tx' => 0, 'disk_read' => 0, 'disk_write' => 0, 'pids' => 0];
        $coveredPods = 0;
        foreach ($pods as $pod) {
            $phase = (string) ($pod['status']['phase'] ?? 'Unknown');
            $statuses = (array) ($pod['status']['containerStatuses'] ?? []);
            $podFailed = $phase === 'Failed';
            foreach ($statuses as $containerStatus) {
                $waiting = (array) ($containerStatus['state']['waiting'] ?? []);
                $terminated = (array) ($containerStatus['state']['terminated'] ?? []);
                $reason = (string) ($waiting['reason'] ?? $terminated['reason'] ?? '');
                if (in_array($reason, [
                    'CrashLoopBackOff', 'ImagePullBackOff', 'ErrImagePull', 'CreateContainerConfigError',
                ], true)) {
                    $podFailed = true;
                    $problem = $reason;
                }
            }
            if ($podFailed) {
                ++$failed;
            }
            $podMetadata = (array) ($pod['metadata'] ?? []);
            $metric = $metricMap[(string) ($podMetadata['namespace'] ?? '') . ':'
                . (string) ($podMetadata['name'] ?? '')] ?? null;
            if (! is_array($metric)) {
                continue;
            }
            foreach ((array) ($metric['containers'] ?? []) as $containerMetric) {
                $usage = (array) ($containerMetric['usage'] ?? []);
                $metrics['cpu_percent'] += $this->cpuCores((string) ($usage['cpu'] ?? '0')) * 100;
                $metrics['memory_usage'] += $this->bytes((string) ($usage['memory'] ?? '0'));
            }
            ++$coveredPods;
        }
        foreach ((array) ($status['conditions'] ?? []) as $condition) {
            if (($condition['status'] ?? '') === 'False' && ($condition['type'] ?? '') === 'Progressing') {
                $problem = (string) ($condition['message'] ?? $condition['reason'] ?? $problem);
            }
        }
        $health = $desired === $running && $failed === 0
            ? 'healthy'
            : ($running > 0 ? 'degraded' : 'unhealthy');
        $reference = (string) ($metadata['uid'] ?? $runtime->runtime_ref);
        $images = array_values(array_unique(array_map(
            static fn (array $container): string => (string) ($container['image'] ?? ''),
            (array) ($spec['template']['spec']['containers'] ?? [])
        )));
        return $this->base($runtime, $reference, $metrics) + [
            'image' => implode(', ', $images),
            'desired_tasks' => $desired,
            'running_tasks' => $running,
            'failed_tasks' => $failed,
            'local_containers' => $coveredPods,
            'metric_coverage' => $running > 0 ? round(min($coveredPods, $running) / $running * 100, 2) : 0,
            // metrics.k8s.io does not expose container disk I/O. A future
            // cAdvisor/Prometheus provider can populate the same fields.
            'disk_io_coverage' => 0,
            'health' => $health,
            'update_state' => (int) ($status['updatedReplicas'] ?? 0) < $desired ? 'updating' : '',
            'update_message' => '',
            'error' => $problem,
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
        if (preg_match('/^([0-9.]+)(Ki|Mi|Gi|Ti|K|M|G|T)?$/', trim($quantity), $matches) !== 1) {
            return 0;
        }
        $factor = match ($matches[2] ?? '') {
            'Ki' => 1024,
            'Mi' => 1024 ** 2,
            'Gi' => 1024 ** 3,
            'Ti' => 1024 ** 4,
            'K' => 1000,
            'M' => 1000 ** 2,
            'G' => 1000 ** 3,
            'T' => 1000 ** 4,
            default => 1,
        };
        return (int) round((float) $matches[1] * $factor);
    }
}

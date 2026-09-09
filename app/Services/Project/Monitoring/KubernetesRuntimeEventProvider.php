<?php

namespace App\Services\Project\Monitoring;

use App\Model\Cluster;
use App\Model\ProjectRuntime;
use App\Services\Kubernetes\KubernetesApiClient;
use App\Services\Kubernetes\KubernetesClusterService;
use Throwable;

final class KubernetesRuntimeEventProvider implements ProjectRuntimeEventProvider
{
    public function __construct(
        private KubernetesClusterService $clusters,
        private KubernetesApiClient $api
    ) {}

    public function orchestratorType(): string
    {
        return Cluster::ORCHESTRATOR_KUBERNETES;
    }

    public function collect(Cluster $cluster, array $runtimes, int $since, int $until): array
    {
        [, , $credential] = $this->clusters->connectionWithCredential(
            (int) $cluster->org_id,
            (int) $cluster->id
        );
        $events = [];
        $namespaces = [];
        foreach ($runtimes as $runtime) {
            $namespaces[(string) ($runtime->runtime_namespace ?: 'galaxy-o' . (int) $runtime->org_id)] = true;
        }
        foreach (array_keys($namespaces) as $namespace) {
            $path = '/api/v1/namespaces/' . rawurlencode($namespace) . '/events?limit=500';
            $events = array_merge(
                $events,
                (array) ($this->api->get($credential, $path)['items'] ?? [])
            );
        }
        usort($runtimes, static fn (ProjectRuntime $left, ProjectRuntime $right): int =>
            strlen((string) $right->service_name) <=> strlen((string) $left->service_name)
        );
        $rows = [];
        foreach ($events as $event) {
            $object = (array) ($event['involvedObject'] ?? []);
            $runtime = $this->runtimeFor($object, $runtimes);
            if (! $runtime instanceof ProjectRuntime) {
                continue;
            }
            $occurredAt = $this->occurredAt($event);
            if ($occurredAt < $since || $occurredAt > $until + 5) {
                continue;
            }
            $metadata = (array) ($event['metadata'] ?? []);
            $attributes = [
                'type' => (string) ($event['type'] ?? ''),
                'reason' => (string) ($event['reason'] ?? ''),
                'message' => (string) ($event['message'] ?? ''),
                'count' => (int) ($event['count'] ?? $event['series']['count'] ?? 1),
                'reporting_component' => (string) ($event['reportingComponent']
                    ?? $event['source']['component'] ?? ''),
                'object_kind' => (string) ($object['kind'] ?? ''),
                'object_name' => (string) ($object['name'] ?? ''),
                'namespace' => (string) ($object['namespace'] ?? ''),
            ];
            $rows[] = [
                'runtime' => $runtime,
                'fingerprint' => hash('sha256', implode('|', [
                    $cluster->id,
                    $metadata['uid'] ?? '',
                    $attributes['count'],
                    $occurredAt,
                ])),
                'event_type' => 'kubernetes.' . strtolower((string) ($object['kind'] ?? 'object')),
                'action' => (string) ($event['reason'] ?? $event['type'] ?? 'Event'),
                'actor_id' => (string) ($object['uid'] ?? $object['name'] ?? ''),
                'attributes' => $attributes,
                'occurred_at' => $occurredAt,
            ];
        }
        foreach ($runtimes as $runtime) {
            $rows = array_merge($rows, $this->deploymentConditions(
                $credential,
                $cluster,
                $runtime,
                $since,
                $until
            ));
        }
        return $rows;
    }

    private function deploymentConditions(
        array $credential,
        Cluster $cluster,
        ProjectRuntime $runtime,
        int $since,
        int $until
    ): array {
        if ((string) $runtime->workload_kind !== ProjectRuntime::WORKLOAD_KUBERNETES_DEPLOYMENT) {
            return [];
        }
        $namespace = (string) ($runtime->runtime_namespace ?: 'galaxy-o' . (int) $runtime->org_id);
        $name = trim((string) $runtime->service_name);
        if ($name === '') {
            return [];
        }
        try {
            $deployment = $this->api->get(
                $credential,
                '/apis/apps/v1/namespaces/' . rawurlencode($namespace)
                    . '/deployments/' . rawurlencode($name)
            );
        } catch (Throwable) {
            return [];
        }
        $uid = (string) ($deployment['metadata']['uid'] ?? $runtime->runtime_ref);
        $rows = [];
        foreach ((array) ($deployment['status']['conditions'] ?? []) as $condition) {
            $occurredAt = $this->conditionOccurredAt((array) $condition);
            if ($occurredAt < $since || $occurredAt > $until + 5) {
                continue;
            }
            $type = (string) ($condition['type'] ?? 'Condition');
            $status = (string) ($condition['status'] ?? 'Unknown');
            $reason = (string) ($condition['reason'] ?? $type);
            $attributes = [
                'type' => 'Condition',
                'condition' => $type,
                'status' => $status,
                'reason' => $reason,
                'message' => (string) ($condition['message'] ?? ''),
                'object_kind' => 'Deployment',
                'object_name' => $name,
                'namespace' => $namespace,
            ];
            $rows[] = [
                'runtime' => $runtime,
                'fingerprint' => hash('sha256', implode('|', [
                    $cluster->id, $uid, 'condition', $type, $status, $reason, $occurredAt,
                ])),
                'event_type' => 'kubernetes.deployment.condition',
                'action' => $reason,
                'actor_id' => $uid,
                'attributes' => $attributes,
                'occurred_at' => $occurredAt,
            ];
        }
        return $rows;
    }

    private function conditionOccurredAt(array $condition): int
    {
        foreach ([$condition['lastTransitionTime'] ?? null, $condition['lastUpdateTime'] ?? null] as $value) {
            if (is_string($value) && ($timestamp = strtotime($value)) !== false) {
                return $timestamp;
            }
        }
        return time();
    }

    private function runtimeFor(array $object, array $runtimes): ?ProjectRuntime
    {
        $uid = (string) ($object['uid'] ?? '');
        $namespace = (string) ($object['namespace'] ?? '');
        $name = (string) ($object['name'] ?? '');
        foreach ($runtimes as $runtime) {
            $workload = (string) $runtime->service_name;
            $runtimeNamespace = (string) ($runtime->runtime_namespace ?: 'galaxy-o' . (int) $runtime->org_id);
            if ($namespace !== $runtimeNamespace) {
                continue;
            }
            if ($uid !== '' && $uid === (string) $runtime->runtime_ref) {
                return $runtime;
            }
            if ($name === $workload || str_starts_with($name, $workload . '-')) {
                return $runtime;
            }
        }
        return null;
    }

    private function occurredAt(array $event): int
    {
        foreach ([
            $event['eventTime'] ?? null,
            $event['series']['lastObservedTime'] ?? null,
            $event['lastTimestamp'] ?? null,
            $event['firstTimestamp'] ?? null,
            $event['metadata']['creationTimestamp'] ?? null,
        ] as $value) {
            if (is_string($value) && ($timestamp = strtotime($value)) !== false) {
                return $timestamp;
            }
        }
        return time();
    }
}

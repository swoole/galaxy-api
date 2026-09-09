<?php

namespace App\Services\Project\Monitoring;

use App\Model\Cluster;
use App\Model\ProjectRuntime;
use App\Services\Docker\SwarmApiClient;
use App\Services\Project\ProjectServiceIdentity;
use GuzzleHttp\Client;

final class SwarmRuntimeEventProvider implements ProjectRuntimeEventProvider
{
    public function __construct(private SwarmApiClient $docker) {}

    public function orchestratorType(): string
    {
        return Cluster::ORCHESTRATOR_DOCKER_SWARM;
    }

    public function collect(Cluster $cluster, array $runtimes, int $since, int $until): array
    {
        $raw = $this->docker->withCluster(
            $cluster,
            fn (Client $client, SwarmApiClient $api): string => $api->requestRaw(
                $client,
                'GET',
                '/events',
                ['query' => [
                    'since' => max(0, $since),
                    'until' => $until,
                    'filters' => json_encode(
                        ['type' => ['service', 'task', 'container']],
                        JSON_UNESCAPED_SLASHES
                    ),
                ]]
            ),
            20
        );
        $byReference = [];
        foreach ($runtimes as $runtime) {
            foreach (array_filter([
                (string) $runtime->runtime_ref,
                ProjectServiceIdentity::runtimeDockerName($runtime),
            ]) as $reference) {
                $byReference[$reference] = $runtime;
            }
        }
        $rows = [];
        foreach (preg_split('/\r?\n/', trim($raw)) ?: [] as $line) {
            $event = json_decode($line, true);
            if (! is_array($event)) {
                continue;
            }
            $attributes = (array) ($event['Actor']['Attributes'] ?? []);
            $runtime = $this->runtimeFor([
                (string) ($event['Actor']['ID'] ?? ''),
                (string) ($attributes['name'] ?? ''),
                (string) ($attributes['service'] ?? ''),
                (string) ($attributes['com.docker.swarm.service.id'] ?? ''),
                (string) ($attributes['com.docker.swarm.service.name'] ?? ''),
            ], $byReference);
            if (! $runtime instanceof ProjectRuntime) {
                continue;
            }
            $occurredAt = (int) ($event['time'] ?? $until);
            $rows[] = [
                'runtime' => $runtime,
                'fingerprint' => hash('sha256', implode('|', [
                    $cluster->id, $event['Type'] ?? '', $event['Action'] ?? '',
                    $event['Actor']['ID'] ?? '',
                    $event['timeNano'] ?? ($occurredAt . ':' . json_encode($attributes)),
                ])),
                'event_type' => (string) ($event['Type'] ?? ''),
                'action' => (string) ($event['Action'] ?? ''),
                'actor_id' => (string) ($event['Actor']['ID'] ?? ''),
                'attributes' => $attributes,
                'occurred_at' => $occurredAt,
            ];
        }
        return $rows;
    }

    private function runtimeFor(array $references, array $byReference): ?ProjectRuntime
    {
        foreach ($references as $reference) {
            if ($reference !== '' && isset($byReference[$reference])) {
                return $byReference[$reference];
            }
        }
        return null;
    }
}

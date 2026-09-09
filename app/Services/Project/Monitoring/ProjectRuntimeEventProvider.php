<?php

namespace App\Services\Project\Monitoring;

use App\Model\Cluster;

interface ProjectRuntimeEventProvider
{
    public function orchestratorType(): string;

    /**
     * @return array<int, array{
     *     runtime: \App\Model\ProjectRuntime,
     *     fingerprint: string,
     *     event_type: string,
     *     action: string,
     *     actor_id: string,
     *     attributes: array,
     *     occurred_at: int
     * }>
     */
    public function collect(Cluster $cluster, array $runtimes, int $since, int $until): array;
}

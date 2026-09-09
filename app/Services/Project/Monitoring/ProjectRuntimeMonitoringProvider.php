<?php

namespace App\Services\Project\Monitoring;

use App\Model\Cluster;

interface ProjectRuntimeMonitoringProvider
{
    public function orchestratorType(): string;

    /**
     * @param array<int, \App\Model\ProjectRuntime> $runtimes
     * @return array<int, array<string, mixed>> Snapshots keyed by runtime ID.
     */
    public function collect(Cluster $cluster, array $runtimes): array;
}

<?php

namespace App\Services\Project\Build;

use App\Model\Build;
use App\Model\Cluster;
use App\Services\Project\BuildKitRunnerService;

final class SwarmBuildExecutor implements BuildExecutor
{
    public function __construct(private BuildKitRunnerService $runner) {}

    public function orchestratorType(): string
    {
        return Cluster::ORCHESTRATOR_DOCKER_SWARM;
    }

    public function run(Build $build): array
    {
        return $this->runner->run($build);
    }

    public function cancel(Build $build): void
    {
        $this->runner->cancel($build);
    }

    public function reconcileRunner(Build $build): bool
    {
        return $this->runner->reconcileRunner($build);
    }
}

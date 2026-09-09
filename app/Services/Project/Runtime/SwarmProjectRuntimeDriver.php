<?php

namespace App\Services\Project\Runtime;

use App\Model\Cluster;
use App\Model\ProjectRelease;
use App\Model\ProjectRuntime;
use App\Services\Docker\SwarmOverviewService;
use App\Services\Project\ManagedSwarmResourceGuard;
use App\Services\Project\SwarmReleaseRunnerService;

final class SwarmProjectRuntimeDriver implements ProjectRuntimeDriver
{
    public function __construct(
        private SwarmReleaseRunnerService $runner,
        private SwarmOverviewService $swarm,
        private ManagedSwarmResourceGuard $guard
    ) {}

    public function orchestratorType(): string
    {
        return Cluster::ORCHESTRATOR_DOCKER_SWARM;
    }

    public function deploy(ProjectRelease $release): array
    {
        return $this->runner->run($release);
    }

    public function reconcileSucceededRelease(ProjectRelease $release): ?array
    {
        return $this->runner->reconcileSucceededRelease($release);
    }

    public function cleanupFailedRelease(ProjectRelease $release): bool
    {
        return $this->runner->cleanupFailedReleaseSecrets($release);
    }

    public function restartRuntime(Cluster $cluster, ProjectRuntime $runtime): void
    {
        $service = $this->swarm->inspectService($cluster, (string) $runtime->runtime_ref);
        $this->guard->assertService(
            $service,
            (int) $runtime->org_id,
            (int) $runtime->project_id,
            (int) $runtime->env_id
        );
        $this->swarm->forceUpdateService($cluster, (string) $runtime->runtime_ref);
    }

    public function cleanupRuntimeResources(
        Cluster $cluster,
        int $orgId,
        int $projectId,
        int $envId,
        int $clusterId,
        string $runtimeName
    ): bool {
        return $this->runner->cleanupRuntimeSecrets(
            $cluster,
            $orgId,
            $projectId,
            $envId,
            $clusterId,
            $runtimeName
        );
    }
}

<?php

namespace App\Services\Project\Runtime;

use App\Model\Cluster;
use App\Model\ProjectRelease;
use App\Model\ProjectRuntime;

/**
 * Project-facing runtime boundary.
 *
 * Project, artifact, environment and release code depend on this contract.
 * Provider-specific implementations translate the immutable release snapshot
 * into Swarm or Kubernetes resources.
 */
interface ProjectRuntimeDriver
{
    public function orchestratorType(): string;

    public function deploy(ProjectRelease $release): array;

    public function reconcileSucceededRelease(ProjectRelease $release): ?array;

    public function cleanupFailedRelease(ProjectRelease $release): bool;

    public function restartRuntime(Cluster $cluster, ProjectRuntime $runtime): void;

    public function cleanupRuntimeResources(
        Cluster $cluster,
        int $orgId,
        int $projectId,
        int $envId,
        int $clusterId,
        string $runtimeName
    ): bool;
}

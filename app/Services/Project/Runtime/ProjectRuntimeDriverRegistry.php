<?php

namespace App\Services\Project\Runtime;

use App\Exception\AppException;
use App\Model\Cluster;
use App\Model\ProjectRelease;

final class ProjectRuntimeDriverRegistry
{
    /** @var array<string, ProjectRuntimeDriver> */
    private array $drivers;

    public function __construct(
        SwarmProjectRuntimeDriver $swarm,
        KubernetesProjectRuntimeDriver $kubernetes
    )
    {
        $this->drivers = [
            $swarm->orchestratorType() => $swarm,
            $kubernetes->orchestratorType() => $kubernetes,
        ];
    }

    public function forCluster(Cluster $cluster): ProjectRuntimeDriver
    {
        $type = (string) $cluster->orchestrator_type;
        if (! isset($this->drivers[$type])) {
            throw new AppException(422, sprintf('集群编排类型 %s 暂不支持项目部署', $type ?: 'unknown'));
        }
        return $this->drivers[$type];
    }

    public function forRelease(ProjectRelease $release): ProjectRuntimeDriver
    {
        /** @var Cluster|null $cluster */
        $cluster = Cluster::where('id', (int) $release->cluster_id)
            ->where('org_id', (int) $release->org_id)->first();
        if ($cluster === null) {
            throw new AppException(404, '发布目标集群不存在');
        }
        return $this->forCluster($cluster);
    }

    /** @return string[] */
    public function supportedTypes(): array
    {
        return array_keys($this->drivers);
    }
}

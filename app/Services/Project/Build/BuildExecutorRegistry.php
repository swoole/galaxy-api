<?php

namespace App\Services\Project\Build;

use App\Exception\AppException;
use App\Model\Build;
use App\Model\Cluster;

final class BuildExecutorRegistry
{
    /** @var array<string, BuildExecutor> */
    private array $executors;

    public function __construct(
        SwarmBuildExecutor $swarm,
        KubernetesBuildExecutor $kubernetes
    ) {
        $this->executors = [
            $swarm->orchestratorType() => $swarm,
            $kubernetes->orchestratorType() => $kubernetes,
        ];
    }

    public function forBuild(Build $build): BuildExecutor
    {
        $clusterId = (int) (((array) $build->spec_snapshot)['cluster_id'] ?? 0);
        /** @var Cluster|null $cluster */
        $cluster = Cluster::where('id', $clusterId)->where('org_id', (int) $build->org_id)->first();
        if ($cluster === null) {
            throw new AppException(409, 'BuildKit 构建集群不存在');
        }
        $executor = $this->executors[(string) $cluster->orchestrator_type] ?? null;
        if ($executor === null) {
            throw new AppException(422, '当前集群编排类型暂不支持 BuildKit 构建');
        }
        return $executor;
    }
}

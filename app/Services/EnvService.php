<?php

/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */
namespace App\Services;

use App\Constants\ErrorCode;
use App\Constants\Role;
use App\Exception\AppException;
use App\Model\Cluster;
use App\Model\ProjectRelease;
use App\Model\ProjectRuntime;
use App\Model\Env;
use App\Model\EnvClusterRel;
use App\Model\OrgMember;

class EnvService
{
    public function validateClusterIds(int $orgId, array $clusterIds): array
    {
        $clusterIds = array_values(array_unique(array_map('intval', $clusterIds)));
        if (in_array(0, $clusterIds, true)) {
            throw new AppException(422, '环境关联包含无效的集群 ID');
        }
        if ($clusterIds === []) {
            return [];
        }
        $count = Cluster::where('org_id', $orgId)
            ->whereIn('orchestrator_type', Cluster::knownOrchestrators())
            ->whereIn('id', $clusterIds)->distinct()->count('id');
        if ($count !== count($clusterIds)) {
            throw new AppException(422, '环境关联包含不存在或不属于当前组织的集群');
        }
        return $clusterIds;
    }

    public function assertClustersCanDetach(int $orgId, int $envId, array $clusterIds): void
    {
        if ($clusterIds === []) {
            return;
        }
        if (ProjectRuntime::where('org_id', $orgId)->where('env_id', $envId)
            ->whereIn('cluster_id', $clusterIds)->exists()) {
            throw new AppException(409, '所选集群仍承载该环境的项目运行实例，不能解除关联');
        }
        if (ProjectRelease::where('org_id', $orgId)->where('env_id', $envId)
            ->whereIn('cluster_id', $clusterIds)
            ->whereIn('status', [ProjectRelease::STATUS_PENDING, ProjectRelease::STATUS_DEPLOYING])->exists()) {
            throw new AppException(409, '所选集群仍有该环境的发布任务，不能解除关联');
        }
    }

    public function getEnvIdByCluster($org_id, $cluster_id)
    {
        return EnvClusterRel::where('org_id', $org_id)
            ->where('cluster_id', $cluster_id)
            ->pluck('env_id');
    }

    public function getClusterIdByEnv($org_id, $env_id)
    {
        return EnvClusterRel::where('org_id', $org_id)
            ->where('env_id', $env_id)
            ->pluck('cluster_id');
    }

    /**
     * 获取集群的环境变量.
     * @param $org_id
     * @param $cluster_id
     * @param mixed $env_id
     * @param mixed $columns
     */
    public function getEnvs($org_id, $cluster_id, $env_id = '', $columns = ['id', 'title'])
    {
        return Env::whereIn('id', $this->getEnvIdByCluster($org_id, $cluster_id))
            ->when(
                $env_id,
                function ($query) use ($env_id) {
                    $query->where('id', $env_id);
                }
            )
            ->get($columns);
    }

    /**
     * 获取集群的环境变量信息.
     * @param $org_id
     * @param $cluster_id
     * @param mixed $env_id
     * @param mixed $columns
     */
    public function getEnvsInfo($org_id, $cluster_id, $columns = ['id', 'title'])
    {
        return Env::whereIn('id', $this->getEnvIdByCluster($org_id, $cluster_id))
            ->get($columns);
    }

    /**
     * 获取env关联的集群.
     * @param $org_id
     * @param $env_id
     * @param string $cluster_id
     * @param string[] $columns
     * @return \Hyperf\Collection\Collection
     */
    public function getClusters($org_id, $env_id, $columns = ['id', 'title'])
    {
        return Cluster::whereIn('id', $this->getClusterIdByEnv($org_id, $env_id))
            ->get($columns);
    }

    /**
     * 项目名称 组织/项目内唯一
     * @param $title
     * @param $org_id
     * @param mixed $value
     * @param mixed $group_id
     * @param mixed $env_id
     */
    public function uniqueTitle($value, $org_id, $env_id = 0)
    {
        $existTitle = Env::where(['title' => $value, 'org_id' => $org_id])
            ->when(
                $env_id,
                function ($query, $env_id) {
                    return $query->where('id', '!=', $env_id);
                }
            )->first();
        if ($existTitle) {
            throw new AppException(
                ErrorCode::ENV_TITLE_DUPLICATE,
                ErrorCode::getMessage(ErrorCode::ENV_TITLE_DUPLICATE)
            );
        }
    }

    public function getEnvInfo($id, $org_id = 0, $columns = ['id', 'title'])
    {
        return Env::when($org_id, function ($query, $org_id) {
            return $query->where('org_id', $org_id);
        })->find($id, $columns);
    }

    public function getClusterInfo($id, $org_id = 0, $columns = ['id', 'title'])
    {
        return Cluster::when($org_id, function ($query, $org_id) {
            return $query->where('org_id', $org_id);
        })->find($id, $columns);
    }

    /**
     * 配置项是否存在.
     * @param $env_id
     * @param $org_id
     */
    public function existEnv($env_id, $org_id)
    {
        $exists = Env::where([
            'id' => $env_id,
            'org_id' => $org_id,
        ])->exists();
        if (! $exists) {
            throw new AppException(
                ErrorCode::ENV_NO_EXIST,
                ErrorCode::getMessage(ErrorCode::ENV_NO_EXIST)
            );
        }
    }

    /**
     * 是否关联集群.
     * @param $env_id
     * @param mixed $org_id
     */
    public function existCluster($org_id, $env_id)
    {
        $envExist = EnvClusterRel::where(['org_id' => $org_id, 'env_id' => $env_id])->exists();
        if ($envExist) {
            throw new AppException(
                ErrorCode::ENV_CANNOT_DELETE,
                ErrorCode::getMessage(ErrorCode::ENV_CANNOT_DELETE)
            );
        }
    }
}

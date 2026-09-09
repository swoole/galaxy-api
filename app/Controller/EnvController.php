<?php

/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */

namespace App\Controller;

use App\Exception\AppException;
use App\Model\ProjectRelease;
use App\Model\ProjectRuntime;
use App\Model\Env;
use App\Model\EnvClusterRel;
use App\Model\GroupResourceGrant;
use App\Services\EnvService;
use App\Services\OrgService;
use App\Support\Functions;
use Hyperf\DbConnection\Db;
use Hyperf\Di\Annotation\Inject;

class EnvController extends AbstractController
{
    /**
     * @Inject
     */
    #[Inject]
    protected OrgService $orgService;

    /**
     * @Inject
     */
    #[Inject]
    protected EnvService $envService;

    /**
     * @Inject
     */
    #[Inject]
    protected Env $env;

    public function index()
    {
        $orgId = Functions::getContextValue('org_id');

        $envs = Env::query()->where('org_id', $orgId)
            ->with([
                'creatorInfo' => function ($query) use ($orgId) {
                    $query->where('org_id', $orgId);
                },
            ])
            ->with('clusters')
            ->get();
        $envIds = $envs->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $relations = EnvClusterRel::where('org_id', $orgId)->whereIn('env_id', $envIds)
            ->get(['env_id', 'cluster_id']);
        $clusterIds = $relations->pluck('cluster_id')->map(static fn ($id): int => (int) $id)->unique()->all();
        $clusterGroups = [];
        foreach (GroupResourceGrant::where('org_id', $orgId)
            ->where('resource_type', GroupResourceGrant::TYPE_CLUSTER)->whereIn('resource_id', $clusterIds)
            ->get(['resource_id', 'group_id']) as $grant) {
            $clusterGroups[(int) $grant->resource_id][(int) $grant->group_id] = true;
        }
        $environmentGroups = [];
        foreach ($relations as $relation) {
            foreach (array_keys($clusterGroups[(int) $relation->cluster_id] ?? []) as $groupId) {
                $environmentGroups[(int) $relation->env_id][$groupId] = true;
            }
        }
        $runtimeCounts = ProjectRuntime::where('org_id', $orgId)->whereIn('env_id', $envIds)
            ->select('env_id', Db::raw('COUNT(*) AS cnt'))->groupBy('env_id')->pluck('cnt', 'env_id');
        $lastReleases = ProjectRelease::where('org_id', $orgId)->whereIn('env_id', $envIds)
            ->select('env_id', Db::raw('MAX(created_at) AS last_release_at'))
            ->groupBy('env_id')->pluck('last_release_at', 'env_id');
        foreach ($envs as $environment) {
            $clusters = $environment->clusters;
            $environment->setAttribute('ready_cluster_count', $clusters->filter(
                static fn ($cluster): bool => (int) $cluster->status === \App\Model\Cluster::STATUS_READY
            )->count());
            $environment->setAttribute('authorized_group_count', count($environmentGroups[(int) $environment->id] ?? []));
            $environment->setAttribute('runtime_count', (int) ($runtimeCounts[(int) $environment->id] ?? 0));
            $environment->setAttribute('last_release_at', (int) ($lastReleases[(int) $environment->id] ?? 0));
        }

        return $this->success(['envs' => $envs->toArray()]);
    }

    public function update()
    {
        $orgId = Functions::getContextValue('org_id');
        $param = $this->validate([
            'env_id' => 'integer|required',
            'title' => 'string|required|max:50',
            'remark' => 'string|max:200',
            'cluster_ids' => 'array',
            'cluster_ids.*' => 'integer|distinct|min:1',
        ], [
            'env_id.required' => '环境id必传',
            'title.required' => '请输入环境名称',
            'title.max' => '环境名称长度1-50个字符',
            'remark.max' => '环境备注长度不能超过200个字符',
        ]);

        $env_id = $param['env_id'];

        // 环境是否存在
        $this->envService->existEnv($env_id, $orgId);
        // 名称/标识唯一性判定
        $this->envService->uniqueTitle($param['title'], $orgId, $env_id);
        $clusterIds = $this->envService->validateClusterIds($orgId, (array) ($param['cluster_ids'] ?? []));

        // 开启事务
        Db::beginTransaction();
        try {
            /** @var null|Env $env */
            $env = Env::where('id', $env_id)->where('org_id', $orgId)->lockForUpdate()->first();
            if ($env === null) {
                throw new AppException(404, '环境不存在');
            }
            if ((int) $env->archived_at > 0) {
                throw new AppException(409, '已归档环境不能修改，请先恢复');
            }
            $currentClusterIds = EnvClusterRel::where('org_id', $orgId)->where('env_id', $env_id)
                ->lockForUpdate()->pluck('cluster_id')->map(static fn ($id): int => (int) $id)->all();
            $this->envService->assertClustersCanDetach(
                $orgId,
                (int) $env_id,
                array_values(array_diff($currentClusterIds, $clusterIds))
            );
            // 更新项目
            $env->title = $param['title'] ?? '';
            $env->remark = $param['remark'] ?? '';
            $env->save();

            // 删除之前关联集群
            EnvClusterRel::where(['org_id' => $orgId, 'env_id' => $env_id])->delete();

            // 关联集群
            if ($clusterIds !== []) {
                $insertData = [];

                foreach ($clusterIds as $cluster_id) {
                    $insertData[] = [
                        'org_id' => $orgId,
                        'env_id' => $env->id,
                        'cluster_id' => $cluster_id,
                    ];
                }
                EnvClusterRel::insert($insertData);
            }
            Db::commit();
            return $this->success(['env_id' => (int) $env->id]);
        } catch (\Exception $e) {
            Db::rollBack();
            throw new AppException((int) $e->getCode(), $e->getMessage());
        }
    }

    public function create()
    {
        $orgId = Functions::getContextValue('org_id');
        $param = $this->validate([
            'title' => 'string|required|max:50',
            'remark' => 'string|max:200',
            'cluster_ids' => 'array',
            'cluster_ids.*' => 'integer|distinct|min:1',
        ], [
            'title.required' => '请输入环境名称',
            'title.max' => '环境名称长度1-50个字符',
            'remark.max' => '环境备注长度不能超过200个字符',
        ]);
        // 获取用户
        $user_id = Functions::getLoginUser()->getId();

        // 校验 项目名称 项目标识 组织内唯一
        $this->envService->uniqueTitle($param['title'], $orgId);
        $clusterIds = $this->envService->validateClusterIds($orgId, (array) ($param['cluster_ids'] ?? []));

        // 开启事务
        Db::beginTransaction();
        try {
            // 创建项目
            $env = Env::create([
                'org_id' => $orgId,
                'title' => $param['title'],
                'remark' => $param['remark'] ?? '',
                'creator' => $user_id,
                'created_at' => time(),
            ]);

            // 关联集群
            if ($clusterIds !== []) {
                $insertData = [];
                foreach ($clusterIds as $cluster_id) {
                    $insertData[] = [
                        'org_id' => $orgId,
                        'env_id' => $env->id,
                        'cluster_id' => $cluster_id,
                    ];
                }
                EnvClusterRel::insert($insertData);
            }
            Db::commit();
            return $this->success(['env_id' => (int) $env->id]);
        } catch (\Throwable $e) {
            Db::rollBack();
            throw new AppException((int) $e->getCode(), $e->getMessage());
        }
    }

    public function archive()
    {
        $orgId = Functions::getContextValue('org_id');
        $params = $this->validate(['env_id' => 'integer|required|min:1']);
        $this->env->archive((int) $orgId, (int) $params['env_id']);
        return $this->success();
    }

    public function restore()
    {
        $orgId = Functions::getContextValue('org_id');
        $params = $this->validate(['env_id' => 'integer|required|min:1']);
        $this->env->restore((int) $orgId, (int) $params['env_id']);
        return $this->success();
    }

    public function profile()
    {
        $orgId = Functions::getContextValue('org_id');
        $params = $this->validateAll([
            'env_id' => 'required|integer',
        ]);

        $envs = Env::query()->where('id', $params['env_id'])
            ->where('org_id', $orgId)
            ->with([
                'creatorInfo' => function ($query) use ($orgId) {
                    $query->where('org_id', $orgId);
                },
            ])
            ->with('clusters')
            ->get();

        return $this->success(['env' => $envs->toArray()[0] ?? emptyObj()]);
    }

    public function simple()
    {
        $orgId = Functions::getContextValue('org_id');
        $groupId = Functions::getContextValue('group_id', false, null);
        $envs = $this->env->simpleList($orgId, $groupId === null ? null : (int) $groupId);

        return $this->success([
            'envs' => $envs,
        ]);
    }

    /**
     * 关联实例.
     */
    public function relInstances()
    {
        $orgId = Functions::getContextValue('org_id');
        $params = $this->validate([
            'env_id' => 'integer|required',
            'page' => 'nullable|integer|min:1',
            'pagesize' => 'nullable|integer|min:1|max:100',
        ]);
        $params = Functions::arrNull2default($params, [
            'page' => 1,
            'pagesize' => 20,
        ]);

        $data = $this->env->relInstances(
            $orgId,
            $params['env_id'],
            (int) $params['page'],
            (int) $params['pagesize']
        );

        return $this->success($data);
    }

}

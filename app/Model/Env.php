<?php

/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */

namespace App\Model;

use App\Exception\AppException;
use App\Support\MySQL;
use Hyperf\Context\Context;
use Hyperf\Database\Model\Collection;
use Hyperf\DbConnection\Db;

/**
 * @property int $id
 * @property int $org_id
 * @property string $title
 * @property string $remark
 * @property int $creator
 * @property int $created_at
 * @property Cluster[]|Collection $clusters
 */
class Env extends Model
{
    use TraitRelationCreatorInfo;

    /**
     * The table associated with the model.
     */
    protected ?string $table = 'env';

    /**
     * The attributes that are mass assignable.
     */
    //    protected $fillable = [];
    protected array $guarded = [];

    /**
     * The attributes that should be cast to native types.
     */
    protected array $casts = [
        'id' => 'integer', 'org_id' => 'integer', 'creator' => 'integer',
        'created_at' => 'integer', 'archived_at' => 'integer',
    ];

    public function getEnvInfo($id, $columns = ['id', 'title'])
    {
        return self::find($id, $columns);
    }

    public function archive(int $orgId, int $envId): void
    {
        Db::transaction(function () use ($orgId, $envId): void {
            /** @var Env|null $env */
            $env = $this->where('id', $envId)
                ->where('org_id', $orgId)
                ->lockForUpdate()
                ->first();
            if ($env === null) {
                throw new AppException(404, '环境不存在');
            }
            if ((int) $env->archived_at > 0) {
                return;
            }
            // Serialize with release creation and environment/cluster binding.
            EnvClusterRel::where('org_id', $orgId)->where('env_id', $envId)
                ->lockForUpdate()->get(['cluster_id']);

            if (ProjectRuntime::where('org_id', $orgId)->where('env_id', $envId)->exists()) {
                throw new AppException(422, '环境中仍有 Docker Swarm 运行实例，请先在项目实例页面删除');
            }
            if (ProjectRelease::where('org_id', $orgId)->where('env_id', $envId)
                ->whereIn('status', [ProjectRelease::STATUS_PENDING, ProjectRelease::STATUS_DEPLOYING])->exists()) {
                throw new AppException(422, '环境中仍有等待或执行中的项目发布，请等待发布结束');
            }
            if (ProjectRelease::where('org_id', $orgId)->where('env_id', $envId)
                ->where('status', ProjectRelease::STATUS_FAILED)
                ->where(function ($query): void {
                    $query->whereNull('result')
                        ->orWhereRaw("JSON_EXTRACT(`result`, '$.failure_reconciled_at') IS NULL");
                })->exists()) {
                throw new AppException(422, '环境中仍有尚未完成远端资源对账的失败发布，请等待治理任务完成');
            }
            $env->archived_at = time();
            $env->save();
        });
    }

    public function restore(int $orgId, int $envId): void
    {
        $updated = $this->where('id', $envId)->where('org_id', $orgId)->update(['archived_at' => 0]);
        if ($updated === 0 && ! $this->where('id', $envId)->where('org_id', $orgId)->exists()) {
            throw new AppException(404, '环境不存在');
        }
    }

    /**
     * 关联的实例.
     * @param mixed $orgId
     * @param mixed $envId
     * @param mixed $page
     * @param mixed $pageSize
     */
    public function relInstances($orgId, $envId, $page = 1, $pageSize = 20)
    {
        $builder = ProjectRuntime::where('org_id', $orgId)
            ->where('env_id', $envId)
            ->select(
                'id',
                'org_id',
                'group_id',
                'project_id',
                'env_id',
                'cluster_id',
                'name',
                'release_id',
                'runtime_ref',
                'running_count',
                'desired_count',
                'status',
                'health',
                'last_error',
                'last_synced_at'
            )->with('env')
            ->with('cluster')
            ->with('release')
            ->with('project')
            ->with('group');

        return MySQL::jsonPaginate($builder, $page, $pageSize);
    }

    /**
     * 简单列表.
     * @param mixed $orgId
     */
    public function simpleList($orgId, ?int $groupId = null)
    {
        $builder = $this->where('org_id', $orgId)->where('archived_at', 0)
            ->select('id', 'title');
        $roles = Context::get('roles', [0]);
        if ($groupId !== null && (int) ($roles[0] ?? 0) !== OrgMember::ROLE_MANAGER) {
            $clusterIds = GroupResourceGrant::clusterIds((int) $orgId, $groupId);
            $envIds = EnvClusterRel::where('org_id', $orgId)
                ->whereIn('cluster_id', $clusterIds)
                ->pluck('env_id');
            $builder->whereIn('id', $envIds);
        }
        return $builder->orderBy('id')->get();
    }

    /**
     * 模型关联: clusters.
     */
    public function clusters()
    {
        return $this->belongsToMany(Cluster::class, 'env_cluster_rel', 'env_id', 'cluster_id')
            ->select(
                'cluster.id', 'cluster.title', 'cluster.org_id', 'cluster.orchestrator_type', 'cluster.status'
            );
    }
}

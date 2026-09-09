<?php

/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */

namespace App\Model;

use App\Constants\ErrorCode;
use App\Exception\AppException;
use Hyperf\Context\Context;
use Hyperf\DbConnection\Db;

class Cluster extends Model
{
    use TraitRelationCreatorInfo;

    public const STATUS_CREATING = 0;

    public const STATUS_INITING = 1;

    public const STATUS_PENDING_CONNECT = 2;

    public const STATUS_READY = 3;

    public const STATUS_OFFLINE = 4;

    public const STATUS_EXCEPTION = 9;

    public const ORCHESTRATOR_DOCKER_SWARM = 'docker_swarm';

    public const ORCHESTRATOR_KUBERNETES = 'kubernetes';

    public const TYPE_SELF_PAY = 0;

    public const TYPE_MANAGED = 1;

    public const TYPE_TRYOUT = 2;

    public const SOURCE_FILL = 2;

    public static array $types = [self::TYPE_SELF_PAY => '自建'];

    /** @return string[] */
    public static function knownOrchestrators(): array
    {
        return [self::ORCHESTRATOR_DOCKER_SWARM, self::ORCHESTRATOR_KUBERNETES];
    }

    /** @return string[] */
    public static function deployableOrchestrators(): array
    {
        return [self::ORCHESTRATOR_DOCKER_SWARM, self::ORCHESTRATOR_KUBERNETES];
    }

    /** @return string[] */
    public static function buildableOrchestrators(): array
    {
        return [self::ORCHESTRATOR_DOCKER_SWARM, self::ORCHESTRATOR_KUBERNETES];
    }

    public static function routeNamespace(string $orchestratorType): string
    {
        return match ($orchestratorType) {
            self::ORCHESTRATOR_DOCKER_SWARM => 'swarm',
            self::ORCHESTRATOR_KUBERNETES => 'k8s',
            default => '',
        };
    }

    protected ?string $table = 'cluster';

    protected array $guarded = [];

    protected array $casts = [
        'id' => 'integer', 'org_id' => 'integer', 'status' => 'integer', 'creator' => 'integer',
        'created_at' => 'integer', 'type' => 'integer', 'source' => 'integer', 'extra' => 'array',
        'current_credential_version' => 'integer', 'registered_at' => 'integer',
    ];

    public static function queryCluster(int $orgId, int $clusterId)
    {
        return self::where('id', $clusterId)->where('org_id', $orgId)
            ->where('orchestrator_type', self::ORCHESTRATOR_DOCKER_SWARM);
    }

    public function getClusterInfo(int $clusterId)
    {
        return self::find($clusterId, ['id', 'title']);
    }

    public function list(int $orgId, ?int $groupId = null, ?int $envId = null, ?string $keyword = null)
    {
        $builder = self::where('org_id', $orgId)
            ->where('orchestrator_type', self::ORCHESTRATOR_DOCKER_SWARM)
            ->select(
                'id',
                'org_id',
                'title',
                'vendor',
                'type',
                'creator',
                'resolve',
                'orchestrator_type',
                'registration_status',
                'agent_status',
                'registered_at',
                'swarm_id',
                'remark',
                'created_at',
                'status',
                'version'
            )
            ->withCount([
                'agentNodes',
                'agentNodes as online_agent_nodes_count' => static fn ($query) => $query->where('status', 'online'),
                'agentNodes as manager_agent_nodes_count' => static fn ($query) => $query->where('role', 'manager'),
                'agentNodes as online_manager_agent_nodes_count' => static fn ($query) => $query
                    ->where('role', 'manager')->where('status', 'online'),
            ])
            ->with(['creatorInfo' => static fn ($query) => $query->where('org_id', $orgId)])
            ->with(['envs' => static fn ($query) => $query->where('env_cluster_rel.org_id', $orgId)]);
        $roles = Context::get('roles', [0]);
        if ($groupId !== null && (int) ($roles[0] ?? 0) !== OrgMember::ROLE_MANAGER) {
            $builder->whereIn('id', GroupResourceGrant::clusterIds($orgId, $groupId));
        }
        if ($envId !== null) {
            $ids = EnvClusterRel::where('org_id', $orgId)->where('env_id', $envId)->pluck('cluster_id');
            $builder->whereIn('id', $ids);
        }
        if ($keyword !== null && $keyword !== '') {
            $builder->where(static function ($query) use ($keyword): void {
                $query->where('title', 'like', '%' . $keyword . '%');
                if (ctype_digit($keyword)) {
                    $query->orWhere('id', (int) $keyword);
                }
            });
        }
        return $builder->orderBy('id')->get()->each(static function ($cluster): void {
            unset($cluster->pivot);
            $cluster->envs->each(static function ($env): void {
                unset($env->pivot);
            });
        });
    }

    public function simpleList(int $orgId, ?int $groupId = null, ?int $envId = null, ?string $keyword = null)
    {
        $builder = self::where('org_id', $orgId)
            ->whereIn('orchestrator_type', self::knownOrchestrators())
            ->select('id', 'type', 'title', 'resolve', 'endpoint', 'orchestrator_type', 'remark', 'vendor', 'status', 'version');
        $roles = Context::get('roles', [0]);
        if ($groupId !== null && (int) ($roles[0] ?? 0) !== OrgMember::ROLE_MANAGER) {
            $builder->whereIn('id', GroupResourceGrant::clusterIds($orgId, $groupId));
        }
        if ($envId !== null) {
            $ids = EnvClusterRel::where('org_id', $orgId)->where('env_id', $envId)->pluck('cluster_id');
            $builder->whereIn('id', $ids);
        }
        if ($keyword !== null && $keyword !== '') {
            $builder->where('title', 'like', '%' . $keyword . '%');
        }
        return $builder->orderBy('id')->get();
    }

    public function createSwarmCluster(int $uid, int $orgId, string $title, string $remark, string $endpoint): self
    {
        if (self::where('org_id', $orgId)->where('title', $title)->exists()) {
            throw new AppException(422, '该集群已存在，请重新命名');
        }
        return self::create([
            'org_id' => $orgId, 'vendor' => 0, 'type' => self::TYPE_SELF_PAY, 'title' => $title,
            'version' => '', 'orchestrator_type' => self::ORCHESTRATOR_DOCKER_SWARM,
            'endpoint' => $endpoint, 'resolve' => '', 'remark' => $remark, 'extra' => new \stdClass(),
            'status' => self::STATUS_PENDING_CONNECT, 'source' => self::SOURCE_FILL,
            'creator' => $uid, 'created_at' => time(),
        ]);
    }

    public function getDeleteOverview(int $uid, int $clusterId, int $orgId): array
    {
        $this->getCluster($clusterId, $orgId);
        $grouped = static fn ($builder): array => $builder->select('org_id', Db::raw('COUNT(1) AS cnt'))
            ->groupBy('org_id')->get()->toArray();
        $buildBlockers = Build::whereRaw(
            "CAST(JSON_UNQUOTE(JSON_EXTRACT(`spec_snapshot`, '$.cluster_id')) AS UNSIGNED) = ?",
            [$clusterId]
        )->where('executor', 'buildkit')->where(function ($query): void {
            $query->whereIn('status', [Build::STATUS_PENDING_RUN, Build::STATUS_RUNNING])
                ->orWhere(function ($terminal): void {
                    $terminal->whereIn('status', [Build::STATUS_SUCCESS, Build::STATUS_FAILED, Build::STATUS_CANCELED])
                        ->where(function ($unreconciled): void {
                            $unreconciled->whereNull('result')
                                ->orWhereRaw("JSON_EXTRACT(`result`, '$.runner_reconciled_at') IS NULL");
                        });
                });
        });
        $releaseBlockers = ProjectRelease::where('cluster_id', $clusterId)->where(function ($query): void {
            $query->whereIn('status', [ProjectRelease::STATUS_PENDING, ProjectRelease::STATUS_DEPLOYING])
                ->orWhere(function ($terminal): void {
                    $terminal->where('status', ProjectRelease::STATUS_FAILED)
                        ->where(function ($unreconciled): void {
                            $unreconciled->whereNull('result')
                                ->orWhereRaw("JSON_EXTRACT(`result`, '$.failure_reconciled_at') IS NULL");
                        });
                });
        });
        return [
            'domains' => $grouped(ProjectRoute::where('cluster_id', $clusterId)),
            'deploys' => $grouped(ProjectRelease::where('cluster_id', $clusterId)),
            'releaseBlockers' => $grouped($releaseBlockers),
            'pipelines' => $grouped(Pipeline::where('cluster_id', $clusterId)->where('archived_at', 0)),
            'builds' => $grouped($buildBlockers),
            'envRel' => $grouped(EnvClusterRel::where('cluster_id', $clusterId)),
            'instances' => $grouped(ProjectRuntime::where('cluster_id', $clusterId)),
            'workspaces' => $grouped(Workspace::where('cluster_id', $clusterId)),
            'webGateway' => $grouped(ClusterWebGateway::where('cluster_id', $clusterId)),
        ];
    }

    public function deleteCluster(int $uid, int $clusterId, int $orgId): void
    {
        Db::transaction(function () use ($clusterId, $orgId): void {
            /** @var null|self $cluster */
            $cluster = self::where('id', $clusterId)->where('org_id', $orgId)
                ->where('orchestrator_type', self::ORCHESTRATOR_DOCKER_SWARM)
                ->lockForUpdate()->first();
            if ($cluster === null) {
                throw new AppException(404, 'Docker Swarm 集群不存在');
            }
            // Release creation locks the same relation row. Taking this lock
            // before rechecking blockers closes the cluster-delete race.
            EnvClusterRel::where('org_id', $orgId)->where('cluster_id', $clusterId)
                ->lockForUpdate()->get(['env_id']);
            if (ProjectRuntime::where('cluster_id', $clusterId)->exists()) {
                throw new AppException(422, '集群中仍有项目运行实例，请先删除');
            }
            if (Pipeline::where('cluster_id', $clusterId)->where('archived_at', 0)->exists()) {
                throw new AppException(422, '集群仍被活动 BuildKit 流水线引用，请先修改或归档流水线');
            }
            $clusterBuilds = Build::whereRaw(
                "CAST(JSON_UNQUOTE(JSON_EXTRACT(`spec_snapshot`, '$.cluster_id')) AS UNSIGNED) = ?",
                [$clusterId]
            )->where('executor', 'buildkit');
            if ((clone $clusterBuilds)->whereIn('status', [Build::STATUS_PENDING_RUN, Build::STATUS_RUNNING])->exists()) {
                throw new AppException(422, '集群中仍有等待或运行中的 BuildKit 构建，请先取消并等待结束');
            }
            if ((clone $clusterBuilds)
                ->whereIn('status', [Build::STATUS_SUCCESS, Build::STATUS_FAILED, Build::STATUS_CANCELED])
                ->where(function ($query): void {
                    $query->whereNull('result')
                        ->orWhereRaw("JSON_EXTRACT(`result`, '$.runner_reconciled_at') IS NULL");
                })->exists()) {
                throw new AppException(422, '集群中仍有尚未完成远端资源对账的构建，请等待治理任务完成');
            }
            if (ProjectRelease::where('cluster_id', $clusterId)
                ->whereIn('status', [ProjectRelease::STATUS_PENDING, ProjectRelease::STATUS_DEPLOYING])->exists()) {
                throw new AppException(422, '集群中仍有等待或运行中的项目发布，请等待发布结束');
            }
            if (ProjectRelease::where('cluster_id', $clusterId)->where('status', ProjectRelease::STATUS_FAILED)
                ->where(function ($query): void {
                    $query->whereNull('result')
                        ->orWhereRaw("JSON_EXTRACT(`result`, '$.failure_reconciled_at') IS NULL");
                })->exists()) {
                throw new AppException(422, '集群中仍有尚未完成远端资源对账的失败发布，请等待治理任务完成');
            }
            if (Workspace::where('cluster_id', $clusterId)->exists()) {
                throw new AppException(422, '集群中仍有开发工作区，请先提交并推送代码后删除');
            }
            if (ProjectRoute::where('cluster_id', $clusterId)->exists()) {
                throw new AppException(422, '集群中仍有 Traefik 域名路由，请先删除');
            }
            if (ClusterWebGateway::where('cluster_id', $clusterId)->exists()) {
                throw new AppException(422, '集群中仍有托管 Web 网关，请先卸载');
            }
            EnvClusterRel::where('cluster_id', $clusterId)->delete();
            ClusterAgentNode::where('cluster_id', $clusterId)->delete();
            ClusterAgentCredential::where('cluster_id', $clusterId)->delete();
            // Legacy rows may still exist until a later schema cleanup migration.
            Db::table('cluster_docker_credential')->where('cluster_id', $clusterId)->delete();
            Db::table('docker_agent')->where('cluster_id', $clusterId)->delete();
            GroupResourceGrant::where('org_id', $orgId)
                ->where('resource_type', GroupResourceGrant::TYPE_CLUSTER)
                ->where('resource_id', $clusterId)->delete();
            Db::table('cluster_event_cursor')->where('cluster_id', $clusterId)->delete();
            $cluster->delete();
        });
    }

    public function simpleProfile(int $orgId, int $clusterId)
    {
        return $this->requiredAny($clusterId, $orgId)
            ->select(
                'id',
                'org_id',
                'title',
                'vendor',
                'type',
                'resolve',
                'endpoint',
                'orchestrator_type',
                'remark',
                'status',
                'version'
            )->first();
    }

    public function profile(int $clusterId, int $orgId)
    {
        $cluster = $this->requiredAny($clusterId, $orgId)
            ->select(
                'id',
                'org_id',
                'vendor',
                'type',
                'title',
                'resolve',
                'endpoint',
                'orchestrator_type',
                'remark',
                'status',
                'source',
                'creator',
                'created_at',
                'version',
                'extra'
            )
            ->with(['creatorInfo' => static fn ($query) => $query->where('org_id', $orgId)])
            ->with(['envs' => static fn ($query) => $query->where('env_cluster_rel.org_id', $orgId)])->first();
        $cluster->account_id = null;
        unset($cluster['extra']);
        return $cluster;
    }

    public function getCluster(int $clusterId, int $orgId): self
    {
        return $this->getClusterForOrchestrator($clusterId, $orgId, self::ORCHESTRATOR_DOCKER_SWARM);
    }

    public function getClusterForOrchestrator(int $clusterId, int $orgId, string $orchestratorType): self
    {
        if (! in_array($orchestratorType, self::knownOrchestrators(), true)) {
            throw new AppException(422, '不支持的集群编排类型');
        }
        $cluster = $this->requiredForOrchestrator($clusterId, $orgId, $orchestratorType)
            ->with('envs')->first();
        if (! $cluster instanceof self) {
            throw new AppException(404, '集群不存在');
        }
        return $cluster;
    }

    public function clusterExists(int $orgId, int $clusterId): bool
    {
        return self::where('id', $clusterId)->where('org_id', $orgId)->exists();
    }

    public function bindEnvs(int $orgId, int $clusterId, array $envIds): void
    {
        $cluster = $this->getCluster($clusterId, $orgId);
        $this->validEnvIds($orgId, $envIds);
        $envIds = array_values(array_unique(array_map('intval', $envIds)));
        Db::transaction(function () use ($orgId, $clusterId, $cluster, $envIds): void {
            $currentEnvIds = EnvClusterRel::where('org_id', $orgId)->where('cluster_id', $clusterId)
                ->lockForUpdate()->pluck('env_id')->map(static fn ($id): int => (int) $id)->all();
            $removedEnvIds = array_values(array_diff($currentEnvIds, $envIds));
            if ($removedEnvIds !== [] && ProjectRuntime::where('org_id', $orgId)->where('cluster_id', $clusterId)
                ->whereIn('env_id', $removedEnvIds)->exists()) {
                throw new AppException(409, '该集群仍承载被移除环境的项目运行实例，不能解除关联');
            }
            if ($removedEnvIds !== [] && ProjectRelease::where('org_id', $orgId)->where('cluster_id', $clusterId)
                ->whereIn('env_id', $removedEnvIds)
                ->whereIn('status', [ProjectRelease::STATUS_PENDING, ProjectRelease::STATUS_DEPLOYING])->exists()) {
                throw new AppException(409, '该集群仍有被移除环境的发布任务，不能解除关联');
            }
            EnvClusterRel::where('org_id', $orgId)->where('cluster_id', $clusterId)->delete();
            $rows = array_map(static fn ($envId): array => [
                'org_id' => $orgId, 'cluster_id' => (int) $cluster->id, 'env_id' => (int) $envId,
            ], $envIds);
            if ($rows !== []) {
                Db::table('env_cluster_rel')->insert($rows);
            }
        });
    }

    public function envs()
    {
        return $this->belongsToMany(Env::class, 'env_cluster_rel', 'cluster_id', 'env_id')
            ->select('env.id', 'env.title');
    }

    public function agentNodes()
    {
        return $this->hasMany(ClusterAgentNode::class, 'cluster_id', 'id');
    }

    public function kubernetesConnection()
    {
        return $this->hasOne(KubernetesClusterConnection::class, 'cluster_id', 'id');
    }

    private function requiredAny(int $clusterId, int $orgId)
    {
        $query = self::where('id', $clusterId)->where('org_id', $orgId);
        if (! $query->exists()) {
            throw new AppException(404, '集群不存在');
        }
        return $query;
    }

    private function requiredForOrchestrator(int $clusterId, int $orgId, string $orchestratorType)
    {
        $query = self::where('id', $clusterId)->where('org_id', $orgId)
            ->where('orchestrator_type', $orchestratorType);
        if (! $query->exists()) {
            throw new AppException(404, sprintf('%s 集群不存在', $orchestratorType));
        }
        return $query;
    }

    private function validEnvIds(int $orgId, array $envIds): void
    {
        if ($envIds === []) {
            return;
        }
        $count = Env::where('org_id', $orgId)->whereIn('id', $envIds)->distinct()->count('id');
        if ($count !== count(array_unique(array_map('intval', $envIds)))) {
            throw new AppException(ErrorCode::INVALID_PARAMS, '包含不存在的环境');
        }
    }
}

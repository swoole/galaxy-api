<?php

namespace App\Services\Project;

use App\Exception\AppException;
use App\Model\Project;
use App\Model\ProjectRegistryRel;
use App\Model\Build;
use App\Model\Cluster;
use App\Model\Pipeline;
use App\Model\PipelineGithook;
use App\Model\GroupResourceGrant;
use App\Model\Registry;
use App\Model\RegistryGroupGrant;
use App\Support\MySQL;
use Hyperf\DbConnection\Db;

class ProjectPipelineService
{
    public function __construct(
        private PipelineDefinitionService $definitions,
        private PipelineSecretService $secrets
    ) {}

    public function options(int $orgId, int $groupId, int $projectId): array
    {
        $this->assertScope($orgId, $groupId, $projectId);
        $buildClusterId = $this->buildClusterId($orgId, $groupId, $projectId);
        $clusterIds = GroupResourceGrant::clusterIds($orgId, $groupId);
        $registryIds = ProjectRegistryRel::where('org_id', $orgId)->where('project_id', $projectId)
            ->pluck('registry_id')->map(static fn ($id): int => (int) $id)->all();
        $grantNamespaces = RegistryGroupGrant::where('org_id', $orgId)->where('group_id', $groupId)
            ->whereIn('registry_id', $registryIds ?: [0])->pluck('namespace', 'registry_id');
        $registries = Registry::where('org_id', $orgId)->whereIn('id', array_keys($grantNamespaces->all()) ?: [0])
            ->select('id', 'remark', 'address', 'namespace', 'proto', 'is_push')->orderBy('id')->get()
            ->map(static function (Registry $registry) use ($grantNamespaces): Registry {
                $registry->credential_namespace = (string) $registry->namespace;
                $registry->namespace = (string) $grantNamespaces[(int) $registry->getRawOriginal('id')];
                return $registry;
            });
        $defaultRegistry = $registries->first(static fn (Registry $registry): bool => (int) $registry->is_push === 1);
        return [
            'schema_version' => 'v1',
            'runner_kind' => 'buildkit',
            'default_cluster_id' => $buildClusterId,
            'default_yml' => $this->definitions->defaultYaml(),
            'default_registry' => $defaultRegistry,
            'clusters' => Cluster::where('org_id', $orgId)->where('id', $buildClusterId)
                ->whereIn('id', $clusterIds)
                ->whereIn('orchestrator_type', Cluster::buildableOrchestrators())
                ->where('status', Cluster::STATUS_READY)
                ->select('id', 'title', 'status', 'orchestrator_type')->orderBy('id')->get(),
            'registries' => $registries,
            'variables' => ['COMMIT_SHA', 'COMMIT_SHORT_SHA', 'BRANCH', 'BUILD_ID'],
        ];
    }

    public function create(
        int $uid,
        int $orgId,
        int $groupId,
        int $projectId,
        string $title,
        string $remark,
        string $yaml,
        int $clusterId
    ): Pipeline {
        $this->assertScope($orgId, $groupId, $projectId);
        $clusterId = $this->buildClusterId($orgId, $groupId, $projectId, $clusterId);
        $this->assertCluster($orgId, $groupId, $clusterId);
        $definition = $this->definitions->parse($yaml);
        $this->assertRegistry($orgId, $groupId, $projectId, $definition);
        $now = time();
        return Pipeline::create([
            'org_id' => $orgId,
            'group_id' => $groupId,
            'project_id' => $projectId,
            'title' => $title,
            'remark' => $remark,
            'yml' => $yaml,
            'schema_version' => 'v1',
            'definition' => $definition,
            'runner_kind' => 'buildkit',
            'cluster_id' => $clusterId,
            'version' => 1,
            'creator' => $uid,
            'created_at' => $now,
            'updated_at' => $now,
            'is_default' => 0,
        ]);
    }

    /**
     * Every source project starts with one usable BuildKit pipeline.
     * Existing active pipelines always win and are never overwritten.
     */
    public function ensureDefault(int $uid, int $orgId, int $groupId, int $projectId): Pipeline
    {
        $this->assertScope($orgId, $groupId, $projectId);
        /** @var Pipeline|null $existing */
        $existing = Pipeline::where('org_id', $orgId)->where('group_id', $groupId)
            ->where('project_id', $projectId)->where('archived_at', 0)->orderBy('id')->first();
        if ($existing !== null) {
            return $existing;
        }
        $clusterId = $this->buildClusterId($orgId, $groupId, $projectId);
        $this->assertCluster($orgId, $groupId, $clusterId);
        $yaml = $this->definitions->defaultYaml();
        $now = time();
        return Pipeline::create([
            'org_id' => $orgId,
            'group_id' => $groupId,
            'project_id' => $projectId,
            'title' => '默认镜像构建',
            'remark' => '系统自动创建：获取 Git 源码、使用 BuildKit 构建并推送 OCI 镜像',
            'yml' => $yaml,
            'schema_version' => 'v1',
            'definition' => $this->definitions->parse($yaml),
            'runner_kind' => 'buildkit',
            'cluster_id' => $clusterId,
            'version' => 1,
            'creator' => $uid,
            'created_at' => $now,
            'updated_at' => $now,
            'archived_at' => 0,
            'is_default' => 1,
        ]);
    }

    public function update(
        int $uid,
        int $pipelineId,
        int $orgId,
        int $groupId,
        int $projectId,
        string $title,
        string $remark,
        string $yaml,
        int $clusterId
    ): Pipeline {
        $this->assertScope($orgId, $groupId, $projectId);
        $clusterId = $this->buildClusterId($orgId, $groupId, $projectId, $clusterId);
        $this->assertCluster($orgId, $groupId, $clusterId);
        $definition = $this->definitions->parse($yaml);
        $this->assertRegistry($orgId, $groupId, $projectId, $definition);
        return Db::transaction(function () use (
            $pipelineId, $orgId, $groupId, $projectId, $title, $remark, $yaml, $definition, $clusterId
        ): Pipeline {
            $pipeline = $this->required($pipelineId, $orgId, $groupId, $projectId, true);
            $pipeline->title = $title;
            $pipeline->remark = $remark;
            $pipeline->yml = $yaml;
            $pipeline->schema_version = 'v1';
            $pipeline->definition = $definition;
            $pipeline->runner_kind = 'buildkit';
            $pipeline->cluster_id = $clusterId;
            $pipeline->version = (int) $pipeline->version + 1;
            $pipeline->updated_at = time();
            $pipeline->save();
            $this->secrets->prune($pipeline);
            return $pipeline;
        });
    }

    public function delete(int $pipelineId, int $orgId, int $groupId, int $projectId): void
    {
        Db::transaction(function () use ($pipelineId, $orgId, $groupId, $projectId): void {
            /** @var Pipeline|null $pipeline */
            $pipeline = Pipeline::where('id', $pipelineId)->where('org_id', $orgId)
                ->where('group_id', $groupId)->where('project_id', $projectId)
                ->where('archived_at', 0)->lockForUpdate()->first();
            if ($pipeline === null) {
                throw new AppException(404, '流水线不存在');
            }
            if (Build::where('pipeline_id', $pipelineId)
                ->whereIn('status', [Build::STATUS_PENDING_RUN, Build::STATUS_RUNNING])->exists()) {
                throw new AppException(409, '流水线仍有等待或正在执行的构建');
            }
            PipelineGithook::where('pipeline_id', $pipelineId)->delete();
            $this->secrets->purgePipeline($pipelineId);
            $pipeline->archived_at = time();
            $pipeline->updated_at = time();
            $pipeline->save();
        });
    }

    public function profile(int $pipelineId, int $orgId, int $groupId, int $projectId): Pipeline
    {
        return $this->required($pipelineId, $orgId, $groupId, $projectId)
            ->load(['creatorInfo' => fn ($query) => $query->where('org_id', $orgId)]);
    }

    public function list(
        int $orgId,
        int $groupId,
        int $projectId,
        ?string $keyword,
        int $page,
        int $pageSize
    ): array {
        $builder = Pipeline::where('org_id', $orgId)->where('group_id', $groupId)->where('project_id', $projectId)
            ->where('archived_at', 0)
            ->select(
                'id', 'org_id', 'group_id', 'project_id', 'title', 'remark', 'schema_version',
                'runner_kind', 'cluster_id', 'is_default', 'creator', 'created_at', 'updated_at'
            )
            ->with(['creatorInfo' => fn ($query) => $query->where('org_id', $orgId)])
            ->orderByDesc('id');
        if ($keyword !== null && $keyword !== '') {
            $builder->where(function ($query) use ($keyword): void {
                $query->where('title', 'like', '%' . $keyword . '%');
                if (ctype_digit($keyword)) {
                    $query->orWhere('id', (int) $keyword);
                }
            });
        }
        return MySQL::jsonPaginate($builder, $page, $pageSize);
    }

    public function simple(int $orgId, int $groupId, int $projectId, ?string $keyword): array
    {
        $query = Pipeline::where('org_id', $orgId)->where('group_id', $groupId)->where('project_id', $projectId)
            ->where('archived_at', 0)
            ->select('id', 'title', 'remark', 'runner_kind', 'cluster_id', 'is_default')
            ->orderByDesc('id')->limit(20);
        if ($keyword !== null && $keyword !== '') {
            $query->where('title', 'like', '%' . $keyword . '%');
        }
        return $query->get()->all();
    }

    private function required(
        int $pipelineId,
        int $orgId,
        int $groupId,
        int $projectId,
        bool $lockForUpdate = false
    ): Pipeline
    {
        /** @var Pipeline|null $pipeline */
        $query = Pipeline::where('id', $pipelineId)->where('org_id', $orgId)
            ->where('group_id', $groupId)->where('project_id', $projectId)->where('archived_at', 0);
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }
        $pipeline = $query->first();
        if ($pipeline === null) {
            throw new AppException(404, '流水线不存在');
        }
        return $pipeline;
    }

    private function assertScope(int $orgId, int $groupId, int $projectId): void
    {
        if (! Project::where('id', $projectId)->where('org_id', $orgId)->where('group_id', $groupId)
            ->where('develop', 1)->exists()) {
            throw new AppException(409, '该项目使用已有镜像模式，未启用源码构建与 Pipeline');
        }
    }

    private function assertCluster(int $orgId, int $groupId, int $clusterId): void
    {
        if (! GroupResourceGrant::canUseCluster($orgId, $groupId, $clusterId)) {
            throw new AppException(403, '当前项目组未获授权使用该构建集群');
        }
        if (! Cluster::where('id', $clusterId)->where('org_id', $orgId)
            ->whereIn('orchestrator_type', Cluster::buildableOrchestrators())
            ->where('status', Cluster::STATUS_READY)->exists()) {
            throw new AppException(409, 'BuildKit 构建集群不存在、当前不在线或编排类型不受支持');
        }
    }

    private function buildClusterId(int $orgId, int $groupId, int $projectId, int $requested = 0): int
    {
        $clusterId = (int) (Project::where('id', $projectId)->where('org_id', $orgId)
            ->where('group_id', $groupId)->value('build_cluster_id') ?? 0);
        if ($clusterId <= 0) {
            throw new AppException(409, '项目尚未设置 BuildKit 构建集群，请先修改项目设置');
        }
        if ($requested > 0 && $requested !== $clusterId) {
            throw new AppException(409, 'Pipeline 必须使用项目设置的 BuildKit 构建集群');
        }
        return $clusterId;
    }

    private function assertRegistry(int $orgId, int $groupId, int $projectId, array $definition): void
    {
        $registryId = (int) ($definition['output']['registry_id'] ?? 0);
        $query = Registry::where('org_id', $orgId);
        if ($registryId > 0) {
            $query->where('id', $registryId);
            if (! ProjectRegistryRel::where('org_id', $orgId)->where('project_id', $projectId)
                ->where('registry_id', $registryId)->exists()) {
                throw new AppException(422, 'Pipeline 指定的 Registry 未关联到当前项目，请先在项目设置中添加');
            }
        } else {
            $query->where('is_push', 1);
        }
        $registryId = $registryId > 0
            ? $registryId
            : (int) (clone $query)->value('id');
        if ($registryId <= 0 || ! $query->exists()) {
            throw new AppException(422, $registryId > 0
                ? 'Pipeline 指定的推送 Registry 不存在'
                : '组织尚未配置默认推送 Registry');
        }
        if (! RegistryGroupGrant::where('org_id', $orgId)->where('group_id', $groupId)
            ->where('registry_id', $registryId)->exists()) {
            throw new AppException(403, '当前项目组未获授权使用 Pipeline 指定的 Registry');
        }
    }
}

<?php

namespace App\Services\Project;

use App\Exception\AppException;
use App\Job\BuildKitBuildJob;
use App\Model\ProjectRepository;
use App\Model\Project;
use App\Model\ProjectRegistryRel;
use App\Model\Build;
use App\Model\BuildArtifact;
use App\Model\DockerfileTemplate;
use App\Model\Pipeline;
use App\Model\ProjectRelease;
use App\Model\Registry;
use App\Model\GroupResourceGrant;
use App\Services\RegistryService;
use App\Services\RegistryGroupGrantService;
use App\Services\AsyncQueue\DefaultQueueService;
use App\Services\Project\Build\BuildExecutorRegistry;
use App\Support\GitReference;
use App\Support\MySQL;
use Hyperf\DbConnection\Db;
use Throwable;

class ProjectBuildService
{
    public function __construct(
        private DefaultQueueService $queue,
        private BuildExecutorRegistry $executors,
        private PipelineSecretService $pipelineSecrets,
        private RegistryService $registries,
        private RegistryGroupGrantService $registryGrants,
        private ProjectBuildProfileService $buildProfiles,
        private BuildKitLogAnalyzerService $logAnalyzer
    ) {}

    public function list(
        int $orgId,
        int $groupId,
        int $projectId,
        ?int $pipelineId,
        ?int $status,
        ?int $hookId,
        int $page,
        int $pageSize
    ): array {
        $query = Build::where('org_id', $orgId)->where('group_id', $groupId)->where('project_id', $projectId)
            ->select(
                'id', 'org_id', 'group_id', 'project_id', 'pipeline_id', 'executor', 'runner_ref', 'hook_id',
                'branch', 'commit_id', 'source_revision', 'remark', 'status', 'cancel_requested', 'error',
                'start_at', 'end_at', 'creator', 'created_at', 'updated_at', 'image_id', 'result'
            )
            ->with('pipeline')->with('artifacts')
            ->with(['creatorInfo' => fn ($builder) => $builder->where('org_id', $orgId)])
            ->orderByDesc('id');
        if ($pipelineId !== null && $pipelineId > 0) {
            $query->where('pipeline_id', $pipelineId);
        }
        if ($status !== null) {
            $query->where('status', $status);
        }
        if ($hookId !== null && $hookId > 0) {
            $query->where('hook_id', $hookId);
        }
        return MySQL::jsonPaginate($query, $page, $pageSize);
    }

    public function artifactProfile(int $orgId, int $groupId, int $projectId, int $artifactId): array
    {
        /** @var BuildArtifact|null $artifact */
        $artifact = BuildArtifact::where('id', $artifactId)
            ->where('org_id', $orgId)
            ->where('group_id', $groupId)
            ->where('project_id', $projectId)
            ->first();
        if ($artifact === null) {
            throw new AppException(404, '镜像制品不存在');
        }

        $build = Build::where('id', (int) $artifact->build_id)
            ->where('org_id', $orgId)
            ->where('group_id', $groupId)
            ->where('project_id', $projectId)
            ->select(
                'id', 'org_id', 'group_id', 'project_id', 'pipeline_id', 'executor',
                'branch', 'commit_id', 'source_revision', 'remark', 'status', 'error',
                'start_at', 'end_at', 'creator', 'created_at', 'updated_at', 'spec_snapshot'
            )
            ->with('pipeline')
            ->with(['creatorInfo' => fn ($builder) => $builder->where('org_id', $orgId)])
            ->first();

        $profile = (array) (($build?->spec_snapshot ?? [])['build_profile'] ?? []);
        if (($profile['dockerfile_source'] ?? '') === 'template' && (int) ($profile['template_id'] ?? 0) > 0) {
            $template = DockerfileTemplate::where('id', (int) $profile['template_id'])->first([
                'template_key', 'title', 'language', 'framework',
            ]);
            if ($template !== null) {
                $artifact->setAttribute('template', [
                    'key' => (string) $template->template_key,
                    'title' => (string) $template->title,
                    'language' => (string) $template->language,
                    'framework' => (string) $template->framework,
                    'runtime_version' => (string) ($profile['options']['runtime_version'] ?? ''),
                    'framework_version' => (string) ($profile['options']['framework_version'] ?? ''),
                ]);
            }
        }
        $build?->makeHidden(['spec_snapshot']);

        $releaseQuery = ProjectRelease::where('org_id', $orgId)
            ->where('group_id', $groupId)
            ->where('project_id', $projectId)
            ->where('artifact_id', $artifactId);
        $releaseTotal = (clone $releaseQuery)->count();
        $releases = $releaseQuery->with('runtime')->with('env')->with('cluster')
            ->with(['creatorInfo' => fn ($builder) => $builder->where('org_id', $orgId)])
            ->orderByDesc('id')->limit(100)->get();

        return [
            'artifact' => $artifact,
            'build' => $build,
            'releases' => $releases,
            'release_total' => $releaseTotal,
        ];
    }

    public function artifacts(int $orgId, int $groupId, int $projectId, int $page, int $pageSize): array
    {
        $query = BuildArtifact::where('org_id', $orgId)
            ->where('group_id', $groupId)
            ->where('project_id', $projectId)
            ->with(['build' => fn ($builder) => $builder
                ->select('id', 'pipeline_id', 'executor', 'branch', 'commit_id', 'remark', 'spec_snapshot', 'creator', 'created_at')
                ->with('pipeline')
                ->with(['creatorInfo' => fn ($creator) => $creator->where('org_id', $orgId)])])
            ->orderByDesc('id');
        $data = MySQL::jsonPaginate($query, $page, $pageSize);
        $templateIds = [];
        foreach ($data['data'] as $artifact) {
            $profile = (array) (($artifact->build?->spec_snapshot ?? [])['build_profile'] ?? []);
            if (($profile['dockerfile_source'] ?? '') === 'template' && (int) ($profile['template_id'] ?? 0) > 0) {
                $templateIds[] = (int) $profile['template_id'];
            }
        }
        $templates = DockerfileTemplate::whereIn('id', array_values(array_unique($templateIds)))
            ->get(['id', 'template_key', 'template_version', 'title', 'language', 'framework', 'runtime_versions', 'framework_versions'])
            ->keyBy('id');
        foreach ($data['data'] as $artifact) {
            $profile = (array) (($artifact->build?->spec_snapshot ?? [])['build_profile'] ?? []);
            $template = $templates->get((int) ($profile['template_id'] ?? 0));
            $artifact->build?->makeHidden(['spec_snapshot']);
            $artifact->setAttribute('template', $template === null ? null : [
                'key' => (string) $template->template_key,
                'title' => (string) $template->title,
                'language' => (string) $template->language,
                'framework' => (string) $template->framework,
                'runtime_version' => (string) ($profile['options']['runtime_version'] ?? ''),
                'framework_version' => (string) ($profile['options']['framework_version'] ?? ''),
            ]);
        }
        return $data;
    }

    public function create(
        int $uid,
        int $orgId,
        int $groupId,
        int $projectId,
        int $pipelineId,
        string $remark,
        string $branch,
        string $commitId,
        int $hookId = 0,
        int $credentialUid = 0,
        string $revisionType = 'commit'
    ): Build {
        $branch = trim($branch);
        $commitId = trim($commitId);
        $revisionType = strtolower(trim($revisionType));
        if (! in_array($revisionType, ['commit', 'tag'], true)) {
            throw new AppException(422, 'Git 版本类型不合法');
        }
        if ($revisionType === 'commit') {
            if (! GitReference::validBranch($branch)) {
                throw new AppException(422, 'Git 分支名称不合法');
            }
            if ($commitId === '' || ! preg_match('/^[a-f0-9]{7,64}$/i', $commitId)) {
                throw new AppException(422, 'Git Commit ID 格式不合法');
            }
        } else {
            if (! GitReference::validTag($commitId) || $commitId !== $branch) {
                throw new AppException(422, 'Git Tag 名称不合法');
            }
        }
        $triggerKey = $hookId > 0 ? 'hook:' . $hookId . ':commit:' . strtolower($commitId) : null;
        if ($triggerKey !== null) {
            /** @var Build|null $existing */
            $existing = Build::where('trigger_key', $triggerKey)->first();
            if ($existing !== null) {
                return $existing;
            }
        }
        try {
            $build = Db::transaction(function () use (
                $uid, $orgId, $groupId, $projectId, $pipelineId, $hookId, $branch, $commitId,
                $remark, $triggerKey, $credentialUid, $revisionType
            ): Build {
                // Serialize against pipeline archival. Once this row is locked,
                // delete() cannot archive it until the Build row is committed.
                /** @var Pipeline|null $pipeline */
                $pipeline = Pipeline::where('id', $pipelineId)->where('org_id', $orgId)
                    ->where('group_id', $groupId)->where('project_id', $projectId)
                    ->where('archived_at', 0)->lockForUpdate()->first();
                if ($pipeline === null || (array) $pipeline->definition === []) {
                    throw new AppException(404, 'BuildKit 流水线不存在、已归档或尚未迁移到 v1');
                }
                if (! GroupResourceGrant::canUseCluster($orgId, $groupId, (int) $pipeline->cluster_id)) {
                    throw new AppException(403, '当前项目组已无权使用 Pipeline 指定的构建集群');
                }
                /** @var ProjectRepository|null $repository */
                $repository = ProjectRepository::where('project_id', $projectId)->where('org_id', $orgId)
                    ->where('group_id', $groupId)->where('status', ProjectRepository::STATUS_ACTIVE)
                    ->lockForUpdate()->first();
                if ($repository === null || trim((string) $repository->clone_url) === '') {
                    throw new AppException(422, '项目尚未配置代码仓库');
                }
                $registry = $this->buildRegistry($orgId, $groupId, $projectId, (array) $pipeline->definition);
                $now = time();
                $build = Build::create([
                    'org_id' => $orgId,
                    'group_id' => $groupId,
                    'project_id' => $projectId,
                    'pipeline_id' => $pipelineId,
                    'trigger_key' => $triggerKey,
                    'executor' => 'buildkit',
                    'runner_ref' => '',
                    'hook_id' => $hookId,
                    'branch' => $branch,
                    'commit_id' => $commitId,
                    'source_revision' => '',
                    'remark' => $remark,
                    'status' => Build::STATUS_PENDING_RUN,
                    'cancel_requested' => 0,
                    'spec_snapshot' => [
                        'schema_version' => (string) $pipeline->schema_version,
                        'pipeline_version' => (int) $pipeline->version,
                        'runner_kind' => (string) $pipeline->runner_kind,
                        'cluster_id' => (int) $pipeline->cluster_id,
                        'credential_uid' => $credentialUid > 0 ? $credentialUid : $uid,
                        'revision_type' => $revisionType,
                        'repository' => [
                            'id' => (int) $repository->id,
                            'clone_url' => (string) $repository->clone_url,
                            'provider' => (int) $repository->provider,
                            'updated_at' => (int) $repository->updated_at,
                        ],
                        'registry' => [
                            // Registry exposes a HashID through getIdAttribute() for HTTP responses.
                            // Build snapshots must persist the real database foreign key.
                            'id' => (int) $registry->getAttr('id'),
                            'address' => (string) $registry->address,
                            'namespace' => (string) $registry->namespace,
                            'proto' => (int) $registry->proto,
                        ],
                        'definition' => (array) $pipeline->definition,
                        'build_profile' => $this->buildProfiles->snapshot($orgId, $groupId, $projectId),
                        'build_secret_hashes' => [],
                    ],
                    'result' => null,
                    'log' => '',
                    'creator' => $uid,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $snapshot = (array) $build->spec_snapshot;
                $snapshot['build_secret_hashes'] = $this->pipelineSecrets->freeze($build, $pipeline);
                $build->spec_snapshot = $snapshot;
                $build->save();
                return $build;
            });
        } catch (Throwable $e) {
            if ($triggerKey !== null) {
                /** @var Build|null $existing */
                $existing = Build::where('trigger_key', $triggerKey)->first();
                if ($existing !== null) {
                    return $existing;
                }
            }
            throw $e;
        }
        try {
            $queued = $this->queue->push(new BuildKitBuildJob((int) $build->id));
        } catch (Throwable $e) {
            $queued = false;
        }
        if (! $queued) {
            $build->status = Build::STATUS_FAILED;
            $build->trigger_key = null;
            $build->result = ['runner_reconciled_at' => time()];
            $build->error = '无法提交 BuildKit 构建任务';
            $build->end_at = time();
            $build->updated_at = time();
            $build->save();
            $this->pipelineSecrets->purgeBuild((int) $build->id);
            throw new AppException(503, '构建队列暂不可用');
        }
        return $build;
    }

    private function buildRegistry(int $orgId, int $groupId, int $projectId, array $definition): Registry
    {
        $registryId = (int) ($definition['output']['registry_id'] ?? 0);
        $query = Registry::where('org_id', $orgId);
        if ($registryId > 0) {
            if (! ProjectRegistryRel::where('org_id', $orgId)->where('project_id', $projectId)
                ->where('registry_id', $registryId)->exists()) {
                throw new AppException(422, 'Pipeline 指定的 Registry 未关联到当前项目');
            }
            $query->where('id', $registryId);
        } else {
            $query->where('is_push', 1);
        }
        /** @var Registry|null $registry */
        $registry = $query->lockForUpdate()->first();
        if ($registry === null) {
            throw new AppException(422, 'Pipeline 的镜像输出 Registry 不存在');
        }
        return $this->registryGrants->apply($registry, $groupId);
    }

    public function rebuild(int $uid, int $orgId, int $groupId, int $projectId, int $buildId): Build
    {
        $previous = $this->required($buildId, $orgId, $groupId, $projectId);
        if (in_array((int) $previous->status, [Build::STATUS_PENDING_RUN, Build::STATUS_RUNNING], true)) {
            throw new AppException(409, '等待或运行中的构建不能重复提交');
        }
        $previousRemark = preg_replace('/^(?:重新构建：)+/u', '', (string) $previous->remark);
        return $this->create(
            $uid, $orgId, $groupId, $projectId, (int) $previous->pipeline_id,
            '重新构建：' . (string) $previousRemark,
            (string) $previous->branch,
            preg_match('/^[a-f0-9]{7,64}$/i', (string) $previous->source_revision)
                ? (string) $previous->source_revision
                : (string) $previous->commit_id,
            0,
            0,
            'commit'
        );
    }

    public function importArtifact(
        int $uid,
        int $orgId,
        int $groupId,
        int $projectId,
        int $registryId,
        string $repository,
        string $tag,
        string $remark,
        bool $qualifyImageName = false
    ): array {
        if (! Project::where('id', $projectId)->where('org_id', $orgId)->where('group_id', $groupId)->exists()) {
            throw new AppException(404, '项目不存在');
        }
        /** @var Registry|null $registry */
        $registry = Registry::where('id', $registryId)->where('org_id', $orgId)->first();
        if ($registry === null) {
            throw new AppException(404, 'Registry 不存在');
        }
        if (! ProjectRegistryRel::where('org_id', $orgId)->where('project_id', $projectId)
            ->where('registry_id', $registryId)->exists()) {
            throw new AppException(422, '该 Registry 未关联到当前项目，请先在项目设置中添加');
        }
        $registry = $this->registryGrants->apply($registry, $groupId);
        $repository = strtolower(trim($repository, " \t\n\r\0\x0B/"));
        $tag = trim($tag);
        $allowedNamespace = trim((string) $registry->namespace, '/');
        if ($qualifyImageName) {
            if (! preg_match('/^[a-z0-9]+(?:[._-][a-z0-9]+)*$/', $repository)) {
                throw new AppException(422, '镜像名称不合法，只能填写不含 namespace 的镜像名');
            }
            $repository = implode('/', array_filter(
                [$allowedNamespace, $repository],
                static fn (string $part): bool => $part !== ''
            ));
        } else {
            if (! preg_match('#^[a-z0-9]+(?:[._/-][a-z0-9]+)*$#', $repository)) {
                throw new AppException(422, '镜像 Repository 路径不合法');
            }
            if ($repository !== $allowedNamespace && ! str_starts_with($repository, $allowedNamespace . '/')) {
                throw new AppException(403, sprintf('Repository 必须位于项目组 namespace「%s」之下', $allowedNamespace));
            }
        }
        if (! preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.-]{0,127}$/', $tag)) {
            throw new AppException(422, '镜像 Tag 不合法');
        }
        $reference = implode('/', array_filter([
            trim((string) $registry->address, '/'), $repository,
        ], static fn (string $part): bool => $part !== '')) . ':' . $tag;
        $this->assertArtifactReferenceAvailable($orgId, $groupId, $projectId, $reference);
        $manifest = $this->registries->inspectManifest($registry, $repository, $tag);
        if (! preg_match('/^sha256:[a-f0-9]{64}$/i', (string) ($manifest['digest'] ?? ''))) {
            throw new AppException(502, 'Registry 未返回有效的 OCI 镜像 Digest');
        }
        $now = time();
        return Db::transaction(function () use (
            $uid, $orgId, $groupId, $projectId, $registryId, $repository, $tag,
            $remark, $manifest, $reference, $now
        ): array {
            if (Project::where('id', $projectId)->where('org_id', $orgId)->where('group_id', $groupId)
                ->lockForUpdate()->first(['id']) === null) {
                throw new AppException(404, '项目不存在');
            }
            // Serialize imports for this project so concurrent submissions
            // cannot both pass the duplicate check and create two artifacts.
            $this->assertArtifactReferenceAvailable($orgId, $groupId, $projectId, $reference);
            $revision = substr((string) preg_replace('/^sha256:/', '', (string) $manifest['digest']), 0, 64);
            $build = Build::create([
                'org_id' => $orgId, 'group_id' => $groupId, 'project_id' => $projectId,
                'pipeline_id' => 0, 'trigger_key' => null, 'executor' => 'external-image',
                'runner_ref' => '', 'hook_id' => 0, 'branch' => 'registry-import',
                'commit_id' => $revision, 'source_revision' => $revision, 'remark' => $remark,
                'status' => Build::STATUS_SUCCESS, 'cancel_requested' => 0,
                'spec_snapshot' => [
                    'source' => 'registry-import', 'registry_id' => $registryId,
                    'repository' => $repository, 'tag' => $tag,
                ],
                'result' => ['imported' => true, 'manifest' => $manifest],
                'log' => '已通过 Registry Manifest API 校验并登记已有镜像：' . $reference,
                'error' => null, 'start_at' => $now, 'end_at' => $now,
                'creator' => $uid, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $artifact = BuildArtifact::create([
                'org_id' => $orgId, 'group_id' => $groupId, 'project_id' => $projectId,
                'build_id' => (int) $build->id, 'type' => BuildArtifact::TYPE_CONTAINER_IMAGE,
                'reference' => $reference, 'digest' => (string) $manifest['digest'],
                'size' => (int) $manifest['size'],
                'metadata' => [
                    'source' => 'registry-import', 'registry_id' => $registryId,
                    'media_type' => (string) $manifest['media_type'],
                    'platforms' => (array) $manifest['platforms'],
                    'attestations' => ['sbom' => false, 'provenance' => false],
                ],
                'created_at' => $now,
            ]);
            return ['build' => $build, 'artifact' => $artifact];
        });
    }

    private function assertArtifactReferenceAvailable(
        int $orgId,
        int $groupId,
        int $projectId,
        string $reference
    ): void {
        $existing = BuildArtifact::where('org_id', $orgId)
            ->where('group_id', $groupId)
            ->where('project_id', $projectId)
            ->where('reference', $reference)
            ->orderBy('id')
            ->first(['id']);
        if ($existing !== null) {
            throw new AppException(409, sprintf(
                '该 OCI 镜像已登记为制品 #%d，不能重复添加',
                (int) $existing->id
            ));
        }
    }

    public function cancel(int $orgId, int $groupId, int $projectId, int $buildId): void
    {
        $build = $this->required($buildId, $orgId, $groupId, $projectId);
        if (! in_array((int) $build->status, [Build::STATUS_PENDING_RUN, Build::STATUS_RUNNING], true)) {
            throw new AppException(409, '当前构建状态不能取消');
        }
        $build->cancel_requested = 1;
        $build->updated_at = time();
        if ((int) $build->status === Build::STATUS_PENDING_RUN) {
            $build->status = Build::STATUS_CANCELED;
            $build->error = '构建已取消';
            $build->end_at = time();
            $result = (array) ($build->result ?? []);
            $result['runner_reconciled_at'] = time();
            $result['termination_reason'] = 'user_canceled';
            $result['terminated_at'] = $build->end_at;
            $build->result = $result;
        }
        $build->save();
        if ((int) $build->status === Build::STATUS_RUNNING) {
            $this->executors->forBuild($build)->cancel($build);
            $now = time();
            $build->refresh();
            $result = (array) ($build->result ?? []);
            $result['termination_reason'] = 'user_canceled';
            $result['terminated_at'] = $now;
            Build::where('id', (int) $build->id)
                ->where('status', Build::STATUS_RUNNING)
                ->update([
                    'status' => Build::STATUS_CANCELED,
                    'result' => json_encode(
                        $result,
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                    ),
                    'error' => '构建已取消',
                    'end_at' => $now,
                    'updated_at' => $now,
                ]);
            $this->pipelineSecrets->purgeBuild((int) $build->id);
        } else {
            $this->pipelineSecrets->purgeBuild((int) $build->id);
        }
    }

    public function log(int $orgId, int $groupId, int $projectId, int $buildId): array
    {
        $build = $this->required($buildId, $orgId, $groupId, $projectId);
        return [
            'id' => (int) $build->id,
            'executor' => (string) $build->executor,
            'runner_ref' => (string) $build->runner_ref,
            'status' => (int) $build->status,
            'start_at' => (int) $build->start_at,
            'end_at' => (int) $build->end_at,
            'error' => (string) ($build->error ?? ''),
            'log' => (string) ($build->log ?? ''),
            'timing' => $this->logAnalyzer->analyze(
                (string) ($build->log ?? ''),
                (int) $build->start_at,
                (int) $build->end_at
            ),
            'result' => (array) ($build->result ?? []),
        ];
    }

    private function required(int $buildId, int $orgId, int $groupId, int $projectId): Build
    {
        /** @var Build|null $build */
        $build = Build::where('id', $buildId)->where('org_id', $orgId)
            ->where('group_id', $groupId)->where('project_id', $projectId)->first();
        if ($build === null) {
            throw new AppException(404, '构建记录不存在');
        }
        return $build;
    }
}

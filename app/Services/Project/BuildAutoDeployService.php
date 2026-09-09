<?php

namespace App\Services\Project;

use App\Model\Build;
use App\Model\BuildArtifact;
use App\Model\PipelineGithook;
use App\Model\ProjectRuntime;
use Throwable;

class BuildAutoDeployService
{
    public function __construct(private ProjectReleaseService $releases) {}

    public function deploy(Build $build): array
    {
        if ((int) $build->status !== Build::STATUS_SUCCESS || (int) $build->hook_id <= 0) {
            return ['enabled' => false, 'reason' => 'not-webhook-build'];
        }
        /** @var PipelineGithook|null $hook */
        $hook = PipelineGithook::where('id', (int) $build->hook_id)
            ->where('org_id', (int) $build->org_id)
            ->where('group_id', (int) $build->group_id)
            ->where('project_id', (int) $build->project_id)
            ->first(['id', 'auto_deploy', 'auto_deploy_target', 'creator']);
        if ($hook === null || ! (bool) $hook->auto_deploy) {
            return ['enabled' => false, 'reason' => $hook === null ? 'hook-deleted' : 'disabled'];
        }
        /** @var BuildArtifact|null $artifact */
        $artifact = BuildArtifact::where('build_id', (int) $build->id)->orderBy('id')->first(['id', 'reference', 'digest']);
        if ($artifact === null) {
            return ['enabled' => true, 'status' => 'failed', 'error' => '构建成功但没有生成镜像制品'];
        }
        $targetIds = array_values(array_unique(array_filter(array_map(
            'intval',
            explode(',', (string) $hook->auto_deploy_target)
        ))));
        if ($targetIds === []) {
            return ['enabled' => true, 'status' => 'failed', 'error' => '自动部署未配置目标实例'];
        }
        $runtimes = ProjectRuntime::where('org_id', (int) $build->org_id)
            ->where('group_id', (int) $build->group_id)
            ->where('project_id', (int) $build->project_id)
            ->whereIn('id', $targetIds)->get()->keyBy('id');
        $targets = [];
        foreach ($targetIds as $runtimeId) {
            $runtime = $runtimes->get($runtimeId);
            if ($runtime === null || (string) $runtime->runtime_ref === '') {
                $targets[] = ['runtime_id' => $runtimeId, 'status' => 'skipped', 'error' => '运行实例已不存在'];
                continue;
            }
            try {
                $release = $this->releases->updateArtifact(
                    (int) $hook->creator,
                    (int) $build->org_id,
                    (int) $build->group_id,
                    (int) $build->project_id,
                    $runtimeId,
                    (int) $artifact->id,
                    'Git Webhook 自动部署：构建 #' . (int) $build->id
                );
                $targets[] = [
                    'runtime_id' => $runtimeId,
                    'release_id' => (int) $release->id,
                    'status' => 'queued',
                ];
            } catch (Throwable $e) {
                $targets[] = [
                    'runtime_id' => $runtimeId,
                    'status' => 'failed',
                    'error' => mb_substr($e->getMessage(), 0, 1000),
                ];
            }
        }
        $failed = array_filter($targets, static fn (array $target): bool => $target['status'] !== 'queued');
        return [
            'enabled' => true,
            'status' => $failed === [] ? 'queued' : (count($failed) === count($targets) ? 'failed' : 'partial'),
            'artifact_id' => (int) $artifact->id,
            'artifact_reference' => (string) $artifact->reference,
            'targets' => $targets,
            'triggered_at' => time(),
        ];
    }
}

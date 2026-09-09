<?php

/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */
namespace App\Services;

use App\Constants\ErrorCode;
use App\Exception\AppException;
use App\Model\Project;
use App\Model\ProjectMember;
use App\Model\ProjectRepository;
use App\Model\ProjectRegistryRel;
use App\Model\ProjectRelease;
use App\Model\ProjectRoute;
use App\Model\ProjectRuntime;
use App\Model\Build;
use App\Model\BuildArtifact;
use App\Model\GatewayVhost;
use App\Model\Pipeline;
use Hyperf\Collection\Collection;
use App\Services\Project\ProjectServiceIdentity;
use App\Services\Project\RepositoryReferenceService;
use Throwable;

class ProjectService
{
    public function __construct(
        private RepositoryReferenceService $repositoryReferences
    ) {}

    /**
     * 项目名称 组织/项目内唯一
     * @param mixed $value
     * @param $org_id
     * @param mixed $group_id
     */
    public function uniqueTitle($value, $org_id, $group_id)
    {
        $existTitle = Project::where(['title' => $value, 'org_id' => $org_id, 'group_id' => $group_id])->exists();
        if ($existTitle) {
            throw new AppException(ErrorCode::PROJECT_TITLE_DUPLICATE, ErrorCode::getMessage(ErrorCode::PROJECT_TITLE_DUPLICATE));
        }
    }

    /**
     * 概览.
     * @param int $orgId
     * @param int $groupId
     * @param int $projectId
     */
    public function overview($uid, $orgId, $groupId, $projectId)
    {
        $project = Project::where('id', $projectId)->where('org_id', $orgId)->where('group_id', $groupId)
            ->select('id', 'develop', 'image_name')->first();
        if ($project === null) {
            throw new AppException(404, '项目不存在');
        }
        $repository = ProjectRepository::where('org_id', $orgId)->where('group_id', $groupId)
            ->where('project_id', $projectId)->first();
        $pipelineQuery = Pipeline::where('org_id', $orgId)->where('group_id', $groupId)
            ->where('project_id', $projectId);
        $pipelineTotal = (clone $pipelineQuery)->count();
        $pipelineActive = (clone $pipelineQuery)->where('archived_at', 0)->count();
        $runtimeQuery = ProjectRuntime::where('org_id', $orgId)
            ->where('group_id', $groupId)
            ->where('project_id', $projectId);
        $runtimeTargets = (clone $runtimeQuery)->where('name', '<>', '')
            ->get(['org_id', 'project_id', 'cluster_id', 'name', 'service_name']);
        $targetKeys = [];
        foreach ($runtimeTargets as $runtimeTarget) {
            $targetKeys[(int) $runtimeTarget->cluster_id . ':'
                . ProjectServiceIdentity::runtimeDockerName($runtimeTarget)] = true;
        }
        $gatewayRoutes = $targetKeys === []
            ? new Collection()
            : GatewayVhost::where('org_id', $orgId)
                ->whereIn('cluster_id', $runtimeTargets->pluck('cluster_id')->unique()->all())
                ->get(['cluster_id', 'target_service', 'enabled', 'tls_enabled', 'updated_at'])
                ->filter(static fn (GatewayVhost $route): bool => isset(
                    $targetKeys[(int) $route->cluster_id . ':' . (string) $route->target_service]
                ));
        $routeQuery = ProjectRoute::where('org_id', $orgId)->where('group_id', $groupId)
            ->where('project_id', $projectId);
        $routeTotal = (clone $routeQuery)->count() + $gatewayRoutes->count();
        $routeEnabled = (clone $routeQuery)->where('enabled', 1)->count()
            + $gatewayRoutes->where('enabled', true)->count();
        $routeTls = (clone $routeQuery)->where('enabled', 1)->where('tls_enabled', 1)->count()
            + $gatewayRoutes->where('enabled', true)->where('tls_enabled', true)->count();
        $artifactCount = BuildArtifact::where('org_id', $orgId)
            ->where('group_id', $groupId)
            ->where('project_id', $projectId)
            ->count();
        $registryCount = ProjectRegistryRel::where('org_id', $orgId)
            ->where('project_id', $projectId)
            ->count();
        $sourceConfigured = $project->develop
            ? $repository !== null
            : trim((string) $project->image_name) !== '' && $registryCount > 0;
        $deployedArtifactCount = ProjectRelease::where('org_id', $orgId)
            ->where('group_id', $groupId)
            ->where('project_id', $projectId)
            ->where('status', ProjectRelease::STATUS_SUCCEEDED)
            ->distinct()->count('artifact_id');
        $builds = Build::where('org_id', $orgId)->where('group_id', $groupId)->where('project_id', $projectId)
            ->selectRaw('status, COUNT(*) AS aggregate')->groupBy('status')->pluck('aggregate', 'status');
        $releaseStatuses = ProjectRelease::where('org_id', $orgId)
            ->where('group_id', $groupId)
            ->where('project_id', $projectId)
            ->selectRaw('status, COUNT(*) AS aggregate')->groupBy('status')->pluck('aggregate', 'status');
        $runtimeCount = (clone $runtimeQuery)->count();
        $desiredReplicas = (int) (clone $runtimeQuery)->sum('desired_count');
        $runningReplicas = (int) (clone $runtimeQuery)->sum('running_count');
        $unhealthyRuntimes = (clone $runtimeQuery)->where('health', 'unhealthy')->count();

        // 成员
        $memberCnt = ProjectMember::where('org_id', $orgId)
            ->where('group_id', $groupId)
            ->where('project_id', $projectId)
            ->count();
        $lastBuild = (int) Build::where('org_id', $orgId)
            ->where('group_id', $groupId)
            ->where('project_id', $projectId)
            ->max('updated_at');
        $lastRelease = (int) ProjectRelease::where('org_id', $orgId)
            ->where('group_id', $groupId)->where('project_id', $projectId)->max('updated_at');
        $lastRoute = max(
            (int) (clone $routeQuery)->max('updated_at'),
            (int) ($gatewayRoutes->max('updated_at') ?? 0)
        );
        $deliverySince = time() - 30 * 86400;
        $recentBuilds = Build::where('org_id', $orgId)->where('group_id', $groupId)
            ->where('project_id', $projectId)->where('created_at', '>=', $deliverySince)
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('SUM(status = ?) AS succeeded', [Build::STATUS_SUCCESS])
            ->first();
        $recentReleases = ProjectRelease::where('org_id', $orgId)->where('group_id', $groupId)
            ->where('project_id', $projectId)->where('created_at', '>=', $deliverySince)
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('SUM(status IN (?, ?)) AS succeeded', [
                ProjectRelease::STATUS_SUCCEEDED,
                ProjectRelease::STATUS_ROLLED_BACK,
            ])
            ->selectRaw('SUM(operation = ?) AS deploys', [ProjectRelease::OPERATION_DEPLOY])
            ->selectRaw('SUM(operation = ?) AS rollbacks', [ProjectRelease::OPERATION_ROLLBACK])
            ->first();
        $recentBuildTotal = (int) ($recentBuilds?->total ?? 0);
        $recentReleaseTotal = (int) ($recentReleases?->total ?? 0);
        return [
            'source' => [
                'mode' => $project->develop ? 'repository' : 'external-image',
                'configured' => $sourceConfigured,
                'connection_status' => $repository?->connection_status
                    ?? ($project->develop ? 'missing' : ($sourceConfigured ? 'ready' : 'missing')),
                'default_branch' => $repository?->default_branch ?? '',
                'checked_at' => (int) ($repository?->checked_at ?? 0),
                'image_name' => (string) $project->image_name,
                'registry_count' => $registryCount,
                // Git Vendor 属于外部慢数据，由 /project/overview/source 独立加载。
                'commit_count' => null,
                'commit_count_available' => false,
                'commit_count_error' => '',
            ],
            'pipeline' => ['total' => $pipelineTotal, 'active' => $pipelineActive,
                'archived' => max(0, $pipelineTotal - $pipelineActive)],
            'artifact' => ['total' => $artifactCount, 'deployed' => $deployedArtifactCount],
            'build' => [
                'total' => array_sum($builds->toArray()),
                'running' => (int) (($builds[Build::STATUS_PENDING_RUN] ?? 0) + ($builds[Build::STATUS_RUNNING] ?? 0)),
                'succeeded' => (int) ($builds[Build::STATUS_SUCCESS] ?? 0),
                'failed' => (int) ($builds[Build::STATUS_FAILED] ?? 0),
            ],
            'release' => [
                'total' => array_sum($releaseStatuses->toArray()),
                'deploying' => (int) (($releaseStatuses[ProjectRelease::STATUS_PENDING] ?? 0) + ($releaseStatuses[ProjectRelease::STATUS_DEPLOYING] ?? 0)),
                'succeeded' => (int) ($releaseStatuses[ProjectRelease::STATUS_SUCCEEDED] ?? 0),
                'failed' => (int) ($releaseStatuses[ProjectRelease::STATUS_FAILED] ?? 0),
            ],
            'runtime' => [
                'services' => $runtimeCount, 'desired_replicas' => $desiredReplicas,
                'running_replicas' => $runningReplicas, 'unhealthy' => $unhealthyRuntimes,
            ],
            'route' => ['total' => $routeTotal, 'enabled' => $routeEnabled, 'tls' => $routeTls],
            'member' => ['count' => $memberCnt],
            'delivery_30d' => [
                'builds' => [
                    'total' => $recentBuildTotal,
                    'succeeded' => (int) ($recentBuilds?->succeeded ?? 0),
                    'success_rate' => $recentBuildTotal > 0
                        ? round((int) $recentBuilds->succeeded / $recentBuildTotal * 100, 2)
                        : 0,
                ],
                'releases' => [
                    'total' => $recentReleaseTotal,
                    'succeeded' => (int) ($recentReleases?->succeeded ?? 0),
                    'deploys' => (int) ($recentReleases?->deploys ?? 0),
                    'rollbacks' => (int) ($recentReleases?->rollbacks ?? 0),
                    'success_rate' => $recentReleaseTotal > 0
                        ? round((int) $recentReleases->succeeded / $recentReleaseTotal * 100, 2)
                        : 0,
                ],
            ],
            'web_24h' => [
                // Prometheus 指标由 /deploy/monitoring/http 独立加载。
                'available' => false,
                'partial' => false,
                'summary' => [],
                'slo' => ['status' => 'no_data', 'checks' => [], 'objectives' => []],
                'metric_note' => '',
            ],
            'last_activity_at' => max($lastBuild, $lastRelease, $lastRoute),
        ];
    }

    /**
     * 概览页源码慢数据。与基础概览分离，避免 Git Vendor 响应阻塞页面。
     */
    public function overviewSource(int $uid, int $orgId, int $groupId, int $projectId): array
    {
        $project = Project::where('id', $projectId)
            ->where('org_id', $orgId)
            ->where('group_id', $groupId)
            ->select('id', 'develop')
            ->first();
        if ($project === null) {
            throw new AppException(404, '项目不存在');
        }

        $repository = ProjectRepository::where('org_id', $orgId)
            ->where('group_id', $groupId)
            ->where('project_id', $projectId)
            ->first();
        if (!$project->develop || $repository === null) {
            return [
                'commit_count' => null,
                'commit_count_available' => false,
                'commit_count_error' => '',
            ];
        }

        try {
            $commitCount = $this->repositoryReferences->commitCount($uid, $orgId, $groupId, $projectId);
            return [
                'commit_count' => $commitCount,
                'commit_count_available' => true,
                'commit_count_error' => '',
            ];
        } catch (Throwable $e) {
            return [
                'commit_count' => null,
                'commit_count_available' => false,
                'commit_count_error' => mb_substr($e->getMessage(), 0, 300),
            ];
        }
    }
}

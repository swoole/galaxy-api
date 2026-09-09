<?php

namespace App\Services;

use App\Exception\AppException;
use App\Model\Project;
use App\Model\ProjectRelease;
use App\Model\ProjectRuntime;
use App\Model\Workspace;
use App\Model\Build;
use App\Model\Cluster;
use App\Model\Pipeline;
use App\Model\Group;
use App\Model\GroupResourceGrant;
use Hyperf\DbConnection\Db;

class GroupResourceGrantService
{
    public function clusterGroups(int $orgId, int $clusterId): array
    {
        $this->cluster($orgId, $clusterId);
        $granted = GroupResourceGrant::where('org_id', $orgId)
            ->where('resource_type', GroupResourceGrant::TYPE_CLUSTER)
            ->where('resource_id', $clusterId)->pluck('group_id')
            ->map(static fn ($id): int => (int) $id)->flip()->all();

        $groups = Group::where('org_id', $orgId)->select('id', 'title', 'alias')->orderBy('id')->get();
        $blockers = $this->revokeBlockers($orgId, $clusterId, $groups->pluck('id')->map(
            static fn ($id): int => (int) $id
        )->all());
        return $groups->map(static function (Group $group) use ($granted, $blockers): array {
                $groupBlockers = $blockers[(int) $group->id] ?? [];
                return [
                    'id' => (int) $group->id,
                    'title' => (string) $group->title,
                    'alias' => (string) $group->alias,
                    'granted' => isset($granted[(int) $group->id]),
                    'revoke_blocked' => array_sum($groupBlockers) > 0,
                    'revoke_blockers' => $groupBlockers,
                ];
            })->all();
    }

    public function syncClusterGroups(int $uid, int $orgId, int $clusterId, array $groupIds): array
    {
        $groupIds = array_values(array_unique(array_map('intval', $groupIds)));
        if (count($groupIds) > 1000 || in_array(0, $groupIds, true)) {
            throw new AppException(422, '项目组授权列表不合法');
        }
        return Db::transaction(function () use ($uid, $orgId, $clusterId, $groupIds): array {
            $this->cluster($orgId, $clusterId, true);
            $currentIds = GroupResourceGrant::where('org_id', $orgId)
                ->where('resource_type', GroupResourceGrant::TYPE_CLUSTER)
                ->where('resource_id', $clusterId)->lockForUpdate()->pluck('group_id')
                ->map(static fn ($id): int => (int) $id)->all();
            if ($groupIds !== []) {
                $validIds = Group::where('org_id', $orgId)->whereIn('id', $groupIds)
                    ->lockForUpdate()->pluck('id')->map(static fn ($id): int => (int) $id)->all();
                sort($validIds);
                $expected = $groupIds;
                sort($expected);
                if ($validIds !== $expected) {
                    throw new AppException(422, '授权列表中包含不存在的项目组');
                }
            }
            $revokedIds = array_values(array_diff($currentIds, $groupIds));
            $addedIds = array_values(array_diff($groupIds, $currentIds));
            if ($revokedIds !== []) {
                $blockers = $this->revokeBlockers($orgId, $clusterId, $revokedIds);
                foreach ($revokedIds as $groupId) {
                    if (array_sum($blockers[$groupId] ?? []) === 0) {
                        continue;
                    }
                    $groupTitle = (string) (Group::where('id', $groupId)->where('org_id', $orgId)
                        ->value('title') ?? ('#' . $groupId));
                    throw new AppException(409, sprintf(
                        '项目组“%s”仍在使用该集群：%s。请先迁移或清理这些资源再撤销授权',
                        $groupTitle,
                        $this->formatBlockers($blockers[$groupId])
                    ));
                }
                GroupResourceGrant::where('org_id', $orgId)
                    ->where('resource_type', GroupResourceGrant::TYPE_CLUSTER)
                    ->where('resource_id', $clusterId)->whereIn('group_id', $revokedIds)->delete();
            }
            $now = time();
            foreach ($addedIds as $groupId) {
                GroupResourceGrant::create([
                    'org_id' => $orgId,
                    'group_id' => $groupId,
                    'resource_type' => GroupResourceGrant::TYPE_CLUSTER,
                    'resource_id' => $clusterId,
                    'granted_by' => $uid,
                    'created_at' => $now,
                ]);
            }
            return $this->clusterGroups($orgId, $clusterId);
        });
    }

    private function revokeBlockers(int $orgId, int $clusterId, array $groupIds): array
    {
        if ($groupIds === []) {
            return [];
        }
        $result = [];
        foreach ($groupIds as $groupId) {
            $result[(int) $groupId] = [
                'projects' => 0,
                'pipelines' => 0,
                'workspaces' => 0,
                'runtimes' => 0,
                'builds' => 0,
                'releases' => 0,
            ];
        }
        $collect = static function ($query, string $name) use (&$result): void {
            foreach ($query->select('group_id', Db::raw('COUNT(*) AS cnt'))->groupBy('group_id')->get() as $row) {
                $groupId = (int) $row->group_id;
                if (isset($result[$groupId])) {
                    $result[$groupId][$name] = (int) $row->cnt;
                }
            }
        };
        $scope = static fn ($query) => $query->where('org_id', $orgId)->whereIn('group_id', $groupIds);
        $collect($scope(Project::query())->where('develop', 1)->where('build_cluster_id', $clusterId), 'projects');
        $collect($scope(Pipeline::query())->where('cluster_id', $clusterId)->where('archived_at', 0), 'pipelines');
        $collect($scope(Workspace::query())->where('cluster_id', $clusterId), 'workspaces');
        $collect($scope(ProjectRuntime::query())->where('cluster_id', $clusterId), 'runtimes');
        $collect($scope(Build::query())->where('executor', 'buildkit')
            ->whereRaw("CAST(JSON_UNQUOTE(JSON_EXTRACT(`spec_snapshot`, '$.cluster_id')) AS UNSIGNED) = ?", [$clusterId])
            ->where(function ($query): void {
                $query->whereIn('status', [Build::STATUS_PENDING_RUN, Build::STATUS_RUNNING])
                    ->orWhere(function ($terminal): void {
                        $terminal->whereIn('status', [Build::STATUS_SUCCESS, Build::STATUS_FAILED, Build::STATUS_CANCELED])
                            ->where(function ($unreconciled): void {
                                $unreconciled->whereNull('result')
                                    ->orWhereRaw("JSON_EXTRACT(`result`, '$.runner_reconciled_at') IS NULL");
                            });
                    });
            }), 'builds');
        $collect($scope(ProjectRelease::query())->where('cluster_id', $clusterId)
            ->where(function ($query): void {
                $query->whereIn('status', [ProjectRelease::STATUS_PENDING, ProjectRelease::STATUS_DEPLOYING])
                    ->orWhere(function ($failed): void {
                        $failed->where('status', ProjectRelease::STATUS_FAILED)
                            ->where(function ($unreconciled): void {
                                $unreconciled->whereNull('result')
                                    ->orWhereRaw("JSON_EXTRACT(`result`, '$.failure_reconciled_at') IS NULL");
                            });
                    });
            }), 'releases');
        return $result;
    }

    private function formatBlockers(array $blockers): string
    {
        $labels = [
            'projects' => '构建集群设置',
            'pipelines' => '活动 Pipeline',
            'workspaces' => 'Workspace',
            'runtimes' => '运行实例',
            'builds' => '待执行/待对账构建',
            'releases' => '待执行/待对账发布',
        ];
        $parts = [];
        foreach ($labels as $name => $label) {
            if ((int) ($blockers[$name] ?? 0) > 0) {
                $parts[] = $label . ' ' . (int) $blockers[$name];
            }
        }
        return implode('、', $parts);
    }

    private function cluster(int $orgId, int $clusterId, bool $lock = false): Cluster
    {
        $query = Cluster::where('id', $clusterId)->where('org_id', $orgId)
            ->whereIn('orchestrator_type', Cluster::knownOrchestrators());
        if ($lock) {
            $query->lockForUpdate();
        }
        $cluster = $query->first();
        if ($cluster === null) {
            throw new AppException(404, '集群不存在');
        }
        return $cluster;
    }
}

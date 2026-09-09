<?php

namespace App\Services\Project;

use App\Job\ProjectReleaseJob;
use App\Job\BuildKitBuildJob;
use App\Exception\AppException;
use App\Model\ProjectAlert;
use App\Model\ProjectAuditLog;
use App\Model\ProjectRetentionPolicy;
use App\Model\ProjectRuntimeEvent;
use App\Model\ProjectRuntimeMetric;
use App\Model\Project;
use App\Model\ProjectRelease;
use App\Model\Build;
use App\Model\BuildArtifact;
use App\Model\BuildSecret;
use App\Services\AsyncQueue\DefaultQueueService;
use App\Services\Project\Runtime\ProjectRuntimeDriverRegistry;
use App\Services\Project\Build\BuildExecutorRegistry;
use App\Support\MySQL;

class ProjectGovernanceService
{
    public function __construct(
        private BuildExecutorRegistry $buildExecutors,
        private PipelineSecretService $pipelineSecrets,
        private DefaultQueueService $queue,
        private ProjectRuntimeDriverRegistry $runtimeDrivers,
        private ProjectMutationLock $projectMutationLock
    ) {}

    public function auditList(
        int $orgId,
        int $groupId,
        int $projectId,
        ?string $action,
        ?string $status,
        ?int $uid,
        ?int $begin,
        ?int $end,
        int $page,
        int $pageSize
    ): array {
        $query = ProjectAuditLog::where('org_id', $orgId)->where('group_id', $groupId)->where('project_id', $projectId)
            ->with('actor')->orderByDesc('id');
        if ($action !== null && $action !== '') {
            $query->where('action', $action);
        }
        if ($status !== null && $status !== '') {
            $query->where('status', $status);
        }
        if ($uid !== null && $uid > 0) {
            $query->where('uid', $uid);
        }
        if ($begin !== null && $begin > 0) {
            $query->where('created_at', '>=', $begin);
        }
        if ($end !== null && $end > 0) {
            $query->where('created_at', '<=', $end);
        }
        return MySQL::jsonPaginate($query, $page, $pageSize);
    }

    public function policy(int $orgId, int $groupId, int $projectId): array
    {
        $defaults = (array) config('project-governance.defaults', []);
        /** @var ProjectRetentionPolicy|null $policy */
        $policy = ProjectRetentionPolicy::where('org_id', $orgId)->where('group_id', $groupId)
            ->where('project_id', $projectId)->first();
        return [
            'enabled' => $policy?->enabled ?? true,
            'metric_days' => $policy?->metric_days ?? (int) ($defaults['metric_days'] ?? 30),
            'event_days' => $policy?->event_days ?? (int) ($defaults['event_days'] ?? 90),
            'resolved_alert_days' => $policy?->resolved_alert_days ?? (int) ($defaults['resolved_alert_days'] ?? 180),
            'build_log_days' => $policy?->build_log_days ?? (int) ($defaults['build_log_days'] ?? 90),
            'audit_days' => $policy?->audit_days ?? (int) ($defaults['audit_days'] ?? 365),
            'last_purged_at' => $policy?->last_purged_at ?? 0,
        ];
    }

    public function savePolicy(int $uid, int $orgId, int $groupId, int $projectId, array $input): array
    {
        $now = time();
        $policy = ProjectRetentionPolicy::firstOrNew([
            'org_id' => $orgId, 'group_id' => $groupId, 'project_id' => $projectId,
        ]);
        if (! $policy->exists) {
            $policy->creator = $uid;
            $policy->created_at = $now;
            $policy->last_purged_at = 0;
        }
        $policy->enabled = (bool) $input['enabled'];
        foreach (['metric_days', 'event_days', 'resolved_alert_days', 'build_log_days', 'audit_days'] as $field) {
            $policy->{$field} = (int) $input[$field];
        }
        $policy->updated_at = $now;
        $policy->save();
        return $this->policy($orgId, $groupId, $projectId);
    }

    public function purgeDuePolicies(): array
    {
        $now = time();
        $interval = max(300, (int) config('project-governance.retention_interval', 3600));
        $summary = [
            'policies' => 0, 'metrics' => 0, 'events' => 0, 'alerts' => 0,
            'build_logs' => 0, 'build_secrets' => 0, 'requeued_builds' => 0, 'stale_builds' => 0,
            'build_runners' => 0,
            'requeued_releases' => 0, 'reconciled_releases' => 0,
            'stale_releases' => 0, 'release_secrets' => 0, 'audits' => 0,
        ];
        [$summary['requeued_builds'], $summary['stale_builds']] = $this->recoverStaleBuilds($now);
        $summary['build_runners'] = $this->recoverBuildRunners($now);
        [
            $summary['requeued_releases'],
            $summary['reconciled_releases'],
            $summary['stale_releases'],
        ] = $this->recoverStaleReleases($now);
        $summary['release_secrets'] = $this->recoverFailedReleaseSecrets($now);
        $summary['build_secrets'] = BuildSecret::whereIn(
            'build_id',
            Build::whereIn('status', [Build::STATUS_SUCCESS, Build::STATUS_FAILED, Build::STATUS_CANCELED])
                ->select('id')
        )->delete();
        ProjectRetentionPolicy::where('enabled', 1)->where('last_purged_at', '<=', $now - $interval)
            ->orderBy('id')->chunkById(100, function ($policies) use ($now, &$summary): void {
                foreach ($policies as $policy) {
                    $scope = static fn ($query) => $query->where('org_id', $policy->org_id)
                        ->where('group_id', $policy->group_id)->where('project_id', $policy->project_id);
                    $summary['metrics'] += $scope(ProjectRuntimeMetric::query())
                        ->where('collected_at', '<', $now - ((int) $policy->metric_days * 86400))->delete();
                    $summary['events'] += $scope(ProjectRuntimeEvent::query())
                        ->where('occurred_at', '<', $now - ((int) $policy->event_days * 86400))->delete();
                    $summary['alerts'] += $scope(ProjectAlert::query())->where('status', ProjectAlert::STATUS_RESOLVED)
                        ->where('resolved_at', '>', 0)
                        ->where('resolved_at', '<', $now - ((int) $policy->resolved_alert_days * 86400))->delete();
                    $summary['build_logs'] += $scope(Build::query())
                        ->whereIn('status', [Build::STATUS_SUCCESS, Build::STATUS_FAILED, Build::STATUS_CANCELED])
                        ->where('end_at', '>', 0)
                        ->where('end_at', '<', $now - ((int) $policy->build_log_days * 86400))
                        ->whereNotNull('log')->update(['log' => null, 'updated_at' => $now]);
                    $summary['audits'] += $scope(ProjectAuditLog::query())
                        ->where('created_at', '<', $now - ((int) $policy->audit_days * 86400))->delete();
                    $policy->last_purged_at = $now;
                    $policy->updated_at = $now;
                    $policy->save();
                    if (! Project::where('id', $policy->project_id)->where('org_id', $policy->org_id)->exists()
                        && ! ProjectAuditLog::where('org_id', $policy->org_id)->where('group_id', $policy->group_id)
                            ->where('project_id', $policy->project_id)->exists()) {
                        $policy->delete();
                    }
                    ++$summary['policies'];
                }
            });
        return $summary;
    }

    /** @return array{0: int, 1: int} */
    private function recoverStaleBuilds(int $now): array
    {
        $requeued = 0;
        Build::where('status', Build::STATUS_PENDING_RUN)->where('cancel_requested', 0)
            ->where('updated_at', '<=', $now - 300)->orderBy('id')
            ->chunkById(100, function ($builds) use ($now, &$requeued): void {
                foreach ($builds as $build) {
                    try {
                        if ($this->queue->push(new BuildKitBuildJob((int) $build->id))) {
                            $requeued += Build::where('id', (int) $build->id)
                                ->where('status', Build::STATUS_PENDING_RUN)
                                ->where('cancel_requested', 0)
                                ->update(['updated_at' => $now]);
                        }
                    } catch (\Throwable) {
                        // Queue outage is retried by the next governance pass.
                    }
                }
            });

        $cutoff = $now - max(300, (int) config('buildkit.timeout', 1800) + 300);
        $recovered = 0;
        Build::where('status', Build::STATUS_RUNNING)->where('start_at', '>', 0)
            ->where('start_at', '<=', $cutoff)->orderBy('id')->chunkById(100, function ($builds) use ($now, &$recovered): void {
                foreach ($builds as $build) {
                    try {
                        $didRecover = $this->projectMutationLock->synchronized(
                            (int) $build->org_id,
                            (int) $build->project_id,
                            function () use ($build, $now): bool {
                                try {
                                    $this->buildExecutors->forBuild($build)->cancel($build);
                                } catch (\Throwable) {
                                }
                                $updated = Build::where('id', (int) $build->id)
                                    ->where('status', Build::STATUS_RUNNING)->update([
                                        'status' => Build::STATUS_FAILED,
                                        'error' => '构建执行进程异常退出，超过超时窗口后已由保留任务回收',
                                        'end_at' => $now,
                                        'updated_at' => $now,
                                ]);
                                if ($updated > 0) {
                                    BuildArtifact::where('build_id', (int) $build->id)->delete();
                                    $this->pipelineSecrets->purgeBuild((int) $build->id);
                                }
                                return $updated > 0;
                            },
                            0,
                            300
                        );
                        if ($didRecover) {
                            ++$recovered;
                        }
                    } catch (\Throwable) {
                        // Active foreground or queue work owns the project lock; retry later.
                    }
                }
            });
        return [$requeued, $recovered];
    }

    /** @return array{0: int, 1: int, 2: int} */
    private function recoverStaleReleases(int $now): array
    {
        $requeued = 0;
        ProjectRelease::where('status', ProjectRelease::STATUS_PENDING)
            ->where('updated_at', '<=', $now - 300)->orderBy('id')
            ->chunkById(100, function ($releases) use ($now, &$requeued): void {
                foreach ($releases as $release) {
                    try {
                        if ($this->queue->push(new ProjectReleaseJob((int) $release->id))) {
                            $requeued += ProjectRelease::where('id', (int) $release->id)
                                ->where('status', ProjectRelease::STATUS_PENDING)
                                ->update(['updated_at' => $now]);
                        }
                    } catch (\Throwable) {
                        // Queue outage is retried by the next governance pass.
                    }
                }
            });

        $reconciled = 0;
        $reconcileCutoff = $now - 90;
        ProjectRelease::where('status', ProjectRelease::STATUS_DEPLOYING)
            ->where('started_at', '>', 0)->where('updated_at', '<=', $reconcileCutoff)
            ->orderBy('id')->chunkById(100, function ($releases) use ($now, &$reconciled): void {
                foreach ($releases as $release) {
                    try {
                        $didReconcile = $this->projectMutationLock->synchronized(
                            (int) $release->org_id,
                            (int) $release->project_id,
                            function () use ($release, $now): bool {
                                $result = $this->runtimeDrivers->forRelease($release)
                                    ->reconcileSucceededRelease($release);
                                if ($result === null) {
                                    return false;
                                }
                                $updated = ProjectRelease::where('id', (int) $release->id)
                                    ->where('status', ProjectRelease::STATUS_DEPLOYING)
                                    ->update([
                                        'result' => json_encode(
                                            $result,
                                            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                                        ),
                                        'status' => ProjectRelease::STATUS_SUCCEEDED,
                                        'error' => null,
                                        'finished_at' => $now,
                                        'updated_at' => $now,
                                    ]);
                                if ($updated !== 1) {
                                    return false;
                                }
                                if ($release->operation === ProjectRelease::OPERATION_ROLLBACK
                                    && (int) $release->previous_release_id > 0) {
                                    ProjectRelease::where('id', (int) $release->previous_release_id)->update([
                                        'status' => ProjectRelease::STATUS_ROLLED_BACK,
                                        'updated_at' => $now,
                                    ]);
                                }
                                return true;
                            },
                            0,
                            180
                        );
                        if ($didReconcile) {
                            ++$reconciled;
                        }
                    } catch (\Throwable) {
                        // Remote state can be temporarily unavailable; the
                        // next pass retries without guessing a terminal state.
                    }
                }
            });

        $failed = 0;
        $releaseTimeout = max(30, (int) config('project-release.timeout', 600));
        // 留出远高于 Runner 自身超时的保护窗口，避免把仍在慢速 Agent/外网链路上收敛的发布误判为僵死。
        $cutoff = $now - max(1800, $releaseTimeout * 3);
        ProjectRelease::where('status', ProjectRelease::STATUS_DEPLOYING)
            ->where('started_at', '>', 0)->where('updated_at', '<=', $cutoff)
            ->orderBy('id')->chunkById(100, function ($releases) use ($now, $cutoff, &$failed): void {
                foreach ($releases as $release) {
                    try {
                        $didFail = $this->projectMutationLock->synchronized(
                            (int) $release->org_id,
                            (int) $release->project_id,
                            function () use ($release, $now, $cutoff): bool {
                                $updated = ProjectRelease::where('id', (int) $release->id)
                                    ->where('status', ProjectRelease::STATUS_DEPLOYING)
                                    ->where('updated_at', '<=', $cutoff)
                                    ->update([
                                        'status' => ProjectRelease::STATUS_FAILED,
                                        'error' => '发布执行进程异常退出，超过超时窗口后已由治理任务回收',
                                        'finished_at' => $now,
                                        'updated_at' => $now,
                                    ]);
                                if ($updated !== 1) {
                                    return false;
                                }
                                try {
                                    $release->refresh();
                                    $this->runtimeDrivers->forRelease($release)->cleanupFailedRelease($release);
                                } catch (\Throwable) {
                                    // Keep the records so a later governance pass can safely retry.
                                }
                                return true;
                            },
                            0,
                            300
                        );
                        if ($didFail) {
                            ++$failed;
                        }
                    } catch (\Throwable) {
                        // Active foreground or queue work owns the project lock; retry later.
                    }
                }
            });

        return [$requeued, $reconciled, $failed];
    }

    private function recoverBuildRunners(int $now): int
    {
        $cleaned = 0;
        Build::where('executor', 'buildkit')
            ->whereIn('status', [Build::STATUS_SUCCESS, Build::STATUS_FAILED, Build::STATUS_CANCELED])
            ->where('updated_at', '<=', $now - 3600)
            ->where(function ($query): void {
                $query->whereNull('result')
                    ->orWhereRaw("JSON_EXTRACT(`result`, '$.runner_reconciled_at') IS NULL");
            })
            ->orderBy('id')
            ->chunkById(50, function ($builds) use ($now, &$cleaned): void {
                foreach ($builds as $build) {
                    try {
                        $didClean = $this->projectMutationLock->synchronized(
                            (int) $build->org_id,
                            (int) $build->project_id,
                            fn (): bool => $this->buildExecutors->forBuild($build)->reconcileRunner($build),
                            0,
                            300
                        );
                        if ($didClean) {
                            ++$cleaned;
                            continue;
                        }
                    } catch (\Throwable) {
                        // Remote Docker/Agent outages are retried with an hourly backoff.
                    }
                    Build::where('id', (int) $build->id)->update(['updated_at' => $now]);
                }
            });
        return $cleaned;
    }

    private function recoverFailedReleaseSecrets(int $now): int
    {
        $cleaned = 0;
        ProjectRelease::where('status', ProjectRelease::STATUS_FAILED)
            ->where('updated_at', '<=', $now - 3600)
            ->where(function ($query): void {
                $query->whereHas('secrets')
                    ->orWhereHas('configs')
                    ->orWhereNull('result')
                    ->orWhereRaw("JSON_EXTRACT(`result`, '$.failure_reconciled_at') IS NULL");
            })
            ->orderBy('id')
            ->chunkById(50, function ($releases) use ($now, &$cleaned): void {
                foreach ($releases as $release) {
                    try {
                        $cleanedRelease = $this->projectMutationLock->synchronized(
                            (int) $release->org_id,
                            (int) $release->project_id,
                            function () use ($release): bool {
                                if (! Project::where('id', (int) $release->project_id)
                                    ->where('org_id', (int) $release->org_id)->exists()
                                    || ! ProjectRelease::where('id', (int) $release->id)
                                        ->where('status', ProjectRelease::STATUS_FAILED)->exists()) {
                                    return true;
                                }
                                $release->refresh();
                                return $this->runtimeDrivers->forRelease($release)->cleanupFailedRelease($release);
                            },
                            0,
                            300
                        );
                        if ($cleanedRelease) {
                            ++$cleaned;
                            continue;
                        }
                    } catch (AppException $e) {
                        if ($e->getCode() !== 409) {
                            // Remote Docker/Agent failures are retried below with an hourly backoff.
                        }
                    } catch (\Throwable) {
                        // Remote Docker/Agent outages are retried with an hourly backoff.
                    }
                    ProjectRelease::where('id', (int) $release->id)
                        ->where('status', ProjectRelease::STATUS_FAILED)
                        ->update(['updated_at' => $now]);
                }
            });
        return $cleaned;
    }
}

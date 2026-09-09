<?php

namespace App\Job;

use App\Model\ProjectRelease;
use App\Model\ProjectRuntime;
use App\Services\Project\ProjectMutationLock;
use App\Services\Project\ProjectRouteService;
use App\Services\Project\ProjectServiceIdentity;
use App\Services\Project\Runtime\ProjectRuntimeDriver;
use App\Services\Project\Runtime\ProjectRuntimeDriverRegistry;
use Hyperf\AsyncQueue\Job;
use Hyperf\Context\ApplicationContext;
use Throwable;

class ProjectReleaseJob extends Job
{
    public function __construct(public int $releaseId) {}

    public function handle(): void
    {
        /** @var ProjectRelease|null $scope */
        $scope = ProjectRelease::find($this->releaseId, ['id', 'org_id', 'project_id']);
        if ($scope === null) {
            return;
        }
        /** @var ProjectMutationLock $lock */
        $lock = ApplicationContext::getContainer()->get(ProjectMutationLock::class);
        $lock->synchronized(
            (int) $scope->org_id,
            (int) $scope->project_id,
            fn () => $this->execute(),
            30,
            max(600, (int) config('project-release.timeout', 600) + 300)
        );
    }

    private function execute(): void
    {
        $now = time();
        $claimed = ProjectRelease::where('id', $this->releaseId)
            ->where('status', ProjectRelease::STATUS_PENDING)
            ->update([
                'status' => ProjectRelease::STATUS_DEPLOYING,
                'started_at' => $now,
                'updated_at' => $now,
            ]);
        if ($claimed !== 1) {
            return;
        }
        /** @var ProjectRelease|null $release */
        $release = ProjectRelease::find($this->releaseId);
        if ($release === null) {
            return;
        }
        try {
            /** @var ProjectRuntimeDriverRegistry $drivers */
            $drivers = ApplicationContext::getContainer()->get(ProjectRuntimeDriverRegistry::class);
            $driver = $drivers->forRelease($release);
            $result = $driver->deploy($release);
            $now = time();
            $completed = ProjectRelease::where('id', (int) $release->id)
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
            if ($completed === 1 && $release->operation === ProjectRelease::OPERATION_ROLLBACK && (int) $release->previous_release_id > 0) {
                ProjectRelease::where('id', (int) $release->previous_release_id)->update([
                    'status' => ProjectRelease::STATUS_ROLLED_BACK,
                    'updated_at' => $now,
                ]);
            }
            if ($completed === 1) {
                try {
                    /** @var ProjectRouteService $routes */
                    $routes = ApplicationContext::getContainer()->get(ProjectRouteService::class);
                    $importedRoutes = $routes->autoImportForRelease($release->fresh());
                    if ($importedRoutes !== []) {
                        $release->refresh();
                        $releaseResult = (array) $release->result;
                        $releaseResult['imported_route_ids'] = array_values(array_map(
                            static fn (array $route): int => (int) ($route['id'] ?? 0),
                            $importedRoutes
                        ));
                        $release->result = $releaseResult;
                        $release->updated_at = time();
                        $release->save();
                    }
                } catch (Throwable $routeError) {
                    // A healthy workload must not become a failed release only
                    // because a legacy gateway rule could not be adopted.
                    $release->refresh();
                    $releaseResult = (array) $release->result;
                    $releaseResult['route_import_error'] = mb_substr($routeError->getMessage(), 0, 2000);
                    $release->result = $releaseResult;
                    $release->updated_at = time();
                    $release->save();
                }
            }
        } catch (Throwable $e) {
            $now = time();
            $error = mb_substr($e->getMessage(), 0, 6000);
            $failed = ProjectRelease::where('id', (int) $release->id)
                ->where('status', ProjectRelease::STATUS_DEPLOYING)
                ->update([
                    'status' => ProjectRelease::STATUS_FAILED,
                    'error' => $error,
                    'finished_at' => $now,
                    'updated_at' => $now,
                ]);
            if ($failed === 1) {
                try {
                    /** @var ProjectRuntimeDriverRegistry $drivers */
                    $drivers ??= ApplicationContext::getContainer()->get(ProjectRuntimeDriverRegistry::class);
                    /** @var ProjectRuntimeDriver $driver */
                    $driver ??= $drivers->forRelease($release);
                    $release->refresh();
                    $driver->cleanupFailedRelease($release);
                } catch (Throwable) {
                    // Keep the encrypted records so governance can safely retry remote cleanup.
                }
                ProjectRuntime::where('org_id', (int) $release->org_id)
                    ->where('project_id', (int) $release->project_id)
                    ->where(
                        'name',
                        ProjectServiceIdentity::instanceName((array) $release->desired_spec)
                    )
                    // A failed update may leave the previous release healthy.
                    // Only degrade a Runtime that remote reconciliation has
                    // confirmed is already running this failed release.
                    ->where('release_id', (int) $release->id)
                    ->update([
                        'status' => 'failed',
                        'health' => 'unhealthy',
                        'last_error' => $error,
                        'updated_at' => $now,
                    ]);
            }
        }
    }
}

<?php

namespace App\Services\Project;

use App\Exception\AppException;
use App\Model\Cluster;
use App\Model\Project;
use App\Model\ProjectRuntime;
use App\Model\ProjectRuntimeEvent;
use App\Services\Project\Monitoring\KubernetesRuntimeEventProvider;
use App\Services\Project\Monitoring\ProjectRuntimeEventProvider;
use App\Services\Project\Monitoring\SwarmRuntimeEventProvider;
use Hyperf\DbConnection\Db;
use Throwable;

class ProjectRuntimeEventService
{
    public function __construct(
        private SwarmRuntimeEventProvider $swarmProvider,
        private KubernetesRuntimeEventProvider $kubernetesProvider,
        private ProjectMutationLock $projectMutationLock
    ) {}

    public function pollAll(): int
    {
        $count = 0;
        $runtimes = ProjectRuntime::orderBy('cluster_id')->get()->groupBy('cluster_id');
        foreach ($runtimes as $clusterId => $clusterRuntimes) {
            $cluster = Cluster::find((int) $clusterId);
            $provider = $cluster === null ? null : $this->provider((string) $cluster->orchestrator_type);
            if ($cluster === null || $provider === null) {
                continue;
            }
            try {
                $count += $this->pollCluster($provider, $cluster, $clusterRuntimes->all());
            } catch (Throwable $e) {
                logger()->warning('运行事件采集失败', [
                    'cluster_id' => $clusterId,
                    'orchestrator_type' => $cluster->orchestrator_type,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        return $count;
    }

    public function list(
        int $orgId,
        int $groupId,
        int $projectId,
        int $limit = 100,
        ?int $runtimeId = null
    ): array
    {
        $query = ProjectRuntimeEvent::where('org_id', $orgId)
            ->where('group_id', $groupId)
            ->where('project_id', $projectId);
        if ($runtimeId !== null) {
            $query->where('runtime_id', $runtimeId);
        }
        return $query
            ->orderBy('occurred_at', 'desc')
            ->limit(max(1, min(500, $limit)))
            ->get()
            ->toArray();
    }

    public function refreshRuntime(
        int $orgId,
        int $groupId,
        int $projectId,
        int $runtimeId
    ): int {
        /** @var ProjectRuntime|null $runtime */
        $runtime = ProjectRuntime::where('id', $runtimeId)
            ->where('org_id', $orgId)
            ->where('group_id', $groupId)
            ->where('project_id', $projectId)
            ->first();
        if ($runtime === null) {
            throw new AppException(404, '所选运行实例不存在或不属于当前项目');
        }
        /** @var Cluster|null $cluster */
        $cluster = Cluster::find((int) $runtime->cluster_id);
        $provider = $cluster === null ? null : $this->provider((string) $cluster->orchestrator_type);
        if ($cluster === null || $provider === null) {
            return 0;
        }
        $now = time();
        $events = $provider->collect($cluster, [$runtime], $now - 30 * 86400, $now);
        return $this->persistCollected($cluster, $events, $now);
    }

    private function provider(string $orchestratorType): ?ProjectRuntimeEventProvider
    {
        return match ($orchestratorType) {
            Cluster::ORCHESTRATOR_DOCKER_SWARM => $this->swarmProvider,
            Cluster::ORCHESTRATOR_KUBERNETES => $this->kubernetesProvider,
            default => null,
        };
    }

    private function pollCluster(
        ProjectRuntimeEventProvider $provider,
        Cluster $cluster,
        array $runtimes
    ): int {
        $now = time();
        $cursor = (int) (Db::table('cluster_event_cursor')
            ->where('cluster_id', (int) $cluster->id)
            ->value('last_event_at') ?: $now - 60);
        $events = $provider->collect($cluster, $runtimes, max(0, $cursor - 2), $now);
        $inserted = $this->persistCollected($cluster, $events, $now);
        Db::table('cluster_event_cursor')->updateOrInsert(
            ['cluster_id' => (int) $cluster->id],
            ['last_event_at' => $now, 'updated_at' => $now]
        );
        return $inserted;
    }

    private function persistCollected(Cluster $cluster, array $events, int $now): int
    {
        $rowsByProject = [];
        foreach ($events as $event) {
            $runtime = $event['runtime'];
            $key = (int) $runtime->org_id . ':' . (int) $runtime->project_id;
            $rowsByProject[$key]['org_id'] = (int) $runtime->org_id;
            $rowsByProject[$key]['group_id'] = (int) $runtime->group_id;
            $rowsByProject[$key]['project_id'] = (int) $runtime->project_id;
            $rowsByProject[$key]['rows'][] = [
                'org_id' => (int) $runtime->org_id,
                'group_id' => (int) $runtime->group_id,
                'project_id' => (int) $runtime->project_id,
                'runtime_id' => (int) $runtime->id,
                'cluster_id' => (int) $cluster->id,
                'fingerprint' => (string) $event['fingerprint'],
                'event_type' => (string) $event['event_type'],
                'action' => (string) $event['action'],
                'actor_id' => (string) $event['actor_id'],
                'attributes' => json_encode(
                    (array) $event['attributes'],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ),
                'occurred_at' => (int) $event['occurred_at'],
                'created_at' => $now,
            ];
        }
        [$inserted, $persistedAll] = $this->persist($rowsByProject);
        if (! $persistedAll) {
            throw new AppException(409, '项目运行事件正在由其他进程写入，请稍后重试');
        }
        return $inserted;
    }

    private function persist(array $rowsByProject): array
    {
        $inserted = 0;
        $persistedAll = true;
        foreach ($rowsByProject as $group) {
            try {
                $inserted += (int) $this->projectMutationLock->synchronized(
                    (int) $group['org_id'],
                    (int) $group['project_id'],
                    function () use ($group): int {
                        if (! Project::where('id', (int) $group['project_id'])
                            ->where('org_id', (int) $group['org_id'])
                            ->where('group_id', (int) $group['group_id'])
                            ->exists()) {
                            return 0;
                        }
                        $runtimeIds = ProjectRuntime::where('org_id', (int) $group['org_id'])
                            ->where('project_id', (int) $group['project_id'])
                            ->pluck('id')
                            ->map(static fn ($id): int => (int) $id)
                            ->all();
                        $rows = array_values(array_filter(
                            (array) $group['rows'],
                            static fn (array $row): bool => in_array(
                                (int) $row['runtime_id'],
                                $runtimeIds,
                                true
                            )
                        ));
                        return $rows === []
                            ? 0
                            : ProjectRuntimeEvent::query()->insertOrIgnore($rows);
                    },
                    0,
                    120
                );
            } catch (AppException $e) {
                if ($e->getCode() !== 409) {
                    throw $e;
                }
                $persistedAll = false;
            }
        }
        return [$inserted, $persistedAll];
    }
}

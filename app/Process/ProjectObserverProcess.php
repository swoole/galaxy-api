<?php

declare(strict_types=1);
/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */

namespace App\Process;

use App\Services\Docker\SwarmResourceSnapshotService;
use App\Services\Kubernetes\KubernetesResourceSnapshotService;
use App\Services\Project\ProjectAlertService;
use App\Services\Project\ProjectMonitoringService;
use App\Services\Project\ProjectRuntimeEventService;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\Coroutine\Coroutine;
use Hyperf\Process\AbstractProcess;
use Hyperf\Process\ProcessManager;
use Hyperf\Redis\Redis;
use Psr\Container\ContainerInterface;

class ProjectObserverProcess extends AbstractProcess
{
    public string $name = 'project-observer';

    public function __construct(
        ContainerInterface $container,
        private ProjectMonitoringService $monitoring,
        private KubernetesResourceSnapshotService $kubernetesResources,
        private SwarmResourceSnapshotService $swarmResources,
        private ProjectRuntimeEventService $events,
        private ProjectAlertService $alerts,
        private Redis $redis,
        private StdoutLoggerInterface $logger
    ) {
        parent::__construct($container);
    }

    public function isEnable($server): bool
    {
        return (bool) config('project-monitoring.enabled', true);
    }

    public function handle(): void
    {
        $interval = max(15, (int) config('project-monitoring.interval', 60));
        while (ProcessManager::isRunning()) {
            try {
                // Only one API replica samples a cycle; duplicate observers would inflate alert occurrences.
                $lockKey = 'cg:project-monitor:cycle-lock';
                $lockToken = bin2hex(random_bytes(16));
                if ($this->redis->set($lockKey, $lockToken, [
                    'nx', 'ex' => max(300, $interval * 3),
                ])) {
                    try {
                        $snapshots = $this->monitoring->collectAll();
                        $kubernetesResult = $this->kubernetesResources->collectAll();
                        if ($kubernetesResult['failed_clusters'] > 0) {
                            $this->logger->warning(sprintf(
                                'Kubernetes 资源快照采集失败：%d 个集群',
                                $kubernetesResult['failed_clusters']
                            ));
                        }
                        $swarmResult = $this->swarmResources->collectAll();
                        if ($swarmResult['failed_clusters'] > 0) {
                            $this->logger->warning(sprintf(
                                'Swarm 资源快照采集失败：%d 个集群',
                                $swarmResult['failed_clusters']
                            ));
                        }
                        $this->alerts->evaluate($snapshots);
                        if ((bool) config('project-monitoring.events_enabled', true)) {
                            $this->events->pollAll();
                        }
                    } finally {
                        $this->redis->eval(
                            "if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) end return 0",
                            [$lockKey, $lockToken],
                            1
                        );
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->error('项目后台监控采集失败：' . $e->getMessage());
            }
            Coroutine::sleep($interval);
        }
    }
}

<?php

namespace App\Process;

use App\Services\Project\ProjectGovernanceService;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\Coroutine\Coroutine;
use Hyperf\Process\AbstractProcess;
use Hyperf\Process\ProcessManager;
use Hyperf\Redis\Redis;
use Psr\Container\ContainerInterface;
use Throwable;

class ProjectRetentionProcess extends AbstractProcess
{
    public string $name = 'project-retention';

    public function __construct(
        ContainerInterface $container,
        private ProjectGovernanceService $governance,
        private Redis $redis,
        private StdoutLoggerInterface $logger
    ) {
        parent::__construct($container);
    }

    public function isEnable($server): bool
    {
        return (bool) config('project-governance.retention_enabled', true);
    }

    public function handle(): void
    {
        $retentionInterval = max(300, (int) config('project-governance.retention_interval', 3600));
        $recoveryInterval = max(60, (int) config('project-governance.recovery_interval', 300));
        $interval = min($retentionInterval, $recoveryInterval);
        while (ProcessManager::isRunning()) {
            try {
                $token = bin2hex(random_bytes(16));
                if ($this->redis->set('cg:project-retention:cycle-lock', $token, ['nx', 'ex' => $interval])) {
                    try {
                        $this->governance->purgeDuePolicies();
                    } finally {
                        $this->redis->eval(
                            "if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) end return 0",
                            ['cg:project-retention:cycle-lock', $token],
                            1
                        );
                    }
                }
            } catch (Throwable $e) {
                $this->logger->error('项目数据保留任务失败：' . $e->getMessage());
            }
            Coroutine::sleep($interval);
        }
    }
}

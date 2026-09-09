<?php

namespace App\Process;

use App\Services\Docker\AgentPresenceService;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\Coroutine\Coroutine;
use Hyperf\Process\AbstractProcess;
use Hyperf\Process\ProcessManager;
use Hyperf\Redis\Redis;
use Psr\Container\ContainerInterface;
use Throwable;

class AgentPresenceProcess extends AbstractProcess
{
    public string $name = 'agent-presence';

    public function __construct(
        ContainerInterface $container,
        private AgentPresenceService $presence,
        private Redis $redis,
        private StdoutLoggerInterface $logger
    ) {
        parent::__construct($container);
    }

    public function isEnable($server): bool
    {
        return (bool) config('docker.agent_presence_enabled', true);
    }

    public function handle(): void
    {
        $interval = max(15, (int) config('docker.agent_presence_interval', 15));
        while (ProcessManager::isRunning()) {
            try {
                $token = bin2hex(random_bytes(16));
                if ($this->redis->set('galaxy:docker-agent:presence-lock', $token, ['nx', 'ex' => $interval])) {
                    try {
                        $this->presence->reconcile();
                    } finally {
                        $this->redis->eval(
                            "if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) end return 0",
                            ['galaxy:docker-agent:presence-lock', $token],
                            1
                        );
                    }
                }
            } catch (Throwable $e) {
                $this->logger->error('Agent 在线状态对账失败：' . $e->getMessage());
            }
            Coroutine::sleep($interval);
        }
    }
}

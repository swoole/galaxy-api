<?php

namespace App\Process;

use App\Services\Gateway\WafEventService;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\Coroutine\Coroutine;
use Hyperf\Process\AbstractProcess;
use Hyperf\Process\ProcessManager;
use Psr\Container\ContainerInterface;
use Throwable;

class WafEventProcess extends AbstractProcess
{
    public string $name = 'waf-events';

    public function __construct(ContainerInterface $container, private WafEventService $events, private StdoutLoggerInterface $logger)
    {
        parent::__construct($container);
    }

    public function isEnable($server): bool
    {
        return (bool) config('waf.collector_enabled', true);
    }

    public function handle(): void
    {
        while (ProcessManager::isRunning()) {
            try { $this->events->collect(); }
            catch (Throwable $e) { $this->logger->warning('WAF 事件采集：' . $e->getMessage()); }
            Coroutine::sleep(max(5, (int) config('waf.collector_interval', 5)));
        }
    }
}

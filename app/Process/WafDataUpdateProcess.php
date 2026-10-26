<?php

namespace App\Process;

use App\Services\Gateway\WafDataUpdater;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\Coroutine\Coroutine;
use Hyperf\Process\AbstractProcess;
use Hyperf\Process\ProcessManager;
use Psr\Container\ContainerInterface;
use Throwable;

/** One blocking, bounded downloader per process; cURL always uses the native pinned transport. */
class WafDataUpdateProcess extends AbstractProcess
{
    public string $name = 'waf-data';
    public function __construct(ContainerInterface $container, private WafDataUpdater $updater, private StdoutLoggerInterface $logger) { parent::__construct($container); }
    public function isEnable($server): bool { return (bool) config('waf.data_updater_enabled', false); }
    public function handle(): void
    {
        ini_set('memory_limit', '256M');
        // Keep framework SQL/Redis pools in their coroutine context. Disable only
        // cURL hooks in this dedicated process so pinned DNS options stay native.
        $flags = \Swoole\Runtime::getHookFlags();
        foreach (['SWOOLE_HOOK_CURL','SWOOLE_HOOK_NATIVE_CURL'] as $flag) if (defined($flag)) $flags &= ~constant($flag);
        \Swoole\Runtime::setHookFlags($flags);
        $lastPruned = 0;
        while (ProcessManager::isRunning()) {
            try {
                $worked = $this->updater->due();
                if (time() - $lastPruned >= 3600) { $this->updater->prune(); $lastPruned = time(); }
            }
            catch (Throwable $e) { $worked = false; $this->logger->warning('WAF 数据更新：' . $e->getMessage()); }
            Coroutine::sleep($worked ? 1 : 30);
        }
    }
}

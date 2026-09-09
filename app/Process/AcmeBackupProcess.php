<?php

namespace App\Process;

use App\Services\Gateway\AcmeBackupService;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\Coroutine\Coroutine;
use Hyperf\Process\AbstractProcess;
use Hyperf\Process\ProcessManager;
use Psr\Container\ContainerInterface;
use Throwable;

class AcmeBackupProcess extends AbstractProcess
{
    public string $name = 'acme-backup';

    public function __construct(
        ContainerInterface $container,
        private AcmeBackupService $backups,
        private StdoutLoggerInterface $logger
    ) {
        parent::__construct($container);
    }

    public function isEnable($server): bool
    {
        return (bool) config('docker.acme_backup_enabled', true);
    }

    public function handle(): void
    {
        $interval = max(30, (int) config('docker.acme_backup_process_interval', 60));
        while (ProcessManager::isRunning()) {
            try {
                $this->backups->runDue();
            } catch (Throwable $e) {
                $this->logger->error('ACME 自动备份任务失败：' . $e->getMessage());
            }
            Coroutine::sleep($interval);
        }
    }
}

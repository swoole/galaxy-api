<?php

namespace App\Job\AppMarket;

use App\Services\AppMarket\KubernetesAppInstaller;
use App\Support\Functions;
use Hyperf\AsyncQueue\Job;
use Hyperf\Context\ApplicationContext;
use Hyperf\Logger\LoggerFactory;
use Throwable;

class InstallKubernetesAppJob extends Job
{
    public function __construct(
        public int $installationId,
        public string $jobId
    ) {}

    public function handle(): void
    {
        try {
            ApplicationContext::getContainer()->get(KubernetesAppInstaller::class)
                ->install($this->installationId, $this->jobId);
        } catch (Throwable $e) {
            ApplicationContext::getContainer()->get(LoggerFactory::class)
                ->get('AppMarketKubernetesInstall', 'queue')
                ->error('Kubernetes app installation failed', [
                    'installation_id' => $this->installationId,
                    'job_id' => $this->jobId,
                    'exception' => Functions::exceptionContext($e),
                ]);
        }
    }
}

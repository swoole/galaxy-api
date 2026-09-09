<?php

namespace App\Job;

use App\Exception\AppException;
use App\Model\Build;
use App\Model\BuildArtifact;
use App\Services\Project\Build\BuildExecutorRegistry;
use App\Services\Project\BuildAutoDeployService;
use App\Services\Project\PipelineSecretService;
use Hyperf\AsyncQueue\Job;
use Hyperf\Context\ApplicationContext;
use Throwable;

class BuildKitBuildJob extends Job
{
    public function __construct(public int $buildId) {}

    public function handle(): void
    {
        // External builds can run for tens of minutes. Holding the project
        // mutation lock for that whole period blocks the cancel endpoint and
        // creates a lock inversion. execute() already claims and completes a
        // Build through compare-and-swap status updates, so it is safe to run
        // without serializing unrelated project mutations.
        $this->execute();
    }

    private function execute(): void
    {
        /** @var Build|null $build */
        $build = Build::find($this->buildId);
        if ($build === null) {
            return;
        }
        if ((int) $build->status !== Build::STATUS_PENDING_RUN) {
            if (in_array((int) $build->status, [Build::STATUS_SUCCESS, Build::STATUS_FAILED, Build::STATUS_CANCELED], true)) {
                ApplicationContext::getContainer()->get(PipelineSecretService::class)->purgeBuild((int) $build->id);
            }
            return;
        }
        if ((bool) $build->cancel_requested) {
            $this->canceled($build);
            return;
        }
        $now = time();
        $claimed = Build::where('id', $this->buildId)
            ->where('status', Build::STATUS_PENDING_RUN)
            ->where('cancel_requested', 0)
            ->update([
                'status' => Build::STATUS_RUNNING,
                'start_at' => $now,
                'updated_at' => $now,
            ]);
        if ($claimed !== 1) {
            return;
        }
        /** @var Build|null $build */
        $build = Build::find($this->buildId);
        if ($build === null) {
            return;
        }

        try {
            /** @var BuildExecutorRegistry $executors */
            $executors = ApplicationContext::getContainer()->get(BuildExecutorRegistry::class);
            $runner = $executors->forBuild($build);
            $result = $runner->run($build);
            $build->refresh();
            if ((bool) $build->cancel_requested) {
                $this->canceled($build);
                return;
            }
            $now = time();
            $completed = Build::where('id', (int) $build->id)
                ->where('status', Build::STATUS_RUNNING)
                ->where('cancel_requested', 0)
                ->update([
                    'status' => Build::STATUS_SUCCESS,
                    'result' => json_encode(
                        array_diff_key($result, ['log' => true]),
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                    ),
                    'log' => (string) ($result['log'] ?? ''),
                    'image_id' => implode(',', (array) ($result['references'] ?? [])),
                    'source_revision' => (string) (
                        $result['metadata']['buildkit.source.git.commit']
                        ?? $result['source_cache']['commit']
                        ?? $build->commit_id
                    ),
                    'error' => null,
                    'end_at' => $now,
                    'updated_at' => $now,
                ]);
            if ($completed !== 1) {
                // A timeout recovery or cancellation won the terminal transition.
                BuildArtifact::where('build_id', (int) $build->id)->delete();
            } else {
                $build->refresh();
                try {
                    $autoDeploy = ApplicationContext::getContainer()->get(BuildAutoDeployService::class)->deploy($build);
                } catch (Throwable $autoDeployError) {
                    $autoDeploy = [
                        'enabled' => true,
                        'status' => 'failed',
                        'error' => mb_substr($autoDeployError->getMessage(), 0, 1000),
                        'triggered_at' => time(),
                    ];
                }
                $storedResult = (array) $build->result;
                $storedResult['auto_deploy'] = $autoDeploy;
                $build->result = $storedResult;
                $build->updated_at = time();
                $build->save();
            }
        } catch (Throwable $e) {
            $build->refresh();
            $timedOut = $this->isTimeout($e);
            if (! $timedOut && (bool) $build->cancel_requested) {
                $this->canceled($build);
                return;
            }
            $now = time();
            $result = (array) ($build->result ?? []);
            $result['termination_reason'] = $timedOut ? 'timeout' : 'failed';
            $result['terminated_at'] = $now;
            Build::where('id', (int) $build->id)
                ->where('status', Build::STATUS_RUNNING)
                ->update([
                    'status' => Build::STATUS_FAILED,
                    'cancel_requested' => $timedOut ? 0 : (int) $build->cancel_requested,
                    'result' => json_encode(
                        $result,
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                    ),
                    'error' => mb_substr($e->getMessage(), 0, 4000),
                    'log' => trim((string) $build->log . "\n" . $e->getMessage()),
                    'end_at' => $now,
                    'updated_at' => $now,
                ]);
            BuildArtifact::where('build_id', (int) $build->id)->delete();
        }
        ApplicationContext::getContainer()->get(PipelineSecretService::class)->purgeBuild((int) $build->id);
    }

    private function canceled(Build $build): void
    {
        $now = time();
        $result = (array) ($build->result ?? []);
        $result['termination_reason'] = 'user_canceled';
        $result['terminated_at'] = $now;
        Build::where('id', (int) $build->id)
            ->whereIn('status', [Build::STATUS_PENDING_RUN, Build::STATUS_RUNNING])
            ->update([
                'status' => Build::STATUS_CANCELED,
                'result' => json_encode(
                    $result,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                ),
                'error' => '构建已取消',
                'end_at' => $now,
                'updated_at' => $now,
            ]);
        BuildArtifact::where('build_id', (int) $build->id)->delete();
        ApplicationContext::getContainer()->get(PipelineSecretService::class)->purgeBuild((int) $build->id);
    }

    private function isTimeout(Throwable $error): bool
    {
        for ($current = $error; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof AppException && $current->getCode() === 504) {
                return true;
            }
            $message = strtolower($current->getMessage());
            if (str_contains($message, 'timed out')
                || str_contains($message, 'timeout')
                || str_contains($message, '超时')
                || (str_contains($message, '超过') && str_contains($message, '秒'))) {
                return true;
            }
        }
        return false;
    }
}

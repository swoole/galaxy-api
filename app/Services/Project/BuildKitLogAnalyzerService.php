<?php

namespace App\Services\Project;

class BuildKitLogAnalyzerService
{
    public function analyze(string $log, int $startAt = 0, int $endAt = 0): array
    {
        $steps = [];
        foreach (preg_split('/\r?\n/', $log) ?: [] as $line) {
            if (! preg_match('/^#(\d+)\s+(.+)$/', trim($line), $matches)) {
                continue;
            }
            $id = (int) $matches[1];
            $message = trim($matches[2]);
            $steps[$id] ??= [
                'id' => $id, 'stage' => '', 'operation' => '', 'category' => 'other',
                'duration_seconds' => 0.0, 'status' => 'running',
            ];
            if (preg_match('/^\[([^]]+)]\s+(.+)$/', $message, $detail)) {
                $steps[$id]['stage'] = $detail[1];
                $steps[$id]['operation'] = $detail[2];
                $steps[$id]['category'] = $this->category($detail[2]);
                continue;
            }
            if (preg_match('/^DONE\s+([0-9.]+)(ms|s)$/i', $message, $done)) {
                $steps[$id]['duration_seconds'] = round(
                    (float) $done[1] / (strtolower($done[2]) === 'ms' ? 1000 : 1),
                    3
                );
                $steps[$id]['status'] = 'done';
                if ($steps[$id]['operation'] === '') {
                    $steps[$id]['operation'] = 'BuildKit step #' . $id . '（操作头已被日志截断）';
                }
                continue;
            }
            if ($message === 'CACHED') {
                $steps[$id]['status'] = 'cached';
                continue;
            }
            if (preg_match('/^ERROR\b/i', $message)) {
                $steps[$id]['status'] = 'error';
                continue;
            }
            if (preg_match('/^([0-9.]+)\s+/', $message, $progress)) {
                $steps[$id]['duration_seconds'] = max($steps[$id]['duration_seconds'], (float) $progress[1]);
                continue;
            }
            if ($steps[$id]['operation'] === '' && ! str_starts_with($message, 'sha256:')) {
                $steps[$id]['operation'] = $message;
                $steps[$id]['category'] = $this->category($message);
            }
        }
        $steps = array_values(array_filter($steps, fn (array $step): bool => $step['operation'] !== ''));
        usort($steps, static fn (array $a, array $b): int => $b['duration_seconds'] <=> $a['duration_seconds']);
        $sum = round(array_sum(array_column($steps, 'duration_seconds')), 3);
        foreach ($steps as &$step) {
            $step['percent_of_stage_time'] = $sum > 0
                ? round($step['duration_seconds'] / $sum * 100, 1)
                : 0.0;
        }
        unset($step);
        $finishedAt = $endAt > 0 ? $endAt : time();
        return [
            'available' => $steps !== [],
            'wall_seconds' => $startAt > 0 ? max(0, $finishedAt - $startAt) : 0,
            'stage_time_seconds' => $sum,
            'parallel_execution' => true,
            'bottleneck' => $steps[0] ?? null,
            'stages' => array_slice($steps, 0, 20),
        ];
    }

    private function category(string $operation): string
    {
        $value = strtolower($operation);
        return match (true) {
            str_contains($value, 'exporting'), str_contains($value, 'sending tarball') => 'export',
            str_starts_with($value, 'from '), str_contains($value, 'load metadata for') => 'base-image',
            str_starts_with($value, 'copy '), str_contains($value, 'load build context') => 'context',
            str_contains($value, 'apt-get'), str_contains($value, 'apk add'), str_contains($value, 'dnf install') => 'system-packages',
            str_contains($value, 'npm install'), str_contains($value, 'npm ci'), str_contains($value, 'pnpm install'),
            str_contains($value, 'yarn install'), str_contains($value, 'composer install'),
            str_contains($value, 'pip install'), str_contains($value, 'poetry install'),
            str_contains($value, 'go mod download'), str_contains($value, 'dependency:go-offline'),
            str_contains($value, 'gradle dependencies') => 'dependencies',
            str_contains($value, 'go build'), str_contains($value, 'mvn ') && str_contains($value, 'package'),
            str_contains($value, 'gradle build'), str_contains($value, ' run build'),
            str_contains($value, 'dump-autoload') => 'compile',
            default => 'other',
        };
    }
}

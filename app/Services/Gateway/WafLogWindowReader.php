<?php

namespace App\Services\Gateway;

use RuntimeException;

/** Adaptive [from, until) windows prevent Docker's tail limit from silently dropping earlier logs. */
final class WafLogWindowReader
{
    public function read(int $from, int $until, callable $fetch, callable $consume, float $deadline, int $batchSize = 512, int $maxRequests = 64): array
    {
        $batch = new WafLogBatch(); $cursor = $from; $requests = 0; $records = 0;
        $windows = [];
        for ($start = $from; $start < $until; $start = $end) {
            $end = min($until, $start + 30000000000); $windows[] = [$start, $end];
            if (count($windows) >= 120) break;
        }
        $windows = array_reverse($windows);
        while ($windows !== [] && microtime(true) < $deadline && $requests < $maxRequests) {
            [$start, $end] = array_pop($windows); ++$requests;
            $raw = $fetch($start, $end, $batchSize);
            $frames = $raw !== null && strlen($raw) <= 8388608 ? $batch->decode($raw) : null;
            if ($frames === null || count($frames) >= $batchSize) {
                if ($end - $start <= 1) throw new RuntimeException('同一纳秒日志密度超过批次上限，游标保留以便补采');
                $middle = $start + intdiv($end - $start, 2);
                $windows[] = [$middle, $end]; $windows[] = [$start, $middle];
                continue;
            }
            $plain = $batch->lines($frames, $start, $end);
            $checkpoint = $batch->checkpoint($end);
            $consume($plain, $checkpoint);
            $cursor = $checkpoint; $records += count($frames);
        }
        return ['cursor_ns' => $cursor, 'requests' => $requests, 'records' => $records,
            'backlog_seconds' => (int) max(0, ceil(($until - $cursor) / 1000000000)),
            'partial' => $windows !== [] || $cursor < $until];
    }
}

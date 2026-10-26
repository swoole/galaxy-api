<?php

namespace App\Services\Gateway;

use App\Exception\AppException;

/** Shared by the report, hourly charts and CSV export. */
final class WafEventFilters
{
    public const RULES = [
        'hours' => 'nullable|integer|min:1|max:720', 'policy_id' => 'nullable|integer|min:1',
        'action' => 'nullable|in:block,monitor,detected,challenge', 'ip' => 'nullable|ip',
        'page' => 'nullable|integer|min:1|max:10000', 'method' => 'nullable|string|max:16',
        'path' => 'nullable|string|max:2048', 'rule_id' => 'nullable|string|max:8',
        'from' => 'nullable|integer|min:1', 'until' => 'nullable|integer|min:1',
    ];

    public static function normalize(array $filters, ?int $now = null): array
    {
        $now ??= time();
        $hours = (int) ($filters['hours'] ?? 24);
        if ($hours < 1 || $hours > 720) throw new AppException(422, '统计范围须为 1 到 720 小时');
        $from = $filters['from'] ?? null;
        $until = $filters['until'] ?? null;
        if (($from === null) !== ($until === null)) throw new AppException(422, '请同时提供开始和结束时间');
        $from = $from === null ? $now - $hours * 3600 : (int) $from;
        $until = $until === null ? $now : (int) $until;
        if ($from < 1 || $from > $until || $until - $from > 86400 * 30) throw new AppException(422, '时间范围无效，最多查询连续 30 天');
        $result = ['from' => $from, 'until' => $until, 'page' => max(1, min(10000, (int) ($filters['page'] ?? 1)))];
        foreach (['policy_id', 'action', 'ip', 'method', 'path', 'rule_id'] as $key) {
            if (isset($filters[$key]) && $filters[$key] !== '') $result[$key] = $filters[$key];
        }
        if (isset($result['method'])) {
            if (! is_string($result['method']) || ! preg_match('/^[A-Z]{1,16}$/D', $result['method'])) throw new AppException(422, '请求方法须为大写英文字母');
        }
        if (isset($result['rule_id']) && (! is_string($result['rule_id']) || ! preg_match('/^(?:CC|AUTO|BROWSER|GEO|INTEL|DATA|GATEWAY|[0-9]{5,6})$/D', $result['rule_id']))) throw new AppException(422, '规则 ID 须为数字、CC、AUTO、BROWSER、GEO、INTEL、DATA 或 GATEWAY');
        if (isset($result['path']) && (! is_string($result['path']) || ! str_starts_with($result['path'], '/') || strlen($result['path']) > 2048 || preg_match('/[\x00-\x1f\x7f]/', $result['path']))) throw new AppException(422, '路径须以 / 开头且不含控制字符');
        return $result;
    }
}

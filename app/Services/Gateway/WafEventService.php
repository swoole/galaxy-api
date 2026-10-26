<?php

namespace App\Services\Gateway;

use App\Model\Cluster;
use App\Model\WafEvent;
use App\Model\WafPolicy;
use App\Exception\AppException;
use Hyperf\Redis\Redis;

final class WafEventService
{
    public const EXPORT_LIMIT = 5000;
    public function __construct(private Redis $redis, private WafLogCollector $collector) {}

    public function collect(): void
    {
        $this->collector->collect();
    }

    public function report(Cluster $cluster, array $filters, ?array $projectRouteIds = null): array
    {
        if (! config('waf.enabled', false)) {
            return ['summary' => ['events' => 0, 'blocked' => 0, 'monitored' => 0, 'ips' => 0],
                'trend' => [], 'top_ips' => [], 'top_paths' => [], 'events' => [], 'total' => 0, 'page' => 1, 'page_size' => 20,
                'note' => 'WAF 模块尚未初始化启用'];
        }
        $filters = WafEventFilters::normalize($filters);
        $query = $this->eventQuery($cluster, $filters, $projectRouteIds);
        $summary = [
            'events' => (clone $query)->count(),
            'blocked' => (clone $query)->where('action', 'block')->count(),
            'monitored' => (clone $query)->where('action', 'monitor')->count(),
            'ips' => (clone $query)->distinct()->count('client_ip'),
        ];
        $trend = (clone $query)->selectRaw('FLOOR(occurred_at / 3600) * 3600 AS bucket, COUNT(*) AS total, SUM(action = ?) AS blocked', ['block'])
            ->groupBy('bucket')->orderBy('bucket')->get()->toArray();
        $hourly = array_column($trend, null, 'bucket');
        $trend = [];
        for ($bucket = (int) floor($filters['from'] / 3600) * 3600, $end = (int) floor($filters['until'] / 3600) * 3600; $bucket <= $end; $bucket += 3600) {
            $trend[] = $hourly[$bucket] ?? ['bucket' => $bucket, 'total' => 0, 'blocked' => 0];
        }
        $topIps = (clone $query)->selectRaw('client_ip, COUNT(*) AS total')->groupBy('client_ip')->orderByDesc('total')->limit(8)->get()->toArray();
        $topPaths = (clone $query)->selectRaw('uri, COUNT(*) AS total')->groupBy('uri')->orderByDesc('total')->limit(8)->get()->toArray();
        $page = (int) ($filters['page'] ?? 1);
        return ['summary' => $summary, 'trend' => $trend, 'top_ips' => $topIps, 'top_paths' => $topPaths,
            'events' => (clone $query)->orderByDesc('occurred_at')->orderByDesc('id')->forPage($page, 20)->get(),
            'total' => $summary['events'], 'page' => $page, 'page_size' => 20,
            'note' => '事件保留 30 天；按容器时间游标分段补采，重复事件去重。日志已被 Docker 清理或长时间离线时仍可能有缺口，请留意积压与采集告警。'];
    }

    public function export(Cluster $cluster, array $filters, ?array $projectRouteIds = null): array
    {
        if (! config('waf.enabled', false)) throw new AppException(409, 'WAF 模块尚未初始化启用');
        $query = $this->eventQuery($cluster, WafEventFilters::normalize($filters), $projectRouteIds);
        $stream = fopen('php://temp/maxmemory:1048576', 'w+');
        if ($stream === false) throw new AppException(500, '无法创建事件导出文件');
        try {
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, ['事件ID', '发生时间(UTC)', '策略ID', '来源IP', '方法', '路径', '动作', 'HTTP状态', '规则ID', '规则摘要'], ',', '"', '');
            $count = 0; $cursor = PHP_INT_MAX; $truncated = false;
            // Keyset pagination bounds memory and excludes newer inserts after the first batch.
            while (true) {
                $events = (clone $query)->where('id', '<', $cursor)->orderByDesc('id')->limit(min(200, self::EXPORT_LIMIT - $count + 1))->get();
                if ($events->isEmpty()) break;
                foreach ($events as $event) {
                    if ($count === self::EXPORT_LIMIT) { $truncated = true; break 2; }
                    $messages = array_slice((array) $event->messages, 0, 30);
                    $row = [$event->id, gmdate('Y-m-d H:i:s', (int) $event->occurred_at), $event->policy_id,
                        $event->client_ip, $event->method, $event->uri, $event->action, $event->status_code,
                        implode(' ', array_unique(array_column($messages, 'rule_id'))),
                        mb_substr(implode(' | ', array_column($messages, 'message')), 0, 512)];
                    $row = array_map(static function ($cell): string {
                        $cell = (string) $cell;
                        // CSV quoting does not prevent spreadsheet formula execution.
                        return preg_match('/^[\x00-\x20]*[=+@-]|^[\t\r\n]/', $cell) ? "'" . $cell : $cell;
                    }, $row);
                    fputcsv($stream, $row, ',', '"', '');
                    ++$count; $cursor = (int) $event->id;
                }
            }
            rewind($stream);
            return ['content' => stream_get_contents($stream), 'count' => $count, 'truncated' => $truncated];
        } finally { fclose($stream); }
    }

    private function eventQuery(Cluster $cluster, array $filters, ?array $projectRouteIds): \Hyperf\Database\Model\Builder
    {
        $query = WafEvent::where('org_id', $cluster->org_id)->where('cluster_id', $cluster->id)
            ->whereBetween('occurred_at', [$filters['from'], $filters['until']]);
        if ($projectRouteIds !== null) $query->whereIn('policy_id', WafPolicy::forRoutes($cluster, $projectRouteIds)->select('id'));
        foreach (['policy_id', 'action', 'method'] as $key) if (isset($filters[$key])) $query->where($key, $filters[$key]);
        if (isset($filters['ip'])) $query->where('client_ip', $filters['ip']);
        if (isset($filters['path'])) $query->whereRaw("uri LIKE ? ESCAPE '!'", ['%' . strtr($filters['path'], ['!' => '!!', '%' => '!%', '_' => '!_']) . '%']);
        if (isset($filters['rule_id'])) $query->whereRaw('JSON_CONTAINS(messages, ?) = 1', [json_encode(['rule_id' => $filters['rule_id']], JSON_THROW_ON_ERROR)]);
        return $query;
    }

    public function collectionStatus(int $policyId): array
    {
        return json_decode($this->redis->get('galaxy:waf:collection:' . $policyId) ?: '{}', true) ?: [];
    }
}

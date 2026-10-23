<?php

namespace App\Services\Gateway;

use App\Model\Cluster;
use App\Model\WafEvent;
use App\Model\WafPolicy;
use App\Services\Docker\SwarmApiClient;
use GuzzleHttp\Client;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\Redis\Redis;
use Throwable;

final class WafEventService
{
    public function __construct(private SwarmApiClient $docker, private WafLogParser $parser,
        private Redis $redis, private StdoutLoggerInterface $logger) {}

    public function collect(): void
    {
        foreach (WafPolicy::where('service_id', '<>', '')->get() as $policy) {
            $key = 'galaxy:waf:collect:' . $policy->id;
            $token = bin2hex(random_bytes(16));
            if (! $this->redis->set($key, $token, ['nx', 'ex' => 90])) continue;
            try {
                $cluster = Cluster::where('org_id', $policy->org_id)->where('id', $policy->cluster_id)->first();
                if ($cluster === null) continue;
                $cursorKey = 'galaxy:waf:cursor:' . $policy->service_id;
                $since = (int) ($this->redis->get($cursorKey) ?: time() - 300);
                $started = time();
                $raw = $this->docker->withCluster($cluster, fn (Client $client, SwarmApiClient $api) =>
                    $api->requestRaw($client, 'GET', '/services/' . rawurlencode((string) $policy->service_id) . '/logs', [
                        'query' => ['stdout' => 1, 'stderr' => 0, 'timestamps' => 0, 'tail' => 2000, 'since' => max(0, $since - 5)],
                    ]));
                foreach ($this->parser->parse($raw, $policy->applied_mode) as $event) {
                    $event['event_key'] = hash('sha256', $policy->service_id . ':' . $event['request_id']);
                    unset($event['request_id']);
                    $event['org_id'] = $policy->org_id;
                    $event['cluster_id'] = $policy->cluster_id;
                    $event['policy_id'] = $policy->id;
                    $event['messages'] = json_encode($event['messages'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                    $inserted = WafEvent::query()->insertOrIgnore($event);
                    if (! $inserted && $event['status_code'] > 0) {
                        // Audit output may arrive before the final access-log line.
                        WafEvent::where('event_key', $event['event_key'])->where('status_code', 0)->update($event);
                    }
                }
                $this->redis->setex($cursorKey, 86400 * 31, (string) $started);
                $this->redis->setex('galaxy:waf:collection:' . $policy->id, 86400 * 31, json_encode([
                    'at' => time(), 'error' => '', 'sample_limit' => 2000,
                ]));
            } catch (Throwable $e) {
                $this->redis->setex('galaxy:waf:collection:' . $policy->id, 86400, json_encode(['at' => time(), 'error' => mb_substr($e->getMessage(), 0, 1000)]));
                $this->logger->warning('WAF 日志采集失败 policy=' . $policy->id . ': ' . $e->getMessage());
            } finally {
                $this->redis->eval("if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) end return 0", [$key, $token], 1);
            }
        }
        WafEvent::where('occurred_at', '<', time() - 86400 * 30)->limit(5000)->delete();
    }

    public function report(Cluster $cluster, array $filters, ?array $projectRouteIds = null): array
    {
        if (! config('waf.enabled', false)) {
            return ['summary' => ['events' => 0, 'blocked' => 0, 'monitored' => 0, 'ips' => 0],
                'trend' => [], 'top_ips' => [], 'events' => [], 'total' => 0, 'page' => 1, 'page_size' => 20,
                'note' => 'WAF 模块尚未初始化启用'];
        }
        $hours = (int) ($filters['hours'] ?? 24);
        $query = WafEvent::where('org_id', $cluster->org_id)->where('cluster_id', $cluster->id)
            ->where('occurred_at', '>=', time() - $hours * 3600);
        if ($projectRouteIds !== null) $query->whereIn('policy_id', WafPolicy::forRoutes($cluster, $projectRouteIds)->select('id'));
        if (! empty($filters['policy_id'])) $query->where('policy_id', $filters['policy_id']);
        if (! empty($filters['action'])) $query->where('action', $filters['action']);
        if (! empty($filters['ip'])) $query->where('client_ip', $filters['ip']);
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
        for ($bucket = (int) floor((time() - $hours * 3600) / 3600) * 3600, $end = (int) floor(time() / 3600) * 3600; $bucket <= $end; $bucket += 3600) {
            $trend[] = $hourly[$bucket] ?? ['bucket' => $bucket, 'total' => 0, 'blocked' => 0];
        }
        $topIps = (clone $query)->selectRaw('client_ip, COUNT(*) AS total')->groupBy('client_ip')->orderByDesc('total')->limit(8)->get()->toArray();
        $page = (int) ($filters['page'] ?? 1);
        return ['summary' => $summary, 'trend' => $trend, 'top_ips' => $topIps,
            'events' => (clone $query)->orderByDesc('occurred_at')->orderByDesc('id')->forPage($page, 20)->get(),
            'total' => $summary['events'], 'page' => $page, 'page_size' => 20,
            'note' => '事件保留 30 天；每 30 秒采集每条策略最近 2000 行日志，高流量或离线期间可能存在缺口。'];
    }

    public function collectionStatus(int $policyId): array
    {
        return json_decode($this->redis->get('galaxy:waf:collection:' . $policyId) ?: '{}', true) ?: [];
    }
}

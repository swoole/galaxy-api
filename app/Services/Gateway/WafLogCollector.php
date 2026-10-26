<?php

namespace App\Services\Gateway;

use App\Model\Cluster;
use App\Model\WafEvent;
use App\Model\WafPolicy;
use App\Exception\AppException;
use App\Services\Docker\SwarmApiClient;
use GuzzleHttp\Client;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\Redis\Redis;
use RuntimeException;
use Throwable;

final class WafLogCollector
{
    public function __construct(private SwarmApiClient $docker, private WafLogParser $parser,
        private WafLogWindowReader $reader, private Redis $redis, private StdoutLoggerInterface $logger,
        private WafAlertService $alerts, private WafRetirementService $retirement,
        private WafRuntimeConfigRegistry $controls, private WafAutoBanService $bans) {}

    public function collect(): void
    {
        if (! config('waf.enabled', false)) return;
        $policies = WafPolicy::orderBy('id')->get();
        $after = (int) ($this->redis->get('galaxy:waf:collector:after') ?: 0);
        $ordered = $policies->filter(fn ($p) => $p->id > $after)->concat($policies->filter(fn ($p) => $p->id <= $after));
        $deadline = microtime(true) + 15;
        foreach ($ordered as $policy) {
            if (microtime(true) >= $deadline) break;
            $lock = 'galaxy:waf:collect:' . $policy->id; $token = bin2hex(random_bytes(16));
            if (! $this->redis->set($lock, $token, ['nx', 'ex' => 120])) continue;
            $status = ['at' => time(), 'error' => '', 'records' => 0, 'backlog_seconds' => 0, 'gap' => false];
            $cluster = null;
            try {
                $cluster = Cluster::where('org_id', $policy->org_id)->where('id', $policy->cluster_id)->first();
                if ($cluster === null) continue;
                $status = $this->collectPolicy($policy, $cluster, min($deadline, microtime(true) + 3));
                $this->redis->setex('galaxy:waf:collection:' . $policy->id, 86400 * 31, json_encode($status));
            } catch (Throwable $e) {
                $status['error'] = mb_substr($e->getMessage(), 0, 1000);
                $this->redis->setex('galaxy:waf:collection:' . $policy->id, 86400 * 31, json_encode($status));
                $this->logger->warning('WAF 采集暂缓 policy=' . $policy->id . ': ' . $status['error']);
            } finally {
                try {
                    if (isset($cluster) && $this->redis->set('galaxy:waf:alert-evaluate:' . $policy->id, '1', ['nx', 'ex' => 30])) $this->alerts->evaluate($policy, $cluster, $status);
                } catch (Throwable $e) { $this->logger->warning('WAF 告警评估失败：' . $e->getMessage()); }
                $this->redis->setex('galaxy:waf:collector:after', 86400 * 31, (string) $policy->id);
                $this->redis->eval("if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) end return 0", [$lock, $token], 1);
            }
        }
        WafEvent::where('occurred_at', '<', time() - 86400 * 30)->limit(5000)->delete();
        $this->bans->prune();
    }

    public function collectPolicy(WafPolicy $policy, Cluster $cluster, float $deadline): array
    {
        $status = ['at' => time(), 'error' => '', 'records' => 0, 'backlog_seconds' => 0, 'gap' => false,
            'control' => $policy->service_id === '' ? ['enabled' => false] : []];
        $generations = $this->retirement->pending($policy);
        if ($policy->service_id !== '') $generations[] = ['service_id' => $policy->service_id, 'mode' => $policy->applied_mode, 'retired_at' => 0];
        $afterKey = 'galaxy:waf:generation-after:' . $policy->id;
        $generations = $this->rotate($generations, (string) ($this->redis->get($afterKey) ?: ''), 'service_id');
        foreach ($generations as $generation) {
            if (microtime(true) >= $deadline) { $status['backlog_seconds'] = max(1, $status['backlog_seconds']); continue; }
            $copy = clone $policy; $copy->service_id = $generation['service_id']; $copy->applied_mode = $generation['mode'];
            try {
                $part = $this->collectGeneration($copy, $cluster, $deadline);
                $status['records'] += $part['records'];
                $status['backlog_seconds'] = max($status['backlog_seconds'], $part['backlog_seconds']);
                if ($generation['retired_at'] === 0) $status['control'] = $part['control'];
                if ($part['error'] !== '') $status['error'] = $part['error'];
                if ($generation['retired_at'] > 0 && time() - $generation['retired_at'] >= 120
                    && $part['backlog_seconds'] === 0 && $part['error'] === '') $this->retirement->remove($cluster, $policy, $generation);
            } catch (Throwable $e) { $status['error'] = mb_substr($e->getMessage(), 0, 1000); }
            // Bound abandoned generations even if their node stays unavailable. Report the loss explicitly.
            if ($generation['retired_at'] > 0 && time() - $generation['retired_at'] > 3600) {
                $this->recordGap($policy, '旧实例补采超过一小时，早期日志可能存在缺口');
                try { $this->retirement->remove($cluster, $policy, $generation); }
                catch (Throwable $e) { $status['error'] = mb_substr($e->getMessage(), 0, 1000); }
            }
            $this->redis->setex($afterKey, 86400 * 31, $generation['service_id']);
        }
        $gap = json_decode($this->redis->get('galaxy:waf:gap:' . $policy->id) ?: '{}', true) ?: [];
        $status['gap'] = (int) ($gap['at'] ?? 0) > time() - 86400;
        $status['last_gap'] = $gap;
        return $status;
    }

    private function collectGeneration(WafPolicy $policy, Cluster $cluster, float $deadline): array
    {
        $status = ['error' => '', 'records' => 0, 'backlog_seconds' => 0, 'control' => ['enabled' => false]];
        $tasks = $this->docker->withCluster($cluster, fn (Client $client, SwarmApiClient $api) => $api->request($client, 'GET', '/tasks',
            ['query' => ['filters' => json_encode(['service' => [$policy->service_id]])]]), 5);
        $targets = [];
        foreach ($tasks as $task) {
            $id = (string) ($task['Status']['ContainerStatus']['ContainerID'] ?? ''); $node = (string) ($task['NodeID'] ?? '');
            $hash = '';
            foreach ($task['Spec']['ContainerSpec']['Env'] ?? [] as $env) if (str_starts_with($env, 'GALAXY_WAF_CONTROL_CONFIG=')) $hash = substr($env, strlen('GALAXY_WAF_CONTROL_CONFIG='));
            if ($id !== '' && $node !== '') $targets[$id] = ['container' => $id, 'node' => $node, 'control_hash' => $hash];
        }
        if ($targets === []) throw new RuntimeException('检测实例没有可读取日志的容器');
        $until = (time() - 2) * 1000000000;
        $afterKey = 'galaxy:waf:container-after:' . $policy->service_id;
        $targets = $this->rotate(array_values($targets), (string) ($this->redis->get($afterKey) ?: ''), 'container');
        foreach ($targets as $target) {
            ['container' => $container, 'node' => $node] = $target;
            $snapshot = $target['control_hash'] === '' ? null : $this->controls->find($policy, $target['control_hash']);
            if ($target['control_hash'] !== '' && $snapshot === null) throw new RuntimeException('动态防护配置快照不可用，暂缓推进采集游标');
            $healthKey = 'galaxy:waf:control-health:' . $policy->org_id . ':' . $policy->cluster_id . ':' . $policy->service_id;
            $controlHealth = $snapshot === null ? [] : (json_decode($this->redis->get($healthKey) ?: '{}', true) ?: []);
            if ($snapshot !== null) $status['control'] = ['enabled' => true] + $controlHealth;
            if ($this->redis->get('galaxy:waf:gone:' . $container)) continue;
            $key = 'galaxy:waf:cursor:v2:' . $policy->service_id . ':' . $container;
            $stored = $this->redis->get($key);
            $cursor = $stored ? (int) $stored : (int) ($this->redis->get('galaxy:waf:cursor:' . $policy->service_id) ?: time() - 300) * 1000000000;
            $oldest = (time() - 86400) * 1000000000;
            if ($cursor < $oldest) { $this->recordGap($policy, '采集离线超过 24 小时，早期日志无法保证完整'); $cursor = $oldest; }
            $from = max($oldest, $cursor - 1000000000);
            if (microtime(true) >= $deadline) { $status['backlog_seconds'] = max($status['backlog_seconds'], (int) ceil(max(0, $until - $cursor) / 1000000000)); continue; }
            $stateKey = 'galaxy:waf:pending:' . $policy->service_id . ':' . $container;
            $state = json_decode($this->redis->get($stateKey) ?: '{}', true) ?: [];
            try {
                $result = $this->reader->read($from, $until,
                fn ($start, $end) => $this->docker->requestLogWindow($cluster, $node, $container, [
                        'stdout' => 1, 'stderr' => 0, 'timestamps' => 1, 'follow' => 0,
                        'since' => WafLogBatch::timestamp($start), 'until' => WafLogBatch::timestamp($end - 1),
                    ]),
                function ($plain, $checkpoint) use ($policy, $key, $stateKey, $cursor, $snapshot, $healthKey, &$state, &$controlHealth): void {
                    $observations = [];
                    $events = $this->parser->parse($plain, $policy->applied_mode, $state, $observations);
                    $this->persist($policy, $events);
                    if ($snapshot !== null) $this->bans->observe($policy, $snapshot, $events, $observations);
                    if ($snapshot !== null) {
                        foreach ($observations as $entry) {
                            if (empty($entry['_fresh']) || ! preg_match('/(?:^|[,:]\\s*)([1-5][0-9]{2})$/D', (string) ($entry['control_status'] ?? ''), $code)) continue;
                            $ms = isset($entry['msec']) && is_numeric($entry['msec']) ? (int) round((float) $entry['msec'] * 1000) : (strtotime($entry['time'] ?? '') ?: 0) * 1000;
                            if ($ms > 0 && $ms <= (time() + 5) * 1000 && $ms >= ($controlHealth['ms'] ?? 0)) $controlHealth = ['status' => (int) $code[1], 'protocol' => ($entry['control_protocol'] ?? '') === '1', 'data_status' => $entry['data_status'] ?? '', 'at' => intdiv($ms, 1000), 'ms' => $ms];
                        }
                        if ($controlHealth !== []) $this->redis->setex($healthKey, 86400 * 31, json_encode($controlHealth));
                    }
                    // Persist safe correlation data before advancing the cursor; failed writes replay idempotently.
                    $this->redis->setex($stateKey, 21600, json_encode($this->parser->pending($state), JSON_INVALID_UTF8_SUBSTITUTE));
                    $this->redis->setex($key, 86400 * 31, (string) max($cursor, $checkpoint));
                }, $deadline);
                $status['records'] += $result['records'];
                $status['backlog_seconds'] = max($status['backlog_seconds'], $result['backlog_seconds']);
                if ($snapshot !== null) $status['control'] = ['enabled' => true] + $controlHealth;
            } catch (Throwable $e) {
                if ($e instanceof AppException && ($e->getContext()['docker_status'] ?? 0) === 404) {
                    $this->recordGap($policy, '容器日志已被 Docker 清理：' . substr($container, 0, 12));
                    $this->redis->setex('galaxy:waf:gone:' . $container, 86400 * 31, '1');
                } else { $status['error'] = mb_substr($e->getMessage(), 0, 1000); }
            }
            $this->redis->setex($afterKey, 86400 * 31, $container);
        }
        return $status;
    }

    private function recordGap(WafPolicy $policy, string $reason): void
    { $this->redis->setex('galaxy:waf:gap:' . $policy->id, 86400 * 31, json_encode(['at' => time(), 'reason' => $reason], JSON_UNESCAPED_UNICODE)); }

    private function rotate(array $targets, string $after, string $field): array
    {
        usort($targets, static fn ($a, $b) => strcmp($a[$field], $b[$field]));
        return array_merge(array_values(array_filter($targets, static fn ($row) => strcmp($row[$field], $after) > 0)),
            array_values(array_filter($targets, static fn ($row) => strcmp($row[$field], $after) <= 0)));
    }

    public function persist(WafPolicy $policy, array $events): int
    {
        $inserted = 0;
        foreach ($events as $event) {
            $event['event_key'] = hash('sha256', $policy->service_id . ':' . $event['request_id']); unset($event['request_id']);
            $event += ['org_id' => $policy->org_id, 'cluster_id' => $policy->cluster_id, 'policy_id' => $policy->id];
            $event['messages'] = json_encode($event['messages'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            $new = WafEvent::query()->insertOrIgnore($event); $inserted += (int) $new;
            if (! $new && $event['status_code'] > 0) WafEvent::where('event_key', $event['event_key'])->where('status_code', 0)->update($event);
        }
        return $inserted;
    }
}

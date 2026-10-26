<?php

namespace App\Services\Gateway;

use App\Exception\AppException;
use App\Model\Cluster;
use App\Model\WafBan;
use App\Model\WafPolicy;
use App\Model\WafRuntimeConfig;
use App\Model\WafSignal;
use Hyperf\Database\Model\Builder;
use Hyperf\Redis\Redis;

/** SQL signals are idempotent and durable; short decision caches bound release/failover staleness. */
final class WafAutoBanService
{
    public function __construct(private WafRuntimeConfigRegistry $configs, private WafClientIp $ips, private Redis $redis) {}

    public function decision(WafRuntimeConfig $config, string $rawIp): array
    {
        $ip = $this->ips->normalize($rawIp);
        if ($ip === null) throw new AppException(400, 'Invalid client IP');
        $settings = $config->settings;
        $result = ['status' => 204, 'action' => 'allow', 'retry_after' => 0];
        if ($settings['mode'] === 'off' || ! $settings['auto_ban']['enabled'] || $this->ips->matches($ip, $settings['ip_allowlist'])) return $result;
        $key = $this->decisionKey($config->org_id, $config->cluster_id, $config->policy_id, $ip);
        $cached = $this->redis->get($key);
        if ($cached === false) {
            $expires = (int) (WafBan::where('org_id', $config->org_id)->where('cluster_id', $config->cluster_id)->where('policy_id', $config->policy_id)
                ->where('client_ip', $ip)->where('status', 'active')->value('expires_at') ?: 0);
            $this->redis->setex($key, 5, (string) $expires);
        } else $expires = (int) $cached;
        if ($expires <= time()) return $result;
        return ['status' => $settings['mode'] === 'monitor' ? 204 : 403,
            'action' => $settings['mode'] === 'monitor' ? 'monitor' : 'block', 'retry_after' => $expires - time()];
    }

    /** Called before the log cursor advances, even when event inserts have already been replayed. */
    public function observe(WafPolicy $policy, WafRuntimeConfig $config, array $events, array $access): void
    {
        $settings = $config->settings; $rule = $settings['auto_ban'];
        if ($policy->org_id !== $config->org_id || $policy->cluster_id !== $config->cluster_id || $policy->id !== $config->policy_id) throw new AppException(403, 'WAF signal scope mismatch');
        if (! $rule['enabled']) return;
        $signals = [];
        $accessById = [];
        foreach ($access as $entry) $accessById[(string) $entry['id']] = $entry;
        if ($rule['attack_enabled']) foreach ($events as $event) {
            $entry = $accessById[$event['request_id']] ?? [];
            $at = strtotime($entry['time'] ?? '');
            if ($at === false || (int) $event['status_code'] <= 0 || ! in_array($event['action'], ['block','monitor'], true)) continue;
            $messages = array_filter($event['messages'], static fn ($message) => ! in_array((string) $message['rule_id'], ['AUTO','BROWSER','GEO','INTEL','DATA','GATEWAY','12000','12001','12002','12003'], true));
            if ($messages !== []) $this->signal($signals, $policy, $settings, $event['request_id'], $event['client_ip'], $event['uri'], $at, 'attack');
        }
        if ($rule['not_found_enabled']) foreach ($access as $entry) {
            if ((int) ($entry['status'] ?? 0) !== 404 || ! preg_match('/(?:^|,\s*)404$/D', (string) ($entry['origin_status'] ?? ''))) continue;
            $at = strtotime($entry['time'] ?? '');
            if ($at !== false) $this->signal($signals, $policy, $settings, (string) $entry['id'], (string) ($entry['ip'] ?? ''),
                (string) ($entry['uri'] ?? ''), $at, 'not_found');
        }
        if ($signals === []) return;
        $invalidations = [];
        foreach (array_chunk(array_values($signals), 128) as $batch) {
            $policy->getConnection()->transaction(function () use ($policy, $config, $batch, $rule, &$invalidations): void {
                $signals = $batch;
                // Serialize observers with a manual release, including different API replicas and generations.
                $this->lockPolicy($policy);
                $ips = array_values(array_unique(array_column($signals, 'client_ip')));
                $prior = $this->banQuery($policy)->whereIn('client_ip', $ips)->get()->keyBy('client_ip');
                $watermarks = [];
                foreach ($ips as $ip) {
                    $ban = $prior->get($ip);
                    // Never reactivate an expired ban just because old logs were replayed.
                    $watermarks[$ip] = $ban ? max($ban->released_at, $ban->expires_at) : 0;
                    if ($ban && $ban->status === 'active' && $ban->expires_at > time()) $invalidations[] = $ip;
                }
                $signals = array_values(array_filter($signals, static fn ($row) => $row['occurred_at'] > $watermarks[$row['client_ip']]));
                if ($signals === []) return;
                $capacity = max(0, (int) config('waf.max_auto_signals', 100000) - WafSignal::where('policy_id', $policy->id)->count());
                $signals = array_slice($signals, 0, $capacity);
                if ($signals !== []) WafSignal::query()->insertOrIgnore($signals);
                $counts = WafSignal::where('org_id', $policy->org_id)->where('cluster_id', $policy->cluster_id)->where('policy_id', $policy->id)
                    ->whereIn('client_ip', $ips)->where('occurred_at', '>=', time() - $rule['window_seconds'])
                    ->where(function ($query) use ($watermarks): void {
                        foreach ($watermarks as $ip => $at) $query->orWhere(fn ($query) => $query->where('client_ip', $ip)->where('occurred_at', '>', $at));
                    })
                    ->selectRaw('client_ip, kind, COUNT(*) AS total')->groupBy('client_ip', 'kind')->get();
                foreach ($counts as $count) {
                    $threshold = $count->kind === 'attack' ? $rule['attack_threshold'] : $rule['not_found_threshold'];
                    if (($count->kind === 'attack' ? ! $rule['attack_enabled'] : ! $rule['not_found_enabled']) || $count->total < $threshold) continue;
                    $ban = $this->banQuery($policy)->where('client_ip', $count->client_ip)->first();
                    if ($ban && $ban->status === 'active' && $ban->expires_at > time()) { $invalidations[] = $ban->client_ip; continue; }
                    if ($this->banQuery($policy)->where('status', 'active')->where('expires_at', '>', time())->count() >= (int) config('waf.max_auto_bans', 1000)) continue;
                    if (! $ban && $this->banQuery($policy)->count() >= (int) config('waf.max_auto_ban_records', 10000)) continue;
                    WafBan::updateOrCreate(['policy_id' => $policy->id, 'client_ip' => $count->client_ip], [
                        'org_id' => $policy->org_id, 'cluster_id' => $policy->cluster_id, 'config_hash' => $config->config_hash,
                        'reason' => $count->kind, 'events_count' => (int) $count->total, 'status' => 'active',
                        'triggered_at' => time(), 'expires_at' => time() + $rule['duration_seconds'],
                        // Preserve the release watermark to reject old replayed logs after a future incident.
                        'released_at' => $ban?->released_at ?? 0, 'released_by' => $ban?->released_by ?? 0]);
                    $invalidations[] = $count->client_ip;
                }
            });
        }
        foreach (array_unique($invalidations) as $ip) $this->invalidate($policy, $ip);
    }

    private function signal(array &$signals, WafPolicy $policy, array $settings, string $requestId, string $ip, string $path, int $at, string $kind): void
    {
        $ip = $this->ips->normalize($ip);
        $path = explode('?', $path)[0];
        if ($ip === null || $requestId === '' || $at < time() - $settings['auto_ban']['window_seconds'] || $at > time() + 5
            || $this->ips->matches($ip, $settings['ip_allowlist'])
            || in_array($path, array_merge($settings['excluded_paths'], $settings['auto_ban']['excluded_paths']), true)) return;
        $key = hash('sha256', $policy->service_id . ':' . $requestId . ':' . $kind);
        $signals[$key] = ['org_id' => $policy->org_id, 'cluster_id' => $policy->cluster_id, 'policy_id' => $policy->id,
            'signal_key' => $key, 'client_ip' => $ip, 'kind' => $kind, 'occurred_at' => min(time(), $at)];
    }

    public function listing(Cluster $cluster, ?array $projectRouteIds, array $filters = []): array
    {
        if (! $this->configs->initialized()) return ['bans' => [], 'total' => 0, 'usage' => [], 'limits' => []];
        $query = $this->scoped($cluster, $projectRouteIds);
        $status = $filters['status'] ?? 'active';
        if ($status === 'active') $query->where('status', 'active')->where('expires_at', '>', time());
        elseif ($status === 'expired') $query->where('status', 'active')->where('expires_at', '<=', time());
        elseif ($status === 'released') $query->where('status', 'released');
        elseif ($status !== 'all') throw new AppException(422, '封禁状态无效');
        if (! empty($filters['policy_id'])) $query->where('policy_id', (int) $filters['policy_id']);
        if (! empty($filters['ip'])) {
            $ip = $this->ips->normalize((string) $filters['ip']);
            if ($ip === null) throw new AppException(422, 'IP 无效');
            $query->where('client_ip', $ip);
        }
        $page = max(1, min(10000, (int) ($filters['page'] ?? 1)));
        $policyIds = WafPolicy::forRoutes($cluster, $projectRouteIds)->pluck('id')->all();
        $signals = WafSignal::where('org_id', $cluster->org_id)->where('cluster_id', $cluster->id)->whereIn('policy_id', $policyIds)
            ->selectRaw('policy_id, COUNT(*) AS total')->groupBy('policy_id')->pluck('total', 'policy_id')->all();
        $active = $this->scoped($cluster, $projectRouteIds)->where('status', 'active')->where('expires_at', '>', time())
            ->selectRaw('policy_id, COUNT(*) AS total')->groupBy('policy_id')->pluck('total', 'policy_id')->all();
        $records = $this->scoped($cluster, $projectRouteIds)->selectRaw('policy_id, COUNT(*) AS total')->groupBy('policy_id')->pluck('total', 'policy_id')->all();
        return ['bans' => (clone $query)->orderByDesc('triggered_at')->orderByDesc('id')->forPage($page, 50)->get(),
            'total' => (clone $query)->count(), 'page' => $page, 'page_size' => 50,
            'usage' => array_map(static fn ($id) => ['policy_id' => $id, 'signals' => (int) ($signals[$id] ?? 0),
                'active' => (int) ($active[$id] ?? 0), 'records' => (int) ($records[$id] ?? 0)], $policyIds),
            'limits' => ['active' => (int) config('waf.max_auto_bans', 1000), 'records' => (int) config('waf.max_auto_ban_records', 10000), 'signals' => (int) config('waf.max_auto_signals', 100000)]];
    }

    public function release(Cluster $cluster, int $id, ?array $projectRouteIds, int $actorId): void
    {
        if (! $this->configs->initialized()) throw new AppException(409, '请先应用 0062_add_waf_dynamic_control.sql');
        $ban = $this->scoped($cluster, $projectRouteIds)->where('id', $id)->first();
        if ($ban === null) throw new AppException(404, '当前作用域中不存在该封禁记录');
        $policy = WafPolicy::forRoutes($cluster, $projectRouteIds)->where('id', $ban->policy_id)->first();
        if ($policy === null) throw new AppException(404, '当前作用域中不存在该策略');
        $policy->getConnection()->transaction(function () use ($policy, $ban, $actorId): void {
            $this->lockPolicy($policy);
            $ban->refresh();
            $ban->status = 'released'; $ban->expires_at = time(); $ban->released_at = time(); $ban->released_by = $actorId; $ban->save();
            WafSignal::where('org_id', $policy->org_id)->where('cluster_id', $policy->cluster_id)->where('policy_id', $policy->id)->where('client_ip', $ban->client_ip)->delete();
        });
        $this->invalidate($policy, $ban->client_ip);
    }

    public function prune(): void
    {
        if (! $this->configs->initialized()) return;
        WafSignal::where('occurred_at', '<', time() - 7200)->limit(5000)->delete();
        WafBan::where('expires_at', '<', time() - 86400 * 30)->limit(5000)->delete();
    }

    private function scoped(Cluster $cluster, ?array $ids): Builder
    { return WafBan::where('org_id', $cluster->org_id)->where('cluster_id', $cluster->id)->whereIn('policy_id', WafPolicy::forRoutes($cluster, $ids)->select('id')); }
    private function banQuery(WafPolicy $policy): Builder
    { return WafBan::where('org_id', $policy->org_id)->where('cluster_id', $policy->cluster_id)->where('policy_id', $policy->id); }
    private function lockPolicy(WafPolicy $policy): void
    { if (! WafPolicy::where('org_id', $policy->org_id)->where('cluster_id', $policy->cluster_id)->where('id', $policy->id)->lockForUpdate()->first()) throw new AppException(404, 'WAF policy no longer exists'); }
    private function decisionKey(int $org, int $cluster, int $policy, string $ip): string
    { return 'galaxy:waf:decision:' . $org . ':' . $cluster . ':' . $policy . ':' . hash('sha256', $ip); }
    private function invalidate(WafPolicy $policy, string $ip): void
    { $this->redis->del($this->decisionKey($policy->org_id, $policy->cluster_id, $policy->id, $ip)); }
}

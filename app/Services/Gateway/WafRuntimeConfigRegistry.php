<?php

namespace App\Services\Gateway;

use App\Exception\AppException;
use App\Model\WafBan;
use App\Model\WafPolicy;
use App\Model\WafRuntimeConfig;
use App\Model\WafSignal;
use App\Model\Cluster;
use Hyperf\Redis\Redis;

/** Immutable control snapshots keep serving generations independent of failed desired-policy edits. */
final class WafRuntimeConfigRegistry
{
    public function __construct(private WafControlConfig $config, private WafControlCredential $credentials, private WafSchemaState $schema, private Redis $redis,
        private WafNetworkPolicy $network) {}

    public function initialized(): bool
    { return $this->schema->ready([WafRuntimeConfig::class, WafBan::class, WafSignal::class]); }

    public function profile(): array
    {
        $initialized = $this->initialized(); $configured = true; $notes = [];
        $collector = (bool) config('waf.collector_enabled', false);
        if (! $collector) $notes[] = '后台采集未启用，自动封禁计数已暂停';
        if (! $initialized) $notes[] = '请应用 0062_add_waf_dynamic_control.sql';
        try { $this->config->endpoint(); $this->config->secret(); }
        catch (AppException $e) { $configured = false; $notes[] = $e->getMessage(); }
        return ['initialized' => $initialized, 'configured' => $configured, 'collector_enabled' => $collector, 'available' => $initialized && $configured && $collector,
            'note' => implode('；', $notes), 'browser_available' => $initialized && $configured, 'network_available' => $initialized && $configured,
            'captcha_available' => extension_loaded('gd'), 'max_bans' => (int) config('waf.max_auto_bans', 1000)];
    }

    public function prepare(WafPolicy $policy, array $settings): array
    {
        $network = ! empty($settings['geo']['enabled']) || ! empty($settings['intelligence']['enabled']);
        if (! $settings['auto_ban']['enabled'] && ! $settings['browser']['enabled'] && ! $network) return [];
        if (! $this->initialized()) throw new AppException(409, '请先应用 database/migrations/0062_add_waf_dynamic_control.sql');
        $endpoint = $this->config->endpoint();
        if ($network) $this->network->assertConfigured($policy->org_id, $settings);
        if ($settings['browser']['enabled'] && $settings['browser']['type'] === 'captcha' && ! extension_loaded('gd')) throw new AppException(409, '图片验证码需要 API 启用 GD 扩展');
        $control = array_intersect_key($settings, array_flip(['mode','ip_allowlist','excluded_paths','auto_ban','browser','geo','intelligence']));
        $hash = hash('sha256', json_encode($control, JSON_THROW_ON_ERROR));
        // Validate the key before recording anything. Token values never enter policy/profile responses.
        $token = $this->credentials->issue($policy->org_id, $policy->cluster_id, $policy->id, $hash);
        $query = WafRuntimeConfig::where('org_id', $policy->org_id)->where('cluster_id', $policy->cluster_id)->where('policy_id', $policy->id);
        if (! (clone $query)->where('config_hash', $hash)->exists()) {
            if ($settings['auto_ban']['enabled'] && ! config('waf.collector_enabled', false)) throw new AppException(409, '启用或调整自动封禁前，请先启用后台日志采集');
            if ((clone $query)->count() >= (int) config('waf.max_control_configs', 500)) throw new AppException(409, '动态防护快照达到容量上限，请检查旧实例回收后重试');
            WafRuntimeConfig::create(['org_id' => $policy->org_id, 'cluster_id' => $policy->cluster_id,
                'policy_id' => $policy->id, 'config_hash' => $hash, 'settings' => $control, 'created_at' => time()]);
        }
        return ['endpoint' => $endpoint, 'token' => $token, 'config_hash' => $hash, 'policy_id' => $policy->id,
            'browser' => $settings['browser']['enabled'], 'network' => $network];
    }

    public function authenticate(string $token): WafRuntimeConfig
    {
        $claims = $this->credentials->verify($token);
        if (! config('waf.enabled', false) || ! $this->initialized()) throw new AppException(503, 'WAF control service is not initialized');
        $cacheKey = sprintf('galaxy:waf:runtime-config:%d:%d:%d:%s', $claims['org'], $claims['cluster'], $claims['policy'], $claims['hash']);
        $cached = $this->redis->get($cacheKey);
        if ($cached === '0') throw new AppException(401, 'WAF control configuration no longer exists');
        if ($cached !== false) {
            $attributes = json_decode($cached, true, 16, JSON_THROW_ON_ERROR);
            return new WafRuntimeConfig($attributes);
        }
        $config = WafRuntimeConfig::where('org_id', $claims['org'])->where('cluster_id', $claims['cluster'])
            ->where('policy_id', $claims['policy'])->where('config_hash', $claims['hash'])
            ->whereIn('policy_id', WafPolicy::where('org_id', $claims['org'])->where('cluster_id', $claims['cluster'])->select('id'))->first();
        $this->redis->setex($cacheKey, 5, $config === null ? '0' : json_encode($config->toArray(), JSON_THROW_ON_ERROR));
        if ($config === null) throw new AppException(401, 'WAF control configuration no longer exists');
        return $config;
    }

    public function find(WafPolicy $policy, string $hash): ?WafRuntimeConfig
    {
        if (! preg_match('/^[a-f0-9]{64}$/D', $hash) || ! $this->initialized()) return null;
        return WafRuntimeConfig::where('org_id', $policy->org_id)->where('cluster_id', $policy->cluster_id)
            ->where('policy_id', $policy->id)->where('config_hash', $hash)->first();
    }

    public function pruneUnreferenced(Cluster $cluster, array $services): void
    {
        $references = [];
        foreach ($services as $service) {
            $labels = $service['Spec']['Labels'] ?? [];
            if (($labels['com.codegalaxy.component'] ?? '') !== 'waf' || (int) ($labels['com.codegalaxy.org.id'] ?? 0) !== $cluster->org_id
                || (int) ($labels['com.codegalaxy.cluster.id'] ?? 0) !== $cluster->id) continue;
            foreach ($service['Spec']['TaskTemplate']['ContainerSpec']['Env'] ?? [] as $env) {
                if (preg_match('/^GALAXY_WAF_CONTROL_CONFIG=([a-f0-9]{64})$/D', $env, $match)) $references[(int) ($labels['com.codegalaxy.waf.policy'] ?? 0)][$match[1]] = true;
            }
        }
        $query = WafRuntimeConfig::where('org_id', $cluster->org_id)->where('cluster_id', $cluster->id);
        $rows = (clone $query)->where('created_at', '<', time() - 86400)->select(['id','policy_id','config_hash'])->orderBy('id')->limit(500)->get();
        $remove = $rows->filter(static fn ($row) => ! isset($references[$row->policy_id][$row->config_hash]))->pluck('id')->all();
        if ($remove !== []) $query->whereIn('id', $remove)->delete();
    }
}

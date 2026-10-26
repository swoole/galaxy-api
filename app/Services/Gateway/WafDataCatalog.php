<?php

namespace App\Services\Gateway;

use App\Exception\AppException;
use App\Model\WafDataSource;
use App\Model\WafPolicy;
use App\Model\WafRuntimeConfig;
use Hyperf\Redis\Redis;
use Throwable;

final class WafDataCatalog
{
    public function __construct(private WafSchemaState $schema, private WafDataConfig $config,
        private WafDataStorage $storage, private WafDataLease $lease, private Redis $redis) {}

    public function initialized(): bool { return $this->schema->ready([WafDataSource::class]); }

    public function listing(int $orgId, bool $canManage): array
    {
        $ready = $this->initialized();
        $sources = $ready ? WafDataSource::where('org_id', $orgId)->orderBy('id')->limit((int) config('waf.data_max_sources', 16))->get()->all() : [];
        return ['initialized' => $ready, 'note' => $ready ? '' : '请应用 0063_add_waf_data_sources.sql',
            'can_manage' => $canManage, 'updater_enabled' => (bool) config('waf.data_updater_enabled', false),
            'sources' => array_map(fn ($source) => $this->describe($source, $canManage), $sources),
            'presets' => WafDataConfig::PRESETS, 'countries' => WafCountries::codes()];
    }

    public function get(int $orgId, string $key): ?WafDataSource
    {
        if (! $this->initialized()) return null;
        $cacheKey = $this->cacheKey($orgId, $key); $raw = $this->redis->get($cacheKey);
        if ($raw !== false) {
            if ($raw === '0') return null;
            return (new WafDataSource())->setRawAttributes(json_decode($raw, true, 16, JSON_THROW_ON_ERROR), true);
        }
        $source = WafDataSource::where('org_id', $orgId)->where('source_key', $key)->first();
        $this->redis->setex($cacheKey, 5, $source ? json_encode($source->getAttributes(), JSON_THROW_ON_ERROR) : '0');
        return $source;
    }

    public function health(WafDataSource $source): array
    {
        $since = $source->validated_at;
        if ($source->published_at > 0 || $source->kind === 'geoip' || str_starts_with($source->provider, 'spamhaus-')) $since = min($since, $source->published_at);
        $expires = $since > 0 ? $since + $source->max_age : 0;
        $error = '';
        if (! $source->enabled) $error = '数据源已停用';
        elseif ($source->content_hash === '' || $since === 0) $error = '尚无已验证的数据版本';
        elseif ($expires <= time()) $error = '数据版本已过期';
        else {
            try { $this->storage->usableFile($source); }
            catch (Throwable) { $error = '当前 API 数据文件不可用，请检查共享数据目录'; }
        }
        return ['usable' => $error === '', 'expires_at' => $expires, 'note' => $error];
    }

    public function save(int $orgId, array $input, int $actor): array
    {
        $this->requireInitialized();
        return $this->lease->run('manage:' . $orgId, 30, function () use ($orgId, $input, $actor): array {
            if (isset($input['id']) && (filter_var($input['id'], FILTER_VALIDATE_INT) === false || $input['id'] < 0)) throw new AppException(422, '数据源 ID 无效');
            $id = (int) ($input['id'] ?? 0);
            $source = $id > 0 ? WafDataSource::where('org_id', $orgId)->where('id', $id)->first() : null;
            if ($id > 0 && $source === null) throw new AppException(404, '当前组织中不存在该数据源');
            if ($source && (filter_var($input['config_version'] ?? null, FILTER_VALIDATE_INT) === false || $input['config_version'] < 1)) throw new AppException(422, '数据源配置版本无效，请刷新后重试');
            if ($source && (int) ($input['config_version'] ?? 0) !== $source->config_version) throw new AppException(409, '数据源配置已变化，请刷新后重试');
            if (! $source && WafDataSource::where('org_id', $orgId)->count() >= (int) config('waf.data_max_sources', 16)) throw new AppException(409, '组织共享数据源数量已达上限');
            $settings = $this->config->normalize($input, $source);
            if (! $source && WafDataSource::where('org_id', $orgId)->where('source_key', $settings['source_key'])->exists()) throw new AppException(409, '数据源标识已存在');
            $values = $settings + ['updated_by' => $actor, 'updated_at' => time(), 'next_check_at' => 0, 'updating_until' => 0];
            if ($source) {
                $values['config_version'] = $source->config_version + 1;
                WafDataSource::where('org_id', $orgId)->where('id', $id)->where('config_version', $source->config_version)->update($values);
            } else $source = WafDataSource::create($values + ['org_id' => $orgId, 'created_by' => $actor, 'created_at' => time()]);
            $this->invalidate($orgId, $source->source_key);
            return $this->describe($source->fresh(), true);
        });
    }

    public function refresh(int $orgId, int $id): void
    {
        $this->requireInitialized();
        $source = WafDataSource::where('org_id', $orgId)->where('id', $id)->first();
        if (! $source) throw new AppException(404, '当前组织中不存在该数据源');
        if (! $source->enabled) throw new AppException(409, '请先启用数据源');
        WafDataSource::where('org_id', $orgId)->where('id', $id)->update(['next_check_at' => 0]);
        $this->invalidate($orgId, $source->source_key);
    }

    public function delete(int $orgId, int $id): void
    {
        $this->requireInitialized();
        $this->lease->run('manage:' . $orgId, 30, function () use ($orgId, $id): void {
            $source = WafDataSource::where('org_id', $orgId)->where('id', $id)->first();
            if (! $source) throw new AppException(404, '当前组织中不存在该数据源');
            $references = $this->references($orgId, $source->source_key);
            if ($references > 0) throw new AppException(409, '数据源仍被防护策略或生效配置引用，请先解除绑定');
            $source->delete(); $this->invalidate($orgId, $source->source_key);
        });
    }

    public function invalidate(int $orgId, string $key): void { $this->redis->del($this->cacheKey($orgId, $key)); }

    private function references(int $orgId, string $key): int
    {
        $matches = static fn ($query) => $query->where(fn ($q) => $q->where('settings->geo->enabled', true)->where('settings->geo->source', $key))
            ->orWhere(fn ($q) => $q->where('settings->intelligence->enabled', true)->whereJsonContains('settings->intelligence->sources', $key));
        $desired = $this->schema->ready([WafPolicy::class]) ? WafPolicy::where('org_id', $orgId)->where($matches)->pluck('id')->all() : [];
        $serving = $this->schema->ready([WafPolicy::class, WafRuntimeConfig::class]) ? WafRuntimeConfig::where('org_id', $orgId)->whereIn('policy_id', WafPolicy::where('org_id', $orgId)->where('service_id', '!=', '')->select('id'))
            ->where($matches)->pluck('policy_id')->all() : [];
        return count(array_unique(array_merge($desired, $serving)));
    }

    private function describe(WafDataSource $source, bool $canManage): array
    {
        $fields = ['id','source_key','provider','kind','title','enabled','refresh_interval','max_age','config_version',
            'content_hash','version','published_at','validated_at','last_checked_at','next_check_at','updating_until',
            'record_count','file_bytes','license_url','attribution'];
        $result = array_intersect_key($source->toArray(), array_flip($fields)) + $this->health($source);
        if ($canManage) $result += ['url' => $this->config->sourceUrl($source), 'last_error' => $source->last_error];
        else $result['last_error'] = $source->last_error === '' ? '' : '最近更新失败，管理员可查看详情';
        return $result;
    }
    private function cacheKey(int $orgId, string $key): string { return 'galaxy:waf:data-source:' . $orgId . ':' . $key; }
    private function requireInitialized(): void { if (! $this->initialized()) throw new AppException(409, '请先应用 0063_add_waf_data_sources.sql'); }
}

<?php

namespace App\Services\Gateway;

use App\Exception\AppException;
use App\Model\WafRuntimeConfig;
use MaxMind\Db\Reader;
use Throwable;

/** Shared IP-derived decisions, independent of the cluster runtime and admin/project UI. */
final class WafNetworkPolicy
{
    public function __construct(private WafDataCatalog $catalog, private WafDataStorage $storage,
        private WafIpIndex $index, private WafClientIp $ips) {}

    public function assertConfigured(int $orgId, array $settings): void
    {
        if ($this->references($settings) !== [] && ! $this->catalog->initialized()) throw new AppException(409, '请先应用 0063_add_waf_data_sources.sql');
        foreach ($this->references($settings) as [$key,$kind]) {
            $source = $this->catalog->get($orgId, $key);
            if ($source === null || $source->kind !== $kind || ! $this->catalog->health($source)['usable']) throw new AppException(409, '数据源 ' . $key . ' 尚无可用版本，请先由组织管理员完成更新');
        }
    }

    public function evaluate(WafRuntimeConfig $snapshot, array $client): array
    {
        $settings = $snapshot->settings; $result = ['decision' => null, 'country' => '', 'source' => '', 'data_status' => 'ok'];
        if ($this->references($settings) === [] || $settings['mode'] === 'off') return $result;
        $ip = $this->ips->normalize((string) ($client['ip'] ?? ''));
        if ($ip === null) throw new AppException(400, 'Invalid client IP');
        if ($this->ips->matches($ip, $settings['ip_allowlist']) || in_array(WafRequestPath::normalize((string) ($client['path'] ?? '/')), $settings['excluded_paths'], true)) return $result;
        foreach ($this->references($settings) as [$key,$kind]) {
            $rule = $settings[$kind === 'geoip' ? 'geo' : 'intelligence'];
            try {
                $source = $this->catalog->get($snapshot->org_id, $key);
                if ($source === null || $source->kind !== $kind || ! $this->catalog->health($source)['usable']) throw new \RuntimeException('数据源不可用');
                $file = $this->storage->usableFile($source);
                if ($kind === 'geoip') {
                    $reader = new Reader($file);
                    try { $code = $reader->get($ip)['country']['iso_code'] ?? null; }
                    finally { $reader->close(); }
                    if ($code !== null && $code !== 'ZZ' && ! WafCountries::valid($code)) throw new \RuntimeException('地域数据库中的国家/地区代码无效');
                    $result['country'] = WafCountries::valid($code) ? $code : '';
                    $matches = $result['country'] === '' ? $rule['unknown'] === 'block'
                        : ($rule['mode'] === 'deny' ? in_array($result['country'], $rule['countries'], true) : ! in_array($result['country'], $rule['countries'], true));
                } else $matches = $this->index->contains($file, $ip);
                if ($matches) {
                    $block = $settings['mode'] === 'block'; $result['source'] = $key;
                    $result['decision'] = ['status' => $block ? 403 : 204,
                        'action' => ($kind === 'geoip' ? 'geo_' : 'intel_') . ($block ? 'block' : 'monitor'), 'retry_after' => 0];
                    return $result;
                }
            } catch (Throwable $e) {
                if ($rule['unavailable'] === 'block') throw new AppException(503, '地域或情报数据暂不可用');
                $result['data_status'] = 'degraded'; $result['source'] = $key;
            }
        }
        return $result;
    }

    private function references(array $settings): array
    {
        $result = [];
        if (! empty($settings['geo']['enabled'])) $result[] = [$settings['geo']['source'], 'geoip'];
        if (! empty($settings['intelligence']['enabled'])) foreach ($settings['intelligence']['sources'] as $key) $result[] = [$key, 'iplist'];
        return $result;
    }
}

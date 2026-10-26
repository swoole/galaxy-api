<?php

namespace App\Services\Gateway;

use App\Exception\AppException;
use App\Model\WafDataSource;

final class WafDataConfig
{
    public const PRESETS = [
        'dbip-country' => ['kind' => 'geoip', 'title' => 'DB-IP 国家/地区 Lite', 'refresh_interval' => 86400, 'max_age' => 5356800,
            'license_url' => 'https://db-ip.com/db/download/ip-to-country-lite', 'attribution' => 'IP Geolocation by DB-IP · CC BY 4.0'],
        'spamhaus-drop-v4' => ['kind' => 'iplist', 'title' => 'Spamhaus DROP IPv4', 'refresh_interval' => 86400, 'max_age' => 259200,
            'license_url' => 'https://www.spamhaus.org/blocklists/do-not-route-or-peer/', 'attribution' => 'The Spamhaus Project · DROP'],
        'spamhaus-drop-v6' => ['kind' => 'iplist', 'title' => 'Spamhaus DROP IPv6', 'refresh_interval' => 86400, 'max_age' => 259200,
            'license_url' => 'https://www.spamhaus.org/blocklists/do-not-route-or-peer/', 'attribution' => 'The Spamhaus Project · DROP'],
    ];

    public function normalize(array $input, ?WafDataSource $existing = null): array
    {
        $provider = $existing?->provider ?? ($input['provider'] ?? '');
        if (! is_string($provider)) throw new AppException(422, '数据提供方无效');
        $known = self::PRESETS[$provider] ?? null;
        if ($known === null && ! in_array($provider, ['custom-geoip','custom-iplist'], true)) throw new AppException(422, '数据提供方无效');
        $key = $existing?->source_key ?? ($known === null ? ($input['source_key'] ?? '') : $provider);
        if (! is_string($key) || ! preg_match('/^[a-z][a-z0-9-]{1,63}$/D', $key)) throw new AppException(422, '数据源标识需为 2–64 位小写字母、数字和连字符');
        if ($known === null && isset(self::PRESETS[$key])) throw new AppException(422, '自定义数据源不可使用内置提供方的保留标识');
        $defaults = $existing?->toArray() ?? ($known ?? ['title' => '', 'refresh_interval' => 86400, 'max_age' => 259200, 'enabled' => true]);
        $result = array_intersect_key(array_replace($defaults, $input), array_flip(['title','url','license_url','attribution','enabled','refresh_interval','max_age']));
        $result += ['enabled' => true, 'url' => '', 'license_url' => '', 'attribution' => ''];
        if (! is_bool($result['enabled'])) throw new AppException(422, '数据源启用开关必须为布尔值');
        foreach (['title' => 100,'attribution' => 255] as $name => $length) {
            if (! is_string($result[$name]) || trim($result[$name]) === '' || mb_strlen($result[$name]) > $length || preg_match('/[\x00-\x1f\x7f]/', $result[$name])) throw new AppException(422, '数据源名称或署名无效');
            $result[$name] = trim($result[$name]);
        }
        if ($known !== null) {
            $result['license_url'] = $known['license_url']; $result['attribution'] = $known['attribution']; $result['url'] = '';
        } else {
            $this->url($result['url']); $this->url($result['license_url']);
        }
        foreach (['refresh_interval' => [3600,2678400], 'max_age' => [7200,31622400]] as $field => [$min,$max]) {
            if (filter_var($result[$field], FILTER_VALIDATE_INT) === false || $result[$field] < $min || $result[$field] > $max) throw new AppException(422, '更新周期或最长使用期超出范围');
            $result[$field] = (int) $result[$field];
        }
        if ($known !== null) $result['refresh_interval'] = max(86400, $result['refresh_interval']);
        if ($result['max_age'] < $result['refresh_interval'] * 2) throw new AppException(422, '最长使用期至少应为更新周期的两倍');
        if (str_starts_with($provider, 'spamhaus-') && $result['max_age'] > 604800) throw new AppException(422, 'Spamhaus 数据最长使用期不可超过 7 天');
        if ($provider === 'dbip-country' && $result['max_age'] > 5356800) throw new AppException(422, 'DB-IP Lite 数据最长使用期不可超过 62 天');
        return $result + ['source_key' => $key, 'provider' => $provider, 'kind' => $known['kind'] ?? ($provider === 'custom-geoip' ? 'geoip' : 'iplist')];
    }

    public function sourceUrl(WafDataSource $source): string
    {
        return match ($source->provider) {
            'dbip-country' => 'https://download.db-ip.com/free/dbip-country-lite-' . gmdate('Y-m') . '.mmdb.gz',
            'spamhaus-drop-v4' => 'https://www.spamhaus.org/drop/drop_v4.json',
            'spamhaus-drop-v6' => 'https://www.spamhaus.org/drop/drop_v6.json',
            default => $source->url,
        };
    }

    public function url(mixed $url): array
    {
        $parts = is_string($url) ? parse_url($url) : false;
        if (! is_array($parts) || strlen($url) > 2048 || ! in_array($parts['scheme'] ?? '', ['http','https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || preg_match('/[\x00-\x20\x7f]/', $url) || (isset($parts['port']) && ($parts['port'] < 1 || $parts['port'] > 65535))) throw new AppException(422, '数据地址须为无账号、片段和空白字符的 HTTP/HTTPS URL');
        $host = trim($parts['host'], '[]');
        if (! filter_var($host, FILTER_VALIDATE_IP) && ! preg_match('/^[a-zA-Z0-9](?:[a-zA-Z0-9.-]{0,251}[a-zA-Z0-9])?$/D', $host)) throw new AppException(422, '数据地址主机名无效');
        if ($parts['scheme'] !== 'https' && ! in_array(strtolower($host), (array) config('waf.data_trusted_hosts', []), true)) throw new AppException(422, '外部数据源必须使用 HTTPS');
        return $parts;
    }
}

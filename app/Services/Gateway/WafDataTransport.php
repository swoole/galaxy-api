<?php

namespace App\Services\Gateway;

use App\Exception\AppException;
use RuntimeException;

/** Dedicated updater transport: bounded streaming, public DNS pinning, no redirects/proxies. */
class WafDataTransport
{
    public function __construct(private WafDataConfig $config, private WafClientIp $ips) {}

    public function target(string $url): array
    {
        $parts = $this->config->url($url); $host = strtolower(trim($parts['host'], '[]'));
        $trusted = in_array($host, (array) config('waf.data_trusted_hosts', []), true);
        if (! filter_var($host, FILTER_VALIDATE_IP) && ! $trusted
            && ! preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{1,62}$/D', $host)) throw new AppException(422, '数据源必须使用完整域名或标准 IP 地址');
        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : $this->lookup($host);
        if ($addresses === []) throw new RuntimeException('数据源 DNS 解析失败');
        foreach ($addresses as $address) if ($this->ips->normalize($address) === null || (! $trusted && ! $this->isPublic($address))) throw new AppException(422, '数据源解析到本机、私有或保留地址；仅平台配置可授权内部数据主机');
        return ['host' => $host, 'port' => $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80), 'address' => $addresses[0]];
    }

    public function fetch(string $url, string $path, array $conditional = []): array
    {
        $target = $this->target($url); $limit = (int) config('waf.data_max_download_bytes', 16777216);
        $file = fopen($path, 'wb'); $curl = curl_init($url); $bytes = 0; $headerBytes = 0; $headers = []; $overflow = false;
        $requestHeaders = ['Accept-Encoding: identity'];
        foreach (['ETag' => 'If-None-Match', 'Last-Modified' => 'If-Modified-Since'] as $name => $request) {
            $value = $conditional[$name] ?? '';
            if (is_string($value) && strlen($value) <= 256 && ! preg_match('/[\x00-\x1f\x7f]/', $value) && $value !== '') $requestHeaders[] = $request . ': ' . $value;
        }
        $address = str_contains($target['address'], ':') ? '[' . $target['address'] . ']' : $target['address'];
        $options = [CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 45,
            CURLOPT_PROXY => '', CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $requestHeaders, CURLOPT_USERAGENT => 'Galaxy-WAF-Data/1.0',
            CURLOPT_HTTP_CONTENT_DECODING => false,
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use ($file, $limit, &$bytes, &$overflow): int {
                $bytes += strlen($chunk);
                if ($bytes > $limit) { $overflow = true; return 0; }
                return fwrite($file, $chunk) ?: 0;
            },
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers, &$headerBytes, &$overflow): int {
                $headerBytes += strlen($line);
                if ($headerBytes > 65536) { $overflow = true; return 0; }
                if (preg_match('/^(ETag|Last-Modified):\s*(.*?)\r?\n$/i', $line, $match) && strlen($match[2]) <= 256 && ! preg_match('/[\x00-\x1f\x7f]/', $match[2])) $headers[strtolower($match[1]) === 'etag' ? 'ETag' : 'Last-Modified'] = $match[2];
                return strlen($line);
            }];
        if (! filter_var($target['host'], FILTER_VALIDATE_IP)) $options[CURLOPT_RESOLVE] = [$target['host'] . ':' . $target['port'] . ':' . $address];
        if (defined('CURLOPT_PROTOCOLS_STR')) $options[constant('CURLOPT_PROTOCOLS_STR')] = 'http,https';
        else $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        try {
            curl_setopt_array($curl, $options); $ok = curl_exec($curl); $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            if ($overflow) throw new RuntimeException('下载内容或响应头超出容量上限');
            if ($ok === false) throw new RuntimeException('数据源连接或读取失败（cURL ' . curl_errno($curl) . '）');
            if (! in_array($status, [200,304], true)) throw new RuntimeException('数据源返回 HTTP ' . $status . ($status >= 300 && $status < 400 ? '，请配置最终地址；不自动跟随跳转' : ''));
            return ['status' => $status, 'headers' => $headers, 'bytes' => $bytes];
        } finally { curl_close($curl); fclose($file); }
    }

    protected function lookup(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
        $addresses = [];
        foreach (array_slice($records, 0, 32) as $record) if (isset($record['ip']) || isset($record['ipv6'])) $addresses[] = $record['ip'] ?? $record['ipv6'];
        return array_values(array_unique($addresses));
    }

    private function isPublic(string $address): bool
    {
        $ip = $this->ips->normalize($address);
        if ($ip === null || ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
        return ! $this->ips->matches($ip, ['0.0.0.0/8','100.64.0.0/10','192.0.0.0/24','192.0.2.0/24','192.88.99.0/24',
            '198.18.0.0/15','198.51.100.0/24','203.0.113.0/24','224.0.0.0/4','240.0.0.0/4',
            '::/96','64:ff9b::/96','64:ff9b:1::/48','100::/64','2001::/23','2001:db8::/32','2002::/16','fc00::/7','fe80::/10','ff00::/8']);
    }
}

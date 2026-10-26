<?php

namespace App\Services\Gateway;

use App\Exception\AppException;

final class WafControlConfig
{
    public function secret(): string
    {
        $secret = (string) config('waf.control_secret', '');
        if (strlen($secret) < 32 || strlen($secret) > 4096) throw new AppException(409, '请设置至少 32 字节的独立 WAF_CONTROL_SECRET');
        return $secret;
    }

    public function endpoint(): string
    {
        return $this->safeUrl((string) config('waf.control_url', '')) . '/internal/waf/check';
    }

    public function safeUrl(string $input): string
    {
        $url = rtrim($input, '/');
        $parts = parse_url($url);
        if (strlen($url) > 2048 || ! is_array($parts) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || ! preg_match('#^https?://(?:[a-zA-Z0-9_.-]+|\[[a-fA-F0-9:]+\])(?::[0-9]{1,5})?(?:/[a-zA-Z0-9_./~-]*)?$#D', $url)
            || (isset($parts['port']) && ($parts['port'] < 1 || $parts['port'] > 65535))) {
            throw new AppException(409, '请设置检测实例可访问的 WAF_CONTROL_URL，仅支持无认证、查询参数的 HTTP/HTTPS API 根地址');
        }
        return $url;
    }
}

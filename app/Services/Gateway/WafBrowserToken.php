<?php

namespace App\Services\Gateway;

use App\Exception\AppException;
use App\Model\WafRuntimeConfig;
use Throwable;

/** Browser tokens have separate MAC purposes from machine/management credentials. */
final class WafBrowserToken
{
    public function __construct(private WafControlConfig $config, private WafClientIp $ips) {}

    public function context(WafRuntimeConfig $snapshot, array $client): array
    {
        $ip = $this->ips->normalize((string) ($client['ip'] ?? ''));
        $host = strtolower((string) ($client['host'] ?? ''));
        if ($ip === null || ! preg_match('/^[a-z0-9.\[\]:_-]{1,255}$/D', $host)) throw new AppException(400, 'Invalid browser context');
        $policy = implode(':', [$snapshot->org_id, $snapshot->cluster_id, $snapshot->policy_id]);
        $scope = $policy . ':' . $snapshot->config_hash;
        return ['p' => substr(hash('sha256', $policy), 0, 32), 'ip' => substr(hash('sha256', $policy . ':' . $ip), 0, 32),
            's' => substr(hash('sha256', $scope), 0, 32),
            'c' => substr(hash_hmac('sha256', $ip . "\0" . substr((string) ($client['ua'] ?? ''), 0, 512) . "\0" . $host, $this->config->secret()), 0, 32)];
    }

    public function issue(string $purpose, array $context, int $ttl, array $extra = []): string
    {
        $payload = $this->encode(json_encode(array_intersect_key($context, ['s' => true, 'c' => true]) + ['iat' => time(), 'exp' => time() + $ttl] + $extra, JSON_THROW_ON_ERROR));
        return $payload . '.' . $this->mac($purpose, $payload);
    }

    public function verify(string $purpose, string $token, array $context, int $maxTtl): array
    {
        if (strlen($token) > 2048 || ! preg_match('/^([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]{43})$/D', $token, $parts)
            || ! hash_equals($this->mac($purpose, $parts[1]), $parts[2])) $this->invalid();
        try { $claims = json_decode(base64_decode(strtr($parts[1], '-_', '+/'), true), true, 8, JSON_THROW_ON_ERROR); }
        catch (Throwable) { $this->invalid(); }
        if (! is_array($claims) || ($claims['s'] ?? null) !== $context['s'] || ($claims['c'] ?? null) !== $context['c']
            || ! is_int($claims['iat'] ?? null) || ! is_int($claims['exp'] ?? null) || $claims['iat'] > time() + 5
            || $claims['exp'] <= time() || $claims['exp'] <= $claims['iat'] || $claims['exp'] - $claims['iat'] > $maxTtl) $this->invalid();
        return $claims;
    }

    public static function cookieName(int $policyId): string { return 'galaxy_waf_' . $policyId; }
    private function encode(string $value): string { return rtrim(strtr(base64_encode($value), '+/', '-_'), '='); }
    private function mac(string $purpose, string $payload): string
    { return $this->encode(hash_hmac('sha256', 'galaxy-waf-browser-v1:' . $purpose . ':' . $payload, $this->config->secret(), true)); }
    private function invalid(): never { throw new AppException(403, '验证已失效，请刷新页面重试'); }
}

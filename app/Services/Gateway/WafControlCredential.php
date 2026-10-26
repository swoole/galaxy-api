<?php

namespace App\Services\Gateway;

use App\Exception\AppException;
use JsonException;

/** Internal credentials authorize one configuration of one policy, never management operations. */
final class WafControlCredential
{
    public function __construct(private WafControlConfig $config) {}

    public function issue(int $orgId, int $clusterId, int $policyId, string $hash): string
    {
        $claims = ['v' => 1, 'org' => $orgId, 'cluster' => $clusterId, 'policy' => $policyId, 'hash' => $hash];
        $payload = $this->encode(json_encode($claims, JSON_THROW_ON_ERROR));
        return $payload . '.' . $this->encode(hash_hmac('sha256', 'galaxy-waf-control-v1.' . $payload, $this->config->secret(), true));
    }

    public function verify(string $token): array
    {
        $secret = $this->config->secret();
        if (strlen($token) > 1024 || ! preg_match('/^([a-zA-Z0-9_-]+)\.([a-zA-Z0-9_-]{43})$/D', $token, $parts)) $this->invalid();
        $expected = $this->encode(hash_hmac('sha256', 'galaxy-waf-control-v1.' . $parts[1], $secret, true));
        if (! hash_equals($expected, $parts[2])) $this->invalid();
        $json = base64_decode(strtr($parts[1], '-_', '+/'), true);
        try { $claims = $json === false ? null : json_decode($json, true, 8, JSON_THROW_ON_ERROR); }
        catch (JsonException) { $this->invalid(); }
        if (! is_array($claims) || array_keys($claims) !== ['v','org','cluster','policy','hash'] || $claims['v'] !== 1
            || ! is_string($claims['hash']) || ! preg_match('/^[a-f0-9]{64}$/D', $claims['hash'])) $this->invalid();
        foreach (['org','cluster','policy'] as $key) if (! is_int($claims[$key]) || $claims[$key] <= 0) $this->invalid();
        return $claims;
    }

    private function encode(string $value): string { return rtrim(strtr(base64_encode($value), '+/', '-_'), '='); }
    private function invalid(): never { throw new AppException(401, 'WAF internal credential is invalid'); }
}

<?php

namespace App\Services\Gateway;

use App\Exception\AppException;
use Hyperf\Redis\Redis;

/** Bounded Redis state and atomic one-use claims work across API replicas. */
final class WafChallengeStore
{
    public const RATE = "local n=redis.call('incr',KEYS[1]); if n==1 then redis.call('expire',KEYS[1],60) end; return n";
    public const CLAIM = "local v=redis.call('get',KEYS[1]); if v then redis.call('del',KEYS[1]) end; return v";
    public function __construct(private Redis $redis) {}

    public function limit(array $context): void
    {
        // Check policy capacity first, so source spoofing cannot create unlimited per-IP counters.
        foreach (['policy' => [$context['p'], (int) config('waf.challenge_policy_rate', 1200)],
            'client' => [$context['ip'], (int) config('waf.challenge_client_rate', 20)]] as $kind => [$scope,$limit]) {
            if ($this->redis->eval(self::RATE, ['galaxy:waf:challenge:rate:' . $kind . ':' . $scope], 1) > $limit) throw new AppException(429, '验证请求过于频繁，请稍后重试');
        }
    }

    public function issue(string $nonce, array $state, int $ttl): void
    { $this->redis->setex('galaxy:waf:challenge:nonce:' . $nonce, $ttl, json_encode($state, JSON_THROW_ON_ERROR)); }

    public function claim(string $nonce): array
    {
        if (! preg_match('/^[a-f0-9]{32}$/D', $nonce)) throw new AppException(403, '验证已失效，请刷新页面重试');
        $raw = $this->redis->eval(self::CLAIM, ['galaxy:waf:challenge:nonce:' . $nonce], 1);
        if (! is_string($raw)) throw new AppException(403, '验证已使用或过期，请刷新页面重试');
        return json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
    }
}

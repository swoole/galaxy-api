<?php

namespace App\Services\Gateway;

use App\Exception\AppException;
use Hyperf\Redis\Redis;

final class WafDataLease
{
    public function __construct(private Redis $redis) {}
    public function run(string $name, int $seconds, callable $callback): mixed
    {
        $key = 'galaxy:waf:data-lock:' . $name; $token = bin2hex(random_bytes(16));
        if (! $this->redis->set($key, $token, ['nx','ex' => $seconds])) throw new AppException(409, '数据源任务正在执行，请稍后重试');
        try { return $callback(); }
        finally { $this->redis->eval("if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) end return 0", [$key,$token], 1); }
    }
}

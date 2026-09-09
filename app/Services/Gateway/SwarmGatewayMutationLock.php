<?php

namespace App\Services\Gateway;

use App\Exception\AppException;
use Hyperf\Coroutine\Coroutine;
use Hyperf\Redis\Redis;
use Throwable;

/** Serializes mutations of the single managed gateway Service in a cluster. */
final class SwarmGatewayMutationLock
{
    public function __construct(private Redis $redis) {}

    public function synchronized(
        int $orgId,
        int $clusterId,
        callable $callback,
        int $waitSeconds = 30,
        int $ttlSeconds = 300
    ): mixed {
        $key = sprintf('cg:swarm-gateway-lock:%d:%d', $orgId, $clusterId);
        $token = bin2hex(random_bytes(16));
        $deadline = microtime(true) + max(0, $waitSeconds);
        $ttlSeconds = max(60, $ttlSeconds);

        do {
            if ($this->redis->set($key, $token, ['nx', 'ex' => $ttlSeconds])) {
                try {
                    return $callback();
                } finally {
                    try {
                        $this->redis->eval(
                            "if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) end return 0",
                            [$key, $token],
                            1
                        );
                    } catch (Throwable) {
                        // TTL is the final safety net and must not mask the gateway result.
                    }
                }
            }
            if (microtime(true) >= $deadline) {
                break;
            }
            Coroutine::sleep(0.2);
        } while (true);

        throw new AppException(409, '该集群的 Web 网关正在执行配置或证书变更，请稍后重试');
    }
}

<?php

namespace App\Services\Docker;

use App\Exception\AppException;
use Hyperf\Coroutine\Coroutine;
use Hyperf\Redis\Redis;
use Throwable;

/**
 * Serializes mutations that create/remove references to one or more Swarm
 * Services. Stable ordering makes service rebinds safe without deadlocks.
 */
final class SwarmServiceReferenceLock
{
    public function __construct(private Redis $redis) {}

    public function synchronized(
        int $orgId,
        int $clusterId,
        array $serviceNames,
        callable $callback,
        int $waitSeconds = 30,
        int $ttlSeconds = 900
    ): mixed {
        $serviceNames = array_values(array_unique(array_filter(array_map(
            static fn (mixed $name): string => trim((string) $name),
            $serviceNames
        ), static fn (string $name): bool => $name !== '')));
        sort($serviceNames, SORT_STRING);

        return $this->acquire(
            $orgId,
            $clusterId,
            $serviceNames,
            0,
            $callback,
            max(0, $waitSeconds),
            max(60, $ttlSeconds)
        );
    }

    private function acquire(
        int $orgId,
        int $clusterId,
        array $serviceNames,
        int $offset,
        callable $callback,
        int $waitSeconds,
        int $ttlSeconds
    ): mixed {
        if (! isset($serviceNames[$offset])) {
            return $callback();
        }

        $serviceName = $serviceNames[$offset];
        $key = 'cg:swarm-service-reference-lock:' . hash('sha256', implode(':', [
            $orgId, $clusterId, $serviceName,
        ]));
        $token = bin2hex(random_bytes(16));
        $deadline = microtime(true) + $waitSeconds;

        do {
            if ($this->redis->set($key, $token, ['nx', 'ex' => $ttlSeconds])) {
                try {
                    return $this->acquire(
                        $orgId,
                        $clusterId,
                        $serviceNames,
                        $offset + 1,
                        $callback,
                        $waitSeconds,
                        $ttlSeconds
                    );
                } finally {
                    try {
                        $this->redis->eval(
                            "if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) end return 0",
                            [$key, $token],
                            1
                        );
                    } catch (Throwable) {
                        // TTL guarantees eventual unlock without masking the operation result.
                    }
                }
            }
            if (microtime(true) >= $deadline) {
                break;
            }
            Coroutine::sleep(0.2);
        } while (true);

        throw new AppException(409, '该 Swarm Service 正在执行路由改绑、发布或删除操作，请稍后重试');
    }
}

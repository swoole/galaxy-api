<?php

namespace App\Services\Project;

use App\Exception\AppException;
use Hyperf\Coroutine\Coroutine;
use Hyperf\Redis\Redis;
use Throwable;

final class ProjectRuntimeLock
{
    public function __construct(private Redis $redis) {}

    public function synchronized(
        int $orgId,
        int $projectId,
        int $envId,
        int $clusterId,
        string $serviceName,
        callable $callback,
        int $waitSeconds = 30,
        int $ttlSeconds = 900
    ): mixed {
        $key = 'cg:project-runtime-lock:' . hash('sha256', implode(':', [
            $orgId, $projectId, $envId, $clusterId, $serviceName,
        ]));
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
                        // TTL guarantees eventual unlock; do not mask a completed Docker operation.
                    }
                }
            }
            if (microtime(true) >= $deadline) {
                break;
            }
            Coroutine::sleep(0.2);
        } while (true);

        throw new AppException(409, '该运行实例正在执行发布、路由变更或维护操作，请稍后重试');
    }
}

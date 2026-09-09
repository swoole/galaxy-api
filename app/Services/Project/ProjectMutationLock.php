<?php

namespace App\Services\Project;

use App\Exception\AppException;
use Hyperf\Coroutine\Coroutine;
use Hyperf\Redis\Redis;
use Throwable;

/** Serializes project-wide mutations such as deletion and resource creation. */
final class ProjectMutationLock
{
    public function __construct(private Redis $redis) {}

    public function synchronized(
        int $orgId,
        int $projectId,
        callable $callback,
        int $waitSeconds = 30,
        int $ttlSeconds = 7200
    ): mixed {
        $key = sprintf('cg:project-mutation-lock:%d:%d', $orgId, $projectId);
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
                        // The TTL is the final safety net; never mask the product operation.
                    }
                }
            }
            if (microtime(true) >= $deadline) {
                break;
            }
            Coroutine::sleep(0.2);
        } while (true);

        throw new AppException(409, '该项目正在执行资源变更或删除操作，请稍后重试');
    }
}

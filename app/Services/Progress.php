<?php

namespace App\Services;

use App\Constants\ErrorCode;
use App\Exception\AppException;
use Hyperf\Redis\Redis;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * 进度上报与查询.
 */
class Progress
{
    protected Redis $redis;

    public function __construct(ContainerInterface $container)
    {
        $this->redis = $container->get(Redis::class);
    }

    /**
     * 上报进度.
     */
    public function report(
        string $key,
        string $tag,
        string $message,
        array $context = [],
        string $level = 'info',
        $ttl = null,
        $skipNotExists = false
    ) {
        // ttl为空时判断key是否存在，避免被攻击
        if (empty($ttl) && !$this->exists($key)) {
            if ($skipNotExists) {
                return;
            }
            throw new AppException(ErrorCode::PROGRESS_NOT_FOUND, '该任务不存在，无法上报进度');
        }

        $score = microtime(true);
        $this->redis->zAdd($key, $score, $this->messageSerialize([
            'tag' => $tag,
            'msg' => $message,
            'context' => $context,
            'level' => $level,
        ]));
        if (! empty($ttl)) {
            $this->redis->expire($key, $ttl);
        }
        $this->redis->publish($key, (string) $score);
    }

    /**
     * 查询进度.
     */
    public function query(string $key, $begin = 0.0)
    {
        // 先查询key是否存在，避免被漏洞利用恶意耗用服务器资源
        if (!$this->exists($key)) {
            throw new AppException(ErrorCode::PROGRESS_NOT_FOUND, '该任务不存在，无法查询进度');
        }

        $redis = RawRedis::get();
        $redis->setOption(\Redis::OPT_READ_TIMEOUT, 10);
        $results = $this->tryQueryProgress($redis, $key, $begin);
        if (! empty($results)) {
            return $results;
        }

        // 进入订阅模式进行等待
        try {
            $redis->subscribe(
                [$key],
                function ($redis, $chan, $msg) use (&$results, $key, $begin) {
                    $results = $this->tryQueryProgress($redis, $key, $begin);
                    $redis->close();
                }
            );
        } catch (Throwable $e) {
            // 连接关闭，订阅被关闭，直接跳过
            if (strpos($e->getMessage(), 'closed') > -1) {
                // do nothing
            } else {
                // 订阅超时，尝试查询结果
                $results = $this->tryQueryProgress($redis, $key, $begin);
            }
        }

        return $results ?: [];
    }

    /**
     * 任务是否存在.
     * @return bool
     */
    public function exists($key)
    {
        return $this->redis->type($key) == \Redis::REDIS_ZSET;
    }

    /**
     * 尝试查询进度.
     */
    protected function tryQueryProgress($redis, $key, $begin)
    {
        $sets = $redis->zRangeByScore($key, '(' . $begin, '+inf', ['withscores' => true]);
        if (empty($sets)) {
            return;
        }

        $results = [];
        foreach ($sets as $val => $score) {
            $json = $this->messageUnserialize($val);
            // 避免异常数据导致前端报错
            if (empty($json)) {
                continue;
            }
            $json['report_at'] = (float) $score;
            $results[] = $json;
        }

        return $results;
    }

    /**
     * 消息序列化.
     */
    protected function messageSerialize($message)
    {
        return json_encode($message);
    }

    /**
     * 消息解序列化.
     * @return array|null|false
     */
    protected function messageUnserialize($message)
    {
        return json_decode($message, true);
    }
}

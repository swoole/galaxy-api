<?php

namespace App\Services\Notify\Channel;

use App\Services\Notify\AbstractNotifyChannel;
use App\Services\RawRedis;
use App\Support\Functions;
use Hyperf\Redis\Redis;
use Psr\Container\ContainerInterface;
use stdClass;
use Throwable;

class Browser extends AbstractNotifyChannel
{
    protected Redis $redis;

    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container);
        $this->redis = $container->get(Redis::class);
    }

    /**
     * 发送通知.
     */
    public function send($users, array $config, array $params = [], ?string $scene = null)
    {
        $paramsFiltered = $this->beforeFilter($config, $params);
        $score = microtime(true);
        foreach ($users as $user) {
            $key = sprintf('cg.notify.browser.%s', $user['id']);
            $data = [
                'title' => $config['title'],
                'body' => $this->formatBody($config, $paramsFiltered),
                'context' => new stdClass(),
                'tag' => $scene,
                'notify_at' => $score,
            ];
            $data = $this->afterFilter($config, $params, $data);
            $msg = json_encode($data);
            $this->redis->zAdd($key, $score, $msg);
            $this->redis->publish($key, $msg);
        }
    }

    public function listen($uid, $begin = null)
    {
        if (is_null($begin)) {
            $begin = microtime(true);
        }

        $key = sprintf('cg.notify.browser.%s', $uid);
        $redis = RawRedis::get();
        $redis->setOption(\Redis::OPT_READ_TIMEOUT, 10);

        $results = $this->tryGetFromScore($key, $begin);
        if (! empty($results)) {
            return $results;
        }

        // 进入订阅模式进行等待
        try {
            $redis->subscribe(
                [$key],
                function ($redis, $chan, $msg) use (&$results, $key, $begin) {
                    $results = [json_decode($msg, true)];
                    $redis->close();
                }
            );
        } catch (Throwable $e) {
            // 连接关闭，订阅被关闭，直接跳过
            if (strpos($e->getMessage(), 'closed') > -1) {
                // do nothing
            } else {
                // 订阅超时，尝试查询结果
                $results = $this->tryGetFromScore($key, $begin);
            }
        }

        return $results ?: [];
    }

    /**
     * 尝试查询进度.
     */
    protected function tryGetFromScore($key, $begin)
    {
        $sets = $this->redis->zRangeByScore($key, '(' . $begin, '+inf', ['withscores' => true,]);
        if (empty($sets)) {
            return;
        }

        $results = [];
        foreach ($sets as $val => $score) {
            $json = json_decode($val, true);
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
     * 格式化body.
     */
    public function formatBody($config, array $params)
    {
        $search = [];
        $replace = [];
        foreach ($config['fields'] as $field) {
            $placeholder = '{{' . $field . '}}';
            $search[] = $placeholder;
            $keys = Functions::fieldSplit($field);
            [$exists, $value] = Functions::getValue($params, $keys, $placeholder);
            $replace[] = $value;
        }

        return str_replace($search, $replace, $config['body']);
    }
}

<?php

namespace App\Services\Notify\Channel;

use App\Services\Notify\AbstractNotifyChannel;
use App\Support\Functions;
use Hyperf\DbConnection\Db;
use Hyperf\Redis\Redis;
use Psr\Container\ContainerInterface;

class Notify extends AbstractNotifyChannel
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
        $notifys = [];
        foreach ($users as $user) {
            $data = [
                'uid' => $user['id'],
                'scene' => $scene,
                'title' => $config['title'],
                'content' => $this->formatBody($config, $paramsFiltered),
                'context' => '{}',
                'read_at' => 0,
                'created_at' => time(),
            ];
            $data = $this->afterFilter($config, $params, $data);
            $notifys[] = $data;
        }
        Db::table('notify')->insert($notifys);
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

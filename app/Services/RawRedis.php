<?php

namespace App\Services;

use Hyperf\Redis\RedisConnection;

class RawRedis extends RedisConnection
{
    public function __construct()
    {
    }

    /**
     * @return \Redis|\RedisCluster|\RedisSentinel
     */
    public static function get($name = 'default')
    {
        return (new RawRedis())->getInstance($name);
    }

    /**
     * @return \Redis|\RedisCluster|\RedisSentinel
     */
    public function getInstance($name = 'default')
    {
        $this->config = array_replace_recursive($this->config, config('redis.' . $name));

        $host = $this->config['host'];
        $port = $this->config['port'];
        $auth = $this->config['auth'];
        $db = $this->config['db'];
        $timeout = $this->config['timeout'];
        $cluster = $this->config['cluster']['enable'] ?? false;
        $sentinel = $this->config['sentinel']['enable'] ?? false;

        $redis = null;
        switch (true) {
            case $cluster:
                $redis = $this->createRedisCluster();
                break;
            case $sentinel:
                $redis = $this->createRedisSentinel();
                break;
            default:
                $redis = $this->createRedis($this->config);
                break;
        }

        $options = $this->config['options'] ?? [];

        foreach ($options as $name => $value) {
            // The name is int, value is string.
            $redis->setOption($name, $value);
        }

        if ($redis instanceof \Redis && isset($auth) && $auth !== '') {
            $redis->auth($auth);
        }

        $database = $this->database ?? $db;
        if ($database > 0) {
            $redis->select($database);
        }

        return $redis;
    }
}

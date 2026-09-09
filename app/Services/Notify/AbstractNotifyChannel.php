<?php

namespace App\Services\Notify;

use Psr\Container\ContainerInterface;

abstract class AbstractNotifyChannel
{
    protected ContainerInterface $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    /**
     * 发送通知.
     */
    abstract public function send($users, array $config, array $params = [], ?string $scene = null);

    /**
     * 前置过滤.
     */
    protected function beforeFilter(array $config, array $params = [])
    {
        if (empty($config['before_filter'])) {
            return $params;
        }

        [$class, $function] = $config['before_filter'];
        
        return $this->container->get($class)->{$function}($params);
    }

    /**
     * 后置过滤.
     */
    protected function afterFilter(array $config, array $params = [], array $data = [])
    {
        if (empty($config['after_filter'])) {
            return $data;
        }

        [$class, $function] = $config['after_filter'];
        
        return $this->container->get($class)->{$function}($params, $data);
    }
}

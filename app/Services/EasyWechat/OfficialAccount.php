<?php

namespace App\Services\EasyWechat;

use EasyWeChat\OfficialAccount\Application;
use Hyperf\Contract\ConfigInterface;
use Psr\Container\ContainerInterface;

class OfficialAccount extends Application
{
    public function __construct(ContainerInterface $container)
    {
        $configer = $container->get(ConfigInterface::class);
        $config = $configer->get('easywechat.officialaccount', []);
        parent::__construct($config);
    }
}

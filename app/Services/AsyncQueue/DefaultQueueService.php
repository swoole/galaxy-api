<?php

/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */
namespace App\Services\AsyncQueue;

use Hyperf\AsyncQueue\Driver\DriverFactory;
use Hyperf\AsyncQueue\Driver\DriverInterface;
use Hyperf\AsyncQueue\Job;

class DefaultQueueService
{
    /**
     * @var DriverInterface
     */
    protected $driver;

    public function __construct(DriverFactory $driverFactory)
    {
        $this->driver = $driverFactory->get('default');
    }

    /**
     * 生产消息.
     * @param int $delay 延时时间 单位秒
     */
    public function push(Job $job, int $delay = 0): bool
    {
        return $this->driver->push($job, $delay);
    }
}

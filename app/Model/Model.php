<?php

/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */
namespace App\Model;

use Hyperf\DbConnection\Model\Model as BaseModel;
use Hyperf\Logger\LoggerFactory;
use Psr\Log\LoggerInterface;

abstract class Model extends BaseModel
{
    /**
     * 默认禁用自动时间戳管理，如需开启，请在父类中设置其值为true.
     *
     * @var bool
     */
    public bool $timestamps = false;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * Create a new Model model instance.
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->logger = $this->getContainer()->get(LoggerFactory::class)->get('model:' . $this->table);

        $this->init();
    }

    protected function getInstance($class)
    {
        return $this->getContainer()->get($class);
    }

    protected function init()
    {
    }

    /**
     * 获取属性修改之前的数据.
     */
    public function getOri($key, $default = null)
    {
        return $this->original[$key] ?? $default;
    }

    /**
     * 获取属性修改之后的数据.
     */
    public function getAttr($key, $default = null)
    {
        return $this->attributes[$key] ?? $default;
    }
}

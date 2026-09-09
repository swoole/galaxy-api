<?php

/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */
namespace App\Constants;

use Hyperf\Constants\AbstractConstants;
use Hyperf\Constants\Annotation\Constants;

/**
 * @Constants
 * @method static string getChannel(int $code, $translate = null)
 */
#[Constants]
class UserLoginChannel extends AbstractConstants
{
    /**
     * @Channel("密码")
     */
    public const PWD = 1;

    /**
     * @Channel("短信验证码")
     */
    public const SMS = 1;

    /**
     * @Channel("微信")
     */
    public const WECHAT = 2;

    /**
     * @Channel("Gitee")
     */
    public const GITEE = 4;

    /**
     * @Channel("GitHub")
     */
    public const GITHUB = 5;

    public static function getAllChannel()
    {
        $ref = new \ReflectionClass(__CLASS__);
        return $ref->getConstants();
    }
}

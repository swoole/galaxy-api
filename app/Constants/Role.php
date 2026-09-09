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
 * @method static string getRole(int $code, $translate = null)
 */
#[Constants]
class Role extends AbstractConstants
{
    /**
     * @Role("普通成员")
     */
    public const GENERAL = 0;

    /**
     * @Role("管理员")
     */
    public const MANAGER = 1;

    /**
     * @Role("负责人")
     */
    public const ADMIN = 9;
}

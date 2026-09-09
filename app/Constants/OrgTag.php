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
 */
#[Constants]
class OrgTag extends AbstractConstants
{
    /**
     * @Message("蓝色")
     */
    public const BLUE = 1;

    /**
     * @Message("绿色")
     */
    public const GREEN = 2;

    /**
     * @Message("灰色")
     */
    public const GREY = 3;

    /**
     * @Message("橙色")
     */
    public const ORANGE = 4;

    /**
     * @Message("红色")
     */
    public const GULES = 5;

    /**
     * @Message("亮蓝")
     */
    public const BRIGHT_BLUE = 6;

    /**
     * @Message("亮绿")
     */
    public const BRIGHT_GREEN = 7;

    /**
     * @Message("亮灰")
     */
    public const BRIGHT_GREY = 8;

    /**
     * @Message("亮红")
     */
    public const BRIGHT_RED = 9;

    /**
     * @Message("亮橙")
     */
    public const BRIGHT_ORANGE = 10;

    /**
     * @Message("素蓝")
     */
    public const PLAIN_BLUE = 11;

    /**
     * @Message("素绿")
     */
    public const PLAIN_GREEN = 12;

    /**
     * @Message("素灰")
     */
    public const PLAIN_GREY = 13;

    /**
     * @Message("素红")
     */
    public const PLAIN_RED = 14;

    /**
     * @Message("素橙")
     */
    public const PLAIN_ORANGE = 15;
}

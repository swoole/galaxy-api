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
class OrgType extends AbstractConstants
{
    /**
     * @Message("个人空间")
     */
    public const ORG_PERSONAL = 0;

    /**
     * @Message("一般组织")
     */
    public const ORG_COMMONLY = 1;

    /**
     * @Message("正常")
     */
    public const ORG_NORMAL = 1;

}

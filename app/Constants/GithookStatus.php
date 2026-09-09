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
 * @method static string getGitHook(int $code, $translate = null)
 */
#[Constants]
class GithookStatus extends AbstractConstants
{
    /**
     * @GitHook("启用")
     */
    public const ENABLED = 1;

    /**
     * @GitHook("禁用")
     */
    public const DISABLED = 0;
}

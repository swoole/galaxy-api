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
class RedisKey extends AbstractConstants
{
    /**
     * @Message("redis key 公共前缀")
     */
    public const COMMON_KEY_PREFIX = 'CodeGalaxy:';

    /**
     * @Message("snowflake算法workerId")
     */
    public const SNOWFLAKE_REDIS_KEY = self::COMMON_KEY_PREFIX . 'snowflake:workerId';

    /**
     * @Message("图形验证码前缀")
     */
    public const CAPTCHA_REDIS_KEY = self::COMMON_KEY_PREFIX . 'captcha:';

    /**
     * @Message("图形验证码错误次数前缀")
     */
    public const CAPTCHA_ERROR_NUM_REDIS_KEY = self::COMMON_KEY_PREFIX . 'captchaErrorNum:';

    /**
     * @Message("滑动验证码挑战前缀")
     */
    public const SLIDER_CAPTCHA_CHALLENGE_KEY = self::COMMON_KEY_PREFIX . 'sliderCaptcha:challenge:';

    /**
     * @Message("滑动验证码令牌前缀")
     */
    public const SLIDER_CAPTCHA_TOKEN_KEY = self::COMMON_KEY_PREFIX . 'sliderCaptcha:token:';

    /**
     * 注册邮箱已完成滑动验证标识.
     */
    public const REGISTER_SLIDER_VERIFIED_KEY = self::COMMON_KEY_PREFIX . 'register:sliderVerified:';

    /**
     * @Message("数字验证码错误次数前缀")
     */
    public const SMS_ERROR_NUM_REDIS_KEY = self::COMMON_KEY_PREFIX . 'smsErrorNum:';

    /**
     * @Message("身份校验通过标识")
     */
    public const ACTION_AUTH_REDIS_KEY = self::COMMON_KEY_PREFIX . 'actionAuth:';

}

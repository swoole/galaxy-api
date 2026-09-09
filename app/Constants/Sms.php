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
class Sms extends AbstractConstants
{
    /**
     * @Message("账户-手机号类型")
     */
    public const ACCOUNT_PHONE = 'phone';

    /**
     * @Message("账户-邮箱类型")
     */
    public const ACCOUNT_EMAIL = 'email';

    /**
     * @Message("注册-验证码类型")
     */
    public const SMS_TYPE_REGISTER = 1;

    /**
     * @Message("登录验证码类型")
     */
    public const SMS_TYPE_LOGIN = 2;

    /**
     * @Message("忘记密码-验证码类型")
     */
    public const SMS_TYPE_FORGET = 3;

    /**
     * @Message("操作-验证码类型")
     */
    public const SMS_TYPE_ACTION = 4;

    /**
     * @Message("实名认证")
     */
    public const SMS_TYPE_REALNAME_AUTH = 5;

    public const SMS_TYPE_ARRAY = [
        self::SMS_TYPE_REGISTER,
        self::SMS_TYPE_LOGIN,
        self::SMS_TYPE_FORGET,
        self::SMS_TYPE_ACTION,
        self::SMS_TYPE_REALNAME_AUTH,
    ];

    /**
     * 不需要校验帐号是否存在的白名单类型.
     */
    public const SMS_ACCOUNT_BLACKLIST = [
        self::SMS_TYPE_REGISTER,
        self::SMS_TYPE_LOGIN,
        self::SMS_TYPE_REALNAME_AUTH,
    ];

    /**
     * @Message("短信模板过期分钟显示")
     */
    public const SMS_TEMPLATE_EXPIRE_NUM = '3';

    /**
     * @Message("短信验证码过期时间")
     */
    public const SMS_EXPIRE_TIME = self::SMS_TEMPLATE_EXPIRE_NUM * 60;

    /**
     * @Message("图形验证码过期时间")
     */
    public const CAPTCHA_EXPIRE_TIME = self::SMS_TEMPLATE_EXPIRE_NUM * 60;

    /**
     * 注册滑动验证状态有效期（30分钟）.
     */
    public const REGISTER_SLIDER_EXPIRE_TIME = 30 * 60;

    /**
     * @Message("手机号每日发送上限数")
     */
    public const SMS_SEND_NUM_MAX = 10;
}

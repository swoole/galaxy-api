<?php

declare(strict_types=1);
/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */
return [
    'CharSet' => env('SMTP_SERVER_CHARSET', 'UTF-8'), //设定邮件编码，默认ISO-8859-1，如果发中文此项必须设置，否则乱码
    'SMTPDebug' => env('SMTP_SERVER_DEBUG', 0), //关闭SMTP调试功能
    'SMTPAuth' => env('SMTP_SERVER_AUTH', true), //启用 SMTP 验证功能
    'SMTPSecure' => env('SMTP_SERVER_SECURE', 'ssl'), //使用安全协议
    'Host' => env('SMTP_SERVER_HOST', 'smtp.qq.com'), //SMTP 服务器
    'Port' => env('SMTP_SERVER_PORT', '465'), //SMTP服务器的端口号
    'Username' => env('SMTP_SERVER_NAME', ''), //SMTP服务器用户名
    'Password' => env('SMTP_SERVER_PASSWORD', ''), //SMTP服务器密码
    'FromEmail' => env('SMTP_FROM_EMAIL', ''), //发送人邮箱
    'FromNickName' => env('SMTP_FROM_NICK_NAME', ''), //发送人昵称
];

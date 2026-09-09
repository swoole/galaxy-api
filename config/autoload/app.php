<?php

declare(strict_types=1);

use App\Services\Utils;

/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */
return [
    // 服务网段，用于公有云等场景设置接口白名单
    'cidr' => explode(':', env('APP_CIDR', '0.0.0.0/0')),
    // 管理员管理员组织，该组织可以修改系统IDE、构建集群等配置，用于临时充当系统管理
    'admin_org' => (int) env('ADMIN_ORG', 1),
    // 管理员邮箱
    'admin_emails' => Utils::parseEmailsConfig(env('ADMIN_EMAILS', 'tianpian@swoole.com:田片')),

    // hash id
    'hashid' => [
        'salt' => env('HASHID_SALT', 'SvKc1Epe6BjdnYXx'),
        'minlength' => (int) env('HASHID_MINLENGTH', 6),
        'pool' => env('HASHID_POOL', 'abcdefghijklmnopqrstuvwxyz1234567890'),
    ],

    // 加密配置
    'encrypt' => [
        'key' => env('ENCRYPT_KEY', ''),
        'key_file' => env('ENCRYPT_KEY_FILE', BASE_PATH . '/storage/keys/.secret.key'),
    ],
];

<?php

declare(strict_types=1);
/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */
ini_set('display_errors', 'on');
ini_set('display_startup_errors', 'on');

// 最新 hyperf-auth 仍会触发 PHP 8.4 的第三方弃用提示，不应中断项目测试。
error_reporting(E_ALL & ~E_DEPRECATED);
date_default_timezone_set('Asia/Shanghai');

! defined('BASE_PATH') && define('BASE_PATH', dirname(__DIR__, 1));
// 文件 hook 会在部分容器中尝试初始化受限的 io_uring。
! defined('SWOOLE_HOOK_FLAGS') && define('SWOOLE_HOOK_FLAGS', SWOOLE_HOOK_ALL & ~SWOOLE_HOOK_FILE);

require BASE_PATH . '/vendor/autoload.php';

Hyperf\Di\ClassLoader::init();

$container = require BASE_PATH . '/config/container.php';

$container->get(Hyperf\Contract\ApplicationInterface::class);

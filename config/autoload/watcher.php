<?php

declare(strict_types=1);
/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */
use Hyperf\Watcher\Driver\ScanFileDriver;

return [
    'driver' => ScanFileDriver::class,
    'bin' => env('WATCHER_BIN', 'php -dswoole.use_shortname=off '),
    'watch' => [
        'dir' => ['project', 'config'],
        'file' => ['.env'],
        'scan_interval' => 2000,
    ],
];

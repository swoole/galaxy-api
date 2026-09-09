<?php

declare(strict_types=1);
/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */
$minimumHandleTimeout = max(
    (int) env('BUILDKIT_TIMEOUT', 1800),
    (int) env('PROJECT_RELEASE_TIMEOUT', env('APP_RELEASE_TIMEOUT', 600))
) + 300;
$configuredHandleTimeout = (int) env('QUEUE_HANDLE_TIMEOUT', $minimumHandleTimeout);

return [
    'default' => [
        'driver' => \Hyperf\AsyncQueue\Driver\RedisDriver::class,
        'redis' => [
            'pool' => 'queue',
        ],
        'channel' => env('QUEUE_CHANNEL', 'api.console'),
        'timeout' => (int) env('QUEUE_TIMEOUT', 2),
        'retry_seconds' => (int) env('QUEUE_RETRY_SECONDS', 5),
        // The Redis reserved lease must outlive the application-level runner
        // timeout. Otherwise a healthy long BuildKit job is prematurely moved
        // to the timeout channel while its worker is still executing it.
        'handle_timeout' => max($minimumHandleTimeout, $configuredHandleTimeout),
        'processes' => (int) env('QUEUE_PROCESSES', 1),
        'concurrent' => [
            'limit' => (int) env('QUEUE_CONCURRENT_LIMIT', 10),
        ],
    ],
];

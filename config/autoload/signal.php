<?php

declare(strict_types=1);
/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */
$config = [
    'handlers' => [
        // Hyperf\Signal\Handler\WorkerStopHandler::class => PHP_INT_MIN
    ],
    'timeout' => 5.0,
];

if (env('SIGNAL_PROCESS_STOP_HANDLER', true)) {
    $config['handlers'][] = \Hyperf\Process\Handler\ProcessStopHandler::class;
}

return $config;

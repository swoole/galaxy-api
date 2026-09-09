<?php

declare(strict_types=1);
/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */
$listeners = [
    \App\Listener\FetchModeListener::class,
    \App\Listener\QueueClearTimeoutListener::class,
    \App\Listener\DockerAgentWorkerStartListener::class,
    \App\Listener\DockerAgentIpcListener::class,
];

if (env('LISTENER_ENABLE_DBLOG')) {
    $listeners[] = \App\Listener\DbQueryExecutedListener::class;
}

return $listeners;

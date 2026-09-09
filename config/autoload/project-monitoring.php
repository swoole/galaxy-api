<?php

declare(strict_types=1);

return [
    'enabled' => (bool) env('PROJECT_MONITOR_ENABLED', env('APP_MONITOR_ENABLED', true)),
    'interval' => max(15, (int) env('PROJECT_MONITOR_INTERVAL', env('APP_MONITOR_INTERVAL', 60))),
    'events_enabled' => (bool) env(
        'PROJECT_MONITOR_EVENTS',
        env('PROJECT_MONITOR_DOCKER_EVENTS', env('APP_MONITOR_DOCKER_EVENTS', true))
    ),
];

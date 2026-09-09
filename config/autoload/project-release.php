<?php

declare(strict_types=1);

return [
    'timeout' => (int) env('PROJECT_RELEASE_TIMEOUT', env('APP_RELEASE_TIMEOUT', 600)),
    'poll_interval' => (int) env('PROJECT_RELEASE_POLL_INTERVAL', env('APP_RELEASE_POLL_INTERVAL', 2)),
];

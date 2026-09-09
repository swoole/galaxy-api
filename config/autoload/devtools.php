<?php

return [
    // Must remain disabled outside an explicitly controlled development API.
    'access_enabled' => (bool) env('DEVTOOLS_ACCESS_ENABLED', false),
    'redis_prefix' => env('DEVTOOLS_REDIS_PREFIX', 'galaxy:devtool:token:'),
];

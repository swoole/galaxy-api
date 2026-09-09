<?php

declare(strict_types=1);

return [
    'image' => env('WORKSPACE_IMAGE', env('APP_WORKSPACE_IMAGE', 'codegalaxy/workspace:2026.07')),
    'target_port' => (int) env('WORKSPACE_TARGET_PORT', env('APP_WORKSPACE_TARGET_PORT', 3000)),
    'volume_path' => env('WORKSPACE_VOLUME_PATH', env('APP_WORKSPACE_VOLUME_PATH', '/workspace')),
    'workspace_path' => env('WORKSPACE_PATH', env('APP_WORKSPACE_PATH', '/workspace/repository')),
    'default_cpu' => (int) env('WORKSPACE_DEFAULT_CPU', env('APP_WORKSPACE_DEFAULT_CPU', 500)),
    'default_memory' => (int) env('WORKSPACE_DEFAULT_MEMORY', env('APP_WORKSPACE_DEFAULT_MEMORY', 1024)),
    'min_published_port' => (int) env('WORKSPACE_MIN_PORT', env('APP_WORKSPACE_MIN_PORT', 10000)),
    'max_published_port' => (int) env('WORKSPACE_MAX_PORT', env('APP_WORKSPACE_MAX_PORT', 60000)),
];

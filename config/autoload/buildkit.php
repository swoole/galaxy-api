<?php

declare(strict_types=1);

return [
    'image' => env('BUILDKIT_IMAGE', 'registry.cn-shanghai.aliyuncs.com/swoole-public/buildkit:v0.31.1-rootless'),
    'kubernetes_image' => env('BUILDKIT_KUBERNETES_IMAGE', 'registry.cn-shanghai.aliyuncs.com/swoole-public/buildkit:v0.31.1'),
    'git_image' => env('BUILDKIT_GIT_IMAGE', 'registry.cn-shanghai.aliyuncs.com/swoole-public/git:latest'),
    'dockerhub_mirror' => env('BUILDKIT_DOCKERHUB_MIRROR', ''),
    'kubernetes_cache_size' => env('BUILDKIT_KUBERNETES_CACHE_SIZE', '10Gi'),
    'pull_policy' => env('BUILDKIT_PULL_POLICY', 'if-not-present'),
    'network_mode' => env('BUILDKIT_NETWORK_MODE', 'bridge'),
    'http_proxy' => env('BUILDKIT_HTTP_PROXY', env('HTTP_PROXY', env('http_proxy', ''))),
    'https_proxy' => env('BUILDKIT_HTTPS_PROXY', env('HTTPS_PROXY', env('https_proxy', ''))),
    'no_proxy' => env('BUILDKIT_NO_PROXY', env('NO_PROXY', env('no_proxy', ''))),
    'timeout' => (int) env('BUILDKIT_TIMEOUT', 1800),
    'log_poll_interval' => (int) env('BUILDKIT_LOG_POLL_INTERVAL', 5),
    'max_log_bytes' => (int) env('BUILDKIT_MAX_LOG_BYTES', 8 * 1024 * 1024),
];

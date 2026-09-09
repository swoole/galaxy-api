<?php

declare(strict_types=1);
/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */
use Hyperf\Contract\StdoutLoggerInterface;

return [
    'app_name' => env('APP_NAME', 'skeleton'),
    'app_env' => env('APP_ENV', 'dev'),
    'scan_cacheable' => env('SCAN_CACHEABLE', false),
    StdoutLoggerInterface::class => [
        'log_level' => explode(',', env('STDOUT_LOG_LEVEL', 'debug,info,notice,warning,error,critical,alert,emergency')),
    ],
    // Galaxy API 对外访问地址。
    'base_url' => env('APP_BASE_URL', 'http://localhost'),
    // Swarm 节点 Agent 主动连接的管理中心地址，禁止使用回环地址。
    'agent_server_url' => env('AGENT_SERVER_URL', env('WEBHOOK_BASE_URL', env('APP_BASE_URL', 'http://localhost:9501'))),
    // Git 服务必须能够访问该地址，不能使用浏览器当前地址或仅宿主机可用的 localhost。
    'webhook_base_url' => env('WEBHOOK_BASE_URL', env('APP_BASE_URL', 'http://localhost:9501')),
    // Native SSH terminal relay. The relay talks only to the two token-protected
    // internal endpoints and then reuses the existing one-time WebSocket ticket.
    'ssh_relay' => [
        'enabled' => (bool) env('SSH_RELAY_ENABLED', false),
        'internal_token' => (string) env('SSH_RELAY_INTERNAL_TOKEN', ''),
        'public_host' => (string) env('SSH_RELAY_PUBLIC_HOST', ''),
        'public_port' => (int) env('SSH_RELAY_PUBLIC_PORT', 9522),
        'user' => (string) env('SSH_RELAY_USER', 'galaxy'),
    ],
    // Private Helm SDK sidecar. It listens on loopback and receives a
    // short-lived kubeconfig only while an application-market job runs.
    'helm_service' => [
        'enabled' => (bool) env('HELM_SERVICE_ENABLED', true),
        'url' => (string) env('HELM_SERVICE_URL', 'http://127.0.0.1:9530'),
        'internal_token' => (string) env('HELM_SERVICE_INTERNAL_TOKEN', ''),
    ],
];

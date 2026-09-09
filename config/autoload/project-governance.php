<?php

declare(strict_types=1);

return [
    'retention_enabled' => (bool) env('PROJECT_RETENTION_ENABLED', env('APP_RETENTION_ENABLED', true)),
    'retention_interval' => (int) env('PROJECT_RETENTION_INTERVAL', env('APP_RETENTION_INTERVAL', 3600)),
    'recovery_interval' => (int) env('PROJECT_RECOVERY_INTERVAL', env('APP_RECOVERY_INTERVAL', 300)),
    'defaults' => [
        'metric_days' => (int) env('PROJECT_RETENTION_METRIC_DAYS', env('APP_RETENTION_METRIC_DAYS', 30)),
        'event_days' => (int) env('PROJECT_RETENTION_EVENT_DAYS', env('APP_RETENTION_EVENT_DAYS', 90)),
        'resolved_alert_days' => (int) env('PROJECT_RETENTION_ALERT_DAYS', env('APP_RETENTION_ALERT_DAYS', 180)),
        'build_log_days' => (int) env('PROJECT_RETENTION_BUILD_LOG_DAYS', env('APP_RETENTION_BUILD_LOG_DAYS', 90)),
        'audit_days' => (int) env('PROJECT_RETENTION_AUDIT_DAYS', env('APP_RETENTION_AUDIT_DAYS', 365)),
    ],
];

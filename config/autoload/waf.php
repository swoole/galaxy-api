<?php

use function Hyperf\Support\env;

return [
    'enabled' => env('WAF_ENABLED', false),
    // Operators can pin a tested digest or use their own registry mirror.
    'image' => env('WAF_IMAGE', 'owasp/modsecurity-crs@sha256:7698c48a41a43138ae317feeec964d1515b861cc1d9e50617bc1cdfe9f314e1d'),
    'max_policies' => 20,
    // Includes active, failed and retiring generations; bound overlap during log drain.
    'max_generations' => 24,
    'max_policy_generations' => 3,
    'collector_enabled' => env('WAF_ENABLED', false) && env('WAF_COLLECTOR_ENABLED', true),
    'collector_interval' => 5,
    'control_url' => env('WAF_CONTROL_URL', ''),
    'control_secret' => env('WAF_CONTROL_SECRET', ''),
    'max_auto_bans' => 1000,
    'max_auto_ban_records' => 10000,
    'max_auto_signals' => 100000,
    'max_control_configs' => 500,
    'challenge_policy_rate' => 1200,
    'challenge_client_rate' => 20,
    'data_directory' => env('WAF_DATA_DIRECTORY', BASE_PATH . '/runtime/waf-data'),
    'data_updater_enabled' => env('WAF_ENABLED', false) && env('WAF_DATA_UPDATER_ENABLED', true),
    'data_max_sources' => 16,
    'data_max_download_bytes' => 16777216,
    'data_max_database_bytes' => 67108864,
    'data_max_entries' => 100000,
    // Only platform configuration may authorize private/HTTP feed destinations.
    'data_trusted_hosts' => [],
];

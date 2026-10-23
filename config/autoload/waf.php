<?php

use function Hyperf\Support\env;

return [
    'enabled' => env('WAF_ENABLED', false),
    // Operators can pin a tested digest or use their own registry mirror.
    'image' => env('WAF_IMAGE', 'owasp/modsecurity-crs@sha256:7698c48a41a43138ae317feeec964d1515b861cc1d9e50617bc1cdfe9f314e1d'),
    'max_policies' => 20,
    'collector_enabled' => env('WAF_ENABLED', false) && env('WAF_COLLECTOR_ENABLED', true),
];

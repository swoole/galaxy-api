<?php

declare(strict_types=1);

return [
    // Production deployments should set the same base64-encoded 32-byte key
    // on every API instance. A local protected key file is generated otherwise.
    'credential_key' => env('SWARM_CREDENTIAL_KEY', ''),
    'credential_key_file' => env('SWARM_CREDENTIAL_KEY_FILE', BASE_PATH . '/storage/keys/swarm-credential.key'),
    // Redis Agent leases expire after 45 seconds. Reconcile MySQL presence
    // frequently so an ungraceful WebSocket loss cannot leave a node online.
    'agent_presence_enabled' => (bool) env('AGENT_PRESENCE_ENABLED', true),
    'agent_presence_interval' => (int) env('AGENT_PRESENCE_INTERVAL', 15),
    // Node-local image metadata is refreshed by the API in the background and
    // served from Redis to lightweight UI detail popovers.
    'image_inventory_enabled' => (bool) env('SWARM_IMAGE_INVENTORY_ENABLED', true),
    'image_inventory_interval' => (int) env('SWARM_IMAGE_INVENTORY_INTERVAL', 60),
    'image_inventory_cache_ttl' => (int) env('SWARM_IMAGE_INVENTORY_CACHE_TTL', 600),
    'image_inventory_stale_after' => (int) env('SWARM_IMAGE_INVENTORY_STALE_AFTER', 180),
    'acme_backup_enabled' => (bool) env('ACME_BACKUP_ENABLED', true),
    'acme_backup_process_interval' => (int) env('ACME_BACKUP_PROCESS_INTERVAL', 60),
];

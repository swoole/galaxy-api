<?php

namespace App\Model;

class AppMarketSwarmInstallation extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_INSTALLING = 'installing';
    public const STATUS_RUNNING = 'running';
    public const STATUS_FAILED = 'failed';

    protected ?string $table = 'app_market_swarm_installation';

    protected array $guarded = ['id'];

    protected array $casts = [
        'id' => 'integer',
        'org_id' => 'integer',
        'cluster_id' => 'integer',
        'tpl_id' => 'integer',
        'uid' => 'integer',
        'docker_service_id' => 'string',
        'docker_config_ids' => 'array',
        'docker_secret_ids' => 'array',
        'form' => 'array',
        'resource_config' => 'array',
        'network_config' => 'array',
        'port_mappings' => 'array',
        'volume_mappings' => 'array',
        'config_schema' => 'array',
        'config_values' => 'array',
        'template_snapshot' => 'array',
        'created_at' => 'integer',
        'updated_at' => 'integer',
    ];
}

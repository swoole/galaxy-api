<?php

namespace App\Model;

class ClusterWebGateway extends Model
{
    public const PROVIDER_TRAEFIK = 'traefik';
    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_DEGRADED = 'degraded';
    public const STATUS_MISSING = 'missing';
    public const STATUS_ERROR = 'error';

    protected ?string $table = 'cluster_web_gateway';

    protected array $guarded = ['id'];

    protected array $casts = [
        'id' => 'integer', 'org_id' => 'integer', 'cluster_id' => 'integer',
        'http_port' => 'integer', 'https_port' => 'integer', 'replicas' => 'integer',
        'redirect_https' => 'boolean', 'access_log_enabled' => 'boolean',
        'metrics_enabled' => 'boolean', 'dashboard_enabled' => 'boolean',
        'dashboard_port' => 'integer', 'acme_enabled' => 'boolean',
        'workspace_certificate_id' => 'integer', 'workspace_https_redirect' => 'boolean',
        'configuration' => 'array', 'creator' => 'integer', 'created_at' => 'integer',
        'updated_at' => 'integer', 'synced_at' => 'integer',
    ];

    public function cluster()
    {
        return $this->belongsTo(Cluster::class, 'cluster_id', 'id');
    }
}

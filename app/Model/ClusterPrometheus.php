<?php

namespace App\Model;

class ClusterPrometheus extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_DEGRADED = 'degraded';
    public const STATUS_ERROR = 'error';

    protected ?string $table = 'cluster_prometheus';

    protected array $guarded = ['id'];

    protected array $casts = [
        'id' => 'integer', 'org_id' => 'integer', 'cluster_id' => 'integer',
        'scrape_interval' => 'integer', 'retention_days' => 'integer',
        'configuration' => 'array', 'creator' => 'integer', 'created_at' => 'integer',
        'updated_at' => 'integer', 'synced_at' => 'integer',
    ];

    public function cluster()
    {
        return $this->belongsTo(Cluster::class, 'cluster_id', 'id');
    }
}

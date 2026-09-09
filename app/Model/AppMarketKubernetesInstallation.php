<?php

namespace App\Model;

class AppMarketKubernetesInstallation extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_INSTALLING = 'installing';
    public const STATUS_RUNNING = 'running';
    public const STATUS_FAILED = 'failed';

    protected ?string $table = 'app_market_kubernetes_installation';

    protected array $guarded = ['id'];

    protected array $casts = [
        'id' => 'integer',
        'org_id' => 'integer',
        'cluster_id' => 'integer',
        'tpl_id' => 'integer',
        'uid' => 'integer',
        'resource_refs' => 'array',
        'form' => 'array',
        'created_at' => 'integer',
        'updated_at' => 'integer',
    ];
}

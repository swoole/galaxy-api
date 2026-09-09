<?php

namespace App\Model;

final class FrpServer extends Model
{
    protected ?string $table = 'frp_server';

    protected array $guarded = ['id'];

    protected array $casts = [
        'id' => 'integer',
        'org_id' => 'integer',
        'cluster_id' => 'integer',
        'bind_port' => 'integer',
        'vhost_http_port' => 'integer',
        'vhost_https_port' => 'integer',
        'dashboard_port' => 'integer',
        'management_mode' => 'string',
        'creator' => 'integer',
        'created_at' => 'integer',
        'updated_at' => 'integer',
    ];

    public function clients()
    {
        return $this->hasMany(FrpClient::class, 'server_id');
    }

    public function cluster()
    {
        return $this->belongsTo(Cluster::class, 'cluster_id');
    }
}

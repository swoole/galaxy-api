<?php

namespace App\Model;

final class FrpClient extends Model
{
    protected ?string $table = 'frp_client';

    protected array $guarded = ['id'];

    protected array $casts = [
        'id' => 'integer',
        'org_id' => 'integer',
        'server_id' => 'integer',
        'cluster_id' => 'integer',
        'transport_pool_count' => 'integer',
        'management_mode' => 'string',
        'creator' => 'integer',
        'created_at' => 'integer',
        'updated_at' => 'integer',
    ];

    public function server()
    {
        return $this->belongsTo(FrpServer::class, 'server_id');
    }

    public function cluster()
    {
        return $this->belongsTo(Cluster::class, 'cluster_id');
    }

    public function tunnels()
    {
        return $this->hasMany(FrpTunnel::class, 'client_id');
    }
}

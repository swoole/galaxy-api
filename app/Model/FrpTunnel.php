<?php

namespace App\Model;

final class FrpTunnel extends Model
{
    protected ?string $table = 'frp_tunnel';

    protected array $guarded = ['id'];

    protected array $casts = [
        'id' => 'integer',
        'org_id' => 'integer',
        'client_id' => 'integer',
        'local_port' => 'integer',
        'remote_port' => 'integer',
        'custom_domains' => 'array',
        'locations' => 'array',
        'transport_encryption' => 'boolean',
        'transport_compression' => 'boolean',
        'enabled' => 'boolean',
        'created_at' => 'integer',
        'updated_at' => 'integer',
    ];

    public function client()
    {
        return $this->belongsTo(FrpClient::class, 'client_id');
    }
}

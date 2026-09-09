<?php

namespace App\Model;

class ClusterAgentNode extends Model
{
    protected ?string $table = 'cluster_agent_node';

    protected array $guarded = [];

    protected array $casts = [
        'id' => 'integer',
        'cluster_id' => 'integer',
        'credential_version' => 'integer',
        'capabilities' => 'array',
        'last_seen_at' => 'integer',
        'created_at' => 'integer',
        'updated_at' => 'integer',
    ];
}

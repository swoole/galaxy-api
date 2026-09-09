<?php

namespace App\Model;

class ClusterAgentCredential extends Model
{
    protected ?string $table = 'cluster_agent_credential';

    protected array $guarded = [];

    protected array $casts = [
        'id' => 'integer',
        'cluster_id' => 'integer',
        'version' => 'integer',
        'created_at' => 'integer',
        'expires_at' => 'integer',
        'used_at' => 'integer',
        'revoked_at' => 'integer',
    ];
}

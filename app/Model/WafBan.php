<?php

namespace App\Model;

class WafBan extends Model
{
    protected ?string $table = 'waf_ban';
    protected array $guarded = ['id'];
    protected array $casts = ['id' => 'integer', 'org_id' => 'integer', 'cluster_id' => 'integer',
        'policy_id' => 'integer', 'events_count' => 'integer', 'triggered_at' => 'integer',
        'expires_at' => 'integer', 'released_at' => 'integer', 'released_by' => 'integer'];
}

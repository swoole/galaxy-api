<?php

namespace App\Model;

class WafEvent extends Model
{
    protected ?string $table = 'waf_event';
    protected array $guarded = ['id'];
    protected array $casts = [
        'id' => 'integer', 'org_id' => 'integer', 'cluster_id' => 'integer',
        'policy_id' => 'integer', 'occurred_at' => 'integer', 'status_code' => 'integer', 'messages' => 'array',
    ];
}

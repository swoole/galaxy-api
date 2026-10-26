<?php

namespace App\Model;

class WafSignal extends Model
{
    protected ?string $table = 'waf_signal';
    protected array $guarded = ['id'];
    protected array $casts = ['id' => 'integer', 'org_id' => 'integer', 'cluster_id' => 'integer',
        'policy_id' => 'integer', 'occurred_at' => 'integer'];
}

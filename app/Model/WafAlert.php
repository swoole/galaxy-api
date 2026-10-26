<?php

namespace App\Model;

class WafAlert extends Model
{
    protected ?string $table = 'waf_alert';
    protected array $guarded = ['id'];
    protected array $casts = ['id' => 'integer', 'org_id' => 'integer', 'cluster_id' => 'integer',
        'policy_id' => 'integer', 'context' => 'array', 'occurrences' => 'integer',
        'first_seen_at' => 'integer', 'last_seen_at' => 'integer', 'resolved_at' => 'integer', 'notified_at' => 'integer',
        'acknowledged_at' => 'integer', 'acknowledged_by' => 'integer'];
}

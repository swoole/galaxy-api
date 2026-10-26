<?php

namespace App\Model;

class WafPolicyRevision extends Model
{
    protected ?string $table = 'waf_policy_revision';
    protected array $guarded = ['id'];
    protected array $casts = ['id' => 'integer', 'org_id' => 'integer', 'cluster_id' => 'integer',
        'policy_id' => 'integer', 'actor_id' => 'integer', 'rollback_of' => 'integer',
        'settings' => 'array', 'created_at' => 'integer', 'completed_at' => 'integer'];
}

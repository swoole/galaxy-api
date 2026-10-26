<?php

namespace App\Model;

class WafRuntimeConfig extends Model
{
    protected ?string $table = 'waf_runtime_config';
    protected array $guarded = ['id'];
    protected array $casts = ['id' => 'integer', 'org_id' => 'integer', 'cluster_id' => 'integer',
        'policy_id' => 'integer', 'settings' => 'array', 'created_at' => 'integer'];
}

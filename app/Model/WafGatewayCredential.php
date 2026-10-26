<?php

namespace App\Model;

class WafGatewayCredential extends Model
{
    protected ?string $table = 'waf_gateway_credential';
    protected array $guarded = ['id'];
    protected array $hidden = ['token'];
    protected array $casts = ['id' => 'integer', 'org_id' => 'integer', 'cluster_id' => 'integer', 'policy_id' => 'integer', 'created_at' => 'integer'];
}

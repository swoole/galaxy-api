<?php

namespace App\Model;

class ManagedDomain extends Model
{
    protected ?string $table = 'managed_domain';
    protected array $guarded = ['id'];
    protected array $casts = [
        'id' => 'integer', 'org_id' => 'integer', 'allow_subdomains' => 'boolean', 'creator' => 'integer',
        'created_at' => 'integer', 'updated_at' => 'integer',
    ];
}

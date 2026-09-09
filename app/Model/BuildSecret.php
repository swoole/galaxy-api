<?php

namespace App\Model;

class BuildSecret extends Model
{
    protected ?string $table = 'build_secret';
    protected array $guarded = ['id'];
    protected array $hidden = ['encrypted_value', 'value_hash'];
    protected array $casts = [
        'id' => 'integer', 'org_id' => 'integer', 'project_id' => 'integer',
        'build_id' => 'integer', 'value_size' => 'integer', 'created_at' => 'integer',
    ];
}

<?php

namespace App\Model;

class PipelineSecret extends Model
{
    protected ?string $table = 'pipeline_secret';
    protected array $guarded = ['id'];
    protected array $hidden = ['encrypted_value', 'value_hash'];
    protected array $casts = [
        'id' => 'integer', 'org_id' => 'integer', 'group_id' => 'integer', 'project_id' => 'integer',
        'pipeline_id' => 'integer', 'value_size' => 'integer', 'creator' => 'integer',
        'created_at' => 'integer', 'updated_at' => 'integer',
    ];
}

<?php

namespace App\Model;

class ProjectRuntimeEvent extends Model
{
    protected ?string $table = 'project_runtime_event';
    protected array $guarded = ['id'];
    protected array $casts = [
        'id' => 'integer', 'org_id' => 'integer', 'group_id' => 'integer', 'project_id' => 'integer',
        'runtime_id' => 'integer', 'cluster_id' => 'integer', 'attributes' => 'array',
        'occurred_at' => 'integer', 'created_at' => 'integer',
    ];
}

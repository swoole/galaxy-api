<?php

namespace App\Model;

class ProjectRegistryRel extends Model
{
    protected ?string $table = 'project_registry_rel';

    protected array $guarded = ['id'];

    protected array $casts = [
        'id' => 'integer',
        'org_id' => 'integer',
        'project_id' => 'integer',
        'registry_id' => 'integer',
        'created_at' => 'integer',
    ];
}

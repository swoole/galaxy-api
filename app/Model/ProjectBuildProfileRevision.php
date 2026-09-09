<?php

namespace App\Model;

class ProjectBuildProfileRevision extends Model
{
    protected ?string $table = 'project_build_profile_revision';

    protected array $guarded = ['id'];

    protected array $casts = [
        'id' => 'integer', 'org_id' => 'integer', 'group_id' => 'integer', 'project_id' => 'integer',
        'revision' => 'integer', 'created_by' => 'integer', 'created_at' => 'integer',
        'profile' => 'array',
    ];
}

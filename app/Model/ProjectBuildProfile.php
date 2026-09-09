<?php

namespace App\Model;

class ProjectBuildProfile extends Model
{
    public const SOURCE_REPOSITORY = 'repository';
    public const SOURCE_TEMPLATE = 'template';

    protected ?string $table = 'project_build_profile';

    protected array $guarded = ['id'];

    protected array $casts = [
        'id' => 'integer', 'org_id' => 'integer', 'group_id' => 'integer', 'project_id' => 'integer',
        'template_id' => 'integer', 'created_by' => 'integer', 'updated_by' => 'integer',
        'created_at' => 'integer', 'updated_at' => 'integer',
        'options' => 'array', 'build_args' => 'array',
    ];
}

<?php

namespace App\Model;

class ProjectReleaseConfig extends Model
{
    protected ?string $table = 'project_release_config';

    protected array $guarded = ['id'];

    protected array $hidden = ['content'];

    protected array $casts = [
        'id' => 'integer', 'org_id' => 'integer', 'project_id' => 'integer',
        'release_id' => 'integer', 'source_configuration_id' => 'integer',
        'source_version' => 'integer', 'content_size' => 'integer',
        'file_mode' => 'integer', 'created_at' => 'integer',
    ];
}

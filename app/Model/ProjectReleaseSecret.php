<?php

namespace App\Model;

class ProjectReleaseSecret extends Model
{
    protected ?string $table = 'project_release_secret';

    protected array $guarded = ['id'];

    protected array $hidden = ['encrypted_value', 'value_hash'];

    protected array $casts = [
        'id' => 'integer', 'org_id' => 'integer', 'project_id' => 'integer',
        'release_id' => 'integer', 'source_configuration_id' => 'integer',
        'source_version' => 'integer', 'value_size' => 'integer',
        'file_mode' => 'integer', 'created_at' => 'integer',
    ];
}

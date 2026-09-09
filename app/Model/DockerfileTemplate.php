<?php

namespace App\Model;

class DockerfileTemplate extends Model
{
    protected ?string $table = 'dockerfile_template';

    protected array $guarded = ['id'];

    protected array $casts = [
        'id' => 'integer', 'org_id' => 'integer', 'created_at' => 'integer', 'updated_at' => 'integer',
        'runtime_versions' => 'array', 'framework_versions' => 'array', 'platforms' => 'array',
        'tags' => 'array', 'manifest' => 'array',
    ];
}

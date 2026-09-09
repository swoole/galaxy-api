<?php

namespace App\Model;

class BuildArtifact extends Model
{
    public const TYPE_CONTAINER_IMAGE = 'container-image';

    protected ?string $table = 'build_artifact';

    protected array $guarded = ['id'];

    protected array $casts = [
        'id' => 'integer', 'org_id' => 'integer', 'group_id' => 'integer', 'project_id' => 'integer',
        'build_id' => 'integer', 'size' => 'integer', 'metadata' => 'array', 'created_at' => 'integer',
    ];

    public function build()
    {
        return $this->belongsTo(Build::class, 'build_id', 'id');
    }
}

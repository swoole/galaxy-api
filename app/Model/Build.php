<?php

namespace App\Model;

class Build extends Model
{
    use TraitRelationCreatorInfo;

    public const STATUS_PENDING_RUN = 0;
    public const STATUS_RUNNING = 1;
    public const STATUS_SUCCESS = 2;
    public const STATUS_FAILED = 3;
    public const STATUS_CANCELED = 4;

    protected ?string $table = 'build';

    protected array $guarded = ['id'];

    protected array $casts = [
        'id' => 'integer', 'org_id' => 'integer', 'group_id' => 'integer', 'project_id' => 'integer',
        'pipeline_id' => 'integer', 'hook_id' => 'integer', 'start_at' => 'integer', 'end_at' => 'integer',
        'status' => 'integer', 'cancel_requested' => 'integer', 'creator' => 'integer',
        'created_at' => 'integer', 'updated_at' => 'integer',
        'spec_snapshot' => 'array', 'result' => 'array',
    ];

    public function pipeline()
    {
        return $this->belongsTo(Pipeline::class, 'pipeline_id', 'id')
            ->select('id', 'title', 'schema_version', 'runner_kind', 'archived_at');
    }

    public function artifacts()
    {
        return $this->hasMany(BuildArtifact::class, 'build_id', 'id')->orderBy('id');
    }
}

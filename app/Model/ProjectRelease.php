<?php

namespace App\Model;

class ProjectRelease extends Model
{
    use TraitRelationCreatorInfo;

    public const STATUS_PENDING = 'pending';
    public const STATUS_DEPLOYING = 'deploying';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_ROLLED_BACK = 'rolled-back';

    public const OPERATION_DEPLOY = 'deploy';
    public const OPERATION_UPDATE_IMAGE = 'update-image';
    public const OPERATION_ROLLBACK = 'rollback';
    public const OPERATION_SCALE = 'scale';

    protected ?string $table = 'project_release';

    protected array $guarded = ['id'];

    protected array $casts = [
        'id' => 'integer', 'org_id' => 'integer', 'group_id' => 'integer', 'project_id' => 'integer',
        'env_id' => 'integer', 'cluster_id' => 'integer', 'artifact_id' => 'integer', 'creator' => 'integer',
        'previous_release_id' => 'integer', 'desired_spec' => 'array', 'result' => 'array',
        'started_at' => 'integer', 'finished_at' => 'integer', 'created_at' => 'integer', 'updated_at' => 'integer',
    ];

    public function artifact()
    {
        return $this->belongsTo(BuildArtifact::class, 'artifact_id', 'id');
    }

    public function runtime()
    {
        return $this->hasOne(ProjectRuntime::class, 'release_id', 'id');
    }

    public function env()
    {
        return $this->belongsTo(Env::class, 'env_id', 'id')->select('id', 'title');
    }

    public function cluster()
    {
        return $this->belongsTo(Cluster::class, 'cluster_id', 'id')->select('id', 'title', 'orchestrator_type');
    }

    public function secrets()
    {
        return $this->hasMany(ProjectReleaseSecret::class, 'release_id', 'id');
    }

    public function configs()
    {
        return $this->hasMany(ProjectReleaseConfig::class, 'release_id', 'id');
    }
}

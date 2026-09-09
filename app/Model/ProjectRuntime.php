<?php

namespace App\Model;

class ProjectRuntime extends Model
{
    public const WORKLOAD_SWARM_SERVICE = 'service';

    public const WORKLOAD_KUBERNETES_DEPLOYMENT = 'deployment';

    public const WORKLOAD_KUBERNETES_STATEFUL_SET = 'statefulset';

    public const WORKLOAD_KUBERNETES_JOB = 'job';

    protected ?string $table = 'project_runtime';

    protected array $guarded = ['id'];

    protected array $casts = [
        'id' => 'integer', 'org_id' => 'integer', 'group_id' => 'integer', 'project_id' => 'integer',
        'env_id' => 'integer', 'cluster_id' => 'integer', 'release_id' => 'integer',
        'desired_count' => 'integer', 'running_count' => 'integer', 'spec' => 'array',
        'provider_metadata' => 'array',
        'last_synced_at' => 'integer', 'created_at' => 'integer', 'updated_at' => 'integer',
    ];

    public function providerReference(): array
    {
        return [
            'orchestrator_type' => (string) $this->orchestrator_type,
            'workload_kind' => (string) $this->workload_kind,
            'namespace' => (string) $this->runtime_namespace,
            'reference' => (string) $this->runtime_ref,
            'metadata' => (array) $this->provider_metadata,
        ];
    }

    public function release()
    {
        return $this->belongsTo(ProjectRelease::class, 'release_id', 'id');
    }

    public function env()
    {
        return $this->belongsTo(Env::class, 'env_id', 'id')->select('id', 'title');
    }

    public function cluster()
    {
        return $this->belongsTo(Cluster::class, 'cluster_id', 'id')->select('id', 'title', 'orchestrator_type');
    }

    public function project()
    {
        return $this->belongsTo(Project::class, 'project_id', 'id')->select('id', 'title', 'alias');
    }

    public function group()
    {
        return $this->belongsTo(Group::class, 'group_id', 'id')->select('id', 'title', 'alias');
    }

    public function metrics()
    {
        return $this->hasMany(ProjectRuntimeMetric::class, 'runtime_id', 'id');
    }
}

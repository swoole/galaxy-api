<?php

namespace App\Model;

class WorkspaceProjectRepository extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_CLONING = 'cloning';
    public const STATUS_READY = 'ready';
    public const STATUS_ERROR = 'error';

    protected ?string $table = 'workspace_project_repository';

    protected array $guarded = ['id'];

    protected array $casts = [
        'id' => 'integer', 'org_id' => 'integer', 'group_id' => 'integer',
        'workspace_id' => 'integer', 'project_id' => 'integer', 'repository_id' => 'integer',
        'last_sync_at' => 'integer', 'created_at' => 'integer', 'updated_at' => 'integer',
    ];

    public function workspace()
    {
        return $this->belongsTo(Workspace::class, 'workspace_id', 'id');
    }

    public function project()
    {
        return $this->belongsTo(Project::class, 'project_id', 'id');
    }

    public function repository()
    {
        return $this->belongsTo(ProjectRepository::class, 'repository_id', 'id');
    }
}

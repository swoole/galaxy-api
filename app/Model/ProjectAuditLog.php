<?php

namespace App\Model;

class ProjectAuditLog extends Model
{
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';

    protected ?string $table = 'project_audit_log';
    protected array $guarded = ['id'];
    protected array $casts = [
        'id' => 'integer', 'org_id' => 'integer', 'group_id' => 'integer', 'project_id' => 'integer',
        'uid' => 'integer', 'http_status' => 'integer', 'duration_ms' => 'integer',
        'metadata' => 'array', 'created_at' => 'integer',
    ];

    public function actor()
    {
        return $this->belongsTo(User::class, 'uid', 'id')->select('id', 'email');
    }
}


<?php

namespace App\Model;

class ProjectRetentionPolicy extends Model
{
    protected ?string $table = 'project_retention_policy';
    protected array $guarded = ['id'];
    protected array $casts = [
        'id' => 'integer', 'org_id' => 'integer', 'group_id' => 'integer', 'project_id' => 'integer',
        'enabled' => 'boolean', 'metric_days' => 'integer', 'event_days' => 'integer',
        'resolved_alert_days' => 'integer', 'build_log_days' => 'integer', 'audit_days' => 'integer',
        'last_purged_at' => 'integer', 'creator' => 'integer', 'created_at' => 'integer', 'updated_at' => 'integer',
    ];
}


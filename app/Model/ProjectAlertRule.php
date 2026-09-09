<?php

namespace App\Model;

class ProjectAlertRule extends Model
{
    protected ?string $table = 'project_alert_rule';
    protected array $guarded = ['id'];
    protected array $casts = [
        'id' => 'integer', 'org_id' => 'integer', 'group_id' => 'integer', 'project_id' => 'integer',
        'enabled' => 'boolean', 'recipients' => 'array', 'failure_threshold' => 'integer',
        'cooldown_seconds' => 'integer', 'notify_recovery' => 'boolean', 'creator' => 'integer',
        'slo_availability_target' => 'float', 'slo_error_rate_max' => 'float',
        'slo_p95_ms_max' => 'integer',
        'created_at' => 'integer', 'updated_at' => 'integer',
    ];
}

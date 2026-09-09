<?php

namespace App\Model;

class ProjectAlert extends Model
{
    public const STATUS_OPEN = 'open';
    public const STATUS_RESOLVED = 'resolved';

    protected ?string $table = 'project_alert';
    protected array $guarded = ['id'];
    protected array $casts = [
        'id' => 'integer', 'org_id' => 'integer', 'group_id' => 'integer', 'project_id' => 'integer',
        'runtime_id' => 'integer', 'rule_id' => 'integer', 'context' => 'array',
        'occurrences' => 'integer', 'first_seen_at' => 'integer', 'last_seen_at' => 'integer',
        'notified_at' => 'integer', 'resolved_at' => 'integer',
    ];
}

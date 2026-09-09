<?php

namespace App\Model;

class ProjectRuntimeMetric extends Model
{
    protected ?string $table = 'project_runtime_metric';

    protected array $guarded = ['id'];

    protected array $casts = [
        'id' => 'integer', 'org_id' => 'integer', 'group_id' => 'integer', 'project_id' => 'integer',
        'runtime_id' => 'integer', 'cluster_id' => 'integer', 'collected_at' => 'integer',
        'cpu_percent' => 'float', 'memory_usage' => 'integer', 'memory_limit' => 'integer',
        'network_rx' => 'integer', 'network_tx' => 'integer', 'pids' => 'integer',
        'disk_read' => 'integer', 'disk_write' => 'integer', 'disk_io_coverage' => 'float',
        'desired_tasks' => 'integer', 'running_tasks' => 'integer', 'failed_tasks' => 'integer',
        'local_containers' => 'integer', 'metric_coverage' => 'float',
    ];
}

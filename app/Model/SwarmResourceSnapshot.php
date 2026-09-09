<?php

declare(strict_types=1);
/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */

namespace App\Model;

final class SwarmResourceSnapshot extends Model
{
    public const TYPE_SERVICE = 'service';

    public const TYPE_NODE = 'node';

    protected ?string $table = 'swarm_resource_snapshot';

    protected array $guarded = ['id'];

    protected array $casts = [
        'id' => 'integer',
        'org_id' => 'integer',
        'cluster_id' => 'integer',
        'desired_tasks' => 'integer',
        'running_tasks' => 'integer',
        'container_count' => 'integer',
        'cpu_percent' => 'float',
        'memory_usage' => 'integer',
        'memory_limit' => 'integer',
        'network_rx' => 'integer',
        'network_tx' => 'integer',
        'disk_read' => 'integer',
        'disk_write' => 'integer',
        'network_rx_bps' => 'float',
        'network_tx_bps' => 'float',
        'disk_read_bps' => 'float',
        'disk_write_bps' => 'float',
        'metric_available' => 'boolean',
        'rate_available' => 'boolean',
        'collected_at' => 'integer',
    ];
}

<?php

declare(strict_types=1);
/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */

namespace App\Model;

final class KubernetesResourceSnapshot extends Model
{
    public const TYPE_POD = 'pod';

    public const TYPE_NODE = 'node';

    protected ?string $table = 'kubernetes_resource_snapshot';

    protected array $guarded = ['id'];

    protected array $casts = [
        'id' => 'integer',
        'org_id' => 'integer',
        'cluster_id' => 'integer',
        'container_count' => 'integer',
        'cpu_percent' => 'float',
        'memory_usage' => 'integer',
        'memory_limit' => 'integer',
        'metric_available' => 'boolean',
        'collected_at' => 'integer',
    ];
}

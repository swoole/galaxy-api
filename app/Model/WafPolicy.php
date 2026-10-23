<?php

namespace App\Model;

use Hyperf\Database\Model\Builder;

class WafPolicy extends Model
{
    /** null is cluster scope; an empty list deliberately grants no project routes. */
    public static function forRoutes(Cluster $cluster, ?array $projectRouteIds = null): Builder
    {
        $query = self::where('org_id', $cluster->org_id)->where('cluster_id', $cluster->id);
        if ($projectRouteIds !== null) $query->where('route_source', 'project')->whereIn('route_id', $projectRouteIds);
        return $query;
    }

    protected ?string $table = 'waf_policy';
    protected array $guarded = ['id'];
    protected array $casts = [
        'id' => 'integer', 'org_id' => 'integer', 'cluster_id' => 'integer',
        'route_id' => 'integer', 'settings' => 'array', 'created_at' => 'integer',
        'updated_at' => 'integer', 'synced_at' => 'integer',
    ];
}

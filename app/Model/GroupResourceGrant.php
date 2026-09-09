<?php

namespace App\Model;

class GroupResourceGrant extends Model
{
    public const TYPE_CLUSTER = 'cluster';
    public const TYPE_DOMAIN = 'domain';

    protected ?string $table = 'group_resource_grant';

    protected array $guarded = [];

    protected array $casts = [
        'id' => 'integer',
        'org_id' => 'integer',
        'group_id' => 'integer',
        'resource_id' => 'integer',
        'granted_by' => 'integer',
        'created_at' => 'integer',
    ];

    public static function clusterIds(int $orgId, int $groupId): array
    {
        return self::where('org_id', $orgId)->where('group_id', $groupId)
            ->where('resource_type', self::TYPE_CLUSTER)
            ->pluck('resource_id')->map(static fn ($id): int => (int) $id)->all();
    }

    public static function canUseCluster(int $orgId, int $groupId, int $clusterId): bool
    {
        return $clusterId > 0 && self::where('org_id', $orgId)->where('group_id', $groupId)
            ->where('resource_type', self::TYPE_CLUSTER)->where('resource_id', $clusterId)->exists();
    }

    public static function domainIds(int $orgId, int $groupId): array
    {
        return self::where('org_id', $orgId)->where('group_id', $groupId)
            ->where('resource_type', self::TYPE_DOMAIN)
            ->pluck('resource_id')->map(static fn ($id): int => (int) $id)->all();
    }
}

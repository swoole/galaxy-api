<?php

namespace App\Model;

/**
 * @property int $id
 * @property int $org_id
 * @property int $registry_id
 * @property int $group_id
 * @property string $namespace
 * @property int $granted_by
 * @property int $created_at
 * @property int $updated_at
 */
class RegistryGroupGrant extends Model
{
    protected ?string $table = 'registry_group_grant';

    protected array $guarded = [];

    protected array $casts = [
        'id' => 'integer',
        'org_id' => 'integer',
        'registry_id' => 'integer',
        'group_id' => 'integer',
        'granted_by' => 'integer',
        'created_at' => 'integer',
        'updated_at' => 'integer',
    ];
}

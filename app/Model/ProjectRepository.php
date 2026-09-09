<?php

namespace App\Model;

class ProjectRepository extends Model
{
    public const TYPE_EXTERNAL = 2;

    public static array $types = [
        self::TYPE_EXTERNAL => '外部 Git 仓库',
    ];

    public const STATUS_DISABLED = 0;

    public const STATUS_ACTIVE = 1;

    protected ?string $table = 'project_repository';

    protected array $guarded = ['id'];

    protected array $hidden = ['webhook_secret_encrypted', 'webhook_secret_hash'];

    protected array $casts = [
        'id' => 'integer',
        'org_id' => 'integer',
        'group_id' => 'integer',
        'project_id' => 'integer',
        'type' => 'integer',
        'provider' => 'integer',
        'status' => 'integer',
        'checked_at' => 'integer',
        'webhook_checked_at' => 'integer',
        'created_at' => 'integer',
        'updated_at' => 'integer',
    ];
}

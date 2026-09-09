<?php

namespace App\Model;

class ProjectConfiguration extends Model
{
    public const KIND_ENV = 'env';
    public const KIND_CONFIG = 'config';
    public const KIND_SECRET = 'secret';

    protected ?string $table = 'project_configuration';

    protected array $guarded = ['id'];

    protected array $hidden = ['encrypted_value', 'value_hash'];

    protected array $casts = [
        'id' => 'integer', 'org_id' => 'integer', 'group_id' => 'integer', 'project_id' => 'integer',
        'env_id' => 'integer',
        'value_size' => 'integer', 'file_mode' => 'integer', 'version' => 'integer',
        'creator' => 'integer', 'created_at' => 'integer', 'updated_at' => 'integer',
    ];
}

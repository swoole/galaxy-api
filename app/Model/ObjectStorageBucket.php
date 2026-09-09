<?php

namespace App\Model;

class ObjectStorageBucket extends Model
{
    public const PROVIDER_COS = 'cos';
    public const PROVIDER_OSS = 'oss';
    public const PROVIDER_S3 = 's3';

    protected ?string $table = 'object_storage_bucket';

    protected array $guarded = ['id'];

    /** Credentials are encrypted; never expose via JSON. */
    protected array $hidden = ['config'];

    protected array $casts = [
        'id' => 'integer', 'org_id' => 'integer', 'cloud_account_id' => 'integer', 'is_default' => 'boolean',
        'creator' => 'integer', 'created_at' => 'integer', 'updated_at' => 'integer',
    ];
}

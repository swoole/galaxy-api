<?php

namespace App\Model;

class CloudAccount extends Model
{
    public const PROVIDER_ALIYUN = 'aliyun';
    public const PROVIDER_TENCENT_CLOUD = 'tencent_cloud';
    public const PROVIDER_AWS_S3 = 'aws_s3';

    protected ?string $table = 'cloud_account';

    protected array $guarded = ['id'];

    protected array $hidden = ['access_key_id', 'secret_ciphertext'];

    protected array $casts = [
        'id' => 'integer', 'org_id' => 'integer', 'last_verified_at' => 'integer',
        'metadata' => 'array', 'creator' => 'integer', 'created_at' => 'integer', 'updated_at' => 'integer',
    ];
}

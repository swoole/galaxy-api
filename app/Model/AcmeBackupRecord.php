<?php

namespace App\Model;

class AcmeBackupRecord extends Model
{
    public const STATUS_SUCCESS = 'success';
    public const STATUS_ERROR = 'error';

    protected ?string $table = 'acme_backup_record';

    protected array $guarded = ['id'];

    protected array $casts = [
        'id' => 'integer', 'policy_id' => 'integer', 'org_id' => 'integer',
        'cluster_id' => 'integer', 'gateway_id' => 'integer', 'bucket_id' => 'integer',
        'source_size' => 'integer', 'backup_size' => 'integer', 'created_at' => 'integer',
    ];
}

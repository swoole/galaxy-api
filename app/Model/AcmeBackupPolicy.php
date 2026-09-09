<?php

namespace App\Model;

class AcmeBackupPolicy extends Model
{
    protected ?string $table = 'acme_backup_policy';

    protected array $guarded = ['id'];

    protected array $casts = [
        'id' => 'integer', 'org_id' => 'integer', 'enabled' => 'boolean',
        'bucket_id' => 'integer', 'interval_seconds' => 'integer',
        'last_attempt_at' => 'integer', 'last_success_at' => 'integer',
        'next_backup_at' => 'integer', 'creator' => 'integer',
        'created_at' => 'integer', 'updated_at' => 'integer',
    ];

    public function bucket()
    {
        return $this->belongsTo(ObjectStorageBucket::class, 'bucket_id', 'id');
    }
}

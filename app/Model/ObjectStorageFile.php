<?php

namespace App\Model;

class ObjectStorageFile extends Model
{
    protected ?string $table = 'object_storage_file';

    protected array $guarded = ['id'];

    protected array $casts = [
        'id' => 'integer', 'org_id' => 'integer', 'bucket_id' => 'integer',
        'size' => 'integer', 'creator' => 'integer', 'created_at' => 'integer',
    ];
}

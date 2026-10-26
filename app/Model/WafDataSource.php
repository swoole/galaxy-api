<?php

namespace App\Model;

class WafDataSource extends Model
{
    protected ?string $table = 'waf_data_source';
    protected array $guarded = ['id'];
    protected array $casts = ['id' => 'integer', 'org_id' => 'integer', 'enabled' => 'boolean',
        'refresh_interval' => 'integer', 'max_age' => 'integer', 'config_version' => 'integer',
        'published_at' => 'integer', 'validated_at' => 'integer', 'last_checked_at' => 'integer',
        'next_check_at' => 'integer', 'updating_until' => 'integer', 'record_count' => 'integer', 'file_bytes' => 'integer',
        'created_by' => 'integer', 'updated_by' => 'integer', 'created_at' => 'integer', 'updated_at' => 'integer'];
}

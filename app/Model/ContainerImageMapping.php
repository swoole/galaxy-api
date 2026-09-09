<?php

namespace App\Model;

class ContainerImageMapping extends Model
{
    protected ?string $table = 'container_image_mapping';

    protected array $guarded = [];

    protected array $casts = [
        'id' => 'integer',
        'org_id' => 'integer',
        'cluster_id' => 'integer',
        'enabled' => 'integer',
        'creator' => 'integer',
        'created_at' => 'integer',
        'updated_at' => 'integer',
    ];
}


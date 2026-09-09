<?php

namespace App\Model;

class GroupSshKey extends Model
{
    protected ?string $table = 'group_ssh_key';

    protected array $guarded = ['id'];

    protected array $hidden = ['privatekey_encrypted'];

    protected array $casts = [
        'id' => 'integer',
        'org_id' => 'integer',
        'group_id' => 'integer',
        'generate_at' => 'integer',
        'updated_by' => 'integer',
    ];
}

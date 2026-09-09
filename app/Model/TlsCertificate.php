<?php

namespace App\Model;

class TlsCertificate extends Model
{
    public const SOURCE_SELF_SIGNED = 'self_signed';
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_CLOUD_IMPORT = 'cloud_import';
    public const SOURCE_LETS_ENCRYPT = 'lets_encrypt';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_PENDING = 'pending';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_NOT_YET_VALID = 'not_yet_valid';
    public const STATUS_ERROR = 'error';

    protected ?string $table = 'tls_certificate';

    protected array $guarded = ['id'];

    protected array $hidden = ['certificate_ciphertext', 'private_key_ciphertext'];

    protected array $casts = [
        'id' => 'integer', 'org_id' => 'integer', 'domains' => 'array', 'key_bits' => 'integer',
        'valid_from' => 'integer', 'valid_to' => 'integer', 'auto_renew' => 'boolean',
        'renew_before_days' => 'integer', 'last_renewed_at' => 'integer', 'next_renew_at' => 'integer',
        'metadata' => 'array', 'creator' => 'integer', 'created_at' => 'integer', 'updated_at' => 'integer',
    ];
}

<?php

namespace App\Model;

class KubernetesClusterConnection extends Model
{
    protected ?string $table = 'kubernetes_cluster_connection';

    protected array $guarded = [];

    protected array $hidden = ['credential_ciphertext', 'credential_fingerprint'];

    protected array $casts = [
        'id' => 'integer',
        'cluster_id' => 'integer',
        'ingress_http_port' => 'integer',
        'ingress_https_port' => 'integer',
        'last_checked_at' => 'integer',
        'created_at' => 'integer',
        'updated_at' => 'integer',
    ];

    public function cluster()
    {
        return $this->belongsTo(Cluster::class, 'cluster_id', 'id');
    }
}

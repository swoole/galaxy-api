<?php

namespace App\Model;

class ProjectRoute extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SYNCED = 'synced';
    public const STATUS_ERROR = 'error';

    protected ?string $table = 'project_route';

    protected array $guarded = ['id'];

    protected array $casts = [
        'id' => 'integer', 'org_id' => 'integer', 'group_id' => 'integer', 'project_id' => 'integer',
        'runtime_id' => 'integer', 'env_id' => 'integer', 'cluster_id' => 'integer',
        'provider_metadata' => 'array',
        'target_port' => 'integer', 'priority' => 'integer', 'methods' => 'array', 'tls_enabled' => 'boolean',
        'certificate_id' => 'integer', 'https_redirect' => 'boolean',
        'https_redirect_port' => 'integer',
        'pass_host_header' => 'boolean', 'ip_allowlist' => 'array', 'ip_denylist' => 'array',
        'rate_limit_average' => 'integer', 'rate_limit_burst' => 'integer',
        'rate_limit_period_seconds' => 'integer', 'max_inflight_requests' => 'integer',
        'retry_attempts' => 'integer', 'retry_initial_interval_ms' => 'integer',
        'dial_timeout_ms' => 'integer', 'response_header_timeout_ms' => 'integer',
        'idle_connection_timeout_ms' => 'integer', 'security_headers_enabled' => 'boolean',
        'compress_enabled' => 'boolean', 'request_body_limit_bytes' => 'integer',
        'custom_request_headers' => 'array', 'custom_response_headers' => 'array',
        'cors_enabled' => 'boolean', 'cors_allow_origins' => 'array',
        'cors_allow_methods' => 'array', 'cors_allow_headers' => 'array',
        'cors_allow_credentials' => 'boolean', 'cors_max_age_seconds' => 'integer',
        'healthcheck_interval_ms' => 'integer', 'healthcheck_timeout_ms' => 'integer',
        'sticky_cookie_enabled' => 'boolean',
        'enabled' => 'boolean', 'creator' => 'integer', 'created_at' => 'integer',
        'updated_at' => 'integer', 'synced_at' => 'integer',
    ];

    public function runtime()
    {
        return $this->belongsTo(ProjectRuntime::class, 'runtime_id', 'id');
    }

    public function project()
    {
        return $this->belongsTo(Project::class, 'project_id', 'id')->select('id', 'title', 'alias');
    }

    public function env()
    {
        return $this->belongsTo(Env::class, 'env_id', 'id')->select('id', 'title');
    }

    public function cluster()
    {
        return $this->belongsTo(Cluster::class, 'cluster_id', 'id')->select('id', 'title', 'orchestrator_type');
    }

    public function certificate()
    {
        return $this->belongsTo(TlsCertificate::class, 'certificate_id', 'id');
    }
}

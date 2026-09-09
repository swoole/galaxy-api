<?php

namespace App\Model;

class Workspace extends Model
{
    public const MODE_WEB_IDE = 'web-ide';
    public const MODE_TERMINAL = 'terminal';

    public const STATUS_PENDING = 'pending';
    public const STATUS_STARTING = 'starting';
    public const STATUS_RUNNING = 'running';
    public const STATUS_STOPPED = 'stopped';
    public const STATUS_ERROR = 'error';

    protected ?string $table = 'workspace';

    protected array $guarded = ['id'];

    protected array $casts = [
        'id' => 'integer', 'org_id' => 'integer', 'group_id' => 'integer',
        'uid' => 'integer', 'cluster_id' => 'integer',
        'published_port' => 'integer', 'gateway_vhost_id' => 'integer', 'spec' => 'array',
        'created_at' => 'integer', 'updated_at' => 'integer',
    ];

    public function group()
    {
        return $this->belongsTo(Group::class, 'group_id', 'id');
    }

    public function syncRuntimeLimits(array $limits): void
    {
        $cpu = (int) round(max(0, (int) ($limits['NanoCPUs'] ?? 0)) / 1_000_000);
        $memory = (int) round(max(0, (int) ($limits['MemoryBytes'] ?? 0)) / 1_048_576);
        $spec = (array) $this->spec;
        if ((int) ($spec['cpu'] ?? 0) === $cpu && (int) ($spec['memory'] ?? 0) === $memory) {
            return;
        }
        $spec['cpu'] = $cpu;
        $spec['memory'] = $memory;
        $this->spec = $spec;
        $this->updated_at = time();
        $this->save();
    }
}

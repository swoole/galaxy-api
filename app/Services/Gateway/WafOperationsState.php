<?php

namespace App\Services\Gateway;

use App\Exception\AppException;
use App\Model\WafAlert;
use App\Model\WafPolicyRevision;

/** Existing installations keep serving while the additive operations migration is pending. */
class WafOperationsState
{
    public function __construct(private WafSchemaState $schema = new WafSchemaState()) {}

    public function initialized(): bool
    {
        return $this->schema->ready([WafPolicyRevision::class, WafAlert::class]);
    }

    public function requireInitialized(): void
    {
        if (! $this->initialized()) throw new AppException(409, '请先应用 database/migrations/0061_add_waf_operations.sql 并刷新，启用策略版本与告警');
    }

    public function profile(): array
    {
        $ready = $this->initialized();
        return ['initialized' => $ready, 'note' => $ready ? '' : '策略版本与告警尚未初始化，请管理员执行 0061_add_waf_operations.sql；现有防护不受影响'];
    }
}

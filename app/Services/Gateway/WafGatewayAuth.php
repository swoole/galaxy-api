<?php

namespace App\Services\Gateway;

use App\Exception\AppException;
use App\Model\WafGatewayCredential;
use App\Model\WafPolicy;
use App\Model\Cluster;

/** Stable per-policy ingress credentials, shared through SQL and never exported in settings. */
final class WafGatewayAuth
{
    public function __construct(private WafSchemaState $schema) {}

    public function credential(WafPolicy $policy): string
    {
        if (! $this->schema->ready([WafGatewayCredential::class])) throw new AppException(409, '请先应用 0064_add_waf_gateway_credentials.sql，启用网关到 WAF 的请求认证');
        $scope = ['org_id' => $policy->org_id, 'cluster_id' => $policy->cluster_id, 'policy_id' => $policy->id];
        WafGatewayCredential::query()->insertOrIgnore($scope + ['token' => bin2hex(random_bytes(32)), 'created_at' => time()]);
        $row = WafGatewayCredential::where($scope)->first();
        if ($row === null) throw new AppException(409, 'WAF 网关凭据作用域不一致');
        if (! preg_match('/^[a-f0-9]{64}$/D', $row->token)) throw new AppException(409, 'WAF 网关凭据无效');
        return $row->token;
    }

    public function prune(Cluster $cluster): void
    {
        if ($this->schema->ready([WafGatewayCredential::class])) WafGatewayCredential::where('org_id', $cluster->org_id)->where('cluster_id', $cluster->id)
            ->whereNotIn('policy_id', WafPolicy::select('id'))->limit(1000)->delete();
    }

    public function render(string $token): array
    {
        if ($token === '') return ['http' => 'map $remote_addr $galaxy_gateway_authorized { default 1; }', 'access' => ''];
        if (! preg_match('/^[a-f0-9]{64}$/D', $token)) throw new AppException(422, 'WAF 网关凭据格式无效');
        return ['http' => "map \$http_x_galaxy_waf_gateway \$galaxy_gateway_authorized { default 0; {$token} 1; }",
            'access' => 'if ($galaxy_gateway_authorized = 0) { return 403; }'];
    }
}

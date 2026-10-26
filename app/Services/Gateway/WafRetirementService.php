<?php

namespace App\Services\Gateway;

use App\Model\Cluster;
use App\Model\WafPolicy;
use App\Services\Docker\SwarmApiClient;
use GuzzleHttp\Client;
use Hyperf\Redis\Redis;
use RuntimeException;

/** Old generations remain reachable by the collector, but no longer receive routed traffic. */
final class WafRetirementService
{
    public function __construct(private Redis $redis, private SwarmApiClient $docker, private SwarmGatewayMutationLock $lock) {}

    private function key(int $orgId, int $clusterId, int $policyId): string
    { return "galaxy:waf:retiring:$orgId:$clusterId:$policyId"; }

    public function register(Cluster $cluster, array $service): void
    {
        $labels = $service['Spec']['Labels'] ?? [];
        $policyId = (int) ($labels['com.codegalaxy.waf.policy'] ?? 0);
        if (! $this->owned($labels, $cluster, $policyId)) throw new RuntimeException('拒绝回收不属于当前作用域的 WAF 实例');
        $key = $this->key($cluster->org_id, $cluster->id, $policyId);
        // HSETNX preserves the original retirement time across gateway syncs.
        $this->redis->hSetNx($key, $service['ID'], json_encode(['service_id' => $service['ID'],
            'service_name' => $service['Spec']['Name'], 'retired_at' => time(),
            'mode' => $this->mode($service), 'configs' => array_column($service['Spec']['TaskTemplate']['ContainerSpec']['Configs'] ?? [], 'ConfigID')], JSON_THROW_ON_ERROR));
    }

    private function mode(array $service): string
    {
        foreach ($service['Spec']['TaskTemplate']['ContainerSpec']['Env'] ?? [] as $value) {
            if ($value === 'MODSEC_RULE_ENGINE=DetectionOnly') return 'monitor';
        }
        return 'block';
    }

    public function pending(WafPolicy $policy): array
    {
        return array_values(array_filter(array_map(static fn ($value) => json_decode($value, true),
            $this->redis->hGetAll($this->key($policy->org_id, $policy->cluster_id, $policy->id)) ?: []), 'is_array'));
    }

    public function cancel(Cluster $cluster, int $policyId, string $serviceId): void
    { $this->redis->hDel($this->key($cluster->org_id, $cluster->id, $policyId), $serviceId); }

    public function remove(Cluster $cluster, WafPolicy $policy, array $generation): void
    {
        $this->lock->synchronized((int) $cluster->org_id, (int) $cluster->id,
            fn () => $this->removeUnlocked($cluster, $policy, $generation), 0, 60);
    }

    private function removeUnlocked(Cluster $cluster, WafPolicy $policy, array $generation): void
    {
        // A rollback may have reactivated the same immutable generation while logs were being read.
        $fresh = WafPolicy::where('org_id', $cluster->org_id)->where('cluster_id', $cluster->id)->where('id', $policy->id)->first();
        if ($fresh !== null && $fresh->service_id === $generation['service_id']) {
            $this->cancel($cluster, $policy->id, $generation['service_id']);
            return;
        }
        $this->docker->withCluster($cluster, function (Client $client, SwarmApiClient $api) use ($cluster, $policy, $generation): void {
            $filters = ['label' => ['com.codegalaxy.component=waf', 'com.codegalaxy.org.id=' . $cluster->org_id,
                'com.codegalaxy.cluster.id=' . $cluster->id, 'com.codegalaxy.waf.policy=' . $policy->id]];
            $services = $api->request($client, 'GET', '/services', ['query' => ['filters' => json_encode($filters)]]);
            foreach ($services as $service) {
                if (($service['ID'] ?? '') !== $generation['service_id']) continue;
                if (! $this->owned($service['Spec']['Labels'] ?? [], $cluster, $policy->id)) throw new RuntimeException('WAF 回收实例归属不符');
                $api->request($client, 'DELETE', '/services/' . rawurlencode($service['ID']));
            }
            $configs = $api->request($client, 'GET', '/configs', ['query' => ['filters' => json_encode($filters)]]);
            foreach ($configs as $item) {
                if (! in_array($item['ID'] ?? '', $generation['configs'], true)) continue;
                if (! $this->owned($item['Spec']['Labels'] ?? [], $cluster, $policy->id)) throw new RuntimeException('WAF Config 回收归属不符');
                // In-use Config errors leave the registry intact for the next collector tick.
                $api->request($client, 'DELETE', '/configs/' . rawurlencode($item['ID']));
            }
        }, 5);
        $this->cancel($cluster, $policy->id, $generation['service_id']);
    }

    private function owned(array $labels, Cluster $cluster, int $policyId): bool
    {
        return $policyId > 0 && ($labels['com.codegalaxy.component'] ?? '') === 'waf'
            && (int) ($labels['com.codegalaxy.org.id'] ?? 0) === (int) $cluster->org_id
            && (int) ($labels['com.codegalaxy.cluster.id'] ?? 0) === (int) $cluster->id
            && (int) ($labels['com.codegalaxy.waf.policy'] ?? 0) === $policyId;
    }
}

<?php

namespace App\Services\Gateway;

use App\Exception\AppException;
use App\Model\Cluster;
use App\Model\WafPolicy;
use App\Model\WafPolicyRevision;

final class WafHistoryService
{
    public function __construct(private WafOperationsState $operations, private WafPolicyConfig $config) {}

    public function latestId(WafPolicy $policy): int
    {
        if (! $policy->id || ! $this->operations->initialized()) return 0;
        return (int) (WafPolicyRevision::where('org_id', $policy->org_id)->where('cluster_id', $policy->cluster_id)
            ->where('policy_id', $policy->id)->max('id') ?: 0);
    }

    /** Called within the same transaction and gateway lock as the desired-policy update. */
    public function begin(WafPolicy $policy, array $settings, int $actorId, ?int $expectedId, int $rollbackOf = 0, bool $captureBaseline = true): ?WafPolicyRevision
    {
        if (! $this->operations->initialized()) {
            if ($expectedId !== null) $this->operations->requireInitialized();
            return null;
        }
        $latest = $this->latestId($policy);
        if ($expectedId !== null && $latest !== $expectedId) throw new AppException(409, '策略已被其他操作更新，请刷新后比较最新版本再保存');
        if ($captureBaseline && $latest === 0 && $policy->settings !== null) {
            $baseline = $this->snapshot($policy, $this->config->normalize((array) $policy->settings), 0, 0);
            $baseline->status = $policy->status === 'synced' ? 'applied' : 'baseline';
            $baseline->completed_at = time(); $baseline->save();
        }
        return $this->snapshot($policy, $settings, $actorId, $rollbackOf);
    }

    public function latestIds(Cluster $cluster, array $policyIds): array
    {
        if ($policyIds === [] || ! $this->operations->initialized()) return [];
        return WafPolicyRevision::where('org_id', $cluster->org_id)->where('cluster_id', $cluster->id)->whereIn('policy_id', $policyIds)
            ->selectRaw('policy_id, MAX(id) AS latest_id')->groupBy('policy_id')->pluck('latest_id', 'policy_id')->all();
    }

    private function snapshot(WafPolicy $policy, array $settings, int $actorId, int $rollbackOf): WafPolicyRevision
    {
        return WafPolicyRevision::create(['org_id' => $policy->org_id, 'cluster_id' => $policy->cluster_id,
            'policy_id' => $policy->id, 'settings' => $settings, 'settings_hash' => hash('sha256', json_encode($settings, JSON_THROW_ON_ERROR)),
            'actor_id' => $actorId, 'rollback_of' => $rollbackOf, 'status' => 'pending', 'error' => null,
            'created_at' => time(), 'completed_at' => 0]);
    }

    public function finish(?WafPolicyRevision $revision, bool $success, ?string $error = null): void
    {
        if ($revision === null) return;
        $revision->status = $success ? 'applied' : 'failed';
        $revision->error = $error === null ? null : mb_substr($error, 0, 2000);
        $revision->completed_at = time(); $revision->save();
        $older = WafPolicyRevision::where('policy_id', $revision->policy_id)->orderByDesc('id')->offset(100)->limit(200)->pluck('id')->all();
        if ($older !== []) WafPolicyRevision::whereIn('id', $older)->where('policy_id', $revision->policy_id)->delete();
    }

    public function list(Cluster $cluster, string $source, int $routeId, ?array $projectRouteIds): array
    {
        $this->operations->requireInitialized();
        $policy = WafPolicy::forRoutes($cluster, $projectRouteIds)->where('route_source', $source)->where('route_id', $routeId)->first();
        if ($policy === null) return ['revisions' => [], 'latest_id' => 0];
        $rows = WafPolicyRevision::where('org_id', $cluster->org_id)->where('cluster_id', $cluster->id)->where('policy_id', $policy->id)
            ->select(['id','actor_id','rollback_of','status','settings_hash','created_at','completed_at','error'])->orderByDesc('id')->limit(100)->get();
        return ['revisions' => $rows, 'latest_id' => $this->latestId($policy)];
    }

    public function detail(Cluster $cluster, int $revisionId, ?array $projectRouteIds): WafPolicyRevision
    {
        $this->operations->requireInitialized();
        $revision = WafPolicyRevision::where('org_id', $cluster->org_id)->where('cluster_id', $cluster->id)->where('id', $revisionId)
            ->whereIn('policy_id', WafPolicy::forRoutes($cluster, $projectRouteIds)->select('id'))->first();
        if ($revision === null) throw new AppException(404, '当前作用域中不存在该策略版本');
        return $revision;
    }
}

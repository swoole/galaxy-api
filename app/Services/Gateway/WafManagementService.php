<?php

namespace App\Services\Gateway;

use App\Exception\AppException;
use App\Model\Cluster;
use App\Model\ClusterWebGateway;
use App\Model\GatewayVhost;
use App\Model\ProjectRoute;
use App\Model\WafPolicy;
use App\Services\Project\ProjectRouteService;
use Throwable;

final class WafManagementService
{
    public function __construct(private WafPolicyConfig $config, private GatewayVhostService $gateway,
        private ProjectRouteService $projects, private WafEventService $events, private SwarmGatewayMutationLock $lock,
        private WafRuntimeService $runtime, private WafHistoryService $history, private WafOperationsState $operations,
        private WafRuntimeConfigRegistry $controls) {}

    public function profile(Cluster $cluster, ?array $projectRouteIds = null): array
    {
        if (! config('waf.enabled', false)) {
            return ['enabled' => false, 'gateway_installed' => false, 'routes' => [],
                'defaults' => $this->config->defaults(), 'presets' => $this->config->presets(), 'categories' => WafPolicyConfig::CATEGORIES, 'response_types' => WafResponseInspection::TYPES,
                'engine' => 'ModSecurity + OWASP CRS', 'max_policies' => (int) config('waf.max_policies', 20),
                'operations' => ['initialized' => false, 'note' => '请先启用 WAF 模块'], 'control' => ['available' => false, 'note' => '请先启用 WAF 模块']];
        }
        $policies = WafPolicy::forRoutes($cluster, $projectRouteIds)->get()->keyBy(
            fn ($p) => $p->route_source . ':' . $p->route_id
        );
        $operations = $this->operations->profile();
        $revisionIds = $this->history->latestIds($cluster, $policies->pluck('id')->all());
        try { $runtime = $this->runtime->status($cluster, $policies->pluck('service_id')->filter()->unique()->values()->all()); }
        catch (Throwable $e) { $runtime = []; $runtimeError = mb_substr($e->getMessage(), 0, 512); }
        $routes = [];
        foreach (['gateway' => GatewayVhost::class, 'project' => ProjectRoute::class] as $source => $class) {
            if ($projectRouteIds !== null && $source !== 'project') continue;
            $query = $class::where('org_id', $cluster->org_id)->where('cluster_id', $cluster->id);
            if ($projectRouteIds !== null) $query->whereIn('id', $projectRouteIds);
            foreach ($query->orderBy('id')->get() as $route) {
                $policy = $policies->get($source . ':' . $route->id);
                $routes[] = ['id' => $route->id, 'source' => $source, 'hostname' => $route->hostname,
                    'path' => $route->path_prefix, 'enabled' => (bool) $route->enabled,
                    'policy' => $policy, 'latest_revision_id' => $policy ? (int) ($revisionIds[$policy->id] ?? 0) : 0,
                    'settings' => $policy ? $this->config->normalize((array) $policy->settings) : $this->config->defaults(),
                    'runtime' => $policy ? ($runtime[$policy->service_id] ?? ['running' => 0, 'error' => $runtimeError ?? '']) : null,
                    'collection' => $policy ? $this->events->collectionStatus($policy->id) : []];
            }
        }
        $gateway = ClusterWebGateway::where('org_id', $cluster->org_id)->where('cluster_id', $cluster->id)->first();
        return ['enabled' => true, 'gateway_installed' => $gateway !== null && $gateway->service_id !== '',
            'routes' => $routes, 'defaults' => $this->config->defaults(), 'presets' => $this->config->presets(), 'categories' => WafPolicyConfig::CATEGORIES, 'response_types' => WafResponseInspection::TYPES,
            'engine' => 'ModSecurity + OWASP CRS', 'max_policies' => (int) config('waf.max_policies', 20), 'operations' => $operations, 'control' => $this->controls->profile()];
    }

    public function save(Cluster $cluster, string $source, int $id, array $settings, ?array $projectRouteIds = null,
        int $actorId = 0, ?int $baseRevisionId = null, int $rollbackOf = 0): array
    {
        $this->assertRouteScope($source, $id, $projectRouteIds);
        if (! config('waf.enabled', false)) throw new AppException(409, 'WAF 模块尚未初始化启用');
        if (! in_array($source, ['gateway', 'project'], true)) throw new AppException(422, '未知路由来源');
        return $this->lock->synchronized((int) $cluster->org_id, (int) $cluster->id,
            fn () => $this->saveUnlocked($cluster, $source, $id, $settings, $actorId, $baseRevisionId, $rollbackOf), 30, 900, 'waf-policy');
    }

    public function history(Cluster $cluster, string $source, int $id, ?array $projectRouteIds): array
    {
        $this->assertRouteScope($source, $id, $projectRouteIds);
        return $this->history->list($cluster, $source, $id, $projectRouteIds);
    }

    public function revision(Cluster $cluster, int $id, ?array $projectRouteIds): array
    {
        return ['revision' => $this->history->detail($cluster, $id, $projectRouteIds)];
    }

    public function restore(Cluster $cluster, int $id, ?array $projectRouteIds, int $actorId, int $baseRevisionId): array
    {
        $revision = $this->history->detail($cluster, $id, $projectRouteIds);
        if ($revision->status !== 'applied') throw new AppException(409, '只能恢复已成功部署的策略版本');
        $policy = WafPolicy::forRoutes($cluster, $projectRouteIds)->where('id', $revision->policy_id)->first();
        if ($policy === null) throw new AppException(404, '当前作用域中不存在该防护策略');
        return $this->save($cluster, $policy->route_source, $policy->route_id, (array) $revision->settings,
            $projectRouteIds, $actorId, $baseRevisionId, $revision->id);
    }

    public function assertRouteScope(string $source, int $id, ?array $projectRouteIds): void
    {
        if ($projectRouteIds !== null && ($source !== 'project' || ! in_array($id, $projectRouteIds, true))) {
            throw new AppException(404, '当前项目中不存在该防护路由');
        }
    }

    public function validateSettings(array $settings): array
    {
        return $this->config->normalize($settings);
    }

    private function saveUnlocked(Cluster $cluster, string $source, int $id, array $settings, int $actorId, ?int $baseRevisionId, int $rollbackOf): array
    {
        $class = $source === 'gateway' ? GatewayVhost::class : ProjectRoute::class;
        $route = $class::where('org_id', $cluster->org_id)->where('cluster_id', $cluster->id)->where('id', $id)->first();
        if ($route === null) throw new AppException(404, '当前集群中不存在该路由');
        if (! $route->enabled) throw new AppException(409, '请先启用网关路由，再配置防护');
        $settings = $this->config->normalize($settings);
        $policy = null;
        $revision = null;
        $mutated = false;
        try {
            $existing = WafPolicy::where('org_id', $cluster->org_id)->where('cluster_id', $cluster->id)
                ->where('route_source', $source)->where('route_id', $id)->first();
            $current = $existing ? $this->config->normalize((array) $existing->settings) : [];
            $metadataOnly = $existing !== null && $existing->status === 'synced'
                && $current['alerts'] !== $settings['alerts']
                && ($current['mode'] === 'off' || ($existing->service_id !== '' && $existing->applied_mode === $current['mode']))
                && array_diff_key($current, ['alerts' => true]) === array_diff_key($settings, ['alerts' => true]);
            $mutation = function () use ($cluster, $source, $id, $settings, $actorId, $baseRevisionId, $rollbackOf, &$policy, &$revision, &$mutated): void {
                $policy = WafPolicy::firstOrNew(['org_id' => $cluster->org_id, 'cluster_id' => $cluster->id,
                    'route_source' => $source, 'route_id' => $id]);
                $policy->getConnection()->transaction(function () use ($policy, $settings, $actorId, $baseRevisionId, $rollbackOf, &$revision): void {
                    $existing = $policy->exists;
                    if (! $existing) {
                        $policy->settings = $settings; $policy->created_at = time(); $policy->status = 'pending'; $policy->save();
                    }
                    $revision = $this->history->begin($policy, $settings, $actorId, $baseRevisionId, $rollbackOf, $existing);
                    $policy->settings = $settings; $policy->status = 'pending'; $policy->error = null;
                    $policy->updated_at = time(); $policy->save();
                });
                $mutated = true;
            };
            // Observer-only settings do not change the engine or interrupt gateway connections.
            if ($metadataOnly) $mutation();
            else $this->gateway->deploy((int) $cluster->org_id, (int) $cluster->id, $mutation);
            if ($source === 'project' && ! $metadataOnly) {
                if ($route->runtime === null) throw new AppException(409, '项目运行实例不存在');
                $this->projects->syncRuntime($route->runtime);
            }
            $policy->refresh();
            $policy->status = 'synced';
            $policy->error = null;
            $policy->synced_at = time();
            if ($settings['mode'] === 'off') {
                $policy->service_id = '';
                $policy->service_name = '';
                $policy->applied_mode = 'off';
            }
            $policy->save();
            $this->history->finish($revision, true);
        } catch (Throwable $e) {
            if ($mutated && $policy !== null && $policy->id) {
                WafPolicy::where('id', $policy->id)->update(['status' => 'error', 'error' => mb_substr($e->getMessage(), 0, 2000)]);
                $this->history->finish($revision, false, $e->getMessage());
            }
            throw $e;
        }
        return ['policy' => $policy, 'revision_id' => $revision?->id ?? 0];
    }
}

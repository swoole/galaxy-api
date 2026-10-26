<?php

namespace App\Services\Gateway;

use App\Exception\AppException;
use App\Model\Cluster;
use App\Model\GroupResourceGrant;
use App\Model\OrgMember;
use App\Model\ProjectMember;
use App\Model\ProjectRoute;
use App\Model\WafAlert;
use App\Model\WafEvent;
use App\Model\WafPolicy;
use App\Services\Notify;
use Throwable;

final class WafAlertService
{
    public function __construct(private WafOperationsState $operations, private WafPolicyConfig $config,
        private Notify $notifications, private WafRuntimeService $runtime) {}

    public function list(Cluster $cluster, ?array $projectRouteIds): array
    {
        if (! $this->operations->initialized()) return ['alerts' => []];
        return ['alerts' => $this->query($cluster, $projectRouteIds)->orderByDesc('last_seen_at')->limit(100)->get()];
    }

    public function acknowledge(Cluster $cluster, int $id, ?array $projectRouteIds, int $actorId): void
    {
        $this->operations->requireInitialized();
        $alert = $this->query($cluster, $projectRouteIds)->where('id', $id)->first();
        if ($alert === null) throw new AppException(404, '当前作用域中不存在该告警');
        if ($alert->status !== 'open') throw new AppException(409, '告警已恢复');
        $alert->acknowledged_at = time(); $alert->acknowledged_by = $actorId; $alert->save();
    }

    private function query(Cluster $cluster, ?array $projectRouteIds): \Hyperf\Database\Model\Builder
    {
        return WafAlert::where('org_id', $cluster->org_id)->where('cluster_id', $cluster->id)
            ->whereIn('policy_id', WafPolicy::forRoutes($cluster, $projectRouteIds)->select('id'));
    }

    /** Caller holds a per-policy observer lock, so occurrences are not inflated by API replicas. */
    public function evaluate(WafPolicy $policy, Cluster $cluster, array $collection): void
    {
        if (! $this->operations->initialized()) return;
        $settings = $this->config->normalize((array) $policy->settings);
        $rule = $settings['alerts'];
        if (! $rule['enabled'] || $policy->service_id === '' || $policy->applied_mode === 'off') {
            WafAlert::where('policy_id', $policy->id)->where('org_id', $policy->org_id)->where('status', 'open')
                ->update(['status' => 'resolved', 'resolved_at' => time()]);
            return;
        }
        $count = WafEvent::where('org_id', $policy->org_id)->where('cluster_id', $policy->cluster_id)->where('policy_id', $policy->id)
            ->where('occurred_at', '>=', time() - $rule['window_seconds'])->whereIn('action', $rule['actions'])->count();
        $this->condition($policy, 'attack_burst', $count >= $rule['attack_threshold'], $rule,
            'WAF 安全事件突增', sprintf('%d 秒内采集 %d 条安全事件，阈值 %d', $rule['window_seconds'], $count, $rule['attack_threshold']),
            ['count' => $count, 'window_seconds' => $rule['window_seconds']], 1);
        $collectionBad = ! empty($collection['error']) || ($collection['backlog_seconds'] ?? 0) > 120 || ! empty($collection['gap']);
        $this->condition($policy, 'collection', $collectionBad, $rule, 'WAF 日志采集异常',
            (string) (($collection['error'] ?? '') ?: ('积压 ' . ($collection['backlog_seconds'] ?? 0) . ' 秒')),
            array_intersect_key($collection, array_flip(['at','error','backlog_seconds','gap'])), $rule['failure_threshold']);
        $control = $collection['control'] ?? [];
        if (array_key_exists('enabled', $control) && (! $control['enabled'] || ($control['status'] ?? 0) > 0)) {
            $bad = $control['enabled'] && (! in_array($control['status'], [204,401,403], true) || empty($control['protocol']));
            $this->condition($policy, 'control', $bad, $rule, 'WAF 动态防护检查异常',
                ! $control['enabled'] ? '当前实例未启用动态检查' : ($bad ? '最近请求的检查接口返回 HTTP ' . $control['status'] . (in_array($control['status'], [204,401,403], true) && empty($control['protocol']) ? '，协议校验失败' : '') : '最近请求的动态检查已恢复'),
                $control, $rule['failure_threshold']);
        }
        if (array_key_exists('enabled', $control) && (! $control['enabled'] || in_array($control['data_status'] ?? '', ['ok','degraded'], true))) {
            $degraded = $control['enabled'] && ($control['data_status'] ?? '') === 'degraded';
            $this->condition($policy, 'data', $degraded, $rule, 'WAF 数据源不可用',
                $degraded ? '最近请求按策略跳过了不可用的地域或情报数据，请检查共享数据源' : '最近请求的数据检查已恢复', $control, $rule['failure_threshold']);
        }
        try {
            $status = $this->runtime->status($cluster, [$policy->service_id]);
            $healthy = ($status[$policy->service_id]['running'] ?? 0) > 0;
            $message = $status[$policy->service_id]['error'] ?? '';
        } catch (Throwable $e) { $healthy = false; $message = mb_substr($e->getMessage(), 0, 1000); }
        $this->condition($policy, 'detector', ! $healthy, $rule, 'WAF 检测实例异常',
            $healthy ? '检测实例已恢复运行' : ($message ?: '未检测到运行中的防护实例'), ['service_id' => $policy->service_id], $rule['failure_threshold']);
    }

    public function condition(WafPolicy $policy, string $kind, bool $unhealthy, array $rule, string $title, string $message, array $context, int $threshold): void
    {
        $alert = WafAlert::where('org_id', $policy->org_id)->where('cluster_id', $policy->cluster_id)->where('policy_id', $policy->id)->where('kind', $kind)->first();
        if (! $unhealthy) {
            if ($alert === null || $alert->status !== 'open') return;
            $alert->status = 'resolved'; $alert->resolved_at = time(); $alert->last_seen_at = time(); $alert->message = $message; $alert->context = $context; $alert->save();
            if ($rule['notify_recovery'] && $alert->notified_at > 0 && ! $alert->notification_error) $this->deliver($policy, $alert, $rule, true);
            return;
        }
        if ($alert === null || $alert->status === 'resolved') {
            $alert = WafAlert::updateOrCreate(['policy_id' => $policy->id, 'kind' => $kind], [
                'org_id' => $policy->org_id, 'cluster_id' => $policy->cluster_id, 'status' => 'open',
                'severity' => in_array($kind, ['detector','control'], true) ? 'critical' : 'warning', 'title' => $title, 'message' => $message,
                'context' => $context, 'occurrences' => 1, 'first_seen_at' => time(), 'last_seen_at' => time(),
                'resolved_at' => 0, 'notified_at' => 0, 'notification_error' => '', 'acknowledged_at' => 0, 'acknowledged_by' => 0]);
        } else {
            $alert->occurrences++; $alert->last_seen_at = time(); $alert->message = $message; $alert->context = $context; $alert->save();
        }
        if (! $alert->acknowledged_at && $alert->occurrences >= $threshold
            && (! $alert->notified_at || time() - $alert->notified_at >= $rule['cooldown_seconds'])) $this->deliver($policy, $alert, $rule, false);
    }

    private function deliver(WafPolicy $policy, WafAlert $alert, array $rule, bool $recovery): void
    {
        try {
            $uids = $this->recipients($policy);
            if ($uids === []) throw new \RuntimeException('当前作用域没有可用告警接收成员');
            $text = ($recovery ? '已恢复：' : '') . $alert->title;
            $outcomes = $this->notifications->sendTracked($uids, 'waf_alert', [
                'title' => $text, 'message' => $alert->message, 'policy_id' => $policy->id, 'cluster_id' => $policy->cluster_id,
            ], $rule['channels']);
            $failures = array_filter($outcomes, static fn ($value) => ! $value['sent']);
            $alert->notification_error = $failures === [] ? '' : mb_substr(json_encode($failures, JSON_UNESCAPED_UNICODE), 0, 2000);
        } catch (Throwable $e) { $alert->notification_error = mb_substr($e->getMessage(), 0, 2000); }
        // Failed delivery attempts also obey cooldown; do not retry every collector tick.
        $alert->notified_at = time(); $alert->save();
    }

    private function recipients(WafPolicy $policy): array
    {
        if ($policy->route_source === 'project') {
            $route = ProjectRoute::where('id', $policy->route_id)->where('org_id', $policy->org_id)->where('cluster_id', $policy->cluster_id)->first();
            if ($route === null || ! GroupResourceGrant::canUseCluster($policy->org_id, $route->group_id, $policy->cluster_id)) return [];
            return ProjectMember::where('org_id', $policy->org_id)->where('group_id', $route->group_id)->where('project_id', $route->project_id)->pluck('uid')->unique()->values()->all();
        }
        return OrgMember::where('org_id', $policy->org_id)->where('role', OrgMember::ROLE_MANAGER)->pluck('uid')->unique()->values()->all();
    }
}

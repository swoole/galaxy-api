<?php

namespace App\Services\Project;

use App\Exception\AppException;
use App\Model\ProjectRoute;
use App\Model\ProjectAlertRule;
use App\Model\ProjectRuntime;
use App\Model\Cluster;
use App\Model\ClusterPrometheus;
use App\Model\GatewayVhost;
use App\Services\Docker\SwarmPrometheusService;
use Hyperf\Collection\Collection;
use Hyperf\Redis\Redis;
use Throwable;

class ProjectHttpMonitoringService
{
    public function __construct(
        private SwarmPrometheusService $prometheus,
        private Redis $redis
    ) {}

    public function report(
        int $orgId,
        int $groupId,
        int $projectId,
        int $hours,
        ?int $routeId = null,
        ?string $routeSource = null,
        ?int $runtimeId = null,
        bool $summaryOnly = false
    ): array
    {
        $hours = max(1, min(168, $hours));
        if ($runtimeId !== null && ! ProjectRuntime::where('id', $runtimeId)
            ->where('org_id', $orgId)
            ->where('group_id', $groupId)
            ->where('project_id', $projectId)
            ->exists()) {
            throw new AppException(404, '所选运行实例不存在或不属于当前项目');
        }
        $allRoutes = $this->routes($orgId, $groupId, $projectId);
        $routes = $runtimeId === null ? $allRoutes : $allRoutes->filter(
            static fn (array $route): bool => (int) ($route['runtime_id'] ?? 0) === $runtimeId
        )->values();
        $routeSource = $routeId === null ? null : ($routeSource ?? 'project');
        $routes = $routeId === null ? $routes : $routes->filter(
            static fn (array $route): bool => (int) $route['id'] === $routeId
                && (string) $route['route_source'] === $routeSource
        )->values();
        if ($routes->isEmpty()) {
            if ($routeId === null) {
                return $this->emptyReport(
                    $runtimeId === null ? '项目尚未配置可监控的 Web 路由' : '所选实例尚未配置 Web 路由',
                    $allRoutes->all(),
                    $runtimeId
                );
            }
            throw new AppException(404, '所选 Web 路由不存在、未启用或不属于当前项目');
        }
        $reports = [];
        foreach ($routes->groupBy('cluster_id') as $clusterId => $clusterRoutes) {
            $cluster = Cluster::where('id', (int) $clusterId)->where('org_id', $orgId)->first();
            if ($cluster !== null
                && (string) $cluster->orchestrator_type === Cluster::ORCHESTRATOR_KUBERNETES) {
                $reports[] = $this->emptyCluster(
                    (int) $clusterId,
                    $cluster->title,
                    'Kubernetes Ingress 指标 Provider 尚未安装'
                );
                continue;
            }
            $installed = ClusterPrometheus::where('cluster_id', (int) $clusterId)
                ->where('org_id', $orgId)->where('service_id', '<>', '')->exists();
            if ($cluster === null || ! $installed) {
                $reports[] = $this->emptyCluster((int) $clusterId, $cluster?->title,
                    '集群尚未部署内置 Prometheus');
                continue;
            }
            try {
                $reports[] = $this->clusterReport($cluster, $clusterRoutes->all(), $hours, $summaryOnly);
            } catch (Throwable $e) {
                $reports[] = $this->emptyCluster((int) $clusterId, $cluster->title, $e->getMessage());
            }
        }
        $rule = ProjectAlertRule::where('org_id', $orgId)->where('group_id', $groupId)
            ->where('project_id', $projectId)->first();
        $objectives = [
            'availability_target' => (float) ($rule?->slo_availability_target ?? 99.9),
            'error_rate_max' => (float) ($rule?->slo_error_rate_max ?? 1.0),
            'p95_ms_max' => (int) ($rule?->slo_p95_ms_max ?? 500),
        ];
        if ($summaryOnly) {
            return $this->aggregateSummaryOnly($reports, $objectives);
        }
        return $this->aggregate(
            $reports,
            $allRoutes->all(),
            $hours,
            $routeId,
            $routeSource,
            $objectives,
            $runtimeId
        );
    }

    private function clusterReport(Cluster $cluster, array $routes, int $hours, bool $summaryOnly): array
    {
        $routeHash = hash('sha256', implode(',', array_column($routes, 'key')));
        // 保留完整报告原有缓存键；概览可复用完整报告缓存，反向则不可复用精简缓存。
        $fullCacheKey = sprintf('cg:http-report:%d:%s:%d', $cluster->id, $routeHash, $hours);
        $cacheKey = $summaryOnly
            ? sprintf('cg:http-report:summary:%d:%s:%d', $cluster->id, $routeHash, $hours)
            : $fullCacheKey;
        try {
            $cacheKeys = $summaryOnly ? [$cacheKey, $fullCacheKey] : [$cacheKey];
            foreach ($cacheKeys as $candidateKey) {
                $cached = $this->redis->get($candidateKey);
                if (is_string($cached) && $cached !== '') {
                    $decoded = json_decode($cached, true);
                    if (is_array($decoded)) {
                        return $decoded + ['cache_hit' => true];
                    }
                }
            }
        } catch (Throwable) {
        }

        $selector = $this->routerSelector($routes);
        $range = $hours . 'h';
        $requests = "traefik_router_requests_total{{$selector}}";
        $duration = "traefik_router_request_duration_seconds";
        $end = time();
        $start = $end - $hours * 3600;
        $summaryQuery = implode(' or ', [
            $this->stat("sum(increase({$requests}[{$range}]))", 'requests'),
            $this->stat("sum(increase(traefik_router_requests_total{{$selector},code=~\"4..\"}[{$range}]))", 'client_errors'),
            $this->stat("sum(increase(traefik_router_requests_total{{$selector},code=~\"5..\"}[{$range}]))", 'server_errors'),
            $this->stat("sum(increase(traefik_router_requests_bytes_total{{$selector}}[{$range}]))", 'request_bytes'),
            $this->stat("sum(increase(traefik_router_responses_bytes_total{{$selector}}[{$range}]))", 'response_bytes'),
            $this->stat("sum(increase({$duration}_sum{{$selector}}[{$range}])) / clamp_min(sum(increase({$duration}_count{{$selector}}[{$range}])), 1)", 'avg_seconds'),
            $this->stat("histogram_quantile(0.95, sum by (le) (increase({$duration}_bucket{{$selector}}[{$range}])))", 'p95_seconds'),
            $this->stat("histogram_quantile(0.99, sum by (le) (increase({$duration}_bucket{{$selector}}[{$range}])))", 'p99_seconds'),
            $this->stat("sum(rate({$requests}[5m]))", 'rps'),
        ]);
        $summaryData = $this->prometheus->query($cluster, $summaryQuery);
        $summary = $this->instantStats($summaryData);
        $summary['error_rate'] = ($summary['requests'] ?? 0) > 0
            ? round(($summary['server_errors'] ?? 0) * 100 / $summary['requests'], 3) : 0.0;
        $summary['success_rate'] = ($summary['requests'] ?? 0) > 0
            ? round(max(0, 100 - $summary['error_rate']), 3) : 0.0;
        if ($summaryOnly) {
            $result = [
                'cluster_id' => (int) $cluster->id,
                'cluster_title' => (string) $cluster->title,
                'available' => true,
                'error' => null,
                'summary' => $summary,
                'cache_hit' => false,
            ];
            try {
                $this->redis->setex($cacheKey, 15, json_encode($result, JSON_THROW_ON_ERROR));
            } catch (Throwable) {
            }
            return $result;
        }
        $previousSummary = $this->instantStats($this->prometheus->query(
            $cluster, $summaryQuery, null, null, null, $start
        ));
        $previousSummary['error_rate'] = ($previousSummary['requests'] ?? 0) > 0
            ? round(($previousSummary['server_errors'] ?? 0)
                * 100 / $previousSummary['requests'], 3) : 0.0;
        $previousSummary['success_rate'] = ($previousSummary['requests'] ?? 0) > 0
            ? round(max(0, 100 - $previousSummary['error_rate']), 3) : 0.0;

        $step = $hours <= 6 ? 60 : ($hours <= 24 ? 300 : 900);
        $window = $step <= 60 ? '5m' : ($step <= 300 ? '15m' : '30m');
        $trendQuery = implode(' or ', [
            $this->stat("sum(rate({$requests}[{$window}]))", 'rps'),
            $this->stat("100 * sum(rate(traefik_router_requests_total{{$selector},code=~\"5..\"}[{$window}])) / clamp_min(sum(rate({$requests}[{$window}])), 0.000001)", 'error_rate'),
            $this->stat("histogram_quantile(0.50, sum by (le) (rate({$duration}_bucket{{$selector}}[{$window}])))", 'p50_seconds'),
            $this->stat("histogram_quantile(0.95, sum by (le) (rate({$duration}_bucket{{$selector}}[{$window}])))", 'p95_seconds'),
            $this->stat("histogram_quantile(0.99, sum by (le) (rate({$duration}_bucket{{$selector}}[{$window}])))", 'p99_seconds'),
        ]);
        $trend = $this->matrix($this->prometheus->query($cluster, $trendQuery, $start, $end, $step));
        $statusTrend = $this->labelMatrix($this->prometheus->query($cluster,
            "sum by (code) (increase({$requests}[{$window}]))", $start, $end, $step), 'code');

        $status = $this->breakdown($this->prometheus->query($cluster,
            "sum by (code) (increase({$requests}[{$range}]))"), 'code');
        $method = $this->breakdown($this->prometheus->query($cluster,
            "sum by (method) (increase({$requests}[{$range}]))"), 'method');
        $protocol = $this->breakdown($this->prometheus->query($cluster,
            "sum by (protocol) (increase({$requests}[{$range}]))"), 'protocol');
        $result = ['cluster_id' => (int) $cluster->id, 'cluster_title' => (string) $cluster->title,
            'available' => true, 'error' => null, 'summary' => $summary, 'trend' => $trend,
            'previous_summary' => $previousSummary,
            'status_trend' => $statusTrend, 'status_codes' => $status, 'methods' => $method,
            'protocols' => $protocol,
            'cache_hit' => false];
        try {
            $this->redis->setex($cacheKey, 15, json_encode($result, JSON_THROW_ON_ERROR));
        } catch (Throwable) {
        }
        return $result;
    }

    /**
     * 概览卡片只需要当前汇总和 SLO，不查询或传输趋势、分组及上周期数据。
     */
    private function aggregateSummaryOnly(array $reports, array $objectives): array
    {
        $available = array_values(array_filter($reports, static fn (array $item): bool => $item['available']));
        $summary = $this->aggregateSummaries($available, 'summary');
        $errors = array_values(array_unique(array_filter(array_column($reports, 'error'))));
        $metricNote = $available === [] && $errors !== []
            ? implode('；', $errors)
            : '当前数据来自 Web 网关 Prometheus 汇总指标。';

        return [
            'available' => $available !== [],
            'partial' => count($available) !== count($reports),
            'summary' => array_map(static fn ($value) => round((float) $value, 4), $summary),
            'slo' => $this->slo($summary, $objectives),
            'collected_at' => time(),
            'metric_note' => $metricNote,
        ];
    }

    private function aggregate(
        array $reports,
        array $routes,
        int $hours,
        ?int $routeId,
        ?string $routeSource,
        array $objectives,
        ?int $runtimeId
    ): array
    {
        $available = array_values(array_filter($reports, static fn (array $item): bool => $item['available']));
        $step = $hours <= 6 ? 60 : ($hours <= 24 ? 300 : 900);
        $summary = $this->aggregateSummaries($available, 'summary');
        $previousSummary = $this->aggregateSummaries($available, 'previous_summary');
        $errors = array_values(array_unique(array_filter(array_column($reports, 'error'))));
        $metricNote = $available === [] && $errors !== []
            ? implode('；', $errors)
            : '当前数据来自 Swarm Traefik Router Prometheus 指标；Kubernetes Ingress 需安装对应 Controller 的指标 Provider 后接入。P95/P99 跨集群时采用最大值。';
        return ['available' => $available !== [], 'partial' => count($available) !== count($reports),
            'selected_runtime_id' => $runtimeId,
            'selected_route_id' => $routeId,
            'selected_route_source' => $routeSource,
            'hours' => $hours, 'summary' => array_map(static fn ($value) => round((float) $value, 4), $summary),
            'previous_summary' => array_map(static fn ($value) => round((float) $value, 4), $previousSummary),
            'comparison' => $this->comparison($summary, $previousSummary),
            'slo' => $this->slo($summary, $objectives),
            'trend' => $this->aggregateTrend($available, $step),
            'status_trend' => $this->aggregateStatusTrend($available, $step),
            'status_codes' => $this->aggregateBreakdown($available, 'status_codes'),
            'methods' => $this->aggregateBreakdown($available, 'methods'),
            'protocols' => $this->aggregateBreakdown($available, 'protocols'),
            'clusters' => $reports, 'routes' => array_map(static fn (array $route): array => [
                'id' => (int) $route['id'], 'cluster_id' => (int) $route['cluster_id'],
                'env_id' => (int) $route['env_id'], 'hostname' => (string) $route['hostname'],
                'path_prefix' => (string) $route['path_prefix'], 'key' => (string) $route['key'],
                'route_source' => (string) $route['route_source'],
                'runtime_id' => (int) ($route['runtime_id'] ?? 0),
                'orchestrator_type' => (string) ($route['orchestrator_type'] ?? ''),
                'metrics_available' => (bool) ($route['metrics_available'] ?? false),
            ], $routes), 'collected_at' => time(),
            'metric_note' => $metricNote];
    }

    private function aggregateSummaries(array $reports, string $field): array
    {
        $summary = ['requests' => 0.0, 'client_errors' => 0.0, 'server_errors' => 0.0,
            'request_bytes' => 0.0, 'response_bytes' => 0.0, 'avg_seconds' => 0.0,
            'p95_seconds' => 0.0, 'p99_seconds' => 0.0, 'rps' => 0.0,
            'error_rate' => 0.0, 'success_rate' => 0.0];
        $weightedDuration = 0.0;
        foreach ($reports as $report) {
            $row = (array) ($report[$field] ?? []);
            foreach (['requests', 'client_errors', 'server_errors', 'request_bytes', 'response_bytes', 'rps'] as $key) {
                $summary[$key] += (float) ($row[$key] ?? 0);
            }
            $weightedDuration += (float) ($row['avg_seconds'] ?? 0) * (float) ($row['requests'] ?? 0);
            $summary['p95_seconds'] = max($summary['p95_seconds'], (float) ($row['p95_seconds'] ?? 0));
            $summary['p99_seconds'] = max($summary['p99_seconds'], (float) ($row['p99_seconds'] ?? 0));
        }
        if ($summary['requests'] > 0) {
            $summary['avg_seconds'] = $weightedDuration / $summary['requests'];
            $summary['error_rate'] = $summary['server_errors'] * 100 / $summary['requests'];
            $summary['success_rate'] = max(0, 100 - $summary['error_rate']);
        }
        return $summary;
    }

    private function comparison(array $current, array $previous): array
    {
        $changes = [];
        foreach (['requests', 'request_bytes', 'response_bytes', 'avg_seconds', 'p95_seconds', 'p99_seconds'] as $key) {
            $old = (float) ($previous[$key] ?? 0);
            $changes[$key . '_change_percent'] = $old > 0
                ? round(((float) ($current[$key] ?? 0) - $old) * 100 / $old, 3) : null;
        }
        $changes['error_rate_change_points'] = round(
            (float) ($current['error_rate'] ?? 0) - (float) ($previous['error_rate'] ?? 0), 3
        );
        $changes['success_rate_change_points'] = round(
            (float) ($current['success_rate'] ?? 0) - (float) ($previous['success_rate'] ?? 0), 3
        );
        return $changes;
    }

    private function slo(array $summary, array $objectives): array
    {
        $requests = (float) ($summary['requests'] ?? 0);
        $availability = $requests > 0
            ? max(0.0, 100 - (float) ($summary['server_errors'] ?? 0) * 100 / $requests) : null;
        $errorRate = $requests > 0 ? (float) ($summary['error_rate'] ?? 0) : null;
        $p95Ms = $requests > 0 ? (float) ($summary['p95_seconds'] ?? 0) * 1000 : null;
        $checks = [
            'availability' => $availability === null ? 'no_data'
                : ($availability >= $objectives['availability_target'] ? 'met' : 'breached'),
            'error_rate' => $errorRate === null ? 'no_data'
                : ($errorRate <= $objectives['error_rate_max'] ? 'met' : 'breached'),
            'p95' => $p95Ms === null ? 'no_data'
                : ($p95Ms <= $objectives['p95_ms_max'] ? 'met' : 'breached'),
        ];
        return [
            'status' => in_array('breached', $checks, true) ? 'breached'
                : (in_array('no_data', $checks, true) ? 'no_data' : 'met'),
            'availability' => $availability === null ? null : round($availability, 4),
            'error_rate' => $errorRate === null ? null : round($errorRate, 4),
            'success_rate' => $errorRate === null ? null : round(max(0, 100 - $errorRate), 4),
            'p95_ms' => $p95Ms === null ? null : round($p95Ms, 3),
            'objectives' => $objectives + [
                'success_rate_target' => max(0, 100 - (float) $objectives['error_rate_max']),
            ],
            'checks' => $checks + ['success_rate' => $checks['error_rate']],
        ];
    }

    private function aggregateTrend(array $reports, int $step): array
    {
        $maps = [];
        $timestamps = [];
        foreach ($reports as $index => $report) {
            foreach (['rps', 'error_rate', 'p50_seconds', 'p95_seconds', 'p99_seconds'] as $stat) {
                $maps[$index][$stat] = $this->pointsByBucket((array) ($report['trend'][$stat] ?? []), $step);
                $timestamps += array_fill_keys(array_keys($maps[$index][$stat]), true);
            }
        }
        $timestamps = array_keys($timestamps);
        sort($timestamps, SORT_NUMERIC);
        $result = array_fill_keys(
            ['rps', 'error_rate', 'success_rate', 'p50_seconds', 'p95_seconds', 'p99_seconds'],
            []
        );
        foreach ($timestamps as $timestamp) {
            $rps = 0.0;
            $weightedError = 0.0;
            $latencies = ['p50_seconds' => 0.0, 'p95_seconds' => 0.0, 'p99_seconds' => 0.0];
            foreach ($maps as $map) {
                $clusterRps = (float) ($map['rps'][$timestamp] ?? 0);
                $rps += $clusterRps;
                $weightedError += $clusterRps * (float) ($map['error_rate'][$timestamp] ?? 0);
                foreach (array_keys($latencies) as $stat) {
                    $latencies[$stat] = max($latencies[$stat], (float) ($map[$stat][$timestamp] ?? 0));
                }
            }
            $result['rps'][] = ['timestamp' => $timestamp, 'value' => $rps];
            $result['error_rate'][] = ['timestamp' => $timestamp,
                'value' => $rps > 0 ? $weightedError / $rps : 0.0];
            $result['success_rate'][] = ['timestamp' => $timestamp,
                'value' => $rps > 0 ? max(0, 100 - $weightedError / $rps) : 0.0];
            foreach ($latencies as $stat => $value) {
                $result[$stat][] = ['timestamp' => $timestamp, 'value' => $value];
            }
        }
        return $result;
    }

    private function aggregateStatusTrend(array $reports, int $step): array
    {
        $totals = [];
        foreach ($reports as $report) {
            foreach ((array) ($report['status_trend'] ?? []) as $code => $points) {
                foreach ($this->pointsByBucket((array) $points, $step) as $timestamp => $value) {
                    $totals[(string) $code][$timestamp] = ($totals[(string) $code][$timestamp] ?? 0.0) + $value;
                }
            }
        }
        uksort($totals, static fn (string $a, string $b): int => strnatcasecmp($a, $b));
        foreach ($totals as $code => $points) {
            ksort($points, SORT_NUMERIC);
            $totals[$code] = array_map(static fn (int $timestamp, float $value): array => [
                'timestamp' => $timestamp, 'value' => $value,
            ], array_keys($points), array_values($points));
        }
        return $totals;
    }

    private function aggregateBreakdown(array $reports, string $key): array
    {
        $totals = [];
        foreach ($reports as $report) {
            foreach ((array) ($report[$key] ?? []) as $row) {
                $name = (string) ($row['key'] ?? 'unknown');
                $totals[$name] = ($totals[$name] ?? 0.0) + (float) ($row['value'] ?? 0);
            }
        }
        $rows = array_map(static fn (string $name, float $value): array => [
            'key' => $name, 'value' => round($value, 2),
        ], array_keys($totals), array_values($totals));
        usort($rows, static fn (array $a, array $b): int => $b['value'] <=> $a['value']);
        return $rows;
    }

    private function pointsByBucket(array $points, int $step): array
    {
        $result = [];
        foreach ($points as $point) {
            $timestamp = (int) ($point['timestamp'] ?? 0);
            if ($timestamp <= 0) {
                continue;
            }
            $bucket = intdiv($timestamp, $step) * $step;
            $value = (float) ($point['value'] ?? 0);
            $result[$bucket] = is_finite($value) ? $value : 0.0;
        }
        return $result;
    }

    private function routerSelector(array $routes): string
    {
        $names = array_column($routes, 'router_pattern');
        return 'router=~"' . implode('|', $names) . '"';
    }

    private function routes(int $orgId, int $groupId, int $projectId): Collection
    {
        $routes = new Collection();
        $projectRoutes = ProjectRoute::where('org_id', $orgId)->where('group_id', $groupId)
            ->where('project_id', $projectId)->where('enabled', 1)
            ->where('status', ProjectRoute::STATUS_SYNCED)
            ->with('cluster')
            ->orderBy('cluster_id')->orderBy('id')->get();
        foreach ($projectRoutes as $route) {
            $orchestratorType = (string) ($route->cluster?->orchestrator_type ?? '');
            $routes->push([
                'id' => (int) $route->id,
                'key' => 'project:' . (int) $route->id,
                'route_source' => 'project',
                'runtime_id' => (int) $route->runtime_id,
                'cluster_id' => (int) $route->cluster_id,
                'env_id' => (int) $route->env_id,
                'hostname' => (string) $route->hostname,
                'path_prefix' => (string) $route->path_prefix,
                'orchestrator_type' => $orchestratorType,
                'metrics_available' => $orchestratorType === Cluster::ORCHESTRATOR_DOCKER_SWARM,
                'router_pattern' => $orchestratorType === Cluster::ORCHESTRATOR_DOCKER_SWARM
                    ? 'cg-' . (int) $route->id . '(-http)?@swarm'
                    : '',
            ]);
        }

        $runtimes = ProjectRuntime::where('org_id', $orgId)->where('group_id', $groupId)
            ->where('project_id', $projectId)->where('name', '<>', '')
            ->where('orchestrator_type', Cluster::ORCHESTRATOR_DOCKER_SWARM)
            ->get(['id', 'org_id', 'project_id', 'cluster_id', 'env_id', 'name', 'service_name']);
        $runtimeByTarget = [];
        foreach ($runtimes as $runtime) {
            $runtimeByTarget[(int) $runtime->cluster_id . ':'
                . ProjectServiceIdentity::runtimeDockerName($runtime)] = $runtime;
        }
        if ($runtimeByTarget !== []) {
            $vhosts = GatewayVhost::where('org_id', $orgId)->where('enabled', 1)
                ->where('status', GatewayVhost::STATUS_SYNCED)
                ->whereIn('cluster_id', $runtimes->pluck('cluster_id')->unique()->all())
                ->orderBy('cluster_id')->orderBy('id')->get();
            foreach ($vhosts as $vhost) {
                $runtime = $runtimeByTarget[(int) $vhost->cluster_id . ':' . (string) $vhost->target_service] ?? null;
                if ($runtime === null) {
                    continue;
                }
                $routes->push([
                    'id' => (int) $vhost->id,
                    'key' => 'gateway:' . (int) $vhost->id,
                    'route_source' => 'gateway',
                    'runtime_id' => (int) $runtime->id,
                    'cluster_id' => (int) $vhost->cluster_id,
                    'env_id' => (int) $runtime->env_id,
                    'hostname' => (string) $vhost->hostname,
                    'path_prefix' => (string) $vhost->path_prefix,
                    'orchestrator_type' => Cluster::ORCHESTRATOR_DOCKER_SWARM,
                    'metrics_available' => true,
                    'router_pattern' => 'cg-vhost-' . (int) $vhost->id . '(-http)?@file',
                ]);
            }
        }

        return $routes->sortBy([
            ['cluster_id', 'asc'],
            ['route_source', 'asc'],
            ['id', 'asc'],
        ])->values();
    }

    private function stat(string $expression, string $name): string
    {
        return 'label_replace((' . $expression . '), "stat", "' . $name . '", "", ".*")';
    }

    private function instantStats(array $data): array
    {
        $stats = [];
        foreach ((array) ($data['result'] ?? []) as $row) {
            $value = (float) ($row['value'][1] ?? 0);
            $stats[(string) ($row['metric']['stat'] ?? '')] = is_finite($value) ? $value : 0.0;
        }
        return $stats;
    }

    private function matrix(array $data): array
    {
        $series = [];
        foreach ((array) ($data['result'] ?? []) as $row) {
            $name = (string) ($row['metric']['stat'] ?? 'unknown');
            $series[$name] = array_map(static fn (array $point): array => [
                'timestamp' => (int) $point[0], 'value' => is_numeric($point[1]) && is_finite((float) $point[1])
                    ? (float) $point[1] : null,
            ], (array) ($row['values'] ?? []));
        }
        return $series;
    }

    private function breakdown(array $data, string $label): array
    {
        $rows = [];
        foreach ((array) ($data['result'] ?? []) as $row) {
            $rows[] = ['key' => (string) ($row['metric'][$label] ?? 'unknown'),
                'value' => round((float) ($row['value'][1] ?? 0), 2)];
        }
        usort($rows, static fn (array $a, array $b): int => $b['value'] <=> $a['value']);
        return $rows;
    }

    private function labelMatrix(array $data, string $label): array
    {
        $series = [];
        foreach ((array) ($data['result'] ?? []) as $row) {
            $name = (string) ($row['metric'][$label] ?? 'unknown');
            $series[$name] = array_map(static fn (array $point): array => [
                'timestamp' => (int) $point[0],
                'value' => is_numeric($point[1]) && is_finite((float) $point[1])
                    ? max(0.0, (float) $point[1]) : 0.0,
            ], (array) ($row['values'] ?? []));
        }
        uksort($series, static fn (string $a, string $b): int => strnatcasecmp($a, $b));
        return $series;
    }

    private function emptyCluster(int $id, ?string $title, string $error): array
    {
        return ['cluster_id' => $id, 'cluster_title' => $title ?: '#' . $id, 'available' => false,
            'error' => $error, 'summary' => [], 'previous_summary' => [], 'trend' => [], 'status_trend' => [],
            'status_codes' => [], 'methods' => [], 'protocols' => []];
    }

    private function emptyReport(string $message, array $routes = [], ?int $runtimeId = null): array
    {
        return ['available' => false, 'partial' => false, 'hours' => 0, 'summary' => [], 'clusters' => [],
            'previous_summary' => [], 'comparison' => [], 'routes' => array_map(
                static fn (array $route): array => [
                    'id' => (int) $route['id'],
                    'cluster_id' => (int) $route['cluster_id'],
                    'env_id' => (int) $route['env_id'],
                    'hostname' => (string) $route['hostname'],
                    'path_prefix' => (string) $route['path_prefix'],
                    'key' => (string) $route['key'],
                    'route_source' => (string) $route['route_source'],
                    'runtime_id' => (int) ($route['runtime_id'] ?? 0),
                    'orchestrator_type' => (string) ($route['orchestrator_type'] ?? ''),
                    'metrics_available' => (bool) ($route['metrics_available'] ?? false),
                ],
                $routes
            ), 'selected_runtime_id' => $runtimeId, 'selected_route_id' => null,
            'selected_route_source' => null,
            'status_codes' => [], 'methods' => [], 'protocols' => [], 'trend' => [], 'status_trend' => [],
            'slo' => ['status' => 'no_data'], 'collected_at' => time(), 'metric_note' => $message];
    }
}

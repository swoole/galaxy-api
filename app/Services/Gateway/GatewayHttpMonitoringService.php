<?php

namespace App\Services\Gateway;

use App\Model\Cluster;
use App\Model\ClusterPrometheus;
use App\Model\GatewayVhost;
use App\Model\ProjectRoute;
use App\Services\Docker\SwarmPrometheusService;
use Throwable;

/**
 * Cluster-level Traefik request metrics.
 *
 * Routes are discovered from the gateway configuration itself. Project
 * ownership is optional metadata and never a prerequisite for monitoring.
 */
class GatewayHttpMonitoringService
{
    public function __construct(private SwarmPrometheusService $prometheus) {}

    public function report(Cluster $cluster, int $hours = 24): array
    {
        $hours = max(1, min(168, $hours));
        $routes = $this->routes($cluster);
        if ($routes === []) {
            return $this->emptyReport($hours, 'Web 网关尚未配置可监控的域名路由');
        }
        if (! ClusterPrometheus::where('org_id', (int) $cluster->org_id)
            ->where('cluster_id', (int) $cluster->id)->where('service_id', '<>', '')->exists()) {
            return $this->emptyReport($hours, '集群尚未部署内置 Prometheus', $routes);
        }

        try {
            $routerStats = $this->queryRouterStats($cluster, $routes, $hours);
        } catch (Throwable $e) {
            return $this->emptyReport($hours, $e->getMessage(), $routes);
        }

        $routeRows = [];
        foreach ($routes as $route) {
            $stats = $this->sumStats(array_map(
                static fn (string $router): array => $routerStats[$router] ?? [],
                $route['routers']
            ));
            $routeRows[] = $route + ['stats' => $this->completeStats($stats)];
        }

        $domains = [];
        foreach ($routeRows as $route) {
            $hostname = $route['hostname'];
            $domains[$hostname] ??= [
                'hostname' => $hostname,
                'route_count' => 0,
                'project_count' => 0,
                'gateway_count' => 0,
                'stats' => [],
            ];
            ++$domains[$hostname]['route_count'];
            ++$domains[$hostname][$route['route_source'] === 'project' ? 'project_count' : 'gateway_count'];
            $domains[$hostname]['stats'] = $this->sumStats([
                $domains[$hostname]['stats'],
                $route['stats'],
            ]);
        }
        foreach ($domains as &$domain) {
            $domain['stats'] = $this->completeStats($domain['stats']);
        }
        unset($domain);
        $domains = array_values($domains);
        usort($domains, static fn (array $left, array $right): int =>
            ($right['stats']['requests'] <=> $left['stats']['requests'])
            ?: strcmp($left['hostname'], $right['hostname']));

        return [
            'available' => true,
            'hours' => $hours,
            'summary' => $this->completeStats($this->sumStats(array_column($routeRows, 'stats'))),
            'domains' => $domains,
            'routes' => $routeRows,
            'metric_note' => '按 Traefik Router 指标汇总全部 Web 网关流量；成功率仅以 5xx 作为失败，非项目 VHost 同样纳入统计。',
            'collected_at' => time(),
        ];
    }

    private function routes(Cluster $cluster): array
    {
        $routes = [];
        foreach (GatewayVhost::where('org_id', (int) $cluster->org_id)
            ->where('cluster_id', (int) $cluster->id)->where('enabled', 1)
            ->where('status', GatewayVhost::STATUS_SYNCED)->orderBy('id')->get() as $vhost) {
            $id = (int) $vhost->id;
            $routes[] = [
                'id' => $id,
                'key' => 'gateway:' . $id,
                'route_source' => 'gateway',
                'hostname' => (string) $vhost->hostname,
                'path_prefix' => (string) $vhost->path_prefix,
                'target_service' => (string) $vhost->target_service,
                'project' => null,
                'routers' => ["cg-vhost-{$id}@file", "cg-vhost-{$id}-http@file"],
            ];
        }
        foreach (ProjectRoute::where('org_id', (int) $cluster->org_id)
            ->where('cluster_id', (int) $cluster->id)->where('enabled', 1)
            ->where('status', ProjectRoute::STATUS_SYNCED)->with('project')->with('runtime')
            ->orderBy('id')->get() as $route) {
            $id = (int) $route->id;
            $routes[] = [
                'id' => $id,
                'key' => 'project:' . $id,
                'route_source' => 'project',
                'hostname' => (string) $route->hostname,
                'path_prefix' => (string) $route->path_prefix,
                'target_service' => (string) ($route->runtime?->service_name ?? ''),
                'project' => $route->project === null ? null : [
                    'id' => (int) $route->project->id,
                    'title' => (string) $route->project->title,
                ],
                'routers' => ["cg-{$id}@swarm", "cg-{$id}-http@swarm"],
            ];
        }
        return $routes;
    }

    private function queryRouterStats(Cluster $cluster, array $routes, int $hours): array
    {
        $routers = array_values(array_unique(array_merge(...array_column($routes, 'routers'))));
        $pattern = implode('|', array_map(
            // Prometheus uses RE2 inside a quoted PromQL string. preg_quote()
            // also escapes "-" as "\-", which is not a valid PromQL string
            // escape sequence even though it is harmless in a PHP regex.
            static fn (string $router): string => preg_replace(
                '/([\\\\.^$|()\\[\\]{}*+?])/',
                '\\\\$1',
                $router
            ),
            $routers
        ));
        $selector = 'router=~"' . str_replace('"', '\\"', $pattern) . '"';
        $range = $hours . 'h';
        $requests = "traefik_router_requests_total{{$selector}}";
        $duration = "traefik_router_request_duration_seconds";
        $expressions = [
            'requests' => "sum by (router) (increase({$requests}[{$range}]))",
            'client_errors' => "sum by (router) (increase(traefik_router_requests_total{{$selector},code=~\"4..\"}[{$range}]))",
            'server_errors' => "sum by (router) (increase(traefik_router_requests_total{{$selector},code=~\"5..\"}[{$range}]))",
            'request_bytes' => "sum by (router) (increase(traefik_router_requests_bytes_total{{$selector}}[{$range}]))",
            'response_bytes' => "sum by (router) (increase(traefik_router_responses_bytes_total{{$selector}}[{$range}]))",
            'avg_seconds' => "sum by (router) (increase({$duration}_sum{{$selector}}[{$range}])) / clamp_min(sum by (router) (increase({$duration}_count{{$selector}}[{$range}])), 1)",
            'p95_seconds' => "histogram_quantile(0.95, sum by (le, router) (increase({$duration}_bucket{{$selector}}[{$range}])))",
            'rps' => "sum by (router) (rate({$requests}[5m]))",
        ];
        $query = implode(' or ', array_map(
            static fn (string $name, string $expression): string =>
                'label_replace((' . $expression . '), "stat", "' . $name . '", "", ".*")',
            array_keys($expressions),
            array_values($expressions)
        ));
        $data = $this->prometheus->query($cluster, $query);
        $result = [];
        foreach ((array) ($data['result'] ?? []) as $row) {
            $router = (string) ($row['metric']['router'] ?? '');
            $stat = (string) ($row['metric']['stat'] ?? '');
            $value = (float) ($row['value'][1] ?? 0);
            if ($router !== '' && $stat !== '') {
                $result[$router][$stat] = is_finite($value) ? max(0, $value) : 0.0;
            }
        }
        return $result;
    }

    private function sumStats(array $rows): array
    {
        $result = [];
        $duration = 0.0;
        foreach ($rows as $row) {
            $requests = (float) ($row['requests'] ?? 0);
            foreach (['requests', 'client_errors', 'server_errors', 'request_bytes', 'response_bytes', 'rps'] as $key) {
                $result[$key] = ($result[$key] ?? 0.0) + (float) ($row[$key] ?? 0);
            }
            $duration += (float) ($row['avg_seconds'] ?? 0) * $requests;
            $result['p95_seconds'] = max((float) ($result['p95_seconds'] ?? 0), (float) ($row['p95_seconds'] ?? 0));
        }
        $result['avg_seconds'] = ($result['requests'] ?? 0) > 0 ? $duration / $result['requests'] : 0.0;
        return $result;
    }

    private function completeStats(array $stats): array
    {
        $requests = (float) ($stats['requests'] ?? 0);
        // At gateway level only 5xx represents a server-side failure.
        // Redirects and client responses (3xx/4xx) are valid HTTP outcomes.
        $errors = (float) ($stats['server_errors'] ?? 0);
        $stats['success_rate'] = $requests > 0
            ? round(max(0, 100 - $errors * 100 / $requests), 3)
            : null;
        foreach (['requests', 'client_errors', 'server_errors', 'request_bytes', 'response_bytes'] as $key) {
            $stats[$key] = round((float) ($stats[$key] ?? 0));
        }
        foreach (['rps', 'avg_seconds', 'p95_seconds'] as $key) {
            $stats[$key] = round((float) ($stats[$key] ?? 0), 4);
        }
        return $stats;
    }

    private function emptyReport(int $hours, string $message, array $routes = []): array
    {
        return [
            'available' => false,
            'hours' => $hours,
            'summary' => [],
            'domains' => [],
            'routes' => array_map(fn (array $route): array => $route + [
                'stats' => $this->completeStats([]),
            ], $routes),
            'metric_note' => $message,
            'collected_at' => time(),
        ];
    }
}

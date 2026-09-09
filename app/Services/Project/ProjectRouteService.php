<?php

namespace App\Services\Project;

use App\Exception\AppException;
use App\Model\ProjectRoute;
use App\Model\ProjectRelease;
use App\Model\ProjectRuntime;
use App\Model\KubernetesClusterConnection;
use App\Model\Cluster;
use App\Model\ClusterWebGateway;
use App\Model\GatewayVhost;
use App\Model\GatewayVhostRewrite;
use App\Model\TlsCertificate;
use App\Services\Docker\SwarmApiClient;
use App\Services\ManagedDomainService;
use App\Services\Gateway\SwarmGatewayCertificateDeployer;
use App\Services\Gateway\GatewayVhostService;
use App\Services\Gateway\TlsCertificateService;
use App\Services\Project\Route\KubernetesProjectRouteService;
use GuzzleHttp\Client;
use Hyperf\DbConnection\Db;
use Throwable;

class ProjectRouteService
{
    public function __construct(
        private SwarmApiClient $docker,
        private TlsCertificateService $certificates,
        private SwarmGatewayCertificateDeployer $certificateDeployer,
        private ManagedSwarmResourceGuard $guard,
        private ProjectRuntimeLock $runtimeLock,
        private GatewayVhostService $gatewayVhosts,
        private KubernetesProjectRouteService $kubernetesRoutes,
        private ManagedDomainService $managedDomains
    ) {}

    public function list(
        int $orgId,
        int $groupId,
        int $projectId,
        int $page,
        int $pageSize,
        ?int $runtimeId = null
    ): array
    {
        $routeQuery = ProjectRoute::where('org_id', $orgId)
            ->where('group_id', $groupId)->where('project_id', $projectId);
        if ($runtimeId !== null) {
            $routeQuery->where('runtime_id', $runtimeId);
        }
        $rows = $routeQuery
            ->with('runtime')->with('env')->with('cluster')->with('certificate')->get()
            ->map(function (ProjectRoute $route): array {
                $row = $route->toArray();
                $row['route_source'] = 'project';
                return $row;
            })->all();
        $runtimeQuery = ProjectRuntime::where('org_id', $orgId)
            ->where('group_id', $groupId)->where('project_id', $projectId)->where('name', '<>', '');
        $runtimes = $runtimeQuery
            ->with('env')->with('cluster')->get();
        $targets = $runtimes->filter(static fn (ProjectRuntime $runtime): bool =>
            (int) $runtime->cluster_id > 0 && trim((string) $runtime->runtime_ref) !== ''
        )->map(static fn (ProjectRuntime $runtime): array => [
            'runtime_id' => (int) $runtime->id,
            'runtime_name' => (string) $runtime->name,
            'target_service' => ProjectServiceIdentity::runtimeDockerName($runtime),
            'orchestrator_type' => (string) $runtime->orchestrator_type,
            'cluster_id' => (int) $runtime->cluster_id,
            'cluster_title' => (string) ($runtime->cluster?->title ?? ('集群 #' . $runtime->cluster_id)),
            'env_id' => (int) $runtime->env_id,
            'env_title' => (string) ($runtime->env?->title ?? ('环境 #' . $runtime->env_id)),
        ])->values()->all();
        foreach ($runtimes->groupBy('cluster_id') as $clusterId => $clusterRuntimes) {
            $byService = $clusterRuntimes->keyBy(
                static fn (ProjectRuntime $runtime): string => ProjectServiceIdentity::runtimeDockerName($runtime)
            );
            $vhosts = GatewayVhost::where('org_id', $orgId)->where('cluster_id', (int) $clusterId)
                ->whereIn('target_service', $byService->keys()->all())->with('certificate')->get();
            foreach ($vhosts as $vhost) {
                /** @var ProjectRuntime|null $runtime */
                $runtime = $byService->get((string) $vhost->target_service);
                if ($runtime === null || ($runtimeId !== null && (int) $runtime->id !== $runtimeId)) {
                    continue;
                }
                $row = $vhost->toArray();
                $row += [
                    'runtime_id' => (int) $runtime->id, 'env_id' => (int) $runtime->env_id,
                    'project_id' => $projectId, 'group_id' => $groupId,
                    'runtime' => $runtime->toArray(), 'env' => $runtime->env?->toArray(),
                    'cluster' => $runtime->cluster?->toArray(), 'status' => 'synced', 'error' => null,
                ];
                $row['route_source'] = 'gateway';
                $rows[] = $row;
            }
        }
        usort($rows, static fn (array $a, array $b): int => ((int) ($b['created_at'] ?? 0)) <=> ((int) ($a['created_at'] ?? 0)));
        $total = count($rows);
        return ['data' => array_slice($rows, ($page - 1) * $pageSize, $pageSize),
            'targets' => $targets, 'total' => $total, 'page' => $page, 'pagesize' => $pageSize];
    }

    public function create(
        int $uid,
        int $orgId,
        int $groupId,
        int $projectId,
        int $runtimeId,
        array $input
    ): ProjectRoute {
        $runtime = $this->runtime($orgId, $groupId, $projectId, $runtimeId);
        $data = $this->normalize($runtime, $input + ['runtime_id' => $runtime->id]);
        if ((string) $runtime->orchestrator_type === Cluster::ORCHESTRATOR_DOCKER_SWARM) {
            $this->assertNetworkConsistency((int) $runtime->id, $data['network_id']);
        }
        $this->assertRouteAvailable((int) $runtime->cluster_id, $data);
        /** @var ProjectRoute $route */
        $route = ProjectRoute::create($data + [
            'org_id' => $orgId,
            'group_id' => $groupId,
            'project_id' => $projectId,
            'runtime_id' => (int) $runtime->id,
            'env_id' => (int) $runtime->env_id,
            'cluster_id' => (int) $runtime->cluster_id,
            'status' => ProjectRoute::STATUS_PENDING,
            'error' => null,
            'creator' => $uid,
            'created_at' => time(),
            'updated_at' => time(),
            'synced_at' => 0,
        ]);
        try {
            $this->syncRuntime($runtime);
        } catch (Throwable $e) {
            // Preserve the failed declaration so the UI exposes the exact
            // reconciliation error and allows an update/retry.
            throw $e;
        }
        return $route->fresh()->load('runtime')->load('env')->load('cluster')->load('certificate');
    }

    public function profile(int $orgId, int $groupId, int $projectId, int $routeId): ProjectRoute
    {
        return $this->route($orgId, $groupId, $projectId, $routeId)
            ->load('runtime')->load('env')->load('cluster')->load('certificate');
    }

    /**
     * Discover legacy gateway routes that terminate at a runtime directly, or
     * at one of the published ports imported from its former Docker container.
     */
    public function importCandidates(
        int $orgId,
        int $groupId,
        int $projectId,
        int $runtimeId
    ): array {
        $runtime = $this->runtime($orgId, $groupId, $projectId, $runtimeId);
        if ((string) $runtime->orchestrator_type !== Cluster::ORCHESTRATOR_DOCKER_SWARM) {
            return [];
        }
        $publishedPorts = $this->runtimePublishedPorts($runtime);
        $serviceName = ProjectServiceIdentity::runtimeDockerName($runtime);
        $legacyTargets = $this->runtimeLegacyTargets($runtime, $serviceName);
        $candidates = [];
        foreach (GatewayVhost::where('org_id', $orgId)
            ->where('cluster_id', (int) $runtime->cluster_id)
            ->with('rewrites')->orderBy('id')->get() as $vhost) {
            /** @var GatewayVhost $vhost */
            $targetPort = 0;
            $reason = '';
            if ((string) $vhost->target_service === $serviceName) {
                $targetPort = (int) $vhost->target_port;
                $reason = 'VHost 已直接指向当前 Swarm Service';
            } elseif (in_array((string) $vhost->target_service, $legacyTargets, true)
                && isset($publishedPorts[(int) $vhost->target_port])) {
                $targetPort = $publishedPorts[(int) $vhost->target_port];
                $reason = sprintf(
                    'VHost 旧入口 :%d 匹配实例端口 :%d → :%d',
                    (int) $vhost->target_port,
                    (int) $vhost->target_port,
                    $targetPort
                );
            }
            if ($targetPort < 1) {
                continue;
            }
            $candidates[] = [
                'vhost_id' => (int) $vhost->id,
                'hostname' => (string) $vhost->hostname,
                'path_prefix' => (string) $vhost->path_prefix,
                'tls_enabled' => (bool) $vhost->tls_enabled,
                'old_target' => (string) $vhost->target_service . ':' . (int) $vhost->target_port,
                'target_service' => $serviceName,
                'target_port' => $targetPort,
                'reason' => $reason,
                'importable' => $vhost->rewrites->count() === 0,
                'blocked_reason' => $vhost->rewrites->count() > 0
                    ? '该 VHost 包含多条有序 Rewrite，当前项目路由模型无法无损导入'
                    : '',
            ];
        }
        return $candidates;
    }

    /**
     * Complete a Docker-container project import after its asynchronous
     * Service release has converged. Only VHosts targeting the original node
     * and one of the imported published ports are eligible.
     */
    public function autoImportForRelease(ProjectRelease $release): array
    {
        $source = (array) (((array) $release->desired_spec)['runtime_import_source'] ?? []);
        if (($source['type'] ?? '') !== ProjectRuntimeImportService::DOCKER_CONTAINER) {
            return [];
        }
        /** @var ProjectRuntime|null $runtime */
        $runtime = ProjectRuntime::where('release_id', (int) $release->id)
            ->where('org_id', (int) $release->org_id)
            ->where('group_id', (int) $release->group_id)
            ->where('project_id', (int) $release->project_id)
            ->first();
        if ($runtime === null) {
            return [];
        }
        $ids = array_column(array_values(array_filter(
            $this->importCandidates(
                (int) $release->org_id,
                (int) $release->group_id,
                (int) $release->project_id,
                (int) $runtime->id
            ),
            static fn (array $candidate): bool => (bool) ($candidate['importable'] ?? false)
        )), 'vhost_id');
        if ($ids === []) {
            return [];
        }
        return $this->importGatewayVhosts(
            (int) $release->creator,
            (int) $release->org_id,
            (int) $release->group_id,
            (int) $release->project_id,
            (int) $runtime->id,
            array_map('intval', $ids)
        );
    }

    /**
     * Promote selected cluster-level VHosts to durable project routes.
     *
     * Project labels are installed first. The legacy file-provider VHosts are
     * removed only after the Service update succeeds, avoiding a routing gap.
     */
    public function importGatewayVhosts(
        int $uid,
        int $orgId,
        int $groupId,
        int $projectId,
        int $runtimeId,
        array $vhostIds
    ): array {
        $runtime = $this->runtime($orgId, $groupId, $projectId, $runtimeId);
        if ((string) $runtime->orchestrator_type !== Cluster::ORCHESTRATOR_DOCKER_SWARM) {
            throw new AppException(422, '只有 Docker Swarm 实例可以导入 Traefik VHost');
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $vhostIds))));
        if ($ids === [] || count($ids) > 50) {
            throw new AppException(422, '请选择 1-50 条可导入的 VHost');
        }
        $candidateMap = [];
        foreach ($this->importCandidates($orgId, $groupId, $projectId, $runtimeId) as $candidate) {
            $candidateMap[(int) $candidate['vhost_id']] = $candidate;
        }
        $gateway = ClusterWebGateway::where('org_id', $orgId)
            ->where('cluster_id', (int) $runtime->cluster_id)->first();
        if ($gateway === null || (string) $gateway->network_id === '') {
            throw new AppException(422, '目标集群 Web 网关尚未就绪');
        }

        $created = [];
        $vhosts = [];
        foreach ($ids as $id) {
            $candidate = $candidateMap[$id] ?? null;
            if ($candidate === null || ! $candidate['importable']) {
                throw new AppException(422, 'VHost #' . $id . ' 无法可靠关联到该实例');
            }
            /** @var GatewayVhost|null $vhost */
            $vhost = GatewayVhost::where('id', $id)->where('org_id', $orgId)
                ->where('cluster_id', (int) $runtime->cluster_id)->first();
            if ($vhost === null || GatewayVhostRewrite::where('vhost_id', $id)->exists()) {
                throw new AppException(422, 'VHost #' . $id . ' 已变化，请刷新候选列表');
            }
            if (ProjectRoute::where('cluster_id', (int) $runtime->cluster_id)
                ->where('route_key', (string) $vhost->vhost_key)->exists()) {
                throw new AppException(409, 'VHost #' . $id . ' 已存在对应项目路由');
            }
            $this->managedDomains->grantImportedHostname(
                $uid,
                (int) $runtime->org_id,
                (int) $runtime->group_id,
                (string) $vhost->hostname
            );
            $created[] = ProjectRoute::create($this->projectRouteFromVhost(
                $uid, $runtime, $gateway, $vhost, (int) $candidate['target_port']
            ));
            $vhosts[] = $vhost;
        }

        try {
            $this->syncRuntime($runtime);
        } catch (Throwable $e) {
            ProjectRoute::whereIn('id', array_map(
                static fn (ProjectRoute $route): int => (int) $route->id,
                $created
            ))->delete();
            throw $e;
        }

        Db::transaction(function () use ($vhosts): void {
            foreach ($vhosts as $vhost) {
                GatewayVhostRewrite::where('vhost_id', (int) $vhost->id)->delete();
                $vhost->delete();
            }
        });
        $this->gatewayVhosts->deploy($orgId, (int) $runtime->cluster_id);
        return array_map(
            static fn (ProjectRoute $route): array => $route->fresh()->toArray(),
            $created
        );
    }

    public function update(int $orgId, int $groupId, int $projectId, int $routeId, array $input): ProjectRoute
    {
        $route = $this->route($orgId, $groupId, $projectId, $routeId);
        $runtime = $this->runtime($orgId, $groupId, $projectId, (int) $route->runtime_id);
        $data = $this->normalize(
            $runtime,
            $input + $route->toArray() + ['runtime_id' => $runtime->id]
        );
        if ((string) $runtime->orchestrator_type === Cluster::ORCHESTRATOR_DOCKER_SWARM) {
            $this->assertNetworkConsistency((int) $runtime->id, $data['network_id'], (int) $route->id);
        }
        $this->assertRouteAvailable((int) $runtime->cluster_id, $data, (int) $route->id);
        foreach ($data as $key => $value) {
            $route->{$key} = $value;
        }
        $route->status = ProjectRoute::STATUS_PENDING;
        $route->error = null;
        $route->updated_at = time();
        $route->save();
        $this->syncRuntime($runtime);
        return $route->fresh()->load('runtime')->load('env')->load('cluster')->load('certificate');
    }

    public function delete(int $orgId, int $groupId, int $projectId, int $routeId): void
    {
        $route = $this->route($orgId, $groupId, $projectId, $routeId);
        $runtime = $this->runtime($orgId, $groupId, $projectId, (int) $route->runtime_id);
        $route->enabled = false;
        $route->status = ProjectRoute::STATUS_PENDING;
        $route->updated_at = time();
        $route->save();
        $this->syncRuntime($runtime);
        $route->delete();
    }

    public function updateFromGateway(
        int $orgId, int $clusterId, int $routeId, array $input,
        ?int $groupId = null, ?int $projectId = null
    ): ProjectRoute
    {
        $query = ProjectRoute::where('id', $routeId)->where('org_id', $orgId)
            ->where('cluster_id', $clusterId);
        if ($projectId !== null) {
            $query->where('group_id', $groupId)->where('project_id', $projectId);
        }
        $route = $query->first();
        if ($route === null) {
            throw new AppException(404, '该集群中的项目路由不存在');
        }
        return $this->update($orgId, (int) $route->group_id, (int) $route->project_id, $routeId, $input);
    }

    public function deleteFromGateway(
        int $orgId, int $clusterId, int $routeId,
        ?int $groupId = null, ?int $projectId = null
    ): void
    {
        $query = ProjectRoute::where('id', $routeId)->where('org_id', $orgId)
            ->where('cluster_id', $clusterId);
        if ($projectId !== null) {
            $query->where('group_id', $groupId)->where('project_id', $projectId);
        }
        $route = $query->first();
        if ($route === null) {
            throw new AppException(404, '该集群中的项目路由不存在');
        }
        $this->delete($orgId, (int) $route->group_id, (int) $route->project_id, $routeId);
    }

    public function applyToServiceSpec(ProjectRuntime $runtime, array $spec): array
    {
        $routes = ProjectRoute::where('runtime_id', (int) $runtime->id)->where('enabled', 1)->orderBy('id')->get();
        $labels = (array) ($spec['Labels'] ?? []);
        foreach (array_keys($labels) as $key) {
            if ($key === 'traefik.enable' || $key === 'traefik.swarm.network'
                || str_starts_with($key, 'traefik.http.routers.cg-')
                || str_starts_with($key, 'traefik.http.services.cg-')
                || str_starts_with($key, 'traefik.http.middlewares.cg-')) {
                unset($labels[$key]);
            }
        }
        if ($routes->isEmpty()) {
            $labels['traefik.enable'] = 'false';
            $spec['Labels'] = $labels;
            return $spec;
        }
        $labels['traefik.enable'] = 'true';
        $labels['traefik.swarm.network'] = (string) $routes->first()->network_name;
        $certificateIds = $routes->pluck('certificate_id')->filter()->unique()->values()->all();
        $certificates = TlsCertificate::whereIn('id', $certificateIds)->get()->keyBy('id');
        foreach ($routes as $route) {
            $name = 'cg-' . $route->id;
            $rule = 'Host(`' . $route->hostname . '`)';
            if ($route->path_prefix !== '/') {
                $matcher = match ((string) $route->path_match) {
                    'exact' => 'Path',
                    'regex' => 'PathRegexp',
                    default => 'PathPrefix',
                };
                $rule .= ' && ' . $matcher . '(`' . $route->path_prefix . '`)';
            }
            if ((array) $route->methods !== []) {
                $rule .= ' && Method(' . implode(',', array_map(
                    static fn (string $method): string => '`' . $method . '`',
                    (array) $route->methods
                )) . ')';
            }
            foreach ((array) $route->ip_denylist as $cidr) {
                $rule .= ' && !ClientIP(`' . $cidr . '`)';
            }
            $labels['traefik.http.routers.' . $name . '.rule'] = $rule;
            $labels['traefik.http.routers.' . $name . '.entrypoints'] = $route->tls_enabled
                ? 'websecure' : (string) $route->entrypoint;
            $labels['traefik.http.routers.' . $name . '.service'] = $name;
            if ($route->priority > 0) {
                $labels['traefik.http.routers.' . $name . '.priority'] = (string) $route->priority;
            }
            if ($route->tls_enabled) {
                $labels['traefik.http.routers.' . $name . '.tls'] = 'true';
                /** @var TlsCertificate|null $certificate */
                $certificate = $certificates->get((int) $route->certificate_id);
                if ($certificate?->source === TlsCertificate::SOURCE_LETS_ENCRYPT) {
                    $labels['traefik.http.routers.' . $name . '.tls.certresolver'] = 'letsencrypt';
                    $domains = array_values((array) $certificate->domains);
                    if ($domains !== []) {
                        $labels['traefik.http.routers.' . $name . '.tls.domains[0].main'] = (string) $domains[0];
                        if (count($domains) > 1) {
                            $labels['traefik.http.routers.' . $name . '.tls.domains[0].sans'] = implode(',', array_slice($domains, 1));
                        }
                    }
                }
            }
            $middlewares = [];
            if ((array) $route->ip_allowlist !== []) {
                $middleware = $name . '-allow-ip';
                $labels['traefik.http.middlewares.' . $middleware . '.ipallowlist.sourcerange'] = implode(',', (array) $route->ip_allowlist);
                $middlewares[] = $middleware;
            }
            if ($route->security_headers_enabled) {
                $middleware = $name . '-security-headers';
                $prefix = 'traefik.http.middlewares.' . $middleware . '.headers.';
                $labels[$prefix . 'contenttypenosniff'] = 'true';
                $labels[$prefix . 'framedeny'] = 'true';
                $labels[$prefix . 'referrerpolicy'] = 'strict-origin-when-cross-origin';
                $labels[$prefix . 'permissionspolicy'] = 'camera=(), microphone=(), geolocation=()';
                $middlewares[] = $middleware;
            }
            if ((array) $route->custom_request_headers !== []
                || (array) $route->custom_response_headers !== []
                || $route->cors_enabled) {
                $middleware = $name . '-headers';
                $prefix = 'traefik.http.middlewares.' . $middleware . '.headers.';
                foreach ((array) $route->custom_request_headers as $header => $value) {
                    $labels[$prefix . 'customrequestheaders.' . $header] = (string) $value;
                }
                foreach ((array) $route->custom_response_headers as $header => $value) {
                    $labels[$prefix . 'customresponseheaders.' . $header] = (string) $value;
                }
                if ($route->cors_enabled) {
                    $labels[$prefix . 'accesscontrolalloworiginlist'] = implode(',', (array) $route->cors_allow_origins);
                    $labels[$prefix . 'accesscontrolallowmethods'] = implode(',', (array) $route->cors_allow_methods);
                    $labels[$prefix . 'accesscontrolallowheaders'] = implode(',', (array) $route->cors_allow_headers);
                    $labels[$prefix . 'accesscontrolallowcredentials'] = $route->cors_allow_credentials ? 'true' : 'false';
                    $labels[$prefix . 'accesscontrolmaxage'] = (string) $route->cors_max_age_seconds;
                    $labels[$prefix . 'addvaryheader'] = 'true';
                }
                $middlewares[] = $middleware;
            }
            if ($route->compress_enabled) {
                $middleware = $name . '-compress';
                $labels['traefik.http.middlewares.' . $middleware . '.compress'] = 'true';
                $labels['traefik.http.middlewares.' . $middleware . '.compress.excludedcontenttypes'] = 'text/event-stream';
                $middlewares[] = $middleware;
            }
            if ((int) $route->request_body_limit_bytes > 0) {
                $middleware = $name . '-body-limit';
                $prefix = 'traefik.http.middlewares.' . $middleware . '.buffering.';
                $labels[$prefix . 'maxrequestbodybytes'] = (string) $route->request_body_limit_bytes;
                $labels[$prefix . 'memrequestbodybytes'] = (string) min((int) $route->request_body_limit_bytes, 2097152);
                $middlewares[] = $middleware;
            }
            if ((int) $route->rate_limit_average > 0) {
                $middleware = $name . '-rate-limit';
                $prefix = 'traefik.http.middlewares.' . $middleware . '.ratelimit.';
                $labels[$prefix . 'average'] = (string) $route->rate_limit_average;
                $labels[$prefix . 'burst'] = (string) $route->rate_limit_burst;
                $labels[$prefix . 'period'] = max(1, (int) $route->rate_limit_period_seconds) . 's';
                $middlewares[] = $middleware;
            }
            if ((int) $route->max_inflight_requests > 0) {
                $middleware = $name . '-inflight';
                $labels['traefik.http.middlewares.' . $middleware . '.inflightreq.amount'] = (string) $route->max_inflight_requests;
                $middlewares[] = $middleware;
            }
            if ($route->rewrite_type === 'strip_prefix') {
                $middleware = $name . '-rewrite';
                $labels['traefik.http.middlewares.' . $middleware . '.stripprefix.prefixes'] = (string) $route->rewrite_pattern;
                $middlewares[] = $middleware;
            } elseif ($route->rewrite_type === 'replace_path_regex') {
                $middleware = $name . '-rewrite';
                $labels['traefik.http.middlewares.' . $middleware . '.replacepathregex.regex'] = (string) $route->rewrite_pattern;
                $labels['traefik.http.middlewares.' . $middleware . '.replacepathregex.replacement'] = (string) $route->rewrite_replacement;
                $middlewares[] = $middleware;
            }
            if ((int) $route->retry_attempts > 0) {
                $middleware = $name . '-retry';
                $prefix = 'traefik.http.middlewares.' . $middleware . '.retry.';
                $labels[$prefix . 'attempts'] = (string) $route->retry_attempts;
                $labels[$prefix . 'initialinterval'] = max(10, (int) $route->retry_initial_interval_ms) . 'ms';
                $middlewares[] = $middleware;
            }
            if ((string) $route->circuit_breaker_expression !== '') {
                $middleware = $name . '-circuit-breaker';
                $labels['traefik.http.middlewares.' . $middleware . '.circuitbreaker.expression'] = (string) $route->circuit_breaker_expression;
                $middlewares[] = $middleware;
            }
            if ($middlewares !== []) {
                $labels['traefik.http.routers.' . $name . '.middlewares'] = implode(',', $middlewares);
            }
            // Every project route gets an SSE path without compression or
            // response-buffering body limits.
            $streamRouter = $name . '-sse';
            $labels['traefik.http.routers.' . $streamRouter . '.rule'] = $rule
                . ' && Header(`Accept`, `text/event-stream`)';
            $labels['traefik.http.routers.' . $streamRouter . '.entrypoints'] = $route->tls_enabled
                ? 'websecure' : (string) $route->entrypoint;
            $labels['traefik.http.routers.' . $streamRouter . '.service'] = $name;
            $streamMiddlewares = array_values(array_filter(
                $middlewares,
                static fn (string $middleware): bool => ! in_array($middleware, [
                    $name . '-compress',
                    $name . '-body-limit',
                ], true)
            ));
            if ($streamMiddlewares !== []) {
                $labels['traefik.http.routers.' . $streamRouter . '.middlewares'] = implode(',', $streamMiddlewares);
            }
            if ($route->priority > 0) {
                $labels['traefik.http.routers.' . $streamRouter . '.priority'] = (string) ($route->priority + 1);
            }
            if ($route->tls_enabled) {
                $labels['traefik.http.routers.' . $streamRouter . '.tls'] = 'true';
                $certResolverKey = 'traefik.http.routers.' . $name . '.tls.certresolver';
                if (isset($labels[$certResolverKey])) {
                    $labels['traefik.http.routers.' . $streamRouter . '.tls.certresolver']
                        = $labels[$certResolverKey];
                }
            }
            if ($route->tls_enabled && $route->https_redirect) {
                $httpRouter = $name . '-http';
                $redirect = $name . '-https';
                $labels['traefik.http.routers.' . $httpRouter . '.rule'] = $rule;
                $labels['traefik.http.routers.' . $httpRouter . '.entrypoints'] = 'web';
                $labels['traefik.http.routers.' . $httpRouter . '.service'] = $name;
                $redirectMiddlewares = [];
                if ((array) $route->ip_allowlist !== []) {
                    $redirectMiddlewares[] = $name . '-allow-ip';
                }
                $redirectMiddlewares[] = $redirect;
                $labels['traefik.http.routers.' . $httpRouter . '.middlewares'] = implode(',', $redirectMiddlewares);
                $labels['traefik.http.middlewares.' . $redirect . '.redirectscheme.scheme'] = 'https';
                $labels['traefik.http.middlewares.' . $redirect . '.redirectscheme.permanent'] = 'true';
            }
            $labels['traefik.http.services.' . $name . '.loadbalancer.server.port'] = (string) $route->target_port;
            $labels['traefik.http.services.' . $name . '.loadbalancer.server.scheme'] = (string) ($route->upstream_scheme ?: 'http');
            $labels['traefik.http.services.' . $name . '.loadbalancer.passhostheader'] = $route->pass_host_header ? 'true' : 'false';
            $labels['traefik.http.services.' . $name . '.loadbalancer.serverstransport'] = 'cg-project-route-' . $route->id . '-transport@file';
            $labels['traefik.http.services.' . $name . '.loadbalancer.responseforwarding.flushinterval'] = '-1ms';
            if ((string) $route->healthcheck_path !== '') {
                $prefix = 'traefik.http.services.' . $name . '.loadbalancer.healthcheck.';
                $labels[$prefix . 'path'] = (string) $route->healthcheck_path;
                $labels[$prefix . 'interval'] = max(1000, (int) $route->healthcheck_interval_ms) . 'ms';
                $labels[$prefix . 'timeout'] = max(100, (int) $route->healthcheck_timeout_ms) . 'ms';
            }
            if ($route->sticky_cookie_enabled) {
                $prefix = 'traefik.http.services.' . $name . '.loadbalancer.sticky.cookie.';
                $labels[$prefix . 'name'] = (string) $route->sticky_cookie_name;
                $labels[$prefix . 'httponly'] = 'true';
                $labels[$prefix . 'secure'] = $route->tls_enabled ? 'true' : 'false';
                $labels[$prefix . 'samesite'] = 'lax';
            }
        }
        $spec['Labels'] = $labels;
        $networks = (array) ($spec['TaskTemplate']['Networks'] ?? []);
        $targets = array_map(static fn (array $network): string => (string) ($network['Target'] ?? ''), $networks);
        $networkId = (string) $routes->first()->network_id;
        if (! in_array($networkId, $targets, true)) {
            $networks[] = ['Target' => $networkId];
        }
        $spec['TaskTemplate']['Networks'] = $networks;
        return $spec;
    }

    public function syncRuntime(ProjectRuntime $runtime): void
    {
        if ((string) $runtime->orchestrator_type === Cluster::ORCHESTRATOR_KUBERNETES) {
            $this->kubernetesRoutes->syncRuntime($runtime);
            return;
        }
        $cluster = $this->cluster((int) $runtime->org_id, (int) $runtime->cluster_id);
        try {
            // ServersTransport is a file-provider object and must exist before
            // the Docker-label service starts referencing it.
            $this->gatewayVhosts->deploy((int) $runtime->org_id, (int) $runtime->cluster_id);
            $this->runtimeLock->synchronized(
                (int) $runtime->org_id,
                (int) $runtime->project_id,
                (int) $runtime->env_id,
                (int) $runtime->cluster_id,
                (string) $runtime->name,
                fn () => $this->docker->withCluster(
                    $cluster,
                    function (Client $client, SwarmApiClient $api) use ($runtime): void {
                        $service = $api->request($client, 'GET', '/services/' . rawurlencode((string) $runtime->runtime_ref));
                        $this->guard->assertService(
                            $service,
                            (int) $runtime->org_id,
                            (int) $runtime->project_id,
                            (int) $runtime->env_id
                        );
                        $spec = $this->applyToServiceSpec($runtime, (array) ($service['Spec'] ?? []));
                        $api->request($client, 'POST', '/services/' . rawurlencode((string) $runtime->runtime_ref) . '/update', [
                            'query' => ['version' => (int) ($service['Version']['Index'] ?? 0), 'registryAuthFrom' => 'spec'],
                            'json' => $spec,
                        ]);
                    }
                )
            );
            ProjectRoute::where('runtime_id', (int) $runtime->id)->update([
                'status' => ProjectRoute::STATUS_SYNCED, 'error' => null, 'synced_at' => time(), 'updated_at' => time(),
            ]);
            $this->certificateDeployer->syncGateway($cluster);
            // Certificate reconciliation may roll the Traefik task. Reapply
            // file-provider transports to the currently running gateway task.
            $this->gatewayVhosts->deploy((int) $runtime->org_id, (int) $runtime->cluster_id);
        } catch (Throwable $e) {
            ProjectRoute::where('runtime_id', (int) $runtime->id)->update([
                'status' => ProjectRoute::STATUS_ERROR, 'error' => mb_substr($e->getMessage(), 0, 2000), 'updated_at' => time(),
            ]);
            throw $e;
        }
    }

    private function normalize(ProjectRuntime $runtime, array $input): array
    {
        $hostname = strtolower(rtrim(trim((string) ($input['hostname'] ?? '')), '.'));
        $isKubernetes = (string) $runtime->orchestrator_type === Cluster::ORCHESTRATOR_KUBERNETES;
        if (! preg_match('/^(?=.{3,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $hostname)) {
            throw new AppException(422, '路由必须绑定完整的 ASCII Host 域名');
        }
        $this->managedDomains->assertGroupCanUse(
            (int) $runtime->org_id,
            (int) $runtime->group_id,
            $hostname
        );
        $pathMatch = $this->normalizePathMatch($input['path_match'] ?? 'prefix');
        $path = trim((string) ($input['path_prefix'] ?? '/'));
        if ($pathMatch !== 'regex') {
            $path = '/' . ltrim($path, '/');
            $path = $path === '/' ? '/' : rtrim($path, '/');
        }
        if (strlen($path) > 1024 || preg_match('/[`\x00-\x1f\x7f]/', $path)) {
            throw new AppException(422, '路径前缀不合法');
        }
        $port = filter_var($input['target_port'] ?? null, FILTER_VALIDATE_INT);
        if ($port === false || $port < 1 || $port > 65535) {
            throw new AppException(422, '容器目标端口必须在 1-65535 之间');
        }
        $entrypoint = trim((string) ($input['entrypoint'] ?? 'web'));
        $tlsEnabled = (bool) ($input['tls_enabled'] ?? false);
        if (! preg_match('/^[a-zA-Z0-9_.-]{1,64}$/', $entrypoint)) {
            throw new AppException(422, 'Traefik EntryPoint 名称不合法');
        }
        $certificateId = $tlsEnabled ? (int) ($input['certificate_id'] ?? 0) : 0;
        $certificate = null;
        if ($tlsEnabled) {
            if ($certificateId <= 0) {
                throw new AppException(422, 'HTTPS 路由必须选择 SSL 证书');
            }
            $certificate = $this->certificates->assertUsableForHostname(
                (int) $runtime->org_id, $certificateId, $hostname
            );
            if ($isKubernetes) {
                $this->certificates->decryptedKeyPair($certificate);
            }
        }
        $network = $isKubernetes
            ? ['id' => '', 'name' => (string) $runtime->runtime_namespace]
            : $this->network($runtime);
        if (! $isKubernetes && $certificate?->source === TlsCertificate::SOURCE_LETS_ENCRYPT) {
            $gateway = ClusterWebGateway::where('cluster_id', (int) $runtime->cluster_id)->first();
            if ($gateway === null || ! $gateway->acme_enabled) {
                throw new AppException(422, '该集群网关尚未启用 Let’s Encrypt ACME');
            }
        }
        $rewriteType = (string) ($input['rewrite_type'] ?? 'none');
        if (! in_array($rewriteType, ['none', 'strip_prefix', 'replace_path_regex'], true)) {
            throw new AppException(422, 'URL Rewrite 类型无效');
        }
        if ($isKubernetes && ($pathMatch === 'regex' || $rewriteType !== 'none'
            || (array) ($input['methods'] ?? []) !== []
            || (array) ($input['ip_allowlist'] ?? []) !== []
            || (array) ($input['ip_denylist'] ?? []) !== []
            || (int) ($input['rate_limit_average'] ?? 0) > 0
            || (int) ($input['max_inflight_requests'] ?? 0) > 0
            || (int) ($input['retry_attempts'] ?? 0) > 0
            || (bool) ($input['cors_enabled'] ?? false)
            || (bool) ($input['sticky_cookie_enabled'] ?? false))) {
            throw new AppException(422, '当前 Kubernetes Ingress 仅支持 Host、Prefix/Exact Path、端口和优先级');
        }
        $rewritePattern = trim((string) ($input['rewrite_pattern'] ?? ''));
        $rewriteReplacement = trim((string) ($input['rewrite_replacement'] ?? ''));
        if ($rewriteType === 'strip_prefix') {
            $rewritePattern = '/' . ltrim($rewritePattern !== '' ? $rewritePattern : $path, '/');
            $rewriteReplacement = '';
        } elseif ($rewriteType === 'replace_path_regex') {
            if ($rewritePattern === '' || $rewriteReplacement === '') {
                throw new AppException(422, '正则路径改写必须填写匹配表达式和替换路径');
            }
        } else {
            $rewritePattern = $rewriteReplacement = '';
        }
        if (strlen($rewritePattern) > 1024 || strlen($rewriteReplacement) > 1024
            || preg_match('/[`\x00-\x1f\x7f]/', $rewritePattern . $rewriteReplacement)) {
            throw new AppException(422, 'URL Rewrite 内容不合法');
        }
        $data = [
            'route_key' => $this->routeKey(
                $hostname,
                $path,
                $pathMatch,
                $this->normalizeMethods((array) ($input['methods'] ?? []))
            ),
            'hostname' => $hostname, 'path_prefix' => $path, 'target_port' => (int) $port,
            'path_match' => $pathMatch,
            'methods' => $this->normalizeMethods((array) ($input['methods'] ?? [])),
            'entrypoint' => $entrypoint, 'tls_enabled' => $tlsEnabled,
            'cert_resolver' => $certificate?->source === TlsCertificate::SOURCE_LETS_ENCRYPT ? 'letsencrypt' : '',
            'certificate_id' => $certificateId,
            'https_redirect' => $tlsEnabled && (bool) ($input['https_redirect'] ?? false),
            'https_redirect_port' => $isKubernetes
                ? $this->kubernetesHttpsPort((int) $runtime->cluster_id)
                : $this->boundedInt($input, 'https_redirect_port', 443, 65535, 1),
            'rewrite_type' => $rewriteType, 'rewrite_pattern' => $rewritePattern,
            'rewrite_replacement' => $rewriteReplacement,
            'upstream_scheme' => $this->normalizeUpstreamScheme($input['upstream_scheme'] ?? 'http'),
            'pass_host_header' => (bool) ($input['pass_host_header'] ?? true),
            'ip_allowlist' => $this->normalizeCidrs((array) ($input['ip_allowlist'] ?? [])),
            'ip_denylist' => $this->normalizeCidrs((array) ($input['ip_denylist'] ?? [])),
            'rate_limit_average' => $this->boundedInt($input, 'rate_limit_average', 0, 1000000),
            'rate_limit_burst' => $this->boundedInt($input, 'rate_limit_burst', 0, 1000000),
            'rate_limit_period_seconds' => $this->boundedInt($input, 'rate_limit_period_seconds', 1, 86400, 1),
            'max_inflight_requests' => $this->boundedInt($input, 'max_inflight_requests', 0, 1000000),
            'retry_attempts' => $this->boundedInt($input, 'retry_attempts', 0, 10),
            'retry_initial_interval_ms' => $this->boundedInt($input, 'retry_initial_interval_ms', 100, 60000, 10),
            'dial_timeout_ms' => $this->boundedInt($input, 'dial_timeout_ms', 30000, 600000),
            'response_header_timeout_ms' => $this->boundedInt($input, 'response_header_timeout_ms', 0, 3600000),
            'idle_connection_timeout_ms' => $this->boundedInt($input, 'idle_connection_timeout_ms', 90000, 3600000),
            'security_headers_enabled' => (bool) ($input['security_headers_enabled'] ?? true),
            'compress_enabled' => (bool) ($input['compress_enabled'] ?? true),
            'request_body_limit_bytes' => $this->boundedInt($input, 'request_body_limit_bytes', 0, 10737418240),
            'custom_request_headers' => $this->normalizeHeaders((array) ($input['custom_request_headers'] ?? [])),
            'custom_response_headers' => $this->normalizeHeaders((array) ($input['custom_response_headers'] ?? [])),
            'cors_enabled' => (bool) ($input['cors_enabled'] ?? false),
            'cors_allow_origins' => $this->normalizeStringList((array) ($input['cors_allow_origins'] ?? []), 100, 255),
            'cors_allow_methods' => $this->normalizeMethods((array) ($input['cors_allow_methods'] ?? ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'])),
            'cors_allow_headers' => $this->normalizeStringList((array) ($input['cors_allow_headers'] ?? ['Content-Type', 'Authorization']), 100, 255),
            'cors_allow_credentials' => (bool) ($input['cors_allow_credentials'] ?? false),
            'cors_max_age_seconds' => $this->boundedInt($input, 'cors_max_age_seconds', 600, 86400),
            'circuit_breaker_expression' => $this->safePolicyString($input['circuit_breaker_expression'] ?? '', 255, '熔断表达式'),
            'healthcheck_path' => $this->safePolicyString($input['healthcheck_path'] ?? '', 1024, '健康检查路径'),
            'healthcheck_interval_ms' => $this->boundedInt($input, 'healthcheck_interval_ms', 10000, 3600000, 1000),
            'healthcheck_timeout_ms' => $this->boundedInt($input, 'healthcheck_timeout_ms', 3000, 600000, 100),
            'sticky_cookie_enabled' => (bool) ($input['sticky_cookie_enabled'] ?? false),
            'sticky_cookie_name' => $this->safePolicyString($input['sticky_cookie_name'] ?? 'cg_session', 64, 'Cookie 名称'),
            'network_id' => $network['id'], 'network_name' => $network['name'],
            'orchestrator_type' => (string) $runtime->orchestrator_type,
            'provider_metadata' => [],
            'priority' => max(0, min(100000, (int) ($input['priority'] ?? 0))),
            'enabled' => (bool) ($input['enabled'] ?? true),
        ];
        if ($isKubernetes) {
            // Standard Ingress does not expose the Swarm/Traefik middleware
            // capabilities below. Keep persisted declarations truthful until
            // the Kubernetes provider implements the equivalent resources.
            $data = array_replace($data, [
                'security_headers_enabled' => false,
                'compress_enabled' => false,
                'request_body_limit_bytes' => 0,
                'custom_request_headers' => [],
                'custom_response_headers' => [],
                'cors_enabled' => false,
                'cors_allow_origins' => [],
                'cors_allow_methods' => [],
                'cors_allow_headers' => [],
                'cors_allow_credentials' => false,
                'circuit_breaker_expression' => '',
                'healthcheck_path' => '',
                'sticky_cookie_enabled' => false,
            ]);
        }
        if ($data['rate_limit_average'] > 0 && $data['rate_limit_burst'] < $data['rate_limit_average']) {
            throw new AppException(422, '限流突发容量不能小于平均请求数');
        }
        if ($data['cors_enabled'] && $data['cors_allow_origins'] === []) {
            throw new AppException(422, '启用 CORS 时至少需要一个允许来源');
        }
        if ($data['healthcheck_path'] !== '' && ! str_starts_with($data['healthcheck_path'], '/')) {
            throw new AppException(422, '健康检查路径必须以 / 开头');
        }
        if ($data['sticky_cookie_name'] === '' || ! preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $data['sticky_cookie_name'])) {
            throw new AppException(422, '会话保持 Cookie 名称不合法');
        }
        return $data;
    }

    private function normalizePathMatch(mixed $value): string
    {
        $value = strtolower(trim((string) $value));
        if (! in_array($value, ['prefix', 'exact', 'regex'], true)) {
            throw new AppException(422, '路径匹配方式无效');
        }
        return $value;
    }

    private function kubernetesHttpsPort(int $clusterId): int
    {
        $connection = KubernetesClusterConnection::where('cluster_id', $clusterId)->first();
        if ($connection === null) {
            throw new AppException(409, 'Kubernetes 集群连接配置不存在');
        }
        $port = (int) $connection->ingress_https_port;
        return $port > 0 ? $port : 443;
    }

    private function normalizeMethods(array $values): array
    {
        $allowed = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'CONNECT', 'TRACE'];
        $methods = array_values(array_unique(array_map(static fn ($value) => strtoupper(trim((string) $value)), $values)));
        foreach ($methods as $method) {
            if (! in_array($method, $allowed, true)) {
                throw new AppException(422, '不支持的 HTTP Method：' . $method);
            }
        }
        sort($methods);
        return $methods;
    }

    private function routeKey(string $hostname, string $path, string $pathMatch, array $methods): string
    {
        sort($methods);
        return hash('sha256', $hostname . "\0" . $path . "\0" . $pathMatch . "\0"
            . json_encode($methods, JSON_UNESCAPED_SLASHES));
    }

    private function normalizeHeaders(array $headers): array
    {
        if (count($headers) > 100) {
            throw new AppException(422, '自定义 Header 最多 100 项');
        }
        $result = [];
        foreach ($headers as $name => $value) {
            $name = trim((string) $name);
            $value = trim((string) $value);
            if (! preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]{1,128}$/', $name)
                || strlen($value) > 2048 || preg_match('/[\r\n]/', $value)) {
                throw new AppException(422, '自定义 Header 名称或内容不合法');
            }
            $result[$name] = $value;
        }
        return $result;
    }

    private function normalizeStringList(array $values, int $limit, int $length): array
    {
        if (count($values) > $limit) {
            throw new AppException(422, '策略列表项过多');
        }
        $result = [];
        foreach ($values as $value) {
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }
            if (strlen($value) > $length || preg_match('/[\r\n\x00]/', $value)) {
                throw new AppException(422, '策略列表内容不合法');
            }
            $result[] = $value;
        }
        return array_values(array_unique($result));
    }

    private function safePolicyString(mixed $value, int $maximum, string $label): string
    {
        $value = trim((string) $value);
        if (strlen($value) > $maximum || preg_match('/[\x00-\x1f\x7f]/', $value)) {
            throw new AppException(422, $label . '内容不合法');
        }
        return $value;
    }

    private function normalizeUpstreamScheme(mixed $value): string
    {
        $scheme = strtolower(trim((string) $value));
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new AppException(422, '上游协议仅支持 HTTP 或 HTTPS');
        }
        return $scheme;
    }

    private function boundedInt(array $input, string $key, int $default, int $maximum, int $minimum = 0): int
    {
        return max($minimum, min($maximum, (int) ($input[$key] ?? $default)));
    }

    private function normalizeCidrs(array $values): array
    {
        $result = [];
        foreach ($values as $value) {
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }
            [$ip, $prefix] = array_pad(explode('/', $value, 2), 2, null);
            $isV4 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
            $isV6 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
            if (! $isV4 && ! $isV6) {
                throw new AppException(422, '无效的 IP/CIDR：' . $value);
            }
            if ($prefix !== null && (! ctype_digit($prefix)
                || (int) $prefix < 0 || (int) $prefix > ($isV4 ? 32 : 128))) {
                throw new AppException(422, '无效的 CIDR 前缀：' . $value);
            }
            $result[] = $prefix === null ? $ip : $ip . '/' . (int) $prefix;
        }
        return array_values(array_unique($result));
    }

    private function network(ProjectRuntime $runtime): array
    {
        /** @var ClusterWebGateway|null $gateway */
        $gateway = ClusterWebGateway::where('org_id', (int) $runtime->org_id)
            ->where('cluster_id', (int) $runtime->cluster_id)->where('service_id', '<>', '')->first();
        if ($gateway === null || $gateway->network_id === '') {
            throw new AppException(422, '目标集群尚未安装 CodeGalaxy Web 网关，请先在集群页面安装 Traefik');
        }
        $cluster = $this->cluster((int) $runtime->org_id, (int) $runtime->cluster_id);
        return $this->docker->withCluster($cluster, function (Client $client, SwarmApiClient $api) use ($gateway): array {
            $network = $api->request($client, 'GET', '/networks/' . rawurlencode((string) $gateway->network_id));
            if (($network['Scope'] ?? '') !== 'swarm' || ($network['Driver'] ?? '') !== 'overlay'
                || ! empty($network['Ingress'])) {
                throw new AppException(422, 'Traefik 路由只能使用 Swarm Overlay 网络');
            }
            return ['id' => (string) ($network['Id'] ?? $gateway->network_id), 'name' => (string) ($network['Name'] ?? '')];
        });
    }

    private function assertNetworkConsistency(int $runtimeId, string $networkId, int $exceptId = 0): void
    {
        $query = ProjectRoute::where('runtime_id', $runtimeId)->where('enabled', 1)->where('network_id', '<>', $networkId);
        if ($exceptId > 0) {
            $query->where('id', '<>', $exceptId);
        }
        if ($query->exists()) {
            throw new AppException(422, '同一个 Swarm Service 的 Traefik 路由必须使用同一 Overlay 网络');
        }
    }

    private function assertRouteAvailable(int $clusterId, array $data, int $exceptId = 0): void
    {
        $query = ProjectRoute::where('cluster_id', $clusterId)->where('route_key', $data['route_key']);
        if ($exceptId > 0) {
            $query->where('id', '<>', $exceptId);
        }
        if ($query->exists()) {
            throw new AppException(409, '该集群中已经存在相同域名和路径的路由');
        }
        if (GatewayVhost::where('cluster_id', $clusterId)->where('vhost_key', $data['route_key'])->exists()) {
            throw new AppException(409, '该域名与路径已由集群 Web 网关规则占用');
        }

        $routes = ProjectRoute::where('cluster_id', $clusterId)->where('hostname', $data['hostname'])
            ->where('path_prefix', $data['path_prefix'])->where('path_match', $data['path_match'])->get();
        foreach ($routes as $candidate) {
            if ((int) $candidate->id === $exceptId) {
                continue;
            }
            if ($this->methodsOverlap($data['methods'], (array) $candidate->methods)) {
                throw new AppException(409, '相同域名、路径和 Method 已存在项目路由');
            }
        }
        $vhosts = GatewayVhost::where('cluster_id', $clusterId)->where('hostname', $data['hostname'])
            ->where('path_prefix', $data['path_prefix'])->where('path_match', $data['path_match'])->get();
        foreach ($vhosts as $candidate) {
            if ($this->methodsOverlap($data['methods'], (array) $candidate->methods)) {
                throw new AppException(409, '相同域名、路径和 Method 已存在网关规则');
            }
        }
    }

    private function methodsOverlap(array $left, array $right): bool
    {
        return $left === [] || $right === [] || array_intersect($left, $right) !== [];
    }

    /** @return array<int, int> published port => target port */
    private function runtimePublishedPorts(ProjectRuntime $runtime): array
    {
        $ports = [];
        foreach ((array) (((array) $runtime->spec)['ports'] ?? []) as $port) {
            $published = (int) ($port['published'] ?? 0);
            $target = (int) ($port['target'] ?? 0);
            if ($published > 0 && $target > 0) {
                $ports[$published] = $target;
            }
        }
        return $ports;
    }

    /** @return string[] */
    private function runtimeLegacyTargets(ProjectRuntime $runtime, string $serviceName): array
    {
        $targets = [$serviceName];
        $nodeId = trim((string) (((array) $runtime->spec)['placement_node_id'] ?? ''));
        if ($nodeId === '') {
            $nodeId = trim((string) (
                ((array) (((array) $runtime->provider_metadata)['import_source'] ?? []))['node_id'] ?? ''
            ));
        }
        if ($nodeId === '') {
            return $targets;
        }
        try {
            $cluster = $this->cluster((int) $runtime->org_id, (int) $runtime->cluster_id);
            $node = $this->docker->withCluster(
                $cluster,
                fn (Client $client, SwarmApiClient $api): array => $api->request(
                    $client,
                    'GET',
                    '/nodes/' . rawurlencode($nodeId)
                )
            );
            $targets[] = trim((string) ($node['Status']['Addr'] ?? ''));
            $targets[] = trim((string) ($node['Description']['Hostname'] ?? ''));
        } catch (Throwable) {
            // Direct Service-name matches remain usable while the node is
            // temporarily unavailable. Port-only matches are intentionally
            // not accepted because another host may publish the same port.
        }
        return array_values(array_unique(array_filter($targets)));
    }

    private function projectRouteFromVhost(
        int $uid,
        ProjectRuntime $runtime,
        ClusterWebGateway $gateway,
        GatewayVhost $vhost,
        int $targetPort
    ): array {
        $shared = [
            'route_key' => 'vhost_key',
            'hostname' => 'hostname',
            'path_prefix' => 'path_prefix',
            'path_match' => 'path_match',
            'methods' => 'methods',
            'upstream_scheme' => 'upstream_scheme',
            'pass_host_header' => 'pass_host_header',
            'entrypoint' => 'entrypoint',
            'tls_enabled' => 'tls_enabled',
            'certificate_id' => 'certificate_id',
            'https_redirect' => 'https_redirect',
            'rewrite_type' => 'rewrite_type',
            'rewrite_pattern' => 'rewrite_pattern',
            'rewrite_replacement' => 'rewrite_replacement',
            'ip_allowlist' => 'ip_allowlist',
            'ip_denylist' => 'ip_denylist',
            'rate_limit_average' => 'rate_limit_average',
            'rate_limit_burst' => 'rate_limit_burst',
            'rate_limit_period_seconds' => 'rate_limit_period_seconds',
            'max_inflight_requests' => 'max_inflight_requests',
            'retry_attempts' => 'retry_attempts',
            'retry_initial_interval_ms' => 'retry_initial_interval_ms',
            'dial_timeout_ms' => 'dial_timeout_ms',
            'response_header_timeout_ms' => 'response_header_timeout_ms',
            'idle_connection_timeout_ms' => 'idle_connection_timeout_ms',
            'security_headers_enabled' => 'security_headers_enabled',
            'compress_enabled' => 'compress_enabled',
            'request_body_limit_bytes' => 'request_body_limit_bytes',
            'custom_request_headers' => 'custom_request_headers',
            'custom_response_headers' => 'custom_response_headers',
            'cors_enabled' => 'cors_enabled',
            'cors_allow_origins' => 'cors_allow_origins',
            'cors_allow_methods' => 'cors_allow_methods',
            'cors_allow_headers' => 'cors_allow_headers',
            'cors_allow_credentials' => 'cors_allow_credentials',
            'cors_max_age_seconds' => 'cors_max_age_seconds',
            'circuit_breaker_expression' => 'circuit_breaker_expression',
            'healthcheck_path' => 'healthcheck_path',
            'healthcheck_interval_ms' => 'healthcheck_interval_ms',
            'healthcheck_timeout_ms' => 'healthcheck_timeout_ms',
            'sticky_cookie_enabled' => 'sticky_cookie_enabled',
            'sticky_cookie_name' => 'sticky_cookie_name',
            'priority' => 'priority',
            'enabled' => 'enabled',
        ];
        $data = [];
        foreach ($shared as $target => $source) {
            $data[$target] = $vhost->{$source};
        }
        $now = time();
        return $data + [
            'org_id' => (int) $runtime->org_id,
            'group_id' => (int) $runtime->group_id,
            'project_id' => (int) $runtime->project_id,
            'runtime_id' => (int) $runtime->id,
            'env_id' => (int) $runtime->env_id,
            'cluster_id' => (int) $runtime->cluster_id,
            'orchestrator_type' => Cluster::ORCHESTRATOR_DOCKER_SWARM,
            'target_port' => $targetPort,
            'cert_resolver' => '',
            'network_id' => (string) $gateway->network_id,
            'network_name' => (string) $gateway->network_name,
            'provider_metadata' => [
                'imported_from' => 'gateway_vhost',
                'gateway_vhost_id' => (int) $vhost->id,
                'legacy_target' => (string) $vhost->target_service . ':' . (int) $vhost->target_port,
            ],
            'status' => ProjectRoute::STATUS_PENDING,
            'error' => null,
            'creator' => $uid,
            'created_at' => $now,
            'updated_at' => $now,
            'synced_at' => 0,
        ];
    }

    private function route(int $orgId, int $groupId, int $projectId, int $routeId): ProjectRoute
    {
        $route = ProjectRoute::where('id', $routeId)->where('org_id', $orgId)
            ->where('group_id', $groupId)->where('project_id', $projectId)->first();
        if ($route === null) {
            throw new AppException(404, '项目路由不存在');
        }
        return $route;
    }

    private function runtime(int $orgId, int $groupId, int $projectId, int $runtimeId): ProjectRuntime
    {
        $runtime = ProjectRuntime::where('id', $runtimeId)->where('org_id', $orgId)
            ->where('group_id', $groupId)->where('project_id', $projectId)
            ->first();
        if ($runtime === null || $runtime->runtime_ref === '') {
            throw new AppException(404, '可配置路由的项目 Runtime 不存在');
        }
        return $runtime;
    }

    private function cluster(int $orgId, int $clusterId): Cluster
    {
        $cluster = Cluster::where('id', $clusterId)->where('org_id', $orgId)
            ->where('orchestrator_type', Cluster::ORCHESTRATOR_DOCKER_SWARM)->first();
        if ($cluster === null) {
            throw new AppException(404, 'Docker Swarm 集群不存在');
        }
        return $cluster;
    }
}

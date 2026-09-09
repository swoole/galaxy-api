<?php

/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */
namespace App\Controller;

use App\Exception\AppException;
use App\Model\Cluster;
use App\Model\ClusterAgentNode;
use App\Model\ProjectRuntime;
use App\Model\User;
use App\Services\Docker\AgentCredentialService;
use App\Services\Docker\AgentRelayService;
use App\Services\Docker\SwarmOverviewService;
use App\Services\Docker\SwarmImageInventoryCache;
use App\Services\Docker\SwarmTerminalService;
use App\Services\Docker\SwarmContainerExecService;
use App\Services\Docker\SwarmWebGatewayService;
use App\Services\Gateway\GatewayHttpMonitoringService;
use App\Services\Docker\SwarmPrometheusService;
use App\Services\Gateway\GatewayVhostService;
use App\Services\Project\ProjectRouteService;
use App\Services\Project\ProjectSwarmScope;
use App\Services\GroupResourceGrantService;
use App\Services\ManagedDomainService;
use App\Support\Functions;
use Hyperf\DbConnection\Db;
use Hyperf\Di\Annotation\Inject;

class ClusterController extends AbstractController
{
    #[Inject]
    protected SwarmOverviewService $swarmOverviewService;

    #[Inject]
    protected SwarmImageInventoryCache $swarmImageInventoryCache;

    #[Inject]
    protected AgentCredentialService $agentCredentialService;

    #[Inject]
    protected AgentRelayService $agentRelayService;

    #[Inject]
    protected SwarmTerminalService $swarmTerminalService;

    #[Inject]
    protected SwarmWebGatewayService $swarmWebGatewayService;

    #[Inject]
    protected GatewayHttpMonitoringService $gatewayHttpMonitoringService;

    #[Inject]
    protected SwarmPrometheusService $swarmPrometheusService;

    #[Inject]
    protected SwarmContainerExecService $swarmContainerExecService;

    #[Inject]
    protected GatewayVhostService $gatewayVhostService;

    #[Inject]
    protected ProjectRouteService $projectRouteService;

    #[Inject]
    protected ProjectSwarmScope $projectSwarmScope;

    #[Inject]
    protected GroupResourceGrantService $groupResourceGrants;

    #[Inject]
    protected ManagedDomainService $managedDomains;

    /**
     * @Inject
     * @var Cluster
     */
    #[Inject]
    protected $cluster;

    #[Inject]
    protected User $user;

    public function create()
    {
        $orgId = Functions::getContextValue('org_id');
        $params = $this->validate([
            'title' => 'required|string|max:20',
            'remark' => 'string|max:2000|nullable',
        ]);
        $params = Functions::arrNull2default($params, [
            'remark' => '',
        ]);

        $uid = (int) Functions::getLoginUser()->getId();
        $result = Db::transaction(function () use ($uid, $orgId, $params): array {
            $cluster = $this->cluster->createSwarmCluster($uid, $orgId, $params['title'], $params['remark'], 'agent-pending://cluster');
            $cluster->endpoint = sprintf('agent://%d', $cluster->id);
            $cluster->registration_status = 'pending';
            $cluster->agent_status = 'offline';
            $cluster->status = Cluster::STATUS_OFFLINE;
            $cluster->save();
            return [
                'cluster_id' => (int) $cluster->id,
                'connection_mode' => 'agent',
                'status' => 'offline',
            ];
        });

        return $this->success($result);
    }

    public function createSwarm()
    {
        return $this->create();
    }

    public function deleteSwarm()
    {
        return $this->delete();
    }

    public function swarmDeleteOverview()
    {
        return $this->getDeleteOverview();
    }

    public function swarmList()
    {
        return $this->index();
    }

    public function getDeleteOverview()
    {
        $orgId = Functions::getContextValue('org_id');
        $params = $this->validate([
            'cluster_id' => 'required|integer',
        ]);
        $uid = Functions::getLoginUser()->getId();

        $overview = $this->cluster->getDeleteOverview($uid, (int) $params['cluster_id'], $orgId);

        return $this->success($overview);
    }

    public function delete()
    {
        $orgId = Functions::getContextValue('org_id');
        $params = $this->validate([
            'cluster_id' => 'required|integer',
            'confirm_token' => 'required|string',
        ]);
        $uid = Functions::getLoginUser()->getId();

        $this->user->idConfirm($uid, $params['confirm_token']);

        $this->cluster->deleteCluster($uid, (int) $params['cluster_id'], $orgId);

        return $this->success();
    }

    public function simpleProfile()
    {
        $orgId = Functions::getContextValue('org_id');
        $params = $this->validate([
            'cluster_id' => 'required|integer',
        ]);
        
        $cluster = $this->cluster->simpleProfile($orgId, (int) $params['cluster_id']);

        return $this->success([
            'cluster' => $cluster,
        ]);
    }

    public function profile()
    {
        $orgId = Functions::getContextValue('org_id');
        $params = $this->validateAll([
            'cluster_id' => 'required|integer',
        ]);
        $uid = Functions::getLoginUser()->getId();

        $cluster = $this->cluster->profile((int) $params['cluster_id'], $orgId);

        return $this->success([
            'cluster' => $cluster,
        ]);
    }

    public function checkConnectivity()
    {
        $orgId = Functions::getContextValue('org_id');
        $params = $this->validate([
            'cluster_id' => 'required|integer',
        ]);
        /** @var Cluster $cluster */
        $cluster = $this->cluster->getCluster((int) $params['cluster_id'], $orgId);
        if ($cluster['orchestrator_type'] !== Cluster::ORCHESTRATOR_DOCKER_SWARM) {
            throw new AppException(422, '该集群不是 Docker Swarm 集群');
        }
        $this->projectSwarmScope->assertGrantedCluster($cluster);

        $online = $this->swarmOverviewService->ping($cluster);
        $cluster->status = $online ? Cluster::STATUS_READY : Cluster::STATUS_OFFLINE;
        $cluster->save();

        return $this->success([
            'online' => $online,
        ]);
    }

    public function swarmWebGateway()
    {
        $cluster = $this->resolveSwarmCluster();
        return $this->success($this->swarmWebGatewayService->profile($cluster));
    }

    public function swarmWebGatewayMetrics()
    {
        $cluster = $this->resolveSwarmCluster();
        $params = $this->validate([
            'hours' => 'nullable|integer|min:1|max:168',
        ]);
        return $this->success($this->gatewayHttpMonitoringService->report(
            $cluster,
            (int) ($params['hours'] ?? 24)
        ));
    }

    public function deploySwarmWebGateway()
    {
        $this->projectSwarmScope->assertClusterScope();
        $cluster = $this->resolveSwarmCluster();
        $params = $this->validate([
            'image' => 'required|string|max:1024',
            'socket_proxy_image' => 'required|string|max:1024',
            'network_name' => 'required|string|max:63',
            'control_network_name' => 'required|string|max:63',
            'http_port' => 'required|integer|min:1|max:65535',
            'https_port' => 'required|integer|min:1|max:65535',
            'publish_mode' => 'required|string|in:ingress,host',
            'replicas' => 'required|integer|min:1|max:10',
            'redirect_https' => 'nullable|boolean',
            'access_log_enabled' => 'nullable|boolean',
            'metrics_enabled' => 'nullable|boolean',
            'dashboard_enabled' => 'nullable|boolean',
            'dashboard_port' => 'nullable|integer|min:1|max:65535',
            'acme_enabled' => 'nullable|boolean',
            'acme_email' => 'nullable|string|max:320',
        ]);
        return $this->success($this->swarmWebGatewayService->deploy(
            (int) Functions::getLoginUser()->getId(),
            $cluster,
            $params
        ));
    }

    public function removeSwarmWebGateway()
    {
        $this->projectSwarmScope->assertClusterScope();
        $cluster = $this->resolveSwarmCluster();
        $this->swarmWebGatewayService->remove($cluster);
        return $this->success();
    }

    public function setSwarmWorkspaceDomain()
    {
        $this->projectSwarmScope->assertClusterScope();
        $params = $this->validate([
            'runtime_id' => 'nullable|integer|min:1',
            'hostname' => 'required|string|max:253',
            'certificate_id' => 'required|integer|min:1',
            'https_redirect' => 'nullable|boolean',
        ]);
        return $this->success($this->swarmWebGatewayService->setWorkspaceDomain(
            $this->resolveSwarmCluster(),
            (string) $params['hostname'],
            (int) $params['certificate_id'],
            (bool) ($params['https_redirect'] ?? true)
        ));
    }

    public function clearSwarmWorkspaceDomain()
    {
        $this->projectSwarmScope->assertClusterScope();
        return $this->success($this->swarmWebGatewayService->clearWorkspaceDomain(
            $this->resolveSwarmCluster()
        ));
    }

    public function swarmPrometheus()
    {
        return $this->success($this->swarmPrometheusService->profile($this->resolveSwarmCluster()));
    }

    public function deploySwarmPrometheus()
    {
        $params = Functions::arrNull2default($this->validate([
            'image' => 'nullable|string|max:1024',
            'scrape_interval' => 'nullable|integer|min:5|max:300',
            'retention_days' => 'nullable|integer|min:1|max:365',
        ]), ['image' => 'prom/prometheus:v3.2.1',
            'scrape_interval' => 15, 'retention_days' => 15]);
        return $this->success($this->swarmPrometheusService->deploy(
            (int) Functions::getLoginUser()->getId(), $this->resolveSwarmCluster(), $params
        ));
    }

    public function swarmPrometheusQuery()
    {
        $params = $this->validate([
            'queries' => 'required|array|min:1|max:10',
            'queries.*' => 'string|max:2000',
            'range' => 'nullable|string|max:16',
            'step' => 'nullable|integer|min:5|max:3600',
        ]);
        $cluster = $this->resolveSwarmCluster();
        $seconds = $this->parsePrometheusRange($params['range'] ?? '1h');
        $step = (int) ($params['step'] ?? 60);
        $end = time();
        $start = $end - $seconds;
        $results = [];
        foreach ($params['queries'] as $key => $query) {
            $results[$key] = $this->swarmPrometheusService->query($cluster, (string) $query, $start, $end, $step);
        }
        return $this->success(['results' => $results, 'start' => $start, 'end' => $end, 'step' => $step]);
    }

    private function parsePrometheusRange(string $range): int
    {
        if (preg_match('/^(\d+)(s|m|h|d|w)$/', trim($range), $matches)) {
            $unit = ['s' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400, 'w' => 604800][$matches[2]];
            return (int) $matches[1] * $unit;
        }
        return 3600;
    }

    public function swarmWebGatewayVhosts()
    {
        $cluster = $this->resolveSwarmCluster();
        $page = (int) $this->request->input('page', 1);
        $pageSize = (int) $this->request->input('pagesize', 20);
        // A project-scoped request must not leak routes from sibling projects.
        // Cluster administrator pages have no project context and keep the full view.
        $projectId = (int) Functions::getContextValue('project_id', false, 0);
        $groupId = $projectId > 0 ? (int) Functions::getContextValue('group_id') : 0;
        return $this->success($this->gatewayVhostService->list(
            (int) $cluster->org_id, (int) $cluster->id, max(1, $page), min(100, max(1, $pageSize)),
            $projectId, $groupId
        ));
    }

    public function swarmWebGatewayVhostCreate()
    {
        $cluster = $this->resolveSwarmCluster();
        $params = $this->validate([
            'hostname' => 'required|string|max:253',
            'path_prefix' => 'nullable|string|max:1024',
            'path_match' => 'nullable|string|in:prefix,exact,regex',
            'methods' => 'nullable|array|max:9', 'methods.*' => 'string|max:16',
            'target_service' => 'required|string|max:255',
            'target_port' => 'required|integer|min:1|max:65535',
            'upstream_scheme' => 'nullable|string|in:http,https',
            'pass_host_header' => 'nullable|boolean',
            'entrypoint' => 'nullable|string|max:64',
            'tls_enabled' => 'nullable|boolean',
            'certificate_id' => 'nullable|integer',
            'https_redirect' => 'nullable|boolean',
            'rewrite_type' => 'nullable|string|in:none,strip_prefix,replace_path_regex',
            'rewrite_pattern' => 'nullable|string|max:1024',
            'rewrite_replacement' => 'nullable|string|max:1024',
            'rewrites' => 'nullable|array|max:20',
            'rewrites.*.rewrite_type' => 'required|string|in:strip_prefix,replace_path_regex',
            'rewrites.*.rewrite_pattern' => 'required|string|max:1024',
            'rewrites.*.rewrite_replacement' => 'nullable|string|max:1024',
            'ip_allowlist' => 'nullable|array|max:100', 'ip_allowlist.*' => 'string|max:64',
            'ip_denylist' => 'nullable|array|max:100', 'ip_denylist.*' => 'string|max:64',
            'rate_limit_average' => 'nullable|integer|min:0|max:1000000',
            'rate_limit_burst' => 'nullable|integer|min:0|max:1000000',
            'rate_limit_period_seconds' => 'nullable|integer|min:1|max:86400',
            'max_inflight_requests' => 'nullable|integer|min:0|max:1000000',
            'retry_attempts' => 'nullable|integer|min:0|max:10',
            'retry_initial_interval_ms' => 'nullable|integer|min:10|max:60000',
            'dial_timeout_ms' => 'nullable|integer|min:0|max:600000',
            'response_header_timeout_ms' => 'nullable|integer|min:0|max:3600000',
            'idle_connection_timeout_ms' => 'nullable|integer|min:0|max:3600000',
            'security_headers_enabled' => 'nullable|boolean',
            'compress_enabled' => 'nullable|boolean',
            'request_body_limit_bytes' => 'nullable|integer|min:0|max:10737418240',
            'custom_request_headers' => 'nullable|array|max:100',
            'custom_response_headers' => 'nullable|array|max:100',
            'cors_enabled' => 'nullable|boolean',
            'cors_allow_origins' => 'nullable|array|max:100', 'cors_allow_origins.*' => 'string|max:255',
            'cors_allow_methods' => 'nullable|array|max:9', 'cors_allow_methods.*' => 'string|max:16',
            'cors_allow_headers' => 'nullable|array|max:100', 'cors_allow_headers.*' => 'string|max:255',
            'cors_allow_credentials' => 'nullable|boolean',
            'cors_max_age_seconds' => 'nullable|integer|min:0|max:86400',
            'circuit_breaker_expression' => 'nullable|string|max:255',
            'healthcheck_path' => 'nullable|string|max:1024',
            'healthcheck_interval_ms' => 'nullable|integer|min:1000|max:3600000',
            'healthcheck_timeout_ms' => 'nullable|integer|min:100|max:600000',
            'sticky_cookie_enabled' => 'nullable|boolean',
            'sticky_cookie_name' => 'nullable|string|max:64',
            'priority' => 'nullable|integer|min:0|max:100000',
            'enabled' => 'nullable|boolean',
        ]);
        $projectId = $this->projectSwarmScope->projectId();
        if ($projectId !== null) {
            $this->projectSwarmScope->assertService($cluster, (string) $params['target_service']);
            $this->managedDomains->assertGroupCanUse(
                (int) $cluster->org_id, (int) Functions::getContextValue('group_id'), (string) $params['hostname']
            );
            $runtimeId = (int) ($params['runtime_id'] ?? 0);
            $runtime = ProjectRuntime::where('id', $runtimeId)
                ->where('org_id', (int) $cluster->org_id)
                ->where('group_id', (int) Functions::getContextValue('group_id'))
                ->where('project_id', $projectId)
                ->where('cluster_id', (int) $cluster->id)
                ->where('orchestrator_type', Cluster::ORCHESTRATOR_DOCKER_SWARM)
                ->first();
            if ($runtime === null || (string) $runtime->service_name !== (string) $params['target_service']) {
                throw new AppException(422, '请选择当前项目中有效的 Swarm 运行实例');
            }
            unset($params['runtime_id'], $params['target_service'], $params['rewrites']);
            return $this->success([
                'route' => $this->projectRouteService->create(
                    (int) Functions::getLoginUser()->getId(),
                    (int) $cluster->org_id,
                    (int) Functions::getContextValue('group_id'),
                    $projectId,
                    (int) $runtime->id,
                    $params
                ),
            ]);
        }
        unset($params['runtime_id']);
        return $this->success([
            'vhost' => $this->gatewayVhostService->create(
                (int) $cluster->org_id, (int) $cluster->id,
                (int) Functions::getLoginUser()->getId(), $params
            ),
        ]);
    }

    public function swarmWebGatewayVhostUpdate()
    {
        $cluster = $this->resolveSwarmCluster();
        $params = $this->validate([
            'vhost_id' => 'required|integer',
            'source' => 'nullable|string|in:gateway,project',
            'hostname' => 'nullable|string|max:253',
            'path_prefix' => 'nullable|string|max:1024',
            'path_match' => 'nullable|string|in:prefix,exact,regex',
            'methods' => 'nullable|array|max:9', 'methods.*' => 'string|max:16',
            'target_service' => 'nullable|string|max:255',
            'target_port' => 'nullable|integer|min:1|max:65535',
            'upstream_scheme' => 'nullable|string|in:http,https',
            'pass_host_header' => 'nullable|boolean',
            'entrypoint' => 'nullable|string|max:64',
            'tls_enabled' => 'nullable|boolean',
            'certificate_id' => 'nullable|integer',
            'https_redirect' => 'nullable|boolean',
            'rewrite_type' => 'nullable|string|in:none,strip_prefix,replace_path_regex',
            'rewrite_pattern' => 'nullable|string|max:1024',
            'rewrite_replacement' => 'nullable|string|max:1024',
            'rewrites' => 'nullable|array|max:20',
            'rewrites.*.rewrite_type' => 'required|string|in:strip_prefix,replace_path_regex',
            'rewrites.*.rewrite_pattern' => 'required|string|max:1024',
            'rewrites.*.rewrite_replacement' => 'nullable|string|max:1024',
            'ip_allowlist' => 'nullable|array|max:100', 'ip_allowlist.*' => 'string|max:64',
            'ip_denylist' => 'nullable|array|max:100', 'ip_denylist.*' => 'string|max:64',
            'rate_limit_average' => 'nullable|integer|min:0|max:1000000',
            'rate_limit_burst' => 'nullable|integer|min:0|max:1000000',
            'rate_limit_period_seconds' => 'nullable|integer|min:1|max:86400',
            'max_inflight_requests' => 'nullable|integer|min:0|max:1000000',
            'retry_attempts' => 'nullable|integer|min:0|max:10',
            'retry_initial_interval_ms' => 'nullable|integer|min:10|max:60000',
            'dial_timeout_ms' => 'nullable|integer|min:0|max:600000',
            'response_header_timeout_ms' => 'nullable|integer|min:0|max:3600000',
            'idle_connection_timeout_ms' => 'nullable|integer|min:0|max:3600000',
            'security_headers_enabled' => 'nullable|boolean',
            'compress_enabled' => 'nullable|boolean',
            'request_body_limit_bytes' => 'nullable|integer|min:0|max:10737418240',
            'custom_request_headers' => 'nullable|array|max:100',
            'custom_response_headers' => 'nullable|array|max:100',
            'cors_enabled' => 'nullable|boolean',
            'cors_allow_origins' => 'nullable|array|max:100', 'cors_allow_origins.*' => 'string|max:255',
            'cors_allow_methods' => 'nullable|array|max:9', 'cors_allow_methods.*' => 'string|max:16',
            'cors_allow_headers' => 'nullable|array|max:100', 'cors_allow_headers.*' => 'string|max:255',
            'cors_allow_credentials' => 'nullable|boolean',
            'cors_max_age_seconds' => 'nullable|integer|min:0|max:86400',
            'circuit_breaker_expression' => 'nullable|string|max:255',
            'healthcheck_path' => 'nullable|string|max:1024',
            'healthcheck_interval_ms' => 'nullable|integer|min:1000|max:3600000',
            'healthcheck_timeout_ms' => 'nullable|integer|min:100|max:600000',
            'sticky_cookie_enabled' => 'nullable|boolean',
            'sticky_cookie_name' => 'nullable|string|max:64',
            'priority' => 'nullable|integer|min:0|max:100000',
            'enabled' => 'nullable|boolean',
        ]);
        $vhostId = (int) $params['vhost_id'];
        $source = (string) ($params['source'] ?? 'gateway');
        unset($params['vhost_id']);
        unset($params['source']);
        $projectId = $this->projectSwarmScope->projectId();
        if ($projectId !== null && isset($params['hostname'])) {
            $this->managedDomains->assertGroupCanUse(
                (int) $cluster->org_id, (int) Functions::getContextValue('group_id'), (string) $params['hostname']
            );
        }
        if ($projectId !== null && ! isset($params['hostname'])) {
            $this->managedDomains->assertGroupCanUseRoute(
                (int) $cluster->org_id, (int) Functions::getContextValue('group_id'), $projectId,
                (int) $cluster->id, $vhostId, $source
            );
        }
        if ($source === 'project') {
            return $this->success(['route' => $this->projectRouteService->updateFromGateway(
                (int) $cluster->org_id, (int) $cluster->id, $vhostId, $params,
                $projectId === null ? null : (int) Functions::getContextValue('group_id'), $projectId
            )]);
        }
        if ($projectId !== null) {
            $this->projectSwarmScope->assertGatewayVhost($cluster, $vhostId);
            if (isset($params['target_service'])) {
                $this->projectSwarmScope->assertService($cluster, (string) $params['target_service']);
            }
        }
        return $this->success([
            'vhost' => $this->gatewayVhostService->update(
                (int) $cluster->org_id, (int) $cluster->id, $vhostId, $params
            ),
        ]);
    }

    public function swarmWebGatewayVhostAssignCertificates()
    {
        $cluster = $this->resolveSwarmCluster();
        $params = $this->validate([
            'assignments' => 'required|array|min:1|max:100',
            'assignments.*.vhost_id' => 'required|integer|min:1|distinct',
            'assignments.*.certificate_id' => 'required|integer|min:1',
        ]);
        if ($this->projectSwarmScope->projectId() !== null) {
            throw new AppException(403, '批量证书切换仅允许集群管理员执行');
        }
        return $this->success([
            'vhosts' => $this->gatewayVhostService->assignCertificates(
                (int) $cluster->org_id,
                (int) $cluster->id,
                (array) $params['assignments']
            ),
        ]);
    }

    public function swarmWebGatewayVhostDelete()
    {
        $cluster = $this->resolveSwarmCluster();
        $params = $this->validate([
            'vhost_id' => 'required|integer', 'source' => 'nullable|string|in:gateway,project',
        ]);
        if (($params['source'] ?? 'gateway') === 'project') {
            $this->projectRouteService->deleteFromGateway(
                (int) $cluster->org_id, (int) $cluster->id, (int) $params['vhost_id'],
                $this->projectSwarmScope->projectId() === null ? null : (int) Functions::getContextValue('group_id'),
                $this->projectSwarmScope->projectId()
            );
            return $this->success();
        }
        if ($this->projectSwarmScope->projectId() !== null) {
            $this->projectSwarmScope->assertGatewayVhost($cluster, (int) $params['vhost_id']);
        }
        $this->gatewayVhostService->delete(
            (int) $cluster->org_id, (int) $cluster->id, (int) $params['vhost_id']
        );
        return $this->success();
    }

    public function swarmWebGatewayServices()
    {
        $cluster = $this->resolveSwarmCluster();
        return $this->success([
            'services' => $this->swarmOverviewService->listServices(
                $cluster, $this->projectSwarmScope->serviceReferences($cluster)
            ),
        ]);
    }

    private function resolveSwarmCluster(): Cluster
    {
        $orgId = Functions::getContextValue('org_id');
        $params = $this->validate([
            'cluster_id' => 'required|integer',
        ]);
        /** @var Cluster $cluster */
        $cluster = $this->cluster->getCluster((int) $params['cluster_id'], $orgId);
        if ($cluster['orchestrator_type'] !== Cluster::ORCHESTRATOR_DOCKER_SWARM) {
            throw new AppException(422, '该集群不是 Docker Swarm 集群');
        }
        // Even an organization administrator must remain inside the selected
        // project when using a project-scoped layout/API URL.
        if ($this->projectSwarmScope->projectId() !== null
            && ! in_array(strtoupper($this->request->getMethod()), ['GET', 'HEAD', 'OPTIONS'], true)) {
            $serviceId = trim((string) $this->request->input('service_id', ''));
            if ($serviceId !== '') {
                $this->projectSwarmScope->assertService($cluster, $serviceId);
            }
            foreach ((array) $this->request->input('service_ids', []) as $candidate) {
                $this->projectSwarmScope->assertService($cluster, (string) $candidate);
            }
            $containerId = trim((string) $this->request->input('container_id', ''));
            if ($containerId !== '') {
                $this->projectSwarmScope->assertContainer($cluster, $containerId);
            }
        }
        return $cluster;
    }

    private function routeSwarmClusterToNode(Cluster $cluster): Cluster
    {
        $nodeId = trim((string) $this->request->input('node_id', ''));
        if ($nodeId === '') {
            return $cluster;
        }
        if (! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,127}$/', $nodeId)) {
            throw new AppException(422, 'node_id 格式无效');
        }
        if (! ClusterAgentNode::where('cluster_id', (int) $cluster->id)->where('node_id', $nodeId)->exists()) {
            throw new AppException(404, '目标节点不属于当前集群或尚未注册 Agent');
        }
        $nodeCluster = clone $cluster;
        $nodeCluster->endpoint = 'agent://' . (int) $cluster->id . '/' . $nodeId;
        return $nodeCluster;
    }

    private function resourceNodeId(array $nodes): string
    {
        $requested = trim((string) $this->request->input('node_id', ''));
        if ($requested !== '') {
            foreach ($nodes as $node) {
                if ((string) ($node['id'] ?? '') === $requested) {
                    return $requested;
                }
            }
            throw new AppException(422, '目标节点不存在于当前 Swarm');
        }
        foreach ($nodes as $node) {
            if (($node['role'] ?? '') === 'manager' && ($node['agent_online'] ?? false)) {
                return (string) $node['id'];
            }
        }
        foreach ($nodes as $node) {
            if (($node['role'] ?? '') === 'manager') {
                return (string) $node['id'];
            }
        }
        throw new AppException(503, '当前 Swarm 没有可用的 Manager 节点');
    }

    public function swarmContainers()
    {
        $cluster = $this->resolveSwarmCluster();
        $nodes = $this->swarmOverviewService->resourceNodes($cluster);
        $nodeId = $this->resourceNodeId($nodes);
        return $this->success([
            'containers' => $this->swarmOverviewService->listContainers(
                $cluster,
                $this->projectSwarmScope->serviceReferences($cluster),
                $nodeId
            ),
            'nodes' => $nodes,
            'selected_node_id' => $nodeId,
        ]);
    }

    public function swarmConfigs()
    {
        $cluster = $this->resolveSwarmCluster();
        return $this->success([
            'configs' => $this->swarmOverviewService->listConfigs($cluster),
        ]);
    }

    public function swarmConfig()
    {
        $cluster = $this->resolveSwarmCluster();
        $configId = $this->request->route('config_id');
        if (empty($configId)) {
            throw new AppException(422, 'config id 字段是必须的');
        }
        return $this->success([
            'config' => $this->swarmOverviewService->getConfig($cluster, $configId),
        ]);
    }

    public function swarmSecrets()
    {
        $cluster = $this->resolveSwarmCluster();
        return $this->success([
            'secrets' => $this->swarmOverviewService->listSecrets($cluster),
        ]);
    }

    public function swarmConfigCreate()
    {
        $cluster = $this->resolveSwarmCluster();
        $params = $this->validate([
            'name' => 'required|string|max:64',
            'content' => 'required|string',
            'labels' => 'nullable|array',
        ]);
        return $this->success(
            $this->swarmOverviewService->createConfig(
                $cluster,
                $params['name'],
                $params['content'],
                $params['labels'] ?? []
            )
        );
    }

    public function swarmSecretCreate()
    {
        $cluster = $this->resolveSwarmCluster();
        $params = $this->validate([
            'name' => 'required|string|max:64',
            'content' => 'required|string',
            'labels' => 'nullable|array',
        ]);
        return $this->success(
            $this->swarmOverviewService->createSecret(
                $cluster,
                $params['name'],
                $params['content'],
                $params['labels'] ?? []
            )
        );
    }

    public function swarmConfigDelete()
    {
        $cluster = $this->resolveSwarmCluster();
        $params = $this->validate(['config_id' => 'required|string|max:64']);
        $this->swarmOverviewService->deleteConfig($cluster, $params['config_id']);
        return $this->success();
    }

    public function swarmSecretDelete()
    {
        $cluster = $this->resolveSwarmCluster();
        $params = $this->validate(['secret_id' => 'required|string|max:64']);
        $this->swarmOverviewService->deleteSecret($cluster, $params['secret_id']);
        return $this->success();
    }

    public function swarmContainerLogs()
    {
        $cluster = $this->routeSwarmClusterToNode($this->resolveSwarmCluster());
        $params = $this->validate([
            'container_id' => 'required|string|max:64',
            'tail' => 'integer|min:1|max:10000',
        ]);
        $tail = (int) ($params['tail'] ?? 100);
        $this->projectSwarmScope->assertContainer($cluster, $params['container_id']);
        $logs = $this->swarmOverviewService->containerLogs($cluster, $params['container_id'], $tail);
        return $this->success(['logs' => $logs]);
    }

    public function swarmServiceLogs()
    {
        $cluster = $this->resolveSwarmCluster();
        $params = $this->validate([
            'service_id' => 'required|string|max:64',
            'tail' => 'integer|min:1|max:10000',
        ]);
        $tail = (int) ($params['tail'] ?? 100);
        $this->projectSwarmScope->assertService($cluster, $params['service_id']);
        $logs = $this->swarmOverviewService->serviceLogs($cluster, $params['service_id'], $tail);
        return $this->success(['logs' => $logs]);
    }

    public function createSwarmTerminalTicket()
    {
        $cluster = $this->routeSwarmClusterToNode($this->resolveSwarmCluster());
        $params = $this->validate([
            'container_id' => 'required|string|max:64',
        ]);
        $this->projectSwarmScope->assertContainer($cluster, $params['container_id']);
        return $this->success($this->swarmTerminalService->issueTicket(
            Functions::getLoginUser()->getId(),
            (int) Functions::getContextValue('org_id'),
            $cluster,
            $params['container_id'],
            (int) Functions::getContextValue('project_id', false, 0)
        ));
    }

    public function swarmTerminalSshCommand()
    {
        $cluster = $this->routeSwarmClusterToNode($this->resolveSwarmCluster());
        $params = $this->validate([
            'container_id' => 'required|string|max:64',
        ]);
        $this->projectSwarmScope->assertContainer($cluster, $params['container_id']);
        return $this->success($this->swarmTerminalService->sshConnection(
            (int) Functions::getContextValue('org_id'),
            $cluster,
            $params['container_id'],
            (int) Functions::getContextValue('project_id', false, 0)
        ));
    }

    public function swarmServices()
    {
        $cluster = $this->resolveSwarmCluster();
        return $this->success([
            'services' => $this->swarmOverviewService->listServices(
                $cluster, $this->projectSwarmScope->serviceReferences($cluster)
            ),
        ]);
    }

    public function swarmContainerInspect()
    {
        $cluster = $this->routeSwarmClusterToNode($this->resolveSwarmCluster());
        $params = $this->validate([
            'container_id' => 'required|string|max:64',
        ]);
        $this->projectSwarmScope->assertContainer($cluster, $params['container_id']);
        return $this->success($this->swarmOverviewService->inspectContainer($cluster, $params['container_id']));
    }

    public function swarmContainerStart()
    {
        $cluster = $this->routeSwarmClusterToNode($this->resolveSwarmCluster());
        $params = $this->validate([
            'container_id' => 'required|string|max:64',
        ]);
        $this->projectSwarmScope->assertContainer($cluster, $params['container_id']);
        $this->swarmOverviewService->startContainer($cluster, $params['container_id']);
        return $this->success();
    }

    public function swarmContainerStop()
    {
        $cluster = $this->routeSwarmClusterToNode($this->resolveSwarmCluster());
        $params = $this->validate([
            'container_id' => 'required|string|max:64',
        ]);
        $this->projectSwarmScope->assertContainer($cluster, $params['container_id']);
        $this->swarmOverviewService->stopContainer($cluster, $params['container_id']);
        return $this->success();
    }

    public function swarmContainerRestart()
    {
        $cluster = $this->routeSwarmClusterToNode($this->resolveSwarmCluster());
        $params = $this->validate([
            'container_id' => 'required|string|max:64',
        ]);
        $this->projectSwarmScope->assertContainer($cluster, $params['container_id']);
        $this->swarmOverviewService->restartContainer($cluster, $params['container_id']);
        return $this->success();
    }

    public function swarmContainerRemove()
    {
        $cluster = $this->routeSwarmClusterToNode($this->resolveSwarmCluster());
        $params = $this->validate([
            'container_id' => 'required|string|max:64',
        ]);
        $this->projectSwarmScope->assertContainer($cluster, $params['container_id']);
        $this->swarmOverviewService->removeContainer($cluster, $params['container_id']);
        return $this->success();
    }

    public function swarmContainerPause()
    {
        $cluster = $this->routeSwarmClusterToNode($this->resolveSwarmCluster());
        $params = $this->validate([
            'container_id' => 'required|string|max:64',
        ]);
        $this->projectSwarmScope->assertContainer($cluster, $params['container_id']);
        $this->swarmOverviewService->pauseContainer($cluster, $params['container_id']);
        return $this->success();
    }

    public function swarmContainerResume()
    {
        $cluster = $this->routeSwarmClusterToNode($this->resolveSwarmCluster());
        $params = $this->validate([
            'container_id' => 'required|string|max:64',
        ]);
        $this->projectSwarmScope->assertContainer($cluster, $params['container_id']);
        $this->swarmOverviewService->resumeContainer($cluster, $params['container_id']);
        return $this->success();
    }

    public function swarmContainerKill()
    {
        $cluster = $this->routeSwarmClusterToNode($this->resolveSwarmCluster());
        $params = $this->validate([
            'container_id' => 'required|string|max:64',
        ]);
        $this->projectSwarmScope->assertContainer($cluster, $params['container_id']);
        $this->swarmOverviewService->killContainer($cluster, $params['container_id']);
        return $this->success();
    }

    public function swarmContainerStats()
    {
        $cluster = $this->routeSwarmClusterToNode($this->resolveSwarmCluster());
        $params = $this->validate([
            'container_id' => 'required|string|max:64',
        ]);
        $this->projectSwarmScope->assertContainer($cluster, $params['container_id']);
        return $this->success($this->swarmOverviewService->containerStats($cluster, $params['container_id']));
    }

    public function swarmContainerTop()
    {
        $cluster = $this->routeSwarmClusterToNode($this->resolveSwarmCluster());
        $params = $this->validate([
            'container_id' => 'required|string|max:64',
        ]);
        $this->projectSwarmScope->assertContainer($cluster, $params['container_id']);
        return $this->success($this->swarmOverviewService->containerTop($cluster, $params['container_id']));
    }

    public function swarmContainerExec()
    {
        $cluster = $this->routeSwarmClusterToNode($this->resolveSwarmCluster());
        $params = $this->validate([
            'container_id' => 'required|string|max:64',
            'command' => 'required|array|min:1',
            'working_dir' => 'nullable|string|max:1024',
        ]);
        $this->projectSwarmScope->assertContainer($cluster, $params['container_id']);
        return $this->success($this->swarmContainerExecService->executeOnContainer(
            $cluster,
            $params['container_id'],
            $params['command'],
            [],
            $params['working_dir'] ?? null
        ));
    }

    public function swarmContainerListFiles()
    {
        $cluster = $this->routeSwarmClusterToNode($this->resolveSwarmCluster());
        $params = $this->validate([
            'container_id' => 'required|string|max:64',
            'path' => 'nullable|string|max:1024',
        ]);
        $this->projectSwarmScope->assertContainer($cluster, $params['container_id']);
        $fileResult = $this->swarmContainerExecService->listFiles(
            $cluster,
            $params['container_id'],
            $params['path'] ?? '/'
        );
        return $this->success([
            'pwd' => $fileResult['pwd'] ?? '/',
            'files' => $fileResult['files'] ?? [],
        ]);
    }

    public function swarmContainerReadFile()
    {
        $cluster = $this->routeSwarmClusterToNode($this->resolveSwarmCluster());
        $params = $this->validate([
            'container_id' => 'required|string|max:64',
            'path' => 'required|string|max:1024',
        ]);
        $this->projectSwarmScope->assertContainer($cluster, $params['container_id']);
        return $this->success($this->swarmContainerExecService->readFile(
            $cluster,
            $params['container_id'],
            $params['path']
        ));
    }

    public function swarmContainerWriteFile()
    {
        $cluster = $this->routeSwarmClusterToNode($this->resolveSwarmCluster());
        $params = $this->validate([
            'container_id' => 'required|string|max:64',
            'path' => 'required|string|max:1024',
            'content' => 'required|string',
            'encoding' => 'string|in:base64',
        ]);
        $this->projectSwarmScope->assertContainer($cluster, $params['container_id']);
        $this->swarmContainerExecService->writeFile(
            $cluster,
            $params['container_id'],
            $params['path'],
            $params['content'],
            $params['encoding'] ?? 'base64'
        );
        return $this->success();
    }

    public function swarmImages()
    {
        $cluster = $this->resolveSwarmCluster();
        $nodes = $this->swarmOverviewService->resourceNodes($cluster);
        $nodeId = $this->resourceNodeId($nodes);
        $images = $this->swarmOverviewService->listImages($cluster, $nodeId);
        $this->swarmImageInventoryCache->store((int) $cluster->id, $nodeId, $images);
        return $this->success([
            'images' => $images,
            'nodes' => $nodes,
            'selected_node_id' => $nodeId,
        ]);
    }

    public function swarmImageInfo()
    {
        $cluster = $this->resolveSwarmCluster();
        $params = $this->validate([
            'image' => 'required|string|max:512',
            'node_id' => 'nullable|string|max:128',
        ]);
        $nodeId = $this->swarmImageInventoryCache->resolveNodeId(
            (int) $cluster->id,
            trim((string) ($params['node_id'] ?? ''))
        );
        return $this->success($this->swarmImageInventoryCache->imageInfo(
            (int) $cluster->id,
            $nodeId,
            $params['image']
        ));
    }

    public function swarmImageRemove()
    {
        $baseCluster = $this->resolveSwarmCluster();
        $cluster = $this->routeSwarmClusterToNode($baseCluster);
        $params = $this->validate([
            'image_id' => 'required|string|max:256',
        ]);
        $this->swarmOverviewService->removeImage($cluster, $params['image_id']);
        $this->swarmImageInventoryCache->forget(
            (int) $baseCluster->id,
            $this->swarmImageInventoryCache->resolveNodeId(
                (int) $baseCluster->id,
                trim((string) $this->request->input('node_id', ''))
            )
        );
        return $this->success();
    }

    public function swarmImageRun()
    {
        $cluster = $this->resolveSwarmCluster();
        $params = $this->validate([
            'image' => 'required|string|max:512',
            'name' => 'string|max:63',
            'replicas' => 'integer|min:1|max:100',
            'command' => 'string|max:4096',
            'env' => 'array',
            'env.*' => 'string|max:4096',
            'ports' => 'array',
            'ports.*.container_port' => 'required|integer|min:1|max:65535',
            'ports.*.host_port' => 'integer|min:0|max:65535',
            'ports.*.protocol' => 'in:tcp,udp',
            'volumes' => 'array',
            'volumes.*.type' => 'in:bind,volume',
            'volumes.*.source' => 'string|max:512',
            'volumes.*.target' => 'required|string|max:512',
            'volumes.*.mode' => 'in:rw,ro',
            'network' => 'string|max:64',
            'restart' => 'in:none,any,on-failure',
            'restart_max_attempts' => 'integer|min:1|max:1000',
            'cpu' => 'numeric|min:0|max:1000',
            'memory' => 'integer|min:0|max:1099511627776',
        ]);
        return $this->success($this->swarmOverviewService->runImageAsService($cluster, $params));
    }

    public function swarmImagePull()
    {
        $baseCluster = $this->resolveSwarmCluster();
        $cluster = $this->routeSwarmClusterToNode($baseCluster);
        $orgId = Functions::getContextValue('org_id');
        $params = $this->validate([
            'image' => 'required|string|max:512',
            'registry_id' => 'nullable|integer|min:0',
        ]);
        $this->swarmOverviewService->pullImage(
            $cluster,
            (int) $orgId,
            $params['image'],
            (int) ($params['registry_id'] ?? 0)
        );
        $this->swarmImageInventoryCache->forget(
            (int) $baseCluster->id,
            $this->swarmImageInventoryCache->resolveNodeId(
                (int) $baseCluster->id,
                trim((string) $this->request->input('node_id', ''))
            )
        );
        return $this->success();
    }

    public function swarmNetworks()
    {
        $cluster = $this->resolveSwarmCluster();
        return $this->success([
            'networks' => $this->swarmOverviewService->listNetworks($cluster),
        ]);
    }

    public function swarmNetworkCreate()
    {
        $cluster = $this->resolveSwarmCluster();
        $params = $this->validate(['name' => 'required|string']);
        return $this->success([
            'network' => $this->swarmOverviewService->createNetwork($cluster, $params['name']),
        ]);
    }

    public function swarmNetworkUpdate()
    {
        $cluster = $this->resolveSwarmCluster();
        $params = $this->validate([
            'id' => 'required|string',
            'attachable' => 'nullable|boolean',
            'internal' => 'nullable|boolean',
            'labels' => 'nullable|array',
        ]);
        $config = array_filter([
            'attachable' => $params['attachable'] ?? null,
            'internal' => $params['internal'] ?? null,
            'labels' => $params['labels'] ?? null,
        ], fn ($v) => $v !== null);
        $this->swarmOverviewService->updateNetwork($cluster, $params['id'], $config);
        return $this->success();
    }

    public function swarmNetworkDelete()
    {
        $cluster = $this->resolveSwarmCluster();
        $params = $this->validate(['id' => 'required|string']);
        $this->swarmOverviewService->deleteNetwork($cluster, $params['id']);
        return $this->success();
    }

    public function swarmNetworkContainers()
    {
        $cluster = $this->resolveSwarmCluster();
        $params = $this->validate(['id' => 'required|string']);
        return $this->success([
            'containers' => $this->swarmOverviewService->networkContainers($cluster, $params['id']),
        ]);
    }

    public function swarmNetworkDisconnect()
    {
        $cluster = $this->resolveSwarmCluster();
        $params = $this->validate([
            'id' => 'required|string',
            'container_id' => 'required|string',
            'force' => 'nullable|boolean',
        ]);
        $this->swarmOverviewService->disconnectNetworkContainer(
            $cluster,
            $params['id'],
            $params['container_id'],
            (bool) ($params['force'] ?? false)
        );
        return $this->success();
    }

    public function swarmVolumes()
    {
        $cluster = $this->resolveSwarmCluster();
        $nodes = $this->swarmOverviewService->resourceNodes($cluster);
        $nodeId = $this->resourceNodeId($nodes);
        return $this->success([
            'volumes' => $this->swarmOverviewService->listVolumes($cluster, $nodeId),
            'nodes' => $nodes,
            'selected_node_id' => $nodeId,
        ]);
    }

    public function swarmVolumeCreate()
    {
        $cluster = $this->routeSwarmClusterToNode($this->resolveSwarmCluster());
        $params = $this->validate([
            'name' => 'required|string|max:64',
            'driver' => 'string|in:local',
            'driver_opts' => 'nullable|array',
        ]);
        return $this->success([
            'volume' => $this->swarmOverviewService->createVolume(
                $cluster,
                $params['name'],
                $params['driver'] ?? 'local',
                $params['driver_opts'] ?? []
            ),
        ]);
    }

    public function swarmVolumeRemove()
    {
        $cluster = $this->routeSwarmClusterToNode($this->resolveSwarmCluster());
        $params = $this->validate([
            'name' => 'required|string|max:64',
            'force' => 'nullable|boolean',
        ]);
        $this->swarmOverviewService->removeVolume(
            $cluster,
            $params['name'],
            (bool) ($params['force'] ?? false)
        );
        return $this->success();
    }

    public function swarmServiceInspect()
    {
        $params = $this->validate([
            'service_id' => 'required|string|max:64',
        ]);
        $cluster = $this->resolveSwarmCluster();
        $this->projectSwarmScope->assertService($cluster, $params['service_id']);
        return $this->success($this->swarmOverviewService->inspectService(
            $cluster,
            $params['service_id']
        ));
    }

    public function swarmServiceScale()
    {
        $params = $this->validate([
            'service_id' => 'required|string|max:64',
            'replicas' => 'required|integer|min:0',
        ]);
        $this->swarmOverviewService->scaleService(
            $this->resolveSwarmCluster(),
            $params['service_id'],
            (int) $params['replicas']
        );
        return $this->success();
    }

    public function swarmServiceForceUpdate()
    {
        $params = $this->validate([
            'service_id' => 'required|string|max:64',
        ]);
        $this->swarmOverviewService->forceUpdateService(
            $this->resolveSwarmCluster(),
            $params['service_id']
        );
        return $this->success();
    }

    public function swarmServiceRollback()
    {
        $params = $this->validate([
            'service_id' => 'required|string|max:64',
        ]);
        $this->swarmOverviewService->rollbackService(
            $this->resolveSwarmCluster(),
            $params['service_id']
        );
        return $this->success();
    }

    public function swarmServiceRemove()
    {
        $params = $this->validate([
            'service_id' => 'required|string|max:64',
        ]);
        $this->swarmOverviewService->removeService(
            $this->resolveSwarmCluster(),
            $params['service_id']
        );
        return $this->success();
    }

    public function swarmServicePrune()
    {
        $params = $this->validate([
            'service_ids' => 'required|array|min:1',
            'service_ids.*' => 'string|max:64',
        ]);
        $result = $this->swarmOverviewService->pruneServices(
            $this->resolveSwarmCluster(),
            $params['service_ids']
        );
        return $this->success($result);
    }

    public function swarmServiceChangeNetwork()
    {
        $params = $this->validate([
            'service_id' => 'required|string|max:64',
            'network_names' => 'required|array|min:0',
        ]);
        $this->swarmOverviewService->updateServiceNetworks(
            $this->resolveSwarmCluster(),
            $params['service_id'],
            $params['network_names']
        );
        return $this->success();
    }

    public function swarmServiceUpdateImage()
    {
        $params = $this->validate([
            'service_id' => 'required|string|max:64',
            'image' => 'required|string|max:512',
            'registry_id' => 'nullable|integer|min:0',
        ]);
        $cluster = $this->resolveSwarmCluster();
        $result = $this->swarmOverviewService->pullAndUpdateServiceImage(
            $cluster,
            (int) Functions::getContextValue('org_id'),
            $params['service_id'],
            $params['image'],
            (int) ($params['registry_id'] ?? 0)
        );
        return $this->success($result);
    }

    public function swarmServiceUpdateResources()
    {
        $params = $this->validate([
            'service_id' => 'required|string|max:64',
            'cpu_limit' => 'numeric|min:0',
            'cpu_reserved' => 'numeric|min:0',
            'memory_limit' => 'integer|min:0',
            'memory_reserved' => 'integer|min:0',
        ]);
        $this->swarmOverviewService->updateServiceResources(
            $this->resolveSwarmCluster(),
            $params['service_id'],
            [
                'cpu_limit' => isset($params['cpu_limit']) ? (float) $params['cpu_limit'] : null,
                'cpu_reserved' => isset($params['cpu_reserved']) ? (float) $params['cpu_reserved'] : null,
                'memory_limit' => isset($params['memory_limit']) ? (int) $params['memory_limit'] : null,
                'memory_reserved' => isset($params['memory_reserved']) ? (int) $params['memory_reserved'] : null,
            ]
        );
        return $this->success();
    }

    public function swarmServiceUpdateEnv()
    {
        $params = $this->validate([
            'service_id' => 'required|string|max:64',
            'env' => 'required|array',
        ]);
        file_put_contents(BASE_PATH . '/runtime/logs/env-update-debug.log',
            sprintf("[%s] service_id=%s env=%s\n", date('Y-m-d H:i:s'), $params['service_id'], json_encode($params['env'])),
            FILE_APPEND
        );
        try {
            $this->swarmOverviewService->updateServiceEnv(
                $this->resolveSwarmCluster(),
                $params['service_id'],
                $params['env']
            );
            file_put_contents(BASE_PATH . '/runtime/logs/env-update-debug.log',
                sprintf("[%s] SUCCESS\n", date('Y-m-d H:i:s')),
                FILE_APPEND
            );
        } catch (\Throwable $e) {
            file_put_contents(BASE_PATH . '/runtime/logs/env-update-debug.log',
                sprintf("[%s] ERROR: %s\n", date('Y-m-d H:i:s'), $e->getMessage()),
                FILE_APPEND
            );
            throw $e;
        }
        return $this->success();
    }

    public function swarmServiceUpdatePorts()
    {
        $params = $this->validate([
            'service_id' => 'required|string|max:64',
            'ports' => 'required|array',
        ]);
        $ports = [];
        foreach ($params['ports'] as $p) {
            $ports[] = [
                'Protocol' => $p['protocol'] ?? 'tcp',
                'TargetPort' => (int) ($p['target_port'] ?? 0),
                'PublishedPort' => (int) ($p['published_port'] ?? 0),
                'PublishMode' => $p['publish_mode'] ?? 'ingress',
            ];
        }
        $this->swarmOverviewService->updateServicePorts(
            $this->resolveSwarmCluster(),
            $params['service_id'],
            $ports
        );
        return $this->success();
    }

    public function swarmServiceUpdateMounts()
    {
        $params = $this->validate([
            'service_id' => 'required|string|max:64',
            'mounts' => 'present|array',
        ]);
        $mounts = [];
        foreach ($params['mounts'] as $m) {
            $mounts[] = [
                'Type' => $m['type'] ?? 'bind',
                'Source' => $m['source'] ?? '',
                'Target' => $m['target'] ?? '',
                'ReadOnly' => (bool) ($m['readonly'] ?? false),
            ];
        }
        $this->swarmOverviewService->updateServiceMounts(
            $this->resolveSwarmCluster(),
            $params['service_id'],
            $mounts
        );
        return $this->success();
    }

    public function swarmServiceUpdateConfig()
    {
        $params = $this->validate([
            'service_id' => 'required|string|max:64',
            'update_config' => 'array',
            'rollback_config' => 'array',
        ]);
        $updateConfig = [];
        if (isset($params['update_config'])) {
            $uc = $params['update_config'];
            if (array_key_exists('parallelism', $uc)) {
                $updateConfig['Parallelism'] = (int) $uc['parallelism'];
            }
            if (array_key_exists('delay', $uc)) {
                $updateConfig['Delay'] = (int) ($uc['delay'] ?? 0) * 1_000_000_000;
            }
            if (array_key_exists('failure_action', $uc)) {
                $updateConfig['FailureAction'] = (string) $uc['failure_action'];
            }
            if (array_key_exists('monitor', $uc)) {
                $updateConfig['Monitor'] = (int) ($uc['monitor'] ?? 0) * 1_000_000_000;
            }
            if (array_key_exists('max_failure_ratio', $uc)) {
                $updateConfig['MaxFailureRatio'] = (float) $uc['max_failure_ratio'];
            }
            if (array_key_exists('order', $uc)) {
                $updateConfig['Order'] = (string) $uc['order'];
            }
        }
        $rollbackConfig = [];
        if (isset($params['rollback_config'])) {
            $rc = $params['rollback_config'];
            if (array_key_exists('parallelism', $rc)) {
                $rollbackConfig['Parallelism'] = (int) $rc['parallelism'];
            }
            if (array_key_exists('delay', $rc)) {
                $rollbackConfig['Delay'] = (int) ($rc['delay'] ?? 0) * 1_000_000_000;
            }
            if (array_key_exists('failure_action', $rc)) {
                $rollbackConfig['FailureAction'] = (string) $rc['failure_action'];
            }
            if (array_key_exists('monitor', $rc)) {
                $rollbackConfig['Monitor'] = (int) ($rc['monitor'] ?? 0) * 1_000_000_000;
            }
            if (array_key_exists('max_failure_ratio', $rc)) {
                $rollbackConfig['MaxFailureRatio'] = (float) $rc['max_failure_ratio'];
            }
            if (array_key_exists('order', $rc)) {
                $rollbackConfig['Order'] = (string) $rc['order'];
            }
        }
        $this->swarmOverviewService->updateServiceUpdateConfig(
            $this->resolveSwarmCluster(),
            $params['service_id'],
            $updateConfig,
            $rollbackConfig
        );
        return $this->success();
    }

    public function swarmServiceConfig()
    {
        $params = $this->validate(['service_id' => 'required|string|max:64']);
        $cluster = $this->resolveSwarmCluster();
        $this->projectSwarmScope->assertService($cluster, $params['service_id']);
        return $this->success([
            'configs' => $this->swarmOverviewService->getServiceConfigs(
                $cluster,
                $params['service_id']
            ),
        ]);
    }

    public function swarmServiceConfigUpdate()
    {
        $params = $this->validate([
            'service_id' => 'required|string|max:64',
            'config_id' => 'required|string|max:64',
            'content' => 'required|string',
        ]);
        $this->swarmOverviewService->updateServiceConfig(
            $this->resolveSwarmCluster(),
            $params['service_id'],
            $params['config_id'],
            $params['content']
        );
        return $this->success();
    }

    public function swarmServiceTasks()
    {
        $params = $this->validate(['service_id' => 'required|string|max:64']);
        $cluster = $this->resolveSwarmCluster();
        $this->projectSwarmScope->assertService($cluster, $params['service_id']);
        return $this->success([
            'tasks' => $this->swarmOverviewService->listServiceTasks(
                $cluster,
                $params['service_id']
            ),
        ]);
    }

    public function swarmServiceContainers()
    {
        $params = $this->validate(['service_id' => 'required|string|max:64']);
        $cluster = $this->resolveSwarmCluster();
        $this->projectSwarmScope->assertService($cluster, $params['service_id']);
        return $this->success([
            'containers' => $this->swarmOverviewService->listServiceContainers(
                $cluster,
                $params['service_id']
            ),
        ]);
    }

    public function swarmNodes()
    {
        return $this->success(
            $this->swarmOverviewService->listNodes($this->resolveSwarmCluster())
        );
    }

    public function swarmNodesRuntime()
    {
        return $this->success(
            $this->swarmOverviewService->nodesRuntime($this->resolveSwarmCluster())
        );
    }

    public function swarmNode()
    {
        $nodeId = $this->request->route('node_id');
        if (empty($nodeId)) {
            throw new AppException(422, 'node id 字段是必须的');
        }
        return $this->success(
            $this->swarmOverviewService->getNode($this->resolveSwarmCluster(), $nodeId)
        );
    }

    public function swarmNodeTasks()
    {
        $params = $this->validate(['node_id' => 'required|string|max:64']);
        return $this->success([
            'tasks' => $this->swarmOverviewService->nodeTasks(
                $this->resolveSwarmCluster(),
                $params['node_id']
            ),
        ]);
    }

    public function swarmNodeContainers()
    {
        $params = $this->validate(['node_id' => 'required|string|max:64']);
        return $this->success([
            'containers' => $this->swarmOverviewService->nodeContainers(
                $this->resolveSwarmCluster(),
                $params['node_id']
            ),
        ]);
    }

    public function swarmServiceEvents()
    {
        $params = $this->validate([
            'service_id' => 'required|string|max:64',
            'since' => 'nullable|integer',
            'until' => 'nullable|integer',
        ]);
        $cluster = $this->resolveSwarmCluster();
        $this->projectSwarmScope->assertService($cluster, $params['service_id']);
        return $this->success([
            'events' => $this->swarmOverviewService->serviceEvents(
                $cluster,
                $params['service_id'],
                isset($params['since']) ? (int) $params['since'] : null,
                isset($params['until']) ? (int) $params['until'] : null
            ),
        ]);
    }

    public function swarmServiceConfigsSecrets()
    {
        $params = $this->validate(['service_id' => 'required|string|max:64']);
        $cluster = $this->resolveSwarmCluster();
        $this->projectSwarmScope->assertService($cluster, $params['service_id']);
        return $this->success(
            $this->swarmOverviewService->getServiceConfigAndSecretMappings(
                $cluster,
                $params['service_id']
            )
        );
    }

    public function swarmServiceConfigAdd()
    {
        $params = $this->validate([
            'service_id' => 'required|string|max:64',
            'config_id' => 'nullable|string|max:64',
            'name' => 'nullable|string|max:64',
            'content' => 'nullable|string',
            'target_dir' => 'string',
            'mode' => 'nullable|integer',
        ]);
        $this->swarmOverviewService->addServiceConfig(
            $this->resolveSwarmCluster(),
            $params['service_id'],
            $params['config_id'] ?? null,
            $params['name'] ?? null,
            array_key_exists('content', $params) ? $params['content'] : null,
            $params['target_dir'] ?? '/',
            isset($params['mode']) ? (int) $params['mode'] : null
        );
        return $this->success();
    }

    public function swarmServiceSecretAdd()
    {
        $params = $this->validate([
            'service_id' => 'required|string|max:64',
            'secret_id' => 'nullable|string|max:64',
            'name' => 'nullable|string|max:64',
            'content' => 'nullable|string',
            'target' => 'nullable|string',
            'mode' => 'nullable|integer',
        ]);
        $this->swarmOverviewService->addServiceSecret(
            $this->resolveSwarmCluster(),
            $params['service_id'],
            $params['secret_id'] ?? null,
            $params['name'] ?? null,
            array_key_exists('content', $params) ? $params['content'] : null,
            isset($params['target']) && $params['target'] !== '' ? $params['target'] : null,
            isset($params['mode']) ? (int) $params['mode'] : null
        );
        return $this->success();
    }

    public function swarmServiceConfigRemove()
    {
        $params = $this->validate([
            'service_id' => 'required|string|max:64',
            'config_id' => 'required|string|max:64',
        ]);
        $this->swarmOverviewService->removeServiceConfig(
            $this->resolveSwarmCluster(),
            $params['service_id'],
            $params['config_id']
        );
        return $this->success();
    }

    public function swarmServiceSecretRemove()
    {
        $params = $this->validate([
            'service_id' => 'required|string|max:64',
            'secret_id' => 'required|string|max:64',
        ]);
        $this->swarmOverviewService->removeServiceSecret(
            $this->resolveSwarmCluster(),
            $params['service_id'],
            $params['secret_id']
        );
        return $this->success();
    }

    public function swarmOverview()
    {
        $cluster = $this->resolveSwarmCluster();

        try {
            $overview = $this->swarmOverviewService->overview($cluster);
            $cluster->status = Cluster::STATUS_READY;
            $cluster->version = $overview['engine']['version'] ?? '';
            $cluster->save();

            return $this->success([
                'overview' => $overview,
            ]);
        } catch (AppException $e) {
            if ($e->getCode() === 502) {
                $cluster->status = Cluster::STATUS_OFFLINE;
                $cluster->save();
            }
            throw $e;
        }
    }

    public function swarmOverviewCore()
    {
        $cluster = $this->resolveSwarmCluster();
        try {
            $overview = $this->swarmOverviewService->overviewCore($cluster);
            $cluster->status = Cluster::STATUS_READY;
            $cluster->version = $overview['engine']['version'] ?? '';
            $cluster->save();
            return $this->success(['overview' => $overview]);
        } catch (AppException $e) {
            if ($e->getCode() === 502) {
                $cluster->status = Cluster::STATUS_OFFLINE;
                $cluster->save();
            }
            throw $e;
        }
    }

    public function swarmOverviewTopology()
    {
        return $this->success(['overview' => $this->swarmOverviewService->overviewTopology($this->resolveSwarmCluster())]);
    }

    public function swarmOverviewRuntime()
    {
        return $this->success(['overview' => $this->swarmOverviewService->overviewRuntime($this->resolveSwarmCluster())]);
    }

    public function swarmSettings()
    {
        $orgId = Functions::getContextValue('org_id');
        $params = $this->validate([
            'cluster_id' => 'required|integer',
        ]);
        /** @var Cluster $cluster */
        $cluster = $this->cluster->getCluster((int) $params['cluster_id'], $orgId);
        if ($cluster['orchestrator_type'] !== Cluster::ORCHESTRATOR_DOCKER_SWARM) {
            throw new AppException(422, '该集群不是 Docker Swarm 集群');
        }

        return $this->success([
            'settings' => [
                'id' => $cluster['id'],
                'title' => $cluster['title'],
                'remark' => $cluster['remark'],
                'registration_status' => $cluster['registration_status'],
                'agent_status' => $cluster['agent_status'],
                'swarm_id' => $cluster['swarm_id'],
                'agent_server_url' => rtrim((string) config('agent_server_url', ''), '/'),
            ],
        ]);
    }

    public function updateSwarmSettings()
    {
        $orgId = Functions::getContextValue('org_id');
        $params = $this->validate([
            'cluster_id' => 'required|integer',
            'title' => 'required|string|max:20',
            'remark' => 'nullable|string|max:2000',
        ]);
        /** @var Cluster $cluster */
        $cluster = $this->cluster->getCluster((int) $params['cluster_id'], $orgId);
        if ($cluster['orchestrator_type'] !== Cluster::ORCHESTRATOR_DOCKER_SWARM) {
            throw new AppException(422, '该集群不是 Docker Swarm 集群');
        }

        $duplicated = Cluster::where('org_id', $orgId)
            ->where('title', $params['title'])
            ->where('id', '<>', $cluster['id'])
            ->exists();
        if ($duplicated) {
            throw new AppException(422, '该集群名称已存在');
        }

        Db::transaction(function () use ($cluster, $params): void {
            $cluster->title = $params['title'];
            $cluster->remark = $params['remark'] ?? '';
            $cluster->save();
        });

        return $this->success([
            'settings' => [
                'id' => $cluster['id'],
                'title' => $cluster['title'],
                'remark' => $cluster['remark'],
                'registration_status' => $cluster['registration_status'],
                'agent_status' => $cluster['agent_status'],
                'swarm_id' => $cluster['swarm_id'],
            ],
        ]);
    }

    public function rotateSwarmAgentToken()
    {
        $orgId = Functions::getContextValue('org_id');
        $params = $this->validate([
            'cluster_id' => 'required|integer',
        ]);
        /** @var Cluster $cluster */
        $cluster = $this->cluster->getCluster((int) $params['cluster_id'], $orgId);
        if ($cluster['orchestrator_type'] !== Cluster::ORCHESTRATOR_DOCKER_SWARM) {
            throw new AppException(422, '该集群不是 Docker Swarm 集群');
        }
        if ((string) $cluster->registration_status === 'pending') {
            $secret = Db::transaction(function () use ($cluster): string {
                // Retired Docker API/TLS endpoints are never reused. Existing
                // clusters enter the same Agent bootstrap lifecycle as newly
                // created clusters when an administrator generates a token.
                $cluster->endpoint = 'agent-pending://cluster';
                $cluster->agent_status = 'pending';
                $cluster->save();
                return $this->agentCredentialService->issueBootstrap((int) $cluster->id);
            });
            return $this->success([
                'cluster_id' => (int) $cluster->id,
                'bootstrap_token' => $secret,
                'expires_in' => AgentCredentialService::BOOTSTRAP_TTL,
            ]);
        }
        throw new AppException(409, '已注册集群的机器凭证由 Manager Agent 自动轮转，不能在 Web 中导出');
    }

    public function resetSwarmAgentRegistration()
    {
        $orgId = Functions::getContextValue('org_id');
        $params = $this->validate([
            'cluster_id' => 'required|integer',
        ]);
        /** @var Cluster $cluster */
        $cluster = $this->cluster->getCluster((int) $params['cluster_id'], $orgId);
        if ($cluster['orchestrator_type'] !== Cluster::ORCHESTRATOR_DOCKER_SWARM) {
            throw new AppException(422, '该集群不是 Docker Swarm 集群');
        }

        $clusterId = (int) $cluster->id;
        $secret = $this->agentCredentialService->resetRegistration($clusterId);
        $this->agentRelayService->disconnect($clusterId, 'cluster agent registration reset');

        return $this->success([
            'cluster_id' => $clusterId,
            'registration_status' => 'pending',
            'agent_status' => 'pending',
            'bootstrap_token' => $secret,
            'expires_in' => AgentCredentialService::BOOTSTRAP_TTL,
        ]);
    }

    public function swarmJoinCommands()
    {
        $orgId = Functions::getContextValue('org_id');
        $params = $this->validate([
            'cluster_id' => 'required|integer',
            'join_address' => 'nullable|string|max:255',
        ]);
        /** @var Cluster $cluster */
        $cluster = $this->cluster->getCluster((int) $params['cluster_id'], $orgId);
        if ($cluster['orchestrator_type'] !== Cluster::ORCHESTRATOR_DOCKER_SWARM) {
            throw new AppException(422, '该集群不是 Docker Swarm 集群');
        }
        try {
            $commands = $this->swarmOverviewService->joinCommands(
                $cluster,
                isset($params['join_address']) ? (string) $params['join_address'] : null
            );
            return $this->success(['commands' => $commands]);
        } catch (\Throwable $e) {
            throw new AppException(502, '获取 Swarm Join Token 失败：' . $e->getMessage());
        }
    }

    private function isAgentEndpoint(string $endpoint): bool
    {
        return str_starts_with($endpoint, 'agent://');
    }

    public function index()
    {
        $orgId = Functions::getContextValue('org_id');
        $groupId = Functions::getContextValue('group_id', false, null);
        $params = $this->validate([
            'env_id' => 'integer',
            'keyword' => 'string',
        ]);
        $params = Functions::arrNull2default($params, [
            'env_id' => null,
            'keyword' => null,
        ]);

        $clusters = $this->cluster->list(
            $orgId,
            $groupId === null ? null : (int) $groupId,
            $params['env_id'],
            $params['keyword']
        );

        return $this->success([
            'clusters' => $clusters,
        ]);
    }

    public function simple()
    {
        $orgId = Functions::getContextValue('org_id');
        $groupId = Functions::getContextValue('group_id', false, null);
        $params = $this->validate([
            'env_id' => 'nullable|integer',
            'keyword' => 'nullable|string',
        ]);
        $params = Functions::arrNull2default($params, [
            'env_id' => null,
            'keyword' => null,
        ]);
        $clusters = $this->cluster->simpleList(
            $orgId,
            $groupId === null ? null : (int) $groupId,
            $params['env_id'],
            $params['keyword']
        );

        return $this->success([
            'clusters' => $clusters,
        ]);
    }

    public function bindEnvs()
    {
        $orgId = Functions::getContextValue('org_id');
        $params = $this->validate([
            'cluster_id' => 'required|integer',
            'env_ids' => 'nullable|array',
            'env_ids.*' => 'integer|distinct',
        ]);
        $params = Functions::arrNull2default($params, [
            'env_ids' => [],
        ]);

        $this->cluster->bindEnvs($orgId, (int) $params['cluster_id'], $params['env_ids']);

        return $this->success();
    }

    public function groupGrants()
    {
        $orgId = (int) Functions::getContextValue('org_id');
        $params = $this->validate(['cluster_id' => 'required|integer|min:1']);
        return $this->success([
            'groups' => $this->groupResourceGrants->clusterGroups($orgId, (int) $params['cluster_id']),
        ]);
    }

    public function syncGroupGrants()
    {
        $orgId = (int) Functions::getContextValue('org_id');
        $params = $this->validate([
            'cluster_id' => 'required|integer|min:1',
            'group_ids' => 'nullable|array|max:1000',
            'group_ids.*' => 'required|integer|min:1|distinct',
        ]);
        $groups = $this->groupResourceGrants->syncClusterGroups(
            (int) Functions::getLoginUser()->getId(),
            $orgId,
            (int) $params['cluster_id'],
            (array) ($params['group_ids'] ?? [])
        );
        return $this->success(['groups' => $groups]);
    }
}

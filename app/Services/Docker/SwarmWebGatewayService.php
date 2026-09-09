<?php

namespace App\Services\Docker;

use App\Exception\AppException;
use App\Model\Cluster;
use App\Model\ClusterAgentNode;
use App\Model\ClusterWebGateway;
use App\Model\GatewayVhost;
use App\Model\ProjectRoute;
use App\Model\TlsCertificate;
use App\Services\Gateway\SwarmGatewayCertificateDeployer;
use App\Services\Gateway\SwarmGatewayMutationLock;
use App\Services\Gateway\TraefikAcmeCertificateReconciler;
use App\Services\Gateway\TlsCertificateService;
use App\Services\Gateway\GatewayVhostService;
use App\Services\Docker\SwarmContainerExecService;
use App\Services\RegistryService;
use App\Services\ContainerImageMappingService;
use GuzzleHttp\Client;
use Throwable;

class SwarmWebGatewayService
{
    private const DEFAULT_IMAGE = 'traefik:v3.7';
    private const DEFAULT_SERVICE = 'galaxy-web-gateway';
    private const DEFAULT_SOCKET_PROXY_IMAGE = 'tecnativa/docker-socket-proxy:latest';
    private const DEFAULT_SOCKET_PROXY_SERVICE = 'galaxy-web-gateway-socket-proxy';
    private const DEFAULT_NETWORK = 'galaxy-web';
    private const DEFAULT_CONTROL_NETWORK = 'galaxy-web-control';

    public function __construct(
        private SwarmApiClient $docker,
        private SwarmGatewayCertificateDeployer $certificateDeployer,
        private TraefikAcmeCertificateReconciler $acmeReconciler,
        private TlsCertificateService $certificates,
        private GatewayVhostService $gatewayVhosts,
        private SwarmGatewayMutationLock $gatewayLock,
        private SwarmContainerExecService $containerExec,
        private RegistryService $registries,
        private ContainerImageMappingService $imageMappings
    ) {}

    public function profile(Cluster $cluster, bool $refresh = true): array
    {
        /** @var ClusterWebGateway|null $gateway */
        $gateway = ClusterWebGateway::where('org_id', (int) $cluster->org_id)
            ->where('cluster_id', (int) $cluster->id)->first();
        if ($gateway === null) {
            return [
                'installed' => false,
                'gateway' => null,
                'runtime' => ['desired' => 0, 'running' => 0, 'failed' => 0, 'tasks' => []],
                'dashboard' => ['enabled' => false, 'port' => 8080, 'url' => ''],
                'defaults' => $this->defaults(),
            ];
        }

        $runtime = ['desired' => (int) $gateway->replicas, 'running' => 0, 'failed' => 0, 'tasks' => []];
        if ($refresh && $gateway->service_id !== '') {
            try {
                $runtime = $this->refreshRuntime($cluster, $gateway);
                if ($runtime['running'] > 0) {
                    $this->acmeReconciler->reconcile($cluster, $gateway);
                }
            } catch (Throwable $e) {
                $gateway->status = ClusterWebGateway::STATUS_ERROR;
                $gateway->error = mb_substr($e->getMessage(), 0, 2000);
                $gateway->updated_at = time();
                $gateway->save();
            }
        }

        return [
            'installed' => $gateway->service_id !== '',
            'gateway' => $gateway->fresh(),
            'runtime' => $runtime,
            'dashboard' => $this->dashboardProfile($gateway),
            'defaults' => $this->defaults(),
        ];
    }

    public function deploy(int $uid, Cluster $cluster, array $input): array
    {
        $this->gatewayLock->synchronized(
            (int) $cluster->org_id,
            (int) $cluster->id,
            fn (): array => $this->deployUnlocked($uid, $cluster, $input),
            30,
            300
        );
        // The gateway Service is disposable; project and administrator routes
        // remain in MySQL. Re-materialize the complete file-provider snapshot
        // after every install/update so a rebuilt cluster does not require
        // users to recreate route declarations.
        $this->gatewayVhosts->deploy((int) $cluster->org_id, (int) $cluster->id);
        return $this->profile($cluster, true);
    }

    private function deployUnlocked(int $uid, Cluster $cluster, array $input): array
    {
        $data = $this->normalize($input);
        if (! array_key_exists('workspace_base_domain', $input)) {
            unset($data['workspace_base_domain']);
        }
        /** @var ClusterWebGateway $gateway */
        $gateway = ClusterWebGateway::firstOrNew(['cluster_id' => (int) $cluster->id]);
        $creating = ! $gateway->exists;
        if ($creating) {
            $gateway->org_id = (int) $cluster->org_id;
            $gateway->service_name = self::DEFAULT_SERVICE;
            $gateway->socket_proxy_service_name = self::DEFAULT_SOCKET_PROXY_SERVICE;
            $gateway->creator = $uid;
            $gateway->created_at = time();
        }
        foreach ($data as $field => $value) {
            $gateway->{$field} = $value;
        }
        $gateway->provider = ClusterWebGateway::PROVIDER_TRAEFIK;
        $gateway->status = ClusterWebGateway::STATUS_PENDING;
        $gateway->error = null;
        $gateway->updated_at = time();
        $gateway->save();

        try {
            $this->docker->withCluster($cluster, function (Client $client, SwarmApiClient $api) use ($cluster, $gateway): void {
                $info = $api->request($client, 'GET', '/info');
                if (($info['Swarm']['LocalNodeState'] ?? '') !== 'active'
                    || empty($info['Swarm']['ControlAvailable'])) {
                    throw new AppException(422, 'Web 网关只能通过 Docker Swarm Manager 部署');
                }
                if (trim((string) $gateway->placement_node_id) === '') {
                    $gateway->placement_node_id = (string) ($info['Swarm']['NodeID'] ?? '');
                }
                $network = $this->ensureNetwork($client, $api, (string) $gateway->network_name, (int) $gateway->cluster_id);
                $gateway->network_id = $network['id'];
                $controlNetwork = $this->ensureNetwork(
                    $client, $api, (string) $gateway->control_network_name, (int) $gateway->cluster_id, true
                );
                $gateway->control_network_id = $controlNetwork['id'];
                $gateway->socket_proxy_service_id = $this->upsertSocketProxy($client, $api, $gateway, $cluster);
                if ($gateway->acme_enabled) {
                    $gateway->acme_volume_name = $this->ensureAcmeVolume($client, $api, (int) $gateway->cluster_id);
                } else {
                    $gateway->acme_volume_name = '';
                }
                $result = $this->certificateDeployer->applyToServiceSpec(
                    $client, $api, $gateway, $this->serviceSpec($gateway, $cluster)
                );
                $spec = $result['spec'];
                $runtimeImage = (string) $spec['TaskTemplate']['ContainerSpec']['Image'];
                $registryHeaders = $this->registries->dockerAuthHeader(
                    (int) $cluster->org_id,
                    $runtimeImage
                );
                $this->ensureImage(
                    $client,
                    $api,
                    $runtimeImage,
                    $registryHeaders
                );
                if ($gateway->service_id === '') {
                    $created = $api->request($client, 'POST', '/services/create', [
                        'headers' => $registryHeaders,
                        'json' => $spec,
                    ]);
                    $gateway->service_id = (string) ($created['ID'] ?? '');
                    if ($gateway->service_id === '') {
                        throw new AppException(502, 'Docker API 未返回 Traefik Service ID');
                    }
                } else {
                    $current = $api->request($client, 'GET', '/services/' . rawurlencode((string) $gateway->service_id));
                    $spec['TaskTemplate']['ForceUpdate'] = (int) ($current['Spec']['TaskTemplate']['ForceUpdate'] ?? 0) + 1;
                    $api->request($client, 'POST', '/services/' . rawurlencode((string) $gateway->service_id) . '/update', [
                        'query' => [
                            'version' => (int) ($current['Version']['Index'] ?? 0),
                            'registryAuthFrom' => 'spec',
                        ],
                        'headers' => $registryHeaders,
                        'json' => $spec,
                    ]);
                }
                if ($result['tlsYaml'] !== null) {
                    $containerIds = $this->containerExec->waitForRunningContainerIds(
                        $client,
                        $api,
                        (string) $gateway->service_id,
                        60,
                        (int) $spec['TaskTemplate']['ForceUpdate']
                    );
                    $tar = $this->containerExec->buildTar('tls.yml', $result['tlsYaml']);
                    foreach ($containerIds as $containerId) {
                        $api->putArchive($client, $containerId, '/dynamic/', $tar);
                    }
                }
                $gc = $this->certificateDeployer->cleanupObsoleteResources($client, $api, $gateway, $spec);
                $gateway->configuration = [
                    'spec_version' => 1,
                    'managed_by' => 'codegalaxy',
                    'tls_gc' => $gc,
                ];
                $gateway->status = ClusterWebGateway::STATUS_PENDING;
                $gateway->error = null;
                $gateway->synced_at = time();
                $gateway->updated_at = time();
                $gateway->save();
            }, 60);
        } catch (Throwable $e) {
            $gateway->status = ClusterWebGateway::STATUS_ERROR;
            $gateway->error = mb_substr($e->getMessage(), 0, 2000);
            $gateway->updated_at = time();
            $gateway->save();
            throw $e;
        }

        return $this->profile($cluster, true);
    }

    public function setWorkspaceDomain(
        Cluster $cluster,
        string $domain,
        int $certificateId,
        bool $httpsRedirect
    ): array
    {
        $mappingCount = $this->gatewayLock->synchronized(
            (int) $cluster->org_id,
            (int) $cluster->id,
            function () use ($cluster, $domain, $certificateId, $httpsRedirect): int {
                $gateway = ClusterWebGateway::where('org_id', (int) $cluster->org_id)
                    ->where('cluster_id', (int) $cluster->id)->first();
                if ($gateway === null || $gateway->service_id === '') {
                    throw new AppException(409, '请先安装 Web 网关，再添加 Workspace 泛域名规则');
                }
                $wildcard = $this->workspaceWildcardDomain($domain);
                $certificate = $this->certificates->assertUsableForHostname(
                    (int) $cluster->org_id,
                    $certificateId,
                    $wildcard
                );
                if ($certificate->source === TlsCertificate::SOURCE_LETS_ENCRYPT) {
                    throw new AppException(422, '当前 Let’s Encrypt 使用 HTTP-01，不能签发泛域名证书；请使用手动上传、自签名或云厂商泛域名证书');
                }
                $previousBase = preg_replace('/^\*\./', '', (string) $gateway->workspace_base_domain);
                $gateway->workspace_base_domain = $wildcard;
                $gateway->workspace_certificate_id = $certificateId;
                $gateway->workspace_https_redirect = $httpsRedirect;
                $gateway->updated_at = time();
                $gateway->save();
                $base = $previousBase !== '' ? $previousBase : preg_replace('/^\*\./', '', $wildcard);
                $suffix = '%.' . $base;
                $fields = [
                    'entrypoint' => 'websecure',
                    'tls_enabled' => 1,
                    'certificate_id' => $certificateId,
                    'https_redirect' => $httpsRedirect ? 1 : 0,
                    'status' => 'pending',
                    'error' => null,
                    'updated_at' => time(),
                ];
                $vhosts = GatewayVhost::where('org_id', (int) $cluster->org_id)
                    ->where('cluster_id', (int) $cluster->id)->where('hostname', 'like', $suffix)->update($fields);
                $routes = ProjectRoute::where('org_id', (int) $cluster->org_id)
                    ->where('cluster_id', (int) $cluster->id)->where('hostname', 'like', $suffix)->update($fields);
                return (int) $vhosts + (int) $routes;
            },
            10,
            30
        );
        if ($mappingCount > 0) {
            $this->certificateDeployer->syncGateway($cluster);
            $this->gatewayVhosts->deploy((int) $cluster->org_id, (int) $cluster->id);
        }
        return $this->profile($cluster, false);
    }

    public function clearWorkspaceDomain(Cluster $cluster): array
    {
        return $this->gatewayLock->synchronized(
            (int) $cluster->org_id,
            (int) $cluster->id,
            function () use ($cluster): array {
                $gateway = ClusterWebGateway::where('org_id', (int) $cluster->org_id)
                    ->where('cluster_id', (int) $cluster->id)->first();
                if ($gateway === null) {
                    throw new AppException(404, 'Web 网关不存在');
                }
                $gateway->workspace_base_domain = '';
                $gateway->workspace_certificate_id = 0;
                $gateway->workspace_https_redirect = true;
                $gateway->updated_at = time();
                $gateway->save();
                return $this->profile($cluster, false);
            },
            10,
            30
        );
    }

    public function remove(Cluster $cluster): void
    {
        $this->gatewayLock->synchronized(
            (int) $cluster->org_id,
            (int) $cluster->id,
            fn () => $this->removeUnlocked($cluster),
            30,
            300
        );
    }

    private function removeUnlocked(Cluster $cluster): void
    {
        /** @var ClusterWebGateway|null $gateway */
        $gateway = ClusterWebGateway::where('org_id', (int) $cluster->org_id)
            ->where('cluster_id', (int) $cluster->id)->first();
        if ($gateway === null) {
            return;
        }
        $routes = ProjectRoute::where('org_id', (int) $cluster->org_id)
            ->where('cluster_id', (int) $cluster->id)->where('enabled', 1)->count();
        if ($routes > 0) {
            throw new AppException(422, sprintf('该网关仍承载 %d 条项目域名路由，请先删除或迁移路由', $routes));
        }
        $gc = $this->docker->withCluster(
            $cluster,
            function (Client $client, SwarmApiClient $api) use ($gateway): array {
                if ($gateway->service_id !== '') {
                    $service = $this->serviceOrNull($client, $api, (string) $gateway->service_id);
                    if ($service !== null) {
                        $result = $this->certificateDeployer->applyToServiceSpec(
                            $client,
                            $api,
                            $gateway,
                            (array) ($service['Spec'] ?? [])
                        );
                        $spec = $result['spec'];
                        $api->request($client, 'POST', '/services/' . rawurlencode((string) $gateway->service_id) . '/update', [
                            'query' => [
                                'version' => (int) ($service['Version']['Index'] ?? 0),
                                'registryAuthFrom' => 'spec',
                            ],
                            'json' => $spec,
                        ]);
                        $this->certificateDeployer->cleanupObsoleteResources($client, $api, $gateway, $spec);
                        $api->request($client, 'DELETE', '/services/' . rawurlencode((string) $gateway->service_id));
                    }
                    $gateway->service_id = '';
                }
                if ($gateway->socket_proxy_service_id !== '') {
                    if ($this->serviceOrNull($client, $api, (string) $gateway->socket_proxy_service_id) !== null) {
                        $api->request($client, 'DELETE', '/services/' . rawurlencode((string) $gateway->socket_proxy_service_id));
                    }
                    $gateway->socket_proxy_service_id = '';
                }
                return $this->certificateDeployer->cleanupObsoleteResources($client, $api, $gateway);
            },
            60
        );
        if ($gc['errors'] !== []) {
            $configuration = (array) $gateway->configuration;
            $configuration['tls_gc'] = $gc;
            $gateway->configuration = $configuration;
            $gateway->status = ClusterWebGateway::STATUS_ERROR;
            $gateway->error = 'Traefik 已卸载，但旧版 TLS Secret/Config 尚未全部回收；请重试卸载';
            $gateway->updated_at = time();
            $gateway->save();
            throw new AppException(503, (string) $gateway->error);
        }
        // Keep the shared overlay network and ACME volume: project services may still reference them.
        $gateway->delete();
    }

    public function gatewayForCluster(int $orgId, int $clusterId): ?ClusterWebGateway
    {
        return ClusterWebGateway::where('org_id', $orgId)->where('cluster_id', $clusterId)
            ->where('service_id', '<>', '')->first();
    }

    private function serviceOrNull(Client $client, SwarmApiClient $api, string $serviceId): ?array
    {
        try {
            return $api->request($client, 'GET', '/services/' . rawurlencode($serviceId));
        } catch (AppException $e) {
            if (str_contains($e->getMessage(), '返回 404')) {
                return null;
            }
            throw $e;
        }
    }

    private function refreshRuntime(Cluster $cluster, ClusterWebGateway $gateway): array
    {
        return $this->docker->withCluster($cluster, function (Client $client, SwarmApiClient $api) use ($gateway): array {
            $service = $api->request($client, 'GET', '/services/' . rawurlencode((string) $gateway->service_id));
            $tasks = $api->request($client, 'GET', '/tasks', ['query' => [
                'filters' => json_encode(['service' => [(string) $gateway->service_id]], JSON_THROW_ON_ERROR),
            ]]);
            $presented = [];
            $running = 0;
            $failed = 0;
            foreach ($tasks as $task) {
                $state = (string) ($task['Status']['State'] ?? 'unknown');
                $desired = (string) ($task['DesiredState'] ?? '');
                if ($state === 'running') {
                    ++$running;
                }
                if (in_array($state, ['failed', 'rejected', 'orphaned'], true)) {
                    ++$failed;
                }
                $presented[] = [
                    'id' => (string) ($task['ID'] ?? ''),
                    'node_id' => (string) ($task['NodeID'] ?? ''),
                    'state' => $state,
                    'desired_state' => $desired,
                    'message' => (string) ($task['Status']['Message'] ?? ''),
                    'error' => (string) ($task['Status']['Err'] ?? ''),
                    'updated_at' => (string) ($task['Status']['Timestamp'] ?? ''),
                ];
            }
            $desired = (int) ($service['Spec']['Mode']['Replicated']['Replicas'] ?? $gateway->replicas);
            $gateway->status = $running >= $desired
                ? ClusterWebGateway::STATUS_RUNNING
                : ($failed > 0 ? ClusterWebGateway::STATUS_DEGRADED : ClusterWebGateway::STATUS_PENDING);
            $gateway->error = $failed > 0 ? 'Traefik 存在失败或被拒绝的任务，请查看任务详情' : null;
            $gateway->updated_at = time();
            $gateway->save();
            return ['desired' => $desired, 'running' => $running, 'failed' => $failed, 'tasks' => $presented];
        });
    }

    private function normalize(array $input): array
    {
        $image = trim((string) ($input['image'] ?? self::DEFAULT_IMAGE));
        if ($image === '' || strlen($image) > 1024 || preg_match('/[\x00-\x20]/', $image)) {
            throw new AppException(422, 'Traefik 镜像格式不合法');
        }
        $network = trim((string) ($input['network_name'] ?? self::DEFAULT_NETWORK));
        if (! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,62}$/', $network)) {
            throw new AppException(422, '网关网络名称格式不合法');
        }
        $controlNetwork = trim((string) ($input['control_network_name'] ?? self::DEFAULT_CONTROL_NETWORK));
        if (! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,62}$/', $controlNetwork)) {
            throw new AppException(422, '网关控制网络名称格式不合法');
        }
        if ($network === $controlNetwork) {
            throw new AppException(422, '业务入口网络与网关控制网络不能相同');
        }
        $httpPort = $this->port($input['http_port'] ?? 80, 'HTTP');
        $httpsPort = $this->port($input['https_port'] ?? 443, 'HTTPS');
        if ($httpPort === $httpsPort) {
            throw new AppException(422, 'HTTP 和 HTTPS 不能使用相同的发布端口');
        }
        $dashboardEnabled = (bool) ($input['dashboard_enabled'] ?? false);
        $dashboardPort = $this->port($input['dashboard_port'] ?? 8080, 'Dashboard');
        if ($dashboardEnabled && in_array($dashboardPort, [$httpPort, $httpsPort], true)) {
            throw new AppException(422, 'Dashboard 不能与 HTTP/HTTPS 使用相同的发布端口');
        }
        $publishMode = (string) ($input['publish_mode'] ?? 'ingress');
        if (! in_array($publishMode, ['ingress', 'host'], true)) {
            throw new AppException(422, '发布模式仅支持 ingress 或 host');
        }
        $replicas = max(1, min(10, (int) ($input['replicas'] ?? 1)));
        $acmeEnabled = (bool) ($input['acme_enabled'] ?? false);
        $email = strtolower(trim((string) ($input['acme_email'] ?? '')));
        $workspaceBaseDomain = $this->workspaceWildcardDomain($input['workspace_base_domain'] ?? '');
        if ($acmeEnabled && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new AppException(422, '启用 Let’s Encrypt 时必须填写有效邮箱');
        }
        if ($acmeEnabled && $replicas !== 1) {
            throw new AppException(422, '当前 ACME 存储使用本地 Volume，启用自动证书时网关副本数必须为 1');
        }
        return [
            'image' => $image,
            'socket_proxy_image' => $this->image($input['socket_proxy_image'] ?? self::DEFAULT_SOCKET_PROXY_IMAGE, 'Docker Socket Proxy'),
            'network_name' => $network, 'control_network_name' => $controlNetwork,
            'http_port' => $httpPort, 'https_port' => $httpsPort,
            'publish_mode' => $publishMode, 'replicas' => $replicas,
            'redirect_https' => (bool) ($input['redirect_https'] ?? false),
            'access_log_enabled' => (bool) ($input['access_log_enabled'] ?? true),
            'metrics_enabled' => (bool) ($input['metrics_enabled'] ?? true),
            'dashboard_enabled' => $dashboardEnabled,
            'dashboard_port' => $dashboardPort,
            'acme_enabled' => $acmeEnabled, 'acme_email' => $acmeEnabled ? $email : '',
            'workspace_base_domain' => $workspaceBaseDomain,
            'cert_resolver' => 'letsencrypt',
        ];
    }

    private function workspaceWildcardDomain(mixed $value): string
    {
        $wildcard = strtolower(rtrim(trim((string) $value), '.'));
        $domain = str_starts_with($wildcard, '*.') ? substr($wildcard, 2) : $wildcard;
        if ($domain !== '' && ! preg_match(
            '/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)+$/',
            $domain
        )) {
            throw new AppException(422, 'Workspace 泛域名格式不合法');
        }
        return $domain === '' ? '' : '*.' . $domain;
    }

    private function port(mixed $value, string $name): int
    {
        $port = filter_var($value, FILTER_VALIDATE_INT);
        if ($port === false || $port < 1 || $port > 65535) {
            throw new AppException(422, $name . ' 发布端口必须在 1-65535 之间');
        }
        return (int) $port;
    }

    private function image(mixed $value, string $name): string
    {
        $image = trim((string) $value);
        if ($image === '' || strlen($image) > 1024 || preg_match('/[\x00-\x20]/', $image)) {
            throw new AppException(422, $name . ' 镜像格式不合法');
        }
        return $image;
    }

    private function ensureNetwork(
        Client $client,
        SwarmApiClient $api,
        string $name,
        int $clusterId,
        bool $encrypted = false
    ): array
    {
        $items = $api->request($client, 'GET', '/networks', ['query' => [
            'filters' => json_encode(['name' => [$name]], JSON_THROW_ON_ERROR),
        ]]);
        foreach ($items as $network) {
            if (($network['Name'] ?? '') !== $name) {
                continue;
            }
            if (($network['Scope'] ?? '') !== 'swarm' || ($network['Driver'] ?? '') !== 'overlay'
                || ! empty($network['Ingress'])) {
                throw new AppException(422, '同名网络已存在，但不是可用于网关的 Swarm Overlay 网络');
            }
            if ($encrypted && ! array_key_exists('encrypted', (array) ($network['Options'] ?? []))) {
                throw new AppException(422, '同名网关控制网络已存在，但未启用 Overlay 数据面加密');
            }
            return ['id' => (string) ($network['Id'] ?? ''), 'name' => $name];
        }
        $spec = [
            'Name' => $name, 'Driver' => 'overlay', 'Attachable' => ! $encrypted,
            'Labels' => ['com.codegalaxy.component' => 'web-gateway',
                'com.codegalaxy.cluster-id' => (string) $clusterId],
        ];
        if ($encrypted) {
            $spec['Options'] = ['encrypted' => 'true'];
        }
        $created = $api->request($client, 'POST', '/networks/create', ['json' => $spec]);
        $id = (string) ($created['Id'] ?? '');
        if ($id === '') {
            throw new AppException(502, 'Docker API 未返回网关 Overlay 网络 ID');
        }
        return ['id' => $id, 'name' => $name];
    }

    private function ensureAcmeVolume(Client $client, SwarmApiClient $api, int $clusterId): string
    {
        $name = 'galaxy-web-gateway-acme';
        $api->request($client, 'POST', '/volumes/create', ['json' => [
            'Name' => $name,
            'Labels' => ['com.codegalaxy.component' => 'web-gateway-acme',
                'com.codegalaxy.cluster-id' => (string) $clusterId],
        ]]);
        return $name;
    }

    private function ensureImage(
        Client $client,
        SwarmApiClient $api,
        string $image,
        array $registryHeaders
    ): void {
        try {
            $api->request($client, 'GET', '/images/' . rawurlencode($image) . '/json');
            return;
        } catch (AppException $e) {
            if (! str_contains($e->getMessage(), '404')) {
                throw $e;
            }
        }

        $raw = $api->requestRaw($client, 'POST', '/images/create', [
            'headers' => $registryHeaders,
            'query' => ['fromImage' => $image],
            'timeout' => 600,
        ]);
        foreach (preg_split('/\r?\n/', trim($raw)) ?: [] as $line) {
            $message = json_decode($line, true);
            if (! is_array($message)) {
                continue;
            }
            $error = trim((string) ($message['errorDetail']['message'] ?? $message['error'] ?? ''));
            if ($error !== '') {
                throw new AppException(502, '拉取 Traefik 镜像失败：' . $error);
            }
        }
    }

    private function serviceSpec(ClusterWebGateway $gateway, Cluster $cluster): array
    {
        $args = [
            '--providers.swarm=true',
            '--providers.swarm.endpoint=tcp://' . $gateway->socket_proxy_service_name . ':2375',
            '--providers.swarm.exposedbydefault=false',
            '--providers.swarm.network=' . $gateway->network_name,
            '--providers.swarm.watch=true',
            '--providers.file.directory=/dynamic/',
            '--providers.file.watch=true',
            '--entrypoints.web.address=:80',
            '--entrypoints.websecure.address=:443',
            '--ping=true',
            '--log.level=INFO',
        ];
        if ($gateway->dashboard_enabled) {
            $args[] = '--api.dashboard=true';
            $args[] = '--api.insecure=true';
        } else {
            $args[] = '--api.dashboard=false';
            $args[] = '--api.insecure=false';
        }
        if ($gateway->access_log_enabled) {
            $args[] = '--accesslog=true';
        }
        if ($gateway->metrics_enabled) {
            $args[] = '--metrics.prometheus=true';
            $args[] = '--metrics.prometheus.addrouterslabels=true';
            $args[] = '--metrics.prometheus.addserviceslabels=true';
        }
        if ($gateway->redirect_https) {
            $args[] = '--entrypoints.web.http.redirections.entrypoint.to=websecure';
            $args[] = '--entrypoints.web.http.redirections.entrypoint.scheme=https';
            $args[] = '--entrypoints.web.http.redirections.entrypoint.permanent=true';
        }
        // Traefik's upstream image does not create /dynamic. Keep the file
        // provider directory task-local and writable so TLS/config snapshots
        // can be copied before the first Docker Config is mounted.
        $mounts = [[
            'Type' => 'tmpfs',
            'Target' => '/dynamic',
            'TmpfsOptions' => ['Mode' => 493],
        ]];
        if ($gateway->acme_enabled) {
            $args[] = '--certificatesresolvers.' . $gateway->cert_resolver . '.acme.email=' . $gateway->acme_email;
            $args[] = '--certificatesresolvers.' . $gateway->cert_resolver . '.acme.storage=/letsencrypt/acme.json';
            $args[] = '--certificatesresolvers.' . $gateway->cert_resolver . '.acme.httpchallenge=true';
            $args[] = '--certificatesresolvers.' . $gateway->cert_resolver . '.acme.httpchallenge.entrypoint=web';
            $mounts[] = [
                'Type' => 'volume', 'Source' => $gateway->acme_volume_name,
                'Target' => '/letsencrypt', 'ReadOnly' => false,
            ];
        }
        $labels = [
            'com.codegalaxy.component' => 'web-gateway',
            'com.codegalaxy.cluster-id' => (string) $gateway->cluster_id,
            'traefik.enable' => 'false',
        ];
        $ports = [
            ['Name' => 'web', 'Protocol' => 'tcp', 'TargetPort' => 80,
                'PublishedPort' => (int) $gateway->http_port, 'PublishMode' => (string) $gateway->publish_mode],
            ['Name' => 'websecure', 'Protocol' => 'tcp', 'TargetPort' => 443,
                'PublishedPort' => (int) $gateway->https_port, 'PublishMode' => (string) $gateway->publish_mode],
        ];
        if ($gateway->dashboard_enabled) {
            $ports[] = [
                'Name' => 'dashboard',
                'Protocol' => 'tcp',
                'TargetPort' => 8080,
                'PublishedPort' => (int) $gateway->dashboard_port,
                'PublishMode' => (string) $gateway->publish_mode,
            ];
        }
        return [
            'Name' => (string) $gateway->service_name,
            'Labels' => $labels,
            'TaskTemplate' => [
                'ContainerSpec' => [
                    'Image' => $this->imageMappings->resolve($cluster, (string) $gateway->image),
                    'Args' => $args,
                    'Mounts' => $mounts,
                    'Labels' => $labels,
                    'StopGracePeriod' => 10_000_000_000,
                ],
                'Resources' => [
                    'Limits' => ['NanoCPUs' => 2_000_000_000, 'MemoryBytes' => 536870912],
                    'Reservations' => ['NanoCPUs' => 50_000_000, 'MemoryBytes' => 67108864],
                ],
                'RestartPolicy' => ['Condition' => 'any', 'Delay' => 5_000_000_000],
                'Placement' => ['Constraints' => trim((string) $gateway->placement_node_id) !== ''
                    ? ['node.id==' . $gateway->placement_node_id]
                    : ['node.role==manager']],
                'Networks' => [
                    ['Target' => (string) $gateway->network_id],
                    ['Target' => (string) $gateway->control_network_id],
                ],
            ],
            'Mode' => ['Replicated' => ['Replicas' => (int) $gateway->replicas]],
            'UpdateConfig' => ['Parallelism' => 1, 'Delay' => 5_000_000_000, 'FailureAction' => 'rollback',
                'Order' => $gateway->publish_mode === 'host' ? 'stop-first' : 'start-first'],
            'RollbackConfig' => ['Parallelism' => 1, 'Delay' => 5_000_000_000, 'FailureAction' => 'pause', 'Order' => 'stop-first'],
            'EndpointSpec' => ['Mode' => 'vip', 'Ports' => $ports],
        ];
    }

    private function upsertSocketProxy(
        Client $client,
        SwarmApiClient $api,
        ClusterWebGateway $gateway,
        Cluster $cluster
    ): string
    {
        $serviceId = (string) $gateway->socket_proxy_service_id;
        if ($serviceId === '') {
            $services = $api->request($client, 'GET', '/services', ['query' => [
                'filters' => json_encode(['name' => [(string) $gateway->socket_proxy_service_name]], JSON_THROW_ON_ERROR),
            ]]);
            foreach ($services as $service) {
                if (($service['Spec']['Name'] ?? '') === $gateway->socket_proxy_service_name) {
                    $serviceId = (string) ($service['ID'] ?? '');
                    break;
                }
            }
        }

        $spec = $this->socketProxySpec($gateway, $cluster);
        $runtimeImage = (string) $spec['TaskTemplate']['ContainerSpec']['Image'];
        $headers = $this->registries->dockerAuthHeader((int) $cluster->org_id, $runtimeImage);
        if ($serviceId === '') {
            $created = $api->request($client, 'POST', '/services/create', [
                'headers' => $headers,
                'json' => $spec,
            ]);
            $serviceId = (string) ($created['ID'] ?? '');
            if ($serviceId === '') {
                throw new AppException(502, 'Docker API 未返回 Socket Proxy Service ID');
            }
            return $serviceId;
        }

        $current = $api->request($client, 'GET', '/services/' . rawurlencode($serviceId));
        $spec['TaskTemplate']['ForceUpdate'] = (int) ($current['Spec']['TaskTemplate']['ForceUpdate'] ?? 0) + 1;
        $api->request($client, 'POST', '/services/' . rawurlencode($serviceId) . '/update', [
            'query' => ['version' => (int) ($current['Version']['Index'] ?? 0), 'registryAuthFrom' => 'spec'],
            'headers' => $headers,
            'json' => $spec,
        ]);
        return $serviceId;
    }

    private function socketProxySpec(ClusterWebGateway $gateway, Cluster $cluster): array
    {
        $labels = [
            'com.codegalaxy.component' => 'web-gateway-socket-proxy',
            'com.codegalaxy.cluster-id' => (string) $gateway->cluster_id,
            'traefik.enable' => 'false',
        ];
        return [
            'Name' => (string) $gateway->socket_proxy_service_name,
            'Labels' => $labels,
            'TaskTemplate' => [
                'ContainerSpec' => [
                    'Image' => $this->imageMappings->resolve($cluster, (string) $gateway->socket_proxy_image),
                    'Env' => [
                        'LOG_LEVEL=warning', 'POST=0', 'AUTH=0', 'SECRETS=0', 'CONFIGS=0',
                        'CONTAINERS=0', 'IMAGES=0', 'VOLUMES=0', 'BUILD=0', 'COMMIT=0', 'EXEC=0',
                        'PING=1', 'VERSION=1', 'INFO=1', 'EVENTS=1', 'SWARM=1',
                        'SERVICES=1', 'TASKS=1', 'NETWORKS=1', 'NODES=1',
                    ],
                    'Mounts' => [[
                        'Type' => 'bind', 'Source' => '/var/run/docker.sock',
                        'Target' => '/var/run/docker.sock', 'ReadOnly' => true,
                    ]],
                    'Labels' => $labels,
                    'StopGracePeriod' => 5_000_000_000,
                ],
                'Resources' => [
                    'Limits' => ['NanoCPUs' => 250_000_000, 'MemoryBytes' => 134217728],
                    'Reservations' => ['NanoCPUs' => 10_000_000, 'MemoryBytes' => 16777216],
                ],
                'RestartPolicy' => ['Condition' => 'any', 'Delay' => 3_000_000_000],
                'Placement' => ['Constraints' => trim((string) $gateway->placement_node_id) !== ''
                    ? ['node.id==' . $gateway->placement_node_id]
                    : ['node.role==manager']],
                'Networks' => [['Target' => (string) $gateway->control_network_id]],
            ],
            'Mode' => ['Replicated' => ['Replicas' => 1]],
            'UpdateConfig' => ['Parallelism' => 1, 'Delay' => 3_000_000_000,
                'FailureAction' => 'rollback', 'Order' => 'stop-first'],
            'RollbackConfig' => ['Parallelism' => 1, 'Delay' => 3_000_000_000,
                'FailureAction' => 'pause', 'Order' => 'stop-first'],
            // DNSRR, not VIP: the control network is an encrypted overlay, whose VIP ARP
            // does not get answered, so a VIP-based endpoint is unreachable from Traefik.
            // DNSRR resolves the service name straight to the container IP, which is reachable.
            // The socket proxy is single-replica and publishes no ports, so DNSRR is safe.
            'EndpointSpec' => ['Mode' => 'dnsrr'],
        ];
    }

    private function defaults(): array
    {
        return [
            'provider' => ClusterWebGateway::PROVIDER_TRAEFIK,
            'image' => self::DEFAULT_IMAGE,
            'socket_proxy_image' => self::DEFAULT_SOCKET_PROXY_IMAGE,
            'service_name' => self::DEFAULT_SERVICE,
            'network_name' => self::DEFAULT_NETWORK,
            'control_network_name' => self::DEFAULT_CONTROL_NETWORK,
            'http_port' => 80, 'https_port' => 443, 'publish_mode' => 'ingress', 'replicas' => 1,
            'redirect_https' => false, 'access_log_enabled' => true, 'metrics_enabled' => true,
            'dashboard_enabled' => false, 'dashboard_port' => 8080,
            'acme_enabled' => false, 'acme_email' => '', 'cert_resolver' => 'letsencrypt',
        ];
    }

    private function dashboardProfile(ClusterWebGateway $gateway): array
    {
        $enabled = (bool) $gateway->dashboard_enabled;
        $address = '';
        if ($enabled) {
            $node = ClusterAgentNode::where('cluster_id', (int) $gateway->cluster_id)
                ->where('node_id', (string) $gateway->placement_node_id)
                ->first(['node_addr']);
            $address = trim((string) ($node?->node_addr ?? ''));
        }
        if ($address !== '' && str_contains($address, ':')
            && ! str_starts_with($address, '[')) {
            $address = '[' . $address . ']';
        }
        return [
            'enabled' => $enabled,
            'port' => (int) $gateway->dashboard_port,
            'url' => $enabled && $address !== ''
                ? 'http://' . $address . ':' . (int) $gateway->dashboard_port . '/dashboard/'
                : '',
        ];
    }
}

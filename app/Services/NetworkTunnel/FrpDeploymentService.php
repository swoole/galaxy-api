<?php

namespace App\Services\NetworkTunnel;

use App\Exception\AppException;
use App\Model\Cluster;
use App\Model\FrpClient;
use App\Model\FrpServer;
use App\Services\Docker\SwarmApiClient;
use App\Services\Encrypt\EncryptService;
use App\Services\Kubernetes\KubernetesApiClient;
use App\Services\Kubernetes\KubernetesClusterService;
use App\Services\ContainerImageMappingService;
use App\Services\RegistryService;
use GuzzleHttp\Client;
use Swoole\Coroutine;
use Throwable;

final class FrpDeploymentService
{
    public function __construct(
        private SwarmApiClient $swarm,
        private KubernetesClusterService $kubernetes,
        private KubernetesApiClient $kubernetesApi,
        private EncryptService $cipher,
        private FrpConfigRenderer $renderer,
        private ContainerImageMappingService $imageMappings,
        private RegistryService $registries
    ) {}

    public function sync(FrpServer $server): void
    {
        $server->load(['cluster', 'clients.cluster', 'clients.tunnels']);
        $unsupportedClient = $server->clients->first(
            static fn (FrpClient $client): bool => (string) $client->deployment_mode !== 'managed'
        );
        if ($unsupportedClient instanceof FrpClient) {
            throw new AppException(
                409,
                sprintf(
                    'FRPC「%s」不是 Galaxy 托管资源，当前架构禁止同步外部 FRPC',
                    (string) $unsupportedClient->title
                )
            );
        }
        $token = $this->cipher->decryptFast((string) $server->auth_token_ciphertext);
        if ($token === '') {
            throw new AppException(422, 'FRP 认证 Token 为空');
        }
        if ((string) $server->deployment_mode === 'external') {
            $server->runtime_name = '';
            $server->runtime_ref = '';
            $server->runtime_status = 'external';
            $server->config_hash = '';
        } else {
            if (! $server->cluster instanceof Cluster) {
                throw new AppException(422, 'Galaxy 托管 FRPS 缺少运行集群');
            }
            $serverConfig = $this->renderer->server($server, $token);
            $this->deploy($server->cluster, 'server', (int) $server->id, (string) $server->image, $serverConfig, $this->serverPorts($server), (string) $server->namespace);
            if ((string) $server->management_mode === 'automatic'
                && (string) $server->cluster->orchestrator_type === Cluster::ORCHESTRATOR_KUBERNETES) {
                $discoveredHost = $this->kubernetesServiceAddress(
                    $server->cluster,
                    (string) $server->namespace,
                    $this->runtimeName('server', (int) $server->id)
                );
                if ($discoveredHost !== '') {
                    $server->advertise_host = $discoveredHost;
                }
            }
            $server->runtime_name = $this->runtimeName('server', (int) $server->id);
            $server->runtime_ref = $server->runtime_name;
            $server->runtime_status = 'running';
            $server->config_hash = hash('sha256', $serverConfig);
        }
        $server->last_error = '';
        $server->updated_at = time();
        $server->save();

        foreach ($server->clients as $client) {
            try {
                $config = $this->renderer->client($client, $server, $token);
                if ((string) $client->deployment_mode === 'external') {
                    $client->runtime_name = '';
                    $client->runtime_ref = '';
                    $client->runtime_status = 'external';
                } else {
                    if (! $client->cluster instanceof Cluster) {
                        throw new AppException(422, 'Galaxy 托管 FRPC 缺少运行集群');
                    }
                    $sourceServices = $client->tunnels
                        ->where('enabled', true)
                        ->map(static fn ($tunnel): array => [
                            'ref' => (string) $tunnel->source_service_ref,
                            'name' => (string) $tunnel->source_service_name,
                        ])
                        ->filter(static fn (array $service): bool => $service['ref'] !== '' || $service['name'] !== '')
                        ->unique(static fn (array $service): string => $service['ref'] . '|' . $service['name'])
                        ->values()
                        ->all();
                    $this->deploy(
                        $client->cluster,
                        'client',
                        (int) $client->id,
                        (string) $client->image,
                        $config,
                        [],
                        (string) $client->namespace,
                        $sourceServices
                    );
                    $client->runtime_name = $this->runtimeName('client', (int) $client->id);
                    $client->runtime_ref = $client->runtime_name;
                    $client->runtime_status = 'running';
                }
                $client->last_error = '';
                $client->config_hash = hash('sha256', $config);
            } catch (Throwable $e) {
                $client->runtime_status = 'error';
                $client->last_error = mb_substr($e->getMessage(), 0, 2000);
            }
            $client->updated_at = time();
            $client->save();
        }
    }

    public function removeClient(FrpClient $client): void
    {
        if ((string) $client->deployment_mode === 'external') {
            return;
        }
        $cluster = Cluster::where('id', (int) $client->cluster_id)
            ->where('org_id', (int) $client->org_id)->first();
        if ($cluster !== null) {
            $this->remove($cluster, 'client', (int) $client->id, (string) $client->namespace);
        }
    }

    public function removeServer(FrpServer $server): void
    {
        $server->load('clients');
        foreach ($server->clients as $client) {
            $this->removeClient($client);
        }
        if ((string) $server->deployment_mode !== 'external') {
            $cluster = Cluster::where('id', (int) $server->cluster_id)
                ->where('org_id', (int) $server->org_id)->first();
            if ($cluster !== null) {
                $this->remove($cluster, 'server', (int) $server->id, (string) $server->namespace);
            }
        }
    }

    private function deploy(
        Cluster $cluster,
        string $role,
        int $id,
        string $image,
        string $config,
        array $ports,
        string $namespace,
        array $sourceServices = []
    ): void {
        $image = $this->imageMappings->resolve($cluster, $image);
        match ((string) $cluster->orchestrator_type) {
            Cluster::ORCHESTRATOR_DOCKER_SWARM => $this->deploySwarm(
                $cluster,
                $role,
                $id,
                $image,
                $config,
                $ports,
                $sourceServices
            ),
            Cluster::ORCHESTRATOR_KUBERNETES => $this->deployKubernetes($cluster, $role, $id, $image, $config, $ports, $namespace),
            default => throw new AppException(422, '该集群编排类型不支持 FRP'),
        };
    }

    private function deploySwarm(
        Cluster $cluster,
        string $role,
        int $id,
        string $image,
        string $config,
        array $ports,
        array $sourceServices
    ): void {
        $name = $this->runtimeName($role, $id);
        $secretName = $name . '-config-' . substr(hash('sha256', $config), 0, 12);
        $this->swarm->withCluster($cluster, function (Client $client, SwarmApiClient $api) use (
            $cluster, $role, $id, $image, $config, $ports, $name, $secretName, $sourceServices
        ): void {
            $secrets = $api->request($client, 'GET', '/secrets', ['query' => [
                'filters' => json_encode(['name' => [$secretName]], JSON_THROW_ON_ERROR),
            ]]);
            $secretId = (string) ($secrets[0]['ID'] ?? '');
            if ($secretId === '') {
                $created = $api->request($client, 'POST', '/secrets/create', ['json' => [
                    'Name' => $secretName,
                    'Labels' => $this->labels($role, $id),
                    'Data' => base64_encode($config),
                ]]);
                $secretId = (string) ($created['ID'] ?? '');
            }
            if ($secretId === '') {
                throw new AppException(502, 'Docker API 未返回 FRP Secret ID');
            }
            $networkIds = $role === 'client'
                ? $this->sourceServiceNetworkIds($client, $api, $sourceServices)
                : [];
            $spec = $this->swarmSpec(
                $role,
                $id,
                $name,
                $image,
                $secretId,
                $secretName,
                $ports,
                $networkIds
            );
            $services = $api->request($client, 'GET', '/services', ['query' => [
                'filters' => json_encode(['name' => [$name]], JSON_THROW_ON_ERROR),
            ]]);
            if ($services === []) {
                $created = $api->request($client, 'POST', '/services/create', [
                    'headers' => $this->registries->dockerAuthHeader((int) $cluster->org_id, $image),
                    'json' => $spec,
                ]);
                if ((string) ($created['ID'] ?? '') === '') {
                    throw new AppException(502, 'Docker API 未返回 FRP Service ID');
                }
                $this->waitForSwarmService($client, $api, $name);
                return;
            }
            $current = $services[0];
            $version = (int) ($current['Version']['Index'] ?? 0);
            $api->request($client, 'POST', '/services/' . rawurlencode((string) $current['ID']) . '/update', [
                'query' => ['version' => $version, 'registryAuthFrom' => 'spec'],
                'headers' => $this->registries->dockerAuthHeader((int) $cluster->org_id, $image),
                'json' => $spec,
            ]);
            $this->waitForSwarmService($client, $api, $name);
        }, 60);
    }

    private function swarmSpec(
        string $role,
        int $id,
        string $name,
        string $image,
        string $secretId,
        string $secretName,
        array $ports,
        array $networkIds
    ): array {
        $binary = $role === 'server' ? 'frps' : 'frpc';
        $taskTemplate = [
            'ContainerSpec' => [
                'Image' => $image,
                'Args' => ['-c', '/etc/frp/' . $binary . '.toml'],
                'Secrets' => [[
                    'SecretID' => $secretId,
                    'SecretName' => $secretName,
                    'File' => ['Name' => '/etc/frp/' . $binary . '.toml', 'UID' => '0', 'GID' => '0', 'Mode' => 256],
                ]],
                'Labels' => $this->labels($role, $id),
            ],
            'RestartPolicy' => ['Condition' => 'any', 'Delay' => 5_000_000_000],
            'Networks' => array_map(
                static fn (string $networkId): array => ['Target' => $networkId],
                $networkIds
            ),
        ];
        if ($role === 'server') {
            $taskTemplate['Placement'] = ['Constraints' => ['node.role == manager']];
        }

        return [
            'Name' => $name,
            'Labels' => $this->labels($role, $id),
            'TaskTemplate' => $taskTemplate,
            'Mode' => ['Replicated' => ['Replicas' => 1]],
            'EndpointSpec' => ['Mode' => 'vip', 'Ports' => array_map(static fn (array $port): array => [
                'Name' => $port['name'],
                'Protocol' => $port['protocol'],
                'TargetPort' => $port['port'],
                'PublishedPort' => $port['port'],
                'PublishMode' => 'ingress',
            ], $ports)],
        ];
    }

    private function sourceServiceNetworkIds(
        Client $client,
        SwarmApiClient $api,
        array $sourceServices
    ): array {
        $networks = [];
        foreach ($sourceServices as $sourceService) {
            $service = null;
            $reference = trim((string) ($sourceService['ref'] ?? ''));
            if ($reference !== '') {
                try {
                    $service = $api->request(
                        $client,
                        'GET',
                        '/services/' . rawurlencode($reference)
                    );
                } catch (Throwable) {
                    $service = null;
                }
            }
            if (! is_array($service)) {
                $name = trim((string) ($sourceService['name'] ?? ''));
                if ($name !== '') {
                    $matches = $api->request($client, 'GET', '/services', ['query' => [
                        'filters' => json_encode(['name' => [$name]], JSON_THROW_ON_ERROR),
                    ]]);
                    $service = $matches[0] ?? null;
                }
            }
            foreach ((array) ($service['Spec']['TaskTemplate']['Networks'] ?? []) as $network) {
                $networkId = trim((string) ($network['Target'] ?? ''));
                if ($networkId !== '') {
                    $networks[$networkId] = $networkId;
                }
            }
        }

        return array_values($networks);
    }

    private function kubernetesServiceAddress(
        Cluster $cluster,
        string $namespace,
        string $name
    ): string {
        [, , $credential] = $this->kubernetes->connectionWithCredential(
            (int) $cluster->org_id,
            (int) $cluster->id
        );
        $service = $this->kubernetesApi->get(
            $credential,
            $this->k8sPath('api/v1', $namespace, 'services', $name)
        );
        foreach ((array) ($service['status']['loadBalancer']['ingress'] ?? []) as $ingress) {
            $address = trim((string) ($ingress['ip'] ?? $ingress['hostname'] ?? ''));
            if ($address !== '') {
                return $address;
            }
        }
        foreach ((array) ($service['spec']['externalIPs'] ?? []) as $address) {
            if (trim((string) $address) !== '') {
                return trim((string) $address);
            }
        }

        return trim((string) ($service['spec']['loadBalancerIP'] ?? ''));
    }

    private function deployKubernetes(
        Cluster $cluster,
        string $role,
        int $id,
        string $image,
        string $config,
        array $ports,
        string $namespace
    ): void {
        [, , $credential] = $this->kubernetes->connectionWithCredential((int) $cluster->org_id, (int) $cluster->id);
        $name = $this->runtimeName($role, $id);
        $binary = $role === 'server' ? 'frps' : 'frpc';
        $configHash = hash('sha256', $config);
        $labels = $this->labels($role, $id) + ['app.kubernetes.io/name' => $name];
        $this->apply($credential, '/api/v1/namespaces/' . rawurlencode($namespace), [
            'apiVersion' => 'v1', 'kind' => 'Namespace',
            'metadata' => ['name' => $namespace, 'labels' => ['app.kubernetes.io/managed-by' => 'galaxy']],
        ]);
        $this->apply($credential, $this->k8sPath('api/v1', $namespace, 'secrets', $name . '-config'), [
            'apiVersion' => 'v1', 'kind' => 'Secret', 'type' => 'Opaque',
            'metadata' => ['name' => $name . '-config', 'namespace' => $namespace, 'labels' => $labels],
            'data' => [$binary . '.toml' => base64_encode($config)],
        ]);
        $containerPorts = array_map(static fn (array $port): array => [
            'name' => substr($port['name'], 0, 15),
            'containerPort' => $port['port'],
            'protocol' => strtoupper($port['protocol']),
        ], $ports);
        $imagePullSecrets = [];
        $dockerConfig = $this->registries->kubernetesDockerConfigJson((int) $cluster->org_id, $image);
        if ($dockerConfig !== null) {
            $registrySecret = $name . '-registry';
            $this->apply($credential, $this->k8sPath('api/v1', $namespace, 'secrets', $registrySecret), [
                'apiVersion' => 'v1', 'kind' => 'Secret', 'type' => 'kubernetes.io/dockerconfigjson',
                'metadata' => ['name' => $registrySecret, 'namespace' => $namespace, 'labels' => $labels],
                'data' => ['.dockerconfigjson' => base64_encode($dockerConfig)],
            ]);
            $imagePullSecrets[] = ['name' => $registrySecret];
        }
        $podSpec = [
            'containers' => [[
                'name' => $binary,
                'image' => $image,
                'imagePullPolicy' => 'IfNotPresent',
                'args' => ['-c', '/etc/frp/' . $binary . '.toml'],
                'ports' => $containerPorts,
                'volumeMounts' => [[
                    'name' => 'config', 'mountPath' => '/etc/frp/' . $binary . '.toml',
                    'subPath' => $binary . '.toml', 'readOnly' => true,
                ]],
            ]],
            'volumes' => [['name' => 'config', 'secret' => ['secretName' => $name . '-config']]],
            'imagePullSecrets' => $imagePullSecrets,
        ];
        $deploymentPath = $this->k8sPath('apis/apps/v1', $namespace, 'deployments', $name);
        $this->ensureRecreateStrategy($credential, $deploymentPath);
        $this->apply($credential, $deploymentPath, [
            'apiVersion' => 'apps/v1', 'kind' => 'Deployment',
            'metadata' => ['name' => $name, 'namespace' => $namespace, 'labels' => $labels],
            'spec' => [
                'replicas' => 1,
                'selector' => ['matchLabels' => ['app.kubernetes.io/name' => $name]],
                // FRPC proxy names and FRPS listen ports are single-owner
                // resources. Running old and new Pods concurrently makes the
                // new client lose registration with "proxy already exists".
                'strategy' => ['type' => 'Recreate'],
                'template' => [
                    'metadata' => [
                        'labels' => $labels,
                        'annotations' => ['codegalaxy.com/config-hash' => $configHash],
                    ],
                    'spec' => $podSpec,
                ],
            ],
        ]);
        if ($role === 'server') {
            $this->apply($credential, $this->k8sPath('api/v1', $namespace, 'services', $name), [
                'apiVersion' => 'v1', 'kind' => 'Service',
                'metadata' => ['name' => $name, 'namespace' => $namespace, 'labels' => $labels],
                'spec' => [
                    'type' => 'LoadBalancer',
                    'selector' => ['app.kubernetes.io/name' => $name],
                    'ports' => array_map(static fn (array $port): array => [
                        'name' => substr($port['name'], 0, 15),
                        'protocol' => strtoupper($port['protocol']),
                        'port' => $port['port'],
                        'targetPort' => $port['port'],
                    ], $ports),
                ],
            ]);
        }
        $this->waitForKubernetesDeployment($credential, $namespace, $name);
    }

    private function ensureRecreateStrategy(array $credential, string $deploymentPath): void
    {
        try {
            $deployment = $this->kubernetesApi->get($credential, $deploymentPath);
        } catch (AppException $e) {
            if (str_contains($e->getMessage(), 'HTTP 404')) {
                return;
            }
            throw $e;
        }
        if ((string) ($deployment['spec']['strategy']['type'] ?? '') === 'Recreate'
            && ! isset($deployment['spec']['strategy']['rollingUpdate'])) {
            return;
        }
        $this->kubernetesApi->request($credential, 'PATCH', $deploymentPath, [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'body' => json_encode([
                'spec' => ['strategy' => ['type' => 'Recreate', 'rollingUpdate' => null]],
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);
    }

    private function remove(Cluster $cluster, string $role, int $id, string $namespace): void
    {
        $name = $this->runtimeName($role, $id);
        if ((string) $cluster->orchestrator_type === Cluster::ORCHESTRATOR_DOCKER_SWARM) {
            $this->swarm->withCluster($cluster, function (Client $client, SwarmApiClient $api) use ($name, $role, $id): void {
                $services = $api->request($client, 'GET', '/services', ['query' => [
                    'filters' => json_encode(['name' => [$name]], JSON_THROW_ON_ERROR),
                ]]);
                if ($services !== []) {
                    $api->request($client, 'DELETE', '/services/' . rawurlencode((string) $services[0]['ID']));
                }
                $secrets = $api->request($client, 'GET', '/secrets', ['query' => [
                    'filters' => json_encode(['label' => [
                        'codegalaxy.com/resource=network-tunnel',
                        'codegalaxy.com/frp-role=' . $role,
                        'codegalaxy.com/frp-id=' . $id,
                    ]], JSON_THROW_ON_ERROR),
                ]]);
                foreach ($secrets as $secret) {
                    try {
                        $api->request($client, 'DELETE', '/secrets/' . rawurlencode((string) $secret['ID']));
                    } catch (Throwable) {
                        // Swarm may keep the Secret referenced briefly while the
                        // removed Service converges. A later delete/reconcile can retry.
                    }
                }
            });
            return;
        }
        if ((string) $cluster->orchestrator_type === Cluster::ORCHESTRATOR_KUBERNETES) {
            [, , $credential] = $this->kubernetes->connectionWithCredential((int) $cluster->org_id, (int) $cluster->id);
            foreach ([
                $this->k8sPath('apis/apps/v1', $namespace, 'deployments', $name),
                $this->k8sPath('api/v1', $namespace, 'secrets', $name . '-config'),
                $this->k8sPath('api/v1', $namespace, 'secrets', $name . '-registry'),
                ...($role === 'server'
                    ? [$this->k8sPath('api/v1', $namespace, 'services', $name)]
                    : []),
            ] as $path) {
                try {
                    $this->kubernetesApi->request($credential, 'DELETE', $path, ['json' => [
                        'apiVersion' => 'v1', 'kind' => 'DeleteOptions',
                    ]]);
                } catch (AppException $e) {
                    if (! str_contains($e->getMessage(), 'HTTP 404')) {
                        throw $e;
                    }
                }
            }
        }
    }

    private function serverPorts(FrpServer $server): array
    {
        $ports = [['name' => 'control', 'protocol' => 'tcp', 'port' => (int) $server->bind_port]];
        if ((int) $server->vhost_http_port > 0) {
            $ports[] = ['name' => 'vhost-http', 'protocol' => 'tcp', 'port' => (int) $server->vhost_http_port];
        }
        if ((int) $server->vhost_https_port > 0) {
            $ports[] = ['name' => 'vhost-https', 'protocol' => 'tcp', 'port' => (int) $server->vhost_https_port];
        }
        if ((int) $server->dashboard_port > 0) {
            $ports[] = ['name' => 'dashboard', 'protocol' => 'tcp', 'port' => (int) $server->dashboard_port];
        }
        foreach ($server->clients as $client) {
            if ((string) $client->deployment_mode !== 'managed') {
                continue;
            }
            foreach ($client->tunnels as $tunnel) {
                if ($tunnel->enabled && in_array((string) $tunnel->type, ['tcp', 'udp'], true)) {
                    $ports[] = [
                        'name' => 'p' . (int) $tunnel->remote_port . '-' . (string) $tunnel->type,
                        'protocol' => (string) $tunnel->type,
                        'port' => (int) $tunnel->remote_port,
                    ];
                }
            }
        }
        $unique = [];
        foreach ($ports as $port) {
            $unique[$port['protocol'] . ':' . $port['port']] = $port;
        }
        return array_values($unique);
    }

    private function labels(string $role, int $id): array
    {
        return [
            'app.kubernetes.io/managed-by' => 'galaxy',
            'codegalaxy.com/resource' => 'network-tunnel',
            'codegalaxy.com/frp-role' => $role,
            'codegalaxy.com/frp-id' => (string) $id,
        ];
    }

    private function runtimeName(string $role, int $id): string
    {
        return 'galaxy-frp' . ($role === 'server' ? 's-' : 'c-') . $id;
    }

    private function apply(array $credential, string $path, array $resource): array
    {
        return $this->kubernetesApi->request($credential, 'PATCH', $path, [
            'query' => ['fieldManager' => 'galaxy-frp', 'force' => 'true'],
            'headers' => ['Content-Type' => 'application/apply-patch+yaml'],
            'body' => json_encode($resource, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);
    }

    private function k8sPath(string $apiVersion, string $namespace, string $kind, string $name): string
    {
        return '/' . $apiVersion . '/namespaces/' . rawurlencode($namespace)
            . '/' . $kind . '/' . rawurlencode($name);
    }

    private function waitForSwarmService(Client $client, SwarmApiClient $api, string $name): void
    {
        $lastError = '';
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $tasks = $api->request($client, 'GET', '/tasks', ['query' => [
                'filters' => json_encode(['service' => [$name]], JSON_THROW_ON_ERROR),
            ]]);
            foreach ($tasks as $task) {
                $state = (string) ($task['Status']['State'] ?? '');
                if ($state === 'running') {
                    return;
                }
                $error = trim((string) ($task['Status']['Err'] ?? ''));
                if ($error !== '') {
                    $lastError = $error;
                }
            }
            Coroutine::sleep(1);
        }
        throw new AppException(502, 'FRP Service 未能就绪' . ($lastError === '' ? '' : '：' . $lastError));
    }

    private function waitForKubernetesDeployment(array $credential, string $namespace, string $name): void
    {
        $lastError = '';
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $deployment = $this->kubernetesApi->get(
                $credential,
                $this->k8sPath('apis/apps/v1', $namespace, 'deployments', $name)
            );
            if ((int) ($deployment['status']['availableReplicas'] ?? 0) >= 1) {
                return;
            }
            $pods = $this->kubernetesApi->request(
                $credential,
                'GET',
                '/api/v1/namespaces/' . rawurlencode($namespace) . '/pods',
                ['query' => ['labelSelector' => 'app.kubernetes.io/name=' . $name]]
            );
            foreach ((array) ($pods['items'] ?? []) as $pod) {
                foreach ((array) ($pod['status']['containerStatuses'] ?? []) as $status) {
                    $waiting = (array) ($status['state']['waiting'] ?? []);
                    $message = trim((string) ($waiting['message'] ?? $waiting['reason'] ?? ''));
                    if ($message !== '') {
                        $lastError = $message;
                    }
                }
            }
            if ($lastError !== '' && preg_match('/(?:ErrImagePull|ImagePullBackOff|failed to pull)/i', $lastError)) {
                break;
            }
            Coroutine::sleep(1);
        }
        throw new AppException(502, 'FRP Deployment 未能就绪' . ($lastError === '' ? '' : '：' . $lastError));
    }
}

<?php

namespace App\Services\Kubernetes;

use App\Exception\AppException;
use App\Model\Cluster;
use App\Model\EnvClusterRel;
use App\Model\KubernetesClusterConnection;
use App\Model\ProjectRoute;
use App\Model\ProjectRuntime;
use App\Services\Encrypt\CredentialCipher;
use App\Services\Gateway\TlsCertificateService;
use Hyperf\DbConnection\Db;
use Throwable;

final class KubernetesClusterService
{
    private const MANAGED_RESOURCE_QUOTA = 'galaxy-resource-quota';

    private const MANAGED_LIMIT_RANGE = 'galaxy-default-limits';

    private const INGRESS_CERTIFICATE_ANNOTATION = 'codegalaxy.com/tls-certificate-id';

    private const INGRESS_HTTPS_REDIRECT_ANNOTATION = 'codegalaxy.com/https-redirect';

    private const INGRESS_HTTPS_REDIRECT_PORT_ANNOTATION = 'codegalaxy.com/https-redirect-port';

    private const INGRESS_HTTP_COMPANION_ANNOTATION = 'codegalaxy.com/http-companion-for';

    public function __construct(
        private KubeconfigParser $kubeconfig,
        private KubernetesApiClient $api,
        private CredentialCipher $cipher,
        private TlsCertificateService $tlsCertificates
    ) {}

    public function list(int $orgId): array
    {
        return Cluster::where('org_id', $orgId)
            ->where('orchestrator_type', Cluster::ORCHESTRATOR_KUBERNETES)
            ->select([
                'id', 'org_id', 'title', 'remark', 'orchestrator_type', 'status',
                'version', 'creator', 'created_at',
            ])
            ->with(['kubernetesConnection' => static fn ($query) => $query->select([
                'cluster_id', 'server_url', 'context_name', 'default_namespace',
                'ingress_http_port', 'ingress_https_port',
                'status', 'version', 'last_checked_at', 'last_error',
            ])])
            ->with(['creatorInfo' => static fn ($query) => $query->where('org_id', $orgId)])
            ->orderBy('id')
            ->get()
            ->map(fn (Cluster $cluster): array => $this->publicCluster($cluster))
            ->all();
    }

    public function create(int $uid, int $orgId, string $title, string $remark, string $kubeconfig): Cluster
    {
        if (Cluster::where('org_id', $orgId)->where('title', $title)->exists()) {
            throw new AppException(422, '该集群名称已存在');
        }
        $credential = $this->kubeconfig->parse($kubeconfig);
        $version = $this->api->get($credential, '/version');
        $gitVersion = trim((string) ($version['gitVersion'] ?? ''));
        if ($gitVersion === '') {
            throw new AppException(502, 'Kubernetes API /version 未返回 gitVersion');
        }
        $encodedCredential = json_encode($credential, JSON_THROW_ON_ERROR);
        $now = time();

        return Db::transaction(function () use (
            $uid,
            $orgId,
            $title,
            $remark,
            $credential,
            $gitVersion,
            $encodedCredential,
            $now
        ): Cluster {
            /** @var Cluster $cluster */
            $cluster = Cluster::create([
                'org_id' => $orgId,
                'vendor' => 0,
                'type' => Cluster::TYPE_SELF_PAY,
                'title' => $title,
                'version' => $gitVersion,
                'orchestrator_type' => Cluster::ORCHESTRATOR_KUBERNETES,
                'endpoint' => (string) $credential['server'],
                'resolve' => '',
                'remark' => $remark,
                'extra' => new \stdClass(),
                'status' => Cluster::STATUS_READY,
                'source' => Cluster::SOURCE_FILL,
                'registration_status' => 'registered',
                'agent_status' => 'not_applicable',
                'registered_at' => $now,
                'creator' => $uid,
                'created_at' => $now,
            ]);
            KubernetesClusterConnection::create([
                'cluster_id' => (int) $cluster->id,
                'server_url' => (string) $credential['server'],
                'context_name' => (string) $credential['context_name'],
                'cluster_name' => (string) $credential['cluster_name'],
                'user_name' => (string) $credential['user_name'],
                'default_namespace' => (string) $credential['namespace'],
                'ingress_http_port' => 80,
                'ingress_https_port' => 443,
                'credential_ciphertext' => $this->cipher->encrypt($encodedCredential),
                'credential_fingerprint' => hash('sha256', $encodedCredential),
                'status' => 'online',
                'version' => $gitVersion,
                'last_checked_at' => $now,
                'last_error' => '',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            return $cluster;
        });
    }

    public function updateCredential(int $orgId, int $clusterId, string $kubeconfig): array
    {
        [$cluster, $connection] = $this->connection($orgId, $clusterId);
        $credential = $this->kubeconfig->parse($kubeconfig);
        $version = $this->api->get($credential, '/version');
        $gitVersion = trim((string) ($version['gitVersion'] ?? ''));
        if ($gitVersion === '') {
            throw new AppException(502, 'Kubernetes API /version 未返回 gitVersion');
        }
        $encodedCredential = json_encode($credential, JSON_THROW_ON_ERROR);
        $now = time();

        Db::transaction(function () use (
            $cluster,
            $connection,
            $credential,
            $gitVersion,
            $encodedCredential,
            $now
        ): void {
            $connection->server_url = (string) $credential['server'];
            $connection->context_name = (string) $credential['context_name'];
            $connection->cluster_name = (string) $credential['cluster_name'];
            $connection->user_name = (string) $credential['user_name'];
            $connection->default_namespace = (string) $credential['namespace'];
            $connection->credential_ciphertext = $this->cipher->encrypt($encodedCredential);
            $connection->credential_fingerprint = hash('sha256', $encodedCredential);
            $connection->status = 'online';
            $connection->version = $gitVersion;
            $connection->last_checked_at = $now;
            $connection->last_error = '';
            $connection->updated_at = $now;
            $connection->save();

            $cluster->endpoint = (string) $credential['server'];
            $cluster->status = Cluster::STATUS_READY;
            $cluster->version = $gitVersion;
            $cluster->save();
        });

        return [
            'cluster_id' => (int) $cluster->id,
            'version' => $gitVersion,
            'connection' => $this->publicConnection($connection),
        ];
    }

    public function profile(int $orgId, int $clusterId): array
    {
        [$cluster, $connection] = $this->connection($orgId, $clusterId);
        return [
            'cluster' => $this->publicCluster($cluster),
            'connection' => $this->publicConnection($connection),
        ];
    }

    public function updateIngressPorts(
        int $orgId,
        int $clusterId,
        int $httpPort,
        int $httpsPort
    ): array {
        [, $connection, $credential] = $this->connectionWithCredential($orgId, $clusterId);
        if ($httpPort === $httpsPort) {
            throw new AppException(422, 'HTTP 与 HTTPS 外部端口不能相同');
        }
        $connection->ingress_http_port = $httpPort;
        $connection->ingress_https_port = $httpsPort;
        $connection->updated_at = time();
        $connection->save();

        ProjectRoute::where('org_id', $orgId)
            ->where('cluster_id', $clusterId)
            ->where('orchestrator_type', Cluster::ORCHESTRATOR_KUBERNETES)
            ->update([
                'https_redirect_port' => $httpsPort,
                'updated_at' => time(),
            ]);

        $syncError = '';
        try {
            $this->syncExistingIngressRedirectPorts($credential, $httpsPort);
        } catch (Throwable $e) {
            $syncError = mb_substr($e->getMessage(), 0, 2000);
        }

        return [
            'connection' => $this->publicConnection($connection),
            'sync_error' => $syncError,
        ];
    }

    public function check(int $orgId, int $clusterId): array
    {
        [$cluster, $connection, $credential] = $this->connectionWithCredential($orgId, $clusterId);
        $started = microtime(true);
        try {
            $version = $this->api->get($credential, '/version');
            $this->markOnline($cluster, $connection, (string) ($version['gitVersion'] ?? ''));
            return [
                'online' => true,
                'version' => (string) ($version['gitVersion'] ?? ''),
                'latency_ms' => (int) round((microtime(true) - $started) * 1000),
            ];
        } catch (Throwable $e) {
            $this->markOffline($cluster, $connection, $e->getMessage());
            throw $e;
        }
    }

    public function overview(int $orgId, int $clusterId): array
    {
        [$cluster, $connection, $credential] = $this->connectionWithCredential($orgId, $clusterId);
        $started = microtime(true);
        try {
            $version = $this->api->get($credential, '/version');
            $nodes = $this->api->get($credential, '/api/v1/nodes');
            $namespaces = $this->api->get($credential, '/api/v1/namespaces');
            $pods = $this->api->get($credential, '/api/v1/pods');
            $deployments = $this->api->get($credential, '/apis/apps/v1/deployments');
            $nodeRows = array_map(fn (array $node): array => $this->nodeRow($node), (array) ($nodes['items'] ?? []));
            $podPhases = array_count_values(array_map(
                static fn (array $pod): string => (string) ($pod['status']['phase'] ?? 'Unknown'),
                (array) ($pods['items'] ?? [])
            ));
            $gitVersion = (string) ($version['gitVersion'] ?? '');
            $this->markOnline($cluster, $connection, $gitVersion);
            return [
                'generated_at' => time(),
                'latency_ms' => (int) round((microtime(true) - $started) * 1000),
                'version' => [
                    'git_version' => $gitVersion,
                    'platform' => (string) ($version['platform'] ?? ''),
                    'go_version' => (string) ($version['goVersion'] ?? ''),
                ],
                'summary' => [
                    'nodes' => count($nodeRows),
                    'ready_nodes' => count(array_filter($nodeRows, static fn (array $node): bool => $node['ready'])),
                    'namespaces' => count((array) ($namespaces['items'] ?? [])),
                    'pods' => count((array) ($pods['items'] ?? [])),
                    'running_pods' => (int) ($podPhases['Running'] ?? 0),
                    'deployments' => count((array) ($deployments['items'] ?? [])),
                ],
                'pod_phases' => $podPhases,
                'nodes' => $nodeRows,
                'connection' => $this->publicConnection($connection),
            ];
        } catch (Throwable $e) {
            $this->markOffline($cluster, $connection, $e->getMessage());
            throw $e;
        }
    }

    public function nodes(int $orgId, int $clusterId): array
    {
        [$cluster, $connection, $credential] = $this->connectionWithCredential($orgId, $clusterId);
        try {
            $response = $this->api->get($credential, '/api/v1/nodes');
            $this->markOnline($cluster, $connection, (string) $connection->version);
            $rows = array_map(fn (array $node): array => $this->nodeRow($node), (array) ($response['items'] ?? []));
            $metrics = $this->nodeMetrics($credential);
            $podCounts = $this->nodePodCounts($credential);
            foreach ($rows as &$row) {
                $name = (string) ($row['name'] ?? '');
                $row['usage'] = $metrics[$name] ?? null;
                $row['pod_count'] = $podCounts[$name] ?? null;
            }
            unset($row);
            return $rows;
        } catch (Throwable $e) {
            $this->markOffline($cluster, $connection, $e->getMessage());
            throw $e;
        }
    }

    /**
     * Best-effort node usage from the metrics.k8s.io API. Returns a map of
     * node name => ['cpu' => <quantity>, 'memory' => <quantity>]. When the
     * metrics API is unavailable the result is an empty array and node lists
     * simply show "N/A" for usage.
     *
     * @return array<string, array{cpu: string, memory: string}>
     */
    private function nodeMetrics(array $credential): array
    {
        try {
            $response = $this->api->get($credential, '/apis/metrics.k8s.io/v1beta1/nodes');
        } catch (Throwable) {
            return [];
        }
        $map = [];
        foreach ((array) ($response['items'] ?? []) as $item) {
            $name = (string) ($item['metadata']['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $usage = (array) ($item['usage'] ?? []);
            $map[$name] = [
                'cpu' => (string) ($usage['cpu'] ?? ''),
                'memory' => (string) ($usage['memory'] ?? ''),
            ];
        }
        return $map;
    }

    /**
     * Counts running pods per node so the Pods usage ratio can be computed.
     *
     * @return array<string, int>
     */
    private function nodePodCounts(array $credential): array
    {
        try {
            $response = $this->api->get($credential, '/api/v1/pods');
        } catch (Throwable) {
            return [];
        }
        $counts = [];
        foreach ((array) ($response['items'] ?? []) as $pod) {
            $nodeName = (string) ($pod['spec']['nodeName'] ?? '');
            if ($nodeName === '') {
                continue;
            }
            $counts[$nodeName] = ($counts[$nodeName] ?? 0) + 1;
        }
        return $counts;
    }

    public function nodeDetail(int $orgId, int $clusterId, string $name): array
    {
        [$cluster, $connection, $credential] = $this->connectionWithCredential($orgId, $clusterId);
        try {
            $response = $this->api->get($credential, '/api/v1/nodes/' . rawurlencode($name));
            $this->markOnline($cluster, $connection, (string) $connection->version);
            $node = (array) $response;
            $metrics = $this->nodeMetrics($credential);
            $podCounts = $this->nodePodCounts($credential);
            $usage = $metrics[$name] ?? null;
            $podCount = $podCounts[$name] ?? null;
            $metadata = (array) ($node['metadata'] ?? []);
            $spec = (array) ($node['spec'] ?? []);
            $status = (array) ($node['status'] ?? []);
            $nodeInfo = (array) ($status['nodeInfo'] ?? []);
            $conditions = array_map(static function (array $condition): array {
                return [
                    'type' => (string) ($condition['type'] ?? ''),
                    'status' => (string) ($condition['status'] ?? ''),
                    'reason' => (string) ($condition['reason'] ?? ''),
                    'message' => (string) ($condition['message'] ?? ''),
                    'last_transition_time' => (string) ($condition['lastTransitionTime'] ?? ''),
                ];
            }, (array) ($status['conditions'] ?? []));
            $taints = array_map(static function (array $taint): array {
                return [
                    'key' => (string) ($taint['key'] ?? ''),
                    'value' => (string) ($taint['value'] ?? ''),
                    'effect' => (string) ($taint['effect'] ?? ''),
                ];
            }, (array) ($spec['taints'] ?? []));
            $images = array_values(array_unique(array_map(
                static fn (array $image): string => (string) ($image['names'][0] ?? ''),
                (array) ($nodeInfo['images'] ?? [])
            )));

            return array_merge($this->nodeRow($node), [
                'usage' => $usage,
                'pod_count' => $podCount,
                'unschedulable' => (bool) ($spec['unschedulable'] ?? false),
                'labels' => (array) ($metadata['labels'] ?? []),
                'annotations' => (array) ($metadata['annotations'] ?? []),
                'conditions' => $conditions,
                'taints' => $taints,
                'images' => array_filter($images),
                'node_info' => [
                    'kernel_version' => (string) ($nodeInfo['kernelVersion'] ?? ''),
                    'os_image' => (string) ($nodeInfo['osImage'] ?? ''),
                    'container_runtime_version' => (string) ($nodeInfo['containerRuntimeVersion'] ?? ''),
                    'kubelet_version' => (string) ($nodeInfo['kubeletVersion'] ?? ''),
                    'kube_proxy_version' => (string) ($nodeInfo['kubeProxyVersion'] ?? ''),
                    'operating_system' => (string) ($nodeInfo['operatingSystem'] ?? ''),
                    'architecture' => (string) ($nodeInfo['architecture'] ?? ''),
                    'hostname' => (string) ($nodeInfo['hostname'] ?? ''),
                    'system_uuid' => (string) ($nodeInfo['systemUUID'] ?? ''),
                    'boot_id' => (string) ($nodeInfo['bootID'] ?? ''),
                ],
            ]);
        } catch (Throwable $e) {
            $this->markOffline($cluster, $connection, $e->getMessage());
            throw $e;
        }
    }

    public function namespaces(int $orgId, int $clusterId): array
    {
        [$cluster, $connection, $credential] = $this->connectionWithCredential($orgId, $clusterId);
        try {
            $response = $this->api->get($credential, '/api/v1/namespaces');
            $this->markOnline($cluster, $connection, (string) $connection->version);
            return array_map(static fn (array $namespace): array => [
                'name' => (string) ($namespace['metadata']['name'] ?? ''),
                'status' => (string) ($namespace['status']['phase'] ?? 'Unknown'),
                'labels' => (array) ($namespace['metadata']['labels'] ?? []),
                'annotations' => (array) ($namespace['metadata']['annotations'] ?? []),
                'created_at' => (string) ($namespace['metadata']['creationTimestamp'] ?? ''),
            ], (array) ($response['items'] ?? []));
        } catch (Throwable $e) {
            $this->markOffline($cluster, $connection, $e->getMessage());
            throw $e;
        }
    }

    public function pods(int $orgId, int $clusterId, string $namespace = ''): array
    {
        [, $connection, $credential] = $this->connectionWithCredential($orgId, $clusterId);
        $path = $namespace === ''
            ? '/api/v1/pods'
            : '/api/v1/namespaces/' . rawurlencode($namespace) . '/pods';
        $response = $this->api->get($credential, $path);
        $this->markConnectionHealthy($connection);

        return array_map(static function (array $pod): array {
            $metadata = (array) ($pod['metadata'] ?? []);
            $spec = (array) ($pod['spec'] ?? []);
            $status = (array) ($pod['status'] ?? []);
            $containers = (array) ($spec['containers'] ?? []);
            $containerStatuses = (array) ($status['containerStatuses'] ?? []);
            $ready = count(array_filter(
                $containerStatuses,
                static fn (array $item): bool => (bool) ($item['ready'] ?? false)
            ));
            $restarts = array_sum(array_map(
                static fn (array $item): int => (int) ($item['restartCount'] ?? 0),
                $containerStatuses
            ));
            $waiting = [];
            foreach ($containerStatuses as $containerStatus) {
                $state = (array) ($containerStatus['state'] ?? []);
                $detail = (array) ($state['waiting'] ?? $state['terminated'] ?? []);
                if ((string) ($detail['reason'] ?? '') !== '') {
                    $waiting[] = [
                        'container' => (string) ($containerStatus['name'] ?? ''),
                        'reason' => (string) $detail['reason'],
                        'message' => (string) ($detail['message'] ?? ''),
                    ];
                }
            }
            return [
                'name' => (string) ($metadata['name'] ?? ''),
                'namespace' => (string) ($metadata['namespace'] ?? ''),
                'uid' => (string) ($metadata['uid'] ?? ''),
                'phase' => (string) ($status['phase'] ?? 'Unknown'),
                'ready' => $ready,
                'containers' => count($containers),
                'container_names' => array_values(array_map(
                    static fn (array $container): string => (string) ($container['name'] ?? ''),
                    $containers
                )),
                'restarts' => $restarts,
                'node_name' => (string) ($spec['nodeName'] ?? ''),
                'pod_ip' => (string) ($status['podIP'] ?? ''),
                'images' => array_values(array_unique(array_map(
                    static fn (array $container): string => (string) ($container['image'] ?? ''),
                    $containers
                ))),
                'problems' => $waiting,
                'reason' => (string) ($status['reason'] ?? ''),
                'message' => (string) ($status['message'] ?? ''),
                'created_at' => (string) ($metadata['creationTimestamp'] ?? ''),
                'labels' => (array) ($metadata['labels'] ?? []),
                'owner_kind' => (string) ($metadata['ownerReferences'][0]['kind'] ?? ''),
                'owner_name' => (string) ($metadata['ownerReferences'][0]['name'] ?? ''),
            ];
        }, (array) ($response['items'] ?? []));
    }

    public function deployments(int $orgId, int $clusterId, string $namespace = ''): array
    {
        [, $connection, $credential] = $this->connectionWithCredential($orgId, $clusterId);
        $path = $namespace === ''
            ? '/apis/apps/v1/deployments'
            : '/apis/apps/v1/namespaces/' . rawurlencode($namespace) . '/deployments';
        $response = $this->api->get($credential, $path);
        $this->markConnectionHealthy($connection);

        return array_map(static function (array $deployment): array {
            $metadata = (array) ($deployment['metadata'] ?? []);
            $spec = (array) ($deployment['spec'] ?? []);
            $status = (array) ($deployment['status'] ?? []);
            $containers = (array) ($spec['template']['spec']['containers'] ?? []);
            return [
                'name' => (string) ($metadata['name'] ?? ''),
                'namespace' => (string) ($metadata['namespace'] ?? ''),
                'uid' => (string) ($metadata['uid'] ?? ''),
                'replicas' => (int) ($spec['replicas'] ?? 0),
                'ready_replicas' => (int) ($status['readyReplicas'] ?? 0),
                'available_replicas' => (int) ($status['availableReplicas'] ?? 0),
                'updated_replicas' => (int) ($status['updatedReplicas'] ?? 0),
                'images' => array_values(array_unique(array_map(
                    static fn (array $container): string => (string) ($container['image'] ?? ''),
                    $containers
                ))),
                'labels' => (array) ($metadata['labels'] ?? []),
                'selector' => (array) ($spec['selector']['matchLabels'] ?? []),
                'pod_labels' => (array) ($spec['template']['metadata']['labels'] ?? []),
                'created_at' => (string) ($metadata['creationTimestamp'] ?? ''),
            ];
        }, (array) ($response['items'] ?? []));
    }

    public function deployment(int $orgId, int $clusterId, string $namespace, string $name): array
    {
        [, $connection, $credential] = $this->connectionWithCredential($orgId, $clusterId);
        $response = $this->api->get(
            $credential,
            '/apis/apps/v1/namespaces/' . rawurlencode($namespace)
                . '/deployments/' . rawurlencode($name)
        );
        $this->markConnectionHealthy($connection);
        return $response;
    }

    public function claimDeployment(
        int $orgId,
        int $clusterId,
        string $namespace,
        string $name,
        array $labels
    ): array {
        [, $connection, $credential] = $this->connectionWithCredential($orgId, $clusterId);
        $path = '/apis/apps/v1/namespaces/' . rawurlencode($namespace)
            . '/deployments/' . rawurlencode($name);
        $current = $this->api->get($credential, $path);
        $existing = (array) ($current['metadata']['labels'] ?? []);
        if (($existing['app.kubernetes.io/managed-by'] ?? '') === 'galaxy'
            && ((int) ($existing['codegalaxy.com/org-id'] ?? 0) !== (int) ($labels['codegalaxy.com/org-id'] ?? 0)
                || (int) ($existing['codegalaxy.com/project-id'] ?? 0) !== (int) ($labels['codegalaxy.com/project-id'] ?? 0))) {
            throw new AppException(409, '该 Kubernetes Deployment 已由其他 Galaxy 项目管理');
        }
        $claimed = $this->api->request($credential, 'PATCH', $path, [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['metadata' => ['labels' => array_merge($existing, $labels)]],
        ]);
        $this->markConnectionHealthy($connection);
        return $claimed;
    }

    public function services(int $orgId, int $clusterId, string $namespace = ''): array
    {
        [, $connection, $credential] = $this->connectionWithCredential($orgId, $clusterId);
        $path = $namespace === ''
            ? '/api/v1/services'
            : '/api/v1/namespaces/' . rawurlencode($namespace) . '/services';
        $response = $this->api->get($credential, $path);
        $this->markConnectionHealthy($connection);

        return array_map(static function (array $service): array {
            $metadata = (array) ($service['metadata'] ?? []);
            $spec = (array) ($service['spec'] ?? []);
            return [
                'name' => (string) ($metadata['name'] ?? ''),
                'namespace' => (string) ($metadata['namespace'] ?? ''),
                'uid' => (string) ($metadata['uid'] ?? ''),
                'type' => (string) ($spec['type'] ?? 'ClusterIP'),
                'cluster_ip' => (string) ($spec['clusterIP'] ?? ''),
                'external_ips' => (array) ($spec['externalIPs'] ?? []),
                'ports' => array_values(array_map(static fn (array $port): array => [
                    'name' => (string) ($port['name'] ?? ''),
                    'protocol' => (string) ($port['protocol'] ?? 'TCP'),
                    'port' => (int) ($port['port'] ?? 0),
                    'target_port' => $port['targetPort'] ?? null,
                    'node_port' => (int) ($port['nodePort'] ?? 0),
                ], (array) ($spec['ports'] ?? []))),
                'selector' => (array) ($spec['selector'] ?? []),
                'created_at' => (string) ($metadata['creationTimestamp'] ?? ''),
            ];
        }, (array) ($response['items'] ?? []));
    }

    // ---------------------------------------------------------------------
    // ConfigMap
    // ---------------------------------------------------------------------
    public function configmaps(int $orgId, int $clusterId, string $namespace = ''): array
    {
        [, $connection, $credential] = $this->connectionWithCredential($orgId, $clusterId);
        $response = $this->api->get($credential, $this->nsPath('api/v1', $namespace, 'configmaps'));
        $this->markConnectionHealthy($connection);
        return array_map(static fn (array $item): array => [
            'name' => (string) ($item['metadata']['name'] ?? ''),
            'namespace' => (string) ($item['metadata']['namespace'] ?? ''),
            'labels' => (array) ($item['metadata']['labels'] ?? []),
            'created_at' => (string) ($item['metadata']['creationTimestamp'] ?? ''),
        ], (array) ($response['items'] ?? []));
    }

    public function configMap(int $orgId, int $clusterId, string $namespace, string $name): array
    {
        [, $connection, $credential] = $this->connectionWithCredential($orgId, $clusterId);
        $path = $this->nsPath('api/v1', $namespace, 'configmaps') . '/' . rawurlencode($name);
        $response = $this->api->get($credential, $path);
        $this->markConnectionHealthy($connection);
        return $response;
    }

    public function configMapCreate(int $orgId, int $clusterId, array $form): array
    {
        [, $connection, $credential] = $this->connectionWithCredential($orgId, $clusterId);
        $manifest = [
            'apiVersion' => 'v1',
            'kind' => 'ConfigMap',
            'metadata' => [
                'name' => (string) $form['name'],
                'namespace' => (string) $form['namespace'],
                'labels' => $this->toAssoc($form['labels'] ?? []),
            ],
            'data' => $this->toAssoc($form['data'] ?? []),
        ];
        $created = $this->api->request($credential, 'POST', $this->nsPath('api/v1', $form['namespace'], 'configmaps'), ['json' => $manifest]);
        $this->markConnectionHealthy($connection);
        return $created;
    }

    public function configMapUpdate(int $orgId, int $clusterId, array $form): array
    {
        [, $connection, $credential] = $this->connectionWithCredential($orgId, $clusterId);
        $patch = [
            'metadata' => ['labels' => $this->toAssoc($form['labels'] ?? [])],
            'data' => $this->toAssoc($form['data'] ?? []),
        ];
        $path = $this->nsPath('api/v1', $form['namespace'], 'configmaps') . '/' . rawurlencode($form['name']);
        $updated = $this->api->request($credential, 'PATCH', $path, [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => $patch,
        ]);
        $this->markConnectionHealthy($connection);
        return $updated;
    }

    public function configMapDelete(int $orgId, int $clusterId, string $namespace, string $name): void
    {
        [, $connection, $credential] = $this->connectionWithCredential($orgId, $clusterId);
        $this->api->request($credential, 'DELETE', $this->nsPath('api/v1', $namespace, 'configmaps') . '/' . rawurlencode($name));
        $this->markConnectionHealthy($connection);
    }

    // ---------------------------------------------------------------------
    // Secret
    // ---------------------------------------------------------------------
    public function secrets(int $orgId, int $clusterId, string $namespace = ''): array
    {
        [, $connection, $credential] = $this->connectionWithCredential($orgId, $clusterId);
        $response = $this->api->get($credential, $this->nsPath('api/v1', $namespace, 'secrets'));
        $this->markConnectionHealthy($connection);
        return array_map(static fn (array $item): array => [
            'name' => (string) ($item['metadata']['name'] ?? ''),
            'namespace' => (string) ($item['metadata']['namespace'] ?? ''),
            'type' => (string) ($item['type'] ?? ''),
            'keys' => array_keys((array) ($item['data'] ?? [])),
            'labels' => (array) ($item['metadata']['labels'] ?? []),
            'created_at' => (string) ($item['metadata']['creationTimestamp'] ?? ''),
        ], (array) ($response['items'] ?? []));
    }

    public function secret(int $orgId, int $clusterId, string $namespace, string $name): array
    {
        [, $connection, $credential] = $this->connectionWithCredential($orgId, $clusterId);
        $path = $this->nsPath('api/v1', $namespace, 'secrets') . '/' . rawurlencode($name);
        $response = $this->api->get($credential, $path);
        $this->markConnectionHealthy($connection);
        return $response;
    }

    public function secretCreate(int $orgId, int $clusterId, array $form): array
    {
        [, $connection, $credential] = $this->connectionWithCredential($orgId, $clusterId);
        $manifest = [
            'apiVersion' => 'v1',
            'kind' => 'Secret',
            'metadata' => [
                'name' => (string) $form['name'],
                'namespace' => (string) $form['namespace'],
                'labels' => $this->toAssoc($form['labels'] ?? []),
            ],
            'type' => (string) ($form['type'] ?? 'Opaque'),
            'stringData' => $this->toAssoc($form['data'] ?? []),
        ];
        $created = $this->api->request($credential, 'POST', $this->nsPath('api/v1', $form['namespace'], 'secrets'), ['json' => $manifest]);
        $this->markConnectionHealthy($connection);
        return $created;
    }

    public function secretUpdate(int $orgId, int $clusterId, array $form): array
    {
        [, $connection, $credential] = $this->connectionWithCredential($orgId, $clusterId);
        $patch = ['metadata' => ['labels' => $this->toAssoc($form['labels'] ?? [])]];
        $data = $this->toAssoc($form['data'] ?? []);
        if ($data !== []) {
            $patch['stringData'] = $data;
        }
        $path = $this->nsPath('api/v1', $form['namespace'], 'secrets') . '/' . rawurlencode($form['name']);
        $updated = $this->api->request($credential, 'PATCH', $path, [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => $patch,
        ]);
        $this->markConnectionHealthy($connection);
        return $updated;
    }

    public function secretDelete(int $orgId, int $clusterId, string $namespace, string $name): void
    {
        [, $connection, $credential] = $this->connectionWithCredential($orgId, $clusterId);
        $this->api->request($credential, 'DELETE', $this->nsPath('api/v1', $namespace, 'secrets') . '/' . rawurlencode($name));
        $this->markConnectionHealthy($connection);
    }

    // ---------------------------------------------------------------------
    // Namespace
    // ---------------------------------------------------------------------
    public function namespaceCreate(int $orgId, int $clusterId, array $form): array
    {
        [, $connection, $credential] = $this->connectionWithCredential($orgId, $clusterId);
        $labels = $this->toAssoc($form['labels'] ?? []);
        $metadata = ['name' => (string) $form['name']];
        if ($labels !== []) {
            $metadata['labels'] = $labels;
        }
        $manifest = [
            'apiVersion' => 'v1',
            'kind' => 'Namespace',
            'metadata' => $metadata,
        ];
        $created = $this->api->request($credential, 'POST', '/api/v1/namespaces', ['json' => $manifest]);
        $this->markConnectionHealthy($connection);
        return $created;
    }

    public function namespaceDelete(int $orgId, int $clusterId, string $name): void
    {
        [, $connection, $credential] = $this->connectionWithCredential($orgId, $clusterId);
        $this->api->request($credential, 'DELETE', '/api/v1/namespaces/' . rawurlencode($name));
        $this->markConnectionHealthy($connection);
    }

    public function namespaceDetail(int $orgId, int $clusterId, string $namespace): array
    {
        [, $connection, $credential] = $this->connectionWithCredential($orgId, $clusterId);
        $base = $this->nsPath('api/v1', $namespace, '');
        $namespaceObject = $this->api->get($credential, '/api/v1/namespaces/' . rawurlencode($namespace));
        $quotaResponse = $this->api->get($credential, $base . 'resourcequotas');
        $limitRangeResponse = $this->api->get($credential, $base . 'limitranges');
        $this->markConnectionHealthy($connection);

        return $this->namespaceResourceState(
            $namespaceObject,
            (array) ($quotaResponse['items'] ?? []),
            (array) ($limitRangeResponse['items'] ?? [])
        );
    }

    public function namespaceResources(int $orgId, int $clusterId, array $form): array
    {
        [, $connection, $credential] = $this->connectionWithCredential($orgId, $clusterId);
        $namespace = (string) $form['namespace'];
        $base = $this->nsPath('api/v1', $namespace, '');
        $quotaResponse = $this->api->get($credential, $base . 'resourcequotas');
        $limitRangeResponse = $this->api->get($credential, $base . 'limitranges');
        $quotas = (array) ($quotaResponse['items'] ?? []);
        $limitRanges = (array) ($limitRangeResponse['items'] ?? []);
        $managedQuota = $this->findKubernetesObject($quotas, self::MANAGED_RESOURCE_QUOTA);
        $managedLimitRange = $this->findKubernetesObject($limitRanges, self::MANAGED_LIMIT_RANGE);
        $quotaEnabled = (bool) $form['quota_enabled'];
        $hard = $quotaEnabled ? $this->resourceQuotaHard($form) : [];
        $limitRangeEnabled = (bool) $form['limit_range_enabled'];
        $limit = $limitRangeEnabled
            ? $this->limitRangeItem((array) $form['default_resources'])
            : [];

        if ($quotaEnabled && $hard === []) {
            throw new AppException(422, '启用 Namespace 资源配额时至少需要设置一项总量限制');
        }
        if ($limitRangeEnabled && $limit === []) {
            throw new AppException(422, '启用默认资源配置时至少需要设置一项请求或限制');
        }

        if ($quotaEnabled) {
            $hardPatch = $hard;
            if ($managedQuota !== null) {
                $hardPatch = array_merge(array_fill_keys([
                    'requests.cpu',
                    'limits.cpu',
                    'requests.memory',
                    'limits.memory',
                    'requests.storage',
                    'pods',
                    'services',
                    'persistentvolumeclaims',
                ], null), $hard);
            }
            $manifest = [
                'apiVersion' => 'v1',
                'kind' => 'ResourceQuota',
                'metadata' => [
                    'name' => self::MANAGED_RESOURCE_QUOTA,
                    'namespace' => $namespace,
                    'labels' => ['app.kubernetes.io/managed-by' => 'galaxy'],
                ],
                'spec' => ['hard' => $hardPatch],
            ];
            $this->upsertNamespacedObject(
                $credential,
                $base . 'resourcequotas',
                self::MANAGED_RESOURCE_QUOTA,
                $managedQuota !== null,
                $manifest
            );
        } elseif ($managedQuota !== null) {
            $this->api->request(
                $credential,
                'DELETE',
                $base . 'resourcequotas/' . rawurlencode(self::MANAGED_RESOURCE_QUOTA)
            );
        }

        if ($limitRangeEnabled) {
            $manifest = [
                'apiVersion' => 'v1',
                'kind' => 'LimitRange',
                'metadata' => [
                    'name' => self::MANAGED_LIMIT_RANGE,
                    'namespace' => $namespace,
                    'labels' => ['app.kubernetes.io/managed-by' => 'galaxy'],
                ],
                'spec' => ['limits' => [$limit]],
            ];
            $this->upsertNamespacedObject(
                $credential,
                $base . 'limitranges',
                self::MANAGED_LIMIT_RANGE,
                $managedLimitRange !== null,
                $manifest
            );
        } elseif ($managedLimitRange !== null) {
            $this->api->request(
                $credential,
                'DELETE',
                $base . 'limitranges/' . rawurlencode(self::MANAGED_LIMIT_RANGE)
            );
        }

        $namespaceObject = $this->api->get($credential, '/api/v1/namespaces/' . rawurlencode($namespace));
        $quotaResponse = $this->api->get($credential, $base . 'resourcequotas');
        $limitRangeResponse = $this->api->get($credential, $base . 'limitranges');
        $this->markConnectionHealthy($connection);

        return $this->namespaceResourceState(
            $namespaceObject,
            (array) ($quotaResponse['items'] ?? []),
            (array) ($limitRangeResponse['items'] ?? [])
        );
    }

    // ---------------------------------------------------------------------
    // Deployment
    // ---------------------------------------------------------------------
    public function deploymentCreate(int $orgId, int $clusterId, array $form): array
    {
        [, $connection, $credential] = $this->connectionWithCredential($orgId, $clusterId);
        $manifest = $this->deploymentManifest($form);
        $created = $this->api->request($credential, 'POST', $this->nsPath('apps/v1', $form['namespace'], 'deployments'), ['json' => $manifest]);
        $this->markConnectionHealthy($connection);
        return $created;
    }

    public function deploymentUpdate(int $orgId, int $clusterId, array $form): array
    {
        [, $connection, $credential] = $this->connectionWithCredential($orgId, $clusterId);
        $manifest = $this->deploymentManifest($form);
        $path = $this->nsPath('apps/v1', $form['namespace'], 'deployments') . '/' . rawurlencode($form['name']);
        $updated = $this->api->request($credential, 'PATCH', $path, [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => $manifest,
        ]);
        $this->markConnectionHealthy($connection);
        return $updated;
    }

    public function deploymentScale(int $orgId, int $clusterId, string $namespace, string $name, int $replicas): array
    {
        [, $connection, $credential] = $this->connectionWithCredential($orgId, $clusterId);
        $path = $this->nsPath('apps/v1', $namespace, 'deployments') . '/' . rawurlencode($name);
        $updated = $this->api->request($credential, 'PATCH', $path, [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['spec' => ['replicas' => $replicas]],
        ]);
        $this->markConnectionHealthy($connection);
        return $updated;
    }

    public function deploymentRestart(int $orgId, int $clusterId, string $namespace, string $name): array
    {
        [, $connection, $credential] = $this->connectionWithCredential($orgId, $clusterId);
        $path = $this->nsPath('apps/v1', $namespace, 'deployments') . '/' . rawurlencode($name);
        $updated = $this->api->request($credential, 'PATCH', $path, [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['spec' => ['template' => ['metadata' => ['annotations' => [
                'kubectl.kubernetes.io/restartedAt' => date('c'),
            ]]]]],
        ]);
        $this->markConnectionHealthy($connection);
        return $updated;
    }

    // 更新 Deployment Pod 模板中的容器资源配置，对该 Deployment 的全部副本生效。
    // Kubernetes 资源字段位于 containers[].resources，因此多容器工作负载仍需保留逐容器配置。
    public function deploymentResources(int $orgId, int $clusterId, string $namespace, string $name, array $containers): array
    {
        [, $connection, $credential] = $this->connectionWithCredential($orgId, $clusterId);
        $path = $this->nsPath('apps/v1', $namespace, 'deployments') . '/' . rawurlencode($name);
        $updated = $this->api->request($credential, 'PATCH', $path, [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['spec' => ['template' => ['spec' => ['containers' => $containers]]]],
        ]);
        $this->markConnectionHealthy($connection);
        return $updated;
    }

    // 通用部分更新：仅 patch 提供的字段，未提供的保持不变。
    // 前端需保证 containers/volumes 为完整数组（保留其余字段），避免覆盖。
    public function deploymentApply(int $orgId, int $clusterId, string $namespace, string $name, array $payload): array
    {
        [, $connection, $credential] = $this->connectionWithCredential($orgId, $clusterId);
        $path = $this->nsPath('apps/v1', $namespace, 'deployments') . '/' . rawurlencode($name);

        $patch = [];
        $spec = [];
        $template = [];
        $templateSpec = [];

        if (array_key_exists('containers', $payload) && is_array($payload['containers'])) {
            $templateSpec['containers'] = $payload['containers'];
        }
        if (array_key_exists('volumes', $payload) && is_array($payload['volumes'])) {
            $templateSpec['volumes'] = $payload['volumes'];
        }
        if (array_key_exists('hostNetwork', $payload)) {
            $templateSpec['hostNetwork'] = (bool) $payload['hostNetwork'];
        }
        if (array_key_exists('dnsPolicy', $payload) && $payload['dnsPolicy'] !== null) {
            $templateSpec['dnsPolicy'] = $payload['dnsPolicy'];
        }
        if (array_key_exists('dnsConfig', $payload)) {
            $templateSpec['dnsConfig'] = $payload['dnsConfig'];
        }
        if (!empty($templateSpec)) {
            $template['spec'] = $templateSpec;
        }
        if (array_key_exists('podLabels', $payload) && is_array($payload['podLabels']) && count($payload['podLabels']) > 0) {
            $template['metadata'] = ['labels' => $payload['podLabels']];
        }
        if (!empty($template)) {
            $spec['template'] = $template;
        }
        if (array_key_exists('replicas', $payload) && $payload['replicas'] !== null) {
            $spec['replicas'] = (int) $payload['replicas'];
        }
        if (!empty($spec)) {
            $patch['spec'] = $spec;
        }
        if (array_key_exists('labels', $payload) && is_array($payload['labels']) && count($payload['labels']) > 0) {
            $patch['metadata'] = ['labels' => $payload['labels']];
        }

        $updated = $this->api->request($credential, 'PATCH', $path, [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => $patch,
        ]);
        $this->markConnectionHealthy($connection);
        return $updated;
    }

    public function deploymentDelete(int $orgId, int $clusterId, string $namespace, string $name): void
    {
        [, $connection, $credential] = $this->connectionWithCredential($orgId, $clusterId);
        $this->api->request($credential, 'DELETE', $this->nsPath('apps/v1', $namespace, 'deployments') . '/' . rawurlencode($name));
        $this->markConnectionHealthy($connection);
    }

    // ---------------------------------------------------------------------
    // Service
    // ---------------------------------------------------------------------
    public function service(int $orgId, int $clusterId, string $namespace, string $name): array
    {
        [, $connection, $credential] = $this->connectionWithCredential($orgId, $clusterId);
        $path = $this->nsPath('api/v1', $namespace, 'services') . '/' . rawurlencode($name);
        $response = $this->api->get($credential, $path);
        $this->markConnectionHealthy($connection);
        return $response;
    }

    public function serviceCreate(int $orgId, int $clusterId, array $form): array
    {
        [, $connection, $credential] = $this->connectionWithCredential($orgId, $clusterId);
        $form['selector'] = $this->resolveServiceSelector($credential, $form);
        $created = $this->api->request($credential, 'POST', $this->nsPath('api/v1', $form['namespace'], 'services'), ['json' => $this->serviceManifest($form)]);
        $this->markConnectionHealthy($connection);
        return $created;
    }

    public function serviceUpdate(int $orgId, int $clusterId, array $form): array
    {
        [, $connection, $credential] = $this->connectionWithCredential($orgId, $clusterId);
        $form['selector'] = $this->resolveServiceSelector($credential, $form);
        $path = $this->nsPath('api/v1', $form['namespace'], 'services') . '/' . rawurlencode($form['name']);
        $updated = $this->api->request($credential, 'PATCH', $path, [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => $this->serviceManifest($form),
        ]);
        $this->markConnectionHealthy($connection);
        return $updated;
    }

    public function serviceDelete(int $orgId, int $clusterId, string $namespace, string $name): void
    {
        [, $connection, $credential] = $this->connectionWithCredential($orgId, $clusterId);
        $this->api->request($credential, 'DELETE', $this->nsPath('api/v1', $namespace, 'services') . '/' . rawurlencode($name));
        $this->markConnectionHealthy($connection);
    }

    // ---------------------------------------------------------------------
    // Ingress
    // ---------------------------------------------------------------------
    public function ingresses(int $orgId, int $clusterId, string $namespace = ''): array
    {
        [, $connection, $credential] = $this->connectionWithCredential($orgId, $clusterId);
        $response = $this->api->get($credential, $this->nsPath('apis/networking.k8s.io/v1', $namespace, 'ingresses'));
        $this->markConnectionHealthy($connection);
        $items = array_values(array_filter(
            (array) ($response['items'] ?? []),
            static fn (array $item): bool => (
                $item['metadata']['annotations'][self::INGRESS_HTTP_COMPANION_ANNOTATION] ?? ''
            ) === ''
        ));
        return array_map(static function (array $item): array {
            $spec = (array) ($item['spec'] ?? []);
            $backends = [];
            foreach ((array) ($spec['rules'] ?? []) as $rule) {
                $http = (array) ($rule['http'] ?? []);
                foreach ((array) ($http['paths'] ?? []) as $path) {
                    $backend = (array) ($path['backend'] ?? []);
                    $svc = (array) ($backend['service'] ?? []);
                    if (!empty($svc['name'])) {
                        $backends[] = (string) $svc['name'];
                    }
                }
            }
            $defaultBackend = (array) ($spec['defaultBackend'] ?? []);
            $defaultSvc = (array) ($defaultBackend['service'] ?? []);
            if (!empty($defaultSvc['name'])) {
                $backends[] = (string) $defaultSvc['name'];
            }
            return [
                'name' => (string) ($item['metadata']['name'] ?? ''),
                'namespace' => (string) ($item['metadata']['namespace'] ?? ''),
                'hosts' => implode(', ', array_map(static fn ($r): string => (string) ($r['host'] ?? '*'), (array) ($spec['rules'] ?? []))),
                'services' => array_values(array_unique($backends)),
                'tls_enabled' => (array) ($spec['tls'] ?? []) !== [],
                'created_at' => (string) ($item['metadata']['creationTimestamp'] ?? ''),
            ];
        }, $items);
    }

    public function ingress(int $orgId, int $clusterId, string $namespace, string $name): array
    {
        [, $connection, $credential] = $this->connectionWithCredential($orgId, $clusterId);
        $path = $this->nsPath('apis/networking.k8s.io/v1', $namespace, 'ingresses') . '/' . rawurlencode($name);
        $response = $this->api->get($credential, $path);
        $this->markConnectionHealthy($connection);
        return $response;
    }

    public function ingressCreate(int $orgId, int $clusterId, array $form): array
    {
        [, $connection, $credential] = $this->connectionWithCredential($orgId, $clusterId);
        $form['https_redirect_port'] = (int) $connection->ingress_https_port;
        $this->prepareIngressTls($orgId, $credential, $form);
        $created = $this->api->request(
            $credential,
            'POST',
            $this->nsPath('apis/networking.k8s.io/v1', $form['namespace'], 'ingresses'),
            ['json' => $this->ingressManifest($form)]
        );
        $this->syncIngressHttpAccess($credential, $form);
        $this->markConnectionHealthy($connection);
        return $created;
    }

    public function ingressUpdate(int $orgId, int $clusterId, array $form): array
    {
        [, $connection, $credential] = $this->connectionWithCredential($orgId, $clusterId);
        $form['https_redirect_port'] = (int) $connection->ingress_https_port;
        $this->prepareIngressTls($orgId, $credential, $form);
        $path = $this->nsPath('apis/networking.k8s.io/v1', $form['namespace'], 'ingresses') . '/' . rawurlencode($form['name']);
        $updated = $this->api->request($credential, 'PATCH', $path, [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => $this->ingressManifest($form, true),
        ]);
        $this->syncIngressHttpAccess($credential, $form);
        $this->markConnectionHealthy($connection);
        return $updated;
    }

    public function ingressDelete(int $orgId, int $clusterId, string $namespace, string $name): void
    {
        [, $connection, $credential] = $this->connectionWithCredential($orgId, $clusterId);
        $this->api->request($credential, 'DELETE', $this->nsPath('apis/networking.k8s.io/v1', $namespace, 'ingresses') . '/' . rawurlencode($name));
        $this->deleteKubernetesObjectIgnoringMissing(
            $credential,
            $this->nsPath('apis/networking.k8s.io/v1', $namespace, 'ingresses')
                . '/' . rawurlencode($this->httpCompanionName($name))
        );
        $this->deleteKubernetesObjectIgnoringMissing(
            $credential,
            $this->nsPath('apis/traefik.io/v1alpha1', $namespace, 'middlewares')
                . '/' . rawurlencode($this->httpsRedirectMiddlewareName($name))
        );
        $this->markConnectionHealthy($connection);
    }

    // ---------------------------------------------------------------------
    // Pod
    // ---------------------------------------------------------------------
    public function pod(int $orgId, int $clusterId, string $namespace, string $name): array
    {
        [, $connection, $credential] = $this->connectionWithCredential($orgId, $clusterId);
        $path = $this->nsPath('api/v1', $namespace, 'pods') . '/' . rawurlencode($name);
        $response = $this->api->get($credential, $path);
        $this->markConnectionHealthy($connection);
        return $response;
    }

    public function podLogs(int $orgId, int $clusterId, string $namespace, string $name, ?string $container, int $tailLines): string
    {
        [, $connection, $credential] = $this->connectionWithCredential($orgId, $clusterId);
        $query = http_build_query(array_filter([
            'container' => $container,
            'tailLines' => $tailLines > 0 ? $tailLines : null,
        ], static fn ($value): bool => $value !== null));
        $path = $this->nsPath('api/v1', $namespace, 'pods') . '/' . rawurlencode($name)
            . '/log' . ($query !== '' ? '?' . $query : '');
        $logs = $this->api->requestRaw($credential, 'GET', $path);
        $this->markConnectionHealthy($connection);
        return $logs;
    }

    public function podDelete(int $orgId, int $clusterId, string $namespace, string $name): void
    {
        [, $connection, $credential] = $this->connectionWithCredential($orgId, $clusterId);
        $this->api->request($credential, 'DELETE', $this->nsPath('api/v1', $namespace, 'pods') . '/' . rawurlencode($name));
        $this->markConnectionHealthy($connection);
    }

    // ---------------------------------------------------------------------
    // Workloads: StatefulSet / DaemonSet / Job / CronJob (apps/v1, namespaced)
    // ---------------------------------------------------------------------
    public function statefulsets(int $orgId, int $clusterId, string $namespace = ''): array
    {
        [, $connection, $credential] = $this->connectionWithCredential($orgId, $clusterId);
        return $this->listOrEmptyOn404(
            $connection,
            $credential,
            $this->nsPath('apps/v1', $namespace, 'statefulsets'),
            static function (array $item): array {
                $metadata = (array) ($item['metadata'] ?? []);
                $spec = (array) ($item['spec'] ?? []);
                $status = (array) ($item['status'] ?? []);
                $containers = (array) ($spec['template']['spec']['containers'] ?? []);
                return [
                    'name' => (string) ($metadata['name'] ?? ''),
                    'namespace' => (string) ($metadata['namespace'] ?? ''),
                    'uid' => (string) ($metadata['uid'] ?? ''),
                    'replicas' => (int) ($spec['replicas'] ?? 0),
                    'ready_replicas' => (int) ($status['readyReplicas'] ?? 0),
                    'service_name' => (string) ($spec['serviceName'] ?? ''),
                    'images' => array_values(array_unique(array_map(
                        static fn (array $c): string => (string) ($c['image'] ?? ''), $containers
                    ))),
                    'created_at' => (string) ($metadata['creationTimestamp'] ?? ''),
                ];
            }
        );
    }

    public function statefulSet(int $orgId, int $clusterId, string $namespace, string $name): array
    {
        [, $connection, $credential] = $this->connectionWithCredential($orgId, $clusterId);
        $response = $this->api->get($credential, $this->nsPath('apps/v1', $namespace, 'statefulsets') . '/' . rawurlencode($name));
        $this->markConnectionHealthy($connection);
        return $response;
    }

    public function statefulSetScale(int $orgId, int $clusterId, string $namespace, string $name, int $replicas): array
    {
        [, $connection, $credential] = $this->connectionWithCredential($orgId, $clusterId);
        $updated = $this->api->request($credential, 'PATCH',
            $this->nsPath('apps/v1', $namespace, 'statefulsets') . '/' . rawurlencode($name), [
                'headers' => ['Content-Type' => 'application/merge-patch+json'],
                'json' => ['spec' => ['replicas' => $replicas]],
            ]);
        $this->markConnectionHealthy($connection);
        return $updated;
    }

    public function statefulSetDelete(int $orgId, int $clusterId, string $namespace, string $name): void
    {
        [, $connection, $credential] = $this->connectionWithCredential($orgId, $clusterId);
        $this->api->request($credential, 'DELETE', $this->nsPath('apps/v1', $namespace, 'statefulsets') . '/' . rawurlencode($name));
        $this->markConnectionHealthy($connection);
    }

    public function daemonsets(int $orgId, int $clusterId, string $namespace = ''): array
    {
        [, $connection, $credential] = $this->connectionWithCredential($orgId, $clusterId);
        return $this->listOrEmptyOn404(
            $connection,
            $credential,
            $this->nsPath('apps/v1', $namespace, 'daemonsets'),
            static function (array $item): array {
                $metadata = (array) ($item['metadata'] ?? []);
                $spec = (array) ($item['spec'] ?? []);
                $status = (array) ($item['status'] ?? []);
                $containers = (array) ($spec['template']['spec']['containers'] ?? []);
                return [
                    'name' => (string) ($metadata['name'] ?? ''),
                    'namespace' => (string) ($metadata['namespace'] ?? ''),
                    'uid' => (string) ($metadata['uid'] ?? ''),
                    'desired' => (int) ($status['desiredNumberScheduled'] ?? 0),
                    'ready' => (int) ($status['numberReady'] ?? 0),
                    'images' => array_values(array_unique(array_map(
                        static fn (array $c): string => (string) ($c['image'] ?? ''), $containers
                    ))),
                    'created_at' => (string) ($metadata['creationTimestamp'] ?? ''),
                ];
            }
        );
    }

    public function daemonSet(int $orgId, int $clusterId, string $namespace, string $name): array
    {
        [, $connection, $credential] = $this->connectionWithCredential($orgId, $clusterId);
        $response = $this->api->get($credential, $this->nsPath('apps/v1', $namespace, 'daemonsets') . '/' . rawurlencode($name));
        $this->markConnectionHealthy($connection);
        return $response;
    }

    public function daemonSetDelete(int $orgId, int $clusterId, string $namespace, string $name): void
    {
        [, $connection, $credential] = $this->connectionWithCredential($orgId, $clusterId);
        $this->api->request($credential, 'DELETE', $this->nsPath('apps/v1', $namespace, 'daemonsets') . '/' . rawurlencode($name));
        $this->markConnectionHealthy($connection);
    }

    public function jobs(int $orgId, int $clusterId, string $namespace = ''): array
    {
        [, $connection, $credential] = $this->connectionWithCredential($orgId, $clusterId);
        return $this->listOrEmptyOn404(
            $connection,
            $credential,
            $this->nsPath('batch/v1', $namespace, 'jobs'),
            static function (array $item): array {
                $metadata = (array) ($item['metadata'] ?? []);
                $spec = (array) ($item['spec'] ?? []);
                $status = (array) ($item['status'] ?? []);
                return [
                    'name' => (string) ($metadata['name'] ?? ''),
                    'namespace' => (string) ($metadata['namespace'] ?? ''),
                    'uid' => (string) ($metadata['uid'] ?? ''),
                    'completions' => (int) ($spec['completions'] ?? 0),
                    'parallelism' => (int) ($spec['parallelism'] ?? 0),
                    'succeeded' => (int) ($status['succeeded'] ?? 0),
                    'active' => (int) ($status['active'] ?? 0),
                    'failed' => (int) ($status['failed'] ?? 0),
                    'start_time' => (string) ($status['startTime'] ?? ''),
                    'completion_time' => (string) ($status['completionTime'] ?? ''),
                    'created_at' => (string) ($metadata['creationTimestamp'] ?? ''),
                ];
            }
        );
    }

    public function job(int $orgId, int $clusterId, string $namespace, string $name): array
    {
        [, $connection, $credential] = $this->connectionWithCredential($orgId, $clusterId);
        $response = $this->api->get($credential, $this->nsPath('batch/v1', $namespace, 'jobs') . '/' . rawurlencode($name));
        $this->markConnectionHealthy($connection);
        return $response;
    }

    public function jobDelete(int $orgId, int $clusterId, string $namespace, string $name): void
    {
        [, $connection, $credential] = $this->connectionWithCredential($orgId, $clusterId);
        $this->api->request($credential, 'DELETE', $this->nsPath('batch/v1', $namespace, 'jobs') . '/' . rawurlencode($name));
        $this->markConnectionHealthy($connection);
    }

    public function cronjobs(int $orgId, int $clusterId, string $namespace = ''): array
    {
        [, $connection, $credential] = $this->connectionWithCredential($orgId, $clusterId);
        return $this->listOrEmptyOn404(
            $connection,
            $credential,
            $this->nsPath('batch/v1', $namespace, 'cronjobs'),
            static function (array $item): array {
                $metadata = (array) ($item['metadata'] ?? []);
                $spec = (array) ($item['spec'] ?? []);
                $status = (array) ($item['status'] ?? []);
                return [
                    'name' => (string) ($metadata['name'] ?? ''),
                    'namespace' => (string) ($metadata['namespace'] ?? ''),
                    'uid' => (string) ($metadata['uid'] ?? ''),
                    'schedule' => (string) ($spec['schedule'] ?? ''),
                    'suspend' => (bool) ($spec['suspend'] ?? false),
                    'active' => count((array) ($status['active'] ?? [])),
                    'last_schedule_time' => (string) ($status['lastScheduleTime'] ?? ''),
                    'created_at' => (string) ($metadata['creationTimestamp'] ?? ''),
                ];
            }
        );
    }

    public function cronjob(int $orgId, int $clusterId, string $namespace, string $name): array
    {
        [, $connection, $credential] = $this->connectionWithCredential($orgId, $clusterId);
        $response = $this->api->get($credential, $this->nsPath('batch/v1', $namespace, 'cronjobs') . '/' . rawurlencode($name));
        $this->markConnectionHealthy($connection);
        return $response;
    }

    public function cronjobDelete(int $orgId, int $clusterId, string $namespace, string $name): void
    {
        [, $connection, $credential] = $this->connectionWithCredential($orgId, $clusterId);
        $this->api->request($credential, 'DELETE', $this->nsPath('batch/v1', $namespace, 'cronjobs') . '/' . rawurlencode($name));
        $this->markConnectionHealthy($connection);
    }

    /**
     * 列出某类资源；当 Kubernetes API 返回 404（例如集群未启用对应 API 组，
     * 或该集合不存在）时，视为空列表返回，不再向上抛出 502 错误——
     * 这种场景符合预期，前端不应弹出错误信息。其它错误照常抛出。
     *
     * @param array $connection
     * @param array $credential
     * @param string $path
     * @param callable $map
     * @return array
     */
    private function listOrEmptyOn404($connection, array $credential, string $path, callable $map): array
    {
        try {
            $response = $this->api->get($credential, $path);
        } catch (AppException $e) {
            if (str_contains($e->getMessage(), 'HTTP 404')) {
                return [];
            }
            throw $e;
        }
        $this->markConnectionHealthy($connection);
        return array_map($map, (array) ($response['items'] ?? []));
    }

    // ---------------------------------------------------------------------
    // Storage: PersistentVolume / PersistentVolumeClaim / StorageClass
    // ---------------------------------------------------------------------
    public function persistentVolumes(int $orgId, int $clusterId): array
    {
        [, $connection, $credential] = $this->connectionWithCredential($orgId, $clusterId);
        $response = $this->api->get($credential, '/api/v1/persistentvolumes');
        $this->markConnectionHealthy($connection);
        return array_map(static function (array $item): array {
            $metadata = (array) ($item['metadata'] ?? []);
            $spec = (array) ($item['spec'] ?? []);
            $status = (array) ($item['status'] ?? []);
            $claim = (array) ($spec['claimRef'] ?? []);
            return [
                'name' => (string) ($metadata['name'] ?? ''),
                'capacity' => (string) ($spec['capacity']['storage'] ?? ''),
                'access_modes' => (array) ($spec['accessModes'] ?? []),
                'reclaim_policy' => (string) ($spec['persistentVolumeReclaimPolicy'] ?? ''),
                'status' => (string) ($status['phase'] ?? ''),
                'storage_class_name' => (string) ($spec['storageClassName'] ?? ''),
                'claim' => (string) ($claim['name'] ?? ''),
                'claim_namespace' => (string) ($claim['namespace'] ?? ''),
                'created_at' => (string) ($metadata['creationTimestamp'] ?? ''),
            ];
        }, (array) ($response['items'] ?? []));
    }

    public function persistentVolume(int $orgId, int $clusterId, string $name): array
    {
        [, $connection, $credential] = $this->connectionWithCredential($orgId, $clusterId);
        $response = $this->api->get($credential, '/api/v1/persistentvolumes/' . rawurlencode($name));
        $this->markConnectionHealthy($connection);
        return $response;
    }

    public function persistentVolumeDelete(int $orgId, int $clusterId, string $name): void
    {
        [, $connection, $credential] = $this->connectionWithCredential($orgId, $clusterId);
        $this->api->request($credential, 'DELETE', '/api/v1/persistentvolumes/' . rawurlencode($name));
        $this->markConnectionHealthy($connection);
    }

    public function persistentVolumeClaims(int $orgId, int $clusterId, string $namespace = ''): array
    {
        [, $connection, $credential] = $this->connectionWithCredential($orgId, $clusterId);
        $response = $this->api->get($credential, $this->nsPath('api/v1', $namespace, 'persistentvolumeclaims'));
        $this->markConnectionHealthy($connection);
        return array_map(static function (array $item): array {
            $metadata = (array) ($item['metadata'] ?? []);
            $spec = (array) ($item['spec'] ?? []);
            $status = (array) ($item['status'] ?? []);
            return [
                'name' => (string) ($metadata['name'] ?? ''),
                'namespace' => (string) ($metadata['namespace'] ?? ''),
                'uid' => (string) ($metadata['uid'] ?? ''),
                'volume_name' => (string) ($spec['volumeName'] ?? ''),
                'capacity' => (string) ($status['capacity']['storage'] ?? (string) ($spec['resources']['requests']['storage'] ?? '')),
                'access_modes' => (array) ($spec['accessModes'] ?? []),
                'storage_class_name' => (string) ($spec['storageClassName'] ?? ''),
                'phase' => (string) ($status['phase'] ?? ''),
                'created_at' => (string) ($metadata['creationTimestamp'] ?? ''),
            ];
        }, (array) ($response['items'] ?? []));
    }

    public function persistentVolumeClaim(int $orgId, int $clusterId, string $namespace, string $name): array
    {
        [, $connection, $credential] = $this->connectionWithCredential($orgId, $clusterId);
        $response = $this->api->get($credential, $this->nsPath('api/v1', $namespace, 'persistentvolumeclaims') . '/' . rawurlencode($name));
        $this->markConnectionHealthy($connection);
        return $response;
    }

    public function persistentVolumeClaimDelete(int $orgId, int $clusterId, string $namespace, string $name): void
    {
        [, $connection, $credential] = $this->connectionWithCredential($orgId, $clusterId);
        $this->api->request($credential, 'DELETE', $this->nsPath('api/v1', $namespace, 'persistentvolumeclaims') . '/' . rawurlencode($name));
        $this->markConnectionHealthy($connection);
    }

    public function storageClasses(int $orgId, int $clusterId): array
    {
        [, $connection, $credential] = $this->connectionWithCredential($orgId, $clusterId);
        $response = $this->api->get($credential, '/apis/storage.k8s.io/v1/storageclasses');
        $this->markConnectionHealthy($connection);
        return array_map(static function (array $item): array {
            $metadata = (array) ($item['metadata'] ?? []);
            return [
                'name' => (string) ($metadata['name'] ?? ''),
                'provisioner' => (string) ($item['provisioner'] ?? ''),
                'reclaim_policy' => (string) ($item['reclaimPolicy'] ?? ''),
                'binding_mode' => (string) ($item['volumeBindingMode'] ?? ''),
                'is_default' => (string) ($metadata['annotations']['storageclass.kubernetes.io/is-default-class'] ?? 'false') === 'true',
                'created_at' => (string) ($metadata['creationTimestamp'] ?? ''),
            ];
        }, (array) ($response['items'] ?? []));
    }

    public function storageClass(int $orgId, int $clusterId, string $name): array
    {
        [, $connection, $credential] = $this->connectionWithCredential($orgId, $clusterId);
        $response = $this->api->get($credential, '/apis/storage.k8s.io/v1/storageclasses/' . rawurlencode($name));
        $this->markConnectionHealthy($connection);
        return $response;
    }

    public function storageClassDelete(int $orgId, int $clusterId, string $name): void
    {
        [, $connection, $credential] = $this->connectionWithCredential($orgId, $clusterId);
        $this->api->request($credential, 'DELETE', '/apis/storage.k8s.io/v1/storageclasses/' . rawurlencode($name));
        $this->markConnectionHealthy($connection);
    }

    // ---------------------------------------------------------------------
    // Events (core/v1)
    // ---------------------------------------------------------------------
    public function events(int $orgId, int $clusterId, string $namespace = '', string $involvedKind = '', string $involvedName = ''): array
    {
        [, $connection, $credential] = $this->connectionWithCredential($orgId, $clusterId);
        $path = $this->nsPath('api/v1', $namespace, 'events');
        $selectors = [];
        if ($involvedKind !== '' && $involvedName !== '') {
            $selectors[] = 'involvedObject.kind=' . $involvedKind;
            $selectors[] = 'involvedObject.name=' . $involvedName;
        }
        if ($selectors !== []) {
            $path .= '?fieldSelector=' . rawurlencode(implode(',', $selectors));
        }
        $response = $this->api->get($credential, $path);
        $this->markConnectionHealthy($connection);
        return array_map(static function (array $item): array {
            $metadata = (array) ($item['metadata'] ?? []);
            $involved = (array) ($item['involvedObject'] ?? []);
            $source = (array) ($item['source'] ?? []);
            $last = (string) ($item['lastTimestamp'] ?? (string) ($item['eventTime'] ?? (string) ($metadata['creationTimestamp'] ?? '')));
            return [
                'uid' => (string) ($metadata['uid'] ?? ''),
                'type' => (string) ($item['type'] ?? ''),
                'reason' => (string) ($item['reason'] ?? ''),
                'message' => (string) ($item['message'] ?? ''),
                'involved_kind' => (string) ($involved['kind'] ?? ''),
                'involved_name' => (string) ($involved['name'] ?? ''),
                'involved_namespace' => (string) ($involved['namespace'] ?? ''),
                'source_component' => (string) ($source['component'] ?? ''),
                'source_host' => (string) ($source['host'] ?? ''),
                'count' => (int) ($item['count'] ?? 0),
                'first_timestamp' => (string) ($item['firstTimestamp'] ?? ''),
                'last_timestamp' => $last,
                'created_at' => (string) ($metadata['creationTimestamp'] ?? ''),
            ];
        }, (array) ($response['items'] ?? []));
    }

    public function delete(int $orgId, int $clusterId): void
    {
        [$cluster] = $this->connection($orgId, $clusterId);
        if (ProjectRuntime::where('cluster_id', $clusterId)->exists()) {
            throw new AppException(409, '该集群仍有关联的项目运行实例，不能删除');
        }
        Db::transaction(static function () use ($cluster, $clusterId): void {
            EnvClusterRel::where('cluster_id', $clusterId)->delete();
            KubernetesClusterConnection::where('cluster_id', $clusterId)->delete();
            $cluster->delete();
        });
    }

    private function toAssoc(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            $key = (string) ($row['key'] ?? '');
            if ($key !== '') {
                $out[$key] = (string) ($row['value'] ?? '');
            }
        }
        return $out;
    }

    private function assocToPairs(array $values): array
    {
        $rows = [];
        foreach ($values as $key => $value) {
            $rows[] = ['key' => (string) $key, 'value' => (string) $value];
        }
        return $rows;
    }

    private function nsPath(string $api, string $namespace, string $plural): string
    {
        $api = trim($api, '/');
        if (! str_starts_with($api, 'api/') && ! str_starts_with($api, 'apis/')) {
            $api = 'apis/' . $api;
        }
        return $namespace === ''
            ? '/' . $api . '/' . $plural
            : '/' . $api . '/namespaces/' . rawurlencode($namespace) . '/' . $plural;
    }

    private function buildResources(?array $r): ?array
    {
        if (empty($r)) {
            return null;
        }
        $requests = [];
        $limits = [];
        if (($r['cpu_request'] ?? '') !== '') {
            $requests['cpu'] = (string) $r['cpu_request'] . 'm';
        }
        if (($r['memory_request'] ?? '') !== '') {
            $requests['memory'] = (string) $r['memory_request'] . 'Mi';
        }
        if (($r['cpu_limit'] ?? '') !== '') {
            $limits['cpu'] = (string) $r['cpu_limit'] . 'm';
        }
        if (($r['memory_limit'] ?? '') !== '') {
            $limits['memory'] = (string) $r['memory_limit'] . 'Mi';
        }
        if ($requests === [] && $limits === []) {
            return null;
        }
        $resources = [];
        if ($requests !== []) {
            $resources['requests'] = $requests;
        }
        if ($limits !== []) {
            $resources['limits'] = $limits;
        }
        return $resources;
    }

    private function deploymentManifest(array $form): array
    {
        $selector = $this->toAssoc($form['selector'] ?? []);
        $containers = array_map(function (array $container): array {
            $entry = [
                'name' => (string) ($container['name'] ?? ''),
                'image' => (string) ($container['image'] ?? ''),
            ];
            $ports = [];
            foreach (($container['ports'] ?? []) as $port) {
                $port = (array) $port;
                if ((string) ($port['containerPort'] ?? '') === '') {
                    continue;
                }
                $item = ['containerPort' => (int) $port['containerPort']];
                if (($port['name'] ?? '') !== '') {
                    $item['name'] = (string) $port['name'];
                }
                $item['protocol'] = (string) ($port['protocol'] ?? 'TCP');
                $ports[] = $item;
            }
            if ($ports !== []) {
                $entry['ports'] = $ports;
            }
            $env = [];
            foreach (($container['env'] ?? []) as $e) {
                $e = (array) $e;
                if (($e['key'] ?? '') !== '') {
                    $env[] = ['name' => (string) $e['key'], 'value' => (string) ($e['value'] ?? '')];
                }
            }
            if ($env !== []) {
                $entry['env'] = $env;
            }
            $resources = $this->buildResources($container['resources'] ?? null);
            if ($resources !== null) {
                $entry['resources'] = $resources;
            }
            return $entry;
        }, (array) ($form['containers'] ?? []));

        return [
            'apiVersion' => 'apps/v1',
            'kind' => 'Deployment',
            'metadata' => [
                'name' => (string) $form['name'],
                'namespace' => (string) $form['namespace'],
                'labels' => $selector,
            ],
            'spec' => [
                'replicas' => (int) ($form['replicas'] ?? 1),
                'selector' => ['matchLabels' => $selector],
                'template' => [
                    'metadata' => ['labels' => $selector],
                    'spec' => ['containers' => $containers],
                ],
            ],
        ];
    }

    private function resolveServiceSelector(array $credential, array $form): array
    {
        $namespace = (string) ($form['namespace'] ?? '');
        $serviceName = (string) ($form['name'] ?? '');
        $targetKind = (string) ($form['target_kind'] ?? '');
        $targetName = (string) ($form['target_name'] ?? '');

        if ($targetKind === '' && $targetName === '') {
            $selector = $this->toAssoc((array) ($form['selector'] ?? []));
            if ($selector === []) {
                throw new AppException(422, '请选择 Service 匹配的 Deployment 或 Pod');
            }
            return $this->assocToPairs($selector);
        }
        if ($targetKind === '' || $targetName === '') {
            throw new AppException(422, 'Service 目标类型和目标工作负载必须同时选择');
        }

        if ($targetKind === 'Deployment') {
            $deployment = $this->api->get(
                $credential,
                $this->nsPath('apis/apps/v1', $namespace, 'deployments')
                    . '/' . rawurlencode($targetName)
            );
            $selector = (array) ($deployment['spec']['selector']['matchLabels'] ?? []);
            if ($selector === []) {
                throw new AppException(422, sprintf(
                    'Deployment %s 没有可供 Service 使用的 matchLabels',
                    $targetName
                ));
            }
            return $this->assocToPairs($selector);
        }

        if ($targetKind !== 'Pod') {
            throw new AppException(422, '不支持的 Service 目标类型');
        }
        $podPath = $this->nsPath('api/v1', $namespace, 'pods') . '/' . rawurlencode($targetName);
        $pod = $this->api->get($credential, $podPath);
        $owner = (array) (($pod['metadata']['ownerReferences'] ?? [])[0] ?? []);
        if ($owner !== []) {
            throw new AppException(422, sprintf(
                'Pod %s 由 %s %s 管理，请直接选择其上层工作负载',
                $targetName,
                (string) ($owner['kind'] ?? 'Controller'),
                (string) ($owner['name'] ?? '')
            ));
        }

        $selectorKey = 'service.codegalaxy.com/target-' . substr(
            hash('sha256', $namespace . '/' . $serviceName),
            0,
            16
        );
        $this->api->request($credential, 'PATCH', $podPath, [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['metadata' => ['labels' => [$selectorKey => 'true']]],
        ]);
        return [['key' => $selectorKey, 'value' => 'true']];
    }

    private function serviceManifest(array $form): array
    {
        $ports = array_map(function (array $port): array {
            $item = [
                'port' => (int) ($port['port'] ?? 0),
                'targetPort' => (int) ($port['targetPort'] ?? 0),
                'protocol' => (string) ($port['protocol'] ?? 'TCP'),
            ];
            if (($port['name'] ?? '') !== '') {
                $item['name'] = (string) $port['name'];
            }
            if ((int) ($port['nodePort'] ?? 0) > 0) {
                $item['nodePort'] = (int) $port['nodePort'];
            }
            return $item;
        }, (array) ($form['ports'] ?? []));

        $metadata = [
            'name' => (string) $form['name'],
            'namespace' => (string) $form['namespace'],
        ];
        $labels = $this->toAssoc($form['labels'] ?? []);
        if ($labels !== []) {
            $metadata['labels'] = $labels;
        }

        return [
            'apiVersion' => 'v1',
            'kind' => 'Service',
            'metadata' => $metadata,
            'spec' => [
                'type' => (string) ($form['type'] ?? 'ClusterIP'),
                'selector' => $this->toAssoc($form['selector'] ?? []),
                'ports' => $ports,
            ],
        ];
    }

    private function ingressManifest(array $form, bool $updating = false): array
    {
        $rules = [];
        foreach (($form['rules'] ?? []) as $rule) {
            $rule = (array) $rule;
            $paths = [];
            foreach (($rule['paths'] ?? []) as $path) {
                $path = (array) $path;
                $paths[] = [
                    'path' => (string) ($path['path'] ?? '/'),
                    'pathType' => (string) ($path['pathType'] ?? 'Prefix'),
                    'backend' => ['service' => [
                        'name' => (string) ($path['serviceName'] ?? ''),
                        'port' => ['number' => (int) ($path['servicePort'] ?? 80)],
                    ]],
                ];
            }
            $entry = ['http' => ['paths' => $paths]];
            if (($rule['host'] ?? '') !== '') {
                $entry['host'] = (string) $rule['host'];
            }
            $rules[] = $entry;
        }
        $metadata = [
            'name' => (string) $form['name'],
            'namespace' => (string) $form['namespace'],
        ];
        $labels = $this->toAssoc($form['labels'] ?? []);
        if ($labels !== []) {
            $metadata['labels'] = $labels;
        }
        if (!empty($form['tls_enabled'])) {
            $metadata['annotations'] = [
                self::INGRESS_CERTIFICATE_ANNOTATION => (string) $form['certificate_id'],
                self::INGRESS_HTTPS_REDIRECT_ANNOTATION => !empty($form['https_redirect'])
                    ? 'true'
                    : 'false',
                self::INGRESS_HTTPS_REDIRECT_PORT_ANNOTATION => (string) (
                    $form['https_redirect_port'] ?? 443
                ),
                'traefik.ingress.kubernetes.io/router.entrypoints' => 'web,websecure',
            ];
        } elseif ($updating) {
            $metadata['annotations'] = [
                self::INGRESS_CERTIFICATE_ANNOTATION => null,
                self::INGRESS_HTTPS_REDIRECT_ANNOTATION => null,
                self::INGRESS_HTTPS_REDIRECT_PORT_ANNOTATION => null,
                'traefik.ingress.kubernetes.io/router.entrypoints' => 'web',
            ];
        }
        $spec = ['rules' => $rules, 'tls' => []];
        if (!empty($form['tls_enabled'])) {
            $spec['tls'] = [[
                'hosts' => (array) $form['tls_hosts'],
                'secretName' => (string) $form['tls_secret_name'],
            ]];
        }
        return [
            'apiVersion' => 'networking.k8s.io/v1',
            'kind' => 'Ingress',
            'metadata' => $metadata,
            'spec' => $spec,
        ];
    }

    public function syncIngressHttpAccess(array $credential, array $form): void
    {
        $namespace = (string) $form['namespace'];
        $name = (string) $form['name'];
        $ingressCollection = $this->nsPath(
            'apis/networking.k8s.io/v1',
            $namespace,
            'ingresses'
        );
        $companionName = $this->httpCompanionName($name);
        $middlewareCollection = $this->nsPath(
            'apis/traefik.io/v1alpha1',
            $namespace,
            'middlewares'
        );
        $middlewareName = $this->httpsRedirectMiddlewareName($name);
        if (empty($form['tls_enabled'])) {
            $this->deleteKubernetesObjectIgnoringMissing(
                $credential,
                $ingressCollection . '/' . rawurlencode($companionName)
            );
            $this->deleteKubernetesObjectIgnoringMissing(
                $credential,
                $middlewareCollection . '/' . rawurlencode($middlewareName)
            );
            return;
        }

        $httpsRedirect = !empty($form['https_redirect']);
        if ($httpsRedirect) {
            $middlewareLabels = $this->toAssoc($form['labels'] ?? []);
            $middlewareLabels['app.kubernetes.io/managed-by'] = 'galaxy';
            $this->upsertKubernetesObject(
                $credential,
                $middlewareCollection,
                $middlewareName,
                [
                    'apiVersion' => 'traefik.io/v1alpha1',
                    'kind' => 'Middleware',
                    'metadata' => [
                        'name' => $middlewareName,
                        'namespace' => $namespace,
                        'labels' => $middlewareLabels,
                    ],
                    'spec' => [
                        'redirectScheme' => [
                            'scheme' => 'https',
                            'port' => (string) ($form['https_redirect_port'] ?? 443),
                            'permanent' => true,
                        ],
                    ],
                ]
            );
        } else {
            $this->deleteKubernetesObjectIgnoringMissing(
                $credential,
                $middlewareCollection . '/' . rawurlencode($middlewareName)
            );
        }

        $labels = $this->toAssoc($form['labels'] ?? []);
        $labels['app.kubernetes.io/managed-by'] = 'galaxy';
        $annotations = [
            self::INGRESS_HTTP_COMPANION_ANNOTATION => $name,
            'traefik.ingress.kubernetes.io/router.entrypoints' => 'web',
        ];
        if ($httpsRedirect) {
            $annotations['traefik.ingress.kubernetes.io/router.middlewares'] =
                $namespace . '-' . $middlewareName . '@kubernetescrd';
        }
        $mainManifest = $this->ingressManifest($form);
        $this->deleteKubernetesObjectIgnoringMissing(
            $credential,
            $ingressCollection . '/' . rawurlencode($companionName)
        );
        $this->api->request($credential, 'POST', $ingressCollection, ['json' => [
            'apiVersion' => 'networking.k8s.io/v1',
            'kind' => 'Ingress',
            'metadata' => [
                'name' => $companionName,
                'namespace' => $namespace,
                'labels' => $labels,
                'annotations' => $annotations,
            ],
            'spec' => [
                'rules' => (array) ($mainManifest['spec']['rules'] ?? []),
            ],
        ]]);
    }

    private function upsertKubernetesObject(
        array $credential,
        string $collectionPath,
        string $name,
        array $manifest
    ): void {
        $path = $collectionPath . '/' . rawurlencode($name);
        try {
            $this->api->get($credential, $path);
            $this->api->request($credential, 'PATCH', $path, [
                'headers' => ['Content-Type' => 'application/merge-patch+json'],
                'json' => $manifest,
            ]);
        } catch (AppException $e) {
            if (! str_contains($e->getMessage(), 'HTTP 404')) {
                throw $e;
            }
            $this->api->request($credential, 'POST', $collectionPath, ['json' => $manifest]);
        }
    }

    private function syncExistingIngressRedirectPorts(array $credential, int $httpsPort): void
    {
        $response = $this->api->get($credential, '/apis/networking.k8s.io/v1/ingresses');
        foreach ((array) ($response['items'] ?? []) as $ingress) {
            $metadata = (array) ($ingress['metadata'] ?? []);
            $annotations = (array) ($metadata['annotations'] ?? []);
            if (($annotations[self::INGRESS_HTTPS_REDIRECT_ANNOTATION] ?? '') !== 'true'
                || isset($annotations[self::INGRESS_HTTP_COMPANION_ANNOTATION])) {
                continue;
            }
            $namespace = (string) ($metadata['namespace'] ?? '');
            $name = (string) ($metadata['name'] ?? '');
            if ($namespace === '' || $name === '') {
                continue;
            }
            $annotations[self::INGRESS_HTTPS_REDIRECT_PORT_ANNOTATION] = (string) $httpsPort;
            $ingressPath = $this->nsPath(
                'apis/networking.k8s.io/v1',
                $namespace,
                'ingresses'
            ) . '/' . rawurlencode($name);
            $this->api->request($credential, 'PATCH', $ingressPath, [
                'headers' => ['Content-Type' => 'application/merge-patch+json'],
                'json' => ['metadata' => ['annotations' => $annotations]],
            ]);

            $middlewareName = $this->httpsRedirectMiddlewareName($name);
            $this->upsertKubernetesObject(
                $credential,
                $this->nsPath('apis/traefik.io/v1alpha1', $namespace, 'middlewares'),
                $middlewareName,
                [
                    'apiVersion' => 'traefik.io/v1alpha1',
                    'kind' => 'Middleware',
                    'metadata' => ['name' => $middlewareName, 'namespace' => $namespace],
                    'spec' => ['redirectScheme' => [
                        'scheme' => 'https',
                        'port' => (string) $httpsPort,
                        'permanent' => true,
                    ]],
                ]
            );
        }
    }

    private function deleteKubernetesObjectIgnoringMissing(array $credential, string $path): void
    {
        try {
            $this->api->request($credential, 'DELETE', $path);
        } catch (AppException $e) {
            if (! str_contains($e->getMessage(), 'HTTP 404')) {
                throw $e;
            }
        }
    }

    private function httpCompanionName(string $name): string
    {
        return substr($name, 0, 248) . '-http';
    }

    private function httpsRedirectMiddlewareName(string $name): string
    {
        return substr($name, 0, 238) . '-https-redirect';
    }

    private function prepareIngressTls(int $orgId, array $credential, array &$form): void
    {
        if (empty($form['tls_enabled'])) {
            return;
        }
        $certificateId = (int) ($form['certificate_id'] ?? 0);
        if ($certificateId <= 0) {
            throw new AppException(422, '启用 HTTPS 时必须选择 Galaxy SSL 证书');
        }
        $hosts = array_values(array_unique(array_filter(array_map(
            static fn ($rule): string => strtolower(trim((string) (((array) $rule)['host'] ?? ''))),
            (array) ($form['rules'] ?? [])
        ))));
        if ($hosts === []) {
            throw new AppException(422, '启用 HTTPS 时必须为 Ingress 配置 Host');
        }
        $form['tls_hosts'] = $hosts;
        $form['tls_secret_name'] = $this->syncTlsSecret(
            $orgId,
            $credential,
            (string) $form['namespace'],
            $certificateId,
            $hosts
        );
    }

    public function syncTlsSecret(
        int $orgId,
        array $credential,
        string $namespace,
        int $certificateId,
        array $hosts
    ): string {
        if ($namespace === '' || $hosts === []) {
            throw new AppException(422, 'Kubernetes TLS Secret 缺少 Namespace 或 Host');
        }
        $certificate = null;
        foreach ($hosts as $host) {
            $certificate = $this->tlsCertificates->assertUsableForHostname(
                $orgId,
                $certificateId,
                $host
            );
        }
        if ($certificate === null) {
            throw new AppException(422, '所选 SSL 证书不可用');
        }
        $pair = $this->tlsCertificates->decryptedKeyPair($certificate);
        $secretName = 'galaxy-tls-' . $certificateId;
        $secret = [
            'apiVersion' => 'v1',
            'kind' => 'Secret',
            'metadata' => [
                'name' => $secretName,
                'namespace' => $namespace,
                'labels' => ['app.kubernetes.io/managed-by' => 'galaxy'],
                'annotations' => [
                    self::INGRESS_CERTIFICATE_ANNOTATION => (string) $certificateId,
                ],
            ],
            'type' => 'kubernetes.io/tls',
            'data' => [
                'tls.crt' => base64_encode($pair['certificate_pem']),
                'tls.key' => base64_encode($pair['private_key_pem']),
            ],
        ];
        $collectionPath = $this->nsPath('api/v1', $namespace, 'secrets');
        $secretPath = $collectionPath . '/' . rawurlencode($secretName);
        try {
            $existing = $this->api->get($credential, $secretPath);
            $existingCertificateId = (string) (
                $existing['metadata']['annotations'][self::INGRESS_CERTIFICATE_ANNOTATION] ?? ''
            );
            if ($existingCertificateId !== (string) $certificateId) {
                throw new AppException(409, sprintf(
                    'Kubernetes Secret %s 已存在且不由当前 Galaxy SSL 证书管理',
                    $secretName
                ));
            }
            $this->api->request($credential, 'PATCH', $secretPath, [
                'headers' => ['Content-Type' => 'application/merge-patch+json'],
                'json' => $secret,
            ]);
        } catch (AppException $e) {
            if (! str_contains($e->getMessage(), 'HTTP 404')) {
                throw $e;
            }
            $this->api->request($credential, 'POST', $collectionPath, ['json' => $secret]);
        }
        return $secretName;
    }

    private function namespaceResourceState(
        array $namespace,
        array $quotas,
        array $limitRanges
    ): array {
        $managedQuota = $this->findKubernetesObject($quotas, self::MANAGED_RESOURCE_QUOTA);
        $managedLimitRange = $this->findKubernetesObject($limitRanges, self::MANAGED_LIMIT_RANGE);
        $metadata = (array) ($namespace['metadata'] ?? []);
        $status = (array) ($namespace['status'] ?? []);

        $quotaRows = array_map(static function (array $quota): array {
            $metadata = (array) ($quota['metadata'] ?? []);
            $spec = (array) ($quota['spec'] ?? []);
            $status = (array) ($quota['status'] ?? []);
            return [
                'name' => (string) ($metadata['name'] ?? ''),
                'managed' => (string) ($metadata['name'] ?? '') === self::MANAGED_RESOURCE_QUOTA,
                'hard' => (array) (($status['hard'] ?? []) ?: ($spec['hard'] ?? [])),
                'used' => (array) ($status['used'] ?? []),
                'created_at' => (string) ($metadata['creationTimestamp'] ?? ''),
            ];
        }, $quotas);

        $limitRangeRows = array_map(static function (array $limitRange): array {
            $metadata = (array) ($limitRange['metadata'] ?? []);
            return [
                'name' => (string) ($metadata['name'] ?? ''),
                'managed' => (string) ($metadata['name'] ?? '') === self::MANAGED_LIMIT_RANGE,
                'limits' => (array) ($limitRange['spec']['limits'] ?? []),
                'created_at' => (string) ($metadata['creationTimestamp'] ?? ''),
            ];
        }, $limitRanges);

        return [
            'namespace' => [
                'name' => (string) ($metadata['name'] ?? ''),
                'uid' => (string) ($metadata['uid'] ?? ''),
                'status' => (string) ($status['phase'] ?? 'Unknown'),
                'labels' => (array) ($metadata['labels'] ?? []),
                'annotations' => (array) ($metadata['annotations'] ?? []),
                'created_at' => (string) ($metadata['creationTimestamp'] ?? ''),
            ],
            'resource_quotas' => $quotaRows,
            'limit_ranges' => $limitRangeRows,
            'managed' => [
                'quota_enabled' => $managedQuota !== null,
                'quota_resources' => $this->quotaEditorResources($managedQuota),
                'storage_gib' => $this->quantityToGib(
                    (string) ($managedQuota['spec']['hard']['requests.storage'] ?? '')
                ),
                'pods' => (int) ($managedQuota['spec']['hard']['pods'] ?? 0),
                'services' => (int) ($managedQuota['spec']['hard']['services'] ?? 0),
                'persistent_volume_claims' => (int) (
                    $managedQuota['spec']['hard']['persistentvolumeclaims'] ?? 0
                ),
                'limit_range_enabled' => $managedLimitRange !== null,
                'default_resources' => $this->limitRangeEditorResources($managedLimitRange),
            ],
        ];
    }

    private function findKubernetesObject(array $objects, string $name): ?array
    {
        foreach ($objects as $object) {
            if ((string) ($object['metadata']['name'] ?? '') === $name) {
                return (array) $object;
            }
        }
        return null;
    }

    private function resourceQuotaHard(array $form): array
    {
        $resources = (array) $form['quota_resources'];
        $hard = [];
        $this->putPositiveQuantity($hard, 'requests.cpu', $resources['cpu_reservation'] ?? 0, '');
        $this->putPositiveQuantity($hard, 'limits.cpu', $resources['cpu_limit'] ?? 0, '');
        $this->putPositiveQuantity($hard, 'requests.memory', $resources['memory_reservation'] ?? 0, 'Mi');
        $this->putPositiveQuantity($hard, 'limits.memory', $resources['memory_limit'] ?? 0, 'Mi');
        $this->putPositiveQuantity($hard, 'requests.storage', $form['storage_gib'] ?? 0, 'Gi');
        $this->putPositiveQuantity($hard, 'pods', $form['pods'] ?? 0, '');
        $this->putPositiveQuantity($hard, 'services', $form['services'] ?? 0, '');
        $this->putPositiveQuantity(
            $hard,
            'persistentvolumeclaims',
            $form['persistent_volume_claims'] ?? 0,
            ''
        );
        return $hard;
    }

    private function limitRangeItem(array $resources): array
    {
        $defaultRequest = [];
        $default = [];
        $this->putPositiveQuantity($defaultRequest, 'cpu', $resources['cpu_reservation'] ?? 0, '');
        $this->putPositiveQuantity($defaultRequest, 'memory', $resources['memory_reservation'] ?? 0, 'Mi');
        $this->putPositiveQuantity($default, 'cpu', $resources['cpu_limit'] ?? 0, '');
        $this->putPositiveQuantity($default, 'memory', $resources['memory_limit'] ?? 0, 'Mi');
        if ($defaultRequest === [] && $default === []) {
            return [];
        }
        $item = ['type' => 'Container'];
        if ($defaultRequest !== []) {
            $item['defaultRequest'] = $defaultRequest;
        }
        if ($default !== []) {
            $item['default'] = $default;
        }
        return $item;
    }

    private function quotaEditorResources(?array $quota): array
    {
        $hard = (array) ($quota['spec']['hard'] ?? []);
        return [
            'cpu_reservation' => $this->cpuQuantityToCores((string) ($hard['requests.cpu'] ?? '')),
            'cpu_limit' => $this->cpuQuantityToCores((string) ($hard['limits.cpu'] ?? '')),
            'memory_reservation' => $this->memoryQuantityToMib((string) ($hard['requests.memory'] ?? '')),
            'memory_limit' => $this->memoryQuantityToMib((string) ($hard['limits.memory'] ?? '')),
        ];
    }

    private function limitRangeEditorResources(?array $limitRange): array
    {
        $containerLimit = [];
        foreach ((array) ($limitRange['spec']['limits'] ?? []) as $limit) {
            if ((string) ($limit['type'] ?? '') === 'Container') {
                $containerLimit = (array) $limit;
                break;
            }
        }
        $request = (array) ($containerLimit['defaultRequest'] ?? []);
        $limit = (array) ($containerLimit['default'] ?? []);
        return [
            'cpu_reservation' => $this->cpuQuantityToCores((string) ($request['cpu'] ?? '')),
            'cpu_limit' => $this->cpuQuantityToCores((string) ($limit['cpu'] ?? '')),
            'memory_reservation' => $this->memoryQuantityToMib((string) ($request['memory'] ?? '')),
            'memory_limit' => $this->memoryQuantityToMib((string) ($limit['memory'] ?? '')),
        ];
    }

    private function putPositiveQuantity(
        array &$target,
        string $key,
        mixed $value,
        string $suffix
    ): void {
        $number = (float) $value;
        if ($number <= 0) {
            return;
        }
        $formatted = rtrim(rtrim(sprintf('%.3F', $number), '0'), '.');
        $target[$key] = $formatted . $suffix;
    }

    private function cpuQuantityToCores(string $quantity): float
    {
        if ($quantity === '') {
            return 0;
        }
        if (preg_match('/^([0-9.]+)([num]?)$/', $quantity, $matches) !== 1) {
            return 0;
        }
        $value = (float) $matches[1];
        return match ($matches[2]) {
            'n' => $value / 1000000000,
            'u' => $value / 1000000,
            'm' => $value / 1000,
            default => $value,
        };
    }

    private function memoryQuantityToMib(string $quantity): float
    {
        if (preg_match('/^([0-9.]+)([EPTGMK]i?|)$/i', $quantity, $matches) !== 1) {
            return 0;
        }
        $value = (float) $matches[1];
        $unit = strtolower($matches[2]);
        $binary = ['ki' => 1 / 1024, 'mi' => 1, 'gi' => 1024, 'ti' => 1048576, 'pi' => 1073741824, 'ei' => 1099511627776];
        $decimal = ['k' => 1000 / 1048576, 'm' => 1000000 / 1048576, 'g' => 1000000000 / 1048576, 't' => 1000000000000 / 1048576];
        return $value * ($binary[$unit] ?? $decimal[$unit] ?? (1 / 1048576));
    }

    private function quantityToGib(string $quantity): float
    {
        return round($this->memoryQuantityToMib($quantity) / 1024, 3);
    }

    private function upsertNamespacedObject(
        array $credential,
        string $collectionPath,
        string $name,
        bool $exists,
        array $manifest
    ): void {
        if (! $exists) {
            $this->api->request($credential, 'POST', $collectionPath, ['json' => $manifest]);
            return;
        }
        $this->api->request(
            $credential,
            'PATCH',
            $collectionPath . '/' . rawurlencode($name),
            [
                'headers' => ['Content-Type' => 'application/merge-patch+json'],
                'json' => $manifest,
            ]
        );
    }

    private function connection(int $orgId, int $clusterId): array
    {
        /** @var Cluster|null $cluster */
        $cluster = Cluster::where('id', $clusterId)->where('org_id', $orgId)
            ->where('orchestrator_type', Cluster::ORCHESTRATOR_KUBERNETES)->first();
        if ($cluster === null) {
            throw new AppException(404, 'Kubernetes 集群不存在');
        }
        /** @var KubernetesClusterConnection|null $connection */
        $connection = KubernetesClusterConnection::where('cluster_id', $clusterId)->first();
        if ($connection === null) {
            throw new AppException(409, 'Kubernetes 集群连接配置不存在');
        }
        return [$cluster, $connection];
    }

    public function connectionWithCredential(int $orgId, int $clusterId): array
    {
        [$cluster, $connection] = $this->connection($orgId, $clusterId);
        try {
            $credential = json_decode(
                $this->cipher->decrypt((string) $connection->credential_ciphertext),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (Throwable $e) {
            throw new AppException(500, 'Kubernetes 集群凭据无法读取：' . $e->getMessage());
        }
        if (! is_array($credential)) {
            throw new AppException(500, 'Kubernetes 集群凭据格式无效');
        }
        return [$cluster, $connection, $credential];
    }

    private function markConnectionHealthy(KubernetesClusterConnection $connection): void
    {
        $connection->status = 'online';
        $connection->last_checked_at = time();
        $connection->last_error = '';
        $connection->updated_at = time();
        $connection->save();
    }

    private function markOnline(
        Cluster $cluster,
        KubernetesClusterConnection $connection,
        string $version
    ): void {
        $now = time();
        $connection->status = 'online';
        $connection->version = $version ?: (string) $connection->version;
        $connection->last_checked_at = $now;
        $connection->last_error = '';
        $connection->updated_at = $now;
        $connection->save();
        $cluster->status = Cluster::STATUS_READY;
        $cluster->version = (string) $connection->version;
        $cluster->save();
    }

    private function markOffline(
        Cluster $cluster,
        KubernetesClusterConnection $connection,
        string $error
    ): void {
        $now = time();
        $connection->status = 'offline';
        $connection->last_checked_at = $now;
        $connection->last_error = mb_substr($error, 0, 2000);
        $connection->updated_at = $now;
        $connection->save();
        $cluster->status = Cluster::STATUS_OFFLINE;
        $cluster->save();
    }

    private function publicCluster(Cluster $cluster): array
    {
        $connection = $cluster->relationLoaded('kubernetesConnection')
            ? $cluster->kubernetesConnection
            : null;
        return [
            'id' => (int) $cluster->id,
            'org_id' => (int) $cluster->org_id,
            'title' => (string) $cluster->title,
            'remark' => (string) $cluster->remark,
            'orchestrator_type' => Cluster::ORCHESTRATOR_KUBERNETES,
            'status' => (int) $cluster->status,
            'version' => (string) $cluster->version,
            'creator' => (int) $cluster->creator,
            'creator_info' => $cluster->creatorInfo,
            'created_at' => (int) $cluster->created_at,
            'connection' => $connection instanceof KubernetesClusterConnection
                ? $this->publicConnection($connection)
                : null,
        ];
    }

    private function publicConnection(KubernetesClusterConnection $connection): array
    {
        return [
            'server_url' => (string) $connection->server_url,
            'context_name' => (string) $connection->context_name,
            'default_namespace' => (string) $connection->default_namespace,
            'ingress_http_port' => (int) $connection->ingress_http_port,
            'ingress_https_port' => (int) $connection->ingress_https_port,
            'status' => (string) $connection->status,
            'version' => (string) $connection->version,
            'last_checked_at' => (int) $connection->last_checked_at,
            'last_error' => (string) $connection->last_error,
        ];
    }

    private function nodeRow(array $node): array
    {
        $labels = (array) ($node['metadata']['labels'] ?? []);
        $conditions = (array) ($node['status']['conditions'] ?? []);
        $ready = false;
        foreach ($conditions as $condition) {
            if (($condition['type'] ?? '') === 'Ready') {
                $ready = ($condition['status'] ?? '') === 'True';
                break;
            }
        }
        $roles = [];
        foreach (array_keys($labels) as $label) {
            if (str_starts_with($label, 'node-role.kubernetes.io/')) {
                $roles[] = substr($label, strlen('node-role.kubernetes.io/')) ?: 'worker';
            }
        }
        return [
            'name' => (string) ($node['metadata']['name'] ?? ''),
            'uid' => (string) ($node['metadata']['uid'] ?? ''),
            'ready' => $ready,
            'roles' => array_values(array_unique($roles ?: ['worker'])),
            'addresses' => (array) ($node['status']['addresses'] ?? []),
            'kubelet_version' => (string) ($node['status']['nodeInfo']['kubeletVersion'] ?? ''),
            'container_runtime' => (string) ($node['status']['nodeInfo']['containerRuntimeVersion'] ?? ''),
            'os_image' => (string) ($node['status']['nodeInfo']['osImage'] ?? ''),
            'architecture' => (string) ($node['status']['nodeInfo']['architecture'] ?? ''),
            'capacity' => (array) ($node['status']['capacity'] ?? []),
            'allocatable' => (array) ($node['status']['allocatable'] ?? []),
            'created_at' => (string) ($node['metadata']['creationTimestamp'] ?? ''),
        ];
    }
}

<?php

/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */

namespace App\Services\Docker;

use App\Exception\AppException;
use App\Model\ClusterAgentNode;
use App\Model\Workspace;
use App\Model\Cluster;
use App\Services\RegistryService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Hyperf\Coroutine\Parallel;
use Throwable;

class SwarmOverviewService
{
    /**
     * Docker 内置的保留网络，不允许通过界面删除。
     */
    private const RESERVED_NETWORKS = ['host', 'none', 'ingress', 'bridge'];

    /**
     * 视为“有效接入网络”的容器状态，其余（exited/dead/created 等）不计入关联容器。
     */
    private const ACTIVE_CONTAINER_STATES = ['running', 'paused', 'restarting'];

    public function __construct(
        private SwarmApiClient $docker,
        private AgentRelayService $agent,
        private RegistryService $registryService
    ) {}

    public function ping(Cluster $cluster): bool
    {
        try {
            $this->agent->domainCommand((int) $cluster->id, 'swarm.version', [], 5);
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function joinCommands(Cluster $cluster, ?string $requestedAddress = null): array
    {
        $swarm = $this->agent->domainCommand((int) $cluster->id, 'swarm.inspect');
        $info = $this->agent->domainCommand((int) $cluster->id, 'swarm.info');
        $tokens = $swarm['JoinTokens'] ?? [];
        $managerToken = trim((string) ($tokens['Manager'] ?? ''));
        $workerToken = trim((string) ($tokens['Worker'] ?? ''));
        $nodeAddr = trim((string) ($info['Swarm']['NodeAddr'] ?? ''));
        if ($managerToken === '' || $workerToken === '') {
            throw new AppException(502, 'Manager Agent 未返回完整的 Swarm Join 信息');
        }
        $addresses = $this->joinAddressCandidates($cluster, $nodeAddr);
        $selectedAddress = trim((string) $requestedAddress);
        if ($selectedAddress !== '') {
            $selectedAddress = $this->validateJoinAddress($selectedAddress);
            if (! in_array($selectedAddress, $addresses, true)) {
                array_unshift($addresses, $selectedAddress);
            }
        } elseif ($addresses !== []) {
            $selectedAddress = $addresses[0];
        }
        if ($selectedAddress === '') {
            throw new AppException(502, '未发现可用于新节点加入集群的 Manager 地址');
        }
        $listenAddress = str_contains($selectedAddress, ':')
            ? '[' . trim($selectedAddress, '[]') . ']:2377'
            : $selectedAddress . ':2377';
        return [
            'manager' => sprintf('docker swarm join --token %s %s', $managerToken, $listenAddress),
            'worker' => sprintf('docker swarm join --token %s %s', $workerToken, $listenAddress),
            'address' => $selectedAddress,
            'addresses' => $addresses,
        ];
    }

    private function joinAddressCandidates(Cluster $cluster, string $nodeAddr): array
    {
        $addresses = [];
        if (filter_var($nodeAddr, FILTER_VALIDATE_IP) !== false) {
            $addresses[$nodeAddr] = true;
        }
        $nodes = ClusterAgentNode::where('cluster_id', (int) $cluster->id)
            ->where('role', 'manager')
            ->orderByDesc('last_seen_at')
            ->get();
        foreach ($nodes as $node) {
            $reportedNodeAddr = trim((string) $node->node_addr);
            if (filter_var($reportedNodeAddr, FILTER_VALIDATE_IP) !== false) {
                $addresses[$reportedNodeAddr] = true;
            }
            foreach ((array) (($node->capabilities ?? [])['join_addresses'] ?? []) as $address) {
                $address = trim((string) $address);
                if (filter_var($address, FILTER_VALIDATE_IP) !== false) {
                    $addresses[$address] = true;
                }
            }
        }
        $result = array_keys($addresses);
        usort($result, fn (string $left, string $right): int => $this->joinAddressPriority($left) <=> $this->joinAddressPriority($right));
        return $result;
    }

    private function joinAddressPriority(string $address): int
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $public = filter_var(
                $address,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            );
            return $public === false ? 0 : 1;
        }
        return 2;
    }

    private function validateJoinAddress(string $address): string
    {
        $address = trim($address, " \t\n\r\0\x0B[]");
        if (filter_var($address, FILTER_VALIDATE_IP) !== false) {
            return $address;
        }
        if (strlen($address) <= 253
            && preg_match('/^(?=.{1,253}$)(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)*[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?$/', $address)) {
            return strtolower($address);
        }
        throw new AppException(422, 'Join 地址必须是有效的 IP 地址或主机名，且不包含端口');
    }

    private function createClient(Cluster $cluster): array
    {
        return $this->docker->clientForCluster($cluster, 10, true);
    }

    /**
     * Return the Swarm control-plane nodes together with their node Agent state.
     * Docker containers, images and local volumes are daemon-local resources and
     * must only be queried through the Agent connected from that exact NodeID.
     */
    public function resourceNodes(Cluster $cluster, ?Client $client = null): array
    {
        $temporaryFiles = [];
        if ($client === null) {
            [$client, $temporaryFiles] = $this->createClient($cluster);
        }
        try {
            $agents = [];
            foreach (ClusterAgentNode::where('cluster_id', (int) $cluster->id)->get() as $agent) {
                $agents[(string) $agent->node_id] = $agent;
            }
            return array_map(static function (array $node) use ($agents): array {
                $nodeId = (string) ($node['ID'] ?? '');
                $agent = $agents[$nodeId] ?? null;
                return [
                    'id' => $nodeId,
                    'hostname' => (string) ($node['Description']['Hostname'] ?? ''),
                    'role' => (string) ($node['Spec']['Role'] ?? ''),
                    'state' => (string) ($node['Status']['State'] ?? ''),
                    'availability' => (string) ($node['Spec']['Availability'] ?? ''),
                    'agent_online' => $agent !== null && (string) $agent->status === 'online',
                    'agent_version' => $agent === null ? '' : (string) $agent->agent_version,
                    'last_seen_at' => $agent === null ? 0 : (int) $agent->last_seen_at,
                ];
            }, $this->request($client, '/nodes'));
        } finally {
            foreach ($temporaryFiles as $file) {
                @unlink($file);
            }
        }
    }

    private function resourceNode(Cluster $cluster, string $nodeId, ?Client $client = null): array
    {
        foreach ($this->resourceNodes($cluster, $client) as $node) {
            if ($node['id'] === $nodeId) {
                return $node;
            }
        }
        throw new AppException(422, '目标节点不存在于当前 Swarm');
    }

    public function listContainers(
        Cluster $cluster,
        ?array $serviceReferences = null,
        string $targetNodeId = ''
    ): array
    {
        [$client, $temporaryFiles] = $this->createClient($cluster);
        try {
            $parallel = new Parallel(3);
            $parallel->add(fn (): array => $this->request($client, '/tasks'), 'tasks');
            $parallel->add(fn (): array => $this->request($client, '/nodes'), 'nodes');
            $parallel->add(fn (): array => $this->request($client, '/services'), 'services');
            $result = $parallel->wait();

            $allowed = $serviceReferences === null ? null : array_fill_keys($serviceReferences, true);
            $nodes = [];
            foreach ($result['nodes'] as $node) {
                $nodeId = (string) ($node['ID'] ?? '');
                if ($nodeId !== '') {
                    $nodes[$nodeId] = (string) ($node['Description']['Hostname'] ?? '');
                }
            }
            if ($targetNodeId === '' || ! isset($nodes[$targetNodeId])) {
                throw new AppException(422, '目标节点不存在于当前 Swarm');
            }
            $containers = [];
            $containerIds = [];
            try {
                $nodeRows = $this->docker->withNode(
                    $cluster,
                    $targetNodeId,
                    fn (Client $nodeClient): array => $this->containers(
                        $nodeClient,
                        $this->request($nodeClient, '/containers/json', ['query' => ['all' => 1]])
                    ),
                    15
                );
                foreach ($nodeRows as $row) {
                    if ($allowed !== null
                        && ! isset($allowed[(string) ($row['service_id'] ?? '')])
                        && ! isset($allowed[(string) ($row['service_name'] ?? '')])) {
                        continue;
                    }
                    $row['node_id'] = $targetNodeId;
                    $row['node_hostname'] = $nodes[$targetNodeId];
                    $row['operable'] = true;
                    $row['remote'] = false;
                    $row['agent_online'] = true;
                    $containers[] = $row;
                    $containerIds[(string) ($row['id'] ?? '')] = true;
                }
            } catch (Throwable) {
                // Preserve Swarm Task placeholders for an offline selected node.
            }

            $services = [];
            foreach ($result['services'] as $service) {
                $id = (string) ($service['ID'] ?? '');
                $name = (string) ($service['Spec']['Name'] ?? '');
                if ($allowed === null || isset($allowed[$id]) || isset($allowed[$name])) {
                    $services[$id] = $service;
                }
            }
            $rows = array_merge(
                $containers,
                $this->remoteTaskContainers(
                    array_values(array_filter(
                        $result['tasks'],
                        static fn (array $task): bool => (string) ($task['NodeID'] ?? '') === $targetNodeId
                    )),
                    $services,
                    $nodes,
                    $containerIds
                )
            );
            return array_map(static function (array $row) use ($cluster): array {
                $row['cluster_id'] = (int) $cluster->id;
                return $row;
            }, $rows);
        } catch (GuzzleException $e) {
            throw new AppException(502, '无法连接 Docker Manager API：' . $e->getMessage(), [], $e);
        } finally {
            foreach ($temporaryFiles as $file) {
                @unlink($file);
            }
        }
    }

    private function aggregateContainers(
        Client $client,
        array $raw,
        array $tasks,
        array $rawNodes,
        array $rawServices,
        ?array $serviceReferences = null
    ): array {
        $allowed = $serviceReferences === null ? null : array_fill_keys($serviceReferences, true);
        if ($allowed !== null) {
            $raw = array_values(array_filter($raw, static function (array $container) use ($allowed): bool {
                $labels = (array) ($container['Labels'] ?? []);
                return isset($allowed[(string) ($labels['com.docker.swarm.service.id'] ?? '')])
                    || isset($allowed[(string) ($labels['com.docker.swarm.service.name'] ?? '')]);
            }));
        }
        $local = $this->containers($client, $raw);
        $localIds = array_fill_keys(array_column($local, 'id'), true);
        $nodes = [];
        foreach ($rawNodes as $node) {
            $nodes[(string) ($node['ID'] ?? '')] = (string) ($node['Description']['Hostname'] ?? '');
        }
        $services = [];
        foreach ($rawServices as $service) {
            $id = (string) ($service['ID'] ?? '');
            $name = (string) ($service['Spec']['Name'] ?? '');
            if ($allowed !== null && ! isset($allowed[$id]) && ! isset($allowed[$name])) {
                continue;
            }
            $services[$id] = $service;
        }

        $local = array_map(static function (array $container) use ($nodes): array {
            $nodeId = (string) ($container['node_id'] ?? '');
            return array_merge($container, [
                'node_hostname' => $nodes[$nodeId] ?? '',
                'operable' => true,
                'remote' => false,
            ]);
        }, $local);

        return array_merge($local, $this->remoteTaskContainers($tasks, $services, $nodes, $localIds));
    }

    /**
     * 当目标节点 Agent 暂时离线时，用 Swarm 控制面的 Task 保留容器行。
     * 这些占位记录不可操作，Agent 恢复后会被真实容器数据替换。
     */
    private function remoteTaskContainers(array $tasks, array $services, array $nodes, array $localIds): array
    {
        $rows = [];
        foreach ($tasks as $task) {
            $containerId = (string) ($task['Status']['ContainerStatus']['ContainerID'] ?? '');
            $serviceId = (string) ($task['ServiceID'] ?? '');
            if ($containerId === ''
                || isset($localIds[$containerId])
                || (string) ($task['DesiredState'] ?? '') !== 'running'
                || ! isset($services[$serviceId])) {
                continue;
            }

            $service = $services[$serviceId];
            $serviceName = (string) ($service['Spec']['Name'] ?? '');
            $taskId = (string) ($task['ID'] ?? '');
            $nodeId = (string) ($task['NodeID'] ?? '');
            $slot = (int) ($task['Slot'] ?? 0);
            $nodeName = $nodes[$nodeId] ?? '';
            $instance = $slot > 0 ? (string) $slot : ($nodeName !== '' ? $nodeName : substr($nodeId, 0, 12));
            $networks = [];
            $ipAddresses = [];
            foreach ((array) ($task['NetworksAttachments'] ?? []) as $attachment) {
                $networkName = (string) ($attachment['Network']['Spec']['Name'] ?? $attachment['Network']['ID'] ?? '');
                if ($networkName === '') {
                    continue;
                }
                $networks[] = $networkName;
                $address = (string) (($attachment['Addresses'][0] ?? ''));
                if ($address !== '') {
                    $ipAddresses[$networkName] = explode('/', $address, 2)[0];
                }
            }
            $ports = array_map(static function (array $port): string {
                return sprintf(
                    '%s:%s/%s',
                    $port['PublishedPort'] ?? '-',
                    $port['TargetPort'] ?? '-',
                    $port['Protocol'] ?? 'tcp'
                );
            }, (array) ($service['Endpoint']['Ports'] ?? []));
            $createdAt = strtotime((string) ($task['CreatedAt'] ?? ''));
            $state = (string) ($task['Status']['State'] ?? 'unknown');
            $status = trim((string) ($task['Status']['Err'] ?? ''));
            if ($status === '') {
                $status = (string) ($task['Status']['Message'] ?? '');
            }

            $rows[] = [
                'id' => $containerId,
                'name' => trim($serviceName . '.' . $instance . '.' . substr($taskId, 0, 12), '.'),
                'image' => (string) ($service['Spec']['TaskTemplate']['ContainerSpec']['Image'] ?? ''),
                'service_id' => $serviceId,
                'service_name' => $serviceName,
                'task_id' => $taskId,
                'node_id' => $nodeId,
                'node_hostname' => $nodeName,
                'state' => $state,
                'status' => $status,
                'health' => 'none',
                'created_at' => $createdAt === false ? 0 : $createdAt,
                'cpu_percent' => 0,
                'memory_usage' => 0,
                'memory_limit' => 0,
                'pids' => 0,
                'network_rx' => 0,
                'network_tx' => 0,
                'ports' => $ports,
                'networks' => array_values(array_unique($networks)),
                'ip_addresses' => $ipAddresses,
                'mounts' => count((array) ($task['Spec']['ContainerSpec']['Mounts'] ?? [])),
                'operable' => false,
                'remote' => true,
                'agent_online' => false,
            ];
        }
        return $rows;
    }

    public function listConfigs(Cluster $cluster): array
    {
        [$client, $temporaryFiles] = $this->createClient($cluster);
        try {
            $raw = $this->request($client, '/configs');
            $used = $this->usedConfigAndSecretIds($client);
            return array_map(static fn (array $config): array => [
                'id' => $config['ID'] ?? '',
                'name' => $config['Spec']['Name'] ?? '',
                'scope' => 'swarm',
                'created_at' => $config['CreatedAt'] ?? '',
                'updated_at' => $config['UpdatedAt'] ?? '',
                'labels' => $config['Spec']['Labels'] ?? [],
                'in_use' => isset($used['configs'][$config['ID'] ?? '']),
            ], $raw);
        } catch (GuzzleException $e) {
            throw new AppException(502, '无法连接 Docker Manager API：' . $e->getMessage(), [], $e);
        } finally {
            foreach ($temporaryFiles as $file) {
                @unlink($file);
            }
        }
    }

    public function getConfig(Cluster $cluster, string $configId): array
    {
        [$client, $temporaryFiles] = $this->createClient($cluster);
        try {
            $raw = $this->request($client, '/configs/' . rawurlencode($configId));
            return [
                'id' => $raw['ID'] ?? '',
                'name' => $raw['Spec']['Name'] ?? '',
                'created_at' => $raw['CreatedAt'] ?? '',
                'updated_at' => $raw['UpdatedAt'] ?? '',
                'labels' => $raw['Spec']['Labels'] ?? [],
                'content' => base64_decode((string) ($raw['Spec']['Data'] ?? ''), true) ?: '',
            ];
        } catch (GuzzleException $e) {
            throw new AppException(502, '无法连接 Docker Manager API：' . $e->getMessage(), [], $e);
        } finally {
            foreach ($temporaryFiles as $file) {
                @unlink($file);
            }
        }
    }

    public function listSecrets(Cluster $cluster): array
    {
        [$client, $temporaryFiles] = $this->createClient($cluster);
        try {
            $raw = $this->request($client, '/secrets');
            $used = $this->usedConfigAndSecretIds($client);
            return array_map(static fn (array $secret): array => [
                'id' => $secret['ID'] ?? '',
                'name' => $secret['Spec']['Name'] ?? '',
                'scope' => 'swarm',
                'created_at' => $secret['CreatedAt'] ?? '',
                'updated_at' => $secret['UpdatedAt'] ?? '',
                'labels' => $secret['Spec']['Labels'] ?? [],
                'in_use' => isset($used['secrets'][$secret['ID'] ?? '']),
            ], $raw);
        } catch (GuzzleException $e) {
            throw new AppException(502, '无法连接 Docker Manager API：' . $e->getMessage(), [], $e);
        } finally {
            foreach ($temporaryFiles as $file) {
                @unlink($file);
            }
        }
    }

    /**
     * 新建 Config（可用于「新增」或「克隆」——克隆即前端预填内容后调用本方法）。
     */
    public function createConfig(Cluster $cluster, string $name, string $content, array $labels = []): array
    {
        [$client, $temporaryFiles] = $this->createClient($cluster);
        try {
            if (! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,62}$/', $name)) {
                throw new AppException(422, 'Config 名称仅允许字母、数字、下划线、点号和连字符，最长 63 字符');
            }
            $json = ['Name' => $name, 'Data' => base64_encode($content)];
            if ($labels) {
                $json['Labels'] = $labels;
            }
            $created = $this->docker->request($client, 'POST', '/configs/create', ['json' => $json]);
            $newId = $created['ID'] ?? '';
            if ($newId === '') {
                throw new AppException(502, 'Docker API 未返回新 Config ID');
            }
            return ['id' => $newId, 'name' => $name];
        } catch (AppException $e) {
            throw $e;
        } catch (GuzzleException $e) {
            throw new AppException(502, '创建 Config 失败：' . $e->getMessage(), [], $e);
        } finally {
            foreach ($temporaryFiles as $file) {
                @unlink($file);
            }
        }
    }

    /**
     * 新建 Secret。
     */
    public function createSecret(Cluster $cluster, string $name, string $content, array $labels = []): array
    {
        [$client, $temporaryFiles] = $this->createClient($cluster);
        try {
            if (! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,62}$/', $name)) {
                throw new AppException(422, 'Secret 名称仅允许字母、数字、下划线、点号和连字符，最长 63 字符');
            }
            $json = ['Name' => $name, 'Data' => base64_encode($content)];
            if ($labels) {
                $json['Labels'] = $labels;
            }
            $created = $this->docker->request($client, 'POST', '/secrets/create', ['json' => $json]);
            $newId = $created['ID'] ?? '';
            if ($newId === '') {
                throw new AppException(502, 'Docker API 未返回新 Secret ID');
            }
            return ['id' => $newId, 'name' => $name];
        } catch (AppException $e) {
            throw $e;
        } catch (GuzzleException $e) {
            throw new AppException(502, '创建 Secret 失败：' . $e->getMessage(), [], $e);
        } finally {
            foreach ($temporaryFiles as $file) {
                @unlink($file);
            }
        }
    }

    public function deleteConfig(Cluster $cluster, string $configId): void
    {
        [$client, $temporaryFiles] = $this->createClient($cluster);
        try {
            if ($this->configOrSecretInUse($client, 'config', $configId)) {
                throw new AppException(422, '该 Config 仍被至少一个 Service 使用，无法删除');
            }
            $response = $client->delete('/configs/' . rawurlencode($configId));
            if ($response->getStatusCode() >= 400) {
                $body = (string) $response->getBody();
                $data = $body ? json_decode($body, true, 512, JSON_THROW_ON_ERROR) : [];
                $message = is_array($data) ? ($data['message'] ?? '') : '';
                throw new AppException(502, '删除 Config 失败：' . ($message ?: 'unknown'));
            }
        } catch (AppException $e) {
            throw $e;
        } catch (GuzzleException $e) {
            throw new AppException(502, '无法连接 Docker Manager API：' . $e->getMessage(), [], $e);
        } finally {
            foreach ($temporaryFiles as $file) {
                @unlink($file);
            }
        }
    }

    public function deleteSecret(Cluster $cluster, string $secretId): void
    {
        [$client, $temporaryFiles] = $this->createClient($cluster);
        try {
            if ($this->configOrSecretInUse($client, 'secret', $secretId)) {
                throw new AppException(422, '该 Secret 仍被至少一个 Service 使用，无法删除');
            }
            $response = $client->delete('/secrets/' . rawurlencode($secretId));
            if ($response->getStatusCode() >= 400) {
                $body = (string) $response->getBody();
                $data = $body ? json_decode($body, true, 512, JSON_THROW_ON_ERROR) : [];
                $message = is_array($data) ? ($data['message'] ?? '') : '';
                throw new AppException(502, '删除 Secret 失败：' . ($message ?: 'unknown'));
            }
        } catch (AppException $e) {
            throw $e;
        } catch (GuzzleException $e) {
            throw new AppException(502, '无法连接 Docker Manager API：' . $e->getMessage(), [], $e);
        } finally {
            foreach ($temporaryFiles as $file) {
                @unlink($file);
            }
        }
    }

    /**
     * 列出所有 Service 中正在使用的 Config / Secret ID（用于前端标记与删除保护）。
     * 使用 optionalRequest：即便获取失败也返回空集合，避免影响列表展示。
     */
    private function usedConfigAndSecretIds(Client $client): array
    {
        $services = $this->optionalRequest($client, '/services') ?: [];
        $configs = [];
        $secrets = [];
        foreach ($services as $service) {
            $containerSpec = $service['Spec']['TaskTemplate']['ContainerSpec'] ?? [];
            foreach ($containerSpec['Configs'] ?? [] as $c) {
                if (($c['ConfigID'] ?? '') !== '') {
                    $configs[$c['ConfigID']] = true;
                }
            }
            foreach ($containerSpec['Secrets'] ?? [] as $s) {
                if (($s['SecretID'] ?? '') !== '') {
                    $secrets[$s['SecretID']] = true;
                }
            }
        }
        return ['configs' => $configs, 'secrets' => $secrets];
    }

    /**
     * 判断某 Config / Secret 是否被任意 Service 使用（失败即抛错，确保删除保护不失效）。
     */
    private function configOrSecretInUse(Client $client, string $kind, string $id): bool
    {
        $services = $this->request($client, '/services');
        foreach ($services as $service) {
            $containerSpec = $service['Spec']['TaskTemplate']['ContainerSpec'] ?? [];
            if ($kind === 'config') {
                foreach ($containerSpec['Configs'] ?? [] as $c) {
                    if (($c['ConfigID'] ?? '') === $id) {
                        return true;
                    }
                }
            } else {
                foreach ($containerSpec['Secrets'] ?? [] as $s) {
                    if (($s['SecretID'] ?? '') === $id) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    public function inspectContainer(Cluster $cluster, string $containerId): array
    {
        [$client, $temporaryFiles] = $this->createClient($cluster);
        try {
            $raw = $this->request($client, '/containers/' . rawurlencode($containerId) . '/json');
            return [
                'id' => $raw['Id'] ?? '',
                'name' => ltrim($raw['Name'] ?? '', '/'),
                'platform' => ($raw['Platform'] ?? '') ?: '-',
                'created' => strtotime($raw['Created'] ?? ''),
                'image' => $raw['Config']['Image'] ?? '',
                'state' => $raw['State']['Status'] ?? '',
                'error' => $raw['State']['Error'] ?? '',
                'exit_code' => (int) ($raw['State']['ExitCode'] ?? 0),
                'started_at' => strtotime($raw['State']['StartedAt'] ?? ''),
                'finished_at' => strtotime($raw['State']['FinishedAt'] ?? ''),
                'restart_count' => (int) ($raw['RestartCount'] ?? 0),
                'pid' => (int) ($raw['State']['Pid'] ?? 0),
                'env' => $raw['Config']['Env'] ?? [],
                'cmd_array' => (array) ($raw['Config']['Cmd'] ?? []),
                'entrypoint_array' => (array) ($raw['Config']['Entrypoint'] ?? []),
                'cmd' => is_array($raw['Config']['Cmd'] ?? null) ? implode(' ', $raw['Config']['Cmd']) : ($raw['Config']['Cmd'] ?? ''),
                'entrypoint' => is_array($raw['Config']['Entrypoint'] ?? null) ? implode(' ', $raw['Config']['Entrypoint']) : '',
                'working_dir' => $raw['Config']['WorkingDir'] ?? '',
                'user' => $raw['Config']['User'] ?? '',
                'exposed_ports' => array_keys($raw['Config']['ExposedPorts'] ?? []),
                'labels' => $raw['Config']['Labels'] ?? [],
                'tty' => (bool) ($raw['Config']['Tty'] ?? false),
                'open_stdin' => (bool) ($raw['Config']['OpenStdin'] ?? false),
                'hostname' => $raw['Config']['Hostname'] ?? '',
                'domainname' => $raw['Config']['Domainname'] ?? '',
                'restart_policy' => $raw['HostConfig']['RestartPolicy'] ?? [],
                'privileged' => (bool) ($raw['HostConfig']['Privileged'] ?? false),
                'readonly_rootfs' => (bool) ($raw['HostConfig']['ReadonlyRootfs'] ?? false),
                'auto_remove' => (bool) ($raw['HostConfig']['AutoRemove'] ?? false),
                'network_mode' => $raw['HostConfig']['NetworkMode'] ?? '',
                'binds' => $raw['HostConfig']['Binds'] ?? [],
                'dns' => $raw['HostConfig']['Dns'] ?? [],
                'dns_search' => $raw['HostConfig']['DnsSearch'] ?? [],
                'extra_hosts' => $raw['HostConfig']['ExtraHosts'] ?? [],
                'log_config' => $raw['HostConfig']['LogConfig'] ?? [],
                'port_bindings' => $raw['HostConfig']['PortBindings'] ?? [],
                'nano_cpus' => (int) ($raw['HostConfig']['NanoCpus'] ?? 0),
                'memory' => (int) ($raw['HostConfig']['Memory'] ?? 0),
                'memory_swap' => (int) ($raw['HostConfig']['MemorySwap'] ?? 0),
                'cap_add' => $raw['HostConfig']['CapAdd'] ?? [],
                'cap_drop' => $raw['HostConfig']['CapDrop'] ?? [],
                'security_opt' => $raw['HostConfig']['SecurityOpt'] ?? [],
                'health_check' => $raw['Config']['Healthcheck'] ?? [],
                'networks' => $this->inspectNetworks($raw['NetworkSettings']['Networks'] ?? []),
                'mounts' => $this->inspectMounts($raw['Mounts'] ?? []),
            ];
        } catch (GuzzleException $e) {
            throw new AppException(502, '无法连接 Docker Manager API：' . $e->getMessage(), [], $e);
        } finally {
            foreach ($temporaryFiles as $file) {
                @unlink($file);
            }
        }
    }

    /** Resolve the Swarm Service owning a container without exposing raw inspect data. */
    public function containerServiceReference(Cluster $cluster, string $containerId): string
    {
        [$client, $temporaryFiles] = $this->createClient($cluster);
        try {
            $raw = $this->request($client, '/containers/' . rawurlencode($containerId) . '/json');
            $labels = (array) ($raw['Config']['Labels'] ?? []);
            return (string) ($labels['com.docker.swarm.service.id']
                ?? $labels['com.docker.swarm.service.name'] ?? '');
        } catch (GuzzleException $e) {
            throw new AppException(502, '无法连接 Docker Manager API：' . $e->getMessage(), [], $e);
        } finally {
            foreach ($temporaryFiles as $file) {
                @unlink($file);
            }
        }
    }

    /**
     * 获取容器实时统计快照（CPU / 内存 / 网络 / 磁盘 IO 聚合）。
     * 基于 Docker 一次性 stats 接口（stream=false），返回的均为累计值。
     */
    public function containerStats(Cluster $cluster, string $containerId): array
    {
        [$client, $temporaryFiles] = $this->createClient($cluster);
        try {
            $stats = $this->optionalRequest($client, '/containers/' . rawurlencode($containerId) . '/stats', [
                'query' => ['stream' => 'false', 'one-shot' => 'true'],
            ]);
            if (empty($stats)) {
                return [
                    'timestamp' => time(),
                    'running' => false,
                    'memory' => ['usage' => 0, 'limit' => 0, 'cache' => 0],
                    'cpu' => ['usage' => 0, 'cores' => 0],
                    'network' => ['rx_bytes' => 0, 'tx_bytes' => 0],
                    'io' => ['read_bytes' => 0, 'write_bytes' => 0],
                ];
            }

            $memoryStats = $stats['memory_stats'] ?? [];
            $memUsage = max(0, (int) ($memoryStats['usage'] ?? 0) - (int) ($memoryStats['stats']['inactive_file'] ?? 0));
            $cpuStats = $stats['cpu_stats'] ?? [];
            $onlineCpus = (int) ($cpuStats['online_cpus'] ?? count($cpuStats['cpu_usage']['percpu_usage'] ?? []));

            $rxBytes = 0;
            $txBytes = 0;
            foreach ($stats['networks'] ?? [] as $network) {
                $rxBytes += (int) ($network['rx_bytes'] ?? 0);
                $txBytes += (int) ($network['tx_bytes'] ?? 0);
            }

            $io = $this->blkioBytes($stats['blkio_stats'] ?? []);

            return [
                'timestamp' => time(),
                'running' => true,
                'memory' => [
                    'usage' => $memUsage,
                    'limit' => (int) ($memoryStats['limit'] ?? 0),
                    'cache' => (int) ($memoryStats['stats']['cache'] ?? 0),
                ],
                'cpu' => [
                    'usage' => $this->containerCpuPercentage($stats),
                    'cores' => $onlineCpus,
                ],
                'network' => [
                    'rx_bytes' => $rxBytes,
                    'tx_bytes' => $txBytes,
                ],
                'io' => [
                    'read_bytes' => $io['read'],
                    'write_bytes' => $io['write'],
                ],
            ];
        } catch (GuzzleException $e) {
            throw new AppException(502, '无法连接 Docker Manager API：' . $e->getMessage(), [], $e);
        } finally {
            foreach ($temporaryFiles as $file) {
                @unlink($file);
            }
        }
    }

    /**
     * 获取容器内的进程列表。
     * 通过定制 ps 参数返回 UID/PID/PPID/C/STIME/TTY/TIME/CMD 列。
     */
    public function containerTop(Cluster $cluster, string $containerId): array
    {
        [$client, $temporaryFiles] = $this->createClient($cluster);
        try {
            $raw = $this->request($client, '/containers/' . rawurlencode($containerId) . '/top', [
                'query' => ['ps_args' => '-o uid,pid,ppid,c,stime,tty,time,cmd'],
            ]);
            return [
                'titles' => $raw['Titles'] ?? [],
                'processes' => $raw['Processes'] ?? [],
            ];
        } catch (GuzzleException $e) {
            throw new AppException(502, '无法连接 Docker Manager API：' . $e->getMessage(), [], $e);
        } finally {
            foreach ($temporaryFiles as $file) {
                @unlink($file);
            }
        }
    }

    /**
     * 从 blkio_stats 中聚合读/写字节数。
     */
    private function blkioBytes(array $blkioStats): array
    {
        $read = 0;
        $write = 0;
        foreach ($blkioStats['io_service_bytes_recursive'] ?? [] as $entry) {
            $op = strtolower($entry['op'] ?? '');
            $value = (int) ($entry['value'] ?? 0);
            if ($op === 'read') {
                $read += $value;
            } elseif ($op === 'write') {
                $write += $value;
            }
        }
        // 部分内核以 Total 汇总，兜底累加
        $total = 0;
        foreach ($blkioStats['io_service_bytes_recursive'] ?? [] as $entry) {
            if (strtolower($entry['op'] ?? '') === 'total') {
                $total += (int) ($entry['value'] ?? 0);
            }
        }
        if ($total > 0 && $read === 0 && $write === 0) {
            $read = $total;
        }
        return ['read' => $read, 'write' => $write];
    }

    public function containerLogs(Cluster $cluster, string $containerId, int $tail = 100): string
    {
        [$client, $temporaryFiles] = $this->createClient($cluster);
        try {
            $response = $client->get('/containers/' . rawurlencode($containerId) . '/logs', [
                'query' => [
                    'stdout' => 1,
                    'stderr' => 1,
                    'tail' => $tail,
                    'timestamps' => 0,
                ],
                'stream' => true,
            ]);
            $body = (string) $response->getBody();
            return $this->demuxLogs($body);
        } catch (GuzzleException $e) {
            throw new AppException(502, '无法连接 Docker Manager API：' . $e->getMessage(), [], $e);
        } finally {
            foreach ($temporaryFiles as $file) {
                @unlink($file);
            }
        }
    }

    /**
     * 去掉 Docker 日志流的多路复用头部（8 字节帧头）。
     */
    private function demuxLogs(string $raw): string
    {
        $output = '';
        $offset = 0;
        $length = strlen($raw);
        while ($offset < $length) {
            if ($offset + 8 > $length) {
                $output .= substr($raw, $offset);
                break;
            }
            $header = substr($raw, $offset, 8);
            $frameSize = unpack('N', substr($header, 4, 4))[1];
            $offset += 8;
            if ($frameSize > 0 && $offset + $frameSize <= $length) {
                $output .= substr($raw, $offset, $frameSize);
                $offset += $frameSize;
            }
        }
        return $output;
    }

    /**
     * 获取 Service 的第一个 running 任务的容器日志。
     */
    public function serviceLogs(Cluster $cluster, string $serviceId, int $tail = 100): string
    {
        [$client, $temporaryFiles] = $this->createClient($cluster);
        try {
            $tasks = $this->request($client, '/tasks', [
                'query' => ['filters' => json_encode(['service' => [$serviceId]])],
            ]);
            // 找到第一个 running 状态且有容器 ID 的任务
            $containerId = '';
            foreach ($tasks as $task) {
                if (
                    ($task['Status']['State'] ?? '') === 'running'
                    && ! empty($task['Status']['ContainerStatus']['ContainerID'] ?? null)
                ) {
                    $containerId = $task['Status']['ContainerStatus']['ContainerID'];
                    break;
                }
            }
            if ($containerId === '') {
                throw new AppException(422, '该 Service 没有正在运行的任务');
            }
            return $this->containerLogs($cluster, $containerId, $tail);
        } catch (AppException $e) {
            throw $e;
        } catch (GuzzleException $e) {
            throw new AppException(502, '无法连接 Docker Manager API：' . $e->getMessage(), [], $e);
        } finally {
            foreach ($temporaryFiles as $file) {
                @unlink($file);
            }
        }
    }

    public function listServices(Cluster $cluster, ?array $serviceReferences = null): array
    {
        [$client, $temporaryFiles] = $this->createClient($cluster);
        try {
            $services = $this->request($client, '/services');
            if ($serviceReferences !== null) {
                $allowed = array_fill_keys($serviceReferences, true);
                $services = array_values(array_filter($services, static fn (array $service): bool =>
                    isset($allowed[(string) ($service['ID'] ?? '')])
                    || isset($allowed[(string) ($service['Spec']['Name'] ?? '')])
                ));
            }
            $tasks = $this->request($client, '/tasks');
            return $this->services($services, $tasks);
        } catch (GuzzleException $e) {
            throw new AppException(502, '无法连接 Docker Manager API：' . $e->getMessage(), [], $e);
        } finally {
            foreach ($temporaryFiles as $file) {
                @unlink($file);
            }
        }
    }

    private function inspectNetworks(array $networks): array
    {
        return array_map(static fn (string $name, array $net): array => [
            'name' => $name,
            'ip_address' => $net['IPAddress'] ?? '',
            'gateway' => $net['Gateway'] ?? '',
            'mac_address' => $net['MacAddress'] ?? '',
            'ipv6_address' => $net['GlobalIPv6Address'] ?? '',
        ], array_keys($networks), array_values($networks));
    }

    private function inspectMounts(array $mounts): array
    {
        return array_map(static fn (array $m): array => [
            'type' => $m['Type'] ?? '',
            'source' => $m['Source'] ?? '',
            'destination' => $m['Destination'] ?? '',
            'mode' => $m['Mode'] ?? '',
            'rw' => (bool) ($m['RW'] ?? true),
            'driver' => $m['Driver'] ?? '',
            'name' => $m['Name'] ?? '',
        ], $mounts);
    }

    public function listImages(Cluster $cluster, string $targetNodeId): array
    {
        [$client, $temporaryFiles] = $this->createClient($cluster);
        try {
            $node = $this->resourceNode($cluster, $targetNodeId, $client);
            if (! $node['agent_online']) {
                return [];
            }
            $images = $this->docker->withNode(
                $cluster,
                $targetNodeId,
                fn (Client $nodeClient): array => $this->request($nodeClient, '/images/json'),
                15
            );
            return array_map(static fn (array $image): array => [
                        'id' => $image['Id'] ?? '',
                        'repo_tags' => $image['RepoTags'] ?? [],
                        'repo_digests' => $image['RepoDigests'] ?? [],
                        'created' => (int) ($image['Created'] ?? 0),
                        'size' => (int) ($image['Size'] ?? 0),
                        'containers' => max(0, (int) ($image['Containers'] ?? 0)),
                        'labels' => $image['Labels'] ?? [],
                        'scope' => 'node',
                        'node_id' => $targetNodeId,
                        'node_hostname' => $node['hostname'],
                        'agent_online' => true,
                    ], $images);
        } catch (GuzzleException $e) {
            throw new AppException(502, '无法连接 Docker Manager API：' . $e->getMessage(), [], $e);
        } finally {
            foreach ($temporaryFiles as $file) {
                @unlink($file);
            }
        }
    }

    /**
     * 统计每个 Service 镜像在 Swarm 中的运行任务数（跨所有节点）。
     * @return array<string, int> image => running_task_count
     */
    private function serviceImageCounts(Client $client): array
    {
        try {
            $services = $this->request($client, '/services');
            $tasks = $this->request($client, '/tasks');
        } catch (\Throwable) {
            return [];
        }

        $runningByService = [];
        foreach ($tasks as $task) {
            $serviceId = $task['ServiceID'] ?? '';
            if ($serviceId !== '' && ($task['Status']['State'] ?? '') === 'running') {
                $runningByService[$serviceId] = ($runningByService[$serviceId] ?? 0) + 1;
            }
        }

        $counts = [];
        foreach ($services as $service) {
            $image = $service['Spec']['TaskTemplate']['ContainerSpec']['Image'] ?? '';
            if ($image === '') {
                continue;
            }
            // 剥离 digest 部分: nginx@sha256:xxx -> 统一用 image 本体匹配
            $counts[$image] = ($counts[$image] ?? 0) + ($runningByService[$service['ID'] ?? ''] ?? 0);
        }

        return $counts;
    }

    public function removeImage(Cluster $cluster, string $imageId): void
    {
        [$client, $temporaryFiles] = $this->createClient($cluster);
        try {
            $images = $this->request($client, '/images/json');
            foreach ($images as $image) {
                $tags = $image['RepoTags'] ?? [];
                $digests = $image['RepoDigests'] ?? [];
                $isTarget = in_array($imageId, $tags, true)
                    || in_array($imageId, $digests, true)
                    || ($image['Id'] ?? '') === $imageId;
                if ($isTarget) {
                    if (($image['Containers'] ?? 0) > 0) {
                        throw new AppException(422, '该镜像有关联容器，无法删除');
                    }
                    break;
                }
            }
            $client->delete('/images/' . rawurlencode($imageId), ['query' => ['force' => 1]]);
        } catch (AppException $e) {
            throw $e;
        } catch (GuzzleException $e) {
            throw new AppException(502, '无法连接 Docker Manager API：' . $e->getMessage(), [], $e);
        } finally {
            foreach ($temporaryFiles as $file) {
                @unlink($file);
            }
        }
    }

    /**
     * 拉取镜像到 Manager 节点。私有仓库会通过 资源->镜像仓库 中配置的用户名/密码登录后拉取。
     * @param int $registryId 0 表示按镜像地址自动匹配已配置的仓库；>0 表示显式指定仓库凭据
     */
    public function pullImage(Cluster $cluster, int $orgId, string $reference, int $registryId = 0): array
    {
        // 拉取可能耗时较长，使用较大的超时
        [$client, $temporaryFiles] = $this->docker->clientForCluster($cluster, 1800, true);
        try {
            [$name, $tag] = $this->splitImageReference($reference);
            $auth = $this->registryService->dockerAuthHeader($orgId, $reference, $registryId);
            $query = ['fromImage' => $name];
            if ($tag !== '' && $tag !== 'latest') {
                $query['tag'] = $tag;
            }
            // /images/create 返回换行分隔的 JSON 进度流，需自行解析错误
            $response = $client->request('POST', '/images/create', [
                'query' => $query,
                'headers' => $auth,
                'http_errors' => false,
            ]);
            $raw = (string) $response->getBody();
            if ($response->getStatusCode() >= 400) {
                throw new AppException(502, '拉取镜像失败：' . $this->scanPullError($raw, $response->getReasonPhrase()));
            }
            $this->assertNoPullError($raw);
            return [
                'image' => $name,
                'tag' => $tag,
                'reference' => ($tag !== '' && $tag !== 'latest') ? $name . ':' . $tag : $name,
            ];
        } catch (AppException $e) {
            throw $e;
        } catch (GuzzleException $e) {
            throw new AppException(502, '无法连接 Docker Manager API：' . $e->getMessage(), [], $e);
        } finally {
            foreach ($temporaryFiles as $file) {
                @unlink($file);
            }
        }
    }

    /**
     * 将镜像引用拆分为 [名称, 标签/摘要]，兼容 name:tag 与 name@sha256:... 两种写法。
     */
    private function splitImageReference(string $reference): array
    {
        $reference = trim($reference);
        if ($reference === '') {
            return ['', 'latest'];
        }
        if (str_contains($reference, '@')) {
            [$name, $tag] = explode('@', $reference, 2);
            return [$name, $tag === '' ? 'latest' : $tag];
        }
        $lastSlash = strrpos($reference, '/');
        $lastColon = strrpos($reference, ':');
        if ($lastColon !== false && ($lastSlash === false || $lastColon > $lastSlash)) {
            return [substr($reference, 0, $lastColon), substr($reference, $lastColon + 1)];
        }
        return [$reference, 'latest'];
    }

    /**
     * 解析 /images/create 返回的换行分隔 JSON 进度流，发现 error 字段则抛出异常。
     */
    private function assertNoPullError(string $raw): void
    {
        $error = $this->scanPullError($raw, '');
        if ($error !== '') {
            throw new AppException(502, '拉取镜像失败：' . $error);
        }
    }

    /**
     * 从 /images/create 的流式响应中提取首个错误信息；无错误且提供了 fallback 时返回 fallback。
     */
    private function scanPullError(string $raw, string $fallback): string
    {
        $lines = preg_split('/\r?\n/', $raw);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $obj = json_decode($line, true);
            if (is_array($obj) && isset($obj['error'])) {
                return is_string($obj['error']) ? $obj['error'] : (string) json_encode($obj['error'], JSON_UNESCAPED_SLASHES);
            }
        }
        return $fallback;
    }

    /**
     * 以 Swarm Service 形式运行一个镜像（对应 docker run 的语义）。
     * 在 Manager 节点上调用 Docker Daemon 的 /services/create 接口创建服务。
     */
    public function runImageAsService(Cluster $cluster, array $params): array
    {
        [$client, $temporaryFiles] = $this->createClient($cluster);
        try {
            $image = (string) ($params['image'] ?? '');
            $name = trim((string) ($params['name'] ?? ''));
            if ($name === '') {
                $name = $this->generateServiceName($image);
            }

            $containerSpec = ['Image' => $image];

            $env = $params['env'] ?? [];
            if (is_array($env) && count($env) > 0) {
                $envList = array_values(array_filter(array_map('strval', $env), static fn ($e) => $e !== ''));
                if (count($envList) > 0) {
                    $containerSpec['Env'] = $envList;
                }
            }

            $command = trim((string) ($params['command'] ?? ''));
            if ($command !== '') {
                $args = preg_split('/\s+/', $command, -1, PREG_SPLIT_NO_EMPTY);
                if (! empty($args)) {
                    $containerSpec['Args'] = $args;
                }
            }

            $mounts = [];
            foreach (($params['volumes'] ?? []) as $vol) {
                $vol = (array) $vol;
                $target = trim((string) ($vol['target'] ?? ''));
                $source = trim((string) ($vol['source'] ?? ''));
                if ($target === '' || $source === '') {
                    continue;
                }
                $type = in_array($vol['type'] ?? 'bind', ['bind', 'volume'], true) ? $vol['type'] : 'bind';
                $mounts[] = [
                    'Type' => $type,
                    'Source' => $source,
                    'Target' => $target,
                    'ReadOnly' => (($vol['mode'] ?? 'rw') === 'ro'),
                ];
            }
            if (count($mounts) > 0) {
                $containerSpec['Mounts'] = $mounts;
            }

            $taskTemplate = ['ContainerSpec' => $containerSpec];

            $restart = in_array($params['restart'] ?? 'any', ['none', 'any', 'on-failure'], true)
                ? ($params['restart'] ?? 'any')
                : 'any';
            $restartPolicy = ['Condition' => $restart];
            if ($restart === 'on-failure') {
                $maxAttempts = (int) ($params['restart_max_attempts'] ?? 0);
                if ($maxAttempts > 0) {
                    $restartPolicy['MaxAttempts'] = $maxAttempts;
                }
            }
            $taskTemplate['RestartPolicy'] = $restartPolicy;

            $resources = [];
            $cpu = isset($params['cpu']) ? (float) $params['cpu'] : 0;
            if ($cpu > 0) {
                $nano = (int) round($cpu * 1e9);
                $resources['Limits']['NanoCpus'] = $nano;
                $resources['Reservations']['NanoCpus'] = $nano;
            }
            $memory = isset($params['memory']) ? (int) $params['memory'] : 0;
            if ($memory > 0) {
                $resources['Limits']['MemoryBytes'] = $memory;
            }
            if (count($resources) > 0) {
                $taskTemplate['Resources'] = $resources;
            }

            $network = trim((string) ($params['network'] ?? ''));
            if ($network !== '') {
                $taskTemplate['Networks'] = [['Target' => $network]];
            }

            $spec = [
                'Name' => $name,
                'TaskTemplate' => $taskTemplate,
                'Mode' => ['Replicated' => ['Replicas' => max(1, (int) ($params['replicas'] ?? 1))]],
            ];

            $ports = [];
            foreach (($params['ports'] ?? []) as $port) {
                $port = (array) $port;
                $containerPort = (int) ($port['container_port'] ?? 0);
                if ($containerPort <= 0) {
                    continue;
                }
                $protocol = in_array(strtolower($port['protocol'] ?? 'tcp'), ['tcp', 'udp'], true)
                    ? strtolower($port['protocol'] ?? 'tcp')
                    : 'tcp';
                $entry = [
                    'Protocol' => $protocol,
                    'TargetPort' => $containerPort,
                ];
                $hostPort = (int) ($port['host_port'] ?? 0);
                if ($hostPort > 0) {
                    $entry['PublishedPort'] = $hostPort;
                }
                $ports[] = $entry;
            }
            if (count($ports) > 0) {
                $spec['EndpointSpec'] = ['Ports' => $ports];
            }

            $raw = $this->docker->request($client, 'POST', '/services/create', [
                'json' => $spec,
                'http_errors' => false,
            ]);

            return [
                'id' => $raw['ID'] ?? '',
                'name' => $name,
                'warnings' => $raw['Warnings'] ?? [],
            ];
        } catch (AppException $e) {
            throw $e;
        } catch (GuzzleException $e) {
            throw new AppException(502, '无法连接 Docker Manager API：' . $e->getMessage(), [], $e);
        } finally {
            foreach ($temporaryFiles as $file) {
                @unlink($file);
            }
        }
    }

    /**
     * 根据镜像引用生成合法的 Swarm Service 名称。
     */
    private function generateServiceName(string $image): string
    {
        $base = preg_replace('#^.*?/?([^:@]+)(?:[:@].*)?$#', '$1', $image);
        $base = preg_replace('/[^a-zA-Z0-9_.-]/', '', (string) $base);
        if ($base === '' || $base === null) {
            $base = 'service';
        }
        return 'svc-' . $base . '-' . substr(md5(uniqid('', true)), 0, 6);
    }

    public function listNetworks(Cluster $cluster): array
    {
        [$client, $temporaryFiles] = $this->createClient($cluster);
        try {
            // Galaxy 只管理 Swarm 控制面网络。bridge、host、none 以及用户
            // 创建的 local-scope 网络属于节点自身资源，不进入产品管理范围。
            $summaries = array_values(array_filter(
                $this->request($client, '/networks'),
                static fn (array $network): bool => ($network['Scope'] ?? '') === 'swarm'
            ));
            // 列表接口的 Containers 字段在 Swarm 模式下不可靠（不含 service 任务容器），
            // 逐个 inspect 网络以拿到准确的容器成员列表；并过滤 Swarm 任务容器与已退出容器。
            $swarmContainerIds = $this->swarmContainerIds($client);
            $containerStates = $this->containerStates($client);
            $parallel = new Parallel(8);
            foreach ($summaries as $summary) {
                $id = $summary['Id'] ?? '';
                if ($id === '') {
                    continue;
                }
                $parallel->add(function () use ($client, $id): array {
                    return $this->optionalRequest($client, '/networks/' . rawurlencode($id));
                }, $id);
            }
            $details = $parallel->count() > 0 ? $parallel->wait(false) : [];
            $networks = [];
            foreach ($summaries as $summary) {
                $id = $summary['Id'] ?? '';
                $detail = $details[$id] ?? $summary;
                // 用 inspect 结果补全 Containers，并排除 Swarm 任务容器与已退出容器
                $merged = $summary;
                if (! empty($detail['Containers'])) {
                    $merged['Containers'] = $this->filterNetworkContainers(
                        $detail['Containers'],
                        $swarmContainerIds,
                        $containerStates
                    );
                }
                $networks[] = $this->networks([$merged])[0];
            }
            return $networks;
        } catch (GuzzleException $e) {
            throw new AppException(502, '无法连接 Docker Manager API：' . $e->getMessage(), [], $e);
        } finally {
            foreach ($temporaryFiles as $file) {
                @unlink($file);
            }
        }
    }

    public function listVolumes(Cluster $cluster, string $targetNodeId): array
    {
        [$client, $temporaryFiles] = $this->createClient($cluster);
        try {
            $node = $this->resourceNode($cluster, $targetNodeId, $client);
            if (! $node['agent_online']) {
                return [];
            }
            $nodeData = $this->docker->withNode(
                $cluster,
                $targetNodeId,
                function (Client $nodeClient): array {
                    return [
                        'volumes' => $this->request($nodeClient, '/volumes'),
                        'containers' => $this->request($nodeClient, '/containers/json', ['query' => ['all' => 1]]),
                    ];
                },
                15
            );
            $volumes = $this->volumes(
                $nodeData['volumes']['Volumes'] ?? [],
                $this->volumeRefCounts([], $nodeData['containers'])
            );
            return array_map(static function (array $volume) use ($node, $targetNodeId): array {
                    $volume['scope'] = 'node';
                    $volume['node_id'] = $targetNodeId;
                    $volume['node_hostname'] = $node['hostname'];
                    $volume['agent_online'] = true;
                    return $volume;
                }, $volumes);
        } catch (GuzzleException $e) {
            throw new AppException(502, '无法连接 Docker Manager API：' . $e->getMessage(), [], $e);
        } finally {
            foreach ($temporaryFiles as $file) {
                @unlink($file);
            }
        }
    }

    public function createNetwork(Cluster $cluster, string $name): array
    {
        if (! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,62}$/', $name)) {
            throw new AppException(422, '网络名称只能包含字母、数字、下划线、点号和连字符，且必须以字母或数字开头');
        }
        [$client, $temporaryFiles] = $this->createClient($cluster);
        try {
            $response = $client->post('/networks/create', [
                'json' => ['Name' => $name, 'Driver' => 'overlay', 'Attachable' => true],
            ]);
            $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            if ($response->getStatusCode() >= 400) {
                $message = is_array($data) ? ($data['message'] ?? '') : '';
                if (stripos($message, 'already exists') !== false) {
                    throw new AppException(422, '网络 "' . $name . '" 已存在');
                }
                throw new AppException(502, 'Docker API 返回错误：' . ($message ?: 'unknown'));
            }
            return [
                'id' => $data['Id'] ?? '',
                'name' => $name,
                'attachable' => true,
            ];
        } catch (AppException $e) {
            throw $e;
        } catch (GuzzleException $e) {
            throw new AppException(502, '无法连接 Docker Manager API：' . $e->getMessage(), [], $e);
        } finally {
            foreach ($temporaryFiles as $file) {
                @unlink($file);
            }
        }
    }

    public function deleteNetwork(Cluster $cluster, string $id): void
    {
        [$client, $temporaryFiles] = $this->createClient($cluster);
        try {
            $detail = $this->optionalRequest($client, '/networks/' . rawurlencode($id));
            $this->assertManagedSwarmNetwork($detail);
            $name = $detail['Name'] ?? '';
            if (in_array($name, self::RESERVED_NETWORKS, true)) {
                throw new AppException(422, '系统网络 "' . $name . '" 为 Docker 内置网络，不允许删除');
            }
            if (! empty($detail['Containers'])) {
                $count = count($detail['Containers']);
                throw new AppException(
                    422,
                    '网络 "' . $name . '" 仍关联 ' . $count . ' 个容器，请先移除容器关联后再删除'
                );
            }
            $response = $client->delete('/networks/' . rawurlencode($id));
            if ($response->getStatusCode() >= 400) {
                $body = (string) $response->getBody();
                $data = $body ? json_decode($body, true, 512, JSON_THROW_ON_ERROR) : [];
                $message = is_array($data) ? ($data['message'] ?? '') : '';
                throw new AppException(502, 'Docker API 返回错误：' . ($message ?: 'unknown'));
            }
        } catch (AppException $e) {
            throw $e;
        } catch (GuzzleException $e) {
            throw new AppException(502, '无法连接 Docker Manager API：' . $e->getMessage(), [], $e);
        } finally {
            foreach ($temporaryFiles as $file) {
                @unlink($file);
            }
        }
    }

    /**
     * 获取网络关联的有效容器列表（排除 Swarm 任务容器与已退出容器），
     * 返回每个容器在该网络中的地址信息。
     */
    public function networkContainers(Cluster $cluster, string $id): array
    {
        [$client, $temporaryFiles] = $this->createClient($cluster);
        try {
            $detail = $this->request($client, '/networks/' . rawurlencode($id));
            $this->assertManagedSwarmNetwork($detail);
            $swarmContainerIds = $this->swarmContainerIds($client);
            $containerStates = $this->containerStates($client);
            $result = [];
            foreach ($detail['Containers'] ?? [] as $containerId => $info) {
                if (isset($swarmContainerIds[$containerId])) {
                    continue;
                }
                $state = $containerStates[$containerId] ?? '';
                if ($state !== '' && ! in_array($state, self::ACTIVE_CONTAINER_STATES, true)) {
                    continue;
                }
                $result[] = [
                    'id' => $containerId,
                    'name' => $info['Name'] ?? '',
                    'ipv4' => $info['IPv4Address'] ?? '',
                    'ipv6' => $info['IPv6Address'] ?? '',
                    'mac' => $info['MacAddress'] ?? '',
                    'endpoint_id' => $info['EndpointID'] ?? '',
                ];
            }
            return $result;
        } catch (GuzzleException $e) {
            throw new AppException(502, '无法连接 Docker Manager API：' . $e->getMessage(), [], $e);
        } finally {
            foreach ($temporaryFiles as $file) {
                @unlink($file);
            }
        }
    }

    /**
     * 将容器从网络断开（退出网络）。
     */
    public function disconnectNetworkContainer(Cluster $cluster, string $id, string $containerId, bool $force = false): void
    {
        [$client, $temporaryFiles] = $this->createClient($cluster);
        try {
            $detail = $this->request($client, '/networks/' . rawurlencode($id));
            $this->assertManagedSwarmNetwork($detail);
            $response = $client->post('/networks/' . rawurlencode($id) . '/disconnect', [
                'json' => ['Container' => $containerId, 'Force' => $force],
            ]);
            if ($response->getStatusCode() >= 400) {
                $body = (string) $response->getBody();
                $data = $body ? json_decode($body, true, 512, JSON_THROW_ON_ERROR) : [];
                $message = is_array($data) ? ($data['message'] ?? '') : '';
                if (stripos($message, 'swarm') !== false) {
                    throw new AppException(422, '该容器由 Swarm 服务管理，无法退出网络：' . $message);
                }
                throw new AppException(502, 'Docker API 返回错误：' . ($message ?: 'unknown'));
            }
        } catch (AppException $e) {
            throw $e;
        } catch (GuzzleException $e) {
            throw new AppException(502, '无法连接 Docker Manager API：' . $e->getMessage(), [], $e);
        } finally {
            foreach ($temporaryFiles as $file) {
                @unlink($file);
            }
        }
    }

    /**
     * 获取某个 Service 相关的事件（service / task / container 等），按时间倒序返回。
     */
    public function serviceEvents(Cluster $cluster, string $serviceId, ?int $since = null, ?int $until = null): array
    {
        [$client, $temporaryFiles] = $this->createClient($cluster);
        try {
            $now = time();
            $since = $since ?? ($now - 30 * 86400);
            $until = $until ?? $now;
            $response = $client->get('/events', [
                'query' => [
                    'since' => $since,
                    'until' => $until,
                    'filters' => json_encode(['service' => [$serviceId]]),
                ],
            ]);
            $events = [];
            foreach (explode("\n", trim((string) $response->getBody())) as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $event = json_decode($line, true);
                if (! is_array($event)) {
                    continue;
                }
                $attrService = $event['Actor']['Attributes']['service'] ?? '';
                // 二次过滤：仅保留确实归属该 Service 的事件（兼容 service 过滤器按名/ID 匹配的差异）
                if ($attrService === '' || ($attrService !== $serviceId && ! str_starts_with($attrService, $serviceId))) {
                    continue;
                }
                $events[] = $this->event($event);
            }
            usort($events, static fn (array $a, array $b): int => $b['time'] <=> $a['time']);
            return $events;
        } catch (GuzzleException $e) {
            throw new AppException(502, '无法连接 Docker Manager API：' . $e->getMessage(), [], $e);
        } finally {
            foreach ($temporaryFiles as $file) {
                @unlink($file);
            }
        }
    }

    private function event(array $event): array
    {
        $attributes = $event['Actor']['Attributes'] ?? [];
        return [
            'time' => (int) ($event['time'] ?? 0),
            'type' => $event['Type'] ?? '',
            'action' => $event['Action'] ?? '',
            'actor_id' => $event['Actor']['ID'] ?? '',
            'actor_name' => $attributes['name'] ?? '',
            'scope' => $event['scope'] ?? '',
            'attributes' => $attributes,
        ];
    }

    /**
     * 收集由 Swarm 服务任务创建的容器 ID 集合（用于判断某容器是否归服务管理）。
     */
    private function swarmContainerIds(Client $client): array
    {
        $tasks = $this->optionalRequest($client, '/tasks');
        $ids = [];
        foreach ($tasks as $task) {
            $containerId = $task['Status']['ContainerStatus']['ContainerID'] ?? '';
            if ($containerId !== '') {
                $ids[$containerId] = true;
            }
        }
        return $ids;
    }

    /**
     * 收集容器 ID => 状态 映射（用于排除已退出/已停止的容器）。
     */
    private function containerStates(Client $client): array
    {
        $containers = $this->optionalRequest($client, '/containers/json', ['query' => ['all' => 1]]);
        $map = [];
        foreach ($containers as $container) {
            $id = $container['Id'] ?? '';
            if ($id !== '') {
                $map[$id] = $container['State'] ?? '';
            }
        }
        return $map;
    }

    /**
     * 过滤网络关联容器：排除 Swarm 任务容器与处于非活跃（已退出/已停止）状态的容器。
     */
    private function filterNetworkContainers(array $containers, array $swarmIds, array $containerStates): array
    {
        $filtered = [];
        foreach ($containers as $containerId => $info) {
            if (isset($swarmIds[$containerId])) {
                continue;
            }
            $state = $containerStates[$containerId] ?? '';
            if ($state !== '' && ! in_array($state, self::ACTIVE_CONTAINER_STATES, true)) {
                continue;
            }
            $filtered[$containerId] = $info;
        }
        return $filtered;
    }

    private function assertManagedSwarmNetwork(array $network): void
    {
        if (($network['Scope'] ?? '') !== 'swarm') {
            throw new AppException(422, 'Galaxy 仅管理 Swarm 集群级网络，不管理节点本地网络');
        }
    }

    public function updateNetwork(Cluster $cluster, string $id, array $config): void
    {
        [$client, $temporaryFiles] = $this->createClient($cluster);
        try {
            $detail = $this->request($client, '/networks/' . rawurlencode($id));
            $this->assertManagedSwarmNetwork($detail);
            $payload = [];
            if (array_key_exists('attachable', $config)) {
                $payload['Attachable'] = (bool) $config['attachable'];
            }
            if (array_key_exists('internal', $config)) {
                $payload['Internal'] = (bool) $config['internal'];
            }
            if (array_key_exists('labels', $config)) {
                $payload['Labels'] = $config['labels'];
            }
            $response = $client->request('PUT', '/networks/' . rawurlencode($id) . '/update', [
                'json' => $payload,
            ]);
            if ($response->getStatusCode() >= 400) {
                $body = (string) $response->getBody();
                $data = $body ? json_decode($body, true, 512, JSON_THROW_ON_ERROR) : [];
                $message = is_array($data) ? ($data['message'] ?? '') : '';
                if (stripos($message, 'not supported') !== false) {
                    throw new AppException(422, '该网络不支持此修改：' . $message);
                }
                throw new AppException(502, 'Docker API 返回错误：' . ($message ?: 'unknown'));
            }
        } catch (AppException $e) {
            throw $e;
        } catch (GuzzleException $e) {
            throw new AppException(502, '无法连接 Docker Manager API：' . $e->getMessage(), [], $e);
        } finally {
            foreach ($temporaryFiles as $file) {
                @unlink($file);
            }
        }
    }

    public function createVolume(Cluster $cluster, string $name, string $driver = 'local', array $driverOpts = []): array
    {
        if (! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,63}$/', $name)) {
            throw new AppException(422, '卷名称只能包含字母、数字、下划线、点号和连字符');
        }
        [$client, $temporaryFiles] = $this->createClient($cluster);
        try {
            $json = ['Name' => $name, 'Driver' => $driver];
            if (! empty($driverOpts)) {
                $json['DriverOpts'] = array_map('strval', $driverOpts);
            }
            $response = $client->post('/volumes/create', ['json' => $json]);
            $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            if ($response->getStatusCode() >= 400) {
                $message = is_array($data) ? ($data['message'] ?? '') : '';
                if (stripos($message, 'already exists') !== false) {
                    throw new AppException(422, '卷 "' . $name . '" 已存在');
                }
                throw new AppException(502, 'Docker API 返回错误：' . ($message ?: 'unknown'));
            }
            return [
                'name' => $data['Name'] ?? $name,
                'driver' => $data['Driver'] ?? $driver,
                'driver_opts' => $driverOpts,
            ];
        } catch (AppException $e) {
            throw $e;
        } catch (GuzzleException $e) {
            throw new AppException(502, '无法连接 Docker Manager API：' . $e->getMessage(), [], $e);
        } finally {
            foreach ($temporaryFiles as $file) {
                @unlink($file);
            }
        }
    }

    public function removeVolume(Cluster $cluster, string $name, bool $force = false): void
    {
        [$client, $temporaryFiles] = $this->createClient($cluster);
        try {
            $client->delete('/volumes/' . rawurlencode($name), [
                'query' => ['force' => $force ? 'true' : 'false'],
            ]);
        } catch (GuzzleException $e) {
            throw new AppException(502, '无法连接 Docker Manager API：' . $e->getMessage(), [], $e);
        } finally {
            foreach ($temporaryFiles as $file) {
                @unlink($file);
            }
        }
    }

    public function overviewCore(Cluster $cluster): array
    {
        $startedAt = microtime(true);
        [$client, $temporaryFiles] = $this->createClient($cluster);
        try {
            $parallel = new Parallel(3);
            $parallel->add(fn (): array => $this->request($client, '/version'), 'version');
            $parallel->add(fn (): array => $this->request($client, '/info'), 'info');
            $parallel->add(fn (): array => $this->request($client, '/swarm'), 'swarm');
            $result = $parallel->wait();
            $version = $result['version'];
            $info = $result['info'];
            $swarm = $result['swarm'];
            if (($info['Swarm']['LocalNodeState'] ?? '') !== 'active' || empty($info['Swarm']['ControlAvailable'])) {
                throw new AppException(422, '目标 Docker 节点不是可管理的 Swarm Manager');
            }
            return [
                'generated_at' => time(),
                'connection' => [
                    'endpoint' => (string) $cluster['endpoint'],
                    'secure' => str_starts_with((string) $cluster['endpoint'], 'https://') || $this->isAgentEndpoint((string) $cluster['endpoint']),
                    'transport' => $this->transport((string) $cluster['endpoint']),
                    'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                    'api_version' => $version['ApiVersion'] ?? '',
                    'min_api_version' => $version['MinAPIVersion'] ?? '',
                ],
                'cluster' => [
                    'id' => $swarm['ID'] ?? '', 'name' => $swarm['Spec']['Name'] ?? '',
                    'created_at' => $swarm['CreatedAt'] ?? '', 'updated_at' => $swarm['UpdatedAt'] ?? '',
                    'local_node_id' => $info['Swarm']['NodeID'] ?? '',
                    'local_node_address' => $info['Swarm']['NodeAddr'] ?? '',
                    'local_node_state' => $info['Swarm']['LocalNodeState'] ?? '',
                    'control_available' => (bool) ($info['Swarm']['ControlAvailable'] ?? false),
                    'task_history_retention' => $swarm['Spec']['Orchestration']['TaskHistoryRetentionLimit'] ?? null,
                    'dispatcher_heartbeat_seconds' => isset($swarm['Spec']['Dispatcher']['HeartbeatPeriod']) ? $swarm['Spec']['Dispatcher']['HeartbeatPeriod'] / 1_000_000_000 : null,
                    'node_cert_expiry_days' => isset($swarm['Spec']['CAConfig']['NodeCertExpiry']) ? round($swarm['Spec']['CAConfig']['NodeCertExpiry'] / 1_000_000_000 / 86400, 2) : null,
                    'autolock_managers' => (bool) ($swarm['Spec']['EncryptionConfig']['AutoLockManagers'] ?? false),
                    'root_rotation_in_progress' => (bool) ($swarm['RootRotationInProgress'] ?? false),
                    'default_address_pool' => $swarm['DefaultAddrPool'] ?? [],
                    'subnet_size' => $swarm['SubnetSize'] ?? null,
                    'data_path_port' => $swarm['DataPathPort'] ?? 4789,
                ],
                'counts' => [
                    'containers_total' => (int) ($info['Containers'] ?? 0),
                    'containers_running' => (int) ($info['ContainersRunning'] ?? 0),
                    'containers_paused' => (int) ($info['ContainersPaused'] ?? 0),
                    'containers_stopped' => (int) ($info['ContainersStopped'] ?? 0),
                    'images' => (int) ($info['Images'] ?? 0),
                ],
                'engine' => [
                    'name' => $info['Name'] ?? '', 'version' => $version['Version'] ?? ($info['ServerVersion'] ?? ''),
                    'go_version' => $version['GoVersion'] ?? '', 'git_commit' => $version['GitCommit'] ?? '',
                    'build_time' => $version['BuildTime'] ?? '', 'kernel_version' => $info['KernelVersion'] ?? '',
                    'operating_system' => $info['OperatingSystem'] ?? '', 'os_version' => $info['OSVersion'] ?? '',
                    'architecture' => $info['Architecture'] ?? '', 'storage_driver' => $info['Driver'] ?? '',
                    'logging_driver' => $info['LoggingDriver'] ?? '', 'cgroup_driver' => $info['CgroupDriver'] ?? '',
                    'cgroup_version' => $info['CgroupVersion'] ?? '', 'docker_root_dir' => $info['DockerRootDir'] ?? '',
                    'default_runtime' => $info['DefaultRuntime'] ?? '', 'live_restore_enabled' => (bool) ($info['LiveRestoreEnabled'] ?? false),
                    'experimental' => (bool) ($info['ExperimentalBuild'] ?? false), 'ipv4_forwarding' => (bool) ($info['IPv4Forwarding'] ?? false),
                    'memory_limit' => (bool) ($info['MemoryLimit'] ?? false), 'swap_limit' => (bool) ($info['SwapLimit'] ?? false),
                    'security_options' => $info['SecurityOptions'] ?? [], 'registry_mirrors' => $info['RegistryConfig']['Mirrors'] ?? [],
                    'warnings' => $info['Warnings'] ?? [],
                ],
            ];
        } finally {
            foreach ($temporaryFiles as $file) { @unlink($file); }
        }
    }

    public function overviewTopology(Cluster $cluster): array
    {
        [$client, $temporaryFiles] = $this->createClient($cluster);
        try {
            $parallel = new Parallel(5);
            $parallel->add(fn (): array => $this->request($client, '/nodes'), 'nodes');
            $parallel->add(fn (): array => $this->request($client, '/services'), 'services');
            $parallel->add(fn (): array => $this->request($client, '/tasks'), 'tasks');
            $parallel->add(fn (): array => $this->optionalRequest($client, '/configs'), 'configs');
            $parallel->add(fn (): array => $this->optionalRequest($client, '/secrets'), 'secrets');
            $result = $parallel->wait();
            $nodes = $this->nodes($result['nodes']);
            $tasks = $result['tasks'];
            $taskSummary = $this->tasks($tasks);
            $services = $this->services($result['services'], $tasks);
            $managers = array_filter($nodes, static fn (array $node) => $node['role'] === 'manager');
            $reachable = array_filter($managers, static fn (array $node) => $node['reachability'] === 'reachable');
            $quorum = intdiv(count($managers), 2) + 1;
            $cpuCapacity = array_sum(array_column($nodes, 'cpu_cores'));
            $memoryCapacity = array_sum(array_column($nodes, 'memory_bytes'));
            $cpuReserved = array_sum(array_column($services, 'cpu_reserved_total'));
            $memoryReserved = array_sum(array_column($services, 'memory_reserved_total'));
            return [
                'generated_at' => time(),
                'cluster' => [
                    'nodes' => count($nodes), 'managers' => count($managers),
                    'workers' => count(array_filter($nodes, static fn (array $node) => $node['role'] === 'worker')),
                    'managers_reachable' => count($reachable), 'manager_quorum_required' => $quorum,
                    'manager_quorum_healthy' => count($reachable) >= $quorum,
                ],
                'resources' => [
                    'cpu_capacity' => $cpuCapacity, 'cpu_reserved' => $cpuReserved,
                    'cpu_reserved_percentage' => $this->percentage($cpuReserved, $cpuCapacity),
                    'memory_capacity' => $memoryCapacity, 'memory_reserved' => $memoryReserved,
                    'memory_reserved_percentage' => $this->percentage($memoryReserved, $memoryCapacity),
                ],
                'counts' => [
                    'nodes_total' => count($nodes),
                    'nodes_ready' => count(array_filter($nodes, static fn (array $node) => $node['state'] === 'ready')),
                    'nodes_unavailable' => count(array_filter($nodes, static fn (array $node) => $node['state'] !== 'ready' || $node['availability'] !== 'active')),
                    'services_total' => count($services),
                    'services_healthy' => count(array_filter($services, static fn (array $service) => $service['desired_tasks'] === $service['running_tasks'])),
                    'services_degraded' => count(array_filter($services, static fn (array $service) => $service['desired_tasks'] !== $service['running_tasks'])),
                    'tasks_total' => count($tasks), 'tasks_running' => $taskSummary['running'] ?? 0,
                    'tasks_failed' => ($taskSummary['failed'] ?? 0) + ($taskSummary['rejected'] ?? 0) + ($taskSummary['orphaned'] ?? 0),
                    'configs' => count($result['configs'] ?? []),
                    'secrets' => count($result['secrets'] ?? []),
                ],
                'task_states' => $taskSummary, 'nodes' => $nodes, 'services' => $services,
            ];
        } finally {
            foreach ($temporaryFiles as $file) { @unlink($file); }
        }
    }

    public function overviewRuntime(Cluster $cluster): array
    {
        [$client, $temporaryFiles] = $this->createClient($cluster);
        try {
            $parallel = new Parallel(4);
            $parallel->add(fn (): array => $this->optionalRequest($client, '/containers/json', ['query' => ['all' => 1]]), 'containers');
            $parallel->add(fn (): array => $this->optionalRequest($client, '/networks'), 'networks');
            $parallel->add(fn (): array => $this->optionalRequest($client, '/volumes'), 'volumes');
            $parallel->add(fn (): array => $this->optionalRequest($client, '/system/df'), 'disk_usage');
            $result = $parallel->wait();
            $containers = $this->containers($client, $result['containers']);
            $networks = $this->networks($result['networks']);
            $volumes = $this->volumes($result['volumes']['Volumes'] ?? []);
            return [
                'generated_at' => time(),
                'resources' => [
                    'manager_container_cpu_cores' => round(array_sum(array_column($containers, 'cpu_percent')) / 100, 3),
                    'manager_container_memory' => array_sum(array_column($containers, 'memory_usage')),
                ],
                'counts' => ['networks' => count($networks), 'volumes' => count($volumes)],
                'storage' => $this->storage($result['disk_usage']),
                'containers' => $containers, 'networks' => $networks, 'volumes' => $volumes,
            ];
        } finally {
            foreach ($temporaryFiles as $file) { @unlink($file); }
        }
    }

    public function overview(Cluster $cluster): array
    {
        $startedAt = microtime(true);
        [$client, $temporaryFiles] = $this->createClient($cluster);

        try {
            try {
                $version = $this->request($client, '/version');
                $info = $this->request($client, '/info');
                if (($info['Swarm']['LocalNodeState'] ?? '') !== 'active' || empty($info['Swarm']['ControlAvailable'])) {
                    throw new AppException(422, '目标 Docker 节点不是可管理的 Swarm Manager');
                }

                $parallel = new Parallel(8);
                $parallel->add(fn (): array => $this->request($client, '/swarm'), 'swarm');
                $parallel->add(fn (): array => $this->request($client, '/nodes'), 'nodes');
                $parallel->add(fn (): array => $this->request($client, '/services'), 'services');
                $parallel->add(fn (): array => $this->request($client, '/tasks'), 'tasks');
                $parallel->add(fn (): array => $this->optionalRequest($client, '/containers/json', ['query' => ['all' => 1]]), 'containers');
                $parallel->add(fn (): array => $this->optionalRequest($client, '/networks'), 'networks');
                $parallel->add(fn (): array => $this->optionalRequest($client, '/volumes'), 'volumes');
                $parallel->add(fn (): array => $this->optionalRequest($client, '/system/df'), 'disk_usage');
                $results = $parallel->wait();
                $swarm = $results['swarm'];
                $nodes = $results['nodes'];
                $services = $results['services'];
                $tasks = $results['tasks'];
                $containers = $results['containers'];
                $networks = $results['networks'];
                $volumesResponse = $results['volumes'];
                $diskUsage = $results['disk_usage'];
            } catch (AppException $e) {
                throw $e;
            } catch (GuzzleException $e) {
                throw new AppException(502, '无法连接 Docker Manager API：' . $e->getMessage(), [], $e);
            } catch (\Throwable $e) {
                throw new AppException(502, '读取 Docker Swarm 信息失败：' . $e->getMessage(), [], $e);
            }

            $nodeRows = $this->nodes($nodes);
            $taskSummary = $this->tasks($tasks);
            $serviceRows = $this->services($services, $tasks);
            $containerRows = $this->containers($client, $containers);
            $volumeRows = $this->volumes($volumesResponse['Volumes'] ?? [], $this->volumeRefCounts($services, $containers));
            $networkRows = $this->networks($networks);
            $managerNodes = array_filter($nodeRows, static fn (array $node) => $node['role'] === 'manager');
            $reachableManagers = array_filter($managerNodes, static fn (array $node) => $node['reachability'] === 'reachable');
            $managerQuorumRequired = intdiv(count($managerNodes), 2) + 1;

            $cpuCapacity = array_sum(array_column($nodeRows, 'cpu_cores'));
            $memoryCapacity = array_sum(array_column($nodeRows, 'memory_bytes'));
            $cpuReserved = array_sum(array_column($serviceRows, 'cpu_reserved_total'));
            $memoryReserved = array_sum(array_column($serviceRows, 'memory_reserved_total'));

            return [
                'generated_at' => time(),
                'connection' => [
                    'endpoint' => (string) $cluster['endpoint'],
                    'secure' => str_starts_with((string) $cluster['endpoint'], 'https://')
                        || $this->isAgentEndpoint((string) $cluster['endpoint']),
                    'transport' => $this->transport((string) $cluster['endpoint']),
                    'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                    'api_version' => $version['ApiVersion'] ?? '',
                    'min_api_version' => $version['MinAPIVersion'] ?? '',
                ],
                'cluster' => [
                    'id' => $swarm['ID'] ?? '',
                    'name' => $swarm['Spec']['Name'] ?? '',
                    'created_at' => $swarm['CreatedAt'] ?? '',
                    'updated_at' => $swarm['UpdatedAt'] ?? '',
                    'local_node_id' => $info['Swarm']['NodeID'] ?? '',
                    'local_node_address' => $info['Swarm']['NodeAddr'] ?? '',
                    'local_node_state' => $info['Swarm']['LocalNodeState'] ?? '',
                    'control_available' => (bool) ($info['Swarm']['ControlAvailable'] ?? false),
                    'nodes' => count($nodeRows),
                    'managers' => count($managerNodes),
                    'workers' => count(array_filter($nodeRows, static fn (array $node) => $node['role'] === 'worker')),
                    'managers_reachable' => count($reachableManagers),
                    'manager_quorum_required' => $managerQuorumRequired,
                    'manager_quorum_healthy' => count($reachableManagers) >= $managerQuorumRequired,
                    'task_history_retention' => $swarm['Spec']['Orchestration']['TaskHistoryRetentionLimit'] ?? null,
                    'dispatcher_heartbeat_seconds' => isset($swarm['Spec']['Dispatcher']['HeartbeatPeriod'])
                        ? $swarm['Spec']['Dispatcher']['HeartbeatPeriod'] / 1_000_000_000
                        : null,
                    'node_cert_expiry_days' => isset($swarm['Spec']['CAConfig']['NodeCertExpiry'])
                        ? round($swarm['Spec']['CAConfig']['NodeCertExpiry'] / 1_000_000_000 / 86400, 2)
                        : null,
                    'autolock_managers' => (bool) ($swarm['Spec']['EncryptionConfig']['AutoLockManagers'] ?? false),
                    'root_rotation_in_progress' => (bool) ($swarm['RootRotationInProgress'] ?? false),
                    'default_address_pool' => $swarm['DefaultAddrPool'] ?? [],
                    'subnet_size' => $swarm['SubnetSize'] ?? null,
                    'data_path_port' => $swarm['DataPathPort'] ?? 4789,
                ],
                'resources' => [
                    'cpu_capacity' => $cpuCapacity,
                    'cpu_reserved' => $cpuReserved,
                    'cpu_reserved_percentage' => $this->percentage($cpuReserved, $cpuCapacity),
                    'memory_capacity' => $memoryCapacity,
                    'memory_reserved' => $memoryReserved,
                    'memory_reserved_percentage' => $this->percentage($memoryReserved, $memoryCapacity),
                    'manager_container_cpu_cores' => round(array_sum(array_column($containerRows, 'cpu_percent')) / 100, 3),
                    'manager_container_memory' => array_sum(array_column($containerRows, 'memory_usage')),
                ],
                'counts' => [
                    'nodes_total' => count($nodeRows),
                    'nodes_ready' => count(array_filter($nodeRows, static fn (array $node) => $node['state'] === 'ready')),
                    'nodes_unavailable' => count(array_filter($nodeRows, static fn (array $node) => $node['state'] !== 'ready' || $node['availability'] !== 'active')),
                    'services_total' => count($serviceRows),
                    'services_healthy' => count(array_filter($serviceRows, static fn (array $service) => $service['desired_tasks'] === $service['running_tasks'])),
                    'services_degraded' => count(array_filter($serviceRows, static fn (array $service) => $service['desired_tasks'] !== $service['running_tasks'])),
                    'tasks_total' => count($tasks),
                    'tasks_running' => $taskSummary['running'] ?? 0,
                    'tasks_failed' => ($taskSummary['failed'] ?? 0) + ($taskSummary['rejected'] ?? 0) + ($taskSummary['orphaned'] ?? 0),
                    'containers_total' => (int) ($info['Containers'] ?? count($containerRows)),
                    'containers_running' => (int) ($info['ContainersRunning'] ?? 0),
                    'containers_paused' => (int) ($info['ContainersPaused'] ?? 0),
                    'containers_stopped' => (int) ($info['ContainersStopped'] ?? 0),
                    'images' => (int) ($info['Images'] ?? 0),
                    'networks' => count($networkRows),
                    'volumes' => count($volumeRows),
                ],
                'engine' => [
                    'name' => $info['Name'] ?? '',
                    'version' => $version['Version'] ?? ($info['ServerVersion'] ?? ''),
                    'go_version' => $version['GoVersion'] ?? '',
                    'git_commit' => $version['GitCommit'] ?? '',
                    'build_time' => $version['BuildTime'] ?? '',
                    'kernel_version' => $info['KernelVersion'] ?? '',
                    'operating_system' => $info['OperatingSystem'] ?? '',
                    'os_version' => $info['OSVersion'] ?? '',
                    'architecture' => $info['Architecture'] ?? '',
                    'storage_driver' => $info['Driver'] ?? '',
                    'logging_driver' => $info['LoggingDriver'] ?? '',
                    'cgroup_driver' => $info['CgroupDriver'] ?? '',
                    'cgroup_version' => $info['CgroupVersion'] ?? '',
                    'docker_root_dir' => $info['DockerRootDir'] ?? '',
                    'default_runtime' => $info['DefaultRuntime'] ?? '',
                    'live_restore_enabled' => (bool) ($info['LiveRestoreEnabled'] ?? false),
                    'experimental' => (bool) ($info['ExperimentalBuild'] ?? false),
                    'ipv4_forwarding' => (bool) ($info['IPv4Forwarding'] ?? false),
                    'memory_limit' => (bool) ($info['MemoryLimit'] ?? false),
                    'swap_limit' => (bool) ($info['SwapLimit'] ?? false),
                    'security_options' => $info['SecurityOptions'] ?? [],
                    'registry_mirrors' => $info['RegistryConfig']['Mirrors'] ?? [],
                    'warnings' => $info['Warnings'] ?? [],
                ],
                'storage' => $this->storage($diskUsage),
                'task_states' => $taskSummary,
                'nodes' => $nodeRows,
                'services' => $serviceRows,
                'containers' => $containerRows,
                'networks' => $networkRows,
                'volumes' => $volumeRows,
            ];
        } finally {
            foreach ($temporaryFiles as $file) {
                @unlink($file);
            }
        }
    }

    private function request(Client $client, string $path, array $options = []): array
    {
        $response = $client->get($path, $options);
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        return is_array($data) ? $data : [];
    }

    private function optionalRequest(Client $client, string $path, array $options = []): array
    {
        try {
            return $this->request($client, $path, $options);
        } catch (\Throwable) {
            return [];
        }
    }

    private function nodes(array $nodes): array
    {
        return array_map(static function (array $node): array {
            return [
                'id' => $node['ID'] ?? '',
                'hostname' => $node['Description']['Hostname'] ?? '',
                'role' => $node['Spec']['Role'] ?? '',
                'availability' => $node['Spec']['Availability'] ?? '',
                'state' => $node['Status']['State'] ?? '',
                'address' => $node['Status']['Addr'] ?? '',
                'manager_address' => $node['ManagerStatus']['Addr'] ?? '',
                'reachability' => $node['ManagerStatus']['Reachability'] ?? '',
                'leader' => (bool) ($node['ManagerStatus']['Leader'] ?? false),
                'cpu_cores' => ($node['Description']['Resources']['NanoCPUs'] ?? 0) / 1_000_000_000,
                'memory_bytes' => (int) ($node['Description']['Resources']['MemoryBytes'] ?? 0),
                'engine_version' => $node['Description']['Engine']['EngineVersion'] ?? '',
                'os' => $node['Description']['Platform']['OS'] ?? '',
                'architecture' => $node['Description']['Platform']['Architecture'] ?? '',
                'labels' => $node['Spec']['Labels'] ?? [],
                'created_at' => $node['CreatedAt'] ?? '',
                'updated_at' => $node['UpdatedAt'] ?? '',
            ];
        }, $nodes);
    }

    private function services(array $services, array $tasks): array
    {
        $tasksByService = [];
        foreach ($tasks as $task) {
            $serviceId = $task['ServiceID'] ?? '';
            if ($serviceId !== '') {
                $tasksByService[$serviceId][] = $task;
            }
        }

        return array_map(function (array $service) use ($tasksByService): array {
            $serviceTasks = $tasksByService[$service['ID'] ?? ''] ?? [];
            $running = count(array_filter($serviceTasks, static fn (array $task) => ($task['Status']['State'] ?? '') === 'running'));
            $desired = count(array_filter($serviceTasks, static fn (array $task) => ($task['DesiredState'] ?? '') === 'running'));
            $replicas = $service['Spec']['Mode']['Replicated']['Replicas'] ?? null;
            if ($replicas !== null) {
                $desired = (int) $replicas;
            }
            $reservations = $service['Spec']['TaskTemplate']['Resources']['Reservations'] ?? [];
            $limits = $service['Spec']['TaskTemplate']['Resources']['Limits'] ?? [];
            $ports = array_map(static function (array $port): string {
                return sprintf(
                    '%s:%s/%s (%s)',
                    $port['PublishedPort'] ?? '-',
                    $port['TargetPort'] ?? '-',
                    $port['Protocol'] ?? 'tcp',
                    $port['PublishMode'] ?? 'ingress'
                );
            }, $service['Endpoint']['Ports'] ?? []);

            return [
                'id' => $service['ID'] ?? '',
                'name' => $service['Spec']['Name'] ?? '',
                'labels' => $service['Spec']['Labels'] ?? [],
                'image' => $service['Spec']['TaskTemplate']['ContainerSpec']['Image'] ?? '',
                'mode' => isset($service['Spec']['Mode']['Global']) ? 'global' : 'replicated',
                'desired_tasks' => $desired,
                'running_tasks' => $running,
                'failed_tasks' => count(array_filter($serviceTasks, static fn (array $task) => in_array($task['Status']['State'] ?? '', ['failed', 'rejected', 'orphaned'], true))),
                'cpu_reserved' => ($reservations['NanoCPUs'] ?? 0) / 1_000_000_000,
                'cpu_limit' => ($limits['NanoCPUs'] ?? 0) / 1_000_000_000,
                'memory_reserved' => (int) ($reservations['MemoryBytes'] ?? 0),
                'memory_limit' => (int) ($limits['MemoryBytes'] ?? 0),
                'cpu_reserved_total' => (($reservations['NanoCPUs'] ?? 0) / 1_000_000_000) * $desired,
                'memory_reserved_total' => (int) ($reservations['MemoryBytes'] ?? 0) * $desired,
                'ports' => $ports,
                'networks' => array_column($service['Spec']['TaskTemplate']['Networks'] ?? [], 'Target'),
                'update_state' => $service['UpdateStatus']['State'] ?? '',
                'update_message' => $service['UpdateStatus']['Message'] ?? '',
                'created_at' => $service['CreatedAt'] ?? '',
                'updated_at' => $service['UpdatedAt'] ?? '',
            ];
        }, $services);
    }

    private function tasks(array $tasks): array
    {
        $states = [];
        foreach ($tasks as $task) {
            $state = $task['Status']['State'] ?? 'unknown';
            $states[$state] = ($states[$state] ?? 0) + 1;
        }
        ksort($states);
        return $states;
    }

    private function containers(Client $client, array $containers): array
    {
        $statsByContainer = [];
        $parallel = new Parallel(8);
        foreach ($containers as $container) {
            if (($container['State'] ?? '') === 'running' && ! empty($container['Id'])) {
                $containerId = (string) $container['Id'];
                $parallel->add(fn (): array => $this->optionalRequest(
                    $client,
                    '/containers/' . rawurlencode($containerId) . '/stats',
                    ['query' => ['stream' => 'false', 'one-shot' => 'true']]
                ), $containerId);
            }
        }
        if ($parallel->count() > 0) {
            $statsByContainer = $parallel->wait(false);
        }

        return array_map(function (array $container) use ($statsByContainer): array {
            $stats = $statsByContainer[$container['Id'] ?? ''] ?? [];
            $labels = (array) ($container['Labels'] ?? []);
            $cpuPercent = $this->containerCpuPercentage($stats);
            $memoryUsage = max(0, (int) ($stats['memory_stats']['usage'] ?? 0) - (int) ($stats['memory_stats']['stats']['inactive_file'] ?? 0));
            $networkSettings = $container['NetworkSettings']['Networks'] ?? [];
            $networks = array_keys($networkSettings);
            $ipAddresses = [];
            foreach ($networkSettings as $networkName => $networkDetail) {
                $ipv4 = $networkDetail['IPAddress'] ?? '';
                if ($ipv4 !== '') {
                    $ipAddresses[$networkName] = $ipv4;
                }
            }
            $ports = array_map(static function (array $port): string {
                return isset($port['PublicPort'])
                    ? sprintf('%s:%s->%s/%s', $port['IP'] ?? '', $port['PublicPort'], $port['PrivatePort'] ?? '', $port['Type'] ?? 'tcp')
                    : sprintf('%s/%s', $port['PrivatePort'] ?? '', $port['Type'] ?? 'tcp');
            }, $container['Ports'] ?? []);

            return [
                'id' => $container['Id'] ?? '',
                'name' => ltrim($container['Names'][0] ?? '', '/'),
                'image' => $container['Image'] ?? '',
                'labels' => $labels,
                'service_id' => (string) ($labels['com.docker.swarm.service.id'] ?? ''),
                'service_name' => (string) ($labels['com.docker.swarm.service.name'] ?? ''),
                'task_id' => (string) ($labels['com.docker.swarm.task.id'] ?? ''),
                'node_id' => (string) ($labels['com.docker.swarm.node.id'] ?? ''),
                'state' => $container['State'] ?? '',
                'status' => $container['Status'] ?? '',
                'health' => $container['Health']['Status'] ?? 'none',
                'created_at' => (int) ($container['Created'] ?? 0),
                'cpu_percent' => $cpuPercent,
                'memory_usage' => $memoryUsage,
                'memory_limit' => (int) ($stats['memory_stats']['limit'] ?? 0),
                'pids' => (int) ($stats['pids_stats']['current'] ?? 0),
                'network_rx' => array_sum(array_column($stats['networks'] ?? [], 'rx_bytes')),
                'network_tx' => array_sum(array_column($stats['networks'] ?? [], 'tx_bytes')),
                'ports' => $ports,
                'networks' => $networks,
                'ip_addresses' => $ipAddresses,
                'mounts' => count($container['Mounts'] ?? []),
            ];
        }, $containers);
    }

    private function containerCpuPercentage(array $stats): float
    {
        $cpuDelta = (int) ($stats['cpu_stats']['cpu_usage']['total_usage'] ?? 0)
            - (int) ($stats['precpu_stats']['cpu_usage']['total_usage'] ?? 0);
        $systemDelta = (int) ($stats['cpu_stats']['system_cpu_usage'] ?? 0)
            - (int) ($stats['precpu_stats']['system_cpu_usage'] ?? 0);
        $onlineCpus = (int) ($stats['cpu_stats']['online_cpus'] ?? count($stats['cpu_stats']['cpu_usage']['percpu_usage'] ?? []));
        if ($cpuDelta <= 0 || $systemDelta <= 0 || $onlineCpus <= 0) {
            return 0;
        }
        return round($cpuDelta / $systemDelta * $onlineCpus * 100, 2);
    }

    private function networks(array $networks): array
    {
        return array_map(static fn (array $network): array => [
            'id' => $network['Id'] ?? '',
            'name' => $network['Name'] ?? '',
            'driver' => $network['Driver'] ?? '',
            'scope' => $network['Scope'] ?? '',
            'internal' => (bool) ($network['Internal'] ?? false),
            'attachable' => (bool) ($network['Attachable'] ?? false),
            'ingress' => (bool) ($network['Ingress'] ?? false),
            'subnets' => array_column($network['IPAM']['Config'] ?? [], 'Subnet'),
            'labels' => $network['Labels'] ?? [],
            'containers' => count($network['Containers'] ?? []),
            'created_at' => $network['Created'] ?? '',
        ], $networks);
    }

    private function volumes(array $volumes, array $refCounts = []): array
    {
        return array_map(static fn (array $volume): array => [
            'name' => $volume['Name'] ?? '',
            'driver' => $volume['Driver'] ?? '',
            'scope' => $volume['Scope'] ?? '',
            'ref_count' => max(
                (int) ($volume['UsageData']['RefCount'] ?? 0),
                $refCounts[$volume['Name'] ?? ''] ?? 0
            ),
            'created_at' => $volume['CreatedAt'] ?? '',
            'labels' => $volume['Labels'] ?? [],
            'driver_opts' => $volume['Options'] ?? [],
        ], $volumes);
    }

    private function volumeRefCounts(array $services, array $containers = []): array
    {
        $counts = [];
        foreach ($services as $service) {
            $mounts = $service['Spec']['TaskTemplate']['ContainerSpec']['Mounts'] ?? [];
            foreach ($mounts as $mount) {
                if (($mount['Type'] ?? '') === 'volume') {
                    $name = $mount['Source'] ?? '';
                    if ($name !== '') {
                        $counts[$name] = ($counts[$name] ?? 0) + 1;
                    }
                }
            }
        }
        foreach ($containers as $container) {
            $mounts = $container['Mounts'] ?? [];
            foreach ($mounts as $mount) {
                if (($mount['Type'] ?? '') === 'volume') {
                    $name = $mount['Name'] ?? '';
                    if ($name !== '') {
                        $counts[$name] = ($counts[$name] ?? 0) + 1;
                    }
                }
            }
        }
        return $counts;
    }

    private function storage(array $diskUsage): array
    {
        $volumeBytes = 0;
        foreach ($diskUsage['Volumes'] ?? [] as $volume) {
            $size = (int) ($volume['UsageData']['Size'] ?? 0);
            if ($size > 0) {
                $volumeBytes += $size;
            }
        }
        return [
            'layers_bytes' => (int) ($diskUsage['LayersSize'] ?? 0),
            'containers_writable_bytes' => array_sum(array_column($diskUsage['Containers'] ?? [], 'SizeRw')),
            'volumes_bytes' => $volumeBytes,
            'build_cache_bytes' => array_sum(array_column($diskUsage['BuildCache'] ?? [], 'Size')),
        ];
    }

    public function inspectService(Cluster $cluster, string $serviceId): array
    {
        [$client, $temporaryFiles] = $this->createClient($cluster);
        try {
            $raw = $this->request($client, '/services/' . rawurlencode($serviceId));
            $result = $this->transformServiceInspect($raw);
            $networks = $this->request($client, '/networks');
            $networkNames = [];
            foreach ($networks as $network) {
                $networkNames[(string) ($network['Id'] ?? '')] = (string) ($network['Name'] ?? '');
            }
            foreach ($result['networks'] as &$network) {
                $network['name'] = $networkNames[(string) $network['target']] ?? '';
            }
            unset($network);
            $services = $this->request($client, '/services');
            $occupied = [];
            foreach ($services as $service) {
                foreach ((array) ($service['Endpoint']['Ports'] ?? []) as $port) {
                    $published = (int) ($port['PublishedPort'] ?? 0);
                    if ($published > 0) $occupied[$published] = true;
                }
            }
            $result['suggested_published_port'] = 0;
            for ($attempt = 0; $attempt < 200; $attempt++) {
                $candidate = random_int(1024, 9999);
                if (! isset($occupied[$candidate])) { $result['suggested_published_port'] = $candidate; break; }
            }
            return $result;
        } catch (GuzzleException $e) {
            throw new AppException(502, '无法连接 Docker Manager API：' . $e->getMessage(), [], $e);
        } finally {
            foreach ($temporaryFiles as $file) {
                @unlink($file);
            }
        }
    }

    public function claimService(Cluster $cluster, string $serviceId, array $labels): array
    {
        return $this->docker->withCluster($cluster, function (Client $client, SwarmApiClient $api) use ($serviceId, $labels): array {
            $current = $api->request($client, 'GET', '/services/' . rawurlencode($serviceId));
            $existing = (array) ($current['Spec']['Labels'] ?? []);
            $existingProjectId = (int) (
                $existing['com.codegalaxy.project.id'] ?? $existing['com.codegalaxy.app.id'] ?? 0
            );
            if ($existingProjectId > 0
                && ((int) ($existing['com.codegalaxy.org.id'] ?? 0) !== (int) ($labels['com.codegalaxy.org.id'] ?? 0)
                    || $existingProjectId !== (int) ($labels['com.codegalaxy.project.id'] ?? 0))) {
                throw new AppException(409, '该 Swarm Service 已由其他 Galaxy 项目管理');
            }
            $spec = (array) $current['Spec'];
            $spec['Labels'] = array_merge($existing, $labels);
            unset($spec['Labels']['com.codegalaxy.managed'], $spec['Labels']['com.codegalaxy.app.id']);
            $api->request($client, 'POST', '/services/' . rawurlencode($serviceId) . '/update', [
                'query' => ['version' => (int) ($current['Version']['Index'] ?? 0)],
                'json' => $spec,
            ]);
            return $this->transformServiceInspect(
                $api->request($client, 'GET', '/services/' . rawurlencode($serviceId))
            );
        });
    }

    public function scaleService(Cluster $cluster, string $serviceId, int $replicas): void
    {
        [$client, $temporaryFiles] = $this->createClient($cluster);
        try {
            $current = $this->request($client, '/services/' . rawurlencode($serviceId));
            $version = (int) ($current['Version']['Index'] ?? 0);
            $mode = $current['Spec']['Mode'] ?? [];
            if (isset($mode['Global'])) {
                throw new AppException(422, 'Global 模式的 Service 不支持调整副本数');
            }
            $spec = $current['Spec'];
            $spec['Mode']['Replicated']['Replicas'] = $replicas;
            $this->docker->request($client, 'POST', '/services/' . rawurlencode($serviceId) . '/update', [
                'query' => ['version' => $version],
                'json' => $spec,
            ]);
        } catch (GuzzleException $e) {
            throw new AppException(502, '无法连接 Docker Manager API：' . $e->getMessage(), [], $e);
        } finally {
            foreach ($temporaryFiles as $file) {
                @unlink($file);
            }
        }
    }

    public function forceUpdateService(Cluster $cluster, string $serviceId): void
    {
        [$client, $temporaryFiles] = $this->createClient($cluster);
        try {
            $current = $this->request($client, '/services/' . rawurlencode($serviceId));
            $version = (int) ($current['Version']['Index'] ?? 0);
            $spec = $current['Spec'];
            $forceUpdate = (int) ($spec['TaskTemplate']['ForceUpdate'] ?? 0);
            $spec['TaskTemplate']['ForceUpdate'] = $forceUpdate + 1;
            $this->docker->request($client, 'POST', '/services/' . rawurlencode($serviceId) . '/update', [
                'query' => ['version' => $version],
                'json' => $spec,
            ]);
        } catch (GuzzleException $e) {
            throw new AppException(502, '无法连接 Docker Manager API：' . $e->getMessage(), [], $e);
        } finally {
            foreach ($temporaryFiles as $file) {
                @unlink($file);
            }
        }
    }

    public function rollbackService(Cluster $cluster, string $serviceId): void
    {
        [$client, $temporaryFiles] = $this->createClient($cluster);
        try {
            $current = $this->request($client, '/services/' . rawurlencode($serviceId));
            if (empty($current['PreviousSpec'])) {
                throw new AppException(422, '该 Service 还没有可回滚的上一个版本，Service 至少需要被更新过一次才会有历史版本');
            }
            $version = (int) ($current['Version']['Index'] ?? 0);
            $this->docker->request($client, 'POST', '/services/' . rawurlencode($serviceId) . '/update', [
                'query' => ['version' => $version],
                'json' => $current['PreviousSpec'],
            ]);
        } catch (GuzzleException $e) {
            throw new AppException(502, '无法连接 Docker Manager API：' . $e->getMessage(), [], $e);
        } finally {
            foreach ($temporaryFiles as $file) {
                @unlink($file);
            }
        }
    }

    public function pruneServices(Cluster $cluster, array $serviceIds): array
    {
        $results = ['succeeded' => [], 'failed' => [], 'pruned_count' => 0];
        foreach ($serviceIds as $serviceId) {
            try {
                $containers = $this->listServiceContainers($cluster, $serviceId);
                $pruned = 0;
                foreach ($containers as $container) {
                    $state = $container['state'] ?? '';
                    if ($state !== 'running' && ($container['operable'] ?? false)) {
                        try {
                            $nodeId = (string) ($container['node_id'] ?? '');
                            if ($nodeId === '') {
                                throw new AppException(422, '容器缺少 NodeID，无法定位节点 Agent');
                            }
                            $nodeCluster = clone $cluster;
                            $nodeCluster->endpoint = 'agent://' . (int) $cluster->id . '/' . rawurlencode($nodeId);
                            $this->removeContainer($nodeCluster, $container['id']);
                            $pruned++;
                        } catch (\Throwable $e) {
                            $results['failed'][] = [
                                'service_id' => $serviceId,
                                'container_id' => $container['id'],
                                'error' => $e->getMessage(),
                            ];
                        }
                    }
                }
                $results['succeeded'][] = $serviceId;
                $results['pruned_count'] += $pruned;
            } catch (\Throwable $e) {
                $results['failed'][] = ['service_id' => $serviceId, 'error' => $e->getMessage()];
            }
        }
        return $results;
    }

    public function removeService(Cluster $cluster, string $serviceId): void
    {
        [$client, $temporaryFiles] = $this->createClient($cluster);
        try {
            $client->delete('/services/' . rawurlencode($serviceId));
        } catch (GuzzleException $e) {
            throw new AppException(502, '无法连接 Docker Manager API：' . $e->getMessage(), [], $e);
        } finally {
            foreach ($temporaryFiles as $file) {
                @unlink($file);
            }
        }
    }

    public function startContainer(Cluster $cluster, string $containerId): void
    {
        [$client, $temporaryFiles] = $this->createClient($cluster);
        try {
            $client->post('/containers/' . rawurlencode($containerId) . '/start');
        } catch (GuzzleException $e) {
            throw new AppException(502, '无法连接 Docker Manager API：' . $e->getMessage(), [], $e);
        } finally {
            foreach ($temporaryFiles as $file) {
                @unlink($file);
            }
        }
    }

    public function stopContainer(Cluster $cluster, string $containerId): void
    {
        [$client, $temporaryFiles] = $this->createClient($cluster);
        try {
            $client->post('/containers/' . rawurlencode($containerId) . '/stop');
        } catch (GuzzleException $e) {
            throw new AppException(502, '无法连接 Docker Manager API：' . $e->getMessage(), [], $e);
        } finally {
            foreach ($temporaryFiles as $file) {
                @unlink($file);
            }
        }
    }

    public function restartContainer(Cluster $cluster, string $containerId): void
    {
        [$client, $temporaryFiles] = $this->createClient($cluster);
        try {
            $client->post('/containers/' . rawurlencode($containerId) . '/restart');
        } catch (GuzzleException $e) {
            throw new AppException(502, '无法连接 Docker Manager API：' . $e->getMessage(), [], $e);
        } finally {
            foreach ($temporaryFiles as $file) {
                @unlink($file);
            }
        }
    }

    public function removeContainer(Cluster $cluster, string $containerId): void
    {
        [$client, $temporaryFiles] = $this->createClient($cluster);
        try {
            $inspect = $this->request($client, '/containers/' . rawurlencode($containerId) . '/json');
            if (($inspect['State']['Running'] ?? false) === true) {
                throw new AppException(422, '运行中的容器无法删除，请先停止');
            }
            $client->delete('/containers/' . rawurlencode($containerId), ['query' => ['force' => 1]]);
        } catch (AppException $e) {
            throw $e;
        } catch (GuzzleException $e) {
            throw new AppException(502, '无法连接 Docker Manager API：' . $e->getMessage(), [], $e);
        } finally {
            foreach ($temporaryFiles as $file) {
                @unlink($file);
            }
        }
    }

    public function pauseContainer(Cluster $cluster, string $containerId): void
    {
        [$client, $temporaryFiles] = $this->createClient($cluster);
        try {
            $client->post('/containers/' . rawurlencode($containerId) . '/pause');
        } catch (GuzzleException $e) {
            throw new AppException(502, '无法连接 Docker Manager API：' . $e->getMessage(), [], $e);
        } finally {
            foreach ($temporaryFiles as $file) {
                @unlink($file);
            }
        }
    }

    public function resumeContainer(Cluster $cluster, string $containerId): void
    {
        [$client, $temporaryFiles] = $this->createClient($cluster);
        try {
            $client->post('/containers/' . rawurlencode($containerId) . '/unpause');
        } catch (GuzzleException $e) {
            throw new AppException(502, '无法连接 Docker Manager API：' . $e->getMessage(), [], $e);
        } finally {
            foreach ($temporaryFiles as $file) {
                @unlink($file);
            }
        }
    }

    public function killContainer(Cluster $cluster, string $containerId): void
    {
        [$client, $temporaryFiles] = $this->createClient($cluster);
        try {
            $client->post('/containers/' . rawurlencode($containerId) . '/kill', [
                'query' => ['signal' => 'KILL'],
            ]);
        } catch (GuzzleException $e) {
            throw new AppException(502, '无法连接 Docker Manager API：' . $e->getMessage(), [], $e);
        } finally {
            foreach ($temporaryFiles as $file) {
                @unlink($file);
            }
        }
    }

    public function updateServiceNetworks(Cluster $cluster, string $serviceId, array $networkNames): void
    {
        [$client, $temporaryFiles] = $this->createClient($cluster);
        try {
            $current = $this->request($client, '/services/' . rawurlencode($serviceId));
            $version = (int) ($current['Version']['Index'] ?? 0);
            $spec = $current['Spec'];
            $spec['TaskTemplate']['Networks'] = array_map(
                static fn (string $name): array => ['Target' => $name],
                $networkNames
            );
            $this->docker->request($client, 'POST', '/services/' . rawurlencode($serviceId) . '/update', [
                'query' => ['version' => $version],
                'json' => $spec,
            ]);
        } catch (GuzzleException $e) {
            throw new AppException(502, '无法连接 Docker Manager API：' . $e->getMessage(), [], $e);
        } finally {
            foreach ($temporaryFiles as $file) {
                @unlink($file);
            }
        }
    }

    public function updateServiceResources(Cluster $cluster, string $serviceId, array $resources): void
    {
        [$client, $temporaryFiles] = $this->createClient($cluster);
        try {
            $current = $this->request($client, '/services/' . rawurlencode($serviceId));
            $version = (int) ($current['Version']['Index'] ?? 0);
            $spec = $current['Spec'];

            $taskResources = [];
            $limits = [];
            if (isset($resources['cpu_limit']) && $resources['cpu_limit'] > 0) {
                $limits['NanoCPUs'] = (int) ($resources['cpu_limit'] * 1_000_000_000);
            }
            if (isset($resources['memory_limit']) && $resources['memory_limit'] > 0) {
                $limits['MemoryBytes'] = (int) $resources['memory_limit'];
            }
            if ($limits) {
                $taskResources['Limits'] = $limits;
            }

            $reservations = [];
            if (isset($resources['cpu_reserved']) && $resources['cpu_reserved'] > 0) {
                $reservations['NanoCPUs'] = (int) ($resources['cpu_reserved'] * 1_000_000_000);
            }
            if (isset($resources['memory_reserved']) && $resources['memory_reserved'] > 0) {
                $reservations['MemoryBytes'] = (int) $resources['memory_reserved'];
            }
            if ($reservations) {
                $taskResources['Reservations'] = $reservations;
            }

            $spec['TaskTemplate']['Resources'] = $taskResources;

            $this->docker->request($client, 'POST', '/services/' . rawurlencode($serviceId) . '/update', [
                'query' => ['version' => $version],
                'json' => $spec,
            ]);
            $this->syncWorkspaceResourceLimits($cluster, $current, $limits);
        } catch (GuzzleException $e) {
            throw new AppException(502, '无法连接 Docker Manager API：' . $e->getMessage(), [], $e);
        } finally {
            foreach ($temporaryFiles as $file) {
                @unlink($file);
            }
        }
    }

    private function syncWorkspaceResourceLimits(Cluster $cluster, array $service, array $limits): void
    {
        $labels = (array) ($service['Spec']['Labels'] ?? []);
        if (($labels['com.codegalaxy.kind'] ?? '') !== 'workspace') {
            return;
        }
        $workspaceId = (int) ($labels['com.codegalaxy.workspace.id'] ?? 0);
        if ($workspaceId <= 0) {
            return;
        }
        $workspace = Workspace::where('id', $workspaceId)
            ->where('org_id', (int) $cluster->org_id)
            ->where('cluster_id', (int) $cluster->id)
            ->where('runtime_ref', (string) ($service['ID'] ?? ''))
            ->first();
        if ($workspace !== null) {
            $workspace->syncRuntimeLimits($limits);
        }
    }

    public function updateServiceEnv(Cluster $cluster, string $serviceId, array $env): void
    {
        [$client, $temporaryFiles] = $this->createClient($cluster);
        try {
            $current = $this->request($client, '/services/' . rawurlencode($serviceId));
            $version = (int) ($current['Version']['Index'] ?? 0);
            $spec = $current['Spec'];
            $spec['TaskTemplate']['ContainerSpec']['Env'] = $env;
            $this->docker->request($client, 'POST', '/services/' . rawurlencode($serviceId) . '/update', [
                'query' => ['version' => $version],
                'json' => $spec,
            ]);
        } catch (Throwable $e) {
            throw new AppException(502, '更新环境变量失败：' . $e->getMessage(), [], $e);
        } finally {
            foreach ($temporaryFiles as $file) {
                @unlink($file);
            }
        }
    }

    public function updateServicePorts(Cluster $cluster, string $serviceId, array $ports): void
    {
        [$client, $temporaryFiles] = $this->createClient($cluster);
        try {
            $current = $this->request($client, '/services/' . rawurlencode($serviceId));
            $version = (int) ($current['Version']['Index'] ?? 0);
            $spec = $current['Spec'];
            $spec['EndpointSpec'] = [
                'Mode' => $spec['EndpointSpec']['Mode'] ?? 'vip',
                'Ports' => $ports,
            ];
            $this->docker->request($client, 'POST', '/services/' . rawurlencode($serviceId) . '/update', [
                'query' => ['version' => $version],
                'json' => $spec,
            ]);
        } catch (Throwable $e) {
            throw new AppException(502, '更新端口失败：' . $e->getMessage(), [], $e);
        } finally {
            foreach ($temporaryFiles as $file) {
                @unlink($file);
            }
        }
    }

    /**
     * 先在 Manager 节点完整拉取并校验镜像，再更新 Service。
     * 拉取失败时不会提交 Service Spec；认证信息随更新请求传给 Swarm，
     * 以便实际被调度的节点也能拉取私有镜像。
     */
    public function pullAndUpdateServiceImage(
        Cluster $cluster,
        int $orgId,
        string $serviceId,
        string $image,
        int $registryId = 0
    ): array {
        $pull = $this->pullImage($cluster, $orgId, $image, $registryId);
        $this->updateServiceImage(
            $cluster,
            $serviceId,
            $image,
            $this->registryService->dockerAuthHeader($orgId, $image, $registryId)
        );
        return $pull;
    }

    public function updateServiceImage(
        Cluster $cluster,
        string $serviceId,
        string $image,
        array $registryHeaders = []
    ): void
    {
        [$client, $temporaryFiles] = $this->createClient($cluster);
        try {
            $current = $this->request($client, '/services/' . rawurlencode($serviceId));
            $version = (int) ($current['Version']['Index'] ?? 0);
            $spec = $current['Spec'];
            $spec['TaskTemplate']['ContainerSpec']['Image'] = $image;
            $this->docker->request($client, 'POST', '/services/' . rawurlencode($serviceId) . '/update', [
                'query' => ['version' => $version],
                'json' => $spec,
                'headers' => $registryHeaders,
            ]);
        } catch (GuzzleException $e) {
            throw new AppException(502, '更新镜像失败：' . $e->getMessage(), [], $e);
        } finally {
            foreach ($temporaryFiles as $file) {
                @unlink($file);
            }
        }
    }

    public function updateServiceUpdateConfig(Cluster $cluster, string $serviceId, array $updateConfig, array $rollbackConfig = []): void
    {
        [$client, $temporaryFiles] = $this->createClient($cluster);
        try {
            $current = $this->request($client, '/services/' . rawurlencode($serviceId));
            $version = (int) ($current['Version']['Index'] ?? 0);
            $spec = $current['Spec'];

            if (!empty($updateConfig)) {
                $spec['UpdateConfig'] = array_merge($spec['UpdateConfig'] ?? [], $updateConfig);
            }
            if (!empty($rollbackConfig)) {
                $spec['RollbackConfig'] = array_merge($spec['RollbackConfig'] ?? [], $rollbackConfig);
            }

            $this->docker->request($client, 'POST', '/services/' . rawurlencode($serviceId) . '/update', [
                'query' => ['version' => $version],
                'json' => $spec,
            ]);
        } catch (Throwable $e) {
            throw new AppException(502, '更新部署配置失败：' . $e->getMessage(), [], $e);
        } finally {
            foreach ($temporaryFiles as $file) {
                @unlink($file);
            }
        }
    }

    public function getServiceConfigs(Cluster $cluster, string $serviceId): array
    {
        [$client, $temporaryFiles] = $this->createClient($cluster);
        try {
            $svc = $this->request($client, '/services/' . rawurlencode($serviceId));
            $configs = $svc['Spec']['TaskTemplate']['ContainerSpec']['Configs'] ?? [];
            $result = [];
            foreach ($configs as $config) {
                $configData = $this->request($client, '/configs/' . rawurlencode($config['ConfigID']));
                $result[] = [
                    'config_id' => $config['ConfigID'] ?? '',
                    'name' => $config['ConfigName'] ?? '',
                    'target' => $config['File']['Name'] ?? '',
                    'content' => base64_decode((string) ($configData['Spec']['Data'] ?? '')),
                ];
            }
            return $result;
        } catch (GuzzleException $e) {
            throw new AppException(502, '无法连接 Docker Manager API：' . $e->getMessage(), [], $e);
        } finally {
            foreach ($temporaryFiles as $file) {
                @unlink($file);
            }
        }
    }

    public function updateServiceConfig(Cluster $cluster, string $serviceId, string $configId, string $newContent): void
    {
        [$client, $temporaryFiles] = $this->createClient($cluster);
        try {
            $current = $this->request($client, '/services/' . rawurlencode($serviceId));
            $version = (int) ($current['Version']['Index'] ?? 0);
            $spec = $current['Spec'];
            $containerSpec = $spec['TaskTemplate']['ContainerSpec'] ?? [];

            $oldName = '';
            $target = '';
            foreach ($containerSpec['Configs'] ?? [] as $c) {
                if (($c['ConfigID'] ?? '') === $configId) {
                    $oldName = $c['ConfigName'] ?? '';
                    $target = $c['File']['Name'] ?? '';
                    break;
                }
            }
            if ($oldName === '') {
                throw new AppException(404, '未找到指定的 Config');
            }

            $suffix = substr(hash('sha256', $serviceId . "\0" . $target . "\0" . $newContent), 0, 12);
            $prefix = preg_replace('/-[a-f0-9]{6,12}$/i', '', $oldName) ?: 'galaxy-config';
            $prefix = rtrim(substr($prefix, 0, 64 - strlen($suffix) - 1), '-_.');
            $newName = ($prefix !== '' ? $prefix : 'galaxy-config') . '-' . $suffix;
            $created = $this->docker->request($client, 'POST', '/configs/create', ['json' => [
                'Name' => $newName,
                'Data' => base64_encode($newContent),
            ]]);
            $newId = $created['ID'] ?? '';
            if ($newId === '') {
                throw new AppException(502, 'Docker API 未返回新 Config ID');
            }

            $spec['TaskTemplate']['ContainerSpec']['Configs'] = array_map(
                static fn (array $c): array => ($c['ConfigID'] ?? '') === $configId
                    ? ['ConfigID' => $newId, 'ConfigName' => $newName,
                       'File' => ['Name' => $target, 'UID' => '0', 'GID' => '0', 'Mode' => $c['File']['Mode'] ?? 292]]
                    : $c,
                $containerSpec['Configs'] ?? []
            );

            try {
                $this->docker->request($client, 'POST', '/services/' . rawurlencode($serviceId) . '/update', [
                    'query' => ['version' => $version],
                    'json' => $spec,
                ]);
            } catch (\Throwable $e) {
                try {
                    $this->docker->request($client, 'DELETE', '/configs/' . rawurlencode($newId));
                } catch (\Throwable) {
                }
                throw $e;
            }

            try {
                $this->request($client, 'DELETE', '/configs/' . rawurlencode($configId));
            } catch (\Throwable $_) {}
        } catch (GuzzleException $e) {
            throw new AppException(502, '无法连接 Docker Manager API：' . $e->getMessage(), [], $e);
        } finally {
            foreach ($temporaryFiles as $file) {
                @unlink($file);
            }
        }
    }

    /**
     * 读取 Service 当前已挂载的 Config / Secret 映射（不含内容）。
     */
    public function getServiceConfigAndSecretMappings(Cluster $cluster, string $serviceId): array
    {
        [$client, $temporaryFiles] = $this->createClient($cluster);
        try {
            $svc = $this->request($client, '/services/' . rawurlencode($serviceId));
            $containerSpec = $svc['Spec']['TaskTemplate']['ContainerSpec'] ?? [];
            $configs = array_map(static fn (array $c): array => [
                'id' => $c['ConfigID'] ?? '',
                'name' => $c['ConfigName'] ?? '',
                'target' => $c['File']['Name'] ?? '',
            ], $containerSpec['Configs'] ?? []);
            $secrets = array_map(static fn (array $s): array => [
                'id' => $s['SecretID'] ?? '',
                'name' => $s['SecretName'] ?? '',
                'target' => $s['File']['Name'] ?? '',
            ], $containerSpec['Secrets'] ?? []);
            return ['configs' => $configs, 'secrets' => $secrets];
        } catch (GuzzleException $e) {
            throw new AppException(502, '无法连接 Docker Manager API：' . $e->getMessage(), [], $e);
        } finally {
            foreach ($temporaryFiles as $file) {
                @unlink($file);
            }
        }
    }

    /**
     * 为 Service 新增 Config 映射：可复用已有 Config，也可新建后挂载。
     * 目标路径 = 目标目录 + '/' + Config 名称。
     */
    public function addServiceConfig(
        Cluster $cluster,
        string $serviceId,
        ?string $configId,
        ?string $name,
        ?string $content,
        string $targetDir,
        ?int $mode = null
    ): void {
        [$client, $temporaryFiles] = $this->createClient($cluster);
        try {
            if ($configId) {
                $existing = $this->request($client, '/configs/' . rawurlencode($configId));
                $resolvedName = $existing['Spec']['Name'] ?? '';
                if ($resolvedName === '') {
                    throw new AppException(422, '未找到指定的 Config');
                }
            } else {
                $name = trim((string) $name);
                if ($name === '' || $content === null) {
                    throw new AppException(422, '新建 Config 需要提供名称和内容');
                }
                if (! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,62}$/', $name)) {
                    throw new AppException(422, 'Config 名称仅允许字母、数字、下划线、点号和连字符，最长 63 字符');
                }
                $created = $this->docker->request($client, 'POST', '/configs/create', ['json' => [
                    'Name' => $name,
                    'Data' => base64_encode($content),
                ]]);
                $configId = $created['ID'] ?? '';
                if ($configId === '') {
                    throw new AppException(502, 'Docker API 未返回新 Config ID');
                }
                $resolvedName = $name;
            }
            $target = rtrim($targetDir, '/') . '/' . ltrim($resolvedName, '/');
            $this->appendServiceConfigMapping($client, $serviceId, $configId, $resolvedName, $target, $mode ?? 292);
        } catch (AppException $e) {
            throw $e;
        } catch (GuzzleException $e) {
            throw new AppException(502, '新增 Config 映射失败：' . $e->getMessage(), [], $e);
        } finally {
            foreach ($temporaryFiles as $file) {
                @unlink($file);
            }
        }
    }

    /**
     * 为 Service 新增 Secret 映射：可复用已有 Secret，也可新建后挂载。
     * 目标路径默认 /run/secrets/<名称>，可由调用方覆盖。
     */
    public function addServiceSecret(
        Cluster $cluster,
        string $serviceId,
        ?string $secretId,
        ?string $name,
        ?string $content,
        ?string $target = null,
        ?int $mode = null
    ): void {
        [$client, $temporaryFiles] = $this->createClient($cluster);
        try {
            if ($secretId) {
                $existing = $this->request($client, '/secrets/' . rawurlencode($secretId));
                $resolvedName = $existing['Spec']['Name'] ?? '';
                if ($resolvedName === '') {
                    throw new AppException(422, '未找到指定的 Secret');
                }
            } else {
                $name = trim((string) $name);
                if ($name === '' || $content === null) {
                    throw new AppException(422, '新建 Secret 需要提供名称和内容');
                }
                if (! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,62}$/', $name)) {
                    throw new AppException(422, 'Secret 名称仅允许字母、数字、下划线、点号和连字符，最长 63 字符');
                }
                $created = $this->docker->request($client, 'POST', '/secrets/create', ['json' => [
                    'Name' => $name,
                    'Data' => base64_encode($content),
                ]]);
                $secretId = $created['ID'] ?? '';
                if ($secretId === '') {
                    throw new AppException(502, 'Docker API 未返回新 Secret ID');
                }
                $resolvedName = $name;
            }
            $target = $target ?: ('/run/secrets/' . ltrim($resolvedName, '/'));
            $this->appendServiceSecretMapping($client, $serviceId, $secretId, $resolvedName, $target, $mode ?? 292);
        } catch (AppException $e) {
            throw $e;
        } catch (GuzzleException $e) {
            throw new AppException(502, '新增 Secret 映射失败：' . $e->getMessage(), [], $e);
        } finally {
            foreach ($temporaryFiles as $file) {
                @unlink($file);
            }
        }
    }

    public function removeServiceConfig(Cluster $cluster, string $serviceId, string $configId): void
    {
        [$client, $temporaryFiles] = $this->createClient($cluster);
        try {
            $current = $this->request($client, '/services/' . rawurlencode($serviceId));
            $version = (int) ($current['Version']['Index'] ?? 0);
            $spec = $current['Spec'];
            $configs = $spec['TaskTemplate']['ContainerSpec']['Configs'] ?? [];
            $filtered = array_values(array_filter(
                $configs,
                static fn (array $c): bool => ($c['ConfigID'] ?? '') !== $configId
            ));
            if (count($filtered) === count($configs)) {
                throw new AppException(404, '该 Config 未映射到此 Service');
            }
            $spec['TaskTemplate']['ContainerSpec']['Configs'] = $filtered;
            $this->docker->request($client, 'POST', '/services/' . rawurlencode($serviceId) . '/update', [
                'query' => ['version' => $version],
                'json' => $spec,
            ]);
        } catch (AppException $e) {
            throw $e;
        } catch (GuzzleException $e) {
            throw new AppException(502, '移除 Config 映射失败：' . $e->getMessage(), [], $e);
        } finally {
            foreach ($temporaryFiles as $file) {
                @unlink($file);
            }
        }
    }

    public function removeServiceSecret(Cluster $cluster, string $serviceId, string $secretId): void
    {
        [$client, $temporaryFiles] = $this->createClient($cluster);
        try {
            $current = $this->request($client, '/services/' . rawurlencode($serviceId));
            $version = (int) ($current['Version']['Index'] ?? 0);
            $spec = $current['Spec'];
            $secrets = $spec['TaskTemplate']['ContainerSpec']['Secrets'] ?? [];
            $filtered = array_values(array_filter(
                $secrets,
                static fn (array $s): bool => ($s['SecretID'] ?? '') !== $secretId
            ));
            if (count($filtered) === count($secrets)) {
                throw new AppException(404, '该 Secret 未映射到此 Service');
            }
            $spec['TaskTemplate']['ContainerSpec']['Secrets'] = $filtered;
            $this->docker->request($client, 'POST', '/services/' . rawurlencode($serviceId) . '/update', [
                'query' => ['version' => $version],
                'json' => $spec,
            ]);
        } catch (AppException $e) {
            throw $e;
        } catch (GuzzleException $e) {
            throw new AppException(502, '移除 Secret 映射失败：' . $e->getMessage(), [], $e);
        } finally {
            foreach ($temporaryFiles as $file) {
                @unlink($file);
            }
        }
    }

    private function appendServiceConfigMapping(
        Client $client,
        string $serviceId,
        string $configId,
        string $name,
        string $target,
        int $mode
    ): void {
        $current = $this->request($client, '/services/' . rawurlencode($serviceId));
        $version = (int) ($current['Version']['Index'] ?? 0);
        $spec = $current['Spec'];
        $configs = $spec['TaskTemplate']['ContainerSpec']['Configs'] ?? [];
        foreach ($configs as $c) {
            if (($c['ConfigID'] ?? '') === $configId) {
                throw new AppException(422, '该 Config 已映射到此 Service');
            }
        }
        $configs[] = [
            'ConfigID' => $configId,
            'ConfigName' => $name,
            'File' => ['Name' => $target, 'UID' => '0', 'GID' => '0', 'Mode' => $mode],
        ];
        $spec['TaskTemplate']['ContainerSpec']['Configs'] = $configs;
        $this->docker->request($client, 'POST', '/services/' . rawurlencode($serviceId) . '/update', [
            'query' => ['version' => $version],
            'json' => $spec,
        ]);
    }

    private function appendServiceSecretMapping(
        Client $client,
        string $serviceId,
        string $secretId,
        string $name,
        string $target,
        int $mode
    ): void {
        $current = $this->request($client, '/services/' . rawurlencode($serviceId));
        $version = (int) ($current['Version']['Index'] ?? 0);
        $spec = $current['Spec'];
        $secrets = $spec['TaskTemplate']['ContainerSpec']['Secrets'] ?? [];
        foreach ($secrets as $s) {
            if (($s['SecretID'] ?? '') === $secretId) {
                throw new AppException(422, '该 Secret 已映射到此 Service');
            }
        }
        $secrets[] = [
            'SecretID' => $secretId,
            'SecretName' => $name,
            'File' => ['Name' => $target, 'UID' => '0', 'GID' => '0', 'Mode' => $mode],
        ];
        $spec['TaskTemplate']['ContainerSpec']['Secrets'] = $secrets;
        $this->docker->request($client, 'POST', '/services/' . rawurlencode($serviceId) . '/update', [
            'query' => ['version' => $version],
            'json' => $spec,
        ]);
    }

    public function listServiceTasks(Cluster $cluster, string $serviceId): array
    {
        [$client, $temporaryFiles] = $this->createClient($cluster);
        try {
            $tasks = $this->request($client, '/tasks', [
                'query' => ['filters' => json_encode(['service' => [$serviceId]])],
            ]);
            $nodes = $this->request($client, '/nodes');
            $nodeMap = [];
            foreach ($nodes as $node) {
                $nodeMap[$node['ID'] ?? ''] = $node['Description']['Hostname'] ?? '';
            }

            return array_map(function (array $task) use ($nodeMap): array {
                $state = (string) ($task['Status']['State'] ?? 'unknown');
                return [
                    'id' => (string) ($task['ID'] ?? ''),
                    'node_id' => (string) ($task['NodeID'] ?? ''),
                    'node_hostname' => $nodeMap[$task['NodeID'] ?? ''] ?? '',
                    'state' => $state,
                    'desired_state' => (string) ($task['DesiredState'] ?? ''),
                    'message' => (string) ($task['Status']['Message'] ?? ''),
                    'error' => (string) ($task['Status']['Err'] ?? ''),
                    'slot' => (int) ($task['Slot'] ?? 0),
                    'container_id' => (string) ($task['Status']['ContainerStatus']['ContainerID'] ?? ''),
                    'created_at' => (string) ($task['CreatedAt'] ?? ''),
                    'updated_at' => (string) ($task['Status']['Timestamp'] ?? ''),
                ];
            }, $tasks);
        } catch (GuzzleException $e) {
            throw new AppException(502, '无法连接 Docker Manager API：' . $e->getMessage(), [], $e);
        } finally {
            foreach ($temporaryFiles as $file) {
                @unlink($file);
            }
        }
    }

    public function listServiceContainers(Cluster $cluster, string $serviceId): array
    {
        [$client, $temporaryFiles] = $this->createClient($cluster);
        try {
            $parallel = new Parallel(3);
            $parallel->add(fn (): array => $this->request($client, '/tasks', [
                'query' => ['filters' => json_encode(['service' => [$serviceId]])],
            ]), 'tasks');
            $parallel->add(fn (): array => $this->request($client, '/nodes'), 'nodes');
            $parallel->add(fn (): array => $this->request($client, '/services/' . rawurlencode($serviceId)), 'service');
            $result = $parallel->wait();

            $resolvedServiceId = (string) ($result['service']['ID'] ?? $serviceId);
            $serviceName = (string) ($result['service']['Spec']['Name'] ?? '');
            $serviceImage = (string) ($result['service']['Spec']['TaskTemplate']['ContainerSpec']['Image'] ?? '');
            $nodeNames = [];
            foreach ($result['nodes'] as $node) {
                $nodeNames[(string) ($node['ID'] ?? '')] = (string) ($node['Description']['Hostname'] ?? '');
            }

            $taskByContainer = [];
            $targetNodes = [];
            foreach ($result['tasks'] as $task) {
                $cid = (string) ($task['Status']['ContainerStatus']['ContainerID'] ?? '');
                $nodeId = (string) ($task['NodeID'] ?? '');
                if ($cid !== '') {
                    $taskByContainer[$cid] = [
                        'task_id' => (string) ($task['ID'] ?? ''),
                        'node_id' => $nodeId,
                        'node_hostname' => $nodeNames[$nodeId] ?? '',
                        'task_state' => (string) ($task['Status']['State'] ?? 'unknown'),
                        'desired_state' => (string) ($task['DesiredState'] ?? ''),
                        'task_error' => (string) ($task['Status']['Err'] ?? ''),
                        'task_message' => (string) ($task['Status']['Message'] ?? ''),
                        'slot' => (int) ($task['Slot'] ?? 0),
                        'created_at' => (string) ($task['CreatedAt'] ?? ''),
                    ];
                }
                if ($cid !== '' && $nodeId !== '') {
                    $targetNodes[$nodeId] = true;
                }
            }

            $nodeContainers = new Parallel(8);
            foreach (array_keys($targetNodes) as $nodeId) {
                $nodeContainers->add(function () use ($cluster, $nodeId, $resolvedServiceId): array {
                    try {
                        $containers = $this->docker->withNode(
                            $cluster,
                            $nodeId,
                            fn (Client $nodeClient): array => $this->containers(
                                $nodeClient,
                                $this->request($nodeClient, '/containers/json', [
                                    'query' => [
                                        'all' => 1,
                                        'filters' => json_encode([
                                            'label' => ['com.docker.swarm.service.id=' . $resolvedServiceId],
                                        ]),
                                    ],
                                ])
                            ),
                            15
                        );
                        return ['containers' => $containers, 'error' => ''];
                    } catch (Throwable $e) {
                        return ['containers' => [], 'error' => $e->getMessage()];
                    }
                }, $nodeId);
            }
            $perNode = $nodeContainers->count() > 0 ? $nodeContainers->wait() : [];

            $rows = [];
            $found = [];
            foreach ($perNode as $nodeId => $nodeResult) {
                foreach ((array) ($nodeResult['containers'] ?? []) as $container) {
                    $cid = (string) ($container['id'] ?? '');
                    if ($cid === '') {
                        continue;
                    }
                    $task = $taskByContainer[$cid] ?? [];
                    $rows[] = array_merge($container, [
                        'cluster_id' => (int) $cluster->id,
                        'node_id' => (string) $nodeId,
                        'node_hostname' => $nodeNames[$nodeId] ?? '',
                        'task_id' => $task['task_id'] ?? (string) ($container['task_id'] ?? ''),
                        'task_state' => $task['task_state'] ?? (string) ($container['state'] ?? 'unknown'),
                        'desired_state' => $task['desired_state'] ?? '',
                        'task_error' => $task['task_error'] ?? '',
                        'task_message' => $task['task_message'] ?? '',
                        'slot' => $task['slot'] ?? 0,
                        'operable' => true,
                        'remote' => false,
                        'agent_online' => true,
                    ]);
                    $found[$cid] = true;
                }
            }

            // A running Task must remain visible even when its node Agent is
            // temporarily offline. It is intentionally non-operable until the
            // exact NodeID route becomes available again.
            foreach ($taskByContainer as $cid => $task) {
                if (isset($found[$cid]) || $task['desired_state'] !== 'running') {
                    continue;
                }
                $nodeId = $task['node_id'];
                $nodeError = (string) ($perNode[$nodeId]['error'] ?? '');
                $createdAt = strtotime($task['created_at']);
                $rows[] = [
                    'id' => $cid,
                    'name' => trim($serviceName . '.' . ($task['slot'] ?: substr($nodeId, 0, 12))
                        . '.' . substr($task['task_id'], 0, 12), '.'),
                    'image' => $serviceImage,
                    'cluster_id' => (int) $cluster->id,
                    'service_id' => $resolvedServiceId,
                    'service_name' => $serviceName,
                    'task_id' => $task['task_id'],
                    'node_id' => $nodeId,
                    'node_hostname' => $task['node_hostname'],
                    'state' => $task['task_state'],
                    'status' => $nodeError !== '' ? $nodeError : $task['task_message'],
                    'health' => 'none',
                    'created_at' => $createdAt === false ? 0 : $createdAt,
                    'cpu_percent' => 0,
                    'memory_usage' => 0,
                    'memory_limit' => 0,
                    'pids' => 0,
                    'network_rx' => 0,
                    'network_tx' => 0,
                    'ports' => [],
                    'networks' => [],
                    'ip_addresses' => [],
                    'mounts' => 0,
                    'task_state' => $task['task_state'],
                    'desired_state' => $task['desired_state'],
                    'task_error' => $task['task_error'],
                    'task_message' => $task['task_message'],
                    'slot' => $task['slot'],
                    'operable' => false,
                    'remote' => true,
                    'agent_online' => false,
                    'node_error' => $nodeError,
                ];
            }

            usort($rows, static fn (array $left, array $right): int =>
                [(int) ($left['slot'] ?? 0), (string) ($left['node_hostname'] ?? ''), (string) ($left['id'] ?? '')]
                <=>
                [(int) ($right['slot'] ?? 0), (string) ($right['node_hostname'] ?? ''), (string) ($right['id'] ?? '')]
            );
            return $rows;
        } catch (GuzzleException $e) {
            throw new AppException(502, '无法连接 Docker Manager API：' . $e->getMessage(), [], $e);
        } finally {
            foreach ($temporaryFiles as $file) {
                @unlink($file);
            }
        }
    }

    public function listNodes(Cluster $cluster): array
    {
        [$client, $temporaryFiles] = $this->createClient($cluster);
        try {
            $parallel = new Parallel(2);
            $parallel->add(fn (): array => $this->request($client, '/nodes'), 'nodes');
            $parallel->add(fn (): array => $this->request($client, '/tasks'), 'tasks');
            $result = $parallel->wait();

            $nodeRows = $this->nodes($result['nodes']);

            $nodes = array_map(function (array $node) use ($result): array {
                $nodeId = $node['id'];
                $nodeTasks = array_filter($result['tasks'], static fn (array $task) => ($task['NodeID'] ?? '') === $nodeId);
                $reserved = $this->nodeReservations($nodeTasks);
                $cpuCapacity = (float) ($node['cpu_cores'] ?? 0);
                $memoryCapacity = (int) ($node['memory_bytes'] ?? 0);
                $warnings = $this->nodeWarnings($node, $reserved, $cpuCapacity, $memoryCapacity);

                return array_merge($node, [
                    'task_counts' => $this->taskStateCounts($nodeTasks),
                    'task_total' => count($nodeTasks),
                    'reserved' => [
                        'cpu_cores' => $reserved['cpu'],
                        'memory_bytes' => $reserved['memory'],
                        'cpu_percent' => $this->percentage($reserved['cpu'], $cpuCapacity),
                        'memory_percent' => $this->percentage($reserved['memory'], $memoryCapacity),
                    ],
                    'used' => null,
                    'container_count' => null,
                    'container_running' => null,
                    'warnings' => $warnings,
                    'health_level' => $this->healthLevel($warnings),
                ]);
            }, $nodeRows);

            return [
                'generated_at' => time(),
                'nodes' => $nodes,
            ];
        } catch (GuzzleException $e) {
            throw new AppException(502, '无法连接 Docker Manager API：' . $e->getMessage(), [], $e);
        } finally {
            foreach ($temporaryFiles as $file) {
                @unlink($file);
            }
        }
    }

    public function nodesRuntime(Cluster $cluster): array
    {
        [$client, $temporaryFiles] = $this->createClient($cluster);
        try {
            $parallel = new Parallel(2);
            $parallel->add(fn (): array => $this->optionalRequest($client, '/containers/json', ['query' => ['all' => 1]]), 'containers');
            $parallel->add(fn (): array => $this->optionalRequest($client, '/system/df'), 'disk_usage');
            $result = $parallel->wait();

            $containerRows = $this->containers($client, $result['containers']);
            $rawById = [];
            foreach ($result['containers'] as $c) {
                $rawById[$c['Id'] ?? ''] = $c;
            }

            // Build per-node runtime data keyed by node ID
            $nodeRuntime = [];
            foreach ($containerRows as $container) {
                $raw = $rawById[$container['id'] ?? ''] ?? [];
                $labels = $raw['Labels'] ?? [];
                $nodeId = $labels['com.docker.swarm.node.id'] ?? '';
                if ($nodeId === '') {
                    continue;
                }
                if (! isset($nodeRuntime[$nodeId])) {
                    $nodeRuntime[$nodeId] = [
                        'cpu_used' => 0.0,
                        'memory_used' => 0,
                        'container_count' => 0,
                        'container_running' => 0,
                    ];
                }
                $nodeRuntime[$nodeId]['container_count']++;
                if (($container['state'] ?? '') === 'running') {
                    $nodeRuntime[$nodeId]['container_running']++;
                }
                $nodeRuntime[$nodeId]['cpu_used'] += (float) ($container['cpu_percent'] ?? 0);
                $nodeRuntime[$nodeId]['memory_used'] += (int) ($container['memory_usage'] ?? 0);
            }

            return [
                'generated_at' => time(),
                'storage' => $this->storage($result['disk_usage']),
                'nodes_runtime' => $nodeRuntime,
            ];
        } catch (GuzzleException $e) {
            throw new AppException(502, '无法连接 Docker Manager API：' . $e->getMessage(), [], $e);
        } finally {
            foreach ($temporaryFiles as $file) {
                @unlink($file);
            }
        }
    }

    public function getNode(Cluster $cluster, string $nodeId): array
    {
        [$client, $temporaryFiles] = $this->createClient($cluster);
        try {
            $raw = $this->request($client, '/nodes/' . rawurlencode($nodeId));
            $node = $this->nodes([$raw])[0] ?? [];
            if (empty($node)) {
                throw new AppException(404, '节点不存在');
            }

            $tasks = $this->request($client, '/tasks', [
                'query' => ['filters' => json_encode(['node' => [$nodeId]])],
            ]);
            $reserved = $this->nodeReservations($tasks);

            $agentOnline = true;
            try {
                $runtime = $this->docker->withNode(
                    $cluster,
                    $nodeId,
                    fn (Client $nodeClient): array => [
                        'usage' => $this->nodeResourceUsage($nodeClient),
                        'disk_usage' => $this->optionalRequest($nodeClient, '/system/df'),
                    ],
                    15
                );
                $usage = $runtime['usage'];
                $diskUsage = $runtime['disk_usage'];
            } catch (Throwable) {
                $agentOnline = false;
                $usage = [
                    'cpu_cores_used' => 0,
                    'memory_used' => 0,
                    'container_counts' => ['total' => 0, 'running' => 0],
                    'containers' => [],
                ];
                $diskUsage = [];
            }

            $cpuCapacity = (float) ($node['cpu_cores'] ?? 0);
            $memoryCapacity = (int) ($node['memory_bytes'] ?? 0);
            $warnings = $this->nodeWarnings($node, $reserved, $cpuCapacity, $memoryCapacity);

            return [
                'generated_at' => time(),
                'agent_online' => $agentOnline,
                'detail' => $this->transformNodeInspect($raw),
                'reserved' => [
                    'cpu_cores' => $reserved['cpu'],
                    'memory_bytes' => $reserved['memory'],
                    'cpu_percent' => $this->percentage($reserved['cpu'], $cpuCapacity),
                    'memory_percent' => $this->percentage($reserved['memory'], $memoryCapacity),
                ],
                'used' => [
                    'cpu_cores' => $usage['cpu_cores_used'],
                    'memory_bytes' => $usage['memory_used'],
                    'cpu_percent' => $this->percentage($usage['cpu_cores_used'], $cpuCapacity),
                    'memory_percent' => $this->percentage($usage['memory_used'], $memoryCapacity),
                ],
                'task_counts' => $this->taskStateCounts($tasks),
                'task_total' => count($tasks),
                'container_count' => $usage['container_counts']['total'],
                'container_running' => $usage['container_counts']['running'],
                'storage' => $this->storage($diskUsage),
                'warnings' => $warnings,
                'health_level' => $this->healthLevel($warnings),
            ];
        } catch (GuzzleException $e) {
            throw new AppException(502, '无法连接 Docker Manager API：' . $e->getMessage(), [], $e);
        } finally {
            foreach ($temporaryFiles as $file) {
                @unlink($file);
            }
        }
    }

    public function nodeTasks(Cluster $cluster, string $nodeId): array
    {
        [$client, $temporaryFiles] = $this->createClient($cluster);
        try {
            $parallel = new Parallel(3);
            $parallel->add(fn (): array => $this->request($client, '/tasks', [
                'query' => ['filters' => json_encode(['node' => [$nodeId]])],
            ]), 'tasks');
            $parallel->add(fn (): array => $this->request($client, '/nodes'), 'nodes');
            $parallel->add(fn (): array => $this->request($client, '/services'), 'services');
            $result = $parallel->wait();
            $nodeMap = [];
            foreach ($result['nodes'] as $node) {
                $nodeMap[$node['ID'] ?? ''] = $node['Description']['Hostname'] ?? '';
            }
            $serviceMap = [];
            foreach ($result['services'] as $service) {
                $serviceMap[(string) ($service['ID'] ?? '')] = [
                    'name' => (string) ($service['Spec']['Name'] ?? ''),
                    'image' => (string) ($service['Spec']['TaskTemplate']['ContainerSpec']['Image'] ?? ''),
                ];
            }
            return array_map(function (array $task) use ($nodeMap, $serviceMap): array {
                $state = (string) ($task['Status']['State'] ?? 'unknown');
                $serviceId = (string) ($task['ServiceID'] ?? '');
                return [
                    'id' => (string) ($task['ID'] ?? ''),
                    'node_id' => (string) ($task['NodeID'] ?? ''),
                    'node_hostname' => $nodeMap[$task['NodeID'] ?? ''] ?? '',
                    'service_id' => $serviceId,
                    'service_name' => (string) ($serviceMap[$serviceId]['name'] ?? ''),
                    'image' => (string) ($serviceMap[$serviceId]['image']
                        ?? $task['Spec']['ContainerSpec']['Image'] ?? ''),
                    'state' => $state,
                    'desired_state' => (string) ($task['DesiredState'] ?? ''),
                    'message' => (string) ($task['Status']['Message'] ?? ''),
                    'error' => (string) ($task['Status']['Err'] ?? ''),
                    'slot' => (int) ($task['Slot'] ?? 0),
                    'container_id' => (string) ($task['Status']['ContainerStatus']['ContainerID'] ?? ''),
                    'created_at' => (string) ($task['CreatedAt'] ?? ''),
                    'updated_at' => (string) ($task['Status']['Timestamp'] ?? ''),
                ];
            }, $result['tasks']);
        } catch (GuzzleException $e) {
            throw new AppException(502, '无法连接 Docker Manager API：' . $e->getMessage(), [], $e);
        } finally {
            foreach ($temporaryFiles as $file) {
                @unlink($file);
            }
        }
    }

    public function nodeContainers(Cluster $cluster, string $nodeId): array
    {
        return $this->docker->withNode(
            $cluster,
            $nodeId,
            function (Client $nodeClient) use ($nodeId): array {
                return array_map(static function (array $container) use ($nodeId): array {
                    $container['node_id'] = $nodeId;
                    $container['operable'] = true;
                    return $container;
                }, $this->nodeResourceUsage($nodeClient)['containers']);
            },
            15
        );
    }

    private function nodeResourceUsage(Client $client): array
    {
        $raw = $this->optionalRequest($client, '/containers/json', ['query' => ['all' => 1]]);
        $rows = $this->containers($client, $raw);
        return [
            'cpu_cores_used' => round(array_sum(array_column($rows, 'cpu_percent')) / 100, 3),
            'memory_used' => array_sum(array_column($rows, 'memory_usage')),
            'container_counts' => [
                'total' => count($rows),
                'running' => count(array_filter($rows, static fn (array $c) => ($c['state'] ?? '') === 'running')),
            ],
            'containers' => $rows,
        ];
    }

    private function nodeReservations(array $tasks): array
    {
        $cpu = 0.0;
        $memory = 0;
        foreach ($tasks as $task) {
            if (($task['NodeID'] ?? '') === '') {
                continue;
            }
            if (($task['DesiredState'] ?? '') !== 'run') {
                continue;
            }
            $res = $task['Spec']['Resources']['Reservations'] ?? [];
            $cpu += ($res['NanoCPUs'] ?? 0) / 1_000_000_000;
            $memory += (int) ($res['MemoryBytes'] ?? 0);
        }
        return ['cpu' => round($cpu, 3), 'memory' => $memory];
    }

    private function taskStateCounts(array $tasks): array
    {
        $states = [];
        foreach ($tasks as $task) {
            $state = $task['Status']['State'] ?? 'unknown';
            $states[$state] = ($states[$state] ?? 0) + 1;
        }
        ksort($states);
        return $states;
    }

    private function transformNodeInspect(array $raw): array
    {
        $desc = $raw['Description'] ?? [];
        $engine = $desc['Engine'] ?? [];
        $resources = $desc['Resources'] ?? [];
        $managerStatus = $raw['ManagerStatus'] ?? [];
        $spec = $raw['Spec'] ?? [];
        $status = $raw['Status'] ?? [];
        return [
            'id' => $raw['ID'] ?? '',
            'version' => (int) ($raw['Version']['Index'] ?? 0),
            'hostname' => $desc['Hostname'] ?? '',
            'platform' => [
                'os' => $desc['Platform']['OS'] ?? '',
                'architecture' => $desc['Platform']['Architecture'] ?? '',
            ],
            'engine' => [
                'version' => $engine['EngineVersion'] ?? '',
                'labels' => $engine['Labels'] ?? [],
                'plugins' => array_map(static fn (array $p): array => [
                    'type' => $p['Type'] ?? '',
                    'name' => $p['Name'] ?? '',
                ], $engine['Plugins'] ?? []),
            ],
            'resources' => [
                'cpu_cores' => ($resources['NanoCPUs'] ?? 0) / 1_000_000_000,
                'memory_bytes' => (int) ($resources['MemoryBytes'] ?? 0),
                'generic_resources' => array_map(static function (array $g): string {
                    if (isset($g['NamedResourceSpec']['Kind'])) {
                        return ($g['NamedResourceSpec']['Kind'] ?? '') . '=' . ($g['NamedResourceSpec']['Value'] ?? '');
                    }
                    if (isset($g['DiscreteResourceSpec']['Kind'])) {
                        return ($g['DiscreteResourceSpec']['Kind'] ?? '') . ':' . ($g['DiscreteResourceSpec']['Value'] ?? 0);
                    }
                    return '';
                }, $resources['GenericResources'] ?? []),
            ],
            'role' => $spec['Role'] ?? '',
            'availability' => $spec['Availability'] ?? '',
            'labels' => $spec['Labels'] ?? [],
            'state' => $status['State'] ?? '',
            'status_message' => $status['Message'] ?? '',
            'address' => $status['Addr'] ?? '',
            'manager_status' => [
                'leader' => (bool) ($managerStatus['Leader'] ?? false),
                'reachability' => $managerStatus['Reachability'] ?? '',
                'address' => $managerStatus['Addr'] ?? '',
            ],
            'raft' => $spec['Raft'] ?? [],
            'created_at' => $raw['CreatedAt'] ?? '',
            'updated_at' => $raw['UpdatedAt'] ?? '',
        ];
    }

    private function nodeWarnings(array $node, array $reserved, float $cpuCapacity, int $memoryCapacity): array
    {
        $warnings = [];
        $state = $node['state'] ?? '';
        $availability = $node['availability'] ?? '';
        if ($state !== 'ready') {
            $warnings[] = [
                'level' => 'danger',
                'type' => 'status',
                'message' => '节点状态异常（非 ready）：' . ($state ?: 'unknown'),
            ];
        }
        if ($availability !== 'active') {
            $warnings[] = [
                'level' => 'warning',
                'type' => 'availability',
                'message' => '节点调度状态为 ' . ($availability ?: 'unknown') . '，不再接收新任务',
            ];
        }
        if (($node['role'] ?? '') === 'manager' && ($node['reachability'] ?? '') !== '' && ($node['reachability'] ?? '') !== 'reachable') {
            $warnings[] = [
                'level' => 'danger',
                'type' => 'manager',
                'message' => 'Manager 节点不可达（Reachability: ' . ($node['reachability'] ?: 'unknown') . '）',
            ];
        }
        $cpuPercent = $this->percentage($reserved['cpu'], $cpuCapacity);
        $memoryPercent = $this->percentage($reserved['memory'], $memoryCapacity);
        if ($cpuPercent >= 90) {
            $warnings[] = [
                'level' => $cpuPercent >= 100 ? 'danger' : 'warning',
                'type' => 'cpu',
                'message' => 'CPU 预留接近或达到上限（' . $cpuPercent . '%）',
            ];
        }
        if ($memoryPercent >= 90) {
            $warnings[] = [
                'level' => $memoryPercent >= 100 ? 'danger' : 'warning',
                'type' => 'memory',
                'message' => '内存预留接近或达到上限（' . $memoryPercent . '%）',
            ];
        }
        return $warnings;
    }

    private function healthLevel(array $warnings): string
    {
        $level = 'normal';
        foreach ($warnings as $warning) {
            if (($warning['level'] ?? '') === 'danger') {
                return 'danger';
            }
            if (($warning['level'] ?? '') === 'warning') {
                $level = 'warning';
            }
        }
        return $level;
    }

    private function transformServiceInspect(array $raw): array
    {
        $spec = $raw['Spec'] ?? [];
        $taskTemplate = $spec['TaskTemplate'] ?? [];
        $containerSpec = $taskTemplate['ContainerSpec'] ?? [];
        $resources = $taskTemplate['Resources'] ?? [];
        $limits = $resources['Limits'] ?? [];
        $reservations = $resources['Reservations'] ?? [];
        $endpoint = $raw['Endpoint'] ?? [];
        $updateStatus = $raw['UpdateStatus'] ?? [];

        return [
            'id' => $raw['ID'] ?? '',
            'version' => (int) ($raw['Version']['Index'] ?? 0),
            'name' => $spec['Name'] ?? '',
            'image' => $containerSpec['Image'] ?? '',
            'mode' => isset($spec['Mode']['Global']) ? 'global' : 'replicated',
            'replicas' => $spec['Mode']['Replicated']['Replicas'] ?? null,
            'labels' => $spec['Labels'] ?? [],
            'env' => $containerSpec['Env'] ?? [],
            'command_array' => (array) ($containerSpec['Command'] ?? []),
            'args_array' => (array) ($containerSpec['Args'] ?? []),
            'cmd' => is_array($containerSpec['Command'] ?? null) ? implode(' ', $containerSpec['Command']) : ($containerSpec['Command'] ?? ''),
            'args' => is_array($containerSpec['Args'] ?? null) ? implode(' ', $containerSpec['Args']) : ($containerSpec['Args'] ?? ''),
            'entrypoint' => is_array($containerSpec['Entrypoint'] ?? null) ? implode(' ', $containerSpec['Entrypoint']) : '',
            'user' => $containerSpec['User'] ?? '',
            'working_dir' => $containerSpec['Dir'] ?? '',
            'stop_grace_period' => $containerSpec['StopGracePeriod'] ?? null,
            'init' => (bool) ($containerSpec['Init'] ?? false),
            'isolation' => $containerSpec['Isolation'] ?? 'default',
            'hostname' => $containerSpec['Hostname'] ?? '',
            'tty' => (bool) ($containerSpec['TTY'] ?? false),
            'open_stdin' => (bool) ($containerSpec['OpenStdin'] ?? false),
            'read_only' => (bool) ($containerSpec['ReadOnly'] ?? false),
            'mounts' => array_map(static fn (array $m): array => [
                'type' => $m['Type'] ?? '',
                'source' => $m['Source'] ?? '',
                'target' => $m['Target'] ?? '',
                'readonly' => (bool) ($m['ReadOnly'] ?? false),
                'driver' => $m['Driver'] ?? '',
            ], $containerSpec['Mounts'] ?? []),
            'networks' => array_map(static fn (array $n): array => [
                'target' => $n['Target'] ?? '',
                'aliases' => $n['Aliases'] ?? [],
            ], $taskTemplate['Networks'] ?? []),
            'ports' => array_map(static fn (array $p): array => [
                'protocol' => $p['Protocol'] ?? 'tcp',
                'target_port' => $p['TargetPort'] ?? 0,
                'published_port' => $p['PublishedPort'] ?? 0,
                'publish_mode' => $p['PublishMode'] ?? 'ingress',
            ], $endpoint['Ports'] ?? []),
            'cpu_limit' => ($limits['NanoCPUs'] ?? 0) / 1_000_000_000,
            'cpu_reserved' => ($reservations['NanoCPUs'] ?? 0) / 1_000_000_000,
            'memory_limit' => (int) ($limits['MemoryBytes'] ?? 0),
            'memory_reserved' => (int) ($reservations['MemoryBytes'] ?? 0),
            'restart_policy' => [
                'condition' => $taskTemplate['RestartPolicy']['Condition'] ?? 'any',
                'delay' => $taskTemplate['RestartPolicy']['Delay'] ?? null,
                'max_attempts' => $taskTemplate['RestartPolicy']['MaxAttempts'] ?? 0,
                'window' => $taskTemplate['RestartPolicy']['Window'] ?? null,
            ],
            'update_config' => [
                'parallelism' => $spec['UpdateConfig']['Parallelism'] ?? 1,
                'delay' => $spec['UpdateConfig']['Delay'] ?? null,
                'failure_action' => $spec['UpdateConfig']['FailureAction'] ?? 'pause',
                'monitor' => $spec['UpdateConfig']['Monitor'] ?? null,
                'max_failure_ratio' => $spec['UpdateConfig']['MaxFailureRatio'] ?? 0,
                'order' => $spec['UpdateConfig']['Order'] ?? 'stop-first',
            ],
            'rollback_config' => [
                'parallelism' => $spec['RollbackConfig']['Parallelism'] ?? 1,
                'delay' => $spec['RollbackConfig']['Delay'] ?? null,
                'failure_action' => $spec['RollbackConfig']['FailureAction'] ?? 'pause',
                'monitor' => $spec['RollbackConfig']['Monitor'] ?? null,
                'max_failure_ratio' => $spec['RollbackConfig']['MaxFailureRatio'] ?? 0,
                'order' => $spec['RollbackConfig']['Order'] ?? 'stop-first',
            ],
            'placement' => [
                'constraints' => $taskTemplate['Placement']['Constraints'] ?? [],
                'platforms' => array_map(static fn (array $p): string => ($p['Architecture'] ?? '') . '/' . ($p['OS'] ?? ''), $taskTemplate['Placement']['Platforms'] ?? []),
                'preferences' => $taskTemplate['Placement']['Preferences'] ?? [],
                'max_replicas_per_node' => $taskTemplate['Placement']['MaxReplicas'] ?? null,
            ],
            'health_check' => ! empty($containerSpec['Healthcheck']) ? [
                'test' => $containerSpec['Healthcheck']['Test'] ?? [],
                'interval' => $containerSpec['Healthcheck']['Interval'] ?? null,
                'timeout' => $containerSpec['Healthcheck']['Timeout'] ?? null,
                'retries' => $containerSpec['Healthcheck']['Retries'] ?? 0,
                'start_period' => $containerSpec['Healthcheck']['StartPeriod'] ?? null,
            ] : null,
            'dns_config' => [
                'nameservers' => $containerSpec['DNSConfig']['Nameservers'] ?? [],
                'search' => $containerSpec['DNSConfig']['Search'] ?? [],
                'options' => $containerSpec['DNSConfig']['Options'] ?? [],
            ],
            'secrets' => array_map(static fn (array $s): array => [
                'id' => $s['SecretID'] ?? '',
                'name' => $s['SecretName'] ?? '',
                'file' => $s['File'] ?? [],
            ], $containerSpec['Secrets'] ?? []),
            'configs' => array_map(static fn (array $c): array => [
                'id' => $c['ConfigID'] ?? '',
                'name' => $c['ConfigName'] ?? '',
                'file' => $c['File'] ?? [],
            ], $containerSpec['Configs'] ?? []),
            'update_status' => [
                'state' => $updateStatus['State'] ?? '',
                'message' => $updateStatus['Message'] ?? '',
                'started_at' => $updateStatus['StartedAt'] ?? '',
                'completed_at' => $updateStatus['CompletedAt'] ?? '',
            ],
            'created_at' => $raw['CreatedAt'] ?? '',
            'updated_at' => $raw['UpdatedAt'] ?? '',
            'has_previous_spec' => ! empty($raw['PreviousSpec']),
        ];
    }

    public function updateServiceMounts(Cluster $cluster, string $serviceId, array $mounts): void
    {
        [$client, $temporaryFiles] = $this->createClient($cluster);
        try {
            $current = $this->request($client, '/services/' . rawurlencode($serviceId));
            $version = (int) ($current['Version']['Index'] ?? 0);
            $spec = $current['Spec'];
            $spec['TaskTemplate']['ContainerSpec']['Mounts'] = $mounts;
            $this->docker->request($client, 'POST', '/services/' . rawurlencode($serviceId) . '/update', [
                'query' => ['version' => $version],
                'json' => $spec,
            ]);
        } catch (Throwable $e) {
            throw new AppException(502, '更新挂载失败：' . $e->getMessage(), [], $e);
        } finally {
            foreach ($temporaryFiles as $file) {
                @unlink($file);
            }
        }
    }

    private function percentage(float|int $value, float|int $total): float
    {
        return $total > 0 ? round($value / $total * 100, 2) : 0;
    }

    private function isAgentEndpoint(string $endpoint): bool
    {
        return str_starts_with($endpoint, 'agent://');
    }

    private function transport(string $endpoint): string
    {
        return str_starts_with($endpoint, 'agent://') ? 'agent' : 'unsupported';
    }
}

<?php

namespace App\Services\NetworkTunnel;

use App\Exception\AppException;
use App\Model\Cluster;
use App\Model\ClusterAgentNode;
use App\Model\FrpClient;
use App\Model\FrpServer;
use App\Model\FrpTunnel;
use App\Services\Encrypt\EncryptService;
use Hyperf\DbConnection\Db;
use Throwable;

final class NetworkTunnelService
{
    public function __construct(
        private EncryptService $cipher,
        private FrpDeploymentService $deployment,
        private NetworkTunnelCatalogService $catalog
    ) {}

    public function topology(int $orgId): array
    {
        $servers = FrpServer::where('org_id', $orgId)
            ->with([
                'cluster:id,title,orchestrator_type,status',
                'clients' => static fn ($query) => $query
                    ->where('deployment_mode', 'managed')
                    ->orderBy('id'),
                'clients.cluster:id,title,orchestrator_type,status',
                'clients.tunnels' => static fn ($query) => $query->orderBy('id'),
            ])
            ->orderBy('id')
            ->get()
            ->map(fn (FrpServer $server): array => $this->serverArray($server))
            ->all();
        $clusters = Cluster::where('org_id', $orgId)
            ->whereIn('orchestrator_type', Cluster::knownOrchestrators())
            ->select(['id', 'title', 'orchestrator_type', 'status'])
            ->orderBy('orchestrator_type')
            ->orderBy('id')
            ->get()
            ->map(static fn (Cluster $cluster): array => [
                'id' => (int) $cluster->id,
                'title' => (string) $cluster->title,
                'orchestrator_type' => (string) $cluster->orchestrator_type,
                'status' => (int) $cluster->status,
            ])->all();
        return ['servers' => $servers, 'clusters' => $clusters, 'defaults' => [
            'frps_image' => 'fatedier/frps:v0.69.0',
            'frpc_image' => 'fatedier/frpc:v0.69.0',
            'namespace' => 'galaxy-frp',
            'bind_port' => 7000,
            'dashboard_port' => 7500,
        ]];
    }

    public function sourceServices(int $orgId, int $clusterId): array
    {
        return $this->catalog->services($orgId, $clusterId);
    }

    public function createServer(int $uid, int $orgId, array $input): FrpServer
    {
        $mode = $this->serverMode($input);
        $clusterId = $mode === 'managed' ? (int) ($input['cluster_id'] ?? 0) : null;
        if ($mode === 'managed') {
            $this->cluster($orgId, (int) $clusterId);
            if (FrpServer::where('org_id', $orgId)
                ->where('deployment_mode', 'managed')
                ->where('cluster_id', $clusterId)
                ->exists()) {
                throw new AppException(409, '该集群已经部署 FRPS；每个集群只保留一个 FRPS 实例');
            }
        }
        $this->assertServerPortsAndDashboard($input, false);
        $token = trim((string) ($input['auth_token'] ?? ''));
        if ($token === '') {
            $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        }
        $now = time();
        return FrpServer::create([
            'org_id' => $orgId,
            'title' => trim((string) $input['title']),
            'deployment_mode' => $mode,
            'management_mode' => (string) ($input['management_mode'] ?? 'manual') === 'automatic'
                ? 'automatic'
                : 'manual',
            'cluster_id' => $clusterId,
            'namespace' => trim((string) ($input['namespace'] ?? '')) ?: 'galaxy-frp',
            'image' => trim((string) ($input['image'] ?? '')) ?: 'fatedier/frps:v0.69.0',
            'advertise_host' => trim((string) $input['advertise_host']),
            'bind_port' => (int) ($input['bind_port'] ?? 7000),
            'vhost_http_port' => (int) ($input['vhost_http_port'] ?? 0),
            'vhost_https_port' => (int) ($input['vhost_https_port'] ?? 0),
            'dashboard_port' => (int) ($input['dashboard_port'] ?? 7500),
            'dashboard_user' => trim((string) ($input['dashboard_user'] ?? '')),
            'dashboard_password_ciphertext' => trim((string) ($input['dashboard_password'] ?? '')) === ''
                ? null
                : $this->cipher->encryptFast(trim((string) $input['dashboard_password'])),
            'auth_token_ciphertext' => $this->cipher->encryptFast($token),
            'runtime_status' => $mode === 'external' ? 'external' : 'not_deployed',
            'creator' => $uid,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function updateServer(int $orgId, int $id, array $input): FrpServer
    {
        $server = $this->server($orgId, $id);
        $mode = $this->serverMode($input);
        $clusterId = $mode === 'managed' ? (int) ($input['cluster_id'] ?? 0) : null;
        if ($mode === 'managed') {
            $this->cluster($orgId, (int) $clusterId);
            if (FrpServer::where('org_id', $orgId)
                ->where('deployment_mode', 'managed')
                ->where('cluster_id', $clusterId)
                ->where('id', '<>', $id)
                ->exists()) {
                throw new AppException(409, '该集群已经部署 FRPS；每个集群只保留一个 FRPS 实例');
            }
        }
        $this->assertServerPortsAndDashboard(
            $input,
            (string) $server->dashboard_password_ciphertext !== ''
        );
        $newNamespace = trim((string) ($input['namespace'] ?? '')) ?: 'galaxy-frp';
        $newBindPort = (int) ($input['bind_port'] ?? 7000);
        $newHttpPort = (int) ($input['vhost_http_port'] ?? 0);
        $newHttpsPort = (int) ($input['vhost_https_port'] ?? 0);
        $newDashboardPort = (int) ($input['dashboard_port'] ?? 0);
        foreach ($server->clients()->with('tunnels')->get() as $client) {
            foreach ($client->tunnels as $tunnel) {
                if ($tunnel->enabled
                    && (string) $tunnel->type === 'tcp'
                    && in_array((int) $tunnel->remote_port, [
                        $newBindPort,
                        $newHttpPort,
                        $newHttpsPort,
                        $newDashboardPort,
                    ], true)) {
                    throw new AppException(409, sprintf(
                        '入口端口 %d 已被规则「%s」使用',
                        (int) $tunnel->remote_port,
                        (string) $tunnel->title
                    ));
                }
            }
        }
        if (((string) $server->deployment_mode !== $mode
                || (int) $server->cluster_id !== (int) $clusterId
                || (string) $server->namespace !== $newNamespace)
            && (string) $server->runtime_name !== '') {
            $this->deployment->removeServer($server);
            $server->runtime_name = '';
            $server->runtime_ref = '';
            foreach ($server->clients as $client) {
                $client->runtime_name = '';
                $client->runtime_ref = '';
                $client->runtime_status = 'pending_sync';
                $client->updated_at = time();
                $client->save();
            }
        }
        $server->title = trim((string) $input['title']);
        $server->deployment_mode = $mode;
        $server->cluster_id = $clusterId;
        $server->namespace = $newNamespace;
        $server->image = trim((string) ($input['image'] ?? '')) ?: 'fatedier/frps:v0.69.0';
        $server->advertise_host = trim((string) $input['advertise_host']);
        $server->bind_port = $newBindPort;
        $server->vhost_http_port = $newHttpPort;
        $server->vhost_https_port = $newHttpsPort;
        $server->dashboard_port = $newDashboardPort;
        $server->dashboard_user = $newDashboardPort > 0
            ? trim((string) ($input['dashboard_user'] ?? ''))
            : '';
        if ($newDashboardPort < 1) {
            $server->dashboard_password_ciphertext = null;
        } elseif (trim((string) ($input['dashboard_password'] ?? '')) !== '') {
            $server->dashboard_password_ciphertext = $this->cipher->encryptFast(
                trim((string) $input['dashboard_password'])
            );
        }
        if (trim((string) ($input['auth_token'] ?? '')) !== '') {
            $server->auth_token_ciphertext = $this->cipher->encryptFast(trim((string) $input['auth_token']));
        }
        if ($mode === 'external') {
            $server->runtime_name = '';
            $server->runtime_ref = '';
            $server->runtime_status = 'external';
            $server->last_error = '';
            $server->updated_at = time();
            $server->save();
            foreach ($server->clients as $client) {
                $client->runtime_status = 'pending_sync';
                $client->updated_at = time();
                $client->save();
            }
        } else {
            $this->markServerPending($server);
        }
        return $server;
    }

    public function createClient(int $uid, int $orgId, array $input): FrpClient
    {
        $server = $this->server($orgId, (int) $input['server_id']);
        $mode = $this->clientMode($input);
        $clusterId = $mode === 'managed' ? (int) ($input['cluster_id'] ?? 0) : null;
        if ($mode === 'managed') {
            $this->cluster($orgId, (int) $clusterId);
        }
        $now = time();
        $client = FrpClient::create([
            'org_id' => $orgId,
            'server_id' => (int) $server->id,
            'title' => trim((string) $input['title']),
            'deployment_mode' => $mode,
            'management_mode' => (string) ($input['management_mode'] ?? 'manual') === 'automatic'
                ? 'automatic'
                : 'manual',
            'cluster_id' => $clusterId,
            'namespace' => trim((string) ($input['namespace'] ?? '')) ?: 'galaxy-frp',
            'image' => trim((string) ($input['image'] ?? '')) ?: 'fatedier/frpc:v0.69.0',
            'frp_user' => $this->slug((string) ($input['frp_user'] ?? '')),
            'transport_pool_count' => max(1, (int) ($input['transport_pool_count'] ?? 5)),
            'runtime_status' => $mode === 'external' ? 'external' : 'not_deployed',
            'creator' => $uid,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->markServerPending($server);
        return $client;
    }

    public function updateClient(int $orgId, int $id, array $input): FrpClient
    {
        $client = $this->client($orgId, $id);
        $mode = $this->clientMode($input);
        $clusterId = $mode === 'managed' ? (int) ($input['cluster_id'] ?? 0) : null;
        if ($mode === 'managed') {
            $this->cluster($orgId, (int) $clusterId);
        }
        if (((string) $client->deployment_mode !== $mode
                || (int) $client->cluster_id !== (int) $clusterId
                || (string) $client->namespace !== (trim((string) ($input['namespace'] ?? '')) ?: 'galaxy-frp'))
            && (string) $client->runtime_name !== '') {
            $this->deployment->removeClient($client);
            $client->runtime_name = '';
            $client->runtime_ref = '';
        }
        $client->title = trim((string) $input['title']);
        $client->deployment_mode = $mode;
        $client->cluster_id = $clusterId;
        $client->namespace = trim((string) ($input['namespace'] ?? '')) ?: 'galaxy-frp';
        $client->image = trim((string) ($input['image'] ?? '')) ?: 'fatedier/frpc:v0.69.0';
        $client->frp_user = $this->slug((string) ($input['frp_user'] ?? ''));
        $existingPoolCount = (int) $client->transport_pool_count > 0
            ? (int) $client->transport_pool_count
            : 5;
        $client->transport_pool_count = max(1, (int) ($input['transport_pool_count'] ?? $existingPoolCount));
        $client->runtime_status = $mode === 'external' ? 'external' : 'pending_sync';
        if ($mode === 'external') {
            $client->runtime_name = '';
            $client->runtime_ref = '';
            $client->last_error = '';
        }
        $client->updated_at = time();
        $client->save();
        $this->markServerPending($client->server);
        return $client;
    }

    public function saveTunnel(
        int $orgId,
        ?int $id,
        array $input,
        bool $allowClientMove = false
    ): FrpTunnel
    {
        $client = $this->client($orgId, (int) $input['client_id']);
        $type = (string) $input['type'];
        $remotePort = in_array($type, ['tcp', 'udp'], true) ? (int) ($input['remote_port'] ?? 0) : 0;
        $domains = array_values(array_unique(array_filter(array_map(
            static fn (mixed $domain): string => strtolower(trim((string) $domain)),
            (array) ($input['custom_domains'] ?? [])
        ))));
        if (in_array($type, ['tcp', 'udp'], true)) {
            $server = $client->server;
            if ($type === 'tcp' && in_array($remotePort, [
                (int) $server->bind_port,
                (int) $server->vhost_http_port,
                (int) $server->vhost_https_port,
                (int) $server->dashboard_port,
            ], true)) {
                throw new AppException(409, '外部端口与 FRPS 控制、Web 入口或 Dashboard 端口冲突');
            }
            $conflict = FrpTunnel::where('org_id', $orgId)
                ->where('type', $type)->where('remote_port', $remotePort)
                ->whereIn('client_id', FrpClient::where('server_id', (int) $client->server_id)->pluck('id'));
            if ($id !== null) {
                $conflict->where('id', '<>', $id);
            }
            if ($conflict->exists()) {
                throw new AppException(409, sprintf('FRPS 上的 %s/%d 入口端口已被其他规则使用', strtoupper($type), $remotePort));
            }
        } else {
            if ($domains === []) {
                throw new AppException(422, 'HTTP/HTTPS 穿透至少需要一个域名');
            }
            $server = $client->server;
            $vhostPort = $type === 'http' ? (int) $server->vhost_http_port : (int) $server->vhost_https_port;
            if ($vhostPort < 1) {
                throw new AppException(422, sprintf('FRPS 尚未设置 %s 入口端口', strtoupper($type)));
            }
            $otherTunnels = FrpTunnel::where('org_id', $orgId)
                ->where('type', $type)
                ->whereIn('client_id', FrpClient::where('server_id', (int) $client->server_id)->pluck('id'))
                ->when($id !== null, static fn ($query) => $query->where('id', '<>', $id))
                ->get();
            foreach ($otherTunnels as $other) {
                if (array_intersect($domains, (array) $other->custom_domains) !== []) {
                    throw new AppException(409, '同一个 FRPS 上存在重复的域名入口');
                }
            }
        }
        $tunnel = $id === null ? new FrpTunnel() : $this->tunnel($orgId, $id);
        if ($id !== null && ! $allowClientMove
            && (int) $tunnel->client_id !== (int) $client->id) {
            throw new AppException(409, '不能把现有规则移动到另一个 FRP 客户端');
        }
        $now = time();
        $tunnel->fill([
            'org_id' => $orgId,
            'client_id' => (int) $client->id,
            'title' => trim((string) $input['title']),
            'proxy_name' => $this->slug((string) $input['proxy_name']),
            'type' => $type,
            'local_host' => trim((string) $input['local_host']),
            'source_service_name' => trim((string) ($input['source_service_name'] ?? '')),
            'source_service_namespace' => trim((string) ($input['source_service_namespace'] ?? '')),
            'source_service_ref' => trim((string) ($input['source_service_ref'] ?? '')),
            'local_port' => (int) $input['local_port'],
            'remote_port' => $remotePort,
            'custom_domains' => $domains,
            'locations' => array_values(array_filter(array_map('trim', (array) ($input['locations'] ?? [])))),
            'host_header_rewrite' => trim((string) ($input['host_header_rewrite'] ?? '')),
            'transport_encryption' => (bool) ($input['transport_encryption'] ?? true),
            'transport_compression' => (bool) ($input['transport_compression'] ?? false),
            'bandwidth_limit' => trim((string) ($input['bandwidth_limit'] ?? '')),
            'enabled' => (bool) ($input['enabled'] ?? true),
            'updated_at' => $now,
        ]);
        if (! $tunnel->exists) {
            $tunnel->created_at = $now;
        }
        $tunnel->save();
        $client->runtime_status = 'pending_sync';
        $client->updated_at = $now;
        $client->save();
        $this->markServerPending($client->server);
        return $tunnel;
    }

    public function saveAutomaticTunnel(int $uid, int $orgId, ?int $id, array $input): FrpTunnel
    {
        $sourceClusterId = (int) $input['source_cluster_id'];
        $destinationClusterId = (int) ($input['destination_cluster_id'] ?? 0);
        $destinationServerId = (int) ($input['destination_server_id'] ?? 0);
        if (($destinationClusterId > 0) === ($destinationServerId > 0)) {
            throw new AppException(422, '映射入口必须选择一个集群或一个外部 FRPS');
        }
        $protocol = strtolower(trim((string) ($input['protocol'] ?? 'tcp')));
        if (! in_array($protocol, ['tcp', 'udp'], true)) {
            throw new AppException(422, '自动网络穿透仅支持 TCP 或 UDP');
        }
        if ($destinationClusterId > 0 && $sourceClusterId === $destinationClusterId) {
            throw new AppException(422, '来源和映射集群相同，无需创建网络穿透');
        }
        $sourceCluster = $this->cluster($orgId, $sourceClusterId);
        $destinationCluster = $destinationClusterId > 0
            ? $this->cluster($orgId, $destinationClusterId)
            : null;
        $selectedExternalServer = $destinationServerId > 0
            ? $this->server($orgId, $destinationServerId)
            : null;
        if ($selectedExternalServer instanceof FrpServer
            && (string) $selectedExternalServer->deployment_mode !== 'external') {
            throw new AppException(422, '指定的 FRPS 不是外部资源；托管 FRPS 请通过映射集群选择');
        }
        $service = $this->catalog->resolveService(
            $orgId,
            $sourceClusterId,
            trim((string) ($input['source_service_ref'] ?? '')),
            trim((string) ($input['source_service_name'] ?? '')),
            trim((string) ($input['source_service_namespace'] ?? ''))
        );

        $oldClient = $id === null ? null : $this->tunnel($orgId, $id)->client;
        [$server, $client] = Db::transaction(function () use (
            $uid,
            $orgId,
            $sourceCluster,
            $destinationCluster,
            $selectedExternalServer,
            $input
        ): array {
            $clusterIds = [(int) $sourceCluster->id];
            if ($destinationCluster instanceof Cluster) {
                $clusterIds[] = (int) $destinationCluster->id;
            }
            Cluster::where('org_id', $orgId)
                ->whereIn('id', $clusterIds)
                ->orderBy('id')->lockForUpdate()->get(['id']);

            if ($selectedExternalServer instanceof FrpServer) {
                $server = FrpServer::where('org_id', $orgId)
                    ->where('id', (int) $selectedExternalServer->id)
                    ->lockForUpdate()
                    ->firstOrFail();
            } else {
                $server = FrpServer::where('org_id', $orgId)
                    ->where('deployment_mode', 'managed')
                    ->where('cluster_id', (int) $destinationCluster?->id)
                    ->orderByRaw("management_mode = 'automatic' DESC")
                    ->orderBy('id')
                    ->first();
            }
            if (! $server instanceof FrpServer && $destinationCluster instanceof Cluster) {
                $server = $this->createServer($uid, $orgId, [
                    'title' => 'Galaxy 自动入口 #' . (int) $destinationCluster->id,
                    'deployment_mode' => 'managed',
                    'management_mode' => 'automatic',
                    'cluster_id' => (int) $destinationCluster->id,
                    'namespace' => 'galaxy-frp',
                    'image' => 'fatedier/frps:v0.69.0',
                    'advertise_host' => $this->automaticAdvertiseHost($destinationCluster),
                    'bind_port' => (int) ($input['frps_bind_port'] ?? 7000),
                    'dashboard_port' => (int) ($input['frps_dashboard_port'] ?? 7500),
                    'dashboard_user' => 'galaxy',
                    'dashboard_password' => $this->randomSecret(),
                ]);
            }

            $client = FrpClient::where('server_id', (int) $server->id)
                ->where('deployment_mode', 'managed')
                ->where('cluster_id', (int) $sourceCluster->id)
                ->orderByRaw("management_mode = 'automatic' DESC")
                ->orderBy('id')
                ->first();
            if (! $client instanceof FrpClient) {
                $destinationLabel = $destinationCluster instanceof Cluster
                    ? (string) $destinationCluster->title
                    : (string) $server->title;
                $client = $this->createClient($uid, $orgId, [
                    'server_id' => (int) $server->id,
                    'title' => sprintf(
                        '%s → %s',
                        (string) $sourceCluster->title,
                        $destinationLabel
                    ),
                    'deployment_mode' => 'managed',
                    'management_mode' => 'automatic',
                    'cluster_id' => (int) $sourceCluster->id,
                    'namespace' => 'galaxy-frp',
                    'image' => 'fatedier/frpc:v0.69.0',
                    'frp_user' => sprintf(
                        'c%d-to-s%d',
                        (int) $sourceCluster->id,
                        (int) $server->id
                    ),
                    'transport_pool_count' => (int) ($input['pool_count'] ?? 5),
                ]);
            }

            return [$server, $client];
        });

        $existingPoolCount = (int) $client->transport_pool_count > 0
            ? (int) $client->transport_pool_count
            : 5;
        $requestedPoolCount = max(1, (int) ($input['pool_count'] ?? $existingPoolCount));
        if ($requestedPoolCount !== (int) $client->transport_pool_count) {
            $client->transport_pool_count = $requestedPoolCount;
            $client->runtime_status = 'pending_sync';
            $client->updated_at = time();
            $client->save();
        }

        $requestedBindPort = (int) ($input['frps_bind_port'] ?? $server->bind_port);
        $requestedDashboardPort = (int) ($input['frps_dashboard_port'] ?? $server->dashboard_port);
        if ($requestedBindPort !== (int) $server->bind_port
            || $requestedDashboardPort !== (int) $server->dashboard_port) {
            if ((string) $server->management_mode !== 'automatic') {
                throw new AppException(
                    409,
                    '目标集群使用手动管理的 FRPS；请通过 FRP 资源管理修改控制端口和管理端口'
                );
            }
            $server = $this->updateServer($orgId, (int) $server->id, [
                'title' => (string) $server->title,
                'deployment_mode' => (string) $server->deployment_mode,
                'cluster_id' => (int) $server->cluster_id,
                'namespace' => (string) $server->namespace,
                'image' => (string) $server->image,
                'advertise_host' => (string) $server->advertise_host,
                'bind_port' => $requestedBindPort,
                'vhost_http_port' => (int) $server->vhost_http_port,
                'vhost_https_port' => (int) $server->vhost_https_port,
                'dashboard_port' => $requestedDashboardPort,
                'dashboard_user' => (string) $server->dashboard_user,
                'dashboard_password' => '',
                'auth_token' => '',
            ]);
        }

        $remotePort = (int) $input['destination_port'];
        $sourcePort = (int) $input['source_port'];
        $destinationLabel = $destinationCluster instanceof Cluster
            ? (string) $destinationCluster->title
            : (string) $server->title;
        $tunnel = $this->saveTunnel($orgId, $id, [
            'client_id' => (int) $client->id,
            'title' => trim((string) ($input['title'] ?? '')) ?: sprintf(
                '%s:%d → %s:%d',
                (string) $service['name'],
                $sourcePort,
                $destinationLabel,
                $remotePort
            ),
            'proxy_name' => $id === null
                ? sprintf('auto-c%d-s%d-p%d-%s', $sourceClusterId, (int) $server->id, $remotePort, substr(bin2hex(random_bytes(4)), 0, 8))
                : (string) $this->tunnel($orgId, $id)->proxy_name,
            'type' => $protocol,
            'local_host' => (string) $service['local_host'],
            'source_service_name' => (string) $service['name'],
            'source_service_namespace' => (string) $service['namespace'],
            'source_service_ref' => (string) $service['ref'],
            'local_port' => $sourcePort,
            'remote_port' => $remotePort,
            'transport_encryption' => (bool) ($input['use_encryption'] ?? true),
            'transport_compression' => (bool) ($input['use_compression'] ?? true),
            'bandwidth_limit' => trim((string) ($input['bandwidth_limit'] ?? '2MB')),
            'enabled' => (bool) ($input['enabled'] ?? true),
        ], true);

        $this->sync($orgId, (int) $server->id);
        if ($oldClient instanceof FrpClient
            && (int) $oldClient->id !== (int) $client->id
            && (string) $oldClient->management_mode === 'automatic'
            && ! $oldClient->tunnels()->exists()) {
            $oldServer = $oldClient->server;
            $this->deleteClient($orgId, (int) $oldClient->id);
            if ((int) $oldServer->id !== (int) $server->id) {
                $this->sync($orgId, (int) $oldServer->id);
            }
        }

        return $tunnel;
    }

    public function sync(int $orgId, int $serverId): void
    {
        $server = $this->server($orgId, $serverId);
        try {
            $this->reconcileAutomaticServiceReferences($server);
            $server->runtime_status = 'deploying';
            $server->last_error = '';
            $server->updated_at = time();
            $server->save();
            $this->deployment->sync($server);
        } catch (Throwable $e) {
            $server->runtime_status = 'error';
            $server->last_error = mb_substr($e->getMessage(), 0, 2000);
            $server->updated_at = time();
            $server->save();
            throw $e;
        }
    }

    public function syncAll(int $orgId, ?int $clusterId = null): array
    {
        $query = FrpServer::where('org_id', $orgId);
        if ($clusterId !== null) {
            $clientServerIds = FrpClient::where('org_id', $orgId)
                ->where('cluster_id', $clusterId)
                ->pluck('server_id');
            $query->where(static function ($scope) use ($clusterId, $clientServerIds): void {
                $scope->where('cluster_id', $clusterId)
                    ->orWhereIn('id', $clientServerIds);
            });
        }
        $results = [];
        foreach ($query->orderBy('id')->pluck('id') as $serverId) {
            try {
                $this->sync($orgId, (int) $serverId);
                $results[] = ['server_id' => (int) $serverId, 'status' => 'running', 'error' => ''];
            } catch (Throwable $e) {
                $results[] = [
                    'server_id' => (int) $serverId,
                    'status' => 'error',
                    'error' => mb_substr($e->getMessage(), 0, 500),
                ];
            }
        }

        return $results;
    }

    public function deleteTunnel(int $orgId, int $id): void
    {
        $tunnel = $this->tunnel($orgId, $id);
        $client = $tunnel->client;
        $server = $client->server;
        $automatic = (string) $tunnel->source_service_ref !== ''
            || (string) $client->management_mode === 'automatic';
        $tunnel->delete();
        if ($automatic
            && (string) $client->management_mode === 'automatic'
            && ! $client->tunnels()->exists()) {
            $this->deployment->removeClient($client);
            $client->delete();
        } else {
            $client->runtime_status = 'pending_sync';
            $client->updated_at = time();
            $client->save();
        }
        $this->markServerPending($server);
        if ($automatic) {
            $this->sync($orgId, (int) $server->id);
        }
    }

    public function deleteClient(int $orgId, int $id): void
    {
        $client = $this->client($orgId, $id);
        $server = $client->server;
        $this->deployment->removeClient($client);
        Db::transaction(function () use ($client): void {
            FrpTunnel::where('client_id', (int) $client->id)->delete();
            $client->delete();
        });
        $this->markServerPending($server);
    }

    public function deleteServer(int $orgId, int $id): void
    {
        $server = $this->server($orgId, $id);
        $this->deployment->removeServer($server);
        Db::transaction(function () use ($server): void {
            $clientIds = FrpClient::where('server_id', (int) $server->id)->pluck('id');
            FrpTunnel::whereIn('client_id', $clientIds)->delete();
            FrpClient::where('server_id', (int) $server->id)->delete();
            $server->delete();
        });
    }

    private function markServerPending(FrpServer $server): void
    {
        if ((string) $server->deployment_mode === 'external') {
            $server->runtime_status = 'external';
            $server->updated_at = time();
            $server->save();
            return;
        }
        if ($server->runtime_status !== 'not_deployed') {
            $server->runtime_status = 'pending_sync';
        }
        $server->updated_at = time();
        $server->save();
    }

    private function reconcileAutomaticServiceReferences(FrpServer $server): void
    {
        $server->load(['clients.tunnels']);
        foreach ($server->clients as $client) {
            foreach ($client->tunnels as $tunnel) {
                if ((string) $tunnel->source_service_name === '') {
                    continue;
                }
                $service = $this->catalog->resolveService(
                    (int) $server->org_id,
                    (int) $client->cluster_id,
                    (string) $tunnel->source_service_ref,
                    (string) $tunnel->source_service_name,
                    (string) $tunnel->source_service_namespace
                );
                $newRef = (string) $service['ref'];
                $newHost = (string) $service['local_host'];
                if ((string) $tunnel->source_service_ref !== $newRef
                    || (string) $tunnel->local_host !== $newHost) {
                    $tunnel->source_service_ref = $newRef;
                    $tunnel->local_host = $newHost;
                    $tunnel->updated_at = time();
                    $tunnel->save();
                }
            }
        }
    }

    private function serverArray(FrpServer $server): array
    {
        $data = $server->toArray();
        unset($data['auth_token_ciphertext']);
        unset($data['dashboard_password_ciphertext']);
        $data['token_configured'] = (string) $server->auth_token_ciphertext !== '';
        $data['dashboard_password_configured'] =
            (int) $server->dashboard_port > 0
            && (string) $server->dashboard_password_ciphertext !== '';
        $data['orchestrator_type'] = (string) ($server->cluster?->orchestrator_type ?? '');
        $data['cluster_title'] = (string) ($server->cluster?->title ?? '');
        $data['clients'] = $server->clients->map(function (FrpClient $client): array {
            $row = $client->toArray();
            $row['orchestrator_type'] = (string) ($client->cluster?->orchestrator_type ?? '');
            $row['cluster_title'] = (string) ($client->cluster?->title ?? '');
            return $row;
        })->all();
        return $data;
    }

    private function cluster(int $orgId, int $id): Cluster
    {
        $cluster = Cluster::where('org_id', $orgId)->where('id', $id)
            ->whereIn('orchestrator_type', Cluster::knownOrchestrators())->first();
        if ($cluster === null) {
            throw new AppException(404, '集群不存在或不支持 FRP');
        }
        return $cluster;
    }

    private function server(int $orgId, int $id): FrpServer
    {
        $server = FrpServer::where('org_id', $orgId)->where('id', $id)->first();
        if ($server === null) {
            throw new AppException(404, 'FRP 服务端不存在');
        }
        return $server;
    }

    private function client(int $orgId, int $id): FrpClient
    {
        $client = FrpClient::where('org_id', $orgId)->where('id', $id)->with('server')->first();
        if ($client === null) {
            throw new AppException(404, 'FRP 客户端不存在');
        }
        return $client;
    }

    private function tunnel(int $orgId, int $id): FrpTunnel
    {
        $tunnel = FrpTunnel::where('org_id', $orgId)->where('id', $id)->with('client.server')->first();
        if ($tunnel === null) {
            throw new AppException(404, '穿透规则不存在');
        }
        return $tunnel;
    }

    private function slug(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9_-]+/', '-', $value) ?: '';
        return trim($value, '-_');
    }

    private function automaticAdvertiseHost(Cluster $cluster): string
    {
        $extra = (array) ($cluster->extra ?? []);
        $configured = trim((string) ($extra['frp_advertise_host'] ?? ''));
        if ($configured !== '') {
            return $configured;
        }
        $resolved = trim((string) ($cluster->resolve ?? ''));
        if ($resolved !== '') {
            return preg_replace('#^https?://#i', '', $resolved) ?: $resolved;
        }
        if ((string) $cluster->orchestrator_type === Cluster::ORCHESTRATOR_DOCKER_SWARM) {
            $manager = ClusterAgentNode::where('cluster_id', (int) $cluster->id)
                ->where('role', 'manager')
                ->orderByRaw("status = 'online' DESC")
                ->orderByDesc('last_seen_at')
                ->first();
            $nodeAddress = trim((string) ($manager?->node_addr ?? ''));
            if ($nodeAddress !== '') {
                return $nodeAddress;
            }
            foreach ((array) ($manager?->capabilities['join_addresses'] ?? []) as $address) {
                if (filter_var($address, FILTER_VALIDATE_IP) !== false) {
                    return (string) $address;
                }
            }
        }
        $endpointHost = parse_url((string) $cluster->endpoint, PHP_URL_HOST);
        if (is_string($endpointHost) && $endpointHost !== '') {
            return $endpointHost;
        }

        throw new AppException(422, sprintf(
            '无法自动确定集群「%s」的 FRPS 对外地址，请先在集群网络设置中配置可达地址',
            (string) $cluster->title
        ));
    }

    private function randomSecret(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function serverMode(array $input): string
    {
        $mode = strtolower(trim((string) ($input['deployment_mode'] ?? 'managed')));
        if (! in_array($mode, ['managed', 'external'], true)) {
            throw new AppException(422, 'FRPS 部署模式无效');
        }
        if ($mode === 'managed' && (int) ($input['cluster_id'] ?? 0) < 1) {
            throw new AppException(422, 'Galaxy 托管 FRPS 必须选择运行集群');
        }
        return $mode;
    }

    private function clientMode(array $input): string
    {
        $mode = strtolower(trim((string) ($input['deployment_mode'] ?? 'managed')));
        if ($mode !== 'managed') {
            throw new AppException(422, '网络穿透只管理 Galaxy 集群内运行的 FRPC');
        }
        if ((int) ($input['cluster_id'] ?? 0) < 1) {
            throw new AppException(422, 'Galaxy 托管 FRPC 必须选择运行集群');
        }
        return 'managed';
    }

    private function assertServerPortsAndDashboard(array $input, bool $passwordConfigured): void
    {
        $ports = array_filter([
            '控制端口' => (int) ($input['bind_port'] ?? 7000),
            'HTTP 入口' => (int) ($input['vhost_http_port'] ?? 0),
            'HTTPS 入口' => (int) ($input['vhost_https_port'] ?? 0),
            'Dashboard' => (int) ($input['dashboard_port'] ?? 7500),
        ], static fn (int $port): bool => $port > 0);
        if (count($ports) !== count(array_unique(array_values($ports)))) {
            throw new AppException(422, 'FRPS 控制、Web 入口和 Dashboard 端口不能重复');
        }
        if ((int) ($input['dashboard_port'] ?? 7500) < 1) {
            return;
        }
        if (trim((string) ($input['dashboard_user'] ?? '')) === '') {
            throw new AppException(422, '启用 FRPS Dashboard 必须设置管理用户名');
        }
        if (! $passwordConfigured
            && trim((string) ($input['dashboard_password'] ?? '')) === '') {
            throw new AppException(422, '启用 FRPS Dashboard 必须设置管理密码');
        }
    }
}

<?php

namespace App\Services\Workspace;

use App\Exception\AppException;
use App\Model\ProjectRoute;
use App\Model\Workspace;
use App\Model\WorkspaceProjectRepository;
use App\Model\Cluster;
use App\Model\ClusterWebGateway;
use App\Model\GatewayVhost;
use App\Model\OrgMember;
use App\Model\GroupResourceGrant;
use App\Model\Group;
use App\Model\GroupMember;
use App\Services\Docker\SwarmApiClient;
use App\Services\Docker\SwarmTerminalService;
use App\Services\Encrypt\CredentialCipher;
use App\Services\RegistryService;
use App\Services\ContainerImageMappingService;
use App\Services\Gateway\GatewayVhostService;
use GuzzleHttp\Client;
use Hyperf\DbConnection\Db;
use Hyperf\Context\Context;
use Swoole\Coroutine;
use Throwable;

class WorkspaceService
{
    public function __construct(
        private SwarmApiClient $docker,
        private SwarmTerminalService $terminal,
        private CredentialCipher $cipher,
        private RegistryService $registries,
        private GatewayVhostService $gatewayVhosts,
        private ContainerImageMappingService $imageMappings
    ) {}

    public function list(int $uid, int $orgId): array
    {
        $roles = (array) Context::get('roles', []);
        $isOrgManager = in_array((int) ($roles[0] ?? 0), [
            OrgMember::ROLE_MANAGER,
        ], true);
        $groupIds = $isOrgManager
            ? Group::where('org_id', $orgId)->pluck('id')->map(static fn ($id): int => (int) $id)->all()
            : GroupMember::where('org_id', $orgId)->where('uid', $uid)
                ->pluck('group_id')->map(static fn ($id): int => (int) $id)->all();
        $groups = Group::where('org_id', $orgId)->whereIn('id', $groupIds)
            ->select('id', 'title', 'alias')->orderBy('title')->get();
        $workspaces = Workspace::where('org_id', $orgId)->where('uid', $uid)
            ->whereIn('group_id', $groupIds)->with('group')->orderByDesc('updated_at')->get()
            ->map(fn (Workspace $workspace): array => $this->present($workspace))->all();
        return ['groups' => $groups, 'workspaces' => $workspaces];
    }

    public function options(int $orgId, int $groupId): array
    {
        $group = Group::where('org_id', $orgId)->where('id', $groupId)
            ->first(['id', 'title', 'alias']);
        if ($group === null) {
            throw new AppException(404, '项目组不存在');
        }
        $clusterIds = GroupResourceGrant::clusterIds($orgId, $groupId);
        $clusters = Cluster::where('org_id', $orgId)
                ->whereIn('id', $clusterIds)
                ->where('orchestrator_type', Cluster::ORCHESTRATOR_DOCKER_SWARM)
                ->where('status', Cluster::STATUS_READY)
                ->select('id', 'title', 'status')
                ->orderBy('id')
                ->get();
        $gateways = ClusterWebGateway::where('org_id', $orgId)
            ->whereIn('cluster_id', $clusterIds)->where('status', ClusterWebGateway::STATUS_RUNNING)
            ->where('service_id', '<>', '')->where('workspace_base_domain', '<>', '')
            ->where('workspace_certificate_id', '>', 0)
            ->get(['cluster_id', 'workspace_base_domain', 'workspace_certificate_id'])->keyBy('cluster_id');
        foreach ($clusters as $cluster) {
            $gateway = $gateways->get((int) $cluster->id);
            $cluster->setAttribute('workspace_gateway_ready', $gateway !== null);
            $cluster->setAttribute('workspace_base_domain', (string) ($gateway?->workspace_base_domain ?? ''));
        }
        return [
            'group' => $group,
            'clusters' => $clusters,
            'defaults' => [
                'image' => (string) config('workspace.image'),
                'cpu' => (int) config('workspace.default_cpu'),
                'memory' => (int) config('workspace.default_memory'),
                'target_port' => (int) config('workspace.target_port'),
                'mode' => Workspace::MODE_WEB_IDE,
                'branch' => 'main',
                'workdir' => (string) config('workspace.workspace_path'),
            ],
            'modes' => [
                ['value' => Workspace::MODE_WEB_IDE, 'label' => 'Web IDE'],
                ['value' => Workspace::MODE_TERMINAL, 'label' => 'CLI 终端'],
            ],
            'tools' => [
                'git', 'git-lfs', 'nodejs-22', 'npm', 'corepack', 'python', 'python3', 'pip', 'venv',
                'build-essential', 'ripgrep', 'tmux', 'claude', 'codex', 'codebuddy',
            ],
        ];
    }

    public function profile(int $uid, int $orgId, int $groupId, bool $refresh = true): array
    {
        /** @var Workspace|null $workspace */
        $workspace = $this->workspace($uid, $orgId, $groupId);
        if ($workspace === null) {
            return [];
        }
        if ($refresh && $workspace->runtime_ref !== '') {
            $this->refresh($workspace);
        }
        return $this->present($workspace);
    }

    public function create(int $uid, int $orgId, int $groupId, array $input): array
    {
        if (! GroupResourceGrant::canUseCluster($orgId, $groupId, (int) $input['cluster_id'])) {
            throw new AppException(403, '当前项目组未获授权使用该 Workspace 集群');
        }
        if ($this->workspace($uid, $orgId, $groupId) !== null) {
            throw new AppException(409, '当前用户已经在该项目组创建了 Workspace');
        }
        /** @var Cluster|null $cluster */
        $cluster = Cluster::where('id', (int) $input['cluster_id'])
            ->where('org_id', $orgId)
            ->where('orchestrator_type', Cluster::ORCHESTRATOR_DOCKER_SWARM)
            ->where('status', Cluster::STATUS_READY)
            ->first();
        if ($cluster === null) {
            throw new AppException(409, 'Docker Swarm 集群不存在或当前不在线');
        }
        $gateway = null;
        if (($input['mode'] ?? Workspace::MODE_WEB_IDE) === Workspace::MODE_WEB_IDE) {
            $gateway = ClusterWebGateway::where('org_id', $orgId)->where('cluster_id', (int) $cluster->id)
                ->where('status', ClusterWebGateway::STATUS_RUNNING)->where('service_id', '<>', '')
                ->where('workspace_base_domain', '<>', '')->where('workspace_certificate_id', '>', 0)->first();
            if ($gateway === null) {
                throw new AppException(409, '该集群尚未配置可用的 Web 网关 Workspace 基础域名');
            }
        }
        $image = trim((string) ($input['image'] ?? config('workspace.image')));
        if ($image === '' || strlen($image) > 1024 || preg_match('/[\x00-\x20]/', $image)) {
            throw new AppException(422, 'Workspace 镜像格式不合法');
        }
        $mode = (string) ($input['mode'] ?? Workspace::MODE_WEB_IDE);
        if (! in_array($mode, [Workspace::MODE_WEB_IDE, Workspace::MODE_TERMINAL], true)) {
            throw new AppException(422, 'Workspace 模式无效');
        }
        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '') {
            $title = '我的开发环境';
        }
        $publishedPort = 0;
        $url = '';
        $cpu = max(100, min(8000, (int) ($input['cpu'] ?? config('workspace.default_cpu'))));
        $memory = max(256, min(32768, (int) ($input['memory'] ?? config('workspace.default_memory'))));
        $password = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
        $targetPort = (int) config('workspace.target_port');
        $volumePath = (string) config('workspace.volume_path');
        $workspacePath = (string) config('workspace.workspace_path');
        $now = time();
        $workspace = Db::transaction(function () use (
            $uid, $orgId, $groupId, $title, $cluster, $mode,
            $workspacePath, $image, $url, $password, $cpu, $memory, $targetPort, $now
        ): Workspace {
            if (GroupResourceGrant::where('org_id', $orgId)->where('group_id', $groupId)
                ->where('resource_type', GroupResourceGrant::TYPE_CLUSTER)
                ->where('resource_id', (int) $cluster->id)->lockForUpdate()->first(['id']) === null) {
                throw new AppException(403, '当前项目组已无权使用该 Workspace 集群');
            }
            if (Workspace::where('uid', $uid)->where('org_id', $orgId)
                ->where('group_id', $groupId)->lockForUpdate()->exists()) {
                throw new AppException(409, '当前用户已经在该项目组创建了 Workspace');
            }
            return Workspace::create([
                'org_id' => $orgId, 'group_id' => $groupId, 'uid' => $uid,
                'title' => $title, 'cluster_id' => (int) $cluster->id,
                'mode' => $mode, 'runtime_ref' => '', 'volume_name' => '',
                'image' => $image, 'url' => $url, 'published_port' => 0, 'gateway_vhost_id' => 0,
                'access_secret' => $mode === Workspace::MODE_WEB_IDE ? $this->cipher->encrypt($password) : null,
                'status' => Workspace::STATUS_STARTING,
                'spec' => [
                    'cpu' => $cpu, 'memory' => $memory, 'target_port' => $targetPort,
                    'workspace_path' => $workspacePath, 'ide_engine' => 'openvscode-server',
                    'tools' => [
                        'git', 'git-lfs', 'nodejs-22', 'npm', 'corepack', 'python', 'python3', 'pip', 'venv',
                        'build-essential', 'ripgrep', 'tmux', 'claude', 'codex', 'codebuddy',
                    ],
                ],
                'created_at' => $now, 'updated_at' => $now,
            ]);
        });
        $serviceName = 'galaxy-workspace-' . $workspace->id;
        $volumeName = $serviceName . '-data';
        $workspace->volume_name = $volumeName;
        if ($mode === Workspace::MODE_WEB_IDE) {
            $workspace->url = 'https://' . $this->workspaceHostname(
                (int) $workspace->cluster_id,
                (string) $gateway->workspace_base_domain,
                (int) $workspace->id
            );
        }
        $spec = (array) $workspace->spec;
        $spec['service_name'] = $serviceName;
        $workspace->spec = $spec;
        $workspace->updated_at = time();
        $workspace->save();
        $runtimeImage = $this->imageMappings->resolve($cluster, $image);
        try {
            $this->docker->withCluster($cluster, function (Client $client, SwarmApiClient $api) use (
                $workspace, $serviceName, $volumeName, $runtimeImage, $password,
                $cpu, $memory, $targetPort, $volumePath, $workspacePath, $mode
            ): void {
                $info = $api->request($client, 'GET', '/info');
                $nodeId = (string) ($info['Swarm']['NodeID'] ?? '');
                if ($nodeId === '') {
                    throw new AppException(422, '目标 Docker Engine 不是 Swarm Manager');
                }
                $api->request($client, 'POST', '/volumes/create', ['json' => [
                    'Name' => $volumeName,
                    'Labels' => $this->labels($workspace),
                ]]);
                $volume = $api->request($client, 'GET', '/volumes/' . rawurlencode($volumeName));
                $this->assertWorkspaceLabels($workspace, (array) ($volume['Labels'] ?? []));
                $secretRefs = [];
                if ($mode === Workspace::MODE_WEB_IDE) {
                    $secretName = $serviceName . '-ide-token';
                    $secret = $api->request($client, 'POST', '/secrets/create', ['json' => [
                        'Name' => $secretName,
                        'Data' => base64_encode($password),
                        'Labels' => $this->labels($workspace),
                    ]]);
                    $secretId = (string) ($secret['ID'] ?? '');
                    if ($secretId === '') {
                        throw new AppException(502, 'Docker API 未返回 Workspace Secret ID');
                    }
                    $secretRefs['ide_token'] = ['id' => $secretId, 'name' => $secretName];
                    $this->saveSecretRefs($workspace, $secretRefs);
                }
                $containerSpec = [
                    'Image' => $runtimeImage,
                    'User' => 'root',
                    'Command' => ['/usr/local/bin/galaxy-workspace-entrypoint'],
                    'Mounts' => [[
                        'Type' => 'volume', 'Source' => $volumeName,
                        'Target' => $volumePath, 'ReadOnly' => false,
                    ]],
                    'Labels' => $this->labels($workspace),
                ];
                if ($mode === Workspace::MODE_WEB_IDE) {
                    $containerSpec['Args'] = [
                        '/home/.openvscode-server/bin/openvscode-server',
                        '--host', '0.0.0.0', '--port', (string) $targetPort,
                        '--connection-token-file', '/run/secrets/ide-token', $workspacePath,
                    ];
                    $containerSpec['Secrets'][] = [
                        'SecretID' => $secretRefs['ide_token']['id'],
                        'SecretName' => $secretRefs['ide_token']['name'],
                        'File' => ['Name' => 'ide-token', 'UID' => '1000', 'GID' => '1000', 'Mode' => 256],
                    ];
                } else {
                    $containerSpec['Args'] = ['/bin/bash', '-lc', 'exec sleep infinity'];
                }
                $serviceSpec = [
                    'Name' => $serviceName,
                    'Labels' => $this->labels($workspace),
                    'TaskTemplate' => [
                        'ContainerSpec' => $containerSpec,
                        'Resources' => ['Limits' => [
                            'NanoCPUs' => $cpu * 1000000,
                            'MemoryBytes' => $memory * 1024 * 1024,
                        ]],
                        'RestartPolicy' => ['Condition' => 'on-failure', 'Delay' => 5000000000],
                        'Placement' => ['Constraints' => ['node.id==' . $nodeId]],
                    ],
                    'Mode' => ['Replicated' => ['Replicas' => 1]],
                ];
                $created = $api->request($client, 'POST', '/services/create', [
                    'headers' => $this->registries->dockerAuthHeader((int) $workspace->org_id, $runtimeImage),
                    'json' => $serviceSpec,
                ]);
                $serviceId = (string) ($created['ID'] ?? '');
                if ($serviceId === '') {
                    throw new AppException(502, 'Docker API 未返回 Workspace Service ID');
                }
                $workspace->runtime_ref = $serviceId;
                $spec = (array) $workspace->spec;
                $spec['secret_refs'] = $secretRefs;
                $workspace->spec = $spec;
                $workspace->updated_at = time();
                $workspace->save();
            });
            if ($mode === Workspace::MODE_WEB_IDE) {
                $hostname = (string) parse_url((string) $workspace->url, PHP_URL_HOST);
                $vhost = $this->gatewayVhosts->create($orgId, (int) $cluster->id, $uid, [
                    'hostname' => $hostname,
                    'path_prefix' => '/',
                    'path_match' => 'prefix',
                    'target_service' => $serviceName,
                    'target_port' => $targetPort,
                    'entrypoint' => 'websecure',
                    'tls_enabled' => true,
                    'certificate_id' => (int) $gateway->workspace_certificate_id,
                    'https_redirect' => (bool) $gateway->workspace_https_redirect,
                    'enabled' => true,
                ]);
                $workspace->gateway_vhost_id = (int) $vhost->id;
                $workspace->updated_at = time();
                $workspace->save();
            }
            $this->refresh($workspace);
            return $this->present($workspace);
        } catch (Throwable $e) {
            $hostname = (string) parse_url((string) $workspace->url, PHP_URL_HOST);
            if ($hostname !== '') {
                $removed = GatewayVhost::where('org_id', $orgId)
                    ->where('cluster_id', (int) $cluster->id)
                    ->where('hostname', $hostname)
                    ->where('target_service', $serviceName)
                    ->delete();
                if ($removed > 0) {
                    try {
                        $this->gatewayVhosts->deploy($orgId, (int) $cluster->id);
                    } catch (Throwable) {
                        // Preserve the Workspace creation error. A later gateway
                        // reconciliation will remove any partially written route.
                    }
                }
            }
            $workspace->status = Workspace::STATUS_ERROR;
            $workspace->error = mb_substr($e->getMessage(), 0, 2000);
            $workspace->updated_at = time();
            $workspace->save();
            throw $e;
        }
    }

    public function setState(int $uid, int $orgId, int $groupId, string $action): array
    {
        $workspace = $this->requiredWorkspace($uid, $orgId, $groupId);
        if (! in_array($action, ['start', 'stop'], true)) {
            throw new AppException(422, 'Workspace 状态操作无效');
        }
        if ((string) $workspace->runtime_ref === '') {
            throw new AppException(409, 'Workspace 创建未完成，尚无可启停的 Swarm Service；请删除失败记录后重新创建');
        }
        $replicas = $action === 'start' ? 1 : 0;
        $cluster = $this->cluster($orgId, (int) $workspace->cluster_id);
        $this->docker->withCluster($cluster, function (Client $client, SwarmApiClient $api) use ($workspace, $replicas): void {
            $service = $api->request($client, 'GET', '/services/' . rawurlencode((string) $workspace->runtime_ref));
            $this->assertWorkspaceLabels($workspace, (array) ($service['Spec']['Labels'] ?? []));
            $version = (int) ($service['Version']['Index'] ?? 0);
            $spec = (array) ($service['Spec'] ?? []);
            $spec['Mode'] = ['Replicated' => ['Replicas' => $replicas]];
            $api->request($client, 'POST', '/services/' . rawurlencode((string) $workspace->runtime_ref) . '/update', [
                'query' => ['version' => $version, 'registryAuthFrom' => 'spec'], 'json' => $spec,
            ]);
        });
        $workspace->status = $action === 'start' ? Workspace::STATUS_STARTING : Workspace::STATUS_STOPPED;
        $workspace->error = null;
        $workspace->updated_at = time();
        $workspace->save();
        return $this->present($workspace);
    }

    public function delete(int $uid, int $orgId, int $groupId): void
    {
        $workspace = $this->requiredWorkspace($uid, $orgId, $groupId);
        $cluster = $this->cluster($orgId, (int) $workspace->cluster_id);
        if ((int) $workspace->gateway_vhost_id > 0) {
            $this->gatewayVhosts->delete(
                $orgId,
                (int) $workspace->cluster_id,
                (int) $workspace->gateway_vhost_id
            );
            $workspace->gateway_vhost_id = 0;
            $workspace->updated_at = time();
            $workspace->save();
        }
        $workspaceSpec = (array) $workspace->spec;
        $serviceName = (string) ($workspaceSpec['service_name'] ?? '');
        if ($serviceName !== '') {
            $orphanVhostIds = GatewayVhost::where('org_id', $orgId)
                ->where('cluster_id', (int) $workspace->cluster_id)
                ->where('target_service', $serviceName)
                ->pluck('id')->map(static fn ($id): int => (int) $id)->all();
            foreach ($orphanVhostIds as $orphanVhostId) {
                $this->gatewayVhosts->delete($orgId, (int) $workspace->cluster_id, $orphanVhostId);
            }
        }
        $this->docker->withCluster($cluster, function (Client $client, SwarmApiClient $api) use ($workspace): void {
            $services = $api->request($client, 'GET', '/services', ['query' => ['filters' => json_encode([
                'label' => ['com.codegalaxy.workspace.id=' . (string) $workspace->id],
            ], JSON_THROW_ON_ERROR)]]);
            foreach ($services as $service) {
                $this->assertWorkspaceLabels($workspace, (array) ($service['Spec']['Labels'] ?? []));
                $serviceId = (string) ($service['ID'] ?? '');
                if ($serviceId !== '') {
                    $this->removeDockerResourceWithRetry(
                        $client, $api, '/services/' . rawurlencode($serviceId), 1
                    );
                }
            }
            // Service removal is asynchronous. Docker acknowledges DELETE before
            // the Swarm task container has released its mounted volume.
            $this->waitForWorkspaceContainersRemoved($client, $api, $workspace);

            $volumes = $api->request($client, 'GET', '/volumes', ['query' => ['filters' => json_encode([
                'label' => ['com.codegalaxy.workspace.id=' . (string) $workspace->id],
            ], JSON_THROW_ON_ERROR)]]);
            foreach ((array) ($volumes['Volumes'] ?? []) as $volume) {
                $this->assertWorkspaceLabels($workspace, (array) ($volume['Labels'] ?? []));
                $volumeName = (string) ($volume['Name'] ?? '');
                if ($volumeName === '') {
                    continue;
                }
                $this->removeDockerResourceWithRetry(
                    $client, $api, '/volumes/' . rawurlencode($volumeName)
                );
            }

            $secrets = $api->request($client, 'GET', '/secrets', ['query' => ['filters' => json_encode([
                'label' => ['com.codegalaxy.workspace.id=' . (string) $workspace->id],
            ], JSON_THROW_ON_ERROR)]]);
            foreach ($secrets as $secret) {
                $this->assertWorkspaceLabels($workspace, (array) ($secret['Spec']['Labels'] ?? []));
                $secretId = (string) ($secret['ID'] ?? '');
                if ($secretId !== '') {
                    $this->removeDockerResourceWithRetry(
                        $client, $api, '/secrets/' . rawurlencode($secretId)
                    );
                }
            }
        });
        WorkspaceProjectRepository::where('workspace_id', (int) $workspace->id)->delete();
        $workspace->delete();
    }

    public function access(int $uid, int $orgId, int $groupId): array
    {
        $workspace = $this->requiredWorkspace($uid, $orgId, $groupId);
        if ($workspace->status !== Workspace::STATUS_RUNNING) {
            throw new AppException(409, 'Workspace 尚未运行');
        }
        if ($workspace->mode !== Workspace::MODE_WEB_IDE) {
            throw new AppException(409, '当前 Workspace 是 CLI 终端模式');
        }
        $token = $this->cipher->decrypt((string) $workspace->access_secret);
        return [
            'url' => (string) $workspace->url,
            'access_url' => $this->withQueryParameter((string) $workspace->url, 'tkn', $token),
        ];
    }

    public function terminal(int $uid, int $orgId, int $groupId): array
    {
        $workspace = $this->requiredWorkspace($uid, $orgId, $groupId);
        if ($workspace->status !== Workspace::STATUS_RUNNING) {
            throw new AppException(409, 'Workspace 尚未运行');
        }
        $cluster = $this->cluster($orgId, (int) $workspace->cluster_id);
        $target = $this->runningContainerTarget($cluster, $workspace);
        $cluster = clone $cluster;
        $cluster->endpoint = 'agent://' . (int) $cluster->id . '/' . $target['node_id'];
        return $this->terminal->issueTicket($uid, $orgId, $cluster, $target['container_id']);
    }

    private function refresh(Workspace $workspace): void
    {
        $cluster = $this->cluster((int) $workspace->org_id, (int) $workspace->cluster_id);
        try {
            $state = $this->docker->withCluster($cluster, function (Client $client, SwarmApiClient $api) use ($workspace): string {
                $service = $api->request(
                    $client,
                    'GET',
                    '/services/' . rawurlencode((string) $workspace->runtime_ref)
                );
                $this->assertWorkspaceLabels($workspace, (array) ($service['Spec']['Labels'] ?? []));
                $workspace->syncRuntimeLimits((array) ($service['Spec']['TaskTemplate']['Resources']['Limits'] ?? []));
                $tasks = $api->request($client, 'GET', '/tasks', ['query' => ['filters' => json_encode([
                    'service' => [(string) $workspace->runtime_ref],
                ], JSON_THROW_ON_ERROR)]]);
                $state = 'pending';
                foreach ($tasks as $task) {
                    $taskState = (string) ($task['Status']['State'] ?? 'pending');
                    if ($taskState === 'running') {
                        return Workspace::STATUS_RUNNING;
                    }
                    if (in_array($taskState, ['failed', 'rejected', 'orphaned'], true)) {
                        throw new AppException(502, 'Workspace 启动失败：' . ($task['Status']['Err'] ?? $taskState));
                    }
                    $state = $taskState;
                }
                return $state === 'shutdown' ? Workspace::STATUS_STOPPED : Workspace::STATUS_STARTING;
            });
            $workspace->status = $state;
            $workspace->error = null;
        } catch (Throwable $e) {
            $workspace->status = Workspace::STATUS_ERROR;
            $workspace->error = mb_substr($e->getMessage(), 0, 2000);
        }
        $workspace->updated_at = time();
        $workspace->save();
    }

    private function present(Workspace $workspace): array
    {
        $data = $workspace->makeHidden(['access_secret'])->toArray();
        $spec = (array) ($data['spec'] ?? []);
        unset($spec['secret_refs']);
        if (isset($spec['repository']) && is_array($spec['repository'])) {
            unset($spec['repository']['credential_revision']);
        }
        $data['spec'] = $spec;
        return $data;
    }

    private function workspaceHostname(int $clusterId, string $wildcardDomain, int $workspaceId): string
    {
        $baseDomain = strtolower(rtrim(trim($wildcardDomain), '.'));
        if (str_starts_with($baseDomain, '*.')) {
            $baseDomain = substr($baseDomain, 2);
        }
        if ($baseDomain === '') {
            throw new AppException(409, '该集群尚未配置 Workspace 泛域名');
        }
        $adjectives = [
            'brave', 'bright', 'calm', 'clever', 'cool', 'eager', 'focused', 'gentle',
            'happy', 'kind', 'lively', 'lucky', 'mighty', 'nimble', 'quiet', 'swift',
        ];
        $names = [
            'atlas', 'curie', 'darwin', 'hopper', 'lovelace', 'newton', 'pascal', 'tesla',
            'turing', 'falcon', 'otter', 'panda', 'phoenix', 'tiger', 'whale', 'wolf',
        ];
        for ($attempt = 0; $attempt < 20; ++$attempt) {
            $slug = $adjectives[random_int(0, count($adjectives) - 1)]
                . '-' . $names[random_int(0, count($names) - 1)];
            $hostname = $slug . '.' . $baseDomain;
            if (! GatewayVhost::where('cluster_id', $clusterId)->where('hostname', $hostname)->exists()
                && ! ProjectRoute::where('cluster_id', $clusterId)->where('hostname', $hostname)->exists()) {
                return $hostname;
            }
        }
        return 'workspace-' . $workspaceId . '.' . $baseDomain;
    }

    private function saveSecretRefs(Workspace $workspace, array $secretRefs): void
    {
        $spec = (array) $workspace->spec;
        $spec['secret_refs'] = $secretRefs;
        $workspace->spec = $spec;
        $workspace->updated_at = time();
        $workspace->save();
    }

    private function removeDockerResourceWithRetry(
        Client $client,
        SwarmApiClient $api,
        string $path,
        int $attempts = 20
    ): void {
        $last = null;
        for ($attempt = 0; $attempt < $attempts; ++$attempt) {
            try {
                $api->request($client, 'DELETE', $path);
                return;
            } catch (Throwable $e) {
                if (str_contains($e->getMessage(), '404')) {
                    return;
                }
                $last = $e;
                Coroutine::sleep(0.25);
            }
        }
        throw $last ?? new AppException(502, 'Docker 资源删除失败');
    }

    private function waitForWorkspaceContainersRemoved(
        Client $client,
        SwarmApiClient $api,
        Workspace $workspace,
        int $attempts = 60
    ): void {
        $remaining = [];
        for ($attempt = 0; $attempt < $attempts; ++$attempt) {
            $remaining = $api->request($client, 'GET', '/containers/json', ['query' => [
                'all' => '1',
                'filters' => json_encode([
                    'label' => ['com.codegalaxy.workspace.id=' . (string) $workspace->id],
                ], JSON_THROW_ON_ERROR),
            ]]);
            if ($remaining === []) {
                return;
            }
            Coroutine::sleep(0.5);
        }

        $ids = array_values(array_filter(array_map(
            static fn (array $container): string => substr((string) ($container['Id'] ?? ''), 0, 12),
            $remaining
        )));
        throw new AppException(
            502,
            'Workspace Service 已删除，但 Task 容器未及时释放：' . implode(', ', $ids)
        );
    }

    /** @return array{container_id: string, node_id: string} */
    private function runningContainerTarget(Cluster $cluster, Workspace $workspace): array
    {
        return $this->docker->withCluster($cluster, function (Client $client, SwarmApiClient $api) use ($workspace): array {
            $serviceId = (string) $workspace->runtime_ref;
            $service = $api->request($client, 'GET', '/services/' . rawurlencode($serviceId));
            $this->assertWorkspaceLabels($workspace, (array) ($service['Spec']['Labels'] ?? []));
            $tasks = $api->request($client, 'GET', '/tasks', ['query' => ['filters' => json_encode([
                'service' => [$serviceId], 'desired-state' => ['running'],
            ], JSON_THROW_ON_ERROR)]]);
            foreach ($tasks as $task) {
                $containerId = (string) ($task['Status']['ContainerStatus']['ContainerID'] ?? '');
                $nodeId = (string) ($task['NodeID'] ?? '');
                if (($task['Status']['State'] ?? '') === 'running' && $containerId !== '' && $nodeId !== '') {
                    return ['container_id' => $containerId, 'node_id' => $nodeId];
                }
            }
            throw new AppException(409, 'Workspace 容器尚未就绪');
        });
    }

    private function labels(Workspace $workspace): array
    {
        return [
            'com.codegalaxy.kind' => 'workspace',
            'com.codegalaxy.workspace.id' => (string) $workspace->id,
            'com.codegalaxy.group.id' => (string) $workspace->group_id,
            'com.codegalaxy.user.id' => (string) $workspace->uid,
        ];
    }

    private function assertWorkspaceLabels(Workspace $workspace, array $labels): void
    {
        $expected = $this->labels($workspace);
        foreach ($expected as $name => $value) {
            if ($name === 'com.codegalaxy.group.id'
                && (string) ($labels[$name] ?? $labels['com.codegalaxy.project.id'] ?? '') === $value) {
                continue;
            }
            if ((string) ($labels[$name] ?? '') !== $value) {
                throw new AppException(409, '拒绝删除归属标签不匹配的 Docker Workspace 资源');
            }
        }
    }

    private function withQueryParameter(string $url, string $name, string $value): string
    {
        return $url . (str_contains($url, '?') ? '&' : '?')
            . rawurlencode($name) . '=' . rawurlencode($value);
    }

    private function workspace(int $uid, int $orgId, int $groupId): ?Workspace
    {
        return Workspace::where('uid', $uid)->where('org_id', $orgId)
            ->where('group_id', $groupId)->first();
    }

    private function requiredWorkspace(int $uid, int $orgId, int $groupId): Workspace
    {
        return $this->workspace($uid, $orgId, $groupId)
            ?? throw new AppException(404, 'Workspace 不存在');
    }

    private function cluster(int $orgId, int $clusterId): Cluster
    {
        return Cluster::where('id', $clusterId)->where('org_id', $orgId)
            ->where('orchestrator_type', Cluster::ORCHESTRATOR_DOCKER_SWARM)->first()
            ?? throw new AppException(404, 'Docker Swarm 集群不存在');
    }
}

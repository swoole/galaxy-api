<?php

namespace App\Services\AppMarket;

use App\Exception\AppException;
use App\Job\AppMarket\InstallSwarmAppJob;
use App\Model\AppMarketSwarmInstallation;
use App\Model\AppMarketTpl;
use App\Model\Cluster;
use App\Services\AsyncQueue\DefaultQueueService;
use App\Services\Docker\SwarmApiClient;
use App\Services\Encrypt\CredentialCipher;
use App\Services\ContainerImageMappingService;
use App\Services\RegistryService;
use GuzzleHttp\Client;
use Hyperf\Redis\Redis;
use Ramsey\Uuid\Uuid;
use Swoole\Coroutine;
use Throwable;

class SwarmAppInstaller
{
    public function __construct(
        private SwarmApiClient $docker,
        private DefaultQueueService $queue,
        private Redis $redis,
        private CredentialCipher $cipher,
        private ContainerImageMappingService $imageMappings,
        private RegistryService $registries
    ) {}

    public function clusters(int $orgId): array
    {
        return Cluster::where('org_id', $orgId)
            ->where('orchestrator_type', Cluster::ORCHESTRATOR_DOCKER_SWARM)
            ->select('id', 'title', 'status', 'version')
            ->orderBy('id')
            ->get()
            ->toArray();
    }

    public function networks(int $orgId, int $clusterId): array
    {
        $cluster = $this->cluster($orgId, $clusterId);
        return $this->docker->withCluster($cluster, function (Client $client, SwarmApiClient $api): array {
            $networks = $api->request($client, 'GET', '/networks');
            $result = [];
            foreach ($networks as $network) {
                if (($network['Scope'] ?? '') !== 'swarm' || ($network['Driver'] ?? '') !== 'overlay') {
                    continue;
                }
                if (! empty($network['Ingress'])) {
                    continue;
                }
                $result[] = [
                    'id' => $network['Id'] ?? '',
                    'name' => $network['Name'] ?? '',
                    'internal' => (bool) ($network['Internal'] ?? false),
                    'attachable' => (bool) ($network['Attachable'] ?? false),
                ];
            }
            usort($result, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
            return $result;
        });
    }

    public function createNetwork(int $orgId, int $clusterId, string $name): array
    {
        if (! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,62}$/', $name)) {
            throw new AppException(422, '网络名称只能包含字母、数字、下划线、点号和连字符，且必须以字母或数字开头');
        }
        $cluster = $this->cluster($orgId, $clusterId);
        return $this->docker->withCluster($cluster, function (Client $client, SwarmApiClient $api) use ($name): array {
            try {
                $network = $api->request($client, 'POST', '/networks/create', ['json' => [
                    'Name' => $name,
                    'Driver' => 'overlay',
                    'Attachable' => true,
                ]]);
            } catch (AppException $e) {
                if (str_contains($e->getMessage(), 'already exists')) {
                    throw new AppException(422, '网络 "' . $name . '" 已存在');
                }
                throw $e;
            }
            return [
                'id' => $network['Id'] ?? '',
                'name' => $name,
                'attachable' => true,
            ];
        });
    }

    public function containers(int $orgId, int $clusterId): array
    {
        $cluster = $this->cluster($orgId, $clusterId);
        return $this->docker->withCluster($cluster, function (Client $client, SwarmApiClient $api): array {
            $containers = $api->request($client, 'GET', '/containers/json', [
                'query' => ['all' => 'true'],
            ]);
            $result = [];
            foreach ($containers as $c) {
                $names = array_map(static fn (string $n): string => ltrim($n, '/'), $c['Names'] ?? []);
                $swarmService = $c['Labels']['com.docker.swarm.service.name'] ?? null;
                $result[] = [
                    'id' => $c['Id'] ?? '',
                    'name' => $names[0] ?? '',
                    'names' => $names,
                    'image' => $c['Image'] ?? '',
                    'state' => $c['State'] ?? '',
                    'status' => $c['Status'] ?? '',
                    'swarm_service' => $swarmService,
                ];
            }
            usort($result, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
            return $result;
        });
    }

    public function queue(int $uid, int $orgId, AppMarketTpl $tpl, array $form): string
    {
        if (($tpl['pipeline']['orchestrator'] ?? null) !== 'docker-swarm'
            || ($tpl['pipeline']['status'] ?? null) !== 'ready') {
            throw new AppException(422, '该模板尚未支持 Docker Swarm 自动安装');
        }

        $clusterId = (int) ($form['cluster_id'] ?? 0);
        $this->cluster($orgId, $clusterId);
        $values = is_array($form['values'] ?? null) ? $form['values'] : [];
        $configSchema = $tpl['client_config']['form'] ?? [];
        $values = $this->validateValues($configSchema, $values);
        $swarmSpec = $tpl['server_config']['swarm'] ?? [];
        $installPolicy = $swarmSpec['install'] ?? [];
        $resourceConfig = $this->validateResources($form['resources'] ?? [], $installPolicy['resources'] ?? []);
        $networkConfig = $this->validateNetwork($form['network'] ?? [], $installPolicy['network'] ?? []);
        $portPolicy = $installPolicy['ports'] ?? [];
        $mountPolicy = $installPolicy['mounts'] ?? [];
        $portMappings = $networkConfig['mode'] === 'host'
            ? []
            : $this->validatePorts($form['ports'] ?? ($portPolicy['defaults'] ?? []), $portPolicy);
        $volumeMappings = $this->validateMounts($form['mounts'] ?? ($mountPolicy['defaults'] ?? []), $mountPolicy);
        if (array_filter($volumeMappings, static fn (array $mount): bool => $mount['type'] === 'bind')
            && ($form['bind_risk_acknowledged'] ?? false) !== true) {
            throw new AppException(422, 'Bind Mount 可直接访问 Swarm 节点宿主机数据，必须明确确认风险后才能安装');
        }
        $title = trim((string) ($form['title'] ?? $tpl['title']));
        $name = strtolower(trim((string) ($form['name'] ?? '')));
        if ($title === '' || mb_strlen($title) > 100) {
            throw new AppException(422, '应用标题不能为空且不能超过 100 个字符');
        }
        if (! preg_match('/^[a-z][a-z0-9-]{0,40}$/', $name)) {
            throw new AppException(422, '服务名称只能包含小写字母、数字和连字符，并且必须以字母开头');
        }

        $uuid = Uuid::uuid4()->toString();
        $serviceName = sprintf('galaxy-%d-%s', $orgId, $name);
        $volumeMappings = $this->scopeNamedVolumes($volumeMappings, $mountPolicy, $serviceName);
        if (AppMarketSwarmInstallation::where('cluster_id', $clusterId)
            ->where('service_name', $serviceName)
            ->whereNotIn('status', [AppMarketSwarmInstallation::STATUS_FAILED])->exists()) {
            throw new AppException(422, '该集群中已经存在同名的应用市场服务');
        }

        // A previous FAILED attempt still occupies the unique (cluster_id, service_name)
        // slot at the database level, which would make the fresh INSERT below fail with a
        // duplicate-key (1062) error. Free that slot by removing the stale FAILED record.
        // Its Docker service/configs/secrets were already cleaned up by install() on failure,
        // so deleting the row is safe and lets the retry proceed.
        AppMarketSwarmInstallation::where('cluster_id', $clusterId)
            ->where('service_name', $serviceName)
            ->where('status', AppMarketSwarmInstallation::STATUS_FAILED)
            ->delete();

        $secretValues = [];
        foreach ($configSchema as $field => $config) {
            if (($config['type'] ?? '') === 'secret') {
                $secretValues[$field] = $values[$field];
                unset($values[$field]);
            }
        }

        $now = time();
        $installation = AppMarketSwarmInstallation::create([
            'uuid' => $uuid,
            'org_id' => $orgId,
            'cluster_id' => $clusterId,
            'tpl_id' => $tpl['id'],
            'uid' => $uid,
            'title' => $title,
            'service_name' => $serviceName,
            'status' => AppMarketSwarmInstallation::STATUS_PENDING,
            'docker_service_id' => '',
            'docker_config_ids' => [],
            'docker_secret_ids' => [],
            'resource_config' => $resourceConfig,
            'network_config' => $networkConfig,
            'port_mappings' => $portMappings,
            'volume_mappings' => $volumeMappings,
            'config_schema' => $configSchema,
            'config_values' => $values,
            'template_snapshot' => [
                'uuid' => $tpl['uuid'],
                'version' => $tpl['version'],
                'swarm' => $swarmSpec,
            ],
            'form' => [
                'resources' => $resourceConfig,
                'network' => $networkConfig,
                'ports' => $portMappings,
                'mounts' => $volumeMappings,
                'values' => $values,
            ],
            'secret_payload' => $this->cipher->encrypt(json_encode($secretValues, JSON_THROW_ON_ERROR)),
            'error' => '',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $jobId = $uuid;
        $this->report($jobId, 'queued', '安装任务已进入队列', [
            'installation_id' => $installation['id'],
            'cluster_id' => $clusterId,
            'service_name' => $serviceName,
        ]);
        if (! $this->queue->push(new InstallSwarmAppJob((int) $installation['id'], $jobId))) {
            $installation->status = AppMarketSwarmInstallation::STATUS_FAILED;
            $installation->error = '安装任务进入队列失败';
            $installation->updated_at = time();
            $installation->save();
            throw new AppException(500, '安装任务进入队列失败');
        }

        return $jobId;
    }

    public function install(int $installationId, string $jobId): void
    {
        /** @var AppMarketSwarmInstallation|null $installation */
        $installation = AppMarketSwarmInstallation::find($installationId);
        if ($installation === null) {
            throw new AppException(404, '安装记录不存在');
        }
        /** @var AppMarketTpl|null $tpl */
        $tpl = AppMarketTpl::find($installation['tpl_id']);
        if ($tpl === null) {
            throw new AppException(404, '应用市场模板不存在');
        }
        $cluster = $this->cluster((int) $installation['org_id'], (int) $installation['cluster_id']);
        $installation->status = AppMarketSwarmInstallation::STATUS_INSTALLING;
        $installation->updated_at = time();
        $installation->save();

        $createdConfigs = [];
        $createdSecrets = [];
        try {
            $secrets = json_decode($this->cipher->decrypt((string) $installation['secret_payload']), true, 512, JSON_THROW_ON_ERROR);
            $form = $installation['config_values'] ?? [];
            $spec = $installation['template_snapshot']['swarm'] ?? ($tpl['server_config']['swarm'] ?? []);
            $resource = $tpl['client_config']['resource'] ?? [];
            $this->report($jobId, 'validate', '模板和集群参数校验完成');

            $result = $this->docker->withCluster($cluster, function (Client $client, SwarmApiClient $api) use (
                $installation, $spec, $resource, $form, $secrets, $jobId, $cluster, &$createdConfigs, &$createdSecrets
            ): array {
                $networkIds = $this->resolveNetworks($client, $api, $installation['network_config'] ?? []);

                $this->connectExternalContainers($client, $api, $networkIds, $installation['network_config'] ?? []);

                $suffix = str_replace('-', '', substr((string) $installation['uuid'], 0, 13));
                foreach ($spec['secrets'] ?? [] as $secretSpec) {
                    $source = (string) ($secretSpec['source'] ?? '');
                    $sourceKey = str_starts_with($source, 'form.') ? substr($source, 5) : $source;
                    if (! array_key_exists($sourceKey, $secrets) || ($secrets[$sourceKey] ?? '') === '') {
                        if (($secretSpec['required'] ?? true) === false) {
                            continue;
                        }
                        throw new AppException(422, '模板所需 Secret 参数缺失：' . $sourceKey);
                    }
                    $name = $installation['service_name'] . '-' . $secretSpec['name'] . '-' . $suffix;
                    $created = $api->request($client, 'POST', '/secrets/create', ['json' => [
                        'Name' => $name,
                        'Labels' => $this->labels($installation),
                        'Data' => base64_encode((string) $secrets[$sourceKey]),
                    ]]);
                    $createdSecrets[] = [
                        'id' => $created['ID'],
                        'name' => $name,
                        'target' => $secretSpec['target'],
                        'mode' => $this->fileMode($secretSpec['mode'] ?? '0400'),
                    ];
                }
                $this->report($jobId, 'secret', 'Docker Secret 创建完成');

                foreach ($spec['configs'] ?? [] as $configSpec) {
                    $name = $installation['service_name'] . '-' . $configSpec['name'] . '-' . $suffix;
                    if (($configSpec['format'] ?? '') === 'htpasswd') {
                        // Skip htpasswd when token auth is configured
                        if (! empty($form['token_realm'])) {
                            continue;
                        }
                        $htUser = $form[$configSpec['username_field'] ?? 'registry_user'] ?? 'admin';
                        $htPass = $secrets[$configSpec['password_field'] ?? 'registry_password'] ?? '';
                        if ($htPass === '') {
                            throw new AppException(422, 'htpasswd 密码不能为空');
                        }
                        $hash = password_hash($htPass, PASSWORD_BCRYPT);
                        $content = $htUser . ':' . $hash . "\n";
                    } elseif (($configSpec['format'] ?? '') === 'self_signed_cert') {
                        $certContent = $this->generateSelfSignedCert(
                            $installation['service_name'] . '.' . $configSpec['name']
                        );
                        // cert (public) as Docker Config
                        $content = $certContent['cert'];
                        // key (private) as Docker Secret
                        $secretName = $installation['service_name'] . '-' . ($configSpec['secret_name'] ?? 'signing-key') . '-' . $suffix;
                        $secretCreated = $api->request($client, 'POST', '/secrets/create', ['json' => [
                            'Name' => $secretName,
                            'Labels' => $this->labels($installation),
                            'Data' => base64_encode($certContent['key']),
                        ]]);
                        $createdSecrets[] = [
                            'id' => $secretCreated['ID'],
                            'name' => $secretName,
                            'target' => $configSpec['secret_target'] ?? '/certs/signing-key.pem',
                            'mode' => $this->fileMode($configSpec['secret_mode'] ?? '0400'),
                        ];
                    } else {
                        $renderValues = $form;
                        foreach (($configSpec['bcrypt_fields'] ?? []) as $field) {
                            if (! empty($secrets[$field])) {
                                $renderValues[$field] = 'bcrypt:' . $secrets[$field];
                            }
                        }
                        $content = $this->render((string) ($configSpec['template'] ?? ''), $renderValues, $secrets);
                        if (trim($content) === '') {
                            continue;
                        }
                    }
                    $created = $api->request($client, 'POST', '/configs/create', ['json' => [
                        'Name' => $name,
                        'Labels' => $this->labels($installation),
                        'Data' => base64_encode($content),
                    ]]);
                    $createdConfigs[] = [
                        'id' => $created['ID'],
                        'name' => $name,
                        'target' => $configSpec['target'],
                        'mode' => $this->fileMode($configSpec['mode'] ?? '0444'),
                    ];
                }
                $this->report($jobId, 'config', 'Docker Config 创建完成');

                $ports = $installation['port_mappings'] ?? [];
                if (! empty($ports)) {
                    $this->checkPortConflicts($client, $api, $ports);
                }
                $this->report($jobId, 'portcheck', '端口可用性校验通过');

                // When token auth is configured, switch registry env vars
                if (! empty($form['token_realm'])) {
                    $spec['environment'] = [
                        'REGISTRY_STORAGE_DELETE_ENABLED' => 'true',
                        'REGISTRY_AUTH' => 'token',
                        'REGISTRY_AUTH_TOKEN_REALM' => $form['token_realm'],
                        'REGISTRY_AUTH_TOKEN_SERVICE' => 'registry',
                        'REGISTRY_AUTH_TOKEN_ISSUER' => $form['token_issuer'] ?? 'galaxy-docker-auth',
                        'REGISTRY_AUTH_TOKEN_ROOTCERTBUNDLE' => '/certs/rootcertbundle.pem',
                    ];
                }

                $payload = $this->servicePayload(
                    $installation,
                    $spec,
                    $resource,
                    $networkIds,
                    $createdConfigs,
                    $createdSecrets,
                    $installation['resource_config'] ?? [],
                    $installation['port_mappings'] ?? [],
                    $installation['volume_mappings'] ?? [],
                    $form,
                    $cluster
                );
                $runtimeImage = (string) $payload['TaskTemplate']['ContainerSpec']['Image'];
                $service = $api->request($client, 'POST', '/services/create', [
                    'headers' => $this->registries->dockerAuthHeader(
                        (int) $cluster->org_id,
                        $runtimeImage
                    ),
                    'json' => $payload,
                ]);
                $serviceId = (string) ($service['ID'] ?? '');
                if ($serviceId === '') {
                    throw new AppException(502, 'Docker API 未返回 Service ID');
                }
                $installation->docker_service_id = $serviceId;
                $installation->docker_config_ids = $createdConfigs;
                $installation->docker_secret_ids = $createdSecrets;
                $installation->updated_at = time();
                $installation->save();
                $this->report($jobId, 'service', 'Docker Swarm Service 创建完成', ['service_id' => $serviceId]);
                $this->waitUntilRunning($client, $api, $serviceId);
                return ['service_id' => $serviceId];
            });

            $installation->status = AppMarketSwarmInstallation::STATUS_RUNNING;
            $installation->docker_service_id = $result['service_id'];
            $installation->docker_config_ids = $createdConfigs;
            $installation->docker_secret_ids = $createdSecrets;
            $installation->updated_at = time();
            $installation->save();
            AppMarketTpl::where('id', $tpl['id'])->increment('used');
            $this->report($jobId, 'finish', $installation['title'] . ' 安装完成', [
                'installation_id' => $installation['id'],
                'cluster_id' => $installation['cluster_id'],
                'service_id' => $result['service_id'],
                'service_name' => $installation['service_name'],
            ], 'success');
        } catch (Throwable $e) {
            // 清理已创建的 Docker 资源（Service、Config、Secret）
            try {
                $this->docker->withCluster($cluster, function (Client $client, SwarmApiClient $api) use ($installation, $createdConfigs, $createdSecrets) {
                    $serviceId = $installation->docker_service_id;
                    if (! empty($serviceId)) {
                        try {
                            $api->request($client, 'DELETE', '/services/' . rawurlencode($serviceId));
                        } catch (Throwable $_) {}
                    }
                    foreach ($createdSecrets as $secret) {
                        try {
                            $api->request($client, 'DELETE', '/secrets/' . rawurlencode($secret['id']));
                        } catch (Throwable $_) {}
                    }
                    foreach ($createdConfigs as $config) {
                        try {
                            $api->request($client, 'DELETE', '/configs/' . rawurlencode($config['id']));
                        } catch (Throwable $_) {}
                    }
                });
            } catch (Throwable $cleanupError) {
                logger('app-market')->warning('清理 Docker 资源失败', [
                    'installation_id' => $installationId,
                    'error' => $cleanupError->getMessage(),
                ]);
            }

            $installation->status = AppMarketSwarmInstallation::STATUS_FAILED;
            $installation->error = mb_substr($e->getMessage(), 0, 2000);
            $installation->updated_at = time();
            $installation->save();
            $this->report($jobId, 'error', '安装失败：' . $e->getMessage(), [], 'error');
            throw $e;
        }
    }

    private function cluster(int $orgId, int $clusterId): Cluster
    {
        /** @var Cluster|null $cluster */
        $cluster = Cluster::where('id', $clusterId)->where('org_id', $orgId)->first();
        if ($cluster === null || $cluster['orchestrator_type'] !== Cluster::ORCHESTRATOR_DOCKER_SWARM) {
            throw new AppException(404, 'Docker Swarm 集群不存在');
        }
        return $cluster;
    }

    private function validateValues(array $fields, array $values): array
    {
        $result = [];
        foreach ($fields as $name => $field) {
            $value = $values[$name] ?? ($field['default'] ?? null);
            if (! empty($field['required']) && ($value === null || $value === '')) {
                throw new AppException(422, ($field['label'] ?? $name) . '不能为空');
            }
            if (($field['type'] ?? '') === 'number') {
                if (! is_numeric($value)) {
                    throw new AppException(422, ($field['label'] ?? $name) . '必须是数字');
                }
                $value = (int) $value;
                if ((isset($field['min']) && $value < $field['min']) || (isset($field['max']) && $value > $field['max'])) {
                    throw new AppException(422, ($field['label'] ?? $name) . '超出允许范围');
                }
            } elseif (is_string($value)) {
                if (strlen($value) > 20000 || str_contains($value, "\0")) {
                    throw new AppException(422, ($field['label'] ?? $name) . '内容不合法');
                }
            }
            if (isset($field['pattern']) && ! preg_match('/' . str_replace('/', '\\/', $field['pattern']) . '/u', (string) $value)) {
                throw new AppException(422, $field['pattern_message'] ?? (($field['label'] ?? $name) . '格式不合法'));
            }
            if (isset($field['contains']) && ! str_contains((string) $value, (string) $field['contains'])) {
                throw new AppException(422, $field['contains_message'] ?? (($field['label'] ?? $name) . '内容不合法'));
            }
            $result[$name] = $value;
        }
        return $result;
    }

    private function render(string $template, array $values, array $secrets = []): string
    {
        $merged = array_merge($secrets, $values);
        foreach ($merged as $key => $value) {
            $replacement = (string) $value;
            if (str_starts_with($replacement, 'bcrypt:')) {
                $replacement = password_hash(substr($replacement, 7), PASSWORD_BCRYPT);
            }
            $fieldType = is_string($value) && (str_contains($value, "\n") || str_contains($value, "\r")) ? 'block' : 'scalar';
            if ($fieldType === 'scalar') {
                $replacement = str_replace(['\\', '"', "\r", "\n"], ['\\\\', '\\"', '', ''], $replacement);
            }
            $template = str_replace('{{ ' . $key . ' }}', $replacement, $template);
        }
        if (preg_match('/{{\s*[a-zA-Z0-9_.-]+\s*}}/', $template)) {
            throw new AppException(422, '模板仍包含未填写的参数');
        }
        return $template;
    }

    private function labels(AppMarketSwarmInstallation $installation): array
    {
        $payload = [
            'com.code-galaxy.managed' => 'true',
            'com.code-galaxy.installation' => (string) $installation['uuid'],
            'com.code-galaxy.org-id' => (string) $installation['org_id'],
            'com.code-galaxy.tpl-id' => (string) $installation['tpl_id'],
        ];
        return $payload;
    }

    private function servicePayload(
        AppMarketSwarmInstallation $installation,
        array $spec,
        array $resource,
        array $networkIds,
        array $configs,
        array $secrets,
        array $resourceConfig,
        array $ports,
        array $mounts,
        array $form,
        Cluster $cluster
    ): array {
        $container = [
            'Image' => $this->imageMappings->resolve($cluster, (string) $spec['image']),
            'Args' => $spec['args'] ?? ($spec['command'] ?? []),
            'Labels' => $this->labels($installation),
            'Configs' => array_map(static fn (array $config): array => [
                'ConfigID' => $config['id'],
                'ConfigName' => $config['name'],
                'File' => ['Name' => $config['target'], 'UID' => '0', 'GID' => '0', 'Mode' => $config['mode']],
            ], $configs),
            'Secrets' => array_map(static fn (array $secret): array => [
                'SecretID' => $secret['id'],
                'SecretName' => $secret['name'],
                'File' => ['Name' => $secret['target'], 'UID' => '0', 'GID' => '0', 'Mode' => $secret['mode']],
            ], $secrets),
        ];
        if (! empty($spec['entrypoint'])) {
            $container['Command'] = array_values((array) $spec['entrypoint']);
        }

        $env = $spec['environment'] ?? [];
        if (! empty($env)) {
            $container['Env'] = [];
            foreach ($env as $k => $v) {
                $container['Env'][] = $k . '=' . $this->render((string) $v, $form);
            }
        }
        $restart = (array) ($spec['restart_policy'] ?? []);
        $payload = [
            'Name' => $installation['service_name'],
            'Labels' => $this->labels($installation),
            'TaskTemplate' => [
                'ContainerSpec' => $container,
                'Resources' => $this->dockerResources($resourceConfig, $resource),
                'RestartPolicy' => [
                    'Condition' => $restart['condition'] ?? 'on-failure',
                    'Delay' => max(0, (int) ($restart['delay_seconds'] ?? 5)) * 1000000000,
                ],
                'Networks' => array_map(static fn (string $id): array => ['Target' => $id], $networkIds),
            ],
            'Mode' => ['Replicated' => ['Replicas' => (int) ($spec['replicas'] ?? 1)]],
        ];
        if (isset($restart['max_attempts']) && (int) $restart['max_attempts'] > 0) {
            $payload['TaskTemplate']['RestartPolicy']['MaxAttempts'] = (int) $restart['max_attempts'];
        }
        if (isset($restart['window_seconds']) && (int) $restart['window_seconds'] > 0) {
            $payload['TaskTemplate']['RestartPolicy']['Window'] = (int) $restart['window_seconds'] * 1000000000;
        }

        if (! empty($mounts)) {
            $payload['TaskTemplate']['ContainerSpec']['Mounts'] = array_map($this->dockerMount(...), $mounts);
        }
        if (! empty($ports)) {
            $payload['EndpointSpec'] = ['Ports' => array_map(static fn (array $port): array => [
                'Protocol' => $port['protocol'] ?? 'tcp',
                'TargetPort' => (int) $port['target'],
                'PublishedPort' => (int) $port['published'],
                'PublishMode' => $port['mode'] ?? 'ingress',
            ], $ports)];
        }
        if (! empty($spec['placement']['constraints'])) {
            $payload['TaskTemplate']['Placement'] = ['Constraints' => $spec['placement']['constraints']];
        }
        if (! empty($spec['update_config'])) {
            $update = (array) $spec['update_config'];
            $payload['UpdateConfig'] = [
                'Parallelism' => max(1, (int) ($update['parallelism'] ?? 1)),
                'Delay' => max(0, (int) ($update['delay_seconds'] ?? 0)) * 1000000000,
                'FailureAction' => $update['failure_action'] ?? 'rollback',
                'Monitor' => max(0, (int) ($update['monitor_seconds'] ?? 5)) * 1000000000,
                'MaxFailureRatio' => max(0.0, min(1.0, (float) ($update['max_failure_ratio'] ?? 0.0))),
                'Order' => $update['order'] ?? 'stop-first',
            ];
        }

        return $payload;
    }

    private function scopeNamedVolumes(array $mounts, array $policy, string $serviceName): array
    {
        if (empty($policy['scope_named_volumes'])) {
            return $mounts;
        }
        foreach ($mounts as &$mount) {
            if (($mount['type'] ?? '') !== 'volume') {
                continue;
            }
            $logicalName = trim((string) ($mount['source'] ?? ''), '.-');
            $mount['source'] = substr($serviceName . '-' . $logicalName, 0, 128);
        }
        unset($mount);
        return $mounts;
    }

    private function validateResources(array $input, array $policy): array
    {
        $fields = [
            'limit_cpu' => ['default' => $policy['limit_cpu_default'] ?? 250, 'min' => $policy['limit_cpu_min'] ?? 10, 'max' => $policy['limit_cpu_max'] ?? 64000],
            'limit_memory' => ['default' => $policy['limit_memory_default'] ?? 128, 'min' => $policy['limit_memory_min'] ?? 16, 'max' => $policy['limit_memory_max'] ?? 1048576],
            'reserve_cpu' => ['default' => $policy['reserve_cpu_default'] ?? 0, 'min' => 0, 'max' => $policy['limit_cpu_max'] ?? 64000],
            'reserve_memory' => ['default' => $policy['reserve_memory_default'] ?? 0, 'min' => 0, 'max' => $policy['limit_memory_max'] ?? 1048576],
        ];
        $result = [];
        foreach ($fields as $name => $rule) {
            $value = $input[$name] ?? $rule['default'];
            if (! is_numeric($value) || (int) $value < $rule['min'] || (int) $value > $rule['max']) {
                throw new AppException(422, 'CPU 或内存资源配置超出模板允许范围');
            }
            $result[$name] = (int) $value;
        }
        if ($result['reserve_cpu'] > $result['limit_cpu'] || $result['reserve_memory'] > $result['limit_memory']) {
            throw new AppException(422, '资源预留不能大于资源上限');
        }
        return $result;
    }

    private function validateNetwork(array $input, array $policy): array
    {
        $allowed = $policy['allowed_modes'] ?? ['overlay', 'host'];
        $mode = (string) ($input['mode'] ?? ($policy['default_mode'] ?? 'overlay'));
        if (! in_array($mode, $allowed, true)) {
            throw new AppException(422, '模板不允许所选网络模式');
        }
        // 支持新旧格式：targets（多选数组）或 target（单选字符串）+ additional
        $targets = [];
        if ($mode === 'overlay') {
            if (! empty($input['targets']) && is_array($input['targets'])) {
                $targets = array_values(array_unique(array_map(
                    fn ($v) => trim((string) $v),
                    $input['targets']
                )));
            } else {
                $target = trim((string) ($input['target'] ?? ''));
                if ($target !== '') {
                    $targets[] = $target;
                }
                if (! empty($input['additional']) && is_array($input['additional'])) {
                    foreach ($input['additional'] as $v) {
                        $t = trim((string) $v);
                        if ($t !== '' && ! in_array($t, $targets, true)) {
                            $targets[] = $t;
                        }
                    }
                }
            }
            if (empty($targets)) {
                throw new AppException(422, 'Overlay 网络模式必须选择至少一个网络');
            }
        }
        $externalContainers = [];
        if ($mode === 'overlay' && ! empty($input['external_containers']) && is_array($input['external_containers'])) {
            $externalContainers = array_values(array_unique(array_map(
                fn ($v) => trim((string) $v),
                $input['external_containers']
            )));
            $externalContainers = array_values(array_filter($externalContainers, fn ($v) => $v !== ''));
        }
        return ['mode' => $mode, 'targets' => $targets, 'external_containers' => $externalContainers];
    }

    private function validatePorts(array $ports, array $policy): array
    {
        if (empty($policy['customizable']) && $ports !== ($policy['defaults'] ?? [])) {
            throw new AppException(422, '该模板不允许修改端口映射');
        }
        $result = [];
        $seen = [];
        foreach ($ports as $port) {
            $target = (int) ($port['target'] ?? 0);
            $published = (int) ($port['published'] ?? 0);
            $protocol = (string) ($port['protocol'] ?? 'tcp');
            $mode = (string) ($port['mode'] ?? 'ingress');
            if ($target < 1 || $target > 65535 || $published < 1 || $published > 65535
                || ! in_array($protocol, ['tcp', 'udp', 'sctp'], true)
                || ! in_array($mode, ['ingress', 'host'], true)) {
                throw new AppException(422, '端口映射配置不合法');
            }
            $key = $published . '/' . $protocol;
            if (isset($seen[$key])) {
                throw new AppException(422, '存在重复的发布端口：' . $key);
            }
            $seen[$key] = true;
            $result[] = compact('target', 'published', 'protocol', 'mode');
        }
        return $result;
    }

    private function validateMounts(array $mounts, array $policy): array
    {
        if (empty($policy['customizable']) && $mounts !== ($policy['defaults'] ?? [])) {
            throw new AppException(422, '该模板不允许修改目录映射');
        }
        $result = [];
        $targets = [];
        foreach ($mounts as $mount) {
            $type = (string) ($mount['type'] ?? 'volume');
            $source = trim((string) ($mount['source'] ?? ''));
            $target = trim((string) ($mount['target'] ?? ''));
            if (! in_array($type, ['volume', 'bind', 'tmpfs'], true) || ! str_starts_with($target, '/')) {
                throw new AppException(422, '目录映射配置不合法');
            }
            if ($type === 'bind' && ! str_starts_with($source, '/')) {
                throw new AppException(422, 'Bind Mount 源目录必须是绝对路径');
            }
            if ($type === 'volume' && ! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,127}$/', $source)) {
                throw new AppException(422, 'Volume 名称格式不合法');
            }
            if (isset($targets[$target])) {
                throw new AppException(422, '存在重复的容器挂载目录：' . $target);
            }
            $targets[$target] = true;
            $result[] = ['type' => $type, 'source' => $source, 'target' => $target, 'read_only' => (bool) ($mount['read_only'] ?? false), 'driver' => $mount['driver'] ?? '', 'driver_opts' => $mount['driver_opts'] ?? []];
        }
        return $result;
    }

    private function resolveNetworks(Client $client, SwarmApiClient $api, array $config): array
    {
        $mode = $config['mode'] ?? 'overlay';
        if ($mode === 'host') {
            return ['host'];
        }
        // targets（新格式）或 target + additional（旧格式兼容）
        $targets = $config['targets'] ?? [];
        if (empty($targets)) {
            $targets = array_filter(array_merge(
                [trim((string) ($config['target'] ?? ''))],
                array_map(fn ($v) => trim((string) $v), $config['additional'] ?? [])
            ), fn ($v) => $v !== '');
        }
        $ids = [];
        foreach ($targets as $target) {
            $network = $api->request($client, 'GET', '/networks/' . rawurlencode($target));
            if (($network['Scope'] ?? '') !== 'swarm' || ($network['Driver'] ?? '') !== 'overlay') {
                throw new AppException(422, '所选网络 ' . ($network['Name'] ?? $target) . ' 不是 Swarm Overlay Network');
            }
            $ids[] = (string) ($network['Id'] ?? $target);
        }
        return $ids;
    }

    private function checkPortConflicts(Client $client, SwarmApiClient $api, array $ports): void
    {
        $services = $api->request($client, 'GET', '/services');
        $used = [];
        foreach ($services as $svc) {
            foreach ($svc['Endpoint']['Ports'] ?? [] as $p) {
                $key = ($p['PublishedPort'] ?? '') . '/' . ($p['Protocol'] ?? 'tcp');
                if ($key !== '/') {
                    $used[$key] = $svc['Spec']['Name'] ?? '';
                }
            }
        }
        foreach ($ports as $port) {
            $key = $port['published'] . '/' . ($port['protocol'] ?? 'tcp');
            if (isset($used[$key])) {
                throw new AppException(422, sprintf(
                    '端口 %d/%s 已被服务 %s 占用',
                    $port['published'],
                    $port['protocol'] ?? 'tcp',
                    $used[$key]
                ));
            }
        }
    }

    private function connectExternalContainers(Client $client, SwarmApiClient $api, array $networkIds, array $config): void
    {
        $containers = $config['external_containers'] ?? [];
        if (empty($containers)) {
            return;
        }
        foreach ($networkIds as $networkId) {
            if ($networkId === 'host') {
                continue;
            }
            foreach ($containers as $container) {
                try {
                    $api->request($client, 'POST', '/networks/' . rawurlencode($networkId) . '/connect', [
                        'json' => ['Container' => $container],
                    ]);
                } catch (AppException $e) {
                    if (str_contains($e->getMessage(), 'already attached')) {
                        continue;
                    }
                    throw $e;
                }
            }
        }
    }

    private function dockerResources(array $config, array $fallback): array
    {
        $limits = [
            'NanoCPUs' => ((int) ($config['limit_cpu'] ?? $fallback['limit_cpu'] ?? 250)) * 1000000,
            'MemoryBytes' => ((int) ($config['limit_memory'] ?? $fallback['limit_mem'] ?? 128)) * 1024 * 1024,
        ];
        $reservations = [];
        if (($config['reserve_cpu'] ?? 0) > 0) {
            $reservations['NanoCPUs'] = (int) $config['reserve_cpu'] * 1000000;
        }
        if (($config['reserve_memory'] ?? 0) > 0) {
            $reservations['MemoryBytes'] = (int) $config['reserve_memory'] * 1024 * 1024;
        }
        return $reservations === [] ? ['Limits' => $limits] : ['Limits' => $limits, 'Reservations' => $reservations];
    }

    private function dockerMount(array $mount): array
    {
        $result = [
            'Type' => $mount['type'],
            'Target' => $mount['target'],
            'ReadOnly' => $mount['read_only'],
        ];
        if ($mount['type'] !== 'tmpfs') {
            $result['Source'] = $mount['source'];
        }
        if (($mount['type'] ?? '') === 'volume' && ! empty($mount['driver'])) {
            $result['VolumeOptions'] = [
                'DriverConfig' => [
                    'Name' => $mount['driver'],
                    'Options' => $mount['driver_opts'] ?? [],
                ],
            ];
        }
        return $result;
    }

    private function fileMode(int|string $mode): int
    {
        if (is_int($mode)) {
            return $mode;
        }
        if (! preg_match('/^0?[0-7]{3,4}$/', $mode)) {
            throw new AppException(422, 'Config 或 Secret 文件权限格式不合法');
        }
        return intval($mode, 8);
    }

    private function waitUntilRunning(Client $client, SwarmApiClient $api, string $serviceId): void
    {
        for ($attempt = 0; $attempt < 45; ++$attempt) {
            $tasks = $api->request($client, 'GET', '/tasks', [
                'query' => ['filters' => json_encode(['service' => [$serviceId]], JSON_THROW_ON_ERROR)],
            ]);
            foreach ($tasks as $task) {
                $state = $task['Status']['State'] ?? '';
                if ($state === 'running') {
                    return;
                }
                if (in_array($state, ['failed', 'rejected', 'orphaned'], true)) {
                    throw new AppException(502, 'Service 任务启动失败：' . ($task['Status']['Err'] ?? $state));
                }
            }
            Coroutine::sleep(1);
        }
        throw new AppException(504, '等待 Docker Swarm Service 运行超时');
    }

    private function generateSelfSignedCert(string $commonName): array
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if ($key === false) {
            throw new AppException(500, '生成自签名证书私钥失败');
        }
        $csr = openssl_csr_new([
            'commonName' => $commonName,
        ], $key);
        if ($csr === false) {
            throw new AppException(500, '生成自签名证书 CSR 失败');
        }
        $cert = openssl_csr_sign($csr, null, $key, 3650);
        if ($cert === false) {
            throw new AppException(500, '签发自签名证书失败');
        }
        openssl_x509_export($cert, $certPem);
        openssl_pkey_export($key, $keyPem);
        return ['cert' => $certPem, 'key' => $keyPem];
    }

    public function installations(int $orgId): array
    {
        $installations = AppMarketSwarmInstallation::where('org_id', $orgId)
            ->orderBy('id', 'desc')
            ->get();
        $clusterIds = $installations->pluck('cluster_id')->unique()->values()->toArray();
        $clusters = ! empty($clusterIds)
            ? Cluster::whereIn('id', $clusterIds)->pluck('title', 'id')->toArray()
            : [];
        $tplIds = $installations->pluck('tpl_id')->unique()->values()->toArray();
        $tpls = ! empty($tplIds)
            ? AppMarketTpl::whereIn('id', $tplIds)->select('id', 'uuid', 'title')->get()->keyBy('id')->toArray()
            : [];
        return $installations->map(function (AppMarketSwarmInstallation $inst) use ($clusters, $tpls): array {
            $tpl = $tpls[$inst['tpl_id']] ?? null;
            return [
                'id' => $inst['id'],
                'uuid' => $inst['uuid'],
                'title' => $inst['title'],
                'service_name' => $inst['service_name'],
                'docker_service_id' => $inst->docker_service_id ?: '',
                'status' => $inst['status'],
                'error' => $inst['error'],
                'tpl_id' => $inst['tpl_id'],
                'tpl_uuid' => $tpl['uuid'] ?? '',
                'tpl_title' => $tpl['title'] ?? '',
                'cluster_id' => $inst['cluster_id'],
                'cluster_title' => $clusters[$inst['cluster_id']] ?? '',
                'config_schema' => $inst['config_schema'],
                'config_values' => $inst['config_values'],
                'resource_config' => $inst['resource_config'],
                'network_config' => $inst['network_config'],
                'port_mappings' => $inst['port_mappings'],
                'volume_mappings' => $inst['volume_mappings'],
                'created_at' => $inst['created_at'],
                'updated_at' => $inst['updated_at'],
            ];
        })->toArray();
    }

    public function findInstallation(int $orgId, int $clusterId, string $serviceName): ?array
    {
        $inst = AppMarketSwarmInstallation::where('org_id', $orgId)
            ->where('cluster_id', $clusterId)
            ->where('service_name', $serviceName)
            ->first();
        if ($inst === null) {
            return null;
        }
        $cluster = Cluster::find($inst['cluster_id']);
        $tpl = AppMarketTpl::find($inst['tpl_id']);
        return [
            'id' => $inst['id'],
            'uuid' => $inst['uuid'],
            'title' => $inst['title'],
            'service_name' => $inst['service_name'],
            'status' => $inst['status'],
            'tpl_uuid' => $tpl['uuid'] ?? '',
            'tpl_title' => $tpl['title'] ?? '',
            'cluster_id' => $inst['cluster_id'],
            'cluster_title' => $cluster['title'] ?? '',
            'config_schema' => $inst['config_schema'],
            'config_values' => $inst['config_values'],
            'resource_config' => $inst['resource_config'],
            'created_at' => $inst['created_at'],
        ];
    }

    public function reconfigure(int $orgId, int $installationId, array $formValues): void
    {
        /** @var AppMarketSwarmInstallation|null $installation */
        $installation = AppMarketSwarmInstallation::find($installationId);
        if ($installation === null || $installation['org_id'] !== $orgId) {
            throw new AppException(404, '安装记录不存在');
        }
        if ($installation['status'] !== AppMarketSwarmInstallation::STATUS_RUNNING) {
            throw new AppException(422, '仅运行中的应用支持修改配置');
        }

        /** @var AppMarketTpl|null $tpl */
        $tpl = AppMarketTpl::find($installation['tpl_id']);
        $cluster = $this->cluster($orgId, (int) $installation['cluster_id']);
        $configSchema = $installation['config_schema'] ?? [];

        // Merge new values over existing, skipping secret fields
        $existing = $installation['config_values'] ?? [];
        foreach ($configSchema as $field => $config) {
            if (($config['type'] ?? '') === 'secret') {
                continue;
            }
            if (array_key_exists($field, $formValues)) {
                $existing[$field] = $formValues[$field];
            }
        }
        $newValues = $this->validateValues($configSchema, $existing);
        $secrets = json_decode($this->cipher->decrypt((string) $installation['secret_payload']), true, 512, JSON_THROW_ON_ERROR);
        $spec = $installation['template_snapshot']['swarm'] ?? ($tpl['server_config']['swarm'] ?? []);

        $newConfigs = [];
        $oldConfigsToDelete = [];
        try {
            $this->docker->withCluster($cluster, function (Client $client, SwarmApiClient $api) use (
                $installation, $spec, $newValues, $secrets, &$newConfigs, &$oldConfigsToDelete
            ): void {
                $suffix = str_replace('-', '', substr((string) $installation['uuid'], 0, 13));
                $newSuffix = $suffix . '-' . substr(md5(uniqid()), 0, 6);

                // Read current service spec for version
                $current = $api->request($client, 'GET', '/services/' . rawurlencode($installation['docker_service_id']));
                $version = (int) ($current['Version']['Index'] ?? 0);

                // Re-render config templates and create new Docker Configs
                foreach ($spec['configs'] ?? [] as $configSpec) {
                    $cfgName = $configSpec['name'] ?? '';
                    if (($configSpec['format'] ?? '') === 'self_signed_cert') {
                        continue; // certs don't change on reconfigure
                    }
                    if (($configSpec['format'] ?? '') === 'htpasswd') {
                        if (! empty($newValues['token_realm'])) {
                            continue;
                        }
                        $htUser = $newValues[$configSpec['username_field'] ?? 'registry_user'] ?? 'admin';
                        $htPass = $secrets[$configSpec['password_field'] ?? 'registry_password'] ?? '';
                        if ($htPass === '') {
                            throw new AppException(422, 'htpasswd 密码不能为空');
                        }
                        $hash = password_hash($htPass, PASSWORD_BCRYPT);
                        $content = $htUser . ':' . $hash . "\n";
                    } else {
                        $renderValues = $newValues;
                        foreach (($configSpec['bcrypt_fields'] ?? []) as $field) {
                            if (! empty($secrets[$field])) {
                                $renderValues[$field] = 'bcrypt:' . $secrets[$field];
                            }
                        }
                        $content = $this->render((string) ($configSpec['template'] ?? ''), $renderValues, $secrets);
                        if (trim($content) === '') {
                            continue;
                        }
                    }
                    $name = $installation['service_name'] . '-' . $cfgName . '-' . $newSuffix;
                    $created = $api->request($client, 'POST', '/configs/create', ['json' => [
                        'Name' => $name,
                        'Labels' => $this->labels($installation),
                        'Data' => base64_encode($content),
                    ]]);
                    $newConfigs[] = [
                        'id' => $created['ID'],
                        'name' => $name,
                        'target' => $configSpec['target'],
                        'mode' => $this->fileMode($configSpec['mode'] ?? '0444'),
                    ];
                }

                // Rebuild Configs list: replace old with new where target matches, keep the rest
                $currentConfigs = $current['Spec']['TaskTemplate']['ContainerSpec']['Configs'] ?? [];
                $targetMap = [];
                foreach ($newConfigs as $nc) {
                    $targetMap[$nc['target']] = $nc;
                }
                $updatedConfigs = [];
                foreach ($currentConfigs as $cc) {
                    $cfgTarget = $cc['File']['Name'] ?? '';
                    if (isset($targetMap[$cfgTarget])) {
                        $nc = $targetMap[$cfgTarget];
                        $updatedConfigs[] = [
                            'ConfigID' => $nc['id'],
                            'ConfigName' => $nc['name'],
                            'File' => ['Name' => $nc['target'], 'UID' => '0', 'GID' => '0', 'Mode' => $nc['mode']],
                        ];
                        unset($targetMap[$cfgTarget]);
                    } else {
                        $updatedConfigs[] = $cc;
                    }
                }
                // Append new configs that didn't replace any existing target
                foreach ($targetMap as $nc) {
                    $updatedConfigs[] = [
                        'ConfigID' => $nc['id'],
                        'ConfigName' => $nc['name'],
                        'File' => ['Name' => $nc['target'], 'UID' => '0', 'GID' => '0', 'Mode' => $nc['mode']],
                    ];
                }

                $serviceSpec = $current['Spec'];
                $serviceSpec['TaskTemplate']['ContainerSpec']['Configs'] = $updatedConfigs;

                // Update env vars for token auth mode
                if (! empty($newValues['token_realm'])) {
                    $serviceSpec['TaskTemplate']['ContainerSpec']['Env'] = [
                        'REGISTRY_STORAGE_DELETE_ENABLED=true',
                        'REGISTRY_AUTH=token',
                        'REGISTRY_AUTH_TOKEN_REALM=' . $newValues['token_realm'],
                        'REGISTRY_AUTH_TOKEN_SERVICE=registry',
                        'REGISTRY_AUTH_TOKEN_ISSUER=' . ($newValues['token_issuer'] ?? 'galaxy-docker-auth'),
                        'REGISTRY_AUTH_TOKEN_ROOTCERTBUNDLE=/certs/rootcertbundle.pem',
                    ];
                }

                $api->request($client, 'POST', '/services/' . rawurlencode($installation['docker_service_id']) . '/update', [
                    'query' => ['version' => $version],
                    'json' => $serviceSpec,
                ]);

                // Collect old config IDs for cleanup (those that were replaced)
                $replacedTargets = [];
                foreach ($newConfigs as $nc) {
                    $replacedTargets[] = $nc['target'];
                }
                foreach ($installation['docker_config_ids'] as $old) {
                    if (in_array($old['target'], $replacedTargets, true)) {
                        $oldConfigsToDelete[] = $old['id'];
                    }
                }

                $this->waitUntilRunning($client, $api, $installation['docker_service_id']);
            });

            // Delete old replaced configs
            if (! empty($oldConfigsToDelete)) {
                try {
                    $this->docker->withCluster($cluster, function (Client $client, SwarmApiClient $api) use ($oldConfigsToDelete) {
                        foreach ($oldConfigsToDelete as $oldId) {
                            try {
                                $api->request($client, 'DELETE', '/configs/' . rawurlencode($oldId));
                            } catch (Throwable $_) {}
                        }
                    });
                } catch (Throwable $_) {}
            }

            // Update installation record: merge new config IDs, replace old ones
            $updatedDockerConfigIds = [];
            $replacedTargets = array_column($newConfigs, 'target');
            foreach ($installation['docker_config_ids'] as $old) {
                if (! in_array($old['target'], $replacedTargets, true)) {
                    $updatedDockerConfigIds[] = $old;
                }
            }
            $updatedDockerConfigIds = array_merge($updatedDockerConfigIds, $newConfigs);

            $installation->config_values = $newValues;
            $installation->docker_config_ids = $updatedDockerConfigIds;
            $installation->updated_at = time();
            $installation->save();
        } catch (Throwable $e) {
            // Clean up newly created configs on failure
            if (! empty($newConfigs)) {
                try {
                    $this->docker->withCluster($cluster, function (Client $client, SwarmApiClient $api) use ($newConfigs) {
                        foreach ($newConfigs as $nc) {
                            try {
                                $api->request($client, 'DELETE', '/configs/' . rawurlencode($nc['id']));
                            } catch (Throwable $_) {}
                        }
                    });
                } catch (Throwable $_) {}
            }
            throw $e;
        }
    }

    private function report(string $jobId, string $tag, string $message, array $context = [], string $level = 'info'): void
    {
        $key = 'cg.am.jobpg.' . $jobId;
        $score = microtime(true);
        $this->redis->zAdd($key, $score, json_encode([
            'tag' => $tag,
            'msg' => $message,
            'context' => $context,
            'level' => $level,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $this->redis->expire($key, 3600);
        $this->redis->publish($key, (string) $score);
    }
}

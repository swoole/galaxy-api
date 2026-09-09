<?php

namespace App\Services\Project\Runtime;

use App\Exception\AppException;
use App\Model\BuildArtifact;
use App\Model\Cluster;
use App\Model\ProjectRelease;
use App\Model\ProjectReleaseConfig;
use App\Model\ProjectReleaseSecret;
use App\Model\ProjectRuntime;
use App\Services\Kubernetes\KubernetesApiClient;
use App\Services\Kubernetes\KubernetesClusterService;
use App\Services\Project\ProjectRuntimeLock;
use App\Services\Project\ProjectServiceIdentity;
use App\Services\Project\ReleaseSecretService;
use App\Services\RegistryGroupGrantService;
use App\Services\RegistryService;
use App\Services\ContainerImageMappingService;
use Swoole\Coroutine;

final class KubernetesProjectRuntimeDriver implements ProjectRuntimeDriver
{
    public function __construct(
        private KubernetesClusterService $clusters,
        private KubernetesApiClient $api,
        private RegistryService $registries,
        private RegistryGroupGrantService $registryGrants,
        private ContainerImageMappingService $imageMappings,
        private ReleaseSecretService $secrets,
        private ProjectRuntimeLock $runtimeLock
    ) {}

    public function orchestratorType(): string
    {
        return Cluster::ORCHESTRATOR_KUBERNETES;
    }

    public function deploy(ProjectRelease $release): array
    {
        [$cluster, , $credential] = $this->clusters->connectionWithCredential(
            (int) $release->org_id,
            (int) $release->cluster_id
        );
        /** @var BuildArtifact|null $artifact */
        $artifact = BuildArtifact::where('id', (int) $release->artifact_id)
            ->where('org_id', (int) $release->org_id)
            ->where('group_id', (int) $release->group_id)
            ->where('project_id', (int) $release->project_id)
            ->first();
        if (! $cluster instanceof Cluster || $artifact === null) {
            throw new AppException(404, '发布使用的 Kubernetes 集群或镜像制品不存在');
        }
        $metadata = (array) $artifact->metadata;
        $registryId = (int) ($metadata['registry_id'] ?? 0);
        if ($registryId > 0) {
            $this->registryGrants->assertReference(
                (int) $release->org_id,
                (int) $release->group_id,
                $registryId,
                (string) $artifact->reference
            );
        }
        $definition = (array) $release->desired_spec;
        if ((array) ($definition['networks'] ?? [])) {
            throw new AppException(422, 'Kubernetes 发布不支持 Docker Network，请清空容器网络');
        }
        if ((array) ($definition['mounts'] ?? [])) {
            throw new AppException(422, 'Kubernetes 发布暂不支持 Docker Volume/Bind/Tmpfs，请清空持久化挂载');
        }
        $name = ProjectServiceIdentity::releaseResourceName(
            $definition,
            (int) $release->org_id,
            (int) $release->project_id,
            (int) $release->env_id,
            true
        );
        $instanceName = ProjectServiceIdentity::instanceName($definition);
        $namespace = 'galaxy-o' . (int) $release->org_id;
        $timeout = max(30, (int) config('project-release.timeout', 600));

        return $this->runtimeLock->synchronized(
            (int) $release->org_id,
            (int) $release->project_id,
            (int) $release->env_id,
            (int) $release->cluster_id,
            $instanceName,
            function () use ($release, $artifact, $definition, $credential, $name, $namespace, $registryId, $timeout, $cluster): array {
                $labels = $this->labels($release, $name);
                $this->apply($credential, '/api/v1/namespaces/' . rawurlencode($namespace), [
                    'apiVersion' => 'v1',
                    'kind' => 'Namespace',
                    'metadata' => ['name' => $namespace, 'labels' => [
                        'app.kubernetes.io/managed-by' => 'galaxy',
                        'codegalaxy.com/org-id' => (string) $release->org_id,
                    ]],
                ]);
                [$volumes, $mounts] = $this->applyReleaseFiles(
                    $credential,
                    $release,
                    $namespace,
                    $name,
                    $labels
                );
                $imagePullSecrets = [];
                $sourceImage = (string) $artifact->reference;
                $runtimeImage = $this->imageMappings->resolve($cluster, $sourceImage);
                $dockerConfig = $this->registries->kubernetesDockerConfigJson(
                    (int) $release->org_id,
                    $runtimeImage,
                    $runtimeImage === $sourceImage ? $registryId : 0
                );
                if ($dockerConfig !== null) {
                    $pullSecretName = $name . '-registry';
                    $this->apply($credential, $this->resourcePath('api/v1', $namespace, 'secrets', $pullSecretName), [
                        'apiVersion' => 'v1',
                        'kind' => 'Secret',
                        'metadata' => ['name' => $pullSecretName, 'namespace' => $namespace, 'labels' => $labels],
                        'type' => 'kubernetes.io/dockerconfigjson',
                        'data' => ['.dockerconfigjson' => base64_encode($dockerConfig)],
                    ]);
                    $imagePullSecrets[] = ['name' => $pullSecretName];
                }
                $image = str_contains($runtimeImage, '@')
                    ? $runtimeImage
                    : $runtimeImage . '@' . (string) $artifact->digest;
                $deployment = $this->apply(
                    $credential,
                    $this->resourcePath('apis/apps/v1', $namespace, 'deployments', $name),
                    $this->deployment($release, $definition, $namespace, $name, $labels, $image, $volumes, $mounts, $imagePullSecrets)
                );
                $ports = (array) ($definition['ports'] ?? []);
                if ($ports) {
                    $this->apply(
                        $credential,
                        $this->resourcePath('api/v1', $namespace, 'services', $name),
                        $this->service($namespace, $name, $labels, $ports)
                    );
                } else {
                    $this->deleteIgnoringMissing(
                        $credential,
                        $this->resourcePath('api/v1', $namespace, 'services', $name)
                    );
                }
                $uid = (string) ($deployment['metadata']['uid'] ?? '');
                if ($uid === '') {
                    throw new AppException(502, 'Kubernetes API 未返回 Deployment UID');
                }
                $release->runtime_ref = $uid;
                $release->updated_at = time();
                $release->save();
                $now = time();
                $this->storeRuntime($release, $definition, $namespace, $name, $uid, 0, 'deploying', 'unknown', $now);
                $state = $this->waitForDeployment(
                    $credential,
                    $namespace,
                    $name,
                    (int) ($definition['replicas'] ?? 1),
                    $timeout
                );
                $this->storeRuntime(
                    $release,
                    $definition,
                    $namespace,
                    $name,
                    $uid,
                    (int) $state['ready'],
                    (int) ($definition['replicas'] ?? 1) === 0 ? 'stopped' : 'running',
                    'healthy',
                    time()
                );
                return [
                    'operation' => 'apply',
                    'deployment_uid' => $uid,
                    'deployment_name' => $name,
                    'namespace' => $namespace,
                    'service_name' => $ports ? $name : '',
                ] + $state;
            },
            30,
            $timeout + 120
        );
    }

    public function reconcileSucceededRelease(ProjectRelease $release): ?array
    {
        if ((string) $release->status !== ProjectRelease::STATUS_DEPLOYING) {
            return null;
        }
        [, , $credential] = $this->clusters->connectionWithCredential(
            (int) $release->org_id,
            (int) $release->cluster_id
        );
        $definition = (array) $release->desired_spec;
        $name = ProjectServiceIdentity::releaseResourceName(
            $definition,
            (int) $release->org_id,
            (int) $release->project_id,
            (int) $release->env_id,
            true
        );
        $namespace = 'galaxy-o' . (int) $release->org_id;
        try {
            $deployment = $this->api->get(
                $credential,
                $this->resourcePath('apis/apps/v1', $namespace, 'deployments', $name)
            );
        } catch (AppException $e) {
            return str_contains($e->getMessage(), 'HTTP 404') ? null : throw $e;
        }
        $desired = (int) ($definition['replicas'] ?? 1);
        $ready = (int) ($deployment['status']['readyReplicas'] ?? 0);
        if ($ready < $desired) {
            return null;
        }
        $uid = (string) ($deployment['metadata']['uid'] ?? '');
        $this->storeRuntime($release, $definition, $namespace, $name, $uid, $ready, 'running', 'healthy', time());
        return ['operation' => 'reconcile', 'deployment_uid' => $uid, 'deployment_name' => $name, 'ready' => $ready];
    }

    public function cleanupFailedRelease(ProjectRelease $release): bool
    {
        $result = (array) ($release->result ?? []);
        $result['failure_reconciled_at'] = time();
        $release->result = $result;
        $release->updated_at = time();
        $release->save();
        return true;
    }

    public function restartRuntime(Cluster $cluster, ProjectRuntime $runtime): void
    {
        [, , $credential] = $this->clusters->connectionWithCredential(
            (int) $runtime->org_id,
            (int) $runtime->cluster_id
        );
        $name = trim((string) $runtime->service_name);
        if ($name === '') {
            $name = ProjectServiceIdentity::kubernetesName(
                (int) $runtime->org_id,
                (int) $runtime->project_id,
                (int) $runtime->env_id,
                (string) $runtime->name
            );
        }
        $namespace = (string) ($runtime->runtime_namespace ?: 'galaxy-o' . (int) $runtime->org_id);
        $path = $this->resourcePath('apis/apps/v1', $namespace, 'deployments', $name);
        $deployment = $this->api->get($credential, $path);
        $this->assertOwnedDeployment($deployment, $runtime);
        $this->api->request($credential, 'PATCH', $path, [
            'headers' => ['Content-Type' => 'application/strategic-merge-patch+json'],
            'json' => [
                'spec' => [
                    'template' => [
                        'metadata' => [
                            'annotations' => [
                                'codegalaxy.com/restarted-at' => gmdate('c'),
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function cleanupRuntimeResources(
        Cluster $cluster,
        int $orgId,
        int $projectId,
        int $envId,
        int $clusterId,
        string $runtimeName
    ): bool {
        [, , $credential] = $this->clusters->connectionWithCredential($orgId, $clusterId);
        $runtime = ProjectRuntime::where('org_id', $orgId)
            ->where('project_id', $projectId)
            ->where('name', $runtimeName)
            ->first();
        $name = trim((string) ($runtime?->service_name ?? ''));
        if ($name === '') {
            $name = ProjectServiceIdentity::kubernetesName($orgId, $projectId, $envId, $runtimeName);
        }
        $namespace = (string) ($runtime?->runtime_namespace ?: 'galaxy-o' . $orgId);
        if ($runtime !== null) {
            $path = $this->resourcePath('apis/apps/v1', $namespace, 'deployments', $name);
            try {
                $this->assertOwnedDeployment($this->api->get($credential, $path), $runtime);
            } catch (AppException $e) {
                if (! str_contains($e->getMessage(), 'HTTP 404')) {
                    throw $e;
                }
            }
            $ingresses = $this->api->request(
                $credential,
                'GET',
                '/apis/networking.k8s.io/v1/namespaces/' . rawurlencode($namespace) . '/ingresses',
                ['query' => ['labelSelector' => 'codegalaxy.com/runtime-id=' . (int) $runtime->id]]
            );
            foreach ((array) ($ingresses['items'] ?? []) as $ingress) {
                $labels = (array) ($ingress['metadata']['labels'] ?? []);
                $ingressName = trim((string) ($ingress['metadata']['name'] ?? ''));
                if ($ingressName === ''
                    || ($labels['app.kubernetes.io/managed-by'] ?? '') !== 'galaxy'
                    || (int) ($labels['codegalaxy.com/org-id'] ?? 0) !== $orgId
                    || (int) ($labels['codegalaxy.com/project-id'] ?? 0) !== $projectId) {
                    continue;
                }
                $this->deleteIgnoringMissing(
                    $credential,
                    $this->resourcePath(
                        'apis/networking.k8s.io/v1',
                        $namespace,
                        'ingresses',
                        $ingressName
                    )
                );
            }
        }
        foreach ([
            ['apis/apps/v1', 'deployments', $name],
            ['api/v1', 'services', $name],
            ['api/v1', 'configmaps', $name . '-config'],
            ['api/v1', 'secrets', $name . '-secret'],
            ['api/v1', 'secrets', $name . '-registry'],
        ] as [$apiVersion, $kind, $resourceName]) {
            $this->deleteIgnoringMissing(
                $credential,
                $this->resourcePath($apiVersion, $namespace, $kind, $resourceName)
            );
        }
        return true;
    }

    private function assertOwnedDeployment(array $deployment, ProjectRuntime $runtime): void
    {
        $labels = (array) ($deployment['metadata']['labels'] ?? []);
        if (($labels['app.kubernetes.io/managed-by'] ?? '') !== 'galaxy'
            || (int) ($labels['codegalaxy.com/org-id'] ?? 0) !== (int) $runtime->org_id
            || (int) ($labels['codegalaxy.com/project-id'] ?? 0) !== (int) $runtime->project_id
            || (int) ($labels['codegalaxy.com/env-id'] ?? 0) !== (int) $runtime->env_id) {
            throw new AppException(409, 'Kubernetes Deployment 归属校验失败，拒绝操作');
        }
        $runtimeRef = (string) $runtime->runtime_ref;
        $uid = (string) ($deployment['metadata']['uid'] ?? '');
        if ($runtimeRef !== '' && $uid !== '' && $runtimeRef !== $uid) {
            throw new AppException(409, 'Kubernetes Deployment UID 已变化，请刷新实例状态后重试');
        }
    }

    private function apply(array $credential, string $path, array $resource): array
    {
        return $this->api->request($credential, 'PATCH', $path, [
            'query' => ['fieldManager' => 'galaxy', 'force' => 'true'],
            'headers' => ['Content-Type' => 'application/apply-patch+yaml'],
            // Kubernetes accepts JSON as YAML, but its YAML decoder rejects
            // PHP's default escaped slash form (for example `app.kubernetes.io\/name`).
            'body' => json_encode(
                $resource,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ),
        ]);
    }

    private function deleteIgnoringMissing(array $credential, string $path): void
    {
        try {
            $this->api->request($credential, 'DELETE', $path, [
                'json' => ['apiVersion' => 'v1', 'kind' => 'DeleteOptions', 'propagationPolicy' => 'Foreground'],
            ]);
        } catch (AppException $e) {
            if (! str_contains($e->getMessage(), 'HTTP 404')) {
                throw $e;
            }
        }
    }

    private function resourcePath(string $apiVersion, string $namespace, string $kind, string $name): string
    {
        return '/' . $apiVersion . '/namespaces/' . rawurlencode($namespace)
            . '/' . $kind . '/' . rawurlencode($name);
    }

    private function labels(ProjectRelease $release, string $name): array
    {
        return [
            'app.kubernetes.io/name' => $name,
            'app.kubernetes.io/managed-by' => 'galaxy',
            'codegalaxy.com/org-id' => (string) $release->org_id,
            'codegalaxy.com/project-id' => (string) $release->project_id,
            'codegalaxy.com/env-id' => (string) $release->env_id,
        ];
    }

    private function applyReleaseFiles(
        array $credential,
        ProjectRelease $release,
        string $namespace,
        string $name,
        array $labels
    ): array {
        $volumes = [];
        $mounts = [];
        $configs = ProjectReleaseConfig::where('release_id', (int) $release->id)->get();
        if ($configs->isNotEmpty()) {
            $configName = $name . '-config';
            $binaryData = [];
            foreach ($configs as $config) {
                $binaryData[(string) $config->name] = base64_encode((string) $config->content);
                $mounts[] = [
                    'name' => 'galaxy-config',
                    'mountPath' => (string) $config->target,
                    'subPath' => (string) $config->name,
                    'readOnly' => true,
                ];
            }
            $this->apply($credential, $this->resourcePath('api/v1', $namespace, 'configmaps', $configName), [
                'apiVersion' => 'v1', 'kind' => 'ConfigMap',
                'metadata' => ['name' => $configName, 'namespace' => $namespace, 'labels' => $labels],
                'binaryData' => $binaryData,
            ]);
            $volumes[] = ['name' => 'galaxy-config', 'configMap' => ['name' => $configName]];
        }
        $secrets = ProjectReleaseSecret::where('release_id', (int) $release->id)->get();
        if ($secrets->isNotEmpty()) {
            $secretName = $name . '-secret';
            $data = [];
            foreach ($secrets as $secret) {
                $data[(string) $secret->name] = base64_encode($this->secrets->plaintext($secret));
                $target = (string) $secret->target;
                $mounts[] = [
                    'name' => 'galaxy-secret',
                    'mountPath' => str_starts_with($target, '/') ? $target : '/run/secrets/' . $target,
                    'subPath' => (string) $secret->name,
                    'readOnly' => true,
                ];
            }
            $this->apply($credential, $this->resourcePath('api/v1', $namespace, 'secrets', $secretName), [
                'apiVersion' => 'v1', 'kind' => 'Secret', 'type' => 'Opaque',
                'metadata' => ['name' => $secretName, 'namespace' => $namespace, 'labels' => $labels],
                'data' => $data,
            ]);
            $volumes[] = ['name' => 'galaxy-secret', 'secret' => ['secretName' => $secretName]];
        }
        return [$volumes, $mounts];
    }

    private function deployment(
        ProjectRelease $release,
        array $definition,
        string $namespace,
        string $name,
        array $labels,
        string $image,
        array $volumes,
        array $mounts,
        array $imagePullSecrets
    ): array {
        $podLabels = ['app.kubernetes.io/name' => $name, 'codegalaxy.com/runtime' => $name];
        $container = [
            'name' => $name,
            'image' => $image,
            'imagePullPolicy' => 'IfNotPresent',
            'env' => array_values(array_map(static fn (array $item): array => [
                'name' => (string) $item['name'], 'value' => (string) $item['value'],
            ], (array) ($definition['env'] ?? []))),
            'ports' => array_values(array_map(static fn (array $port): array => [
                'name' => 'p' . (int) $port['target'] . '-' . strtolower((string) $port['protocol']),
                'containerPort' => (int) $port['target'],
                'protocol' => strtoupper((string) $port['protocol']),
            ], (array) ($definition['ports'] ?? []))),
            'resources' => $this->resources((array) ($definition['resources'] ?? [])),
            'volumeMounts' => $mounts,
        ];
        if ((array) ($definition['command'] ?? [])) {
            $container['command'] = array_values((array) $definition['command']);
        }
        if ((array) ($definition['args'] ?? [])) {
            $container['args'] = array_values((array) $definition['args']);
        }
        return [
            'apiVersion' => 'apps/v1',
            'kind' => 'Deployment',
            'metadata' => [
                'name' => $name,
                'namespace' => $namespace,
                'labels' => $labels,
                'annotations' => ['codegalaxy.com/release-id' => (string) $release->id],
            ],
            'spec' => [
                'replicas' => (int) ($definition['replicas'] ?? 1),
                'selector' => ['matchLabels' => $podLabels],
                'strategy' => ['type' => 'RollingUpdate', 'rollingUpdate' => [
                    'maxUnavailable' => ($definition['update']['order'] ?? '') === 'start-first' ? 0 : 1,
                    'maxSurge' => 1,
                ]],
                'template' => [
                    'metadata' => [
                        'labels' => $labels + $podLabels,
                        'annotations' => ['codegalaxy.com/release-id' => (string) $release->id],
                    ],
                    'spec' => [
                        'containers' => [$container],
                        'volumes' => $volumes,
                        'imagePullSecrets' => $imagePullSecrets,
                    ],
                ],
            ],
        ];
    }

    private function service(string $namespace, string $name, array $labels, array $ports): array
    {
        return [
            'apiVersion' => 'v1',
            'kind' => 'Service',
            'metadata' => ['name' => $name, 'namespace' => $namespace, 'labels' => $labels],
            'spec' => [
                'type' => 'ClusterIP',
                'selector' => ['codegalaxy.com/runtime' => $name],
                'ports' => array_values(array_map(static fn (array $port, int $index): array => [
                    'name' => 'p' . (int) $port['target'] . '-' . strtolower((string) $port['protocol']) . '-' . $index,
                    'protocol' => strtoupper((string) $port['protocol']),
                    'port' => (int) (($port['published'] ?? 0) ?: $port['target']),
                    'targetPort' => (int) $port['target'],
                ], $ports, array_keys($ports))),
            ],
        ];
    }

    private function resources(array $resources): array
    {
        $result = [];
        foreach (['limits', 'reservations'] as $source) {
            $target = $source === 'reservations' ? 'requests' : 'limits';
            $values = (array) ($resources[$source] ?? []);
            if ((float) ($values['cpus'] ?? 0) > 0) {
                // Kubernetes Quantity must not receive a decimal through the
                // YAML apply decoder: 0.1 can be rounded up to 101m. Emit an
                // exact integer millicore value instead.
                $millicores = max(1, (int) round((float) $values['cpus'] * 1000));
                $result[$target]['cpu'] = $millicores % 1000 === 0
                    ? (string) intdiv($millicores, 1000)
                    : $millicores . 'm';
            }
            if ((int) ($values['memory_mb'] ?? 0) > 0) {
                $result[$target]['memory'] = (int) $values['memory_mb'] . 'Mi';
            }
        }
        return $result;
    }

    private function waitForDeployment(
        array $credential,
        string $namespace,
        string $name,
        int $replicas,
        int $timeout
    ): array {
        $started = time();
        do {
            $deployment = $this->api->get(
                $credential,
                $this->resourcePath('apis/apps/v1', $namespace, 'deployments', $name)
            );
            $status = (array) ($deployment['status'] ?? []);
            $ready = (int) ($status['readyReplicas'] ?? 0);
            $updated = (int) ($status['updatedReplicas'] ?? 0);
            $observed = (int) ($status['observedGeneration'] ?? 0);
            $generation = (int) ($deployment['metadata']['generation'] ?? 0);
            if ($observed >= $generation && $ready >= $replicas && $updated >= $replicas) {
                return ['ready' => $ready, 'updated' => $updated, 'elapsed_seconds' => time() - $started];
            }
            foreach ((array) ($status['conditions'] ?? []) as $condition) {
                if (($condition['type'] ?? '') === 'Progressing'
                    && ($condition['status'] ?? '') === 'False'
                    && ($condition['reason'] ?? '') === 'ProgressDeadlineExceeded') {
                    throw new AppException(502, 'Kubernetes Deployment 发布超时：'
                        . (string) ($condition['message'] ?? 'ProgressDeadlineExceeded'));
                }
            }
            $pods = $this->api->request(
                $credential,
                'GET',
                '/api/v1/namespaces/' . rawurlencode($namespace) . '/pods',
                ['query' => ['labelSelector' => 'codegalaxy.com/runtime=' . $name]]
            );
            foreach ((array) ($pods['items'] ?? []) as $pod) {
                foreach ((array) ($pod['status']['containerStatuses'] ?? []) as $containerStatus) {
                    $waiting = (array) ($containerStatus['state']['waiting'] ?? []);
                    $reason = (string) ($waiting['reason'] ?? '');
                    if (time() - $started >= 15
                        && in_array($reason, ['ErrImagePull', 'ImagePullBackOff', 'InvalidImageName'], true)) {
                        throw new AppException(502, sprintf(
                            'Kubernetes Pod %s 镜像拉取失败（%s）：%s',
                            (string) ($pod['metadata']['name'] ?? ''),
                            $reason,
                            (string) ($waiting['message'] ?? '')
                        ));
                    }
                    if (time() - $started >= 30 && $reason === 'CrashLoopBackOff') {
                        throw new AppException(502, sprintf(
                            'Kubernetes Pod %s 启动失败（CrashLoopBackOff）：%s',
                            (string) ($pod['metadata']['name'] ?? ''),
                            (string) ($waiting['message'] ?? '')
                        ));
                    }
                }
            }
            Coroutine::sleep(2);
        } while (time() - $started < $timeout);
        throw new AppException(504, sprintf('等待 Kubernetes Deployment 就绪超时（%d 秒）', $timeout));
    }

    private function storeRuntime(
        ProjectRelease $release,
        array $definition,
        string $namespace,
        string $name,
        string $uid,
        int $ready,
        string $status,
        string $health,
        int $now
    ): void {
        $instanceName = ProjectServiceIdentity::instanceName($definition);
        $runtime = ProjectRuntime::where('org_id', (int) $release->org_id)
            ->where('project_id', (int) $release->project_id)
            ->where('name', $instanceName)->first();
        ProjectRuntime::updateOrCreate([
            'org_id' => (int) $release->org_id,
            'project_id' => (int) $release->project_id,
            'name' => $instanceName,
        ], [
            'group_id' => (int) $release->group_id,
            'env_id' => (int) $release->env_id,
            'cluster_id' => (int) $release->cluster_id,
            'release_id' => (int) $release->id,
            'orchestrator_type' => Cluster::ORCHESTRATOR_KUBERNETES,
            'workload_kind' => ProjectRuntime::WORKLOAD_KUBERNETES_DEPLOYMENT,
            'runtime_ref' => $uid,
            'runtime_namespace' => $namespace,
            'service_name' => $name,
            'desired_count' => (int) ($definition['replicas'] ?? 1),
            'running_count' => $ready,
            'status' => $status,
            'health' => $health,
            'last_error' => '',
            'last_synced_at' => $health === 'healthy' ? $now : 0,
            'spec' => $definition,
            'provider_metadata' => ['deployment_name' => $name, 'service_name' => $name],
            'created_at' => (int) ($runtime?->created_at ?: $now),
            'updated_at' => $now,
        ]);
    }
}

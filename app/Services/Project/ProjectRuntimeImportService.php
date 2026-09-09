<?php

namespace App\Services\Project;

use App\Exception\AppException;
use App\Model\BuildArtifact;
use App\Model\Cluster;
use App\Model\Env;
use App\Model\EnvClusterRel;
use App\Model\GroupResourceGrant;
use App\Model\Project;
use App\Model\ProjectRelease;
use App\Model\ProjectRuntime;
use App\Services\Docker\SwarmOverviewService;
use App\Services\Kubernetes\KubernetesClusterService;
use Hyperf\DbConnection\Db;

final class ProjectRuntimeImportService
{
    public const SWARM_SERVICE = 'swarm_service';
    public const KUBERNETES_DEPLOYMENT = 'kubernetes_deployment';
    public const DOCKER_CONTAINER = 'docker_container';

    public function __construct(
        private SwarmOverviewService $swarm,
        private KubernetesClusterService $kubernetes,
        private ReleaseDefinitionService $definitions,
        private ProjectReleaseService $releases
    ) {}

    public function sources(
        int $orgId,
        int $groupId,
        int $clusterId,
        string $type,
        string $nodeId = ''
    ): array {
        $cluster = $this->cluster($orgId, $groupId, $clusterId);
        $environments = Env::where('env.org_id', $orgId)->where('env.archived_at', 0)
            ->join('env_cluster_rel', 'env_cluster_rel.env_id', '=', 'env.id')
            ->where('env_cluster_rel.cluster_id', $clusterId)
            ->select('env.id', 'env.title')->orderBy('env.id')->get()->toArray();
        if ($type === self::SWARM_SERVICE) {
            $this->assertOrchestrator($cluster, Cluster::ORCHESTRATOR_DOCKER_SWARM);
            $resources = array_values(array_filter(
                $this->swarm->listServices($cluster),
                static fn (array $service): bool =>
                    (int) ($service['labels']['com.codegalaxy.project.id']
                        ?? $service['labels']['com.codegalaxy.app.id'] ?? 0) <= 0
            ));
            return ['resources' => $resources, 'nodes' => [], 'environments' => $environments];
        }
        if ($type === self::KUBERNETES_DEPLOYMENT) {
            $this->assertOrchestrator($cluster, Cluster::ORCHESTRATOR_KUBERNETES);
            $resources = array_values(array_filter(
                $this->kubernetes->deployments($orgId, $clusterId),
                static fn (array $deployment): bool =>
                    (($deployment['labels']['app.kubernetes.io/managed-by'] ?? '') !== 'galaxy')
                    && ! in_array((string) ($deployment['namespace'] ?? ''), [
                        'kube-system', 'kube-public', 'kube-node-lease',
                    ], true)
            ));
            return ['resources' => $resources, 'nodes' => [], 'environments' => $environments];
        }
        if ($type !== self::DOCKER_CONTAINER) {
            throw new AppException(422, '不支持的运行资源类型');
        }
        $this->assertOrchestrator($cluster, Cluster::ORCHESTRATOR_DOCKER_SWARM);
        $nodes = $this->swarm->resourceNodes($cluster);
        if ($nodeId === '') {
            return ['resources' => [], 'nodes' => $nodes, 'environments' => $environments];
        }
        $containers = array_values(array_filter(
            $this->swarm->listContainers($cluster, null, $nodeId),
            static fn (array $container): bool =>
                trim((string) ($container['service_id'] ?? '')) === ''
                && ! array_key_exists('k3d.cluster', (array) ($container['labels'] ?? []))
                && (($container['labels']['app'] ?? '') !== 'k3d')
        ));
        return ['resources' => $containers, 'nodes' => $nodes, 'environments' => $environments];
    }

    public function profile(int $orgId, int $groupId, array $source, bool $redactSensitiveValues = false): array
    {
        $clusterId = (int) ($source['cluster_id'] ?? 0);
        $type = (string) ($source['type'] ?? '');
        $cluster = $this->cluster($orgId, $groupId, $clusterId);
        $profile = match ($type) {
            self::SWARM_SERVICE => $this->swarmServiceProfile($cluster, (string) ($source['reference'] ?? '')),
            self::KUBERNETES_DEPLOYMENT => $this->deploymentProfile(
                $orgId, $clusterId, (string) ($source['namespace'] ?? ''), (string) ($source['reference'] ?? '')
            ),
            self::DOCKER_CONTAINER => $this->containerProfile(
                $cluster, (string) ($source['node_id'] ?? ''), (string) ($source['reference'] ?? '')
            ),
            default => throw new AppException(422, '不支持的运行资源类型'),
        };
        [$profile['definition']] = $this->definitions->normalize((array) $profile['definition']);
        if ($redactSensitiveValues) {
            foreach ($profile['definition']['env'] as &$environment) {
                if (preg_match('/(?:PASSWORD|PASSWD|TOKEN|SECRET|PRIVATE_KEY|ACCESS_KEY)/i', (string) ($environment['name'] ?? ''))) {
                    $environment['value'] = '••••••';
                }
            }
            unset($environment);
        }
        return $profile;
    }

    public function import(
        int $uid,
        Project $project,
        BuildArtifact $artifact,
        int $envId,
        array $source
    ): array {
        $orgId = (int) $project->org_id;
        $groupId = (int) $project->group_id;
        $clusterId = (int) ($source['cluster_id'] ?? 0);
        $cluster = $this->cluster($orgId, $groupId, $clusterId);
        $this->environment($orgId, $envId, $clusterId);
        $profile = $this->profile($orgId, $groupId, $source);
        $definition = (array) $profile['definition'];
        $definition['service_name'] = (string) $profile['resource_name'];
        $definition['resource_identity_version'] = 'imported-v1';

        if ((string) $source['type'] === self::DOCKER_CONTAINER) {
            return $this->migrateContainer(
                $uid, $project, $artifact, $envId, $cluster, $source, $definition
            );
        }

        return Db::transaction(function () use (
            $uid, $project, $artifact, $envId, $cluster, $source, $profile, $definition
        ): array {
            $now = time();
            $release = ProjectRelease::create([
                'org_id' => $project->org_id, 'group_id' => $project->group_id, 'project_id' => $project->id,
                'env_id' => $envId, 'cluster_id' => $cluster->id, 'artifact_id' => $artifact->id,
                'version' => 'import-' . $now, 'operation' => ProjectRelease::OPERATION_DEPLOY,
                'previous_release_id' => 0, 'remark' => '从已有运行资源导入',
                'status' => ProjectRelease::STATUS_SUCCEEDED, 'desired_spec' => $definition,
                'runtime_ref' => (string) $profile['runtime_ref'], 'error' => null,
                'result' => ['imported' => true, 'source' => $source],
                'started_at' => $now, 'finished_at' => $now, 'creator' => $uid,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            if ((string) $source['type'] === self::SWARM_SERVICE) {
                $claimed = $this->swarm->claimService($cluster, (string) $source['reference'], [
                    'com.codegalaxy.org.id' => (string) $project->org_id,
                    'com.codegalaxy.project.id' => (string) $project->id,
                    'com.codegalaxy.env.id' => (string) $envId,
                    'com.codegalaxy.release.id' => (string) $release->id,
                ]);
                $runtimeRef = (string) ($claimed['id'] ?? $profile['runtime_ref']);
                $workloadKind = ProjectRuntime::WORKLOAD_SWARM_SERVICE;
                $namespace = '';
                $metadata = ['service_name' => (string) $profile['resource_name'], 'imported' => true];
            } else {
                $claimed = $this->kubernetes->claimDeployment(
                    (int) $project->org_id,
                    (int) $cluster->id,
                    (string) $source['namespace'],
                    (string) $source['reference'],
                    [
                        'app.kubernetes.io/managed-by' => 'galaxy',
                        'codegalaxy.com/org-id' => (string) $project->org_id,
                        'codegalaxy.com/project-id' => (string) $project->id,
                        'codegalaxy.com/env-id' => (string) $envId,
                        'codegalaxy.com/release-id' => (string) $release->id,
                    ]
                );
                $runtimeRef = (string) ($claimed['metadata']['uid'] ?? $profile['runtime_ref']);
                $workloadKind = ProjectRuntime::WORKLOAD_KUBERNETES_DEPLOYMENT;
                $namespace = (string) $source['namespace'];
                $metadata = [
                    'deployment_name' => (string) $profile['resource_name'],
                    'service_name' => (string) $profile['resource_name'],
                    'imported' => true,
                ];
            }
            $release->runtime_ref = $runtimeRef;
            $release->save();
            $runtime = ProjectRuntime::create([
                'org_id' => $project->org_id, 'group_id' => $project->group_id, 'project_id' => $project->id,
                'env_id' => $envId, 'cluster_id' => $cluster->id, 'release_id' => $release->id,
                'orchestrator_type' => $cluster->orchestrator_type, 'workload_kind' => $workloadKind,
                'runtime_ref' => $runtimeRef, 'runtime_namespace' => $namespace,
                'service_name' => (string) $profile['resource_name'], 'name' => (string) $profile['title'],
                'desired_count' => (int) $profile['desired_count'], 'running_count' => (int) $profile['running_count'],
                'status' => (int) $profile['running_count'] > 0 ? 'running' : 'stopped',
                'health' => (int) $profile['running_count'] >= (int) $profile['desired_count'] ? 'healthy' : 'degraded',
                'last_error' => '', 'last_synced_at' => $now, 'spec' => $definition,
                'provider_metadata' => $metadata, 'created_at' => $now, 'updated_at' => $now,
            ]);
            return ['release' => $release, 'runtime' => $runtime, 'warnings' => $profile['warnings']];
        });
    }

    private function migrateContainer(
        int $uid,
        Project $project,
        BuildArtifact $artifact,
        int $envId,
        Cluster $cluster,
        array $source,
        array $definition
    ): array {
        $nodeId = (string) ($source['node_id'] ?? '');
        $containerId = (string) ($source['reference'] ?? '');
        $nodeCluster = clone $cluster;
        $nodeCluster->endpoint = 'agent://' . (int) $cluster->id . '/' . rawurlencode($nodeId);
        $this->swarm->stopContainer($nodeCluster, $containerId);
        try {
            $definition['bind_risk_acknowledged'] = true;
            $definition['runtime_import_source'] = [
                'type' => self::DOCKER_CONTAINER,
                'cluster_id' => (int) $cluster->id,
                'node_id' => $nodeId,
                'reference' => $containerId,
            ];
            $release = $this->releases->create(
                $uid,
                (int) $project->org_id,
                (int) $project->group_id,
                (int) $project->id,
                $envId,
                (int) $cluster->id,
                (int) $artifact->id,
                'import-' . time(),
                '从独立 Docker 容器迁移',
                $definition
            );
        } catch (\Throwable $e) {
            $this->swarm->startContainer($nodeCluster, $containerId);
            throw $e;
        }
        return [
            'release' => $release,
            'runtime' => null,
            'warnings' => ['源容器已停止但未删除，将在 Swarm Service 发布失败时用于手动回滚'],
        ];
    }

    private function swarmServiceProfile(Cluster $cluster, string $reference): array
    {
        $this->assertOrchestrator($cluster, Cluster::ORCHESTRATOR_DOCKER_SWARM);
        $service = $this->swarm->inspectService($cluster, $reference);
        if ((string) ($service['mode'] ?? '') !== 'replicated') {
            throw new AppException(422, '当前仅支持导入 Replicated Swarm Service');
        }
        if ((array) ($service['secrets'] ?? []) !== [] || (array) ($service['configs'] ?? []) !== []) {
            throw new AppException(422, '该 Service 使用了 Secret/Config；Docker 无法读取 Secret 原文，暂不能安全导入');
        }
        return $this->profileResult(
            self::SWARM_SERVICE,
            (string) $service['name'],
            (string) $service['id'],
            (string) $service['image'],
            (int) ($service['replicas'] ?? 1),
            (int) ($service['replicas'] ?? 1),
            [
                'instance_name' => (string) $service['name'],
                'replicas' => (int) ($service['replicas'] ?? 1),
                'command' => (array) ($service['command_array'] ?? []),
                'args' => (array) ($service['args_array'] ?? []),
                'env' => $this->environmentVariables((array) ($service['env'] ?? [])),
                'ports' => array_map(static fn (array $port): array => [
                    'target' => (int) ($port['target_port'] ?? 0),
                    'published' => (int) ($port['published_port'] ?? 0),
                    'protocol' => (string) ($port['protocol'] ?? 'tcp'),
                    'mode' => (string) ($port['publish_mode'] ?? 'ingress'),
                ], (array) ($service['ports'] ?? [])),
                'mounts' => (array) ($service['mounts'] ?? []),
                'bind_risk_acknowledged' => true,
                'networks' => array_values(array_filter(array_map(
                    static fn (array $network): string => (string) ($network['name'] ?? ''),
                    (array) ($service['networks'] ?? [])
                ))),
                'resources' => [
                    'limits' => [
                        'cpus' => (float) ($service['cpu_limit'] ?? 0),
                        'memory_mb' => (int) ceil(((int) ($service['memory_limit'] ?? 0)) / 1048576),
                    ],
                    'reservations' => [
                        'cpus' => (float) ($service['cpu_reserved'] ?? 0),
                        'memory_mb' => (int) ceil(((int) ($service['memory_reserved'] ?? 0)) / 1048576),
                    ],
                ],
                'update' => [
                    'parallelism' => (int) ($service['update_config']['parallelism'] ?? 1),
                    'delay_seconds' => 0,
                    'order' => (string) ($service['update_config']['order'] ?? 'stop-first'),
                    'failure_action' => (string) ($service['update_config']['failure_action'] ?? 'pause'),
                ],
            ],
            []
        );
    }

    private function deploymentProfile(int $orgId, int $clusterId, string $namespace, string $name): array
    {
        if ($namespace === '' || $name === '') {
            throw new AppException(422, 'Kubernetes namespace 和 Deployment 名称不能为空');
        }
        $deployment = $this->kubernetes->deployment($orgId, $clusterId, $namespace, $name);
        $containers = (array) ($deployment['spec']['template']['spec']['containers'] ?? []);
        if (count($containers) !== 1) {
            throw new AppException(422, '当前只支持导入恰好包含一个容器的 Kubernetes Deployment');
        }
        $container = (array) $containers[0];
        foreach ((array) ($container['env'] ?? []) as $env) {
            if (array_key_exists('valueFrom', $env)) {
                throw new AppException(422, 'Deployment 使用了 valueFrom 环境变量，暂不能安全导入');
            }
        }
        $replicas = (int) ($deployment['spec']['replicas'] ?? 1);
        $ready = (int) ($deployment['status']['readyReplicas'] ?? 0);
        return $this->profileResult(
            self::KUBERNETES_DEPLOYMENT,
            $name,
            (string) ($deployment['metadata']['uid'] ?? ''),
            (string) ($container['image'] ?? ''),
            $replicas,
            $ready,
            [
                'instance_name' => $name,
                'replicas' => $replicas,
                'command' => (array) ($container['command'] ?? []),
                'args' => (array) ($container['args'] ?? []),
                'env' => array_map(static fn (array $env): array => [
                    'name' => (string) ($env['name'] ?? ''), 'value' => (string) ($env['value'] ?? ''),
                ], (array) ($container['env'] ?? [])),
                'ports' => array_map(static fn (array $port): array => [
                    'target' => (int) ($port['containerPort'] ?? 0), 'published' => 0,
                    'protocol' => strtolower((string) ($port['protocol'] ?? 'tcp')), 'mode' => 'ingress',
                ], (array) ($container['ports'] ?? [])),
                'mounts' => [],
                'networks' => [],
            ],
            (array) ($container['volumeMounts'] ?? []) === []
                ? []
                : ['Kubernetes Volume Mount 不会复制到 Galaxy 发布配置，请导入后在实例配置中核对']
        );
    }

    private function containerProfile(Cluster $cluster, string $nodeId, string $reference): array
    {
        $this->assertOrchestrator($cluster, Cluster::ORCHESTRATOR_DOCKER_SWARM);
        if ($nodeId === '') {
            throw new AppException(422, '独立容器缺少节点 ID');
        }
        $nodeCluster = clone $cluster;
        $nodeCluster->endpoint = 'agent://' . (int) $cluster->id . '/' . rawurlencode($nodeId);
        $container = $this->swarm->inspectContainer($nodeCluster, $reference);
        $ports = [];
        foreach ((array) ($container['port_bindings'] ?? []) as $containerPort => $bindings) {
            [$target, $protocol] = array_pad(explode('/', (string) $containerPort, 2), 2, 'tcp');
            foreach ((array) $bindings ?: [[]] as $binding) {
                $ports[] = [
                    'target' => (int) $target,
                    'published' => (int) ($binding['HostPort'] ?? 0),
                    'protocol' => strtolower($protocol),
                    'mode' => 'host',
                ];
            }
        }
        return $this->profileResult(
            self::DOCKER_CONTAINER,
            (string) $container['name'],
            (string) $container['id'],
            (string) $container['image'],
            1,
            (string) $container['state'] === 'running' ? 1 : 0,
            [
                'instance_name' => (string) $container['name'],
                'replicas' => 1,
                'command' => (array) ($container['entrypoint_array'] ?? []),
                'args' => (array) ($container['cmd_array'] ?? []),
                'env' => $this->environmentVariables((array) ($container['env'] ?? [])),
                'ports' => $ports,
                'mounts' => array_map(static fn (array $mount): array => [
                    'type' => strtolower((string) ($mount['type'] ?? '')),
                    'source' => (string) (($mount['type'] ?? '') === 'volume'
                        ? ($mount['name'] ?? '')
                        : ($mount['source'] ?? '')),
                    'target' => (string) ($mount['destination'] ?? ''),
                    'readonly' => ! (bool) ($mount['rw'] ?? true),
                ], (array) ($container['mounts'] ?? [])),
                'bind_risk_acknowledged' => true,
                // A standalone container belongs to the inspected node. This is
                // especially important for bind mounts: rescheduling the
                // migrated Service to another node changes the filesystem it
                // sees, or makes the task fail before startup.
                'placement_node_id' => $nodeId,
                'networks' => [],
            ],
            ['独立容器将停止并迁移为 Swarm Service；源容器会保留，不会自动删除']
        );
    }

    private function profileResult(
        string $type,
        string $title,
        string $runtimeRef,
        string $image,
        int $desired,
        int $running,
        array $definition,
        array $warnings
    ): array {
        if ($image === '') {
            throw new AppException(422, '运行资源没有可导入的容器镜像');
        }
        $ports = (array) ($definition['ports'] ?? []);
        return [
            'type' => $type, 'title' => $title, 'resource_name' => $title,
            'runtime_ref' => $runtimeRef, 'image' => $image,
            'desired_count' => $desired, 'running_count' => $running,
            'default_port' => (int) ($ports[0]['target'] ?? 8080),
            'definition' => $definition, 'warnings' => $warnings,
        ];
    }

    private function environmentVariables(array $values): array
    {
        return array_values(array_filter(array_map(static function (string $value): ?array {
            $position = strpos($value, '=');
            return $position === false ? null : [
                'name' => substr($value, 0, $position),
                'value' => substr($value, $position + 1),
            ];
        }, $values)));
    }

    private function cluster(int $orgId, int $groupId, int $clusterId): Cluster
    {
        if (! GroupResourceGrant::canUseCluster($orgId, $groupId, $clusterId)) {
            throw new AppException(403, '当前项目组未获授权使用该集群');
        }
        $cluster = Cluster::where('id', $clusterId)->where('org_id', $orgId)
            ->where('status', Cluster::STATUS_READY)->first();
        if ($cluster === null) {
            throw new AppException(422, '请选择在线且可用的集群');
        }
        return $cluster;
    }

    private function environment(int $orgId, int $envId, int $clusterId): void
    {
        if (! Env::where('id', $envId)->where('org_id', $orgId)->where('archived_at', 0)->exists()
            || ! EnvClusterRel::where('org_id', $orgId)->where('env_id', $envId)
                ->where('cluster_id', $clusterId)->exists()) {
            throw new AppException(422, '所选环境未关联到目标集群');
        }
    }

    private function assertOrchestrator(Cluster $cluster, string $expected): void
    {
        if ((string) $cluster->orchestrator_type !== $expected) {
            throw new AppException(422, '运行资源类型与集群编排方式不匹配');
        }
    }
}

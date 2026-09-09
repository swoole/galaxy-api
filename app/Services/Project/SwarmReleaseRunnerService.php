<?php

namespace App\Services\Project;

use App\Exception\AppException;
use App\Model\ProjectRelease;
use App\Model\ProjectReleaseConfig;
use App\Model\ProjectReleaseSecret;
use App\Model\ProjectRuntime;
use App\Model\BuildArtifact;
use App\Model\Cluster;
use App\Model\ClusterWebGateway;
use App\Model\GatewayVhost;
use App\Services\Docker\SwarmApiClient;
use App\Services\Docker\SwarmServiceReferenceLock;
use App\Services\RegistryService;
use App\Services\RegistryGroupGrantService;
use App\Services\ContainerImageMappingService;
use GuzzleHttp\Client;
use Swoole\Coroutine;
use Throwable;

class SwarmReleaseRunnerService
{
    public function __construct(
        private SwarmApiClient $docker,
        private ReleaseSecretService $secrets,
        private ProjectRouteService $routes,
        private RegistryService $registries,
        private RegistryGroupGrantService $registryGrants,
        private ContainerImageMappingService $imageMappings,
        private ManagedSwarmResourceGuard $guard,
        private ProjectRuntimeLock $runtimeLock,
        private SwarmServiceReferenceLock $serviceReferenceLock
    ) {}

    public function run(ProjectRelease $release): array
    {
        /** @var Cluster|null $cluster */
        $cluster = Cluster::where('id', (int) $release->cluster_id)
            ->where('org_id', (int) $release->org_id)
            ->where('orchestrator_type', Cluster::ORCHESTRATOR_DOCKER_SWARM)
            ->first();
        /** @var BuildArtifact|null $artifact */
        $artifact = BuildArtifact::where('id', (int) $release->artifact_id)
            ->where('org_id', (int) $release->org_id)
            ->where('group_id', (int) $release->group_id)
            ->where('project_id', (int) $release->project_id)
            ->first();
        if ($cluster === null || $artifact === null) {
            throw new AppException(404, '发布使用的 Swarm 集群或镜像制品不存在');
        }
        $artifactMetadata = (array) $artifact->metadata;
        $registryId = (int) ($artifactMetadata['registry_id'] ?? 0);
        if ($registryId > 0) {
            $this->registryGrants->assertReference(
                (int) $release->org_id,
                (int) $release->group_id,
                $registryId,
                (string) $artifact->reference
            );
        }
        $definition = (array) $release->desired_spec;
        $logicalName = ProjectServiceIdentity::instanceName($definition) ?: 'project';
        $runtime = ProjectRuntime::where('org_id', (int) $release->org_id)
            ->where('group_id', (int) $release->group_id)
            ->where('project_id', (int) $release->project_id)
            ->where('name', $logicalName)
            ->first();
        $serviceName = $runtime === null
            ? ProjectServiceIdentity::releaseResourceName(
                $definition,
                (int) $release->org_id,
                (int) $release->project_id,
                (int) $release->env_id
            )
            : ProjectServiceIdentity::runtimeDockerName($runtime);
        $timeout = max(30, (int) config('project-release.timeout', 600));

        return $this->runtimeLock->synchronized(
            (int) $release->org_id,
            (int) $release->project_id,
            (int) $release->env_id,
            (int) $release->cluster_id,
            $logicalName,
            fn (): array => $this->docker->withCluster($cluster, function (Client $client, SwarmApiClient $api) use (
                $release, $artifact, $definition, $serviceName, $logicalName, $runtime, $timeout, $cluster
            ): array {
            $secretRefs = $this->ensureSecrets($client, $api, $release, $serviceName);
            $configRefs = $this->ensureConfigs($client, $api, $release, $serviceName);
            [$serviceId, $operation] = $this->serviceReferenceLock->synchronized(
                (int) $release->org_id,
                (int) $release->cluster_id,
                [$serviceName],
                function () use (
                    $client,
                    $api,
                    $release,
                    $artifact,
                    $definition,
                    $serviceName,
                    $secretRefs,
                    $configRefs,
                    $runtime,
                    $cluster
                ): array {
                    $spec = $this->serviceSpec(
                        $release,
                        $artifact,
                        $definition,
                        $serviceName,
                        $secretRefs,
                        $configRefs,
                        $runtime,
                        $cluster
                    );
                    $metadata = (array) $artifact->metadata;
                    $runtimeImage = (string) $spec['TaskTemplate']['ContainerSpec']['Image'];
                    $mapped = $runtimeImage !== $this->imageReference($artifact);
                    $headers = $this->registries->dockerAuthHeader(
                        (int) $release->org_id,
                        $runtimeImage,
                        $mapped ? 0 : (int) ($metadata['registry_id'] ?? 0)
                    );
                    $serviceId = (string) ($runtime?->runtime_ref ?? '');
                    $operation = 'create';
                    if ($serviceId !== '') {
                        try {
                            $api->request($client, 'GET', '/services/' . rawurlencode($serviceId));
                        } catch (AppException $e) {
                            if (! str_contains($e->getMessage(), '404')) {
                                throw $e;
                            }
                            $serviceId = '';
                        }
                    }
                    if ($serviceId === '') {
                        $existing = $api->request($client, 'GET', '/services', [
                            'query' => ['filters' => json_encode(['name' => [$serviceName]], JSON_THROW_ON_ERROR)],
                        ]);
                        foreach ($existing as $candidate) {
                            if (($candidate['Spec']['Name'] ?? '') === $serviceName) {
                                $serviceId = (string) ($candidate['ID'] ?? '');
                                break;
                            }
                        }
                    }
                    if ($serviceId !== '') {
                        $current = $api->request($client, 'GET', '/services/' . rawurlencode($serviceId));
                        $this->guard->assertService(
                            $current,
                            (int) $release->org_id,
                            (int) $release->project_id,
                            (int) $release->env_id,
                            $serviceName
                        );
                        $api->request($client, 'POST', '/services/' . rawurlencode($serviceId) . '/update', [
                            'query' => [
                                'version' => (int) ($current['Version']['Index'] ?? 0),
                                'registryAuthFrom' => 'spec',
                            ],
                            'headers' => $headers,
                            'json' => $spec,
                        ]);
                        $operation = 'update';
                    } else {
                        $created = $api->request($client, 'POST', '/services/create', [
                            'headers' => $headers,
                            'json' => $spec,
                        ]);
                        $serviceId = (string) ($created['ID'] ?? '');
                        if ($serviceId === '') {
                            throw new AppException(502, 'Docker API 未返回 Swarm Service ID');
                        }
                    }
                    $release->runtime_ref = $serviceId;
                    $release->updated_at = time();
                    $release->save();
                    return [$serviceId, $operation];
                }
            );
            if ($runtime === null) {
                // Track a newly-created remote Service before convergence. If
                // tasks fail to start, the UI and app deletion flow must still
                // be able to inspect and safely remove the managed Service.
                $now = time();
                $runtime = ProjectRuntime::updateOrCreate(
                    [
                        'org_id' => (int) $release->org_id,
                        'project_id' => (int) $release->project_id,
                        'env_id' => (int) $release->env_id,
                        'cluster_id' => (int) $release->cluster_id,
                        'name' => $logicalName,
                    ],
                    [
                        'group_id' => (int) $release->group_id,
                        'release_id' => (int) $release->id,
                        'orchestrator_type' => Cluster::ORCHESTRATOR_DOCKER_SWARM,
                        'workload_kind' => ProjectRuntime::WORKLOAD_SWARM_SERVICE,
                        'runtime_ref' => $serviceId,
                        'runtime_namespace' => '',
                        'service_name' => $serviceName,
                        'desired_count' => (int) $definition['replicas'],
                        'running_count' => 0,
                        'status' => 'deploying',
                        'health' => 'unknown',
                        'last_error' => '',
                        'last_synced_at' => 0,
                        'spec' => $definition,
                        'provider_metadata' => $this->runtimeProviderMetadata($definition, $serviceName),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]
                );
            }
            $state = $this->waitForConvergence(
                $client,
                $api,
                $release,
                $serviceId,
                (int) $definition['replicas'],
                $timeout
            );
            $now = time();
            ProjectRuntime::updateOrCreate(
                [
                    'org_id' => (int) $release->org_id, 'project_id' => (int) $release->project_id,
                    'env_id' => (int) $release->env_id, 'cluster_id' => (int) $release->cluster_id,
                    'name' => $logicalName,
                ],
                [
                    'group_id' => (int) $release->group_id,
                    'release_id' => (int) $release->id,
                    'orchestrator_type' => Cluster::ORCHESTRATOR_DOCKER_SWARM,
                    'workload_kind' => ProjectRuntime::WORKLOAD_SWARM_SERVICE,
                    'runtime_ref' => $serviceId,
                    'runtime_namespace' => '',
                    'service_name' => $serviceName,
                    'desired_count' => (int) $definition['replicas'],
                    'running_count' => (int) $state['running'],
                    'status' => (int) $definition['replicas'] === 0 ? 'stopped' : 'running',
                    'health' => 'healthy', 'last_error' => '',
                    'last_synced_at' => $now, 'spec' => $definition,
                    'provider_metadata' => $this->runtimeProviderMetadata($definition, $serviceName),
                    'created_at' => $runtime?->created_at ?: $now, 'updated_at' => $now,
                ]
            );
            $removedSecrets = $this->cleanupObsoleteSecrets($client, $api, $release, $serviceId);
            $removedConfigs = $this->cleanupObsoleteConfigs($client, $api, $release, $serviceId);
            return [
                'operation' => $operation,
                'service_id' => $serviceId,
                'service_name' => $serviceName,
                'removed_obsolete_secrets' => $removedSecrets,
                'removed_obsolete_configs' => $removedConfigs,
            ] + $state;
            }, $timeout + 30),
            30,
            $timeout + 120
        );
    }

    /**
     * Repair a release whose queue worker disappeared after Docker accepted
     * the Service mutation. Success is proven from the managed Service and its
     * running tasks; a healthy runtime snapshot alone is not authoritative.
     */
    public function reconcileSucceededRelease(ProjectRelease $release): ?array
    {
        if ((string) $release->status !== ProjectRelease::STATUS_DEPLOYING
            || trim((string) $release->runtime_ref) === '') {
            return null;
        }
        /** @var Cluster|null $cluster */
        $cluster = Cluster::where('id', (int) $release->cluster_id)
            ->where('org_id', (int) $release->org_id)
            ->where('orchestrator_type', Cluster::ORCHESTRATOR_DOCKER_SWARM)
            ->first();
        if ($cluster === null) {
            return null;
        }
        $definition = (array) $release->desired_spec;
        $logicalName = ProjectServiceIdentity::instanceName($definition);
        $runtime = $this->runtimeForReleaseTarget($release, $logicalName);
        $serviceName = $runtime === null
            ? ProjectServiceIdentity::releaseResourceName(
                $definition,
                (int) $release->org_id,
                (int) $release->project_id,
                (int) $release->env_id
            )
            : ProjectServiceIdentity::runtimeDockerName($runtime);
        $serviceId = (string) $release->runtime_ref;
        $replicas = (int) ($definition['replicas'] ?? 0);

        return $this->runtimeLock->synchronized(
            (int) $release->org_id,
            (int) $release->project_id,
            (int) $release->env_id,
            (int) $release->cluster_id,
            $logicalName,
            fn (): ?array => $this->docker->withCluster($cluster, function (Client $client, SwarmApiClient $api) use (
                $release, $definition, $logicalName, $serviceName, $serviceId, $replicas
            ): ?array {
            $service = $api->request($client, 'GET', '/services/' . rawurlencode($serviceId));
            $actualServiceName = (string) ($service['Spec']['Name'] ?? '');
            $this->assertExpectedServiceName($release, $logicalName, $serviceName, $actualServiceName);
            $this->guard->assertService(
                $service,
                (int) $release->org_id,
                (int) $release->project_id,
                (int) $release->env_id,
                $actualServiceName
            );
            $labels = (array) ($service['Spec']['Labels'] ?? []);
            $desiredReplicas = (int) ($service['Spec']['Mode']['Replicated']['Replicas'] ?? -1);
            $updateState = (string) ($service['UpdateStatus']['State'] ?? '');
            if ((int) ($labels['com.codegalaxy.release.id'] ?? 0) !== (int) $release->id
                || $desiredReplicas !== $replicas
                || in_array($updateState, ['updating', 'rollback_started', 'paused'], true)) {
                return null;
            }
            $tasks = $api->request($client, 'GET', '/tasks', [
                'query' => ['filters' => json_encode(['service' => [$serviceId]], JSON_THROW_ON_ERROR)],
            ]);
            $states = [];
            foreach ($tasks as $task) {
                if (($task['DesiredState'] ?? 'running') !== 'running') {
                    continue;
                }
                $state = (string) ($task['Status']['State'] ?? 'unknown');
                $states[$state] = ($states[$state] ?? 0) + 1;
            }
            $running = (int) ($states['running'] ?? 0);
            if ($replicas > 0 && $running < $replicas) {
                return null;
            }

            $now = time();
            $runtime = ProjectRuntime::firstOrNew([
                'org_id' => (int) $release->org_id,
                'project_id' => (int) $release->project_id,
                'env_id' => (int) $release->env_id,
                'cluster_id' => (int) $release->cluster_id,
                'name' => $logicalName,
            ]);
            if (! $runtime->exists) {
                $runtime->created_at = $now;
            }
            $runtime->group_id = (int) $release->group_id;
            $runtime->release_id = (int) $release->id;
            $runtime->orchestrator_type = Cluster::ORCHESTRATOR_DOCKER_SWARM;
            $runtime->workload_kind = ProjectRuntime::WORKLOAD_SWARM_SERVICE;
            $runtime->runtime_ref = $serviceId;
            $runtime->runtime_namespace = '';
            $runtime->service_name = $actualServiceName;
            $runtime->desired_count = $replicas;
            $runtime->running_count = $running;
            $runtime->status = $replicas === 0 ? 'stopped' : 'running';
            $runtime->health = 'healthy';
            $runtime->last_error = '';
            $runtime->last_synced_at = $now;
            $runtime->spec = $definition;
            $runtime->provider_metadata = $this->runtimeProviderMetadata($definition, $actualServiceName);
            $runtime->updated_at = $now;
            $runtime->save();
            return [
                'operation' => 'reconcile',
                'service_id' => $serviceId,
                'service_name' => $actualServiceName,
                'running' => $running,
                'states' => $states,
                'reconciled_at' => $now,
            ];
            }, 30),
            0,
            120
        );
    }

    /** Remove failed-release Docker Configs/Secrets before local snapshots. */
    public function cleanupFailedReleaseSecrets(ProjectRelease $release): bool
    {
        $hasSecrets = ProjectReleaseSecret::where('release_id', (int) $release->id)->exists();
        $hasConfigs = ProjectReleaseConfig::where('release_id', (int) $release->id)->exists();
        /** @var Cluster|null $cluster */
        $cluster = Cluster::where('id', (int) $release->cluster_id)
            ->where('org_id', (int) $release->org_id)
            ->where('orchestrator_type', Cluster::ORCHESTRATOR_DOCKER_SWARM)
            ->first();
        if ($cluster === null) {
            return false;
        }
        $this->docker->withCluster($cluster, function (Client $client, SwarmApiClient $api) use ($release): void {
            $serviceId = $this->reconcileFailedRemoteState($client, $api, $release);
            $this->cleanupSecretRows(
                $client,
                $api,
                ProjectReleaseSecret::where('release_id', (int) $release->id)->get()->all(),
                $serviceId,
                (int) $release->project_id
            );
            $this->cleanupConfigRows(
                $client,
                $api,
                ProjectReleaseConfig::where('release_id', (int) $release->id)->get()->all(),
                $serviceId,
                (int) $release->project_id
            );
        });
        if (! $hasSecrets && ! $hasConfigs) {
            return $this->markFailureReconciled($release);
        }
        if (ProjectReleaseSecret::where('release_id', (int) $release->id)
            ->where('docker_secret_id', '<>', '')->exists()) {
            return false;
        }
        if (ProjectReleaseConfig::where('release_id', (int) $release->id)
            ->where('docker_config_id', '<>', '')->exists()) {
            return false;
        }
        ProjectReleaseSecret::where('release_id', (int) $release->id)->delete();
        ProjectReleaseConfig::where('release_id', (int) $release->id)->delete();
        return $this->markFailureReconciled($release);
    }

    private function markFailureReconciled(ProjectRelease $release): bool
    {
        $result = (array) ($release->result ?? []);
        $result['failure_reconciled_at'] = time();
        $release->result = $result;
        $release->updated_at = time();
        $release->save();
        return true;
    }

    private function reconcileFailedRemoteState(
        Client $client,
        SwarmApiClient $api,
        ProjectRelease $release
    ): string {
        $definition = (array) $release->desired_spec;
        $logicalName = ProjectServiceIdentity::instanceName($definition) ?: 'project';
        $runtime = $this->runtimeForReleaseTarget($release, $logicalName);
        $serviceName = $runtime === null
            ? ProjectServiceIdentity::releaseResourceName(
                $definition,
                (int) $release->org_id,
                (int) $release->project_id,
                (int) $release->env_id
            )
            : ProjectServiceIdentity::runtimeDockerName($runtime);
        $candidateNames = array_values(array_unique([
            $serviceName,
            ProjectServiceIdentity::legacyDockerName(
                (int) $release->org_id,
                (int) $release->project_id,
                $logicalName
            ),
        ]));
        $serviceId = (string) $release->runtime_ref;
        if ($serviceId === '') {
            $services = $api->request($client, 'GET', '/services', [
                'query' => ['filters' => json_encode(['name' => $candidateNames], JSON_THROW_ON_ERROR)],
            ]);
            foreach ($services as $service) {
                $actualServiceName = (string) ($service['Spec']['Name'] ?? '');
                if (! in_array($actualServiceName, $candidateNames, true)) {
                    continue;
                }
                $this->guard->assertService(
                    (array) $service,
                    (int) $release->org_id,
                    (int) $release->project_id,
                    (int) $release->env_id,
                    $actualServiceName
                );
                if ((int) (($service['Spec']['Labels']['com.codegalaxy.release.id'] ?? 0)) !== (int) $release->id) {
                    continue;
                }
                $serviceId = (string) ($service['ID'] ?? '');
                $serviceName = $actualServiceName;
                break;
            }
            if ($serviceId !== '') {
                $release->runtime_ref = $serviceId;
                $release->updated_at = time();
                $release->save();
            }
        } else {
            try {
                $service = $api->request($client, 'GET', '/services/' . rawurlencode($serviceId));
                $actualServiceName = (string) ($service['Spec']['Name'] ?? '');
                $this->assertExpectedServiceName($release, $logicalName, $serviceName, $actualServiceName);
                $serviceName = $actualServiceName;
                $this->guard->assertService(
                    $service,
                    (int) $release->org_id,
                    (int) $release->project_id,
                    (int) $release->env_id,
                    $actualServiceName
                );
                if ((int) (($service['Spec']['Labels']['com.codegalaxy.release.id'] ?? 0)) !== (int) $release->id) {
                    $serviceId = '';
                    // Rollback/scale rows initially carry the current Runtime
                    // reference. If Docker never applied this release, do not
                    // leave the failed row claiming ownership of the old Service.
                    $release->runtime_ref = '';
                    $release->updated_at = time();
                    $release->save();
                }
            } catch (AppException $e) {
                if (! str_contains($e->getMessage(), '404')) {
                    throw $e;
                }
                $serviceId = '';
            }
        }

        if ($serviceId !== '') {
            $now = time();
            $existingRuntime = ProjectRuntime::where('org_id', (int) $release->org_id)
                ->where('project_id', (int) $release->project_id)
                ->where('env_id', (int) $release->env_id)
                ->where('cluster_id', (int) $release->cluster_id)
                ->where('name', $logicalName)
                ->first();
            ProjectRuntime::updateOrCreate([
                'org_id' => (int) $release->org_id,
                'project_id' => (int) $release->project_id,
                'env_id' => (int) $release->env_id,
                'cluster_id' => (int) $release->cluster_id,
                'name' => $logicalName,
            ], [
                'group_id' => (int) $release->group_id,
                'release_id' => (int) $release->id,
                'orchestrator_type' => Cluster::ORCHESTRATOR_DOCKER_SWARM,
                'workload_kind' => ProjectRuntime::WORKLOAD_SWARM_SERVICE,
                'runtime_ref' => $serviceId,
                'runtime_namespace' => '',
                'service_name' => $serviceName,
                'desired_count' => (int) ($definition['replicas'] ?? 0),
                'running_count' => 0,
                'status' => 'failed',
                'health' => 'unhealthy',
                'last_error' => (string) ($release->error ?? '发布结果未能确认'),
                'last_synced_at' => 0,
                'spec' => $definition,
                'provider_metadata' => $this->runtimeProviderMetadata($definition, $serviceName),
                'created_at' => (int) ($existingRuntime?->created_at ?: $now),
                'updated_at' => $now,
            ]);
        }

        foreach (ProjectReleaseSecret::where('release_id', (int) $release->id)->get() as $secret) {
            if ((string) $secret->docker_secret_id !== '') {
                continue;
            }
            $secretName = substr($serviceName . '-r' . $release->id . '-' . $secret->name, 0, 253);
            $remotes = $api->request($client, 'GET', '/secrets', [
                'query' => ['filters' => json_encode(['name' => [$secretName]], JSON_THROW_ON_ERROR)],
            ]);
            foreach ($remotes as $remote) {
                if ((string) ($remote['Spec']['Name'] ?? '') !== $secretName) {
                    continue;
                }
                $this->guard->assertSecret(
                    (array) $remote,
                    (int) $release->id,
                    (int) $release->project_id,
                    $secretName
                );
                $secret->docker_secret_id = (string) ($remote['ID'] ?? '');
                $secret->docker_secret_name = $secretName;
                $secret->save();
                break;
            }
        }

        foreach (ProjectReleaseConfig::where('release_id', (int) $release->id)->get() as $config) {
            if ((string) $config->docker_config_id !== '') {
                continue;
            }
            $configName = substr($serviceName . '-r' . $release->id . '-' . $config->name, 0, 253);
            $remotes = $api->request($client, 'GET', '/configs', [
                'query' => ['filters' => json_encode(['name' => [$configName]], JSON_THROW_ON_ERROR)],
            ]);
            foreach ($remotes as $remote) {
                if ((string) ($remote['Spec']['Name'] ?? '') !== $configName) {
                    continue;
                }
                $this->guard->assertConfig((array) $remote, (int) $release->id, (int) $release->project_id, $configName);
                $config->docker_config_id = (string) ($remote['ID'] ?? '');
                $config->docker_config_name = $configName;
                $config->save();
                break;
            }
        }

        return $serviceId;
    }

    private function runtimeForReleaseTarget(ProjectRelease $release, string $logicalName): ?ProjectRuntime
    {
        return ProjectRuntime::where('org_id', (int) $release->org_id)
            ->where('group_id', (int) $release->group_id)
            ->where('project_id', (int) $release->project_id)
            ->where('env_id', (int) $release->env_id)
            ->where('cluster_id', (int) $release->cluster_id)
            ->where('name', $logicalName)
            ->first();
    }

    private function assertExpectedServiceName(
        ProjectRelease $release,
        string $logicalName,
        string $expectedName,
        string $actualName
    ): void {
        $allowed = array_values(array_unique([
            $expectedName,
            ProjectServiceIdentity::legacyDockerName(
                (int) $release->org_id,
                (int) $release->project_id,
                $logicalName
            ),
        ]));
        if (! in_array($actualName, $allowed, true)) {
            throw new AppException(409, '远程 Service 名称与项目 Runtime 身份不一致，拒绝接管');
        }
    }

    public function cleanupRuntimeSecrets(
        Cluster $cluster,
        int $orgId,
        int $projectId,
        int $envId,
        int $clusterId,
        string $serviceName
    ): bool {
        $releaseIds = $this->runtimeReleaseIds($orgId, $projectId, $envId, $clusterId, $serviceName);
        if ($releaseIds === []) {
            return true;
        }
        try {
            $this->docker->withCluster(
                $cluster,
                function (Client $client, SwarmApiClient $api) use ($releaseIds, $projectId): void {
                    $this->cleanupSecretRows(
                        $client, $api, ProjectReleaseSecret::whereIn('release_id', $releaseIds)
                            ->where('docker_secret_id', '<>', '')->get()->all(), '', $projectId
                    );
                    $this->cleanupConfigRows(
                        $client, $api, ProjectReleaseConfig::whereIn('release_id', $releaseIds)
                            ->where('docker_config_id', '<>', '')->get()->all(), '', $projectId
                    );
                }
            );
        } catch (Throwable) {
            return false;
        }
        return ! ProjectReleaseSecret::whereIn('release_id', $releaseIds)
            ->where('docker_secret_id', '<>', '')->exists()
            && ! ProjectReleaseConfig::whereIn('release_id', $releaseIds)
                ->where('docker_config_id', '<>', '')->exists();
    }

    private function ensureSecrets(Client $client, SwarmApiClient $api, ProjectRelease $release, string $serviceName): array
    {
        $refs = [];
        /** @var ProjectReleaseSecret $secret */
        foreach (ProjectReleaseSecret::where('release_id', (int) $release->id)->get() as $secret) {
            $name = substr($serviceName . '-r' . $release->id . '-' . $secret->name, 0, 253);
            $id = (string) $secret->docker_secret_id;
            if ($id !== '') {
                try {
                    $remote = $api->request($client, 'GET', '/secrets/' . rawurlencode($id));
                    $this->guard->assertSecret($remote, (int) $release->id, (int) $release->project_id, $name);
                } catch (AppException $e) {
                    if (! str_contains($e->getMessage(), '404')) {
                        throw $e;
                    }
                    $id = '';
                }
            }
            if ($id === '') {
                $existing = $api->request($client, 'GET', '/secrets', [
                    'query' => ['filters' => json_encode(['name' => [$name]], JSON_THROW_ON_ERROR)],
                ]);
                if (isset($existing[0])) {
                    $this->guard->assertSecret(
                        (array) $existing[0],
                        (int) $release->id,
                        (int) $release->project_id,
                        $name
                    );
                    $id = (string) ($existing[0]['ID'] ?? '');
                }
            }
            if ($id === '') {
                $created = $api->request($client, 'POST', '/secrets/create', [
                    'json' => [
                        'Name' => $name,
                        'Data' => base64_encode($this->secrets->plaintext($secret)),
                        'Labels' => [
                            'com.codegalaxy.release.id' => (string) $release->id,
                            'com.codegalaxy.group.id' => (string) $release->group_id,
                            'com.codegalaxy.project.id' => (string) $release->project_id,
                        ],
                    ],
                ]);
                $id = (string) ($created['ID'] ?? '');
            }
            if ($id === '') {
                throw new AppException(502, '无法创建 Docker Secret：' . $secret->name);
            }
            $secret->docker_secret_id = $id;
            $secret->docker_secret_name = $name;
            $secret->save();
            $refs[] = [
                'SecretID' => $id,
                'SecretName' => $name,
                'File' => [
                    'Name' => (string) $secret->target, 'UID' => '0', 'GID' => '0',
                    'Mode' => (int) $secret->file_mode,
                ],
            ];
        }
        return $refs;
    }

    private function ensureConfigs(Client $client, SwarmApiClient $api, ProjectRelease $release, string $serviceName): array
    {
        $refs = [];
        /** @var ProjectReleaseConfig $config */
        foreach (ProjectReleaseConfig::where('release_id', (int) $release->id)->get() as $config) {
            $name = substr($serviceName . '-r' . $release->id . '-' . $config->name, 0, 253);
            $id = (string) $config->docker_config_id;
            if ($id !== '') {
                try {
                    $remote = $api->request($client, 'GET', '/configs/' . rawurlencode($id));
                    $this->guard->assertConfig($remote, (int) $release->id, (int) $release->project_id, $name);
                } catch (AppException $e) {
                    if (! str_contains($e->getMessage(), '404')) {
                        throw $e;
                    }
                    $id = '';
                }
            }
            if ($id === '') {
                $existing = $api->request($client, 'GET', '/configs', [
                    'query' => ['filters' => json_encode(['name' => [$name]], JSON_THROW_ON_ERROR)],
                ]);
                if (isset($existing[0])) {
                    $this->guard->assertConfig((array) $existing[0], (int) $release->id, (int) $release->project_id, $name);
                    $id = (string) ($existing[0]['ID'] ?? '');
                }
            }
            if ($id === '') {
                $created = $api->request($client, 'POST', '/configs/create', ['json' => [
                    'Name' => $name,
                    'Data' => base64_encode((string) $config->content),
                    'Labels' => [
                        'com.codegalaxy.release.id' => (string) $release->id,
                        'com.codegalaxy.group.id' => (string) $release->group_id,
                        'com.codegalaxy.project.id' => (string) $release->project_id,
                    ],
                ]]);
                $id = (string) ($created['ID'] ?? '');
            }
            if ($id === '') {
                throw new AppException(502, '无法创建 Docker Config：' . $config->name);
            }
            $config->docker_config_id = $id;
            $config->docker_config_name = $name;
            $config->save();
            $refs[] = [
                'ConfigID' => $id, 'ConfigName' => $name,
                'File' => [
                    'Name' => (string) $config->target, 'UID' => '0', 'GID' => '0',
                    'Mode' => (int) $config->file_mode,
                ],
            ];
        }
        return $refs;
    }

    private function cleanupObsoleteSecrets(
        Client $client,
        SwarmApiClient $api,
        ProjectRelease $release,
        string $serviceId
    ): int {
        $releaseIds = array_values(array_filter(
            $this->runtimeReleaseIds(
                (int) $release->org_id,
                (int) $release->project_id,
                (int) $release->env_id,
                (int) $release->cluster_id,
                (string) (((array) $release->desired_spec)['service_name'] ?? '')
            ),
            static fn (int $id): bool => $id !== (int) $release->id
        ));
        if ($releaseIds === []) {
            return 0;
        }
        return $this->cleanupSecretRows(
            $client,
            $api,
            ProjectReleaseSecret::whereIn('release_id', $releaseIds)
                ->where('docker_secret_id', '<>', '')->get()->all(),
            $serviceId,
            (int) $release->project_id
        );
    }

    private function cleanupObsoleteConfigs(
        Client $client,
        SwarmApiClient $api,
        ProjectRelease $release,
        string $serviceId
    ): int {
        $releaseIds = array_values(array_filter(
            $this->runtimeReleaseIds(
                (int) $release->org_id, (int) $release->project_id, (int) $release->env_id,
                (int) $release->cluster_id,
                (string) (((array) $release->desired_spec)['service_name'] ?? '')
            ),
            static fn (int $id): bool => $id !== (int) $release->id
        ));
        if ($releaseIds === []) {
            return 0;
        }
        return $this->cleanupConfigRows(
            $client, $api, ProjectReleaseConfig::whereIn('release_id', $releaseIds)
                ->where('docker_config_id', '<>', '')->get()->all(), $serviceId, (int) $release->project_id
        );
    }

    /** @param ProjectReleaseSecret[] $secrets */
    private function cleanupSecretRows(
        Client $client,
        SwarmApiClient $api,
        array $secrets,
        string $serviceId,
        int $projectId
    ): int {
        $referenced = [];
        if ($serviceId !== '') {
            try {
                $service = $api->request($client, 'GET', '/services/' . rawurlencode($serviceId));
                foreach ((array) ($service['Spec']['TaskTemplate']['ContainerSpec']['Secrets'] ?? []) as $ref) {
                    $referenced[(string) ($ref['SecretID'] ?? '')] = true;
                }
            } catch (AppException $e) {
                if (! str_contains($e->getMessage(), '404')) {
                    return 0;
                }
            }
        }
        $removed = 0;
        foreach ($secrets as $secret) {
            $id = (string) $secret->docker_secret_id;
            if ($id === '' || isset($referenced[$id])) {
                continue;
            }
            try {
                $remote = $api->request($client, 'GET', '/secrets/' . rawurlencode($id));
                $labels = (array) ($remote['Spec']['Labels'] ?? []);
                if ((int) ($labels['com.codegalaxy.app.id'] ?? $labels['com.codegalaxy.project.id'] ?? 0) !== $projectId
                    || (int) ($labels['com.codegalaxy.release.id'] ?? 0) !== (int) $secret->release_id) {
                    continue;
                }
                $api->request($client, 'DELETE', '/secrets/' . rawurlencode($id));
            } catch (AppException $e) {
                if (! str_contains($e->getMessage(), '404')) {
                    continue;
                }
            }
            $secret->docker_secret_id = '';
            $secret->docker_secret_name = '';
            $secret->save();
            ++$removed;
        }
        return $removed;
    }

    /** @param ProjectReleaseConfig[] $configs */
    private function cleanupConfigRows(
        Client $client,
        SwarmApiClient $api,
        array $configs,
        string $serviceId,
        int $projectId
    ): int {
        $referenced = [];
        if ($serviceId !== '') {
            try {
                $service = $api->request($client, 'GET', '/services/' . rawurlencode($serviceId));
                foreach ((array) ($service['Spec']['TaskTemplate']['ContainerSpec']['Configs'] ?? []) as $ref) {
                    $referenced[(string) ($ref['ConfigID'] ?? '')] = true;
                }
            } catch (AppException $e) {
                if (! str_contains($e->getMessage(), '404')) {
                    return 0;
                }
            }
        }
        $removed = 0;
        foreach ($configs as $config) {
            $id = (string) $config->docker_config_id;
            if ($id === '' || isset($referenced[$id])) {
                continue;
            }
            try {
                $remote = $api->request($client, 'GET', '/configs/' . rawurlencode($id));
                $labels = (array) ($remote['Spec']['Labels'] ?? []);
                if ((int) ($labels['com.codegalaxy.app.id'] ?? $labels['com.codegalaxy.project.id'] ?? 0) !== $projectId
                    || (int) ($labels['com.codegalaxy.release.id'] ?? 0) !== (int) $config->release_id) {
                    continue;
                }
                $api->request($client, 'DELETE', '/configs/' . rawurlencode($id));
            } catch (AppException $e) {
                if (! str_contains($e->getMessage(), '404')) {
                    continue;
                }
            }
            $config->docker_config_id = '';
            $config->docker_config_name = '';
            $config->save();
            ++$removed;
        }
        return $removed;
    }

    private function runtimeReleaseIds(
        int $orgId,
        int $projectId,
        int $envId,
        int $clusterId,
        string $serviceName
    ): array {
        return ProjectRelease::where('org_id', $orgId)->where('project_id', $projectId)
            ->where('env_id', $envId)->where('cluster_id', $clusterId)
            ->get(['id', 'desired_spec'])
            ->filter(static fn (ProjectRelease $candidate): bool =>
                (string) (((array) $candidate->desired_spec)['service_name'] ?? '') === $serviceName
            )
            ->pluck('id')->map(static fn ($id): int => (int) $id)->all();
    }

    private function runtimeProviderMetadata(array $definition, string $serviceName): array
    {
        $metadata = ['service_name' => $serviceName];
        $source = (array) ($definition['runtime_import_source'] ?? []);
        if (($source['type'] ?? '') === ProjectRuntimeImportService::DOCKER_CONTAINER) {
            $metadata['import_source'] = $source;
        }
        return $metadata;
    }

    private function serviceSpec(
        ProjectRelease $release,
        BuildArtifact $artifact,
        array $definition,
        string $serviceName,
        array $secretRefs,
        array $configRefs,
        ?ProjectRuntime $runtime,
        Cluster $cluster
    ): array {
        $container = [
            'Image' => $this->runtimeImageReference($artifact, $cluster),
            'Env' => array_map(static fn (array $item): string => $item['name'] . '=' . $item['value'], (array) $definition['env']),
            'Mounts' => array_map(static function (array $mount): array {
                $result = ['Type' => $mount['type'], 'Target' => $mount['target'], 'ReadOnly' => (bool) $mount['readonly']];
                if ($mount['type'] !== 'tmpfs') {
                    $result['Source'] = $mount['source'];
                }
                return $result;
            }, (array) $definition['mounts']),
            'Secrets' => $secretRefs,
            'Configs' => $configRefs,
            'Labels' => ['com.codegalaxy.release.id' => (string) $release->id],
        ];
        if (! empty($definition['command'])) {
            $container['Command'] = array_values($definition['command']);
        }
        if (! empty($definition['args'])) {
            $container['Args'] = array_values($definition['args']);
        }
        $limits = (array) $definition['resources']['limits'];
        $reservations = (array) $definition['resources']['reservations'];
        $resources = [];
        $limitObject = $this->resourceObject($limits);
        $reservationObject = $this->resourceObject($reservations);
        if ($limitObject !== []) {
            $resources['Limits'] = $limitObject;
        }
        if ($reservationObject !== []) {
            $resources['Reservations'] = $reservationObject;
        }
        $update = (array) $definition['update'];
        $ports = array_map(static function (array $port): array {
            $result = [
                'TargetPort' => (int) $port['target'], 'Protocol' => $port['protocol'], 'PublishMode' => $port['mode'],
            ];
            if ((int) $port['published'] > 0) {
                $result['PublishedPort'] = (int) $port['published'];
            }
            return $result;
        }, (array) $definition['ports']);
        $spec = [
            'Name' => $serviceName,
            'Labels' => [
                'com.codegalaxy.org.id' => (string) $release->org_id,
                'com.codegalaxy.group.id' => (string) $release->group_id,
                'com.codegalaxy.project.id' => (string) $release->project_id,
                'com.codegalaxy.env.id' => (string) $release->env_id,
                'com.codegalaxy.release.id' => (string) $release->id,
            ],
            'TaskTemplate' => [
                'ContainerSpec' => $container,
                'RestartPolicy' => ['Condition' => 'any', 'Delay' => 5_000_000_000, 'MaxAttempts' => 0],
                'Networks' => array_map(static fn (string $network): array => ['Target' => $network], (array) $definition['networks']),
            ],
            'Mode' => ['Replicated' => ['Replicas' => (int) $definition['replicas']]],
            'UpdateConfig' => [
                'Parallelism' => (int) $update['parallelism'],
                'Delay' => (int) $update['delay_seconds'] * 1_000_000_000,
                'FailureAction' => $update['failure_action'],
                'Monitor' => 10_000_000_000,
                'MaxFailureRatio' => 0,
                'Order' => $update['order'],
            ],
            'RollbackConfig' => [
                'Parallelism' => (int) $update['parallelism'], 'Delay' => 0,
                'FailureAction' => 'pause', 'Monitor' => 10_000_000_000,
                'MaxFailureRatio' => 0, 'Order' => $update['order'],
            ],
            'EndpointSpec' => ['Mode' => 'vip', 'Ports' => $ports],
        ];
        if ($resources !== []) {
            $spec['TaskTemplate']['Resources'] = $resources;
        }
        $placementNodeId = trim((string) ($definition['placement_node_id'] ?? ''));
        if ($placementNodeId !== '') {
            $spec['TaskTemplate']['Placement'] = [
                'Constraints' => ['node.id==' . $placementNodeId],
            ];
        }
        $spec = $runtime === null ? $spec : $this->routes->applyToServiceSpec($runtime, $spec);
        return $this->applyGatewayVhostNetwork($release, $serviceName, $spec);
    }

    private function applyGatewayVhostNetwork(ProjectRelease $release, string $serviceName, array $spec): array
    {
        $referenced = GatewayVhost::where('org_id', (int) $release->org_id)
            ->where('cluster_id', (int) $release->cluster_id)
            ->where('target_service', $serviceName)
            ->where('enabled', 1)
            ->exists();
        if (! $referenced) {
            return $spec;
        }
        $gateway = ClusterWebGateway::where('org_id', (int) $release->org_id)
            ->where('cluster_id', (int) $release->cluster_id)
            ->where('network_id', '<>', '')
            ->first();
        if ($gateway === null) {
            return $spec;
        }
        $networks = (array) ($spec['TaskTemplate']['Networks'] ?? []);
        $targets = array_map(
            static fn (array $network): string => (string) ($network['Target'] ?? ''),
            $networks
        );
        if (! in_array((string) $gateway->network_id, $targets, true)) {
            $networks[] = ['Target' => (string) $gateway->network_id];
        }
        $spec['TaskTemplate']['Networks'] = $networks;
        return $spec;
    }

    private function waitForConvergence(
        Client $client,
        SwarmApiClient $api,
        ProjectRelease $release,
        string $serviceId,
        int $replicas,
        int $timeout
    ): array
    {
        $deadline = time() + $timeout;
        $interval = max(1, min(10, (int) config('project-release.poll_interval', 2)));
        $last = [];
        $nextHeartbeatAt = 0;
        while (time() <= $deadline) {
            if (time() >= $nextHeartbeatAt) {
                $releaseQuery = ProjectRelease::where('id', (int) $release->id)
                    ->where('status', ProjectRelease::STATUS_DEPLOYING);
                if (! $releaseQuery->exists()) {
                    throw new AppException(409, '发布状态已被其他进程终止');
                }
                $releaseQuery->update(['updated_at' => time()]);
                $nextHeartbeatAt = time() + 30;
            }
            $service = $api->request($client, 'GET', '/services/' . rawurlencode($serviceId));
            $tasks = $api->request($client, 'GET', '/tasks', [
                'query' => ['filters' => json_encode(['service' => [$serviceId]], JSON_THROW_ON_ERROR)],
            ]);
            $states = [];
            $errors = [];
            foreach ($tasks as $task) {
                if (($task['DesiredState'] ?? 'running') !== 'running') {
                    continue;
                }
                $state = (string) ($task['Status']['State'] ?? 'unknown');
                $states[$state] = ($states[$state] ?? 0) + 1;
                if (in_array($state, ['failed', 'rejected'], true)) {
                    $errors[] = (string) ($task['Status']['Err'] ?? $task['Status']['Message'] ?? $state);
                }
            }
            $last = ['running' => (int) ($states['running'] ?? 0), 'states' => $states];
            if (($service['UpdateStatus']['State'] ?? '') === 'paused') {
                throw new AppException(502, 'Swarm Service 更新已暂停：' . ($service['UpdateStatus']['Message'] ?? implode('; ', $errors)));
            }
            $updateState = (string) ($service['UpdateStatus']['State'] ?? '');
            if (($replicas === 0 || $last['running'] >= $replicas) && ! in_array($updateState, ['updating', 'rollback_started'], true)) {
                if (! ProjectRelease::where('id', (int) $release->id)
                    ->where('status', ProjectRelease::STATUS_DEPLOYING)->exists()) {
                    throw new AppException(409, '发布状态已被其他进程终止');
                }
                return $last;
            }
            if ($errors !== [] && array_sum($states) <= count($errors)) {
                throw new AppException(502, 'Swarm Service 任务启动失败：' . implode('; ', array_unique($errors)));
            }
            Coroutine::sleep($interval);
        }
        throw new AppException(504, '等待 Swarm Service 达到期望副本数超时：' . json_encode($last, JSON_UNESCAPED_UNICODE));
    }

    private function resourceObject(array $resource): array
    {
        $result = [];
        if ((float) $resource['cpus'] > 0) {
            $result['NanoCPUs'] = (int) round((float) $resource['cpus'] * 1_000_000_000);
        }
        if ((int) $resource['memory_mb'] > 0) {
            $result['MemoryBytes'] = (int) $resource['memory_mb'] * 1024 * 1024;
        }
        return $result;
    }

    private function imageReference(BuildArtifact $artifact): string
    {
        $reference = (string) $artifact->reference;
        $digest = (string) $artifact->digest;
        return $digest !== '' && ! str_contains($reference, '@') ? $reference . '@' . $digest : $reference;
    }

    private function runtimeImageReference(BuildArtifact $artifact, Cluster $cluster): string
    {
        $reference = (string) $artifact->reference;
        $mapped = $this->imageMappings->resolve($cluster, $reference);
        $digest = (string) $artifact->digest;
        return $digest !== '' && ! str_contains($mapped, '@') ? $mapped . '@' . $digest : $mapped;
    }

}

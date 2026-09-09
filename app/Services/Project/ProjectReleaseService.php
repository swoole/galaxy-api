<?php

namespace App\Services\Project;

use App\Exception\AppException;
use App\Job\ProjectReleaseJob;
use App\Model\ProjectRelease;
use App\Model\Project;
use App\Model\ProjectRoute;
use App\Model\ProjectAlert;
use App\Model\ProjectRuntime;
use App\Model\ProjectRuntimeEvent;
use App\Model\ProjectRuntimeMetric;
use App\Model\ProjectBuildProfile;
use App\Model\BuildArtifact;
use App\Model\Build;
use App\Model\Cluster;
use App\Model\GatewayVhost;
use App\Model\Env;
use App\Model\EnvClusterRel;
use App\Model\GroupResourceGrant;
use App\Services\AsyncQueue\DefaultQueueService;
use App\Services\Docker\SwarmApiClient;
use App\Services\Docker\SwarmOverviewService;
use App\Services\Docker\SwarmServiceReferenceLock;
use App\Services\Project\Runtime\ProjectRuntimeDriverRegistry;
use App\Support\MySQL;
use GuzzleHttp\Client;
use Hyperf\DbConnection\Db;
use Throwable;

class ProjectReleaseService
{
    public function __construct(
        private ReleaseDefinitionService $definitions,
        private ProjectConfigurationService $configurations,
        private ReleaseSecretService $secrets,
        private ReleaseConfigService $configs,
        private DefaultQueueService $queue,
        private SwarmApiClient $docker,
        private SwarmOverviewService $swarm,
        private ProjectRuntimeDriverRegistry $runtimeDrivers,
        private ManagedSwarmResourceGuard $guard,
        private ProjectRuntimeLock $runtimeLock,
        private SwarmServiceReferenceLock $serviceReferenceLock
    ) {}

    public function options(int $orgId, int $groupId, int $projectId, ?int $clusterId = null): array
    {
        $clusterIds = GroupResourceGrant::clusterIds($orgId, $groupId);
        $clusters = Cluster::where('org_id', $orgId)
            ->whereIn('id', $clusterIds)
            ->whereIn('orchestrator_type', Cluster::deployableOrchestrators())
            ->where('status', Cluster::STATUS_READY)
            ->select('id', 'title', 'status', 'version', 'orchestrator_type')->orderBy('id')->get();
        $readyClusterIds = $clusters->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $environmentClusters = EnvClusterRel::where('org_id', $orgId)
            ->whereIn('cluster_id', $readyClusterIds)->select('env_id', 'cluster_id')->get();
        $availableCounts = $environmentClusters->groupBy('env_id')->map->count();
        $boundCounts = EnvClusterRel::where('org_id', $orgId)
            ->select('env_id', Db::raw('COUNT(*) AS cnt'))->groupBy('env_id')->pluck('cnt', 'env_id');
        $environments = Env::where('org_id', $orgId)
            ->select('id', 'title', 'remark', 'archived_at')->orderBy('id')->get();
        foreach ($environments as $environment) {
            $available = (int) ($availableCounts[(int) $environment->id] ?? 0);
            $bound = (int) ($boundCounts[(int) $environment->id] ?? 0);
            $archived = (int) $environment->archived_at > 0;
            $environment->setAttribute('deployable', ! $archived && $available > 0);
            $environment->setAttribute('available_cluster_count', $available);
            $environment->setAttribute('bound_cluster_count', $bound);
            $environment->setAttribute('unavailable_reason', $archived
                ? '环境已归档，仅用于查看历史发布'
                : ($available > 0
                    ? ''
                    : ($bound === 0 ? '尚未关联任何集群' : '当前项目没有已授权且在线的关联集群')));
        }
        $projectProfile = ProjectBuildProfile::where('org_id', $orgId)->where('group_id', $groupId)
            ->where('project_id', $projectId)->first(['options', 'template_key']);
        $projectDefaultPort = (int) (($projectProfile?->options ?? [])['port'] ?? 0);
        $artifacts = BuildArtifact::where('org_id', $orgId)->where('group_id', $groupId)
            ->where('project_id', $projectId)
            ->whereIn('build_id', Build::where('org_id', $orgId)->where('group_id', $groupId)
                ->where('project_id', $projectId)
                ->where('status', Build::STATUS_SUCCESS)->select('id'))
            ->whereRaw("`digest` REGEXP '^sha256:[0-9a-fA-F]{64}$'")
            ->with(['build' => fn ($builder) => $builder->select('id', 'spec_snapshot')])
            ->select('id', 'build_id', 'reference', 'digest', 'size', 'created_at')->orderByDesc('id')->get();
        foreach ($artifacts as $artifact) {
            $profile = (array) (($artifact->build?->spec_snapshot ?? [])['build_profile'] ?? []);
            $defaultPort = (int) (($profile['options']['port'] ?? null) ?: $projectDefaultPort);
            $artifact->setAttribute('default_port', $defaultPort > 0 && $defaultPort <= 65535 ? $defaultPort : null);
            $artifact->setAttribute('template_key', (string) (($profile['template_key'] ?? null) ?: ($projectProfile?->template_key ?? '')));
            $artifact->unsetRelation('build');
        }
        $data = [
            'environments' => $environments,
            'clusters' => $clusters,
            'environment_clusters' => $environmentClusters,
            'artifacts' => $artifacts,
            'project_configurations' => $this->configurations->list($orgId, $groupId, $projectId),
            'networks' => [],
        ];
        if ($clusterId !== null && $clusterId > 0) {
            if (! in_array($clusterId, $clusterIds, true)) {
                throw new AppException(403, '当前项目组未获授权使用该发布集群');
            }
            $cluster = $this->cluster($orgId, $clusterId);
            if ((string) $cluster->orchestrator_type === Cluster::ORCHESTRATOR_KUBERNETES) {
                $data['orchestrator_type'] = Cluster::ORCHESTRATOR_KUBERNETES;
                $data['suggested_published_port'] = 0;
                return $data;
            }
            $inventory = $this->docker->withCluster($cluster, function (Client $client, SwarmApiClient $api): array {
                $networks = $api->request($client, 'GET', '/networks');
                $services = $api->request($client, 'GET', '/services');
                $occupied = [];
                foreach ($services as $service) {
                    foreach ((array) ($service['Endpoint']['Ports'] ?? []) as $port) {
                        $published = (int) ($port['PublishedPort'] ?? 0);
                        if ($published > 0) $occupied[$published] = true;
                    }
                }
                $suggested = 0;
                for ($attempt = 0; $attempt < 200; $attempt++) {
                    $candidate = random_int(1024, 9999);
                    if (! isset($occupied[$candidate])) { $suggested = $candidate; break; }
                }
                return ['networks' => array_values(array_map(static fn (array $network): array => [
                    'id' => (string) ($network['Id'] ?? ''),
                    'name' => (string) ($network['Name'] ?? ''),
                    'driver' => (string) ($network['Driver'] ?? ''),
                    'scope' => (string) ($network['Scope'] ?? ''),
                    'ingress' => (bool) ($network['Ingress'] ?? false),
                ], array_filter($networks, static fn (array $network): bool =>
                    (($network['Scope'] ?? '') === 'swarm' || ($network['Driver'] ?? '') === 'host')
                    && ! (bool) ($network['Ingress'] ?? false)
                ))), 'suggested_published_port' => $suggested];
            });
            $data['networks'] = $inventory['networks'];
            $data['suggested_published_port'] = $inventory['suggested_published_port'];
        }
        return $data;
    }

    public function createNetwork(
        int $orgId,
        int $groupId,
        int $projectId,
        int $clusterId,
        string $name
    ): array {
        if (! GroupResourceGrant::canUseCluster($orgId, $groupId, $clusterId)) {
            throw new AppException(403, '当前项目组未获授权使用该发布集群');
        }
        $name = strtolower(trim($name));
        if (! preg_match('/^[a-z0-9][a-z0-9_.-]{0,39}$/', $name)) {
            throw new AppException(422, '网络名称只能包含小写字母、数字、下划线、点号和连字符，长度不能超过 40 个字符');
        }
        $cluster = $this->cluster($orgId, $clusterId);
        if ((string) $cluster->orchestrator_type !== Cluster::ORCHESTRATOR_DOCKER_SWARM) {
            throw new AppException(422, '创建 Docker Overlay 网络只适用于 Docker Swarm 集群');
        }
        $networkName = sprintf('galaxy-p%d-%s', $projectId, $name);
        return $this->docker->withCluster($cluster, function (Client $client, SwarmApiClient $api) use (
            $orgId, $groupId, $projectId, $networkName
        ): array {
            $networks = $api->request($client, 'GET', '/networks');
            foreach ($networks as $network) {
                if ((string) ($network['Name'] ?? '') !== $networkName) {
                    continue;
                }
                $labels = (array) ($network['Labels'] ?? []);
                if ((string) ($labels['com.codegalaxy.project.id'] ?? '') !== (string) $projectId) {
                    throw new AppException(409, '同名 Docker 网络已存在，但不属于当前项目');
                }
                return [
                    'id' => (string) ($network['Id'] ?? ''), 'name' => $networkName,
                    'driver' => (string) ($network['Driver'] ?? 'overlay'),
                    'scope' => (string) ($network['Scope'] ?? 'swarm'), 'created' => false,
                ];
            }
            $created = $api->request($client, 'POST', '/networks/create', ['json' => [
                'Name' => $networkName,
                'CheckDuplicate' => true,
                'Driver' => 'overlay',
                'Attachable' => true,
                'Labels' => [
                    'com.codegalaxy.component' => 'project-network',
                    'com.codegalaxy.org.id' => (string) $orgId,
                    'com.codegalaxy.group.id' => (string) $groupId,
                    'com.codegalaxy.project.id' => (string) $projectId,
                ],
            ]]);
            $networkId = (string) ($created['Id'] ?? '');
            if ($networkId === '') {
                throw new AppException(502, 'Docker API 未返回新建网络 ID');
            }
            return [
                'id' => $networkId, 'name' => $networkName,
                'driver' => 'overlay', 'scope' => 'swarm', 'created' => true,
            ];
        });
    }

    public function list(
        int $orgId,
        int $groupId,
        int $projectId,
        ?int $envId,
        ?int $clusterId,
        ?string $status,
        int $page,
        int $pageSize
    ): array {
        $query = ProjectRelease::where('org_id', $orgId)->where('group_id', $groupId)->where('project_id', $projectId)
            ->with('artifact')->with('runtime')->with('env')->with('cluster')
            ->with(['creatorInfo' => fn ($builder) => $builder->where('org_id', $orgId)])
            ->orderByDesc('id');
        if ($envId !== null && $envId > 0) {
            $query->where('env_id', $envId);
        }
        if ($clusterId !== null && $clusterId > 0) {
            $query->where('cluster_id', $clusterId);
        }
        if ($status !== null && $status !== '') {
            $query->where('status', $status);
        }
        return MySQL::jsonPaginate($query, $page, $pageSize);
    }

    public function create(
        int $uid,
        int $orgId,
        int $groupId,
        int $projectId,
        int $envId,
        int $clusterId,
        int $artifactId,
        string $version,
        string $remark,
        array $input
    ): ProjectRelease {
        $version = trim($version);
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._+-]{0,127}$/', $version)) {
            throw new AppException(422, '发布版本只能包含字母、数字、点号、下划线、加号和连字符');
        }
        $this->validateTarget($orgId, $groupId, $projectId, $envId, $clusterId, $artifactId);
        [$definition, $secrets, $configs] = $this->definitions->normalize($input);
        [$definition, $secrets, $configs] = $this->configurations->resolveRelease(
            $orgId, $groupId, $projectId, $envId, $definition, $secrets, $configs
        );
        $instanceName = ProjectServiceIdentity::instanceName($definition);
        return $this->runtimeLock->synchronized(
            $orgId,
            $projectId,
            0,
            0,
            $instanceName,
            function () use (
                $uid, $orgId, $groupId, $projectId, $envId, $clusterId, $artifactId, $version,
                $remark, $definition, $secrets, $configs, $instanceName
            ): ProjectRelease {
                $release = Db::transaction(function () use (
                    $uid, $orgId, $groupId, $projectId, $envId, $clusterId, $artifactId, $version,
                    $remark, $definition, $secrets, $configs, $instanceName
                ): ProjectRelease {
                    $this->lockTarget($orgId, $groupId, $projectId, $envId, $clusterId, $instanceName);
                    /** @var ProjectRuntime|null $current */
                    $current = ProjectRuntime::where('org_id', $orgId)->where('project_id', $projectId)
                        ->where('name', $instanceName)->lockForUpdate()->first();
                    if ($current !== null
                        && ((int) $current->env_id !== $envId || (int) $current->cluster_id !== $clusterId)) {
                        throw new AppException(409, sprintf(
                            '实例名称“%s”已在本项目中使用；同一项目的实例名称不能重复',
                            $instanceName
                        ));
                    }
                    $definition['service_name'] = $current !== null
                        ? ProjectServiceIdentity::runtimeDockerName($current)
                        : ProjectServiceIdentity::newResourceName($orgId, $projectId);
                    $definition['resource_identity_version'] = 'opaque-v1';
                    $now = time();
                    $release = ProjectRelease::create([
                        'org_id' => $orgId, 'group_id' => $groupId, 'project_id' => $projectId,
                        'env_id' => $envId, 'cluster_id' => $clusterId, 'artifact_id' => $artifactId,
                        'version' => $version,
                        'operation' => ProjectRelease::OPERATION_DEPLOY,
                        'previous_release_id' => (int) ($current?->release_id ?? 0),
                        'remark' => $remark, 'status' => ProjectRelease::STATUS_PENDING,
                        'desired_spec' => $definition, 'runtime_ref' => '', 'error' => null, 'result' => null,
                        'started_at' => 0, 'finished_at' => 0,
                        'creator' => $uid, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                    $this->secrets->store($orgId, $projectId, (int) $release->id, $secrets);
                    $this->configs->store($orgId, $projectId, (int) $release->id, $configs);
                    return $release;
                });
                $this->queueOrFail($release);
                return $release;
            }
        );
    }

    public function rollback(int $uid, int $orgId, int $groupId, int $projectId, int $targetReleaseId): ProjectRelease
    {
        /** @var ProjectRelease|null $target */
        $target = ProjectRelease::where('id', $targetReleaseId)->where('org_id', $orgId)
            ->where('group_id', $groupId)->where('project_id', $projectId)
            ->whereIn('status', [ProjectRelease::STATUS_SUCCEEDED, ProjectRelease::STATUS_ROLLED_BACK])->first();
        if ($target === null) {
            throw new AppException(404, '可回滚的目标版本不存在');
        }
        $spec = (array) $target->desired_spec;
        $instanceName = ProjectServiceIdentity::instanceName($spec);
        /** @var ProjectRuntime|null $current */
        $current = ProjectRuntime::where('org_id', $orgId)->where('project_id', $projectId)
            ->where('name', $instanceName)->first();
        if ($current === null) {
            throw new AppException(409, '当前运行实例不存在，不能执行回滚；可以将该版本作为新发布重新部署');
        }
        return $this->runtimeLock->synchronized(
            $orgId,
            $projectId,
            (int) $target->env_id,
            (int) $target->cluster_id,
            $instanceName,
            function () use ($uid, $orgId, $groupId, $projectId, $target, $current, $spec, $instanceName): ProjectRelease {
                $release = Db::transaction(function () use (
                    $uid, $orgId, $groupId, $projectId, $target, $current, $spec, $instanceName
                ): ProjectRelease {
                    $this->lockTarget(
                        $orgId, $groupId, $projectId, (int) $target->env_id, (int) $target->cluster_id,
                        $instanceName
                    );
                    /** @var ProjectRuntime|null $lockedRuntime */
                    $lockedRuntime = ProjectRuntime::where('id', (int) $current->id)->lockForUpdate()->first();
                    if ($lockedRuntime === null) {
                        throw new AppException(409, '当前运行实例已被删除，不能执行回滚');
                    }
                    $now = time();
                    $release = ProjectRelease::create([
                        'org_id' => $orgId, 'group_id' => $groupId, 'project_id' => $projectId,
                        'env_id' => $target->env_id, 'cluster_id' => $target->cluster_id, 'artifact_id' => $target->artifact_id,
                        'version' => 'rollback-' . $target->id . '-' . $now,
                        'operation' => ProjectRelease::OPERATION_ROLLBACK,
                        'previous_release_id' => (int) $lockedRuntime->release_id,
                        'remark' => '回滚到发布 #' . $target->id, 'status' => ProjectRelease::STATUS_PENDING,
                        'desired_spec' => $spec, 'runtime_ref' => (string) $lockedRuntime->runtime_ref,
                        'error' => null, 'result' => ['target_release_id' => (int) $target->id],
                        'started_at' => 0, 'finished_at' => 0,
                        'creator' => $uid, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                    $this->secrets->copy((int) $target->id, (int) $release->id, $orgId, $projectId);
                    $this->configs->copy((int) $target->id, (int) $release->id, $orgId, $projectId);
                    return $release;
                });
                $this->queueOrFail($release);
                return $release;
            }
        );
    }

    public function updateArtifact(
        int $uid,
        int $orgId,
        int $groupId,
        int $projectId,
        int $runtimeId,
        int $artifactId,
        string $remark
    ): ProjectRelease {
        $runtime = $this->runtime($orgId, $groupId, $projectId, $runtimeId);
        /** @var ProjectRelease|null $current */
        $current = ProjectRelease::where('id', (int) $runtime->release_id)
            ->where('org_id', $orgId)->where('group_id', $groupId)->where('project_id', $projectId)->first();
        if ($current === null) {
            throw new AppException(409, '运行实例没有可用的当前发布记录');
        }
        if ((int) $current->artifact_id === $artifactId) {
            throw new AppException(409, '当前实例已经在使用该镜像版本');
        }
        $this->validateTarget(
            $orgId,
            $groupId,
            $projectId,
            (int) $runtime->env_id,
            (int) $runtime->cluster_id,
            $artifactId
        );
        $spec = (array) $current->desired_spec;
        $instanceName = (string) $runtime->name;

        return $this->runtimeLock->synchronized(
            $orgId,
            $projectId,
            (int) $runtime->env_id,
            (int) $runtime->cluster_id,
            $instanceName,
            function () use (
                $uid, $orgId, $groupId, $projectId, $runtime, $current, $artifactId, $remark, $spec, $instanceName
            ): ProjectRelease {
                $release = Db::transaction(function () use (
                    $uid, $orgId, $groupId, $projectId, $runtime, $current, $artifactId, $remark, $spec, $instanceName
                ): ProjectRelease {
                    $this->lockTarget(
                        $orgId,
                        $groupId,
                        $projectId,
                        (int) $runtime->env_id,
                        (int) $runtime->cluster_id,
                        $instanceName
                    );
                    /** @var ProjectRuntime|null $lockedRuntime */
                    $lockedRuntime = ProjectRuntime::where('id', (int) $runtime->id)->lockForUpdate()->first();
                    if ($lockedRuntime === null || (int) $lockedRuntime->release_id !== (int) $current->id) {
                        throw new AppException(409, '实例版本已经变化，请刷新后重试');
                    }
                    $now = time();
                    $release = ProjectRelease::create([
                        'org_id' => $orgId, 'group_id' => $groupId, 'project_id' => $projectId,
                        'env_id' => $lockedRuntime->env_id, 'cluster_id' => $lockedRuntime->cluster_id,
                        'artifact_id' => $artifactId, 'version' => 'image-' . $artifactId . '-' . $now,
                        'operation' => ProjectRelease::OPERATION_UPDATE_IMAGE,
                        'previous_release_id' => (int) $current->id,
                        'remark' => $remark, 'status' => ProjectRelease::STATUS_PENDING,
                        'desired_spec' => $spec, 'runtime_ref' => (string) $lockedRuntime->runtime_ref,
                        'error' => null, 'result' => ['source_release_id' => (int) $current->id],
                        'started_at' => 0, 'finished_at' => 0,
                        'creator' => $uid, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                    $this->secrets->copy((int) $current->id, (int) $release->id, $orgId, $projectId);
                    $this->configs->copy((int) $current->id, (int) $release->id, $orgId, $projectId);
                    return $release;
                });
                $this->queueOrFail($release);
                return $release;
            }
        );
    }

    public function profile(int $orgId, int $groupId, int $projectId, int $releaseId): ProjectRelease
    {
        /** @var ProjectRelease|null $release */
        $release = ProjectRelease::where('id', $releaseId)->where('org_id', $orgId)
            ->where('group_id', $groupId)->where('project_id', $projectId)
            ->with('artifact')->with('runtime')->with('env')->with('cluster')->first();
        if ($release === null) {
            throw new AppException(404, '发布记录不存在');
        }
        return $release;
    }

    public function runtimes(int $orgId, int $groupId, int $projectId): array
    {
        return ProjectRuntime::where('org_id', $orgId)->where('group_id', $groupId)->where('project_id', $projectId)
            ->with('env')->with('cluster')->with(['release.artifact'])
            ->orderBy('env_id')->orderBy('cluster_id')->orderBy('name')->get()->toArray();
    }

    public function renameRuntime(
        int $orgId,
        int $groupId,
        int $projectId,
        int $runtimeId,
        string $name
    ): ProjectRuntime {
        $name = trim($name);
        if ($name === '' || preg_match('/[\x00-\x1f\x7f]/u', $name)) {
            throw new AppException(422, '实例名称不能为空或包含控制字符');
        }
        if (mb_strlen($name) > 128) {
            throw new AppException(422, '实例名称不能超过 128 个字符');
        }
        $runtime = $this->runtime($orgId, $groupId, $projectId, $runtimeId, false);
        $oldName = (string) $runtime->name;
        if ($name === $oldName) {
            return $runtime;
        }
        $lockNames = [$oldName, $name];
        sort($lockNames, SORT_STRING);

        return $this->runtimeLock->synchronized(
            $orgId,
            $projectId,
            0,
            0,
            $lockNames[0],
            fn (): ProjectRuntime => $this->runtimeLock->synchronized(
                $orgId,
                $projectId,
                0,
                0,
                $lockNames[1],
                function () use (
                    $orgId,
                    $groupId,
                    $projectId,
                    $runtimeId,
                    $oldName,
                    $name
                ): ProjectRuntime {
                    return Db::transaction(function () use (
                        $orgId,
                        $groupId,
                        $projectId,
                        $runtimeId,
                        $oldName,
                        $name
                    ): ProjectRuntime {
                        /** @var ProjectRuntime|null $locked */
                        $locked = ProjectRuntime::where('id', $runtimeId)
                            ->where('org_id', $orgId)
                            ->where('group_id', $groupId)
                            ->where('project_id', $projectId)
                            ->lockForUpdate()
                            ->first();
                        if ($locked === null) {
                            throw new AppException(404, '项目运行实例不存在');
                        }
                        if ((string) $locked->name !== $oldName) {
                            throw new AppException(409, '实例名称已经变化，请刷新后重试');
                        }
                        $this->assertNoActiveRelease($locked);
                        if (ProjectRuntime::where('project_id', $projectId)
                            ->where('name', $name)
                            ->where('id', '<>', $runtimeId)
                            ->exists()) {
                            throw new AppException(422, sprintf(
                                '实例名称“%s”已在本项目中使用；同一项目的实例名称不能重复',
                                $name
                            ));
                        }

                        $runtimeSpec = (array) $locked->spec;
                        $runtimeSpec['instance_name'] = $name;
                        $locked->name = $name;
                        $locked->spec = $runtimeSpec;
                        $locked->updated_at = time();
                        $locked->save();

                        $releases = ProjectRelease::where('org_id', $orgId)
                            ->where('group_id', $groupId)
                            ->where('project_id', $projectId)
                            ->whereRaw(
                                "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(`desired_spec`, '$.instance_name')), "
                                . "JSON_UNQUOTE(JSON_EXTRACT(`desired_spec`, '$.service_name'))) = ?",
                                [$oldName]
                            )
                            ->lockForUpdate()
                            ->get();
                        foreach ($releases as $release) {
                            $desiredSpec = (array) $release->desired_spec;
                            $desiredSpec['instance_name'] = $name;
                            $release->desired_spec = $desiredSpec;
                            $release->updated_at = time();
                            $release->save();
                        }
                        return $locked;
                    });
                }
            )
        );
    }

    public function scale(int $uid, int $orgId, int $groupId, int $projectId, int $runtimeId, int $replicas): ProjectRelease
    {
        if ($replicas < 0 || $replicas > 100) {
            throw new AppException(422, '副本数必须在 0 到 100 之间');
        }
        $runtime = $this->runtime($orgId, $groupId, $projectId, $runtimeId);
        /** @var ProjectRelease|null $current */
        $current = ProjectRelease::where('id', (int) $runtime->release_id)->first();
        if ($current === null) {
            throw new AppException(409, '运行实例没有可用的当前发布记录');
        }
        $instanceName = (string) $runtime->name;
        return $this->runtimeLock->synchronized(
            $orgId,
            $projectId,
            (int) $runtime->env_id,
            (int) $runtime->cluster_id,
            $instanceName,
            function () use (
                $uid, $orgId, $groupId, $projectId, $runtime, $instanceName, $replicas
            ): ProjectRelease {
                $release = Db::transaction(function () use (
                    $uid, $orgId, $groupId, $projectId, $runtime, $instanceName, $replicas
                ): ProjectRelease {
                    $this->lockTarget(
                        $orgId, $groupId, $projectId, (int) $runtime->env_id, (int) $runtime->cluster_id,
                        $instanceName
                    );
                    /** @var ProjectRuntime|null $lockedRuntime */
                    $lockedRuntime = ProjectRuntime::where('id', (int) $runtime->id)->lockForUpdate()->first();
                    /** @var ProjectRelease|null $current */
                    $current = $lockedRuntime === null ? null : ProjectRelease::where('id', (int) $lockedRuntime->release_id)->first();
                    if ($lockedRuntime === null || $current === null) {
                        throw new AppException(409, '运行实例的当前发布记录已经变化，请刷新后重试');
                    }
                    $spec = (array) $current->desired_spec;
                    $spec['replicas'] = $replicas;
                    $now = time();
                    $release = ProjectRelease::create([
                        'org_id' => $orgId, 'group_id' => $groupId, 'project_id' => $projectId,
                        'env_id' => $lockedRuntime->env_id, 'cluster_id' => $lockedRuntime->cluster_id,
                        'artifact_id' => $current->artifact_id, 'version' => 'scale-' . $replicas . '-' . $now,
                        'operation' => ProjectRelease::OPERATION_SCALE,
                        'previous_release_id' => (int) $current->id,
                        'remark' => '调整副本数为 ' . $replicas, 'status' => ProjectRelease::STATUS_PENDING,
                        'desired_spec' => $spec, 'runtime_ref' => (string) $lockedRuntime->runtime_ref,
                        'error' => null, 'result' => null, 'started_at' => 0, 'finished_at' => 0,
                        'creator' => $uid, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                    $this->secrets->copy((int) $current->id, (int) $release->id, $orgId, $projectId);
                    $this->configs->copy((int) $current->id, (int) $release->id, $orgId, $projectId);
                    return $release;
                });
                $this->queueOrFail($release);
                return $release;
            }
        );
    }

    public function restart(int $orgId, int $groupId, int $projectId, int $runtimeId): void
    {
        $runtime = $this->runtime($orgId, $groupId, $projectId, $runtimeId);
        $cluster = $this->cluster($orgId, (int) $runtime->cluster_id);
        $this->runtimeLock->synchronized(
            $orgId,
            $projectId,
            (int) $runtime->env_id,
            (int) $runtime->cluster_id,
            (string) $runtime->name,
            function () use ($cluster, $runtime): void {
                $this->assertNoActiveRelease($runtime);
                $this->runtimeDrivers->forCluster($cluster)->restartRuntime($cluster, $runtime);
                $runtime->status = 'restarting';
                $runtime->health = 'unknown';
                $runtime->last_error = '';
                $runtime->updated_at = time();
                $runtime->save();
            }
        );
    }

    public function removeRuntime(int $orgId, int $groupId, int $projectId, int $runtimeId): void
    {
        // Deletion also reconciles the case where Docker created the Service
        // but the response was lost before runtime_ref could be persisted.
        $runtime = $this->runtime($orgId, $groupId, $projectId, $runtimeId, false);
        $cluster = $this->cluster($orgId, (int) $runtime->cluster_id);
        $this->runtimeLock->synchronized(
            $orgId,
            $projectId,
            (int) $runtime->env_id,
            (int) $runtime->cluster_id,
            (string) $runtime->name,
            function () use ($cluster, $runtime, $orgId, $projectId): void {
                if ((string) $cluster->orchestrator_type !== Cluster::ORCHESTRATOR_DOCKER_SWARM) {
                    $this->removeRuntimeUnlocked($cluster, $runtime, $orgId, $projectId);
                    return;
                }
                $this->serviceReferenceLock->synchronized(
                    $orgId,
                    (int) $runtime->cluster_id,
                    [ProjectServiceIdentity::runtimeDockerName($runtime)],
                    fn () => $this->removeRuntimeUnlocked($cluster, $runtime, $orgId, $projectId)
                );
            }
        );
    }

    public function removeRuntimeByReference(int $orgId, int $groupId, int $projectId, string $serviceId): void
    {
        /** @var ProjectRuntime|null $runtime */
        $runtime = ProjectRuntime::where('org_id', $orgId)
            ->where('group_id', $groupId)
            ->where('project_id', $projectId)
            ->where('runtime_ref', $serviceId)
            ->first();
        if ($runtime === null) {
            throw new AppException(404, '项目运行实例不存在');
        }
        $this->removeRuntime($orgId, $groupId, $projectId, (int) $runtime->id);
    }

    private function removeRuntimeUnlocked(
        Cluster $cluster,
        ProjectRuntime $runtime,
        int $orgId,
        int $projectId
    ): void {
                $this->assertNoActiveRelease($runtime);
                $gatewayRouteCount = GatewayVhost::where('org_id', $orgId)
                    ->where('cluster_id', (int) $runtime->cluster_id)
                    ->where('target_service', ProjectServiceIdentity::runtimeDockerName($runtime))
                    ->count();
                if ($gatewayRouteCount > 0) {
                    throw new AppException(
                        409,
                        sprintf(
                            '该实例仍被 %d 条 Web 网关路由引用，请先在“资源 → 集群 → Web 网关”中删除或改绑路由',
                            $gatewayRouteCount
                        )
                    );
                }
                if ((string) $cluster->orchestrator_type === Cluster::ORCHESTRATOR_DOCKER_SWARM) {
                    $serviceId = (string) $runtime->runtime_ref;
                    if ($serviceId === '') {
                        $serviceId = $this->discoverRuntimeService($cluster, $runtime, $orgId, $projectId);
                    }
                    if ($serviceId !== '') {
                        try {
                            $service = $this->swarm->inspectService($cluster, $serviceId);
                            $this->guard->assertService($service, $orgId, $projectId, (int) $runtime->env_id);
                            $this->swarm->removeService($cluster, $serviceId);
                        } catch (AppException $e) {
                            if (! str_contains($e->getMessage(), '404')) {
                                throw $e;
                            }
                            // A previous attempt may have removed the Service before
                            // losing the Agent connection during Secret cleanup.
                        }
                    }
                }
                if (! $this->runtimeDrivers->forCluster($cluster)->cleanupRuntimeResources(
                    $cluster,
                    $orgId,
                    $projectId,
                    (int) $runtime->env_id,
                    (int) $runtime->cluster_id,
                    (string) $runtime->name
                )) {
                    throw new AppException(503, '工作负载已删除，但关联运行资源尚未全部安全回收，请稍后重试');
                }
                ProjectRoute::where('runtime_id', (int) $runtime->id)->delete();
                ProjectRuntimeMetric::where('runtime_id', (int) $runtime->id)->delete();
                ProjectRuntimeEvent::where('runtime_id', (int) $runtime->id)->delete();
                ProjectAlert::where('runtime_id', (int) $runtime->id)->delete();
                $runtime->delete();
    }

    /**
     * Reconcile a Docker create response lost before runtime_ref was persisted.
     * An empty return value means Docker confirmed that the Service is absent.
     */
    private function discoverRuntimeService(
        Cluster $cluster,
        ProjectRuntime $runtime,
        int $orgId,
        int $projectId
    ): string {
        return $this->docker->withCluster($cluster, function (Client $client, SwarmApiClient $api) use (
            $runtime, $orgId, $projectId
        ): string {
            $name = ProjectServiceIdentity::runtimeDockerName($runtime);
            $services = $api->request($client, 'GET', '/services', [
                'query' => ['filters' => json_encode(['name' => [$name]], JSON_THROW_ON_ERROR)],
            ]);
            foreach ($services as $service) {
                if ((string) ($service['Spec']['Name'] ?? '') !== $name) {
                    continue;
                }
                $this->guard->assertService($service, $orgId, $projectId, (int) $runtime->env_id, $name);
                $serviceId = (string) ($service['ID'] ?? '');
                if ($serviceId === '') {
                    throw new AppException(502, 'Docker API 返回了缺少 ID 的项目 Service');
                }
                $runtime->runtime_ref = $serviceId;
                $runtime->updated_at = time();
                $runtime->save();
                return $serviceId;
            }
            return '';
        });
    }

    private function assertNoActiveRelease(ProjectRuntime $runtime): void
    {
        $active = ProjectRelease::where('org_id', (int) $runtime->org_id)
            ->where('project_id', (int) $runtime->project_id)
            ->whereIn('status', [ProjectRelease::STATUS_PENDING, ProjectRelease::STATUS_DEPLOYING])
            ->whereRaw(
                "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(`desired_spec`, '$.instance_name')), "
                . "JSON_UNQUOTE(JSON_EXTRACT(`desired_spec`, '$.service_name'))) = ?",
                [(string) $runtime->name]
            )
            ->exists();
        if ($active) {
            throw new AppException(409, '该运行实例仍有等待或正在执行的发布，暂不能执行此操作');
        }
    }

    private function validateTarget(
        int $orgId,
        int $groupId,
        int $projectId,
        int $envId,
        int $clusterId,
        int $artifactId
    ): void
    {
        if (! Env::where('id', $envId)->where('org_id', $orgId)->where('archived_at', 0)->exists()) {
            throw new AppException(404, '部署环境不存在');
        }
        if (! GroupResourceGrant::canUseCluster($orgId, $groupId, $clusterId)) {
            throw new AppException(403, '当前项目组未获授权使用该发布集群');
        }
        $this->cluster($orgId, $clusterId);
        if (! EnvClusterRel::where('org_id', $orgId)->where('env_id', $envId)
            ->where('cluster_id', $clusterId)->exists()) {
            throw new AppException(422, '所选集群尚未绑定到该部署环境');
        }
        /** @var BuildArtifact|null $artifact */
        $artifact = BuildArtifact::where('id', $artifactId)->where('org_id', $orgId)
            ->where('group_id', $groupId)->where('project_id', $projectId)->first(['id', 'build_id', 'digest']);
        if ($artifact === null) {
            throw new AppException(404, '镜像制品不存在');
        }
        if (! Build::where('id', (int) $artifact->build_id)->where('org_id', $orgId)
            ->where('group_id', $groupId)->where('project_id', $projectId)
            ->where('status', Build::STATUS_SUCCESS)->exists()) {
            throw new AppException(409, '镜像制品所属构建尚未成功，不能发布');
        }
        if (! preg_match('/^sha256:[a-f0-9]{64}$/i', (string) $artifact->digest)) {
            throw new AppException(409, '镜像制品缺少有效的不可变 Digest，不能发布');
        }
    }

    private function cluster(int $orgId, int $clusterId): Cluster
    {
        /** @var Cluster|null $cluster */
        $cluster = Cluster::where('id', $clusterId)->where('org_id', $orgId)
            ->whereIn('orchestrator_type', Cluster::deployableOrchestrators())
            ->where('status', Cluster::STATUS_READY)->first();
        if ($cluster === null) {
            throw new AppException(409, '目标集群不存在、不在线或暂不支持项目部署');
        }
        return $cluster;
    }

    private function runtime(
        int $orgId,
        int $groupId,
        int $projectId,
        int $runtimeId,
        bool $requireRuntimeRef = true
    ): ProjectRuntime
    {
        /** @var ProjectRuntime|null $runtime */
        $runtime = ProjectRuntime::where('id', $runtimeId)->where('org_id', $orgId)
            ->where('group_id', $groupId)->where('project_id', $projectId)->first();
        if ($runtime === null || ($requireRuntimeRef && (string) $runtime->runtime_ref === '')) {
            throw new AppException(404, '项目运行实例不存在');
        }
        return $runtime;
    }

    private function queueOrFail(ProjectRelease $release): void
    {
        try {
            if (! $this->queue->push(new ProjectReleaseJob((int) $release->id))) {
                throw new AppException(503, '发布队列暂不可用');
            }
        } catch (Throwable $e) {
            $now = time();
            $release->status = ProjectRelease::STATUS_FAILED;
            $release->error = $e->getMessage();
            // The transaction has committed but no queue consumer has run, so
            // Docker was never touched. Mark remote reconciliation complete
            // immediately instead of blocking app deletion until governance.
            $release->result = [
                'queue_submission_failed' => true,
                'failure_reconciled_at' => $now,
            ];
            $release->finished_at = $now;
            $release->updated_at = $now;
            $release->save();
            // 入队失败时尚未创建任何 Docker Secret，不保留不可重试版本的密文副本。
            $this->secrets->purge((int) $release->id);
            throw $e;
        }
    }

    private function lockTarget(
        int $orgId,
        int $groupId,
        int $projectId,
        int $envId,
        int $clusterId,
        string $instanceName
    ): void {
        if (Project::where('id', $projectId)->where('org_id', $orgId)->where('group_id', $groupId)
            ->lockForUpdate()->first(['id']) === null) {
            throw new AppException(404, '项目不存在');
        }
        if (Env::where('id', $envId)->where('org_id', $orgId)->where('archived_at', 0)
            ->lockForUpdate()->first(['id']) === null) {
            throw new AppException(409, '部署环境已归档，不能创建发布');
        }
        if (EnvClusterRel::where('org_id', $orgId)->where('env_id', $envId)
            ->where('cluster_id', $clusterId)->lockForUpdate()->first(['env_id']) === null) {
            throw new AppException(422, '所选集群已不再绑定到该部署环境');
        }
        if (GroupResourceGrant::where('org_id', $orgId)->where('group_id', $groupId)
            ->where('resource_type', GroupResourceGrant::TYPE_CLUSTER)->where('resource_id', $clusterId)
            ->lockForUpdate()->first(['id']) === null) {
            throw new AppException(403, '当前项目组已无权使用该发布集群');
        }
        $active = ProjectRelease::where('org_id', $orgId)->where('group_id', $groupId)->where('project_id', $projectId)
            ->whereIn('status', [ProjectRelease::STATUS_PENDING, ProjectRelease::STATUS_DEPLOYING])
            ->whereRaw(
                "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(`desired_spec`, '$.instance_name')), "
                . "JSON_UNQUOTE(JSON_EXTRACT(`desired_spec`, '$.service_name'))) = ?",
                [$instanceName]
            )
            ->exists();
        if ($active) {
            throw new AppException(409, '该实例已有等待或正在执行的发布，请完成后再操作');
        }
    }
}

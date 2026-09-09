<?php

namespace App\Services\Project;

use App\Exception\AppException;
use App\Model\Project;
use App\Model\ProjectRepository;
use App\Model\Build;
use App\Model\BuildArtifact;
use App\Model\Cluster;
use App\Model\GitAuth;
use App\Model\Registry;
use App\Model\GroupResourceGrant;
use App\Services\RegistryService;
use App\Services\RegistryGroupGrantService;
use App\Services\ContainerImageMappingService;
use App\Services\Docker\SwarmApiClient;
use GuzzleHttp\Client;
use Throwable;

class BuildKitRunnerService
{
    private const METADATA_MARKER = '__GALAXY_BUILDKIT_METADATA__';

    public function __construct(
        private SwarmApiClient $docker,
        private ProjectRepositoryCredentialService $repositoryCredential,
        private PipelineSecretService $pipelineSecrets,
        private RegistryService $registries,
        private RegistryGroupGrantService $registryGrants,
        private ContainerImageMappingService $imageMappings
    ) {}

    public function run(Build $build): array
    {
        $snapshot = (array) $build->spec_snapshot;
        $definition = (array) ($snapshot['definition'] ?? []);
        $clusterId = (int) ($snapshot['cluster_id'] ?? 0);
        if (! GroupResourceGrant::canUseCluster(
            (int) $build->org_id,
            (int) $build->group_id,
            $clusterId
        )) {
            throw new AppException(403, '当前项目组已无权使用该 BuildKit 构建集群');
        }
        /** @var Cluster|null $cluster */
        $cluster = Cluster::where('id', $clusterId)->where('org_id', (int) $build->org_id)
            ->where('orchestrator_type', Cluster::ORCHESTRATOR_DOCKER_SWARM)
            ->where('status', Cluster::STATUS_READY)->first();
        if ($cluster === null) {
            throw new AppException(409, 'BuildKit 构建集群不存在或当前不在线');
        }
        $repositorySnapshot = (array) ($snapshot['repository'] ?? []);
        $credentialUid = (int) ($snapshot['credential_uid'] ?? $build->creator);
        if (trim((string) ($repositorySnapshot['clone_url'] ?? '')) === '') {
            // Compatibility for queued Builds created before repository
            // snapshots were introduced. New Builds never enter this branch.
            /** @var ProjectRepository|null $repository */
            $repository = ProjectRepository::where('project_id', (int) $build->project_id)
                ->where('org_id', (int) $build->org_id)->first();
            if ($repository === null) {
                throw new AppException(422, '构建来源快照缺失且项目代码仓库已不存在');
            }
            $repositorySnapshot = [
                'clone_url' => (string) $repository->clone_url,
                'provider' => (int) $repository->provider,
            ];
        }
        $source = $this->repositoryCredential->runtimeConfigForSnapshot(
            $credentialUid,
            (int) $build->org_id,
            (int) $build->group_id,
            (string) $repositorySnapshot['clone_url'],
            (int) ($repositorySnapshot['provider'] ?? 0)
        );
        $cloneUrl = (string) ($source['clone_url'] ?? '');
        if ($cloneUrl === '') {
            throw new AppException(422, '构建来源 Git 地址为空');
        }
        if (($source['transport'] ?? 'http') === 'ssh' && empty($source['ssh_private_key'])) {
            throw new AppException(422, 'SSH Git 仓库需要先把项目组平台公钥添加到 Git 服务');
        }
        $registry = $this->registry(
            (int) $build->org_id,
            (int) $build->group_id,
            (array) ($definition['output'] ?? []),
            (array) ($snapshot['registry'] ?? [])
        );
        $secrets = $this->secretFiles($build, $source, $registry);
        $generatedFiles = [];
        $buildProfile = (array) ($snapshot['build_profile'] ?? []);
        if (($buildProfile['dockerfile_source'] ?? 'repository') === 'template') {
            $dockerfile = (string) ($buildProfile['rendered_dockerfile'] ?? '');
            $dockerignore = (string) ($buildProfile['rendered_dockerignore'] ?? '');
            if ($dockerfile === '' || hash('sha256', $dockerfile) !== (string) ($buildProfile['rendered_checksum'] ?? '')) {
                throw new AppException(409, 'Build 快照中的模板 Dockerfile 缺失或校验失败');
            }
            if ($dockerignore === '' || hash('sha256', $dockerignore) !== (string) ($buildProfile['dockerignore_checksum'] ?? '')) {
                throw new AppException(409, 'Build 快照中的 .dockerignore 缺失或校验失败');
            }
            $generatedFiles['Dockerfile.generated'] = $dockerfile;
            $generatedFiles['Dockerfile.generated.dockerignore'] = $dockerignore;
        }
        $timeout = max(60, (int) config('buildkit.timeout', 1800));
        $runnerEnvironment = $this->runnerEnvironment();
        $networkMode = $this->networkMode();
        $containerId = '';

        $result = null;
        $runnerReconciledAt = 0;
        try {
            $result = $this->docker->withCluster($cluster, function (Client $client, SwarmApiClient $api) use (
                $build, $source, $repositorySnapshot, $secrets, $generatedFiles, $definition, $registry, $timeout,
                $runnerEnvironment, $networkMode, $buildProfile, $cluster, &$containerId
            ): array {
                $sourceCache = $this->prepareSourceCache(
                    $client,
                    $api,
                    $build,
                    $source,
                    (int) ($repositorySnapshot['id'] ?? 0),
                    $timeout
                );
                $references = $this->imageReferences(
                    $build,
                    $registry,
                    (array) ($definition['output'] ?? []),
                    (string) $sourceCache['commit']
                );
                $command = $this->command(
                    $definition,
                    '/run/galaxy-source/repository',
                    $references,
                    array_keys($secrets),
                    (int) $registry->proto === Registry::PROTO_HTTP,
                    $buildProfile
                );
                $name = 'galaxy-buildkit-' . (int) $build->id . '-' . bin2hex(random_bytes(4));
                $buildKitImage = $this->ensureBuildKitImage($client, $api, $cluster);
                $created = $api->request($client, 'POST', '/containers/create', [
                    'query' => ['name' => $name],
                    'json' => [
                        'Image' => $buildKitImage,
                        'Entrypoint' => ['/bin/sh', '-c'],
                        'Cmd' => array_merge([
                            'while [ ! -f /run/galaxy-secrets/ready ]; do sleep 0.1; done; '
                            . 'buildctl-daemonless.sh "$@"; status=$?; '
                            . 'printf "\\n' . self::METADATA_MARKER . '\\n"; '
                            . '[ ! -f /run/galaxy-secrets/metadata.json ] || cat /run/galaxy-secrets/metadata.json; '
                            . 'exit $status',
                            'galaxy-buildkit',
                        ], $command),
                        'Env' => $runnerEnvironment,
                        'Labels' => [
                            'com.codegalaxy.component' => 'buildkit-runner',
                            'com.codegalaxy.build.id' => (string) $build->id,
                        ],
                        'HostConfig' => [
                            'AutoRemove' => false,
                            'NetworkMode' => $networkMode,
                            'SecurityOpt' => [
                                'seccomp=unconfined', 'apparmor=unconfined',
                            ],
                            // Docker's archive API writes below a tmpfs mount
                            // into the hidden container rootfs. An anonymous
                            // volume keeps the files visible to the runner and
                            // is deleted with the container via `v=1`.
                            'Mounts' => [[
                                'Type' => 'volume',
                                'Target' => '/run/galaxy-secrets',
                                'ReadOnly' => false,
                                'VolumeOptions' => ['NoCopy' => true],
                            ], [
                                'Type' => 'volume',
                                'Target' => '/run/galaxy-generated',
                                'ReadOnly' => false,
                                'VolumeOptions' => ['NoCopy' => true],
                            ], [
                                'Type' => 'volume',
                                'Source' => (string) $sourceCache['volume'],
                                'Target' => '/run/galaxy-source',
                                'ReadOnly' => true,
                                'VolumeOptions' => ['NoCopy' => true],
                            ]],
                        ],
                    ],
                ]);
                $containerId = (string) ($created['Id'] ?? '');
                if ($containerId === '') {
                    throw new AppException(502, 'Docker API 未返回 BuildKit Runner Container ID');
                }
                $build->runner_ref = $containerId;
                $build->updated_at = time();
                $build->save();
                $api->request($client, 'POST', '/containers/' . rawurlencode($containerId) . '/start');
                if ($generatedFiles !== []) {
                    $this->stageSecrets($client, $api, $containerId, $generatedFiles, '/run/galaxy-generated');
                }
                $this->stageSecrets($client, $api, $containerId, $secrets);
                $state = $this->waitForContainer($client, $api, $build, $containerId, $timeout);
                $logs = $api->requestRaw($client, 'GET', '/containers/' . rawurlencode($containerId) . '/logs', [
                    'query' => ['stdout' => 1, 'stderr' => 1, 'timestamps' => 0, 'tail' => 10000],
                    'timeout' => min(60, $timeout),
                ]);
                [$stdout, $stderr] = $this->demultiplex($logs);
                $exitCode = (int) ($state['ExitCode'] ?? -1);
                $metadata = $this->metadata($stdout);
                $cleanStdout = $this->withoutMetadata(trim($stdout));
                $log = $this->trimLog(trim($cleanStdout . ($stderr === '' ? '' : "\n" . $stderr)));
                $build->log = $log;
                $build->updated_at = time();
                $build->save();
                if ($exitCode !== 0) {
                    throw new AppException(502, 'BuildKit 构建失败：' . mb_substr($log, -1800));
                }
                $digest = (string) ($metadata['containerimage.digest'] ?? '');
                if (! preg_match('/^sha256:[a-f0-9]{64}$/i', $digest)) {
                    throw new AppException(502, 'BuildKit 未返回有效的 OCI 镜像 Digest，拒绝生成可变镜像制品');
                }
                $artifactMetadata = $metadata + [
                    'registry_id' => (int) $registry->getRawOriginal('id'),
                    'source_cache' => $sourceCache,
                    'platforms' => array_values((array) (
                        $buildProfile['options']['platforms'] ?? $definition['build']['platforms'] ?? ['linux/amd64']
                    )),
                    'attestations' => [
                        'sbom' => (bool) ($definition['attestations']['sbom'] ?? true),
                        'provenance' => (bool) ($definition['attestations']['provenance'] ?? true),
                    ],
                ];
                $artifactSize = (int) ($metadata['containerimage.descriptor']['size'] ?? 0);
                try {
                    [$manifestRepository, $manifestTag] = $this->manifestTarget($references[0], $registry);
                    $manifest = $this->registries->inspectManifest($registry, $manifestRepository, $manifestTag);
                    $artifactSize = (int) ($manifest['size'] ?? $artifactSize);
                    if ((array) ($manifest['platforms'] ?? []) !== []) {
                        $artifactMetadata['platforms'] = (array) $manifest['platforms'];
                    }
                    $artifactMetadata['media_type'] = (string) ($manifest['media_type'] ?? '');
                } catch (Throwable $inspectionError) {
                    $artifactMetadata['manifest_inspection_error'] = mb_substr($inspectionError->getMessage(), 0, 500);
                }
                foreach ($references as $reference) {
                    BuildArtifact::updateOrCreate(
                        ['build_id' => (int) $build->id, 'reference' => $reference],
                        [
                            'org_id' => (int) $build->org_id,
                            'group_id' => (int) $build->group_id,
                            'project_id' => (int) $build->project_id,
                            'type' => BuildArtifact::TYPE_CONTAINER_IMAGE,
                            'digest' => $digest,
                            'size' => $artifactSize,
                            'metadata' => $artifactMetadata,
                            'created_at' => time(),
                        ]
                    );
                }
                return [
                    'references' => $references,
                    'digest' => $digest,
                    'metadata' => $metadata,
                    'source_cache' => $sourceCache,
                    'log' => $log,
                ];
            }, $timeout);
        } finally {
            try {
                if ($this->reconcileRunner($build, $cluster)) {
                    $runnerReconciledAt = time();
                }
            } catch (Throwable) {
                // A governance pass retries terminal builds whose marker is absent.
            }
        }
        if ($runnerReconciledAt > 0) {
            $result['runner_reconciled_at'] = $runnerReconciledAt;
        }
        return $result;
    }

    public function cancel(Build $build): void
    {
        $snapshot = (array) $build->spec_snapshot;
        /** @var Cluster|null $cluster */
        $cluster = Cluster::where('id', (int) ($snapshot['cluster_id'] ?? 0))
            ->where('org_id', (int) $build->org_id)
            ->where('orchestrator_type', Cluster::ORCHESTRATOR_DOCKER_SWARM)->first();
        if ($cluster === null) {
            return;
        }
        $this->reconcileRunner($build, $cluster);
    }

    private function ensureBuildKitImage(Client $client, SwarmApiClient $api, Cluster $cluster): string
    {
        $sourceImage = trim((string) config('buildkit.image'));
        if ($sourceImage === '') {
            throw new AppException(500, 'BUILDKIT_IMAGE 不能为空');
        }
        $image = $this->imageMappings->resolve($cluster, $sourceImage);
        $pullPolicy = strtolower(trim((string) config('buildkit.pull_policy', 'if-not-present')));
        if (! in_array($pullPolicy, ['always', 'if-not-present'], true)) {
            throw new AppException(500, 'BUILDKIT_PULL_POLICY 只支持 always 或 if-not-present');
        }
        if ($pullPolicy === 'if-not-present') {
            try {
                $api->request($client, 'GET', '/images/' . rawurlencode($image) . '/json');
                return $image;
            } catch (AppException $e) {
                if (! str_contains($e->getMessage(), '404')) {
                    throw $e;
                }
            }
        }
        // Docker keeps /images/create open until the pull finishes. The
        // BuildKit-level timeout supplied to withCluster covers this stream.
        $api->requestRaw($client, 'POST', '/images/create', [
            'headers' => $this->registries->dockerAuthHeader((int) $cluster->org_id, $image),
            'query' => ['fromImage' => $image],
        ]);
        return $image;
    }

    private function networkMode(): string
    {
        $mode = strtolower(trim((string) config('buildkit.network_mode', 'bridge')));
        if (! in_array($mode, ['bridge', 'host'], true)) {
            throw new AppException(500, 'BUILDKIT_NETWORK_MODE 只支持 bridge 或 host');
        }
        return $mode;
    }

    private function runnerEnvironment(): array
    {
        $environment = [
            'DOCKER_CONFIG=/run/galaxy-secrets',
            'NO_COLOR=1',
            'BUILDKITD_FLAGS=--oci-worker-no-process-sandbox',
        ];
        foreach (['http_proxy', 'https_proxy', 'no_proxy'] as $name) {
            $value = trim((string) config('buildkit.' . $name, ''));
            if ($value === '') {
                continue;
            }
            $environment[] = strtoupper($name) . '=' . $value;
            $environment[] = $name . '=' . $value;
        }
        return $environment;
    }

    /**
     * Discover runners by immutable build labels instead of trusting only the
     * create response. This also covers a successful Docker create whose Agent
     * response was lost before runner_ref could be persisted.
     */
    public function reconcileRunner(Build $build, ?Cluster $cluster = null): bool
    {
        $snapshot = (array) $build->spec_snapshot;
        $cluster ??= Cluster::where('id', (int) ($snapshot['cluster_id'] ?? 0))
            ->where('org_id', (int) $build->org_id)
            ->where('orchestrator_type', Cluster::ORCHESTRATOR_DOCKER_SWARM)->first();
        if ($cluster === null) {
            return false;
        }
        $this->docker->withCluster($cluster, function (Client $client, SwarmApiClient $api) use ($build): void {
            $filters = json_encode([
                'label' => [
                    'com.codegalaxy.component=buildkit-runner',
                    'com.codegalaxy.build.id=' . (int) $build->id,
                ],
            ], JSON_THROW_ON_ERROR);
            $containers = $api->request($client, 'GET', '/containers/json', [
                'query' => ['all' => 1, 'filters' => $filters],
            ]);
            foreach ($containers as $container) {
                $labels = (array) ($container['Labels'] ?? []);
                if (($labels['com.codegalaxy.component'] ?? '') !== 'buildkit-runner'
                    || ($labels['com.codegalaxy.build.id'] ?? '') !== (string) $build->id) {
                    throw new AppException(409, 'BuildKit Runner 资源归属校验失败，拒绝删除');
                }
                $containerId = (string) ($container['Id'] ?? '');
                if ($containerId === '') {
                    throw new AppException(502, 'Docker API 返回了缺少 ID 的 BuildKit Runner');
                }
                try {
                    $api->request($client, 'DELETE', '/containers/' . rawurlencode($containerId), [
                        'query' => ['force' => 1, 'v' => 1],
                    ]);
                } catch (AppException $e) {
                    if (! str_contains($e->getMessage(), '404')) {
                        throw $e;
                    }
                }
            }
        });

        $build->refresh();
        $result = (array) ($build->result ?? []);
        $result['runner_reconciled_at'] = time();
        $build->runner_ref = '';
        $build->result = $result;
        $build->updated_at = time();
        $build->save();
        return true;
    }

    /**
     * Maintain a cluster-local, build-only checkout. It is deliberately not
     * shared with Workspace: Workspace is a developer-owned writable tree,
     * while this volume is reset to an immutable remote revision before use.
     */
    private function prepareSourceCache(
        Client $client,
        SwarmApiClient $api,
        Build $build,
        array $source,
        int $repositoryId,
        int $timeout
    ): array {
        $cloneUrl = trim((string) ($source['clone_url'] ?? ''));
        $identity = substr(hash('sha256', $cloneUrl), 0, 16);
        $volumeName = sprintf(
            'galaxy-build-git-cache-o%d-p%d-a%d-r%d-%s',
            (int) $build->org_id,
            (int) $build->group_id,
            (int) $build->project_id,
            max(0, $repositoryId),
            $identity
        );
        $labels = [
            'com.codegalaxy.component' => 'build-git-cache',
            'com.codegalaxy.org.id' => (string) $build->org_id,
            'com.codegalaxy.group.id' => (string) $build->group_id,
            'com.codegalaxy.project.id' => (string) $build->project_id,
            'com.codegalaxy.repository.id' => (string) max(0, $repositoryId),
            'com.codegalaxy.repository.identity' => $identity,
        ];
        $api->request($client, 'POST', '/volumes/create', ['json' => [
            'Name' => $volumeName,
            'Labels' => $labels,
        ]]);
        $volume = $api->request($client, 'GET', '/volumes/' . rawurlencode($volumeName));
        $actualLabels = (array) ($volume['Labels'] ?? []);
        $legacyLabels = [
            'com.codegalaxy.component' => 'build-git-cache',
            'com.codegalaxy.org.id' => (string) $build->org_id,
            // Before the domain rename, project meant group and app meant project.
            'com.codegalaxy.project.id' => (string) $build->group_id,
            'com.codegalaxy.app.id' => (string) $build->project_id,
            'com.codegalaxy.repository.id' => (string) max(0, $repositoryId),
            'com.codegalaxy.repository.identity' => $identity,
        ];
        if (! $this->labelsMatch($actualLabels, $labels)
            && ! $this->labelsMatch($actualLabels, $legacyLabels)) {
            throw new AppException(409, 'Build Git Cache Volume 归属校验失败');
        }

        $image = trim((string) config('workspace.image'));
        if ($image === '') {
            throw new AppException(500, 'WORKSPACE_IMAGE 不能为空');
        }
        $this->ensureSourceCacheImage($client, $api, (int) $build->org_id, $image);
        $helperId = '';
        $credentialFiles = ['credential-state' => 'ready'];
        $environment = [
            'GALAXY_GIT_CLONE_URL=' . $cloneUrl,
            'GALAXY_GIT_BRANCH=' . (string) $build->branch,
            'GALAXY_GIT_COMMIT=' . (string) $build->commit_id,
            'GALAXY_GIT_REVISION_TYPE=' . (string) (((array) $build->spec_snapshot)['revision_type'] ?? 'commit'),
            'GIT_TERMINAL_PROMPT=0',
        ];
        if (! empty($source['ssh_private_key'])) {
            $credentialFiles['git-ssh-key'] = (string) $source['ssh_private_key'];
            $environment[] = 'GIT_SSH_COMMAND=ssh -i /run/secrets/git-ssh-key -o IdentitiesOnly=yes'
                . ' -o BatchMode=yes -o StrictHostKeyChecking=accept-new'
                . ' -o UserKnownHostsFile=/tmp/galaxy-build-git-known-hosts';
        } elseif (! empty($source['token'])) {
            $credentialFiles['git-token'] = (string) $source['token'];
            $environment[] = 'GIT_ASKPASS=/usr/local/bin/galaxy-git-askpass';
            $environment[] = 'GALAXY_GIT_USERNAME=' . (string) ($source['username'] ?? 'oauth2');
        }
        foreach (['http_proxy', 'https_proxy', 'no_proxy'] as $name) {
            $value = trim((string) config('buildkit.' . $name, ''));
            if ($value !== '') {
                $environment[] = strtoupper($name) . '=' . $value;
                $environment[] = $name . '=' . $value;
            }
        }

        $script = <<<'SH'
set -eu
while [ ! -f /run/secrets/ready ]; do sleep 0.1; done
repository=/cache/repository
status=reused
usable=0
if git -C "$repository" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    current_url="$(git -C "$repository" remote get-url origin 2>/dev/null || true)"
    if [ "$current_url" = "$GALAXY_GIT_CLONE_URL" ]; then
        old_remote="$(git -C "$repository" rev-parse "refs/remotes/origin/$GALAXY_GIT_BRANCH" 2>/dev/null || true)"
        if git -C "$repository" fetch --prune --tags origin; then
            new_remote="$(git -C "$repository" rev-parse "refs/remotes/origin/$GALAXY_GIT_BRANCH" 2>/dev/null || true)"
            if [ "$GALAXY_GIT_REVISION_TYPE" = tag ] || [ "$GALAXY_GIT_BRANCH" = manual ]; then
                usable=1
            elif [ -z "$old_remote" ] || [ -z "$new_remote" ] || git -C "$repository" merge-base --is-ancestor "$old_remote" "$new_remote"; then
                usable=1
            fi
        fi
    fi
fi
if [ "$usable" -ne 1 ]; then
    rm -rf -- "$repository"
    git clone --no-checkout "$GALAXY_GIT_CLONE_URL" "$repository"
    status=rebuilt
fi
if [ "$GALAXY_GIT_REVISION_TYPE" = tag ]; then
    git -C "$repository" checkout --detach --force "refs/tags/$GALAXY_GIT_COMMIT"
elif [ -n "$GALAXY_GIT_COMMIT" ]; then
    git -C "$repository" checkout --detach --force "$GALAXY_GIT_COMMIT"
else
    git -C "$repository" checkout -B "$GALAXY_GIT_BRANCH" "origin/$GALAXY_GIT_BRANCH"
fi
git -C "$repository" reset --hard HEAD
git -C "$repository" clean -fdx
commit="$(git -C "$repository" rev-parse HEAD)"
printf '\n__GALAXY_BUILD_GIT_CACHE__%s %s\n' "$status" "$commit"
SH;

        try {
            $created = $api->request($client, 'POST', '/containers/create', ['json' => [
                'Image' => $image,
                'Entrypoint' => ['/bin/sh', '-c'],
                'Cmd' => [$script],
                'Env' => $environment,
                'Labels' => $labels + ['com.codegalaxy.build.id' => (string) $build->id],
                'HostConfig' => [
                    'AutoRemove' => false,
                    'NetworkMode' => $this->networkMode(),
                    'Mounts' => [[
                        'Type' => 'volume', 'Source' => $volumeName, 'Target' => '/cache',
                        'ReadOnly' => false, 'VolumeOptions' => ['NoCopy' => true],
                    ], [
                        'Type' => 'volume', 'Target' => '/run/secrets',
                        'ReadOnly' => false, 'VolumeOptions' => ['NoCopy' => true],
                    ]],
                ],
            ]]);
            $helperId = (string) ($created['Id'] ?? '');
            if ($helperId === '') {
                throw new AppException(502, 'Docker API 未返回 Build Git Cache Helper ID');
            }
            $api->request($client, 'POST', '/containers/' . rawurlencode($helperId) . '/start');
            $this->stageSecrets($client, $api, $helperId, $credentialFiles, '/run/secrets');
            $state = $this->waitForUtilityContainer($client, $api, $helperId, min($timeout, 600));
            $logs = $api->requestRaw($client, 'GET', '/containers/' . rawurlencode($helperId) . '/logs', [
                'query' => ['stdout' => 1, 'stderr' => 1, 'timestamps' => 0, 'tail' => 2000],
                'timeout' => min(60, $timeout),
            ]);
            [$stdout, $stderr] = $this->demultiplex($logs);
            if ((int) ($state['ExitCode'] ?? -1) !== 0) {
                throw new AppException(502, '更新 Build Git Cache 失败：' . mb_substr(trim($stdout . "\n" . $stderr), -1800));
            }
            if (! preg_match('/__GALAXY_BUILD_GIT_CACHE__(reused|rebuilt) ([a-f0-9]{40,64})/i', $stdout, $matches)) {
                throw new AppException(502, 'Build Git Cache Helper 未返回有效版本');
            }
            return [
                'scope' => 'project',
                'org_id' => (int) $build->org_id,
                'group_id' => (int) $build->group_id,
                'project_id' => (int) $build->project_id,
                'cluster_id' => (int) ((array) $build->spec_snapshot)['cluster_id'],
                'volume' => $volumeName,
                'status' => strtolower($matches[1]),
                'commit' => strtolower($matches[2]),
                'repository_identity' => $identity,
                'checked_at' => time(),
            ];
        } finally {
            if ($helperId !== '') {
                try {
                    $api->request($client, 'DELETE', '/containers/' . rawurlencode($helperId), [
                        'query' => ['force' => 1, 'v' => 1],
                    ]);
                } catch (Throwable) {
                    // A subsequent governance pass can remove labeled helpers.
                }
            }
        }
    }

    private function labelsMatch(array $actual, array $expected): bool
    {
        foreach ($expected as $name => $value) {
            if (($actual[$name] ?? '') !== $value) {
                return false;
            }
        }
        return true;
    }

    private function ensureSourceCacheImage(
        Client $client,
        SwarmApiClient $api,
        int $orgId,
        string $image
    ): void {
        try {
            $api->request($client, 'GET', '/images/' . rawurlencode($image) . '/json');
            return;
        } catch (AppException $e) {
            if (! str_contains($e->getMessage(), '404')) {
                throw $e;
            }
        }
        $api->requestRaw($client, 'POST', '/images/create', [
            'headers' => $this->registries->dockerAuthHeader($orgId, $image),
            'query' => ['fromImage' => $image],
        ]);
    }

    private function waitForUtilityContainer(
        Client $client,
        SwarmApiClient $api,
        string $containerId,
        int $timeout
    ): array {
        $deadline = microtime(true) + max(10, $timeout);
        while (true) {
            if (microtime(true) >= $deadline) {
                throw new AppException(504, '更新 Build Git Cache 超时');
            }
            $inspect = $api->request($client, 'GET', '/containers/' . rawurlencode($containerId) . '/json');
            $state = (array) ($inspect['State'] ?? []);
            if (! (bool) ($state['Running'] ?? false)) {
                return $state;
            }
            usleep(500000);
        }
    }

    private function command(
        array $definition,
        string $repositoryPath,
        array $references,
        array $secretFiles,
        bool $insecureRegistry,
        array $buildProfile = []
    ): array
    {
        $buildSpec = (array) ($definition['build'] ?? []);
        $subdir = (string) ($buildProfile['build_context'] ?? $buildSpec['context'] ?? '.');
        $context = rtrim($repositoryPath, '/') . ($subdir === '.' ? '' : '/' . trim($subdir, '/'));
        $templateSource = ($buildProfile['dockerfile_source'] ?? 'repository') === 'template';
        $dockerfileLocal = $templateSource ? '/run/galaxy-generated' : rtrim($repositoryPath, '/');
        $dockerfileName = $templateSource
            ? 'Dockerfile.generated'
            : (string) ($buildProfile['repository_dockerfile_path'] ?? $buildSpec['dockerfile'] ?? 'Dockerfile');
        $command = [
            'build', '--progress=plain', '--frontend=gateway.v0', '--opt', 'source=docker/dockerfile:1',
            '--local', 'context=' . $context,
            '--local', 'dockerfile=' . $dockerfileLocal,
            '--opt', 'filename=' . $dockerfileName,
            '--opt', 'platform=' . implode(',', (array) (
                $buildProfile['options']['platforms'] ?? $buildSpec['platforms'] ?? ['linux/amd64']
            )),
        ];
        if ((string) ($buildSpec['target'] ?? '') !== '') {
            array_push($command, '--opt', 'target=' . (string) $buildSpec['target']);
        }
        $buildArgs = (array) ($buildSpec['args'] ?? []);
        foreach ((array) ($buildProfile['build_args'] ?? []) as $name => $value) {
            if (! str_starts_with((string) $name, 'CG_')) {
                throw new AppException(422, '模板 Build Arg 必须使用 CG_ 命名空间');
            }
            $buildArgs[$name] = $value;
        }
        foreach ($buildArgs as $name => $value) {
            array_push($command, '--opt', 'build-arg:' . $name . '=' . $value);
        }
        foreach ((array) ($buildSpec['secrets'] ?? []) as $name) {
            if (! in_array($name, $secretFiles, true)) {
                throw new AppException(422, '构建 Secret 尚未配置：' . $name);
            }
            array_push($command, '--secret', 'id=' . $name . ',src=/run/galaxy-secrets/' . $name);
        }
        if (in_array('GIT_AUTH_TOKEN', $secretFiles, true)) {
            array_push($command, '--secret', 'id=GIT_AUTH_TOKEN,src=/run/galaxy-secrets/GIT_AUTH_TOKEN');
        }
        if (in_array('GIT_AUTH_HEADER', $secretFiles, true)) {
            array_push($command, '--secret', 'id=GIT_AUTH_HEADER,src=/run/galaxy-secrets/GIT_AUTH_HEADER');
        }
        $output = 'type=image,name=' . implode(',', $references) . ',push=true'
            . ($insecureRegistry ? ',registry.insecure=true' : '');
        array_push($command, '--output', $output, '--metadata-file', '/run/galaxy-secrets/metadata.json');
        if ((bool) (($definition['cache']['enabled'] ?? true))) {
            $cacheImport = 'type=registry,ref=' . $references[0]
                . ($insecureRegistry ? ',registry.insecure=true' : '');
            array_push($command, '--export-cache', 'type=inline', '--import-cache', $cacheImport);
        }
        if ((bool) ($definition['attestations']['sbom'] ?? true)) {
            array_push($command, '--opt', 'attest:sbom=');
        }
        if ((bool) ($definition['attestations']['provenance'] ?? true)) {
            array_push($command, '--opt', 'attest:provenance=mode=min');
        }
        return $command;
    }

    private function registry(int $orgId, int $groupId, array $output, array $snapshot): Registry
    {
        $id = (int) ($snapshot['id'] ?? 0);
        $legacyLookup = $id <= 0;
        $id = $legacyLookup ? (int) ($output['registry_id'] ?? 0) : $id;
        if ($legacyLookup && $id === 0) {
            return Registry::getPush($orgId);
        }
        /** @var Registry|null $registry */
        $registry = Registry::where('id', $id)->where('org_id', $orgId)
            ->select('id', 'org_id', 'address', 'namespace', 'username', 'password', 'proto')->first();
        if ($registry === null) {
            throw new AppException(404, '构建快照指定的 Registry 不存在');
        }
        $registry = $this->registryGrants->apply($registry, $groupId);
        if (! $legacyLookup && (
            (string) $registry->address !== (string) ($snapshot['address'] ?? '')
            || (string) $registry->namespace !== (string) ($snapshot['namespace'] ?? '')
            || (int) $registry->proto !== (int) ($snapshot['proto'] ?? -1)
        )) {
            throw new AppException(409, 'Registry 输出目标在 Build 入队后发生变化，请重新提交构建');
        }
        return $registry;
    }

    private function imageReferences(Build $build, Registry $registry, array $output, ?string $resolvedCommit = null): array
    {
        /** @var Project|null $project */
        $project = Project::where('id', (int) $build->project_id)->select('id', 'image_name')->first();
        $repository = trim((string) ($output['repository'] ?? '')) ?: ((string) ($project?->image_name ?: 'a-' . $build->project_id));
        $base = implode('/', array_filter([
            trim((string) $registry->address, '/'), trim((string) $registry->namespace, '/'), trim($repository, '/'),
        ], fn (string $part): bool => $part !== ''));
        $values = [
            'BUILD_ID' => (string) $build->id,
            'BRANCH' => preg_replace('/[^A-Za-z0-9_.-]+/', '-', (string) $build->branch),
            'COMMIT_SHA' => $resolvedCommit ?: (string) $build->commit_id,
            'COMMIT_SHORT_SHA' => substr($resolvedCommit ?: (string) $build->commit_id, 0, 7),
        ];
        $references = [];
        foreach ((array) ($output['tags'] ?? []) as $tag) {
            $resolved = preg_replace_callback('/\$\{([A-Z_]+)\}/', function (array $match) use ($values): string {
                $value = $values[$match[1]] ?? '';
                if ($value === '') {
                    throw new AppException(422, '镜像标签变量没有值：' . $match[1]);
                }
                return $value;
            }, (string) $tag);
            $references[] = $base . ':' . $resolved;
        }
        return array_values(array_unique($references));
    }

    private function manifestTarget(string $reference, Registry $registry): array
    {
        $tagPosition = strrpos($reference, ':');
        if ($tagPosition === false || $tagPosition < strrpos($reference, '/')) {
            throw new AppException(502, '构建产物地址缺少镜像 Tag');
        }
        $repository = substr($reference, 0, $tagPosition);
        $address = trim((string) $registry->address, '/');
        if ($address !== '' && str_starts_with($repository, $address . '/')) {
            $repository = substr($repository, strlen($address) + 1);
        }
        return [$repository, substr($reference, $tagPosition + 1)];
    }

    private function secretFiles(Build $build, array $source, Registry $registry): array
    {
        $address = trim((string) $registry->address);
        $registryKey = $address === '' ? 'https://index.docker.io/v1/' : $address;
        $files = ['config.json' => json_encode(['auths' => [$registryKey => [
            'auth' => base64_encode((string) $registry->username . ':' . $registry->decryptField('password')),
        ]]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)];
        if (! empty($source['token'])) {
            if ((int) ($source['provider'] ?? 0) === GitAuth::VENDOR_GITHUB) {
                // BuildKit constructs Basic x-access-token:<token> itself.
                $files['GIT_AUTH_TOKEN'] = (string) $source['token'];
            } else {
                // GIT_AUTH_HEADER is an exact Authorization header value.
                $files['GIT_AUTH_HEADER'] = 'Basic ' . base64_encode(
                    (string) $source['username'] . ':' . (string) $source['token']
                );
            }
        }
        return $files + $this->pipelineSecrets->runtimeValues($build);
    }

    private function stageSecrets(
        Client $client,
        SwarmApiClient $api,
        string $containerId,
        array $secrets,
        string $directory = '/run/galaxy-secrets'
    ): void {
        if (! in_array($directory, ['/run/galaxy-secrets', '/run/galaxy-generated', '/run/secrets'], true)) {
            throw new AppException(500, 'Secret 暂存目录不合法');
        }
        $api->putArchive($client, $containerId, $directory, $this->tar($secrets));
        $exec = $api->request($client, 'POST', '/containers/' . rawurlencode($containerId) . '/exec', [
            'json' => [
                'AttachStdout' => true,
                'AttachStderr' => true,
                'Tty' => false,
                'User' => '0:0',
                'Cmd' => [
                    '/bin/sh', '-c',
                    'chown -R 1000:1000 "$1" && chmod 0700 "$1"'
                    . ' && find "$1" -type f -exec chmod 0600 {} +',
                    'galaxy-stage-secrets',
                    $directory,
                ],
            ],
        ]);
        $execId = (string) ($exec['Id'] ?? '');
        if ($execId === '') {
            throw new AppException(502, 'Docker API 未返回 Secret 权限初始化 Exec ID');
        }
        $output = $api->requestRaw($client, 'POST', '/exec/' . rawurlencode($execId) . '/start', [
            'json' => ['Detach' => false, 'Tty' => false],
        ]);
        $inspect = $api->request($client, 'GET', '/exec/' . rawurlencode($execId) . '/json');
        if ((int) ($inspect['ExitCode'] ?? -1) !== 0) {
            [, $stderr] = $this->demultiplex($output);
            throw new AppException(502, '初始化 BuildKit Secret 权限失败：' . trim($stderr));
        }
        // The main process is waiting on this marker, so publish it only after
        // ownership and permissions of every secret are ready.
        $api->putArchive($client, $containerId, $directory, $this->tar(['ready' => 'ready']));
    }

    private function tar(array $files): string
    {
        $archive = '';
        foreach ($files as $name => $content) {
            $name = (string) $name;
            $content = (string) $content;
            if ($name === '' || strlen($name) > 100 || str_contains($name, '/') || str_contains($name, "\0")) {
                throw new AppException(500, '构建 Secret Archive 文件名不合法');
            }
            $header = str_repeat("\0", 512);
            $this->tarField($header, 0, 100, $name);
            $this->tarField($header, 100, 8, sprintf('%07o', 0600) . "\0");
            $this->tarField($header, 108, 8, sprintf('%07o', 1000) . "\0");
            $this->tarField($header, 116, 8, sprintf('%07o', 1000) . "\0");
            $this->tarField($header, 124, 12, sprintf('%011o', strlen($content)) . "\0");
            $this->tarField($header, 136, 12, sprintf('%011o', time()) . "\0");
            $this->tarField($header, 148, 8, '        ');
            $this->tarField($header, 156, 1, '0');
            $this->tarField($header, 257, 6, "ustar\0");
            $this->tarField($header, 263, 2, '00');
            $checksum = array_sum(unpack('C*', $header));
            $this->tarField($header, 148, 8, sprintf('%06o', $checksum) . "\0 ");
            $archive .= $header . $content;
            $padding = (512 - (strlen($content) % 512)) % 512;
            if ($padding > 0) {
                $archive .= str_repeat("\0", $padding);
            }
        }
        return $archive . str_repeat("\0", 1024);
    }

    /**
     * Persist a bounded log tail while a build is running. Agent Relay buffers
     * Docker follow streams until EOF, which otherwise leaves the UI blank.
     */
    private function waitForContainer(
        Client $client,
        SwarmApiClient $api,
        Build $build,
        string $containerId,
        int $timeout
    ): array {
        $deadline = microtime(true) + $timeout;
        $interval = max(2, min(30, (int) config('buildkit.log_poll_interval', 5)));
        $lastLog = null;

        while (true) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                throw new AppException(504, sprintf('BuildKit 构建超过 %d 秒，已终止', $timeout));
            }
            if ((bool) Build::where('id', (int) $build->id)->value('cancel_requested')) {
                throw new AppException(409, 'BuildKit 构建已取消');
            }
            $requestTimeout = max(1, min(30, (int) ceil($remaining)));
            $inspect = $api->request($client, 'GET', '/containers/' . rawurlencode($containerId) . '/json', [
                'timeout' => $requestTimeout,
            ]);
            $state = (array) ($inspect['State'] ?? []);
            $logs = $api->requestRaw($client, 'GET', '/containers/' . rawurlencode($containerId) . '/logs', [
                'query' => ['stdout' => 1, 'stderr' => 1, 'timestamps' => 0, 'tail' => 1000],
                'timeout' => $requestTimeout,
            ]);
            [$stdout, $stderr] = $this->demultiplex($logs);
            $cleanStdout = $this->withoutMetadata(trim($stdout));
            $log = $this->trimLog(trim($cleanStdout . ($stderr === '' ? '' : "\n" . $stderr)));
            if ($log !== $lastLog) {
                $build->log = $log;
                $build->updated_at = time();
                $build->save();
                $lastLog = $log;
            }
            if (! (bool) ($state['Running'] ?? false)) {
                return $state;
            }
            usleep($interval * 1_000_000);
        }
    }

    private function tarField(string &$header, int $offset, int $length, string $value): void
    {
        $header = substr_replace($header, str_pad(substr($value, 0, $length), $length, "\0"), $offset, $length);
    }

    private function demultiplex(string $stream): array
    {
        $stdout = '';
        $stderr = '';
        $offset = 0;
        while ($offset + 8 <= strlen($stream)) {
            $type = ord($stream[$offset]);
            $size = (int) unpack('Nlength', substr($stream, $offset + 4, 4))['length'];
            if ($offset + 8 + $size > strlen($stream)) {
                return [$stream, ''];
            }
            $payload = substr($stream, $offset + 8, $size);
            if ($type === 2) {
                $stderr .= $payload;
            } else {
                $stdout .= $payload;
            }
            $offset += 8 + $size;
        }
        return [$stdout, $stderr];
    }

    private function metadata(string $stdout): array
    {
        $position = strrpos($stdout, self::METADATA_MARKER);
        if ($position === false) {
            return [];
        }
        $json = trim(substr($stdout, $position + strlen(self::METADATA_MARKER)));
        $metadata = json_decode($json, true);
        return is_array($metadata) ? $metadata : [];
    }

    private function withoutMetadata(string $log): string
    {
        $position = strrpos($log, self::METADATA_MARKER);
        return $position === false ? $log : rtrim(substr($log, 0, $position));
    }

    private function trimLog(string $log): string
    {
        $limit = max(65536, (int) config('buildkit.max_log_bytes', 8 * 1024 * 1024));
        return strlen($log) <= $limit ? $log : "[日志已截断]\n" . substr($log, -$limit);
    }
}

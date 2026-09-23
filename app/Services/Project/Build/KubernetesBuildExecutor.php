<?php

namespace App\Services\Project\Build;

use App\Exception\AppException;
use App\Model\Build;
use App\Model\Cluster;
use App\Model\ProjectRepository;
use App\Model\Registry;
use App\Model\GroupResourceGrant;
use App\Services\Kubernetes\KubernetesApiClient;
use App\Services\Kubernetes\KubernetesClusterService;
use App\Services\Project\BuildKitBuildSupportService;
use App\Services\Project\ProjectRepositoryCredentialService;
use App\Services\RegistryGroupGrantService;
use Swoole\Coroutine;
use Throwable;

final class KubernetesBuildExecutor implements BuildExecutor
{
    private const NAMESPACE_PREFIX = 'galaxy-build-o';
    private const CACHE_CLAIM = 'galaxy-buildkit-cache';

    public function __construct(
        private KubernetesClusterService $clusters,
        private KubernetesApiClient $api,
        private ProjectRepositoryCredentialService $repositoryCredentials,
        private RegistryGroupGrantService $registryGrants,
        private BuildKitBuildSupportService $support
    ) {}

    public function orchestratorType(): string
    {
        return Cluster::ORCHESTRATOR_KUBERNETES;
    }

    public function run(Build $build): array
    {
        $snapshot = (array) $build->spec_snapshot;
        $clusterId = (int) ($snapshot['cluster_id'] ?? 0);
        if (! GroupResourceGrant::canUseCluster(
            (int) $build->org_id,
            (int) $build->group_id,
            $clusterId
        )) {
            throw new AppException(403, '当前项目组已无权使用该 BuildKit 构建集群');
        }
        [, , $credential] = $this->clusters->connectionWithCredential((int) $build->org_id, $clusterId);
        $definition = (array) ($snapshot['definition'] ?? []);
        $repositorySnapshot = (array) ($snapshot['repository'] ?? []);
        if (trim((string) ($repositorySnapshot['clone_url'] ?? '')) === '') {
            /** @var ProjectRepository|null $repository */
            $repository = ProjectRepository::where('project_id', (int) $build->project_id)
                ->where('org_id', (int) $build->org_id)->first();
            if ($repository === null) {
                throw new AppException(422, '构建来源快照缺失且项目代码仓库已不存在');
            }
            $repositorySnapshot = [
                'id' => (int) $repository->id,
                'clone_url' => (string) $repository->clone_url,
                'provider' => (int) $repository->provider,
            ];
        }
        $source = $this->repositoryCredentials->runtimeConfigForSnapshot(
            (int) ($snapshot['credential_uid'] ?? $build->creator),
            (int) $build->org_id,
            (int) $build->group_id,
            (string) $repositorySnapshot['clone_url'],
            (int) ($repositorySnapshot['provider'] ?? 0)
        );
        if (($source['transport'] ?? 'http') === 'ssh' && empty($source['ssh_private_key'])) {
            throw new AppException(422, 'SSH Git 仓库需要先把项目组平台公钥添加到 Git 服务');
        }
        $registry = $this->registry(
            (int) $build->org_id,
            (int) $build->group_id,
            (array) ($definition['output'] ?? []),
            (array) ($snapshot['registry'] ?? [])
        );
        $buildProfile = (array) ($snapshot['build_profile'] ?? []);
        $references = $this->support->imageReferences(
            $build,
            $registry,
            (array) ($definition['output'] ?? []),
            (string) $build->commit_id
        );
        if ($references === []) {
            throw new AppException(422, 'Pipeline 没有生成任何镜像标签');
        }
        $secrets = $this->support->secretFiles($build, $source, $registry);
        if (! empty($source['ssh_private_key'])) {
            $secrets['git-ssh-key'] = (string) $source['ssh_private_key'];
        }
        $mirrorHost = $this->dockerHubMirrorHost();
        if ($mirrorHost !== '') {
            $secrets['buildkitd.toml'] = sprintf(
                "[registry.\"docker.io\"]\n  mirrors = [\"%s\"]\n",
                $mirrorHost
            );
        }
        $generated = $this->support->generatedFiles($buildProfile);
        $command = $this->support->command(
            $definition,
            '/workspace/repository',
            $references,
            array_keys($secrets),
            (int) $registry->proto === Registry::PROTO_HTTP,
            $buildProfile
        );
        $name = 'galaxy-buildkit-' . (int) $build->id;
        $namespace = $this->namespace((int) $build->org_id);
        $secretName = $name . '-input';
        $generatedName = $name . '-generated';
        $timeout = max(60, (int) config('buildkit.timeout', 1800));
        $sourceCache = [
            'scope' => 'project',
            'driver' => 'kubernetes-job',
            'cluster_id' => $clusterId,
            'namespace' => $namespace,
            'repository_id' => (int) ($repositorySnapshot['id'] ?? 0),
            'repository_identity' => substr(hash('sha256', (string) $source['clone_url']), 0, 16),
            'commit' => (string) $build->commit_id,
            'status' => 'checked-out',
            'checked_at' => time(),
        ];

        $this->ensureNamespace($credential, $namespace, (int) $build->org_id);
        $this->ensureCacheClaim($credential, $namespace, (int) $build->org_id);
        $this->deleteIgnoringMissing($credential, $this->jobPath($namespace, $name));
        $this->deleteIgnoringMissing($credential, $this->secretPath($namespace, $secretName));
        $this->deleteIgnoringMissing($credential, $this->secretPath($namespace, $generatedName));
        $this->createSecret($credential, $namespace, $secretName, $secrets);
        if ($generated !== []) {
            $this->createSecret($credential, $namespace, $generatedName, $generated);
        }
        $job = $this->job(
            $build,
            $namespace,
            $name,
            $secretName,
            $generated === [] ? '' : $generatedName,
            $source,
            $command,
            $timeout
        );
        $this->api->request($credential, 'POST', '/apis/batch/v1/namespaces/' . $namespace . '/jobs', [
            'json' => $job,
            'timeout' => 30,
        ]);
        $build->runner_ref = $namespace . '/' . $name;
        $build->updated_at = time();
        $build->save();

        try {
            [$exitCode, $stdout] = $this->waitForJob($credential, $build, $namespace, $name, $timeout);
            [$metadata, $log] = $this->support->parseOutput($stdout);
            $build->log = $log;
            $build->updated_at = time();
            $build->save();
            if ($exitCode !== 0) {
                throw new AppException(502, 'Kubernetes BuildKit 构建失败：' . mb_substr($log, -1800));
            }
            $artifact = $this->support->persistArtifacts(
                $build,
                $registry,
                $references,
                $metadata,
                $sourceCache,
                $definition,
                $buildProfile
            );
            return [
                'references' => $references,
                'digest' => $artifact['digest'],
                'metadata' => $metadata,
                'source_cache' => $sourceCache,
                'log' => $log,
                'runner_reconciled_at' => time(),
            ];
        } finally {
            $this->deleteIgnoringMissing($credential, $this->jobPath($namespace, $name));
            $this->deleteIgnoringMissing($credential, $this->secretPath($namespace, $secretName));
            $this->deleteIgnoringMissing($credential, $this->secretPath($namespace, $generatedName));
        }
    }

    public function cancel(Build $build): void
    {
        $snapshot = (array) $build->spec_snapshot;
        $clusterId = (int) ($snapshot['cluster_id'] ?? 0);
        try {
            [, , $credential] = $this->clusters->connectionWithCredential((int) $build->org_id, $clusterId);
            $namespace = $this->namespace((int) $build->org_id);
            $name = 'galaxy-buildkit-' . (int) $build->id;
            $this->deleteIgnoringMissing($credential, $this->jobPath($namespace, $name));
            $this->deleteIgnoringMissing($credential, $this->secretPath($namespace, $name . '-input'));
            $this->deleteIgnoringMissing($credential, $this->secretPath($namespace, $name . '-generated'));
        } catch (Throwable) {
            // Governance retries terminal builds; cancellation remains idempotent.
        }
    }

    public function reconcileRunner(Build $build): bool
    {
        $this->cancel($build);
        $build->refresh();
        $result = (array) ($build->result ?? []);
        $result['runner_reconciled_at'] = time();
        $build->runner_ref = '';
        $build->result = $result;
        $build->updated_at = time();
        $build->save();
        return true;
    }

    private function job(
        Build $build,
        string $namespace,
        string $name,
        string $secretName,
        string $generatedName,
        array $source,
        array $buildCommand,
        int $timeout
    ): array {
        $labels = [
            'app.kubernetes.io/managed-by' => 'galaxy',
            'app.kubernetes.io/name' => 'galaxy-buildkit',
            'codegalaxy.com/build-id' => (string) $build->id,
            'codegalaxy.com/org-id' => (string) $build->org_id,
            'codegalaxy.com/project-id' => (string) $build->project_id,
        ];
        $cloneEnvironment = [
            ['name' => 'GALAXY_GIT_CLONE_URL', 'value' => (string) $source['clone_url']],
            ['name' => 'GALAXY_GIT_BRANCH', 'value' => (string) $build->branch],
            ['name' => 'GALAXY_GIT_COMMIT', 'value' => (string) $build->commit_id],
            ['name' => 'GALAXY_GIT_REVISION_TYPE', 'value' => (string) (
                ((array) $build->spec_snapshot)['revision_type'] ?? 'commit'
            )],
            ['name' => 'GIT_TERMINAL_PROMPT', 'value' => '0'],
        ];
        $runnerEnvironment = [
            ['name' => 'DOCKER_CONFIG', 'value' => '/run/galaxy-secrets'],
            ['name' => 'NO_COLOR', 'value' => '1'],
        ];
        if ($this->dockerHubMirrorHost() !== '') {
            $runnerEnvironment[] = [
                'name' => 'BUILDKITD_FLAGS',
                'value' => '--config=/run/galaxy-secrets/buildkitd.toml',
            ];
        }
        foreach (['http_proxy', 'https_proxy', 'no_proxy'] as $proxyName) {
            $proxyValue = trim((string) config('buildkit.' . $proxyName, ''));
            if ($proxyValue === '') {
                continue;
            }
            $cloneEnvironment[] = ['name' => strtoupper($proxyName), 'value' => $proxyValue];
            $cloneEnvironment[] = ['name' => $proxyName, 'value' => $proxyValue];
            $runnerEnvironment[] = ['name' => strtoupper($proxyName), 'value' => $proxyValue];
            $runnerEnvironment[] = ['name' => $proxyName, 'value' => $proxyValue];
        }
        $cloneScript = <<<'SH'
set -eu
mkdir -p /run/galaxy-secrets /workspace
cp -R /input/. /run/galaxy-secrets/
chmod 0700 /run/galaxy-secrets
find /run/galaxy-secrets -type f -exec chmod 0600 {} +
if [ -f /run/galaxy-secrets/git-ssh-key ]; then
  export GIT_SSH_COMMAND='ssh -i /run/galaxy-secrets/git-ssh-key -o IdentitiesOnly=yes -o BatchMode=yes -o StrictHostKeyChecking=accept-new -o UserKnownHostsFile=/workspace/known-hosts'
fi
if [ -f /run/galaxy-secrets/git-token ]; then
  export GIT_ASKPASS=/usr/local/bin/galaxy-git-askpass
fi
git clone --no-checkout "$GALAXY_GIT_CLONE_URL" /workspace/repository
if [ "$GALAXY_GIT_REVISION_TYPE" = tag ]; then
  git -C /workspace/repository checkout --detach --force "refs/tags/$GALAXY_GIT_COMMIT"
else
  git -C /workspace/repository checkout --detach --force "$GALAXY_GIT_COMMIT"
fi
git -C /workspace/repository reset --hard HEAD
git -C /workspace/repository clean -fdx
git -C /workspace/repository rev-parse HEAD
chown -R 1000:1000 /workspace /run/galaxy-secrets
SH;
        $runnerScript = 'buildctl-daemonless.sh "$@"; status=$?; '
            . 'printf "\\n' . BuildKitBuildSupportService::METADATA_MARKER . '\\n"; '
            . '[ ! -f /run/galaxy-secrets/metadata.json ] || cat /run/galaxy-secrets/metadata.json; '
            . 'exit $status';
        $volumes = [
            ['name' => 'source', 'emptyDir' => (object) []],
            ['name' => 'runtime-secrets', 'emptyDir' => (object) []],
            ['name' => 'input', 'secret' => ['secretName' => $secretName, 'defaultMode' => 256]],
            ['name' => 'buildkit-state', 'persistentVolumeClaim' => ['claimName' => self::CACHE_CLAIM]],
        ];
        $mainMounts = [
            ['name' => 'source', 'mountPath' => '/workspace', 'readOnly' => true],
            ['name' => 'runtime-secrets', 'mountPath' => '/run/galaxy-secrets'],
            ['name' => 'buildkit-state', 'mountPath' => '/var/lib/buildkit'],
        ];
        if ($generatedName !== '') {
            $volumes[] = ['name' => 'generated', 'secret' => ['secretName' => $generatedName, 'defaultMode' => 256]];
            $mainMounts[] = ['name' => 'generated', 'mountPath' => '/run/galaxy-generated', 'readOnly' => true];
        }
        return [
            'apiVersion' => 'batch/v1',
            'kind' => 'Job',
            'metadata' => ['name' => $name, 'namespace' => $namespace, 'labels' => $labels],
            'spec' => [
                'backoffLimit' => 0,
                'activeDeadlineSeconds' => $timeout,
                'template' => [
                    'metadata' => [
                        'labels' => $labels,
                    ],
                    'spec' => [
                        'restartPolicy' => 'Never',
                        'initContainers' => [[
                            'name' => 'source',
                            'image' => (string) config('buildkit.git_image', 'registry.cn-shanghai.aliyuncs.com/swoole-public/git:latest'),
                            'imagePullPolicy' => 'IfNotPresent',
                            'command' => ['/bin/sh', '-c'],
                            'args' => [$cloneScript],
                            'env' => $cloneEnvironment,
                            'securityContext' => ['runAsUser' => 0, 'runAsGroup' => 0],
                            'volumeMounts' => [
                                ['name' => 'source', 'mountPath' => '/workspace'],
                                ['name' => 'runtime-secrets', 'mountPath' => '/run/galaxy-secrets'],
                                ['name' => 'input', 'mountPath' => '/input', 'readOnly' => true],
                            ],
                        ]],
                        'containers' => [[
                            'name' => 'buildkit',
                            'image' => (string) config('buildkit.kubernetes_image'),
                            'imagePullPolicy' => (string) config('buildkit.pull_policy') === 'always'
                                ? 'Always' : 'IfNotPresent',
                            'command' => ['/bin/sh', '-c'],
                            'args' => array_merge([$runnerScript, 'galaxy-buildkit'], $buildCommand),
                            'env' => $runnerEnvironment,
                            'securityContext' => [
                                'runAsUser' => 0,
                                'runAsGroup' => 0,
                                'allowPrivilegeEscalation' => true,
                                'privileged' => true,
                            ],
                            'volumeMounts' => $mainMounts,
                        ]],
                        'volumes' => $volumes,
                    ],
                ],
            ],
        ];
    }

    /** @return array{0:int,1:string} */
    private function waitForJob(
        array $credential,
        Build $build,
        string $namespace,
        string $name,
        int $timeout
    ): array
    {
        $started = time();
        $lastLog = '';
        while (time() - $started < $timeout) {
            if ((bool) Build::where('id', (int) $build->id)->value('cancel_requested')) {
                throw new AppException(409, 'BuildKit 构建已取消');
            }
            $pods = $this->api->request(
                $credential,
                'GET',
                '/api/v1/namespaces/' . $namespace . '/pods',
                ['query' => ['labelSelector' => 'job-name=' . $name]]
            );
            $pod = (array) (($pods['items'] ?? [])[0] ?? []);
            $podName = (string) ($pod['metadata']['name'] ?? '');
            if ($podName !== '') {
                try {
                    $lastLog = $this->api->requestRaw(
                        $credential,
                        'GET',
                        '/api/v1/namespaces/' . $namespace . '/pods/' . rawurlencode($podName) . '/log',
                        ['query' => ['container' => 'buildkit', 'timestamps' => 'false'], 'timeout' => 30]
                    );
                    $build->log = $this->support->parseOutput($lastLog)[1];
                    $build->updated_at = time();
                    $build->save();
                } catch (Throwable) {
                    // Pod may still be initializing.
                }
                $statuses = (array) ($pod['status']['containerStatuses'] ?? []);
                foreach ($statuses as $status) {
                    if (($status['name'] ?? '') !== 'buildkit') {
                        continue;
                    }
                    $terminated = (array) ($status['state']['terminated'] ?? []);
                    if ($terminated !== []) {
                        return [(int) ($terminated['exitCode'] ?? -1), $lastLog];
                    }
                }
                $initStatuses = (array) ($pod['status']['initContainerStatuses'] ?? []);
                foreach ($initStatuses as $status) {
                    $terminated = (array) ($status['state']['terminated'] ?? []);
                    if ($terminated !== [] && (int) ($terminated['exitCode'] ?? 0) !== 0) {
                        $sourceLog = $this->api->requestRaw(
                            $credential,
                            'GET',
                            '/api/v1/namespaces/' . $namespace . '/pods/' . rawurlencode($podName) . '/log',
                            ['query' => ['container' => 'source'], 'timeout' => 30]
                        );
                        throw new AppException(502, 'Kubernetes 构建源码准备失败：' . mb_substr($sourceLog, -1800));
                    }
                }
                foreach ((array) ($pod['status']['containerStatuses'] ?? []) as $status) {
                    $waiting = (array) ($status['state']['waiting'] ?? []);
                    if (time() - $started >= 15
                        && in_array((string) ($waiting['reason'] ?? ''), [
                            'ErrImagePull', 'ImagePullBackOff', 'InvalidImageName', 'CreateContainerConfigError',
                        ], true)) {
                        throw new AppException(502, 'Kubernetes BuildKit Pod 启动失败：'
                            . (string) ($waiting['message'] ?? $waiting['reason']));
                    }
                }
            }
            Coroutine::sleep(max(2, min(10, (int) config('buildkit.log_poll_interval', 5))));
        }
        throw new AppException(504, sprintf('Kubernetes BuildKit 构建超过 %d 秒，已终止', $timeout));
    }

    private function registry(int $orgId, int $groupId, array $output, array $snapshot): Registry
    {
        $id = (int) ($snapshot['id'] ?? 0);
        $legacy = $id <= 0;
        $id = $legacy ? (int) ($output['registry_id'] ?? 0) : $id;
        $registry = $id > 0
            ? Registry::where('id', $id)->where('org_id', $orgId)
                ->select('id', 'org_id', 'address', 'namespace', 'username', 'password', 'proto')->first()
            : Registry::getPush($orgId);
        if ($registry === null) {
            throw new AppException(404, '构建快照指定的 Registry 不存在');
        }
        $registry = $this->registryGrants->apply($registry, $groupId);
        if (! $legacy && (
            (string) $registry->address !== (string) ($snapshot['address'] ?? '')
            || (int) $registry->proto !== (int) ($snapshot['proto'] ?? -1)
        )) {
            throw new AppException(409, 'Registry 输出目标在 Build 入队后发生变化，请重新提交构建');
        }
        return $registry;
    }

    private function ensureNamespace(array $credential, string $namespace, int $orgId): void
    {
        $this->api->request($credential, 'PATCH', '/api/v1/namespaces/' . $namespace, [
            'query' => ['fieldManager' => 'galaxy', 'force' => 'true'],
            'headers' => ['Content-Type' => 'application/apply-patch+yaml'],
            'body' => json_encode([
                'apiVersion' => 'v1',
                'kind' => 'Namespace',
                'metadata' => ['name' => $namespace, 'labels' => [
                    'app.kubernetes.io/managed-by' => 'galaxy',
                    'codegalaxy.com/org-id' => (string) $orgId,
                ]],
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);
    }

    private function createSecret(array $credential, string $namespace, string $name, array $files): void
    {
        $this->api->request($credential, 'POST', '/api/v1/namespaces/' . $namespace . '/secrets', [
            'json' => [
                'apiVersion' => 'v1',
                'kind' => 'Secret',
                'metadata' => ['name' => $name, 'namespace' => $namespace, 'labels' => [
                    'app.kubernetes.io/managed-by' => 'galaxy',
                ]],
                'type' => 'Opaque',
                'data' => array_map(static fn ($value): string => base64_encode((string) $value), $files),
            ],
        ]);
    }

    private function ensureCacheClaim(array $credential, string $namespace, int $orgId): void
    {
        $size = trim((string) config('buildkit.kubernetes_cache_size', '10Gi'));
        if (! preg_match('/^[1-9][0-9]*(?:Mi|Gi|Ti)$/', $size)) {
            throw new AppException(500, 'BUILDKIT_KUBERNETES_CACHE_SIZE 格式无效');
        }
        $this->api->request(
            $credential,
            'PATCH',
            '/api/v1/namespaces/' . $namespace . '/persistentvolumeclaims/' . self::CACHE_CLAIM,
            [
                'query' => ['fieldManager' => 'galaxy', 'force' => 'true'],
                'headers' => ['Content-Type' => 'application/apply-patch+yaml'],
                'body' => json_encode([
                    'apiVersion' => 'v1',
                    'kind' => 'PersistentVolumeClaim',
                    'metadata' => [
                        'name' => self::CACHE_CLAIM,
                        'namespace' => $namespace,
                        'labels' => [
                            'app.kubernetes.io/managed-by' => 'galaxy',
                            'app.kubernetes.io/name' => 'galaxy-buildkit-cache',
                            'codegalaxy.com/org-id' => (string) $orgId,
                        ],
                    ],
                    'spec' => [
                        'accessModes' => ['ReadWriteOnce'],
                        'resources' => ['requests' => ['storage' => $size]],
                    ],
                ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ]
        );
    }

    private function deleteIgnoringMissing(array $credential, string $path): void
    {
        try {
            $this->api->request($credential, 'DELETE', $path, [
                'json' => ['apiVersion' => 'v1', 'kind' => 'DeleteOptions', 'propagationPolicy' => 'Background'],
                'timeout' => 30,
            ]);
        } catch (AppException $e) {
            if (! str_contains($e->getMessage(), 'HTTP 404')) {
                throw $e;
            }
        }
    }

    private function jobPath(string $namespace, string $name): string
    {
        return '/apis/batch/v1/namespaces/' . $namespace . '/jobs/' . rawurlencode($name);
    }

    private function secretPath(string $namespace, string $name): string
    {
        return '/api/v1/namespaces/' . $namespace . '/secrets/' . rawurlencode($name);
    }

    private function namespace(int $orgId): string
    {
        if ($orgId <= 0) {
            throw new AppException(500, 'Kubernetes 构建缺少组织标识');
        }
        return self::NAMESPACE_PREFIX . $orgId;
    }

    private function dockerHubMirrorHost(): string
    {
        $mirror = trim((string) config('buildkit.dockerhub_mirror', ''));
        if ($mirror === '') {
            return '';
        }
        $host = trim((string) (parse_url($mirror, PHP_URL_HOST) ?: $mirror), '[]/');
        if (! preg_match('/^[a-z0-9.-]+(?::[0-9]{1,5})?$/i', $host)) {
            throw new AppException(500, 'BUILDKIT_DOCKERHUB_MIRROR 格式无效');
        }
        return $host;
    }
}

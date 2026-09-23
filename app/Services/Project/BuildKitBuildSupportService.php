<?php

namespace App\Services\Project;

use App\Exception\AppException;
use App\Model\Build;
use App\Model\BuildArtifact;
use App\Model\GitAuth;
use App\Model\Project;
use App\Model\Registry;
use App\Services\RegistryService;
use Throwable;

/**
 * Provider-neutral BuildKit preparation and artifact handling.
 *
 * Swarm and Kubernetes executors only own runner lifecycle, transport and
 * logs. Build arguments, image references, credentials and OCI artifacts must
 * remain identical regardless of where BuildKit runs.
 */
final class BuildKitBuildSupportService
{
    public const METADATA_MARKER = '__GALAXY_BUILDKIT_METADATA__';

    public function __construct(
        private PipelineSecretService $pipelineSecrets,
        private RegistryService $registries
    ) {}

    public function command(
        array $definition,
        string $repositoryPath,
        array $references,
        array $secretFiles,
        bool $insecureRegistry,
        array $buildProfile = []
    ): array {
        $buildSpec = (array) ($definition['build'] ?? []);
        $subdir = (string) ($buildProfile['build_context'] ?? $buildSpec['context'] ?? '.');
        $context = rtrim($repositoryPath, '/') . ($subdir === '.' ? '' : '/' . trim($subdir, '/'));
        $templateSource = ($buildProfile['dockerfile_source'] ?? 'repository') === 'template';
        $dockerfileLocal = $templateSource ? '/run/galaxy-generated' : rtrim($repositoryPath, '/');
        $dockerfileName = $templateSource
            ? 'Dockerfile.generated'
            : (string) ($buildProfile['repository_dockerfile_path'] ?? $buildSpec['dockerfile'] ?? 'Dockerfile');
        $command = [
            'build', '--progress=plain', '--frontend=gateway.v0', '--opt', 'source=registry.cn-shanghai.aliyuncs.com/swoole-public/dockerfile:1',
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
        // Proxy variables must be sent explicitly as Dockerfile predefined
        // build args. Setting them only on buildkitd is enough for resolving
        // base images, but not for RUN apt/composer operations.
        foreach (['http_proxy', 'https_proxy', 'no_proxy'] as $name) {
            $value = trim((string) config('buildkit.' . $name, ''));
            if ($value !== '') {
                array_push($command, '--opt', 'build-arg:' . strtoupper($name) . '=' . $value);
                array_push($command, '--opt', 'build-arg:' . $name . '=' . $value);
            }
        }
        foreach ((array) ($buildSpec['secrets'] ?? []) as $name) {
            if (! in_array($name, $secretFiles, true)) {
                throw new AppException(422, '构建 Secret 尚未配置：' . $name);
            }
            array_push($command, '--secret', 'id=' . $name . ',src=/run/galaxy-secrets/' . $name);
        }
        foreach (['GIT_AUTH_TOKEN', 'GIT_AUTH_HEADER'] as $name) {
            if (in_array($name, $secretFiles, true)) {
                array_push($command, '--secret', 'id=' . $name . ',src=/run/galaxy-secrets/' . $name);
            }
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

    public function imageReferences(
        Build $build,
        Registry $registry,
        array $output,
        ?string $resolvedCommit = null
    ): array {
        /** @var Project|null $project */
        $project = Project::where('id', (int) $build->project_id)->select('id', 'image_name')->first();
        $repository = trim((string) ($output['repository'] ?? ''))
            ?: (string) ($project?->image_name ?: 'a-' . $build->project_id);
        $base = implode('/', array_filter([
            trim((string) $registry->address, '/'),
            trim((string) $registry->namespace, '/'),
            trim($repository, '/'),
        ], static fn (string $part): bool => $part !== ''));
        $values = [
            'BUILD_ID' => (string) $build->id,
            'BRANCH' => preg_replace('/[^A-Za-z0-9_.-]+/', '-', (string) $build->branch),
            'COMMIT_SHA' => $resolvedCommit ?: (string) $build->commit_id,
            'COMMIT_SHORT_SHA' => substr($resolvedCommit ?: (string) $build->commit_id, 0, 7),
        ];
        $references = [];
        foreach ((array) ($output['tags'] ?? []) as $tag) {
            $resolved = preg_replace_callback('/\$\{([A-Z_]+)\}/', static function (array $match) use ($values): string {
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

    public function secretFiles(Build $build, array $source, Registry $registry): array
    {
        $address = trim((string) $registry->address);
        $registryKey = $address === '' ? 'https://index.docker.io/v1/' : $address;
        $files = ['config.json' => json_encode(['auths' => [$registryKey => [
            'auth' => base64_encode((string) $registry->username . ':' . $registry->decryptField('password')),
        ]]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)];
        if (! empty($source['token'])) {
            if ((int) ($source['provider'] ?? 0) === GitAuth::VENDOR_GITHUB) {
                $files['GIT_AUTH_TOKEN'] = (string) $source['token'];
            } else {
                $files['GIT_AUTH_HEADER'] = 'Basic ' . base64_encode(
                    (string) $source['username'] . ':' . (string) $source['token']
                );
            }
        }
        return $files + $this->pipelineSecrets->runtimeValues($build);
    }

    public function generatedFiles(array $buildProfile): array
    {
        if (($buildProfile['dockerfile_source'] ?? 'repository') !== 'template') {
            return [];
        }
        $dockerfile = (string) ($buildProfile['rendered_dockerfile'] ?? '');
        $dockerignore = (string) ($buildProfile['rendered_dockerignore'] ?? '');
        if ($dockerfile === '' || hash('sha256', $dockerfile) !== (string) ($buildProfile['rendered_checksum'] ?? '')) {
            throw new AppException(409, 'Build 快照中的模板 Dockerfile 缺失或校验失败');
        }
        if ($dockerignore === ''
            || hash('sha256', $dockerignore) !== (string) ($buildProfile['dockerignore_checksum'] ?? '')) {
            throw new AppException(409, 'Build 快照中的 .dockerignore 缺失或校验失败');
        }
        return [
            'Dockerfile.generated' => $dockerfile,
            'Dockerfile.generated.dockerignore' => $dockerignore,
        ];
    }

    public function parseOutput(string $stdout, string $stderr = ''): array
    {
        $position = strrpos($stdout, self::METADATA_MARKER);
        $metadata = [];
        if ($position !== false) {
            $decoded = json_decode(trim(substr($stdout, $position + strlen(self::METADATA_MARKER))), true);
            $metadata = is_array($decoded) ? $decoded : [];
            $stdout = rtrim(substr($stdout, 0, $position));
        }
        return [$metadata, $this->trimLog(trim($stdout . ($stderr === '' ? '' : "\n" . $stderr)))];
    }

    public function persistArtifacts(
        Build $build,
        Registry $registry,
        array $references,
        array $metadata,
        array $sourceCache,
        array $definition,
        array $buildProfile
    ): array {
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
            [$repository, $tag] = $this->manifestTarget($references[0], $registry);
            $manifest = $this->registries->inspectManifest($registry, $repository, $tag);
            $artifactSize = (int) ($manifest['size'] ?? $artifactSize);
            if ((array) ($manifest['platforms'] ?? []) !== []) {
                $artifactMetadata['platforms'] = (array) $manifest['platforms'];
            }
            $artifactMetadata['media_type'] = (string) ($manifest['media_type'] ?? '');
        } catch (Throwable $e) {
            $artifactMetadata['manifest_inspection_error'] = mb_substr($e->getMessage(), 0, 500);
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
        return ['digest' => $digest, 'artifact_metadata' => $artifactMetadata];
    }

    public function trimLog(string $log): string
    {
        $limit = max(65536, (int) config('buildkit.max_log_bytes', 8 * 1024 * 1024));
        return strlen($log) <= $limit ? $log : "[日志已截断]\n" . substr($log, -$limit);
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
}

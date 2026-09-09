<?php

namespace App\Services\Project;

use App\Exception\AppException;
use App\Support\Functions;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use stdClass;

class PipelineDefinitionService
{
    public const VERSION = 1;

    public const KIND = 'container-build';

    public function defaultYaml(): string
    {
        return <<<'YAML'
version: 1
kind: container-build
build:
  context: .
  dockerfile: Dockerfile
  target: ''
  platforms:
    - linux/amd64
  args: {}
  secrets: []
output:
  registry_id: 0
  repository: ''
  tags:
    - '${COMMIT_SHORT_SHA}'
cache:
  enabled: true
attestations:
  sbom: true
  provenance: true
YAML;
    }

    public function parse(string $yaml): array
    {
        try {
            $input = Yaml::parse($yaml);
        } catch (ParseException $e) {
            throw new AppException(422, 'Pipeline YAML 解析失败：' . $e->getMessage());
        }
        if (! is_array($input)) {
            throw new AppException(422, 'Pipeline YAML 必须是对象');
        }
        $this->rejectUnknown($input, ['version', 'kind', 'build', 'output', 'cache', 'attestations'], 'Pipeline');
        if ((int) ($input['version'] ?? 0) !== self::VERSION) {
            throw new AppException(422, 'Pipeline version 目前只支持 1');
        }
        if ((string) ($input['kind'] ?? '') !== self::KIND) {
            throw new AppException(422, 'Pipeline kind 目前只支持 container-build');
        }

        $build = $input['build'] ?? null;
        if (! is_array($build)) {
            throw new AppException(422, 'Pipeline build 必须是对象');
        }
        $this->rejectUnknown($build, ['context', 'dockerfile', 'target', 'platforms', 'args', 'secrets'], 'build');
        $context = $this->relativePath((string) ($build['context'] ?? '.'), 'build.context', true);
        $dockerfile = $this->relativePath((string) ($build['dockerfile'] ?? 'Dockerfile'), 'build.dockerfile');
        $target = trim((string) ($build['target'] ?? ''));
        if ($target !== '' && ! preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/', $target)) {
            throw new AppException(422, 'build.target 格式不合法');
        }
        $platforms = $build['platforms'] ?? ['linux/amd64'];
        if (! is_array($platforms) || $platforms === [] || count($platforms) > 4) {
            throw new AppException(422, 'build.platforms 必须包含 1-4 个平台');
        }
        $platforms = array_values(array_unique(array_map(function ($platform): string {
            $platform = trim((string) $platform);
            if (! preg_match('#^linux/(amd64|arm64|arm/v7)$#', $platform)) {
                throw new AppException(422, '不支持构建平台：' . $platform);
            }
            return $platform;
        }, $platforms)));
        $args = $this->stringMap($build['args'] ?? [], 'build.args');
        $secrets = $build['secrets'] ?? [];
        if (! is_array($secrets) || count($secrets) > 32) {
            throw new AppException(422, 'build.secrets 必须是最多 32 项的数组');
        }
        $secrets = array_values(array_unique(array_map(function ($secret): string {
            $secret = trim((string) $secret);
            if (! preg_match('/^[A-Za-z][A-Za-z0-9_.-]{0,63}$/', $secret)) {
                throw new AppException(422, 'build.secrets 包含非法名称');
            }
            if (in_array($secret, ['config.json', 'ready', 'metadata.json', 'GIT_AUTH_TOKEN', 'GIT_AUTH_HEADER'], true)) {
                throw new AppException(422, 'build.secrets 使用了平台保留名称：' . $secret);
            }
            return $secret;
        }, $secrets)));

        $output = $input['output'] ?? null;
        if (! is_array($output)) {
            throw new AppException(422, 'Pipeline output 必须是对象');
        }
        $this->rejectUnknown($output, ['registry_id', 'repository', 'tags'], 'output');
        $registryValue = $output['registry_id'] ?? 0;
        if ($registryValue === 0 || $registryValue === '0' || $registryValue === '') {
            $registryId = 0;
        } elseif (! is_scalar($registryValue)
            || ($registryId = Functions::decodeID((string) $registryValue, true, false)) === null) {
            throw new AppException(422, 'output.registry_id 必须是 Registry 页面显示的有效 ID，0 表示组织默认仓库');
        }
        $repository = trim((string) ($output['repository'] ?? ''));
        if ($repository !== '' && ! preg_match('#^[a-z0-9]+(?:[._/-][a-z0-9]+)*$#', $repository)) {
            throw new AppException(422, 'output.repository 必须是小写镜像仓库路径');
        }
        $tags = $output['tags'] ?? ['${COMMIT_SHORT_SHA}'];
        if (! is_array($tags) || $tags === [] || count($tags) > 16) {
            throw new AppException(422, 'output.tags 必须包含 1-16 个标签');
        }
        $tags = array_values(array_unique(array_map(function ($tag): string {
            $tag = trim((string) $tag);
            if ($tag === '' || strlen($tag) > 128) {
                throw new AppException(422, 'output.tags 包含非法标签');
            }
            preg_match_all('/\$\{([A-Z_]+)\}/', $tag, $matches);
            foreach ($matches[1] as $variable) {
                if (! in_array($variable, ['COMMIT_SHA', 'COMMIT_SHORT_SHA', 'BRANCH', 'BUILD_ID'], true)) {
                    throw new AppException(422, 'output.tags 使用了不支持的变量：' . $variable);
                }
            }
            $rendered = preg_replace('/\$\{(?:COMMIT_SHA|COMMIT_SHORT_SHA|BRANCH|BUILD_ID)\}/', 'value', $tag);
            if (! is_string($rendered) || ! preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.-]{0,127}$/', $rendered)) {
                throw new AppException(422, 'output.tags 包含非法标签');
            }
            return $tag;
        }, $tags)));

        $cache = $input['cache'] ?? [];
        if (! is_array($cache)) {
            throw new AppException(422, 'Pipeline cache 必须是对象');
        }
        $this->rejectUnknown($cache, ['enabled'], 'cache');

        $attestations = $input['attestations'] ?? [];
        if (! is_array($attestations)) {
            throw new AppException(422, 'Pipeline attestations 必须是对象');
        }
        $this->rejectUnknown($attestations, ['sbom', 'provenance'], 'attestations');

        return [
            'version' => self::VERSION,
            'kind' => self::KIND,
            'build' => [
                'context' => $context,
                'dockerfile' => $dockerfile,
                'target' => $target,
                'platforms' => $platforms,
                'args' => $args === [] ? new stdClass() : $args,
                'secrets' => $secrets,
            ],
            'output' => [
                'registry_id' => $registryId,
                'repository' => $repository,
                'tags' => $tags,
            ],
            'cache' => ['enabled' => (bool) ($cache['enabled'] ?? true)],
            'attestations' => [
                'sbom' => (bool) ($attestations['sbom'] ?? true),
                'provenance' => (bool) ($attestations['provenance'] ?? true),
            ],
        ];
    }

    private function relativePath(string $path, string $field, bool $allowDot = false): string
    {
        $path = trim(str_replace('\\', '/', $path));
        if (($allowDot && $path === '.') || preg_match('#^(?!/)(?!.*(?:^|/)\.\.(?:/|$))[A-Za-z0-9._/-]+$#', $path)) {
            return $path;
        }
        throw new AppException(422, $field . ' 必须是仓库内的相对路径');
    }

    private function stringMap(mixed $value, string $field): array
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value)) || count($value) > 64) {
            throw new AppException(422, $field . ' 必须是最多 64 项的键值对象');
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', (string) $key) || ! is_scalar($item)) {
                throw new AppException(422, $field . ' 包含非法参数');
            }
            $result[(string) $key] = (string) $item;
        }
        return $result;
    }

    private function rejectUnknown(array $value, array $allowed, string $field): void
    {
        $unknown = array_diff(array_keys($value), $allowed);
        if ($unknown !== []) {
            throw new AppException(422, $field . ' 包含不支持的字段：' . implode(', ', $unknown));
        }
    }
}

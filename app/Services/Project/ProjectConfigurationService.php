<?php

namespace App\Services\Project;

use App\Exception\AppException;
use App\Model\ProjectConfiguration;
use App\Model\Env;
use App\Services\Encrypt\CredentialCipher;
use Hyperf\DbConnection\Db;

class ProjectConfigurationService
{
    public function __construct(private CredentialCipher $cipher) {}

    public function list(int $orgId, int $groupId, int $projectId): array
    {
        return ProjectConfiguration::where('org_id', $orgId)->where('group_id', $groupId)->where('project_id', $projectId)
            ->orderBy('env_id')
            ->orderByRaw("FIELD(`kind`, 'env', 'config', 'secret')")
            ->orderBy('name')->get()->map(fn (ProjectConfiguration $item): array => $this->present($item))->all();
    }

    public function create(int $uid, int $orgId, int $groupId, int $projectId, array $input): array
    {
        return Db::transaction(function () use ($uid, $orgId, $groupId, $projectId, $input): array {
            $values = $this->normalize($input, false);
            $this->assertEnvironment($orgId, (int) $values['env_id'], true);
            if (ProjectConfiguration::where('project_id', $projectId)->where('env_id', $values['env_id'])
                ->where('kind', $values['kind'])->where('name', $values['name'])->exists()) {
                throw new AppException(409, '当前作用域内的同类型配置名称已存在：' . $values['name']);
            }
            $now = time();
            $item = ProjectConfiguration::create($values + [
                'org_id' => $orgId, 'group_id' => $groupId, 'project_id' => $projectId,
                'version' => 1, 'creator' => $uid, 'created_at' => $now, 'updated_at' => $now,
            ]);
            return $this->present($item);
        });
    }

    public function update(
        int $uid,
        int $orgId,
        int $groupId,
        int $projectId,
        int $configurationId,
        array $input
    ): array {
        return Db::transaction(function () use ($uid, $orgId, $groupId, $projectId, $configurationId, $input): array {
            /** @var ProjectConfiguration|null $item */
            $item = ProjectConfiguration::where('id', $configurationId)->where('org_id', $orgId)
                ->where('group_id', $groupId)->where('project_id', $projectId)->lockForUpdate()->first();
            if ($item === null) {
                throw new AppException(404, '项目配置不存在');
            }
            $values = $this->normalize($input + ['kind' => $item->kind, 'name' => $item->name], true, $item);
            $this->assertEnvironment($orgId, (int) $values['env_id'], true);
            $duplicate = ProjectConfiguration::where('project_id', $projectId)->where('env_id', $values['env_id'])
                ->where('kind', $values['kind'])
                ->where('name', $values['name'])->where('id', '<>', $configurationId)->exists();
            if ($duplicate) {
                throw new AppException(409, '当前作用域内的同类型配置名称已存在：' . $values['name']);
            }
            foreach ($values as $key => $value) {
                $item->{$key} = $value;
            }
            $item->version = (int) $item->version + 1;
            $item->creator = $uid;
            $item->updated_at = time();
            $item->save();
            return $this->present($item);
        });
    }

    public function delete(int $orgId, int $groupId, int $projectId, int $configurationId): void
    {
        $deleted = ProjectConfiguration::where('id', $configurationId)->where('org_id', $orgId)
            ->where('group_id', $groupId)->where('project_id', $projectId)->delete();
        if ($deleted === 0) {
            throw new AppException(404, '项目配置不存在');
        }
    }

    /**
     * Merge project defaults with release-local overrides. The returned
     * values are ready to be frozen on the new Release; later source edits can
     * never mutate an existing release or rollback.
     */
    public function resolveRelease(
        int $orgId,
        int $groupId,
        int $projectId,
        int $envId,
        array $definition,
        array $releaseSecrets,
        array $releaseConfigs
    ): array {
        $env = [];
        $secrets = [];
        $configs = [];
        /** @var ProjectConfiguration $item */
        foreach (ProjectConfiguration::where('org_id', $orgId)->where('group_id', $groupId)
            ->where('project_id', $projectId)->whereIn('env_id', [0, $envId])
            ->orderBy('env_id')->orderBy('id')->get() as $item) {
            $source = ['source_configuration_id' => (int) $item->id, 'source_version' => (int) $item->version];
            if ($item->kind === ProjectConfiguration::KIND_ENV) {
                $env[(string) $item->name] = ['name' => (string) $item->name, 'value' => (string) $item->plain_value] + $source;
            } elseif ($item->kind === ProjectConfiguration::KIND_CONFIG) {
                $configs[(string) $item->name] = [
                    'name' => (string) $item->name, 'target' => (string) $item->target,
                    'value' => (string) $item->plain_value, 'file_mode' => (int) $item->file_mode,
                ] + $source;
            } elseif ($item->kind === ProjectConfiguration::KIND_SECRET) {
                $secrets[(string) $item->name] = [
                    'name' => (string) $item->name, 'target' => (string) $item->target,
                    'value' => $this->cipher->decrypt((string) $item->encrypted_value),
                    'file_mode' => (int) $item->file_mode,
                ] + $source;
            }
        }
        foreach ((array) ($definition['env'] ?? []) as $item) {
            $env[(string) $item['name']] = $item + ['source_configuration_id' => 0, 'source_version' => 0];
        }
        foreach ($releaseConfigs as $item) {
            $configs[(string) $item['name']] = $item + ['source_configuration_id' => 0, 'source_version' => 0];
        }
        foreach ($releaseSecrets as $item) {
            $secrets[(string) $item['name']] = $item + ['source_configuration_id' => 0, 'source_version' => 0, 'file_mode' => 0440];
        }
        if (count($env) > 256 || count($configs) > 64 || count($secrets) > 64) {
            throw new AppException(422, '合并项目默认值后超过发布上限：Env 256、Config 64、Secret 64');
        }
        $envBytes = 0;
        foreach ($env as $item) {
            $envBytes += strlen((string) $item['name']) + strlen((string) $item['value']) + 1;
        }
        if ($envBytes > 256 * 1024) {
            throw new AppException(422, '合并项目默认值后的环境变量总容量不能超过 256 KiB');
        }
        $targets = [];
        foreach ((array) ($definition['mounts'] ?? []) as $mount) {
            $targets[(string) ($mount['target'] ?? '')] = '目录映射';
        }
        foreach (array_merge(array_values($configs), array_values($secrets)) as $item) {
            $target = (string) $item['target'];
            if (isset($targets[$target])) {
                throw new AppException(422, '配置挂载目标与其他配置或目录映射冲突：' . $target);
            }
            $targets[$target] = '配置';
        }
        $definition['env'] = array_map(static fn (array $item): array => [
            'name' => $item['name'], 'value' => $item['value'],
            'source_configuration_id' => $item['source_configuration_id'],
            'source_version' => $item['source_version'],
        ], array_values($env));
        $definition['configs'] = array_map(static fn (array $item): array => [
            'name' => $item['name'], 'target' => $item['target'], 'file_mode' => $item['file_mode'],
            'source_configuration_id' => $item['source_configuration_id'], 'source_version' => $item['source_version'],
        ], array_values($configs));
        $definition['secrets'] = array_map(static fn (array $item): array => [
            'name' => $item['name'], 'target' => $item['target'], 'file_mode' => $item['file_mode'],
            'source_configuration_id' => $item['source_configuration_id'], 'source_version' => $item['source_version'],
        ], array_values($secrets));
        return [$definition, array_values($secrets), array_values($configs)];
    }

    private function normalize(array $input, bool $updating, ?ProjectConfiguration $current = null): array
    {
        $envId = (int) ($input['env_id'] ?? ($current?->env_id ?? 0));
        if ($envId < 0) {
            throw new AppException(422, '配置作用域不合法');
        }
        $kind = strtolower(trim((string) ($input['kind'] ?? '')));
        if (! in_array($kind, [ProjectConfiguration::KIND_ENV, ProjectConfiguration::KIND_CONFIG, ProjectConfiguration::KIND_SECRET], true)) {
            throw new AppException(422, '配置类型只支持 env、config、secret');
        }
        $name = trim((string) ($input['name'] ?? ''));
        $namePattern = $kind === ProjectConfiguration::KIND_ENV
            ? '/^[A-Z_][A-Z0-9_]{0,127}$/'
            : '/^[a-z0-9][a-z0-9_.-]{0,127}$/';
        if (! preg_match($namePattern, $name)) {
            throw new AppException(422, $kind === ProjectConfiguration::KIND_ENV ? '环境变量名称不合法' : '配置名称不合法');
        }
        $hasValue = array_key_exists('value', $input);
        if (! $hasValue && ! ($updating && $kind === ProjectConfiguration::KIND_SECRET && $current !== null)) {
            throw new AppException(422, '配置值不能为空');
        }
        $value = $hasValue ? (string) $input['value'] : '';
        if ($hasValue && (str_contains($value, "\0") || strlen($value) > 500 * 1024)) {
            throw new AppException(422, '配置值不能包含空字节且不能超过 500 KiB');
        }
        if ($kind === ProjectConfiguration::KIND_ENV && $hasValue && strlen($value) > 32768) {
            throw new AppException(422, '单个环境变量值不能超过 32 KiB');
        }
        $target = trim((string) ($input['target'] ?? ''));
        if ($kind === ProjectConfiguration::KIND_ENV) {
            $target = '';
        } elseif ($target === '') {
            $target = $kind === ProjectConfiguration::KIND_CONFIG ? '/etc/' . $name : $name;
        }
        if ($kind === ProjectConfiguration::KIND_CONFIG && (! str_starts_with($target, '/') || ! $this->safePath($target))) {
            throw new AppException(422, '配置文件挂载路径必须是安全的容器绝对路径');
        }
        if ($kind === ProjectConfiguration::KIND_SECRET
            && ! preg_match('#^(?:/[A-Za-z0-9_.-]+)+$|^[A-Za-z0-9][A-Za-z0-9_.-]{0,254}$#', $target)) {
            throw new AppException(422, 'Secret 挂载目标必须是文件名或安全的容器绝对路径');
        }
        $mode = (int) ($input['file_mode'] ?? ($kind === ProjectConfiguration::KIND_SECRET ? 0440 : 0444));
        if ($mode < 0 || $mode > 0777) {
            throw new AppException(422, '文件权限必须在 0000 到 0777 之间');
        }
        $values = [
            'env_id' => $envId, 'kind' => $kind, 'name' => $name, 'target' => $target, 'file_mode' => $mode,
            'description' => mb_substr(trim((string) ($input['description'] ?? '')), 0, 500),
        ];
        if ($kind === ProjectConfiguration::KIND_SECRET) {
            $values['plain_value'] = null;
            if ($hasValue) {
                if ($value === '') {
                    throw new AppException(422, 'Secret 值不能为空');
                }
                $values['encrypted_value'] = $this->cipher->encrypt($value);
                $values['value_hash'] = hash('sha256', $value);
                $values['value_size'] = strlen($value);
            }
        } else {
            $values['plain_value'] = $value;
            $values['encrypted_value'] = null;
            $values['value_hash'] = hash('sha256', $value);
            $values['value_size'] = strlen($value);
        }
        return $values;
    }

    private function assertEnvironment(int $orgId, int $envId, bool $lock = false): void
    {
        if ($envId === 0) {
            return;
        }
        $query = Env::where('org_id', $orgId)->where('id', $envId)->where('archived_at', 0);
        if ($lock) {
            $query->lockForUpdate();
        }
        if ($query->first(['id']) === null) {
            throw new AppException(422, '配置所选部署环境不存在或已归档');
        }
    }

    private function safePath(string $path): bool
    {
        return ! str_contains($path, "\0") && ! preg_match('#(?:^|/)\.\.(?:/|$)#', $path) && $path !== '/';
    }

    private function present(ProjectConfiguration $item): array
    {
        $data = $item->toArray();
        if ($item->kind === ProjectConfiguration::KIND_SECRET) {
            unset($data['plain_value']);
            $data['configured'] = (string) $item->encrypted_value !== '';
        } else {
            $data['value'] = (string) $item->plain_value;
            unset($data['plain_value']);
        }
        return $data;
    }
}

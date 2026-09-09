<?php

namespace App\Services;

use App\Exception\AppException;
use App\Model\Group;
use App\Model\Project;
use App\Model\ProjectRegistryRel;
use App\Model\Registry;
use App\Model\RegistryGroupGrant;
use Hyperf\DbConnection\Db;

final class RegistryGroupGrantService
{
    public function groups(int $orgId, int $registryId): array
    {
        $registry = $this->registry($orgId, $registryId);
        $grants = RegistryGroupGrant::where('org_id', $orgId)->where('registry_id', $registryId)
            ->get()->keyBy('group_id');
        return Group::where('org_id', $orgId)->orderBy('id')->get(['id', 'title', 'alias'])
            ->map(static function (Group $group) use ($grants, $registry): array {
                /** @var RegistryGroupGrant|null $grant */
                $grant = $grants->get((int) $group->id);
                return [
                    'id' => (int) $group->id,
                    'title' => (string) $group->title,
                    'alias' => (string) $group->alias,
                    'granted' => $grant !== null,
                    'namespace' => $grant === null ? (string) $registry->namespace : (string) $grant->namespace,
                ];
            })->all();
    }

    public function sync(int $uid, int $orgId, int $registryId, array $items): array
    {
        $registry = $this->registry($orgId, $registryId);
        $normalized = [];
        foreach ($items as $item) {
            $groupId = (int) ($item['group_id'] ?? 0);
            if ($groupId <= 0 || isset($normalized[$groupId])) {
                throw new AppException(422, '项目组授权列表包含无效或重复项目组');
            }
            $normalized[$groupId] = $this->normalizeNamespace(
                (string) ($item['namespace'] ?? ''),
                (string) $registry->namespace
            );
        }
        $groupIds = array_keys($normalized);
        $validIds = Group::where('org_id', $orgId)->whereIn('id', $groupIds ?: [0])
            ->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        sort($validIds);
        $expectedIds = $groupIds;
        sort($expectedIds);
        if ($validIds !== $expectedIds) {
            throw new AppException(422, '授权列表中包含不存在的项目组');
        }

        $currentIds = RegistryGroupGrant::where('org_id', $orgId)->where('registry_id', $registryId)
            ->pluck('group_id')->map(static fn ($id): int => (int) $id)->all();
        foreach (array_diff($currentIds, $groupIds) as $revokedGroupId) {
            if ($this->groupUsesRegistry($orgId, (int) $revokedGroupId, $registryId)) {
                throw new AppException(409, '该项目组仍有项目使用此镜像仓库，不能撤销授权');
            }
        }

        Db::transaction(function () use ($uid, $orgId, $registryId, $normalized): void {
            RegistryGroupGrant::where('org_id', $orgId)->where('registry_id', $registryId)->delete();
            $now = time();
            foreach ($normalized as $groupId => $namespace) {
                RegistryGroupGrant::create([
                    'org_id' => $orgId,
                    'registry_id' => $registryId,
                    'group_id' => $groupId,
                    'namespace' => $namespace,
                    'granted_by' => $uid,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });
        return $this->groups($orgId, $registryId);
    }

    public function namespace(int $orgId, int $groupId, int $registryId): string
    {
        $namespace = RegistryGroupGrant::where('org_id', $orgId)->where('group_id', $groupId)
            ->where('registry_id', $registryId)->value('namespace');
        if (! is_string($namespace)) {
            throw new AppException(403, '当前项目组未获授权使用该镜像仓库');
        }
        return $namespace;
    }

    public function apply(Registry $registry, int $groupId): Registry
    {
        $registry->namespace = $this->namespace((int) $registry->org_id, $groupId, (int) $registry->getRawOriginal('id'));
        return $registry;
    }

    public function assertReference(
        int $orgId,
        int $groupId,
        int $registryId,
        string $reference
    ): void {
        $namespace = $this->namespace($orgId, $groupId, $registryId);
        $registry = $this->registry($orgId, $registryId);
        $repository = trim($reference);
        $address = trim((string) $registry->address, '/');
        if ($address !== '' && str_starts_with($repository, $address . '/')) {
            $repository = substr($repository, strlen($address) + 1);
        }
        $repository = explode('@', $repository, 2)[0];
        $lastSlash = strrpos($repository, '/');
        $lastColon = strrpos($repository, ':');
        if ($lastColon !== false && ($lastSlash === false || $lastColon > $lastSlash)) {
            $repository = substr($repository, 0, $lastColon);
        }
        $repository = trim($repository, '/');
        if ($repository !== $namespace && ! str_starts_with($repository, $namespace . '/')) {
            throw new AppException(403, sprintf(
                '镜像「%s」不在当前项目组获授权的 namespace「%s」之下',
                $reference,
                $namespace
            ));
        }
    }

    private function registry(int $orgId, int $registryId): Registry
    {
        $registry = Registry::where('org_id', $orgId)->where('id', $registryId)->first();
        if ($registry === null) {
            throw new AppException(404, '镜像仓库不存在');
        }
        return $registry;
    }

    private function normalizeNamespace(string $namespace, string $base): string
    {
        $namespace = strtolower(trim($namespace, " \t\n\r\0\x0B/"));
        $base = strtolower(trim($base, " \t\n\r\0\x0B/"));
        if ($namespace === '') {
            throw new AppException(422, '项目组授权必须设置 namespace');
        }
        if (! preg_match('#^[a-z0-9]+(?:[._/-][a-z0-9]+)*$#', $namespace)) {
            throw new AppException(422, 'namespace 只能包含小写字母、数字、点、下划线、连字符和斜线');
        }
        if ($base !== '' && $namespace !== $base && ! str_starts_with($namespace, $base . '/')) {
            throw new AppException(422, sprintf('项目组 namespace 必须位于仓库 namespace「%s」之下', $base));
        }
        return $namespace;
    }

    private function groupUsesRegistry(int $orgId, int $groupId, int $registryId): bool
    {
        $projectIds = Project::where('org_id', $orgId)->where('group_id', $groupId)->pluck('id');
        return $projectIds->isNotEmpty() && ProjectRegistryRel::where('org_id', $orgId)
            ->where('registry_id', $registryId)->whereIn('project_id', $projectIds->all())->exists();
    }
}

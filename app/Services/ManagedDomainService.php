<?php

namespace App\Services;

use App\Exception\AppException;
use App\Model\GatewayVhost;
use App\Model\Group;
use App\Model\GroupResourceGrant;
use App\Model\ManagedDomain;
use App\Model\ProjectRoute;
use App\Model\ProjectRuntime;
use App\Services\Project\ProjectServiceIdentity;
use App\Support\MySQL;
use Hyperf\DbConnection\Db;

final class ManagedDomainService
{
    public function list(int $orgId, ?string $keyword, int $page, int $pageSize): array
    {
        $query = ManagedDomain::where('org_id', $orgId)->orderByDesc('id');
        if ($keyword !== null && trim($keyword) !== '') {
            $query->where('hostname', 'like', '%' . trim($keyword) . '%');
        }
        return MySQL::jsonPaginate($query, $page, $pageSize);
    }

    public function create(int $uid, int $orgId, string $hostname, bool $allowSubdomains, string $remark): ManagedDomain
    {
        $hostname = $this->normalize($hostname);
        if (ManagedDomain::where('org_id', $orgId)->where('hostname', $hostname)->exists()) {
            throw new AppException(409, '该域名已存在');
        }
        return ManagedDomain::create([
            'org_id' => $orgId, 'hostname' => $hostname, 'allow_subdomains' => $allowSubdomains,
            'remark' => trim($remark),
            'creator' => $uid, 'created_at' => time(), 'updated_at' => time(),
        ]);
    }

    public function update(int $orgId, int $id, string $hostname, bool $allowSubdomains, string $remark): ManagedDomain
    {
        $domain = $this->find($orgId, $id);
        $hostname = $this->normalize($hostname);
        if ($hostname !== (string) $domain->hostname && $this->inUse($orgId, (string) $domain->hostname)) {
            throw new AppException(409, '域名已被网关路由使用，不能修改');
        }
        if ((bool) $domain->allow_subdomains && ! $allowSubdomains
            && $this->descendantInUse($orgId, (string) $domain->hostname)) {
            throw new AppException(409, '仍有子域名路由在使用，不能关闭子域名权限');
        }
        if (ManagedDomain::where('org_id', $orgId)->where('hostname', $hostname)->where('id', '<>', $id)->exists()) {
            throw new AppException(409, '该域名已存在');
        }
        $domain->hostname = $hostname;
        $domain->allow_subdomains = $allowSubdomains;
        $domain->remark = trim($remark);
        $domain->updated_at = time();
        $domain->save();
        return $domain;
    }

    public function delete(int $orgId, int $id): void
    {
        $domain = $this->find($orgId, $id);
        if ($this->inUse($orgId, (string) $domain->hostname)) {
            throw new AppException(409, '域名已被网关路由使用，不能删除');
        }
        Db::transaction(function () use ($orgId, $id, $domain): void {
            GroupResourceGrant::where('org_id', $orgId)->where('resource_type', GroupResourceGrant::TYPE_DOMAIN)
                ->where('resource_id', $id)->delete();
            $domain->delete();
        });
    }

    public function options(int $orgId, int $groupId): array
    {
        $ids = GroupResourceGrant::domainIds($orgId, $groupId);
        return ManagedDomain::where('org_id', $orgId)->whereIn('id', $ids ?: [0])
            ->orderBy('hostname')->get(['id', 'hostname', 'allow_subdomains', 'remark'])->toArray();
    }

    public function assertGroupCanUse(int $orgId, int $groupId, string $hostname): void
    {
        $hostname = $this->normalize($hostname);
        $allowed = ManagedDomain::where('org_id', $orgId)
            ->whereIn('id', GroupResourceGrant::domainIds($orgId, $groupId) ?: [0])
            ->get(['hostname', 'allow_subdomains'])->contains(static function (ManagedDomain $domain) use ($hostname): bool {
                $base = (string) $domain->hostname;
                return $hostname === $base || ((bool) $domain->allow_subdomains && str_ends_with($hostname, '.' . $base));
            });
        if (! $allowed) {
            throw new AppException(403, '该域名未分配给当前项目组');
        }
    }

    /**
     * A legacy administrator VHost becomes a project-owned route during
     * import. Make the same ownership transition in the domain ACL so the
     * project can subsequently edit that persisted route.
     */
    public function grantImportedHostname(
        int $uid,
        int $orgId,
        int $groupId,
        string $hostname
    ): ManagedDomain {
        $hostname = $this->normalize($hostname);
        /** @var ManagedDomain|null $domain */
        $domain = ManagedDomain::where('org_id', $orgId)->get()
            ->filter(static function (ManagedDomain $candidate) use ($hostname): bool {
                $base = (string) $candidate->hostname;
                return $hostname === $base
                    || ((bool) $candidate->allow_subdomains && str_ends_with($hostname, '.' . $base));
            })
            ->sortByDesc(static fn (ManagedDomain $candidate): int => strlen((string) $candidate->hostname))
            ->first();
        if ($domain === null) {
            $domain = $this->create($uid, $orgId, $hostname, false, '从现有网关路由导入');
        }
        if (! GroupResourceGrant::where('org_id', $orgId)->where('group_id', $groupId)
            ->where('resource_type', GroupResourceGrant::TYPE_DOMAIN)
            ->where('resource_id', (int) $domain->id)->exists()) {
            GroupResourceGrant::create([
                'org_id' => $orgId,
                'group_id' => $groupId,
                'resource_type' => GroupResourceGrant::TYPE_DOMAIN,
                'resource_id' => (int) $domain->id,
                'granted_by' => $uid,
                'created_at' => time(),
            ]);
        }
        return $domain;
    }

    public function assertGroupCanUseRoute(
        int $orgId, int $groupId, int $projectId, int $clusterId, int $routeId, string $source
    ): void {
        $hostname = $source === 'project'
            ? ProjectRoute::where('id', $routeId)->where('org_id', $orgId)->where('group_id', $groupId)
                ->where('project_id', $projectId)->where('cluster_id', $clusterId)->value('hostname')
            : GatewayVhost::where('id', $routeId)->where('org_id', $orgId)
                ->where('cluster_id', $clusterId)->value('hostname');
        if (! is_string($hostname) || $hostname === '') {
            throw new AppException(404, '网关路由不存在');
        }
        $this->assertGroupCanUse($orgId, $groupId, $hostname);
    }

    public function groups(int $orgId, int $domainId): array
    {
        $this->find($orgId, $domainId);
        $granted = GroupResourceGrant::where('org_id', $orgId)->where('resource_type', GroupResourceGrant::TYPE_DOMAIN)
            ->where('resource_id', $domainId)->pluck('group_id')->map(static fn ($id): int => (int) $id)->flip()->all();
        return Group::where('org_id', $orgId)->orderBy('id')->get(['id', 'title', 'alias'])
            ->map(static fn (Group $group): array => [
                'id' => (int) $group->id, 'title' => (string) $group->title,
                'alias' => (string) $group->alias, 'granted' => isset($granted[(int) $group->id]),
            ])->all();
    }

    public function syncGroups(int $uid, int $orgId, int $domainId, array $groupIds): array
    {
        $domain = $this->find($orgId, $domainId);
        $groupIds = array_values(array_unique(array_map('intval', $groupIds)));
        $valid = Group::where('org_id', $orgId)->whereIn('id', $groupIds ?: [0])->pluck('id')
            ->map(static fn ($id): int => (int) $id)->all();
        sort($valid); sort($groupIds);
        if ($valid !== $groupIds) {
            throw new AppException(422, '授权列表中包含不存在的项目组');
        }
        $current = GroupResourceGrant::where('org_id', $orgId)
            ->where('resource_type', GroupResourceGrant::TYPE_DOMAIN)->where('resource_id', $domainId)
            ->pluck('group_id')->map(static fn ($id): int => (int) $id)->all();
        foreach (array_diff($current, $groupIds) as $revokedGroupId) {
            if ($this->groupUsesDomain($orgId, (int) $revokedGroupId, (string) $domain->hostname)) {
                throw new AppException(409, '该项目组仍有网关路由使用此域名，不能撤销分配');
            }
        }
        Db::transaction(function () use ($uid, $orgId, $domainId, $groupIds): void {
            GroupResourceGrant::where('org_id', $orgId)->where('resource_type', GroupResourceGrant::TYPE_DOMAIN)
                ->where('resource_id', $domainId)->delete();
            foreach ($groupIds as $groupId) {
                GroupResourceGrant::create(['org_id' => $orgId, 'group_id' => $groupId,
                    'resource_type' => GroupResourceGrant::TYPE_DOMAIN, 'resource_id' => $domainId,
                    'granted_by' => $uid, 'created_at' => time()]);
            }
        });
        return $this->groups($orgId, $domainId);
    }

    private function find(int $orgId, int $id): ManagedDomain
    {
        $domain = ManagedDomain::where('org_id', $orgId)->where('id', $id)->first();
        if ($domain === null) throw new AppException(404, '域名不存在');
        return $domain;
    }

    private function normalize(string $hostname): string
    {
        $hostname = strtolower(rtrim(trim($hostname), '.'));
        if (! preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)+$/', $hostname)) {
            throw new AppException(422, '域名格式不正确');
        }
        return $hostname;
    }

    private function inUse(int $orgId, string $hostname): bool
    {
        return GatewayVhost::where('org_id', $orgId)->where('hostname', $hostname)->exists()
            || ProjectRoute::where('org_id', $orgId)->where('hostname', $hostname)->exists()
            || $this->descendantInUse($orgId, $hostname);
    }

    private function descendantInUse(int $orgId, string $hostname): bool
    {
        $suffix = '%.' . $hostname;
        return GatewayVhost::where('org_id', $orgId)->where('hostname', 'like', $suffix)->exists()
            || ProjectRoute::where('org_id', $orgId)->where('hostname', 'like', $suffix)->exists();
    }

    private function groupUsesDomain(int $orgId, int $groupId, string $hostname): bool
    {
        if (ProjectRoute::where('org_id', $orgId)->where('group_id', $groupId)
            ->where(static fn ($query) => $query->where('hostname', $hostname)
                ->orWhere('hostname', 'like', '%.' . $hostname))->exists()) {
            return true;
        }
        $serviceNames = ProjectRuntime::where('org_id', $orgId)->where('group_id', $groupId)->get()
            ->map(static fn (ProjectRuntime $runtime): string => ProjectServiceIdentity::runtimeDockerName($runtime))
            ->filter()->values()->all();
        return $serviceNames !== [] && GatewayVhost::where('org_id', $orgId)
            ->where(static fn ($query) => $query->where('hostname', $hostname)
                ->orWhere('hostname', 'like', '%.' . $hostname))
            ->whereIn('target_service', $serviceNames)->exists();
    }
}

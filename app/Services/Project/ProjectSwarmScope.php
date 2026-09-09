<?php

namespace App\Services\Project;

use App\Exception\AppException;
use App\Model\Cluster;
use App\Model\GatewayVhost;
use App\Model\GroupResourceGrant;
use App\Model\ProjectRuntime;
use App\Services\Docker\SwarmOverviewService;
use App\Support\Functions;

/** Resolve and enforce the optional project scope of shared cluster APIs. */
final class ProjectSwarmScope
{
    public function __construct(private SwarmOverviewService $swarm) {}

    public function projectId(): ?int
    {
        $projectId = (int) Functions::getContextValue('project_id', false, 0);
        return $projectId > 0 ? $projectId : null;
    }

    public function assertClusterScope(): void
    {
        if ($this->projectId() !== null) {
            throw new AppException(403, '项目视图不允许修改集群基础设置');
        }
    }

    public function assertGrantedCluster(Cluster $cluster): void
    {
        if ($this->projectId() === null) {
            return;
        }
        $groupId = (int) Functions::getContextValue('group_id');
        if (! GroupResourceGrant::canUseCluster((int) $cluster->org_id, $groupId, (int) $cluster->id)) {
            throw new AppException(403, '当前 Docker Swarm 集群未分配给该项目组');
        }
    }

    /** @return null|array<int, string> null denotes unrestricted cluster scope. */
    public function serviceReferences(Cluster $cluster): ?array
    {
        $projectId = $this->projectId();
        if ($projectId === null) {
            return null;
        }
        $groupId = (int) Functions::getContextValue('group_id');
        $this->assertGrantedCluster($cluster);
        $runtimes = ProjectRuntime::where('org_id', (int) $cluster->org_id)
            ->where('group_id', $groupId)->where('project_id', $projectId)
            ->where('cluster_id', (int) $cluster->id)->get();
        $references = [];
        foreach ($runtimes as $runtime) {
            foreach ([(string) $runtime->runtime_ref, ProjectServiceIdentity::runtimeDockerName($runtime)] as $reference) {
                $reference = trim($reference);
                if ($reference !== '') {
                    $references[$reference] = $reference;
                }
            }
        }
        return array_values($references);
    }

    public function assertService(Cluster $cluster, string $serviceId): void
    {
        $references = $this->serviceReferences($cluster);
        if ($references !== null && ! in_array($serviceId, $references, true)) {
            throw new AppException(404, '当前项目中不存在该 Swarm Service');
        }
    }

    public function assertContainer(Cluster $cluster, string $containerId): void
    {
        $references = $this->serviceReferences($cluster);
        if ($references === null) {
            return;
        }
        $reference = $this->swarm->containerServiceReference($cluster, $containerId);
        if ($reference === '' || ! in_array($reference, $references, true)) {
            throw new AppException(404, '当前项目中不存在该 Swarm Container');
        }
    }

    public function assertGatewayVhost(Cluster $cluster, int $vhostId): void
    {
        $vhost = GatewayVhost::where('id', $vhostId)
            ->where('org_id', (int) $cluster->org_id)
            ->where('cluster_id', (int) $cluster->id)->first();
        if ($vhost === null) {
            throw new AppException(404, '网关路由不存在');
        }
        $this->assertService($cluster, (string) $vhost->target_service);
    }
}

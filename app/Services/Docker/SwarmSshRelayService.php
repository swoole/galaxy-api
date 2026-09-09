<?php

namespace App\Services\Docker;

use App\Exception\AppException;
use App\Model\Cluster;
use App\Model\ClusterAgentNode;
use App\Model\GroupMember;
use App\Model\OrgMember;
use App\Model\Project;
use App\Model\ProjectMember;
use App\Model\UserPersonalSshKey;
use App\Services\Project\ProjectSwarmScope;
use Hyperf\Context\Context;

final class SwarmSshRelayService
{
    public function __construct(
        private SwarmTerminalService $terminal,
        private ProjectSwarmScope $scope
    ) {}

    public function authenticate(string $fingerprint): int
    {
        if (! preg_match('/^SHA256:[A-Za-z0-9+\/_-]{20,}$/', $fingerprint)) {
            throw new AppException(401, 'SSH 公钥指纹无效');
        }
        $uid = (int) UserPersonalSshKey::where('fingerprint', $fingerprint)->value('uid');
        if ($uid < 1) {
            throw new AppException(401, 'SSH 公钥未授权');
        }
        return $uid;
    }

    public function issueTicket(
        int $uid,
        int $orgId,
        int $clusterId,
        string $nodeId,
        string $containerId,
        int $projectId = 0
    ): array {
        /** @var OrgMember|null $orgMember */
        $orgMember = OrgMember::where('org_id', $orgId)->where('uid', $uid)->first();
        if ($orgMember === null) {
            throw new AppException(403, '您不在该组织中');
        }
        /** @var Cluster|null $cluster */
        $cluster = Cluster::where('id', $clusterId)->where('org_id', $orgId)
            ->where('orchestrator_type', Cluster::ORCHESTRATOR_DOCKER_SWARM)->first();
        if ($cluster === null) {
            throw new AppException(404, 'Docker Swarm 集群不存在');
        }
        $nodeId = trim($nodeId);
        if (! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,127}$/', $nodeId)) {
            throw new AppException(422, '节点 ID 格式不合法');
        }
        if (! ClusterAgentNode::where('cluster_id', $clusterId)->where('node_id', $nodeId)->exists()) {
            throw new AppException(404, '目标节点不属于当前集群或尚未注册 Agent');
        }
        $cluster = clone $cluster;
        $cluster->endpoint = 'agent://' . $clusterId . '/' . $nodeId;

        if ($projectId > 0) {
            /** @var Project|null $project */
            $project = Project::where('id', $projectId)->where('org_id', $orgId)->first();
            if ($project === null) {
                throw new AppException(404, '项目不存在');
            }
            $isOrgManager = (int) $orgMember->role === OrgMember::ROLE_MANAGER;
            $isGroupDirector = GroupMember::where('org_id', $orgId)
                ->where('group_id', (int) $project->group_id)->where('uid', $uid)
                ->where('role', GroupMember::ROLE_DIRECTOR)->exists();
            $isProjectMember = ProjectMember::where('org_id', $orgId)
                ->where('group_id', (int) $project->group_id)->where('project_id', $projectId)
                ->where('uid', $uid)->exists();
            if (! $isOrgManager && ! $isGroupDirector && ! $isProjectMember) {
                throw new AppException(403, '您没有该项目的终端权限');
            }
            Context::set('org_id', $orgId);
            Context::set('group_id', (int) $project->group_id);
            Context::set('project_id', $projectId);
            $this->scope->assertGrantedCluster($cluster);
            $this->scope->assertContainer($cluster, $containerId);
        } elseif ((int) $orgMember->role !== OrgMember::ROLE_MANAGER) {
            // A project id is mandatory for ordinary members so an SSH command
            // cannot silently escape from project scope into the whole cluster.
            throw new AppException(403, '非组织管理员必须指定项目');
        }

        return $this->terminal->issueRelaySession($uid, $orgId, $cluster, $containerId);
    }
}

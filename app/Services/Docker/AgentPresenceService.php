<?php

namespace App\Services\Docker;

use App\Model\Cluster;
use App\Model\ClusterAgentNode;

class AgentPresenceService
{
    public function __construct(private AgentSessionRegistry $sessions) {}

    /** @return array{clusters: int, offline_nodes: int} */
    public function reconcile(): array
    {
        $clusterCount = 0;
        $offlineCount = 0;
        $clusters = Cluster::where('orchestrator_type', Cluster::ORCHESTRATOR_DOCKER_SWARM)->get();
        foreach ($clusters as $cluster) {
            ++$clusterCount;
            $nodes = ClusterAgentNode::where('cluster_id', (int) $cluster->id)
                ->where('status', 'online')
                ->get();
            foreach ($nodes as $node) {
                if ($this->sessions->hasLease(
                    (int) $cluster->id,
                    (string) $node->node_id,
                    (string) $node->role === 'manager'
                )) {
                    continue;
                }
                $node->status = 'offline';
                $node->updated_at = time();
                $node->save();
                ++$offlineCount;
            }
            $this->refreshClusterStatus($cluster);
        }
        return ['clusters' => $clusterCount, 'offline_nodes' => $offlineCount];
    }

    public function refreshClusterStatus(Cluster $cluster): void
    {
        /** @var ClusterAgentNode|null $manager */
        $manager = ClusterAgentNode::where('cluster_id', (int) $cluster->id)
            ->where('role', 'manager')
            ->where('status', 'online')
            ->orderByDesc('last_seen_at')
            ->first();
        if ($manager === null) {
            $state = self::deriveClusterStatus(false, 0, 0, (string) $cluster->agent_status);
            $cluster->agent_status = $state['agent_status'];
            $cluster->status = $state['status'];
            $cluster->save();
            return;
        }

        $expected = max(1, (int) (($manager->capabilities ?? [])['swarm_nodes'] ?? 1));
        $online = ClusterAgentNode::where('cluster_id', (int) $cluster->id)
            ->where('status', 'online')
            ->count();
        $state = self::deriveClusterStatus(true, $expected, $online, (string) $cluster->agent_status);
        $cluster->agent_status = $state['agent_status'];
        $cluster->status = $state['status'];
        $cluster->save();
    }

    /** @return array{agent_status: string, status: int} */
    public static function deriveClusterStatus(
        bool $managerOnline,
        int $expected,
        int $online,
        string $currentAgentStatus
    ): array {
        if (! $managerOnline) {
            return ['agent_status' => 'offline', 'status' => Cluster::STATUS_OFFLINE];
        }

        $agentStatus = $online >= max(1, $expected)
            ? 'healthy'
            : (in_array($currentAgentStatus, ['healthy', 'degraded'], true) ? 'degraded' : 'initializing');

        // Cluster availability follows the Manager session. Missing Worker
        // Agents reduce per-node coverage, but Manager-scoped Swarm operations
        // remain available and agent_status exposes that degradation.
        return ['agent_status' => $agentStatus, 'status' => Cluster::STATUS_READY];
    }
}

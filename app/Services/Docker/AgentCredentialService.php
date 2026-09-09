<?php

namespace App\Services\Docker;

use App\Exception\AppException;
use App\Model\Cluster;
use App\Model\ClusterAgentCredential;
use App\Model\ClusterAgentNode;
use Hyperf\DbConnection\Db;

class AgentCredentialService
{
    public const BOOTSTRAP_TTL = 900;
    public const MACHINE_GRACE_TTL = 600;

    public function issueBootstrap(int $clusterId): string
    {
        $secret = self::generateSecret();
        $now = time();
        ClusterAgentCredential::where('cluster_id', $clusterId)
            ->where('kind', 'bootstrap')
            ->where('status', 'pending')
            ->update(['status' => 'revoked', 'revoked_at' => $now]);
        $version = (int) ClusterAgentCredential::where('cluster_id', $clusterId)
            ->where('kind', 'bootstrap')
            ->max('version') + 1;
        ClusterAgentCredential::create([
            'cluster_id' => $clusterId,
            'kind' => 'bootstrap',
            'version' => $version,
            'token_hash' => hash('sha256', $secret),
            'status' => 'pending',
            'created_at' => $now,
            'expires_at' => $now + self::BOOTSTRAP_TTL,
            'used_at' => 0,
            'revoked_at' => 0,
        ]);
        return $secret;
    }

    /**
     * @return array{cluster: Cluster, credential_version: int, machine_credential: ?string}
     */
    public function authenticate(string $secret, string $swarmId, string $role): array
    {
        if ($secret === '' || $swarmId === '') {
            throw new AppException(401, 'Agent 登录凭据无效');
        }

        return Db::transaction(function () use ($secret, $swarmId, $role): array {
            /** @var ClusterAgentCredential|null $credential */
            $credential = ClusterAgentCredential::where('token_hash', hash('sha256', $secret))
                ->lockForUpdate()
                ->first();
            if ($credential === null || ! in_array((string) $credential->status, ['pending', 'active', 'grace'], true)) {
                throw new AppException(401, 'Agent 登录凭据无效');
            }
            $now = time();
            if ((int) $credential->expires_at > 0 && (int) $credential->expires_at <= $now) {
                $credential->status = 'revoked';
                $credential->revoked_at = $now;
                $credential->save();
                throw new AppException(401, 'Agent 登录凭据已过期');
            }

            /** @var Cluster|null $cluster */
            $cluster = Cluster::where('id', (int) $credential->cluster_id)->lockForUpdate()->first();
            if ($cluster === null || $cluster->orchestrator_type !== Cluster::ORCHESTRATOR_DOCKER_SWARM) {
                throw new AppException(403, 'Agent 绑定的 Swarm 集群不存在');
            }

            if ($credential->kind === 'bootstrap') {
                if ($role !== 'manager') {
                    throw new AppException(403, '首次注册必须由 Swarm Manager Agent 发起');
                }
                if ((string) $cluster->swarm_id !== '' && ! hash_equals((string) $cluster->swarm_id, $swarmId)) {
                    throw new AppException(403, 'Swarm ID 与集群绑定不匹配');
                }
                $machineSecret = self::generateSecret();
                $machineVersion = $this->nextMachineVersion($cluster);
                ClusterAgentCredential::create([
                    'cluster_id' => (int) $cluster->id,
                    'kind' => 'machine',
                    'version' => $machineVersion,
                    'token_hash' => hash('sha256', $machineSecret),
                    'status' => 'active',
                    'created_at' => $now,
                    'expires_at' => 0,
                    'used_at' => 0,
                    'revoked_at' => 0,
                ]);
                $credential->status = 'revoked';
                $credential->used_at = $now;
                $credential->revoked_at = $now;
                $credential->save();
                $cluster->swarm_id = $swarmId;
                $cluster->registration_status = 'registered';
                $cluster->agent_status = 'initializing';
                $cluster->current_credential_version = $machineVersion;
                $cluster->registered_at = $now;
                $cluster->endpoint = sprintf('agent://%d', $cluster->id);
                $cluster->status = Cluster::STATUS_INITING;
                $cluster->save();
                return [
                    'cluster' => $cluster,
                    'credential_version' => $machineVersion,
                    'machine_credential' => $machineSecret,
                ];
            }

            if (! hash_equals((string) $cluster->swarm_id, $swarmId)) {
                throw new AppException(403, 'Swarm ID 与集群绑定不匹配');
            }
            $credential->used_at = $now;
            $credential->save();
            return [
                'cluster' => $cluster,
                'credential_version' => (int) $credential->version,
                'machine_credential' => null,
            ];
        });
    }

    public function rotateMachineCredential(int $clusterId): string
    {
        return Db::transaction(function () use ($clusterId): string {
            /** @var Cluster|null $cluster */
            $cluster = Cluster::where('id', $clusterId)->lockForUpdate()->first();
            if ($cluster === null || (string) $cluster->swarm_id === '') {
                throw new AppException(422, '集群尚未完成 Agent 注册');
            }
            $now = time();
            ClusterAgentCredential::where('cluster_id', $clusterId)
                ->where('kind', 'machine')
                ->where('status', 'active')
                ->update(['status' => 'grace', 'expires_at' => $now + self::MACHINE_GRACE_TTL]);
            $version = $this->nextMachineVersion($cluster);
            $secret = self::generateSecret();
            ClusterAgentCredential::create([
                'cluster_id' => $clusterId,
                'kind' => 'machine',
                'version' => $version,
                'token_hash' => hash('sha256', $secret),
                'status' => 'active',
                'created_at' => $now,
                'expires_at' => 0,
                'used_at' => 0,
                'revoked_at' => 0,
            ]);
            $cluster->current_credential_version = $version;
            $cluster->save();
            return $secret;
        });
    }

    public function resetRegistration(int $clusterId): string
    {
        return Db::transaction(function () use ($clusterId): string {
            /** @var Cluster|null $cluster */
            $cluster = Cluster::where('id', $clusterId)->lockForUpdate()->first();
            if ($cluster === null || $cluster->orchestrator_type !== Cluster::ORCHESTRATOR_DOCKER_SWARM) {
                throw new AppException(404, 'Docker Swarm 集群不存在');
            }

            $now = time();
            ClusterAgentCredential::where('cluster_id', $clusterId)
                ->whereIn('status', ['pending', 'active', 'grace'])
                ->update(['status' => 'revoked', 'revoked_at' => $now]);
            ClusterAgentNode::where('cluster_id', $clusterId)->delete();

            $cluster->swarm_id = '';
            $cluster->registration_status = 'pending';
            $cluster->agent_status = 'pending';
            $cluster->current_credential_version = 0;
            $cluster->registered_at = 0;
            $cluster->endpoint = 'agent-pending://cluster';
            $cluster->status = Cluster::STATUS_PENDING_CONNECT;
            $cluster->save();

            return $this->issueBootstrap($clusterId);
        });
    }

    private function nextMachineVersion(Cluster $cluster): int
    {
        $historicalVersion = (int) ClusterAgentCredential::where('cluster_id', (int) $cluster->id)
            ->where('kind', 'machine')
            ->max('version');
        return max($historicalVersion, (int) $cluster->current_credential_version) + 1;
    }

    private static function generateSecret(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}

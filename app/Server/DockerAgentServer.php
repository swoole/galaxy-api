<?php

namespace App\Server;

use App\Model\Cluster;
use App\Model\ClusterAgentNode;
use App\Services\Docker\AgentCredentialService;
use App\Services\Docker\AgentPresenceService;
use App\Services\Docker\AgentRelayService;
use App\Services\Docker\AgentSessionRegistry;
use App\Services\RegistryService;
use Hyperf\Contract\OnCloseInterface;
use Hyperf\Contract\OnMessageInterface;
use Hyperf\Contract\OnOpenInterface;
use Hyperf\Logger\LoggerFactory;
use Swoole\WebSocket\Server;
use Throwable;

class DockerAgentServer implements OnOpenInterface, OnMessageInterface, OnCloseInterface
{
    public function __construct(
        private AgentRelayService $relay,
        private AgentSessionRegistry $sessions,
        private AgentPresenceService $presence,
        private AgentCredentialService $credentials,
        private RegistryService $registries,
        private LoggerFactory $loggerFactory
    ) {}

    public function onOpen($server, $request): void
    {
        $fd = (int) $request->fd;
        try {
            $authorization = (string) ($request->header['authorization'] ?? '');
            if (! str_starts_with($authorization, 'Agent ')) {
                throw new \RuntimeException('缺少 Agent 登录凭据');
            }
            $token = substr($authorization, 6);
            $swarmId = $this->requiredIdentifier($request, 'x-galaxy-swarm-id', 'Swarm ID');
            $nodeId = $this->requiredIdentifier($request, 'x-galaxy-node-id', 'Node ID');
            $role = strtolower(trim((string) ($request->header['x-galaxy-node-role'] ?? '')));
            if (! in_array($role, ['manager', 'worker'], true)) {
                throw new \RuntimeException('Agent 节点角色无效');
            }
            $agentVersion = trim((string) ($request->header['x-galaxy-agent-version'] ?? ''));
            if ($agentVersion === '' || strlen($agentVersion) > 64) {
                throw new \RuntimeException('Agent 版本无效');
            }
            $swarmNodes = filter_var(
                $request->header['x-galaxy-swarm-nodes'] ?? null,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 0, 'max_range' => 100000]]
            );
            if ($swarmNodes === false || ($role === 'manager' && $swarmNodes < 1)) {
                throw new \RuntimeException('Agent 上报的 Swarm 节点数量无效');
            }
            $joinAddresses = $this->joinAddresses(
                (string) ($request->header['x-galaxy-join-addresses'] ?? '')
            );
            $nodeAddr = trim((string) ($request->header['x-galaxy-node-addr'] ?? ''));
            if ($role === 'manager' && filter_var($nodeAddr, FILTER_VALIDATE_IP) !== false) {
                $joinAddresses = array_values(array_unique([$nodeAddr, ...$joinAddresses]));
            }
            $agentImage = trim((string) ($request->header['x-galaxy-agent-image'] ?? ''));
            if ($agentImage !== '' && (strlen($agentImage) > 512 || preg_match('/\s/', $agentImage))) {
                throw new \RuntimeException('Agent 镜像地址无效');
            }

            $authenticated = $this->credentials->authenticate($token, $swarmId, $role);
            /** @var Cluster $cluster */
            $cluster = $authenticated['cluster'];
            $credentialVersion = (int) $authenticated['credential_version'];
            $now = time();
            $node = ClusterAgentNode::firstOrNew([
                'cluster_id' => (int) $cluster->id,
                'node_id' => $nodeId,
            ]);
            if (! $node->exists) {
                $node->created_at = $now;
            }
            $capabilities = (array) ($node->capabilities ?? []);
            $capabilities['swarm_nodes'] = (int) $swarmNodes;
            // Only the one-shot installer runs in the host network namespace and
            // reports physical NIC addresses. Preserve them when the containerized
            // Agent reconnects without this header.
            if ($joinAddresses !== []) {
                $capabilities['join_addresses'] = $joinAddresses;
            }
            $node->fill([
                'swarm_id' => $swarmId,
                'node_addr' => $nodeAddr,
                'hostname' => trim((string) ($request->header['x-galaxy-node-hostname'] ?? '')),
                'role' => $role,
                'agent_version' => $agentVersion,
                'credential_version' => $credentialVersion,
                'capabilities' => $capabilities,
                'status' => 'online',
                'last_seen_at' => $now,
                'updated_at' => $now,
            ]);
            $node->save();
            $this->sessions->register(
                (int) $cluster->id,
                $swarmId,
                $nodeId,
                $role,
                $agentVersion,
                $credentialVersion,
                $server,
                $fd
            );
            $this->presence->refreshClusterStatus($cluster);
            $registryAuth = '';
            if ($authenticated['machine_credential'] !== null && $agentImage !== '') {
                $registryHeaders = $this->registries->dockerAuthHeader(
                    (int) $cluster->org_id,
                    $agentImage
                );
                $registryAuth = (string) ($registryHeaders['X-Registry-Auth'] ?? '');
            }
            $server->push($fd, json_encode([
                'type' => 'ready',
                'cluster_id' => (int) $cluster->id,
                'swarm_id' => $swarmId,
                'node_id' => $nodeId,
                'credential_version' => $credentialVersion,
                'machine_credential' => $authenticated['machine_credential'],
                // Returned only during one-shot Bootstrap. The CLI forwards it
                // directly to Docker and never writes it to Agent configuration.
                'registry_auth' => $registryAuth,
            ], JSON_UNESCAPED_UNICODE));
        } catch (Throwable $e) {
            $this->loggerFactory->get('DockerAgent')->warning('Docker Agent connection rejected', ['fd' => $fd, 'error' => $e->getMessage()]);
            $server->push($fd, json_encode(['type' => 'error', 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE));
            $server->disconnect($fd, 1008, 'agent rejected');
        }
    }

    private function joinAddresses(string $header): array
    {
        if ($header === '') {
            return [];
        }
        if (strlen($header) > 2048) {
            throw new \RuntimeException('Agent 上报的 Join 地址过长');
        }
        $addresses = [];
        foreach (explode(',', $header) as $address) {
            $address = trim($address);
            if ($address === '' || filter_var($address, FILTER_VALIDATE_IP) === false) {
                throw new \RuntimeException('Agent 上报的 Join 地址无效');
            }
            $addresses[$address] = true;
            if (count($addresses) >= 32) {
                break;
            }
        }
        return array_keys($addresses);
    }

    public function onMessage($server, $frame): void
    {
        try {
            $message = json_decode((string) $frame->data, true, 512, JSON_THROW_ON_ERROR);
            $type = (string) ($message['type'] ?? '');
            $session = $this->sessions->heartbeat((int) $frame->fd);
            if ($session === null) {
                $server->disconnect((int) $frame->fd, 1008, 'agent session expired');
                return;
            }
            if ($type === 'heartbeat') {
                $now = time();
                ClusterAgentNode::where('cluster_id', $session['cluster_id'])
                    ->where('node_id', $session['node_id'])
                    ->update(['status' => 'online', 'last_seen_at' => $now, 'updated_at' => $now]);
                $server->push((int) $frame->fd, json_encode([
                    'type' => 'heartbeat_ack',
                    'server_time' => $now,
                ], JSON_UNESCAPED_SLASHES));
                return;
            }
            if (in_array($type, [
                'response',
                'domain_response',
                'stream_ready',
                'stream_data',
                'stream_close',
            ], true)) {
                $this->relay->resolve($message, (int) $frame->fd);
            }
        } catch (Throwable $e) {
            $this->loggerFactory->get('DockerAgent')->notice('Invalid Docker Agent frame', ['fd' => $frame->fd, 'error' => $e->getMessage()]);
        }
    }

    public function onClose($server, int $fd, int $reactorId): void
    {
        $removed = $this->relay->unregister($fd);
        foreach ($removed as $session) {
            ClusterAgentNode::where('cluster_id', $session['cluster_id'])
                ->where('node_id', $session['node_id'])
                ->update(['status' => 'offline', 'updated_at' => time()]);
            $cluster = Cluster::find($session['cluster_id']);
            if ($cluster !== null) {
                $this->presence->refreshClusterStatus($cluster);
            }
        }
    }

    private function requiredIdentifier(object $request, string $header, string $label): string
    {
        $value = trim((string) ($request->header[$header] ?? ''));
        if ($value === '' || ! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,127}$/', $value)) {
            throw new \RuntimeException($label . ' 无效');
        }
        return $value;
    }
}

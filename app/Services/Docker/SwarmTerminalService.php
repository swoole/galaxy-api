<?php

namespace App\Services\Docker;

use App\Exception\AppException;
use App\Model\Cluster;
use Hyperf\Redis\Redis;

class SwarmTerminalService
{
    private const TICKET_PREFIX = 'galaxy:swarm:terminal:';
    private const TICKET_TTL = 30;
    private const SHELL_COMMAND = [
        '/bin/sh', '-c',
        'if [ -x /bin/bash ]; then exec /bin/bash; elif [ -x /bin/ash ]; then exec /bin/ash; else exec /bin/sh; fi',
    ];

    public function __construct(
        private SwarmApiClient $docker,
        private AgentRelayService $agentRelay,
        private Redis $redis
    ) {}

    public function issueTicket(
        int $uid,
        int $orgId,
        Cluster $cluster,
        string $containerId,
        int $projectId = 0
    ): array
    {
        $this->requiredAgentNodeId($cluster);
        $container = $this->runningContainer($cluster, $containerId);
        $result = $this->issueResolvedTicket($uid, $orgId, $cluster, $container);
        $ssh = $this->buildSshConnection($orgId, $cluster, (string) ($container['Id'] ?? $containerId), $projectId);
        if ($ssh !== null) {
            $result['ssh'] = $ssh;
        }
        return $result;
    }

    /** Return a display-ready native SSH command without allocating a WebSocket ticket. */
    public function sshConnection(int $orgId, Cluster $cluster, string $containerId, int $projectId = 0): array
    {
        $this->validateContainerId($containerId);
        $connection = $this->buildSshConnection($orgId, $cluster, $containerId, $projectId);
        if ($connection === null) {
            throw new AppException(503, '原生 SSH 终端尚未配置');
        }
        return $connection;
    }

    private function buildSshConnection(int $orgId, Cluster $cluster, string $containerId, int $projectId): ?array
    {
        $relay = (array) config('ssh_relay', []);
        $host = trim((string) ($relay['public_host'] ?? ''));
        if (! ($relay['enabled'] ?? false) || $host === '') {
            return null;
        }
        $nodeId = $this->requiredAgentNodeId($cluster);
        $arguments = [
            'exec', '--org', (string) $orgId, '--cluster', (string) $cluster->id,
            '--node', $nodeId,
            '--container', $containerId,
        ];
        if ($projectId > 0) {
            array_push($arguments, '--project', (string) $projectId);
        }
        $port = max(1, min(65535, (int) ($relay['public_port'] ?? 9522)));
        $user = trim((string) ($relay['user'] ?? 'galaxy')) ?: 'galaxy';
        $target = $user . '@' . $host;
        return [
            'host' => $host,
            'port' => $port,
            'user' => $user,
            'command' => sprintf(
                'ssh -t -p %d %s %s',
                $port,
                $this->shellArgument($target),
                implode(' ', array_map($this->shellArgument(...), $arguments))
            ),
        ];
    }

    /** Keep display-ready commands concise while still quoting unsafe shell arguments. */
    private function shellArgument(string $value): string
    {
        return preg_match('/\A[A-Za-z0-9_@%+=:,\.\/-]+\z/', $value) === 1
            ? $value
            : escapeshellarg($value);
    }

    /** Build the SSH relay data-plane descriptor after authorization. */
    public function issueRelaySession(int $uid, int $orgId, Cluster $cluster, string $containerId): array
    {
        $this->validateContainerId($containerId);
        $this->requiredAgentNodeId($cluster);
        $container = $this->runningContainer($cluster, $containerId);
        return [
            'transport' => 'websocket',
            'websocket' => $this->issueResolvedTicket($uid, $orgId, $cluster, $container),
        ];
    }

    private function runningContainer(Cluster $cluster, string $containerId): array
    {
        $this->validateContainerId($containerId);
        $container = $this->docker->withCluster($cluster, fn ($client, SwarmApiClient $api): array =>
            $api->request($client, 'GET', '/containers/' . rawurlencode($containerId) . '/json')
        );
        if (($container['State']['Running'] ?? false) !== true) {
            throw new AppException(422, '只有运行中的容器可以进入终端');
        }
        return $container;
    }

    private function validateContainerId(string $containerId): void
    {
        if (! preg_match('/^[a-f0-9]{12,64}$/i', $containerId)) {
            throw new AppException(422, '容器 ID 格式不合法');
        }
    }

    private function issueResolvedTicket(int $uid, int $orgId, Cluster $cluster, array $container): array
    {
        $nodeId = $this->requiredAgentNodeId($cluster);
        $ticket = bin2hex(random_bytes(32));
        $payload = json_encode([
            'uid' => $uid,
            'org_id' => $orgId,
            'cluster_id' => (int) $cluster['id'],
            'container_id' => (string) $container['Id'],
            'container_name' => ltrim((string) ($container['Name'] ?? ''), '/'),
            'node_id' => $nodeId,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->redis->setex(self::TICKET_PREFIX . $ticket, self::TICKET_TTL, $payload);
        return ['ticket' => $ticket, 'expires_in' => self::TICKET_TTL, 'websocket_path' => 'cluster/swarm/terminal'];
    }

    public function consumeTicket(string $ticket): array
    {
        if (! preg_match('/^[a-f0-9]{64}$/', $ticket)) {
            throw new AppException(401, 'Web 终端票据无效');
        }
        $payload = $this->redis->eval(
            "local value = redis.call('GET', KEYS[1]); if value then redis.call('DEL', KEYS[1]); end; return value",
            [self::TICKET_PREFIX . $ticket],
            1
        );
        if (! is_string($payload) || $payload === '') {
            throw new AppException(401, 'Web 终端票据已过期或已经使用');
        }
        return json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
    }

    public function open(array $ticket, int $columns = 120, int $rows = 40): array
    {
        /** @var Cluster|null $cluster */
        $cluster = Cluster::where('id', (int) $ticket['cluster_id'])
            ->where('org_id', (int) $ticket['org_id'])->first();
        if ($cluster === null || $cluster['orchestrator_type'] !== Cluster::ORCHESTRATOR_DOCKER_SWARM) {
            throw new AppException(404, 'Docker Swarm 集群不存在');
        }
        $nodeId = trim((string) ($ticket['node_id'] ?? ''));
        if ($nodeId === '') {
            throw new AppException(422, '终端票据缺少目标节点');
        }
        $cluster = clone $cluster;
        $cluster->endpoint = 'agent://' . (int) $cluster->id . '/' . $nodeId;
        $columns = max(20, min(500, $columns));
        $rows = max(5, min(300, $rows));
        $exitMarkerName = 'GALAXY_TERMINAL_EXIT_' . bin2hex(random_bytes(16));
        $exitMarker = "\x1e{$exitMarkerName}\x1f";
        $command = $this->commandWithExitMarker($exitMarkerName);
        $exec = $this->docker->withCluster(
            $cluster,
            function ($client, SwarmApiClient $api) use ($ticket, $command, $rows, $columns): array {
                return $api->request($client, 'POST', '/containers/' . rawurlencode((string) $ticket['container_id']) . '/exec', [
                    'json' => [
                        'AttachStdin' => true, 'AttachStdout' => true, 'AttachStderr' => true,
                        'Tty' => true,
                        'ConsoleSize' => [$rows, $columns],
                        'Cmd' => $command,
                    ],
                ]);
            }
        );
        $execId = (string) ($exec['Id'] ?? '');
        if ($execId === '') {
            throw new AppException(502, 'Docker API 未返回 Exec ID');
        }
        [$client, $files, $initialOutput] = $this->attach($cluster, $execId);
        return compact('cluster', 'execId', 'client', 'files', 'initialOutput', 'exitMarker');
    }

    private function commandWithExitMarker(string $exitMarkerName): array
    {
        return [
            '/bin/sh', '-c',
            "\"\$@\"; status=\$?; printf '\\036%s\\037' " . escapeshellarg($exitMarkerName) . "; exit \"\$status\"",
            'galaxy-terminal',
            ...self::SHELL_COMMAND,
        ];
    }

    public function resize(Cluster $cluster, string $execId, int $columns, int $rows): void
    {
        $columns = max(20, min(500, $columns));
        $rows = max(5, min(300, $rows));
        $this->docker->withCluster($cluster, function ($client, SwarmApiClient $api) use ($execId, $columns, $rows): void {
            $api->request($client, 'POST', '/exec/' . rawurlencode($execId) . '/resize', [
                'query' => ['w' => $columns, 'h' => $rows],
            ]);
        });
    }

    public function close(array $session): void
    {
        if (is_object($session['client'] ?? null) && method_exists($session['client'], 'close')) {
            $session['client']->close();
        }
        foreach ($session['files'] ?? [] as $file) {
            @unlink($file);
        }
    }

    private function attach(Cluster $cluster, string $execId): array
    {
        $this->requiredAgentNodeId($cluster);
        $body = json_encode(['Detach' => false, 'Tty' => true], JSON_THROW_ON_ERROR);
        $client = $this->agentRelay->openStream(
            (string) $cluster['endpoint'],
            'POST',
            '/exec/' . rawurlencode($execId) . '/start',
            ['Connection' => ['Upgrade'], 'Upgrade' => ['tcp'], 'Content-Type' => ['application/json']],
            $body
        );
        return [$client, [], ''];
    }

    private function requiredAgentNodeId(Cluster $cluster): string
    {
        $endpoint = $this->agentRelay->parseEndpoint((string) $cluster['endpoint']);
        if ($endpoint === null) {
            throw new AppException(503, '该 Swarm 集群尚未通过 Galaxy Agent 注册');
        }
        $nodeId = trim((string) ($endpoint[1] ?? ''));
        if ($nodeId === '') {
            throw new AppException(422, '打开容器终端必须指定容器所在节点');
        }
        return $nodeId;
    }
}

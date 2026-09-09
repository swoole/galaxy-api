<?php

namespace App\Services\Docker;

use App\Exception\AppException;
use Hyperf\Coroutine\Coroutine;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Redis\Redis;
use Hyperf\Server\ServerFactory;
use Swoole\Server;
use Throwable;

/**
 * Redis-backed Agent session directory.
 *
 * Redis contains routing metadata and a short online lease only. The actual
 * WebSocket server/fd pair always stays in the worker which owns the socket.
 */
class AgentSessionRegistry
{
    private const KEY_PREFIX = 'galaxy:docker-agent:session:';
    private const LEASE_SECONDS = 45;
    private const HEARTBEAT_SECONDS = 15;
    private const HEARTBEAT_TIMEOUT_SECONDS = 60;

    /**
     * @var array<int, array<string, array{
     *     server: object,
     *     fd: int,
     *     swarm_id: string,
     *     node_id: string,
     *     role: string,
     *     agent_version: string,
     *     credential_version: int,
     *     connection_id: string,
     *     instance_id: string,
     *     worker_id: int,
     *     last_seen_at: int,
     *     redis_payload?: string
     * }>>
     */
    private array $sessions = [];

    /** Stable API master identity shared by workers and custom processes. */
    private ?string $resolvedInstanceId = null;

    #[Inject]
    private ?Redis $injectedRedis = null;

    #[Inject]
    private ?ServerFactory $serverFactory = null;

    public function __construct(
        private ?Redis $redis = null,
        private ?Server $swooleServer = null,
        private bool $useSharedStore = true,
        private ?string $pidFile = null
    ) {}

    public function register(
        int $clusterId,
        string $swarmId,
        string $nodeId,
        string $role,
        string $agentVersion,
        int $credentialVersion,
        object $server,
        int $fd
    ): void {
        $previous = $this->route($clusterId, $nodeId);
        if ($previous !== null && $this->routeIsActive($previous)) {
            throw new \RuntimeException('节点已有活动 Agent 连接，等待原连接关闭后重试');
        }
        $this->discardLocalSession($clusterId, $nodeId, 'stale agent connection replaced');

        $workerId = method_exists($server, 'getWorkerId') ? $server->getWorkerId() : 0;
        $connectionId = bin2hex(random_bytes(16));
        $session = [
            'cluster_id' => $clusterId,
            'server' => $server,
            'fd' => $fd,
            'swarm_id' => $swarmId,
            'node_id' => $nodeId,
            'role' => $role,
            'agent_version' => $agentVersion,
            'credential_version' => $credentialVersion,
            'connection_id' => $connectionId,
            'instance_id' => $this->instanceId($server),
            'worker_id' => is_int($workerId) ? $workerId : 0,
            'last_seen_at' => time(),
        ];
        $this->sessions[$clusterId][$nodeId] = $session;
        $this->refreshLease($clusterId, $nodeId);
        $this->startHeartbeat($clusterId, $nodeId, $connectionId);
    }

    /** @return null|array{cluster_id: int, node_id: string, role: string} */
    public function heartbeat(int $fd): ?array
    {
        foreach ($this->sessions as $clusterId => $nodes) {
            foreach ($nodes as $nodeId => $session) {
                if ($session['fd'] !== $fd || ! $session['server']->isEstablished($fd)) {
                    continue;
                }
                $this->sessions[$clusterId][$nodeId]['last_seen_at'] = time();
                try {
                    $this->refreshLease((int) $clusterId, $nodeId);
                } catch (Throwable) {
                    // Keep the live socket usable during a transient Redis
                    // failure; the watchdog will retry the lease refresh.
                }
                return ['cluster_id' => (int) $clusterId, 'node_id' => $nodeId, 'role' => $session['role']];
            }
        }
        return null;
    }

    /** @return array<int, array{cluster_id: int, node_id: string, role: string}> */
    public function unregister(int $fd): array
    {
        $removed = [];
        foreach ($this->sessions as $clusterId => $nodes) {
            foreach ($nodes as $nodeId => $session) {
                if ($session['fd'] !== $fd) {
                    continue;
                }
                unset($this->sessions[$clusterId][$nodeId]);
                $this->removeLease((int) $clusterId, $nodeId, $session);
                $removed[] = ['cluster_id' => (int) $clusterId, 'node_id' => $nodeId, 'role' => $session['role']];
            }
            if ($this->sessions[$clusterId] === []) {
                unset($this->sessions[$clusterId]);
            }
        }
        return $removed;
    }

    public function node(int $clusterId, string $nodeId): array
    {
        $route = $this->route($clusterId, $nodeId);
        if ($route === null) {
            throw new AppException(503, '目标节点 Agent 当前不在线');
        }
        return $this->localize($clusterId, $route);
    }

    public function manager(int $clusterId): array
    {
        if ($redis = $this->redis()) {
            foreach ((array) $redis->sMembers($this->managerSetKey($clusterId)) as $nodeId) {
                $route = $this->route($clusterId, (string) $nodeId);
                if ($route !== null && ($route['role'] ?? '') === 'manager') {
                    return $this->localize($clusterId, $route);
                }
                $redis->sRem($this->managerSetKey($clusterId), (string) $nodeId);
            }
            throw new AppException(503, 'Swarm Manager Agent 当前不在线');
        }

        foreach ($this->sessions[$clusterId] ?? [] as $session) {
            if ($session['role'] === 'manager' && $this->sessionIsActive($session)) {
                return $session;
            }
        }
        throw new AppException(503, 'Swarm Manager Agent 当前不在线');
    }

    /** @return array<int, array<string, mixed>> */
    public function clusterRoutes(int $clusterId): array
    {
        if (! $redis = $this->redis()) {
            return array_values($this->sessions[$clusterId] ?? []);
        }
        $routes = [];
        foreach ((array) $redis->sMembers($this->nodeSetKey($clusterId)) as $nodeId) {
            $route = $this->route($clusterId, (string) $nodeId);
            if ($route !== null) {
                $routes[] = $this->localize($clusterId, $route);
            } else {
                $redis->sRem($this->nodeSetKey($clusterId), (string) $nodeId);
                $redis->sRem($this->managerSetKey($clusterId), (string) $nodeId);
            }
        }
        return $routes;
    }

    public function localConnection(int $clusterId, string $nodeId, string $connectionId): ?array
    {
        $session = $this->sessions[$clusterId][$nodeId] ?? null;
        if (
            $session === null
            || $session['connection_id'] !== $connectionId
            || ! $this->sessionIsActive($session)
        ) {
            return null;
        }
        return $session;
    }

    public function isOnline(int $clusterId, ?string $nodeId = null): bool
    {
        try {
            $nodeId === null ? $this->manager($clusterId) : $this->node($clusterId, $nodeId);
            return true;
        } catch (AppException) {
            return false;
        }
    }

    public function hasLease(int $clusterId, string $nodeId, bool $manager = false): bool
    {
        if ($this->route($clusterId, $nodeId) !== null) {
            return true;
        }
        if ($redis = $this->redis()) {
            $redis->sRem($this->nodeSetKey($clusterId), $nodeId);
            if ($manager) {
                $redis->sRem($this->managerSetKey($clusterId), $nodeId);
            }
        }
        return false;
    }

    /** Disconnect sessions owned by the current process. Cross-worker routing is handled by AgentRelayService. */
    public function disconnectCluster(int $clusterId, string $reason): void
    {
        foreach ($this->sessions[$clusterId] ?? [] as $session) {
            if ($session['server']->isEstablished($session['fd'])) {
                $session['server']->disconnect($session['fd'], 1000, $reason);
            }
        }
    }

    public function owns(int $clusterId, string $nodeId, int $fd): bool
    {
        $session = $this->sessions[$clusterId][$nodeId] ?? null;
        return $session !== null && $session['fd'] === $fd;
    }

    public function instanceId(?object $server = null): string
    {
        if ($this->resolvedInstanceId !== null) {
            return $this->resolvedInstanceId;
        }
        $server ??= $this->server();
        $masterPid = is_object($server) ? (int) ($server->master_pid ?? 0) : 0;
        if ($masterPid < 1) {
            $masterPid = $this->masterPidFromFile();
        }
        if ($masterPid < 1) {
            // This path is intended for isolated tests and non-server CLI
            // usage only. A process parent is the Swoole Manager for custom
            // processes, not the API Master, so it must never identify a
            // running multi-process API instance.
            $masterPid = getmypid();
        }
        return $this->resolvedInstanceId = (gethostname() ?: 'galaxy-api') . ':' . $masterPid;
    }

    private function masterPidFromFile(): int
    {
        $path = $this->pidFile;
        if ($path === null && defined('BASE_PATH')) {
            $path = BASE_PATH . '/runtime/hyperf.pid';
        }
        if (! is_string($path) || $path === '' || ! is_readable($path)) {
            return 0;
        }
        try {
            $value = trim((string) file_get_contents($path));
        } catch (Throwable) {
            return 0;
        }
        return preg_match('/^[1-9][0-9]*$/', $value) === 1 ? (int) $value : 0;
    }

    private function route(int $clusterId, string $nodeId): ?array
    {
        if (! $redis = $this->redis()) {
            $session = $this->sessions[$clusterId][$nodeId] ?? null;
            return $session !== null && $this->sessionIsActive($session) ? $session : null;
        }
        $payload = $redis->get($this->nodeKey($clusterId, $nodeId));
        if (! is_string($payload) || $payload === '') {
            return null;
        }
        try {
            $route = json_decode($payload, true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }
        if (
            ! is_array($route)
            || ($route['node_id'] ?? '') !== $nodeId
            || (int) ($route['last_seen_at'] ?? 0) < time() - self::HEARTBEAT_TIMEOUT_SECONDS
        ) {
            return null;
        }
        return $route;
    }

    private function localize(int $clusterId, array $route): array
    {
        $local = $this->sessions[$clusterId][(string) ($route['node_id'] ?? '')] ?? null;
        if (
            $local !== null
            && $local['connection_id'] === ($route['connection_id'] ?? '')
            && $this->sessionIsActive($local)
        ) {
            return $local;
        }
        return $route;
    }

    private function routeIsActive(array $route): bool
    {
        $local = $this->sessions[(int) ($route['cluster_id'] ?? 0)][(string) ($route['node_id'] ?? '')] ?? null;
        if ($local !== null && $local['connection_id'] === ($route['connection_id'] ?? '')) {
            return $this->sessionIsActive($local);
        }
        // A Redis lease owned by a previous API master cannot be routed by the
        // current Swoole instance. Rejecting the reconnect here would keep the
        // cluster offline until TTL expiry (or indefinitely while stale
        // workers refresh it).
        if ((string) ($route['instance_id'] ?? '') !== $this->instanceId()) {
            return false;
        }
        if ((int) ($route['last_seen_at'] ?? 0) < time() - self::HEARTBEAT_TIMEOUT_SECONDS) {
            return false;
        }
        return $this->redis() !== null;
    }

    private function refreshLease(int $clusterId, string $nodeId): void
    {
        $redis = $this->redis();
        if ($redis === null || ! isset($this->sessions[$clusterId][$nodeId])) {
            return;
        }
        $session = $this->sessions[$clusterId][$nodeId];
        $route = [
            'cluster_id' => $clusterId,
            'swarm_id' => $session['swarm_id'],
            'node_id' => $nodeId,
            'role' => $session['role'],
            'agent_version' => $session['agent_version'],
            'credential_version' => $session['credential_version'],
            'connection_id' => $session['connection_id'],
            'instance_id' => $session['instance_id'],
            'worker_id' => $session['worker_id'],
            'last_seen_at' => $this->sessionLastSeenAt($session),
            'updated_at' => time(),
        ];
        $payload = json_encode($route, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $this->sessions[$clusterId][$nodeId]['redis_payload'] = $payload;
        $redis->setex($this->nodeKey($clusterId, $nodeId), self::LEASE_SECONDS, $payload);
        $redis->sAdd($this->nodeSetKey($clusterId), $nodeId);
        if ($session['role'] === 'manager') {
            $redis->sAdd($this->managerSetKey($clusterId), $nodeId);
        }
    }

    private function startHeartbeat(int $clusterId, string $nodeId, string $connectionId): void
    {
        if (Coroutine::id() < 0) {
            return;
        }
        Coroutine::create(function () use ($clusterId, $nodeId, $connectionId): void {
            while (true) {
                Coroutine::sleep(self::HEARTBEAT_SECONDS);
                $session = $this->sessions[$clusterId][$nodeId] ?? null;
                if (
                    $session === null
                    || $session['connection_id'] !== $connectionId
                    || ! $session['server']->isEstablished($session['fd'])
                ) {
                    return;
                }
                if (! $this->sessionIsActive($session)) {
                    $this->discardLocalSession($clusterId, $nodeId, 'agent heartbeat timeout');
                    return;
                }
                try {
                    $this->refreshLease($clusterId, $nodeId);
                } catch (Throwable) {
                    // A transient Redis failure must not tear down a healthy
                    // WebSocket; the next heartbeat can restore the lease.
                }
            }
        });
    }

    private function sessionIsActive(array $session): bool
    {
        return $session['server']->isEstablished($session['fd'])
            && $this->sessionLastSeenAt($session) >= time() - self::HEARTBEAT_TIMEOUT_SECONDS;
    }

    private function sessionLastSeenAt(array $session): int
    {
        $lastSeenAt = (int) ($session['last_seen_at'] ?? 0);
        if (method_exists($session['server'], 'getClientInfo')) {
            try {
                $info = $session['server']->getClientInfo($session['fd']);
                if (is_array($info)) {
                    $lastSeenAt = max($lastSeenAt, (int) ($info['last_time'] ?? 0));
                }
            } catch (Throwable) {
                // Application heartbeats remain authoritative when socket
                // metadata is temporarily unavailable.
            }
        }
        return $lastSeenAt;
    }

    private function discardLocalSession(int $clusterId, string $nodeId, string $reason): void
    {
        $session = $this->sessions[$clusterId][$nodeId] ?? null;
        if ($session === null) {
            return;
        }
        unset($this->sessions[$clusterId][$nodeId]);
        if ($this->sessions[$clusterId] === []) {
            unset($this->sessions[$clusterId]);
        }
        $this->removeLease($clusterId, $nodeId, $session);
        if ($session['server']->isEstablished($session['fd'])) {
            $session['server']->disconnect($session['fd'], 1001, $reason);
        }
    }

    private function removeLease(int $clusterId, string $nodeId, array $session): void
    {
        $redis = $this->redis();
        if ($redis === null) {
            return;
        }
        $key = $this->nodeKey($clusterId, $nodeId);
        $payload = (string) ($session['redis_payload'] ?? '');
        if ($payload !== '') {
            $redis->eval(
                "if redis.call('GET', KEYS[1]) == ARGV[1] then return redis.call('DEL', KEYS[1]) end return 0",
                [$key, $payload],
                1
            );
        }
        $redis->sRem($this->nodeSetKey($clusterId), $nodeId);
        if ($session['role'] === 'manager') {
            $redis->sRem($this->managerSetKey($clusterId), $nodeId);
        }
    }

    private function redis(): ?Redis
    {
        if (! $this->useSharedStore) {
            return null;
        }
        return $this->redis ?? $this->injectedRedis;
    }

    private function server(): ?Server
    {
        if ($this->swooleServer !== null) {
            return $this->swooleServer;
        }
        try {
            $server = $this->serverFactory?->getServer()->getServer();
            return $server instanceof Server ? $server : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function nodeKey(int $clusterId, string $nodeId): string
    {
        return self::KEY_PREFIX . $clusterId . ':node:' . hash('sha256', $nodeId);
    }

    private function nodeSetKey(int $clusterId): string
    {
        return self::KEY_PREFIX . $clusterId . ':nodes';
    }

    private function managerSetKey(int $clusterId): string
    {
        return self::KEY_PREFIX . $clusterId . ':managers';
    }
}

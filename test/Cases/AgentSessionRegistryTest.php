<?php

declare(strict_types=1);
/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */

namespace HyperfTest\Cases;

use App\Exception\AppException;
use App\Services\Docker\AgentSessionRegistry;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
class AgentSessionRegistryTest extends TestCase
{
    public function testRoutesManagerAndWorkerByNodeId(): void
    {
        $server = new FakeAgentServer();
        $registry = new AgentSessionRegistry(null, null, false);
        $registry->register(7, 'swarm-a', 'manager-1', 'manager', '1.0.0', 1, $server, 10);
        $registry->register(7, 'swarm-a', 'worker-1', 'worker', '1.0.0', 1, $server, 11);

        self::assertSame(10, $registry->manager(7)['fd']);
        self::assertSame(11, $registry->node(7, 'worker-1')['fd']);
        self::assertTrue($registry->owns(7, 'worker-1', 11));
        self::assertFalse($registry->owns(7, 'worker-1', 10));
    }

    public function testDuplicateNodeSessionWaitsForActiveSessionToClose(): void
    {
        $server = new FakeAgentServer();
        $registry = new AgentSessionRegistry(null, null, false);
        $registry->register(7, 'swarm-a', 'manager-1', 'manager', '1.0.0', 1, $server, 10);
        $registry->register(7, 'swarm-a', 'worker-1', 'worker', '1.0.0', 1, $server, 11);

        try {
            $registry->register(7, 'swarm-a', 'worker-1', 'worker', '1.0.1', 1, $server, 12);
            self::fail('重复节点连接应被拒绝');
        } catch (\RuntimeException $exception) {
            self::assertSame('节点已有活动 Agent 连接，等待原连接关闭后重试', $exception->getMessage());
        }

        self::assertSame([], $server->disconnects);
        self::assertSame(10, $registry->manager(7)['fd']);
        self::assertSame(11, $registry->node(7, 'worker-1')['fd']);

        $registry->unregister(11);
        $registry->register(7, 'swarm-a', 'worker-1', 'worker', '1.0.1', 1, $server, 12);
        self::assertSame(12, $registry->node(7, 'worker-1')['fd']);
    }

    public function testOfflineNodeNeverFallsBackToManager(): void
    {
        $server = new FakeAgentServer();
        $registry = new AgentSessionRegistry(null, null, false);
        $registry->register(7, 'swarm-a', 'manager-1', 'manager', '1.0.0', 1, $server, 10);

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('目标节点 Agent 当前不在线');
        $registry->node(7, 'worker-1');
    }

    public function testStaleNodeSessionIsDisconnectedAndCanBeReplaced(): void
    {
        $server = new FakeAgentServer();
        $registry = new AgentSessionRegistry(null, null, false);
        $registry->register(7, 'swarm-a', 'worker-1', 'worker', '1.0.0', 1, $server, 11);
        $this->ageSession($registry, 7, 'worker-1', 61);
        $server->lastTimes[11] = time() - 61;

        $registry->register(7, 'swarm-a', 'worker-1', 'worker', '1.0.1', 1, $server, 12);

        self::assertSame([[11, 1001, 'stale agent connection replaced']], $server->disconnects);
        self::assertSame(12, $registry->node(7, 'worker-1')['fd']);

        // The delayed close callback of the replaced socket must not remove
        // the new connection that already owns the same node identity.
        self::assertSame([], $registry->unregister(11));
        self::assertSame(12, $registry->node(7, 'worker-1')['fd']);
    }

    public function testApplicationHeartbeatRefreshesSessionActivity(): void
    {
        $server = new FakeAgentServer();
        $registry = new AgentSessionRegistry(null, null, false);
        $registry->register(7, 'swarm-a', 'worker-1', 'worker', '1.0.0', 1, $server, 11);
        $this->ageSession($registry, 7, 'worker-1', 61);
        $server->lastTimes[11] = time() - 61;

        self::assertSame(
            ['cluster_id' => 7, 'node_id' => 'worker-1', 'role' => 'worker'],
            $registry->heartbeat(11)
        );
        self::assertSame(11, $registry->node(7, 'worker-1')['fd']);
    }

    public function testWebSocketPingActivityKeepsOlderAgentSessionAlive(): void
    {
        $server = new FakeAgentServer();
        $registry = new AgentSessionRegistry(null, null, false);
        $registry->register(7, 'swarm-a', 'worker-1', 'worker', '1.0.0', 1, $server, 11);
        $this->ageSession($registry, 7, 'worker-1', 61);
        $server->lastTimes[11] = time();

        self::assertSame(11, $registry->node(7, 'worker-1')['fd']);
    }

    public function testInstanceIdentityUsesMasterPidFileOutsideServerWorker(): void
    {
        $pidFile = tempnam(sys_get_temp_dir(), 'galaxy-api-pid-');
        self::assertIsString($pidFile);
        try {
            file_put_contents($pidFile, "4242\n");
            $registry = new AgentSessionRegistry(null, null, false, $pidFile);

            $expected = (gethostname() ?: 'galaxy-api') . ':4242';
            self::assertSame($expected, $registry->instanceId());

            // An old worker must retain its original master identity if a new
            // API master later replaces the shared pid file.
            file_put_contents($pidFile, "5252\n");
            self::assertSame($expected, $registry->instanceId());
        } finally {
            if (is_string($pidFile)) {
                unlink($pidFile);
            }
        }
    }

    public function testServerMasterPidTakesPrecedenceOverPidFile(): void
    {
        $pidFile = tempnam(sys_get_temp_dir(), 'galaxy-api-pid-');
        self::assertIsString($pidFile);
        try {
            file_put_contents($pidFile, "4242\n");
            $server = new FakeAgentServer();
            $server->master_pid = 6262;
            $registry = new AgentSessionRegistry(null, null, false, $pidFile);

            self::assertSame(
                (gethostname() ?: 'galaxy-api') . ':6262',
                $registry->instanceId($server)
            );
        } finally {
            if (is_string($pidFile)) {
                unlink($pidFile);
            }
        }
    }

    private function ageSession(AgentSessionRegistry $registry, int $clusterId, string $nodeId, int $seconds): void
    {
        $reflection = new \ReflectionClass($registry);
        $property = $reflection->getProperty('sessions');
        $sessions = $property->getValue($registry);
        $sessions[$clusterId][$nodeId]['last_seen_at'] = time() - $seconds;
        $property->setValue($registry, $sessions);
    }
}

final class FakeAgentServer
{
    public int $master_pid = 0;

    /** @var array<int, array{int, int, string}> */
    public array $disconnects = [];

    /** @var array<int, bool> */
    public array $established = [];

    /** @var array<int, int> */
    public array $lastTimes = [];

    public function isEstablished(int $fd): bool
    {
        return $this->established[$fd] ?? true;
    }

    public function disconnect(int $fd, int $code, string $reason): void
    {
        $this->disconnects[] = [$fd, $code, $reason];
        $this->established[$fd] = false;
    }

    public function getClientInfo(int $fd): array
    {
        return isset($this->lastTimes[$fd]) ? ['last_time' => $this->lastTimes[$fd]] : [];
    }
}

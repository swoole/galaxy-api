<?php

declare(strict_types=1);

namespace HyperfTest\Cases;

use App\Exception\AppException;
use App\Services\Docker\AgentRelayService;
use App\Services\Docker\AgentSessionRegistry;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine\Channel;

/**
 * @internal
 * @coversNothing
 */
class AgentRelayServiceTest extends TestCase
{
    public function testParsesManagerAndNodeEndpoints(): void
    {
        $relay = new AgentRelayService(new AgentSessionRegistry());

        self::assertSame([7, null], $relay->parseEndpoint('agent://7'));
        self::assertSame([7, 'worker-1'], $relay->parseEndpoint('agent://7/worker-1'));
    }

    public function testRejectsInvalidNodeEndpoint(): void
    {
        $relay = new AgentRelayService(new AgentSessionRegistry());

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('Docker Agent 节点地址无效');
        $relay->parseEndpoint('agent://7/worker%2Fother');
    }

    public function testUnregisterWakesPendingRequestsForClosedSocket(): void
    {
        $server = new class {
            public function isEstablished(int $fd): bool
            {
                return true;
            }
        };
        $sessions = new AgentSessionRegistry(null, null, false);
        $sessions->register(7, 'swarm-a', 'worker-1', 'worker', '1.0.0', 1, $server, 11);
        $relay = new AgentRelayService($sessions);
        $closed = new Channel(1);
        $other = new Channel(1);
        $property = (new \ReflectionClass($relay))->getProperty('pending');
        $property->setValue($relay, [
            'closed' => ['channel' => $closed, 'cluster_id' => 7, 'node_id' => 'worker-1', 'fd' => 11],
            'other' => ['channel' => $other, 'cluster_id' => 7, 'node_id' => 'worker-2', 'fd' => 12],
        ]);

        self::assertSame([['cluster_id' => 7, 'node_id' => 'worker-1', 'role' => 'worker']], $relay->unregister(11));
        \Swoole\Coroutine\run(static function () use ($closed, $other): void {
            self::assertFalse($closed->pop(0.001));
            self::assertTrue($other->push('still open', 0.001));
            self::assertSame('still open', $other->pop(0.001));
        });
    }
}

<?php

declare(strict_types=1);

namespace HyperfTest\Cases;

use App\Exception\AppException;
use App\Services\Docker\AgentRelayService;
use App\Services\Docker\AgentSessionRegistry;
use PHPUnit\Framework\TestCase;

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
}

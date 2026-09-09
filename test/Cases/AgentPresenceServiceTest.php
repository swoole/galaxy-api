<?php

declare(strict_types=1);
/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */

namespace HyperfTest\Cases;

use App\Model\Cluster;
use App\Services\Docker\AgentPresenceService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
class AgentPresenceServiceTest extends TestCase
{
    #[DataProvider('presenceStates')]
    public function testClusterAvailabilityFollowsManagerPresence(
        bool $managerOnline,
        int $expected,
        int $online,
        string $currentAgentStatus,
        string $expectedAgentStatus,
        int $expectedStatus
    ): void {
        self::assertSame(
            ['agent_status' => $expectedAgentStatus, 'status' => $expectedStatus],
            AgentPresenceService::deriveClusterStatus(
                $managerOnline,
                $expected,
                $online,
                $currentAgentStatus
            )
        );
    }

    public static function presenceStates(): iterable
    {
        yield 'manager offline' => [false, 3, 2, 'healthy', 'offline', Cluster::STATUS_OFFLINE];
        yield 'manager initializing workers' => [true, 3, 1, 'initializing', 'initializing', Cluster::STATUS_READY];
        yield 'manager online with worker degradation' => [true, 3, 2, 'healthy', 'degraded', Cluster::STATUS_READY];
        yield 'all agents online' => [true, 3, 3, 'degraded', 'healthy', Cluster::STATUS_READY];
    }
}

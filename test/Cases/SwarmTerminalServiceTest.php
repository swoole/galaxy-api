<?php

declare(strict_types=1);

namespace HyperfTest\Cases;

use App\Exception\AppException;
use App\Model\Cluster;
use App\Services\Docker\AgentRelayService;
use App\Services\Docker\SwarmTerminalService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * @internal
 * @coversNothing
 */
class SwarmTerminalServiceTest extends TestCase
{
    public function testExitMarkerCannotAppearInProcessListing(): void
    {
        $service = (new ReflectionClass(SwarmTerminalService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($service, 'commandWithExitMarker');
        $markerName = 'GALAXY_TERMINAL_EXIT_regression';
        $command = $method->invoke($service, $markerName);
        $script = (string) ($command[2] ?? '');

        self::assertStringContainsString($markerName, $script);
        self::assertStringContainsString(chr(92) . '036', $script);
        self::assertStringContainsString(chr(92) . '037', $script);
        self::assertStringNotContainsString("\x1e{$markerName}\x1f", $script);
    }

    public function testTerminalRequiresAnExplicitAgentNode(): void
    {
        $reflection = new ReflectionClass(SwarmTerminalService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $property = $reflection->getProperty('agentRelay');
        $property->setValue(
            $service,
            (new ReflectionClass(AgentRelayService::class))->newInstanceWithoutConstructor()
        );
        $method = new ReflectionMethod($service, 'requiredAgentNodeId');

        $cluster = new Cluster();
        $cluster->endpoint = 'agent://7/worker-node-1';
        self::assertSame('worker-node-1', $method->invoke($service, $cluster));

        $cluster->endpoint = 'agent://7';
        $this->expectException(AppException::class);
        $this->expectExceptionMessage('必须指定容器所在节点');
        $method->invoke($service, $cluster);
    }
}

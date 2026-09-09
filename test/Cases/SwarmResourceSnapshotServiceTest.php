<?php

declare(strict_types=1);
/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */

namespace HyperfTest\Cases;

use App\Model\Cluster;
use App\Services\Docker\SwarmResourceSnapshotService;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
final class SwarmResourceSnapshotServiceTest extends TestCase
{
    public function testServiceRowAggregatesEveryTaskContainer(): void
    {
        $service = (new \ReflectionClass(SwarmResourceSnapshotService::class))
            ->newInstanceWithoutConstructor();
        $method = (new \ReflectionClass($service))->getMethod('serviceRow');
        $cluster = new Cluster(['id' => 3, 'org_id' => 1]);
        $row = $method->invoke(
            $service,
            $cluster,
            [
                'ID' => 'service-id',
                'Spec' => [
                    'Name' => 'traefik',
                    'Mode' => ['Replicated' => ['Replicas' => 2]],
                    'TaskTemplate' => ['Resources' => ['Limits' => ['MemoryBytes' => 1024]]],
                ],
            ],
            [
                ['DesiredState' => 'running', 'Status' => ['State' => 'running']],
                ['DesiredState' => 'running', 'Status' => ['State' => 'running']],
            ],
            [
                ['cpu_percent' => 25, 'memory_usage' => 100, 'network_rx' => 1000,
                    'network_tx' => 2000, 'disk_read' => 3000, 'disk_write' => 4000],
                ['cpu_percent' => 75, 'memory_usage' => 200, 'network_rx' => 500,
                    'network_tx' => 1000, 'disk_read' => 1500, 'disk_write' => 2000],
            ],
            123456
        );

        self::assertSame('service-id', $row['resource_uid']);
        self::assertSame('traefik', $row['name']);
        self::assertSame(2, $row['desired_tasks']);
        self::assertSame(2, $row['running_tasks']);
        self::assertSame(2, $row['container_count']);
        self::assertSame(100.0, $row['cpu_percent']);
        self::assertSame(300, $row['memory_usage']);
        self::assertSame(2048, $row['memory_limit']);
        self::assertSame(1500, $row['network_rx']);
        self::assertTrue($row['metric_available']);
    }

    public function testNodeRowUsesHostMemoryAndContainerTotals(): void
    {
        $service = (new \ReflectionClass(SwarmResourceSnapshotService::class))
            ->newInstanceWithoutConstructor();
        $method = (new \ReflectionClass($service))->getMethod('nodeRow');
        $row = $method->invoke(
            $service,
            new Cluster(['id' => 3, 'org_id' => 1]),
            [
                'ID' => 'node-id',
                'Spec' => ['Availability' => 'active'],
                'Status' => ['State' => 'ready'],
                'Description' => ['Hostname' => 'worker-1'],
            ],
            [
                'available' => true,
                'memory_total' => 8192,
                'containers' => [[
                    'cpu_percent' => 40, 'memory_usage' => 512, 'network_rx' => 10,
                    'network_tx' => 20, 'disk_read' => 30, 'disk_write' => 40,
                ]],
            ],
            123456
        );

        self::assertSame('worker-1', $row['name']);
        self::assertSame('ready', $row['phase']);
        self::assertSame(1, $row['container_count']);
        self::assertSame(40.0, $row['cpu_percent']);
        self::assertSame(512, $row['memory_usage']);
        self::assertSame(8192, $row['memory_limit']);
        self::assertTrue($row['metric_available']);
    }
}

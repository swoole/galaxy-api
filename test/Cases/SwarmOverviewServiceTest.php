<?php

declare(strict_types=1);

namespace HyperfTest\Cases;

use App\Services\Docker\SwarmOverviewService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * @internal
 * @coversNothing
 */
class SwarmOverviewServiceTest extends TestCase
{
    public function testRemoteTaskContainersIncludesCurrentWorkerTaskAndSkipsHistoryAndLocalDuplicates(): void
    {
        $service = (new ReflectionClass(SwarmOverviewService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($service, 'remoteTaskContainers');
        $task = static fn (string $id, string $containerId, string $desiredState): array => [
            'ID' => $id,
            'ServiceID' => 'service-1',
            'NodeID' => 'worker-1',
            'Slot' => 1,
            'DesiredState' => $desiredState,
            'CreatedAt' => '2026-07-22T12:00:00Z',
            'Spec' => ['ContainerSpec' => ['Mounts' => []]],
            'Status' => [
                'State' => 'running',
                'Message' => 'started',
                'ContainerStatus' => ['ContainerID' => $containerId],
            ],
        ];
        $services = ['service-1' => [
            'ID' => 'service-1',
            'Spec' => [
                'Name' => 'web',
                'TaskTemplate' => ['ContainerSpec' => ['Image' => 'example/web:1']],
            ],
        ]];

        $rows = $method->invoke(
            $service,
            [
                $task('task-worker', 'container-worker', 'running'),
                $task('task-old', 'container-old', 'shutdown'),
                $task('task-local', 'container-local', 'running'),
            ],
            $services,
            ['worker-1' => 'worker-a'],
            ['container-local' => true]
        );

        self::assertCount(1, $rows);
        self::assertSame('container-worker', $rows[0]['id']);
        self::assertSame('worker-a', $rows[0]['node_hostname']);
        self::assertFalse($rows[0]['operable']);
        self::assertTrue($rows[0]['remote']);
    }
}

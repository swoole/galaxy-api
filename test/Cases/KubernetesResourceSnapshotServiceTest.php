<?php

declare(strict_types=1);
/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */

namespace HyperfTest\Cases;

use App\Services\Kubernetes\KubernetesResourceSnapshotService;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
final class KubernetesResourceSnapshotServiceTest extends TestCase
{
    public function testReplicaSetOwnerResolvesToDeployment(): void
    {
        $service = (new \ReflectionClass(KubernetesResourceSnapshotService::class))
            ->newInstanceWithoutConstructor();
        $method = (new \ReflectionClass($service))->getMethod('workloadOwner');
        $metadata = [
            'name' => 'web-abc-123',
            'ownerReferences' => [[
                'controller' => true,
                'kind' => 'ReplicaSet',
                'name' => 'web-abc',
                'uid' => 'rs-uid',
            ]],
        ];
        $owners = ['rs-uid' => ['kind' => 'Deployment', 'name' => 'web', 'uid' => 'deployment-uid']];

        self::assertSame(['Deployment', 'web'], $method->invoke($service, $metadata, $owners));
    }

    public function testUnownedPodBecomesItsOwnWorkload(): void
    {
        $service = (new \ReflectionClass(KubernetesResourceSnapshotService::class))
            ->newInstanceWithoutConstructor();
        $method = (new \ReflectionClass($service))->getMethod('workloadOwner');

        self::assertSame(['Pod', 'debug-shell'], $method->invoke(
            $service,
            ['name' => 'debug-shell'],
            []
        ));
    }

    public function testKubernetesQuantitiesAreNormalized(): void
    {
        $service = (new \ReflectionClass(KubernetesResourceSnapshotService::class))
            ->newInstanceWithoutConstructor();
        $reflection = new \ReflectionClass($service);

        self::assertSame(0.25, $reflection->getMethod('cpuCores')->invoke($service, '250m'));
        self::assertEqualsWithDelta(
            0.000001,
            $reflection->getMethod('cpuCores')->invoke($service, '1000n'),
            0.000000000001
        );
        self::assertSame(1073741824, $reflection->getMethod('bytes')->invoke($service, '1Gi'));
    }
}

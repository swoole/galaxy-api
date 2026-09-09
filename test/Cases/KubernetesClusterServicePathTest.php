<?php

declare(strict_types=1);
/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */

namespace HyperfTest\Cases;

use App\Services\Kubernetes\KubernetesClusterService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
class KubernetesClusterServicePathTest extends TestCase
{
    #[DataProvider('namespacedApiPaths')]
    public function testNamespacedPathUsesTheCorrectKubernetesApiPrefix(
        string $api,
        string $namespace,
        string $resource,
        string $expected
    ): void {
        $reflection = new \ReflectionClass(KubernetesClusterService::class);
        $service = $reflection->newInstanceWithoutConstructor();

        self::assertSame(
            $expected,
            $reflection->getMethod('nsPath')->invoke($service, $api, $namespace, $resource)
        );
    }

    public static function namespacedApiPaths(): array
    {
        return [
            'core API' => ['api/v1', 'cattle-system', 'services', '/api/v1/namespaces/cattle-system/services'],
            'apps group shorthand' => ['apps/v1', 'cattle-system', 'deployments', '/apis/apps/v1/namespaces/cattle-system/deployments'],
            'batch group shorthand' => ['batch/v1', 'jobs', 'cronjobs', '/apis/batch/v1/namespaces/jobs/cronjobs'],
            'explicit grouped API' => ['apis/networking.k8s.io/v1', 'default', 'ingresses', '/apis/networking.k8s.io/v1/namespaces/default/ingresses'],
            'all namespaces' => ['apps/v1', '', 'deployments', '/apis/apps/v1/deployments'],
        ];
    }
}

<?php

declare(strict_types=1);

namespace HyperfTest\Cases;

use App\Exception\AppException;
use App\Services\Docker\SwarmWebGatewayService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @internal
 * @coversNothing
 */
class SwarmWebGatewayServiceTest extends TestCase
{
    public function testNormalizeEnablesDashboardOnAnIndependentPort(): void
    {
        $reflection = new ReflectionClass(SwarmWebGatewayService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $normalized = $reflection->getMethod('normalize')->invoke($service, [
            'dashboard_enabled' => true,
            'dashboard_port' => 18080,
        ]);

        self::assertTrue($normalized['dashboard_enabled']);
        self::assertSame(18080, $normalized['dashboard_port']);
    }

    public function testNormalizeRejectsDashboardPortConflict(): void
    {
        $reflection = new ReflectionClass(SwarmWebGatewayService::class);
        $service = $reflection->newInstanceWithoutConstructor();

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('Dashboard 不能与 HTTP/HTTPS 使用相同的发布端口');

        $reflection->getMethod('normalize')->invoke($service, [
            'http_port' => 80,
            'https_port' => 443,
            'dashboard_enabled' => true,
            'dashboard_port' => 443,
        ]);
    }
}

<?php

declare(strict_types=1);
/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */

namespace HyperfTest\Cases;

use App\Model\GatewayVhost;
use App\Services\Gateway\GatewayVhostService;
use Hyperf\Database\Model\Collection;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
final class GatewayVhostStreamingTest extends TestCase
{
    public function testGeneratedTraefikConfigFlushesStreamsAndDoesNotCompressSse(): void
    {
        $vhost = new GatewayVhost([
            'hostname' => 'stream.example.test',
            'path_prefix' => '/',
            'path_match' => 'prefix',
            'methods' => [],
            'ip_allowlist' => [],
            'ip_denylist' => [],
            'security_headers_enabled' => true,
            'custom_request_headers' => [],
            'custom_response_headers' => [],
            'cors_enabled' => false,
            'compress_enabled' => true,
            'request_body_limit_bytes' => 104857600,
            'rate_limit_average' => 0,
            'max_inflight_requests' => 0,
            'retry_attempts' => 0,
            'circuit_breaker_expression' => '',
            'tls_enabled' => false,
            'entrypoint' => 'web',
            'priority' => 0,
            'target_service' => 'stream-api',
            'target_port' => 9501,
            'upstream_scheme' => 'http',
            'pass_host_header' => true,
            'dial_timeout_ms' => 30000,
            'response_header_timeout_ms' => 0,
            'idle_connection_timeout_ms' => 90000,
            'healthcheck_path' => '',
            'sticky_cookie_enabled' => false,
            'rewrite_type' => 'none',
            'rewrite_pattern' => '',
        ]);
        $vhost->id = 7;
        $vhost->setRelation('rewrites', new Collection());
        $vhost->setRelation('certificate', null);
        $service = (new \ReflectionClass(GatewayVhostService::class))->newInstanceWithoutConstructor();
        $buildYaml = new \ReflectionMethod(GatewayVhostService::class, 'buildYaml');
        $configuration = $buildYaml->invoke($service, [$vhost]);

        self::assertSame(
            '-1ms',
            $configuration['http']['services']['cg-vhost-7-svc']['loadBalancer']['responseForwarding']['flushInterval']
        );
        self::assertSame(
            ['text/event-stream'],
            $configuration['http']['middlewares']['cg-vhost-7-compress']['compress']['excludedContentTypes']
        );
        self::assertContains(
            'cg-vhost-7-body-limit',
            $configuration['http']['routers']['cg-vhost-7']['middlewares']
        );
        self::assertSame(
            'Host(`stream.example.test`) && Header(`Accept`, `text/event-stream`)',
            $configuration['http']['routers']['cg-vhost-7-sse']['rule']
        );
        self::assertNotContains(
            'cg-vhost-7-body-limit',
            $configuration['http']['routers']['cg-vhost-7-sse']['middlewares']
        );
        self::assertNotContains(
            'cg-vhost-7-compress',
            $configuration['http']['routers']['cg-vhost-7-sse']['middlewares']
        );
    }
}

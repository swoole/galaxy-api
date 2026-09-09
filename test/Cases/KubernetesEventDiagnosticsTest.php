<?php

declare(strict_types=1);

namespace HyperfTest\Cases;

use App\Services\Kubernetes\KubernetesEventDiagnostics;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
class KubernetesEventDiagnosticsTest extends TestCase
{
    public function testImagePullFailureHasPriorityAndIsDeduplicated(): void
    {
        $events = [
            [
                'type' => 'Warning',
                'reason' => 'Failed',
                'message' => 'Failed to pull image "example/missing:v1": not found',
                'involvedObject' => ['kind' => 'Pod', 'name' => 'console-1'],
            ],
            [
                'type' => 'Warning',
                'reason' => 'FailedScheduling',
                'message' => '0/1 nodes are available',
                'involvedObject' => ['kind' => 'Pod', 'name' => 'console-1'],
            ],
        ];
        $pods = [[
            'metadata' => ['name' => 'console-1'],
            'status' => ['containerStatuses' => [[
                'name' => 'console',
                'state' => ['waiting' => [
                    'reason' => 'ImagePullBackOff',
                    'message' => 'Back-off pulling image "example/missing:v1"',
                ]],
            ]]],
        ]];

        $summary = KubernetesEventDiagnostics::summarize($events, $pods, 2);

        self::assertStringStartsWith('Kubernetes Events：', $summary);
        self::assertStringContainsString('Back-off pulling image', $summary);
        self::assertStringContainsString('Failed to pull image', $summary);
        self::assertStringNotContainsString('0/1 nodes are available', $summary);
    }
}

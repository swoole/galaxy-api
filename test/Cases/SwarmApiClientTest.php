<?php

namespace HyperfTest\Cases;

use App\Services\Docker\AgentRelayService;
use App\Services\Docker\SwarmApiClient;
use HyperfTest\HttpTestCase;

/**
 * @internal
 * @coversNothing
 */
class SwarmApiClientTest extends HttpTestCase
{
    public function testNormalizeGlobalServiceSpecKeepsDockerEmptyObject(): void
    {
        $relay = $this->createMock(AgentRelayService::class);
        $client = new SwarmApiClient($relay);

        $spec = $client->normalizeServiceSpec([
            'Name' => 'galaxy-agent',
            'Mode' => ['Global' => [], 'Replicated' => null],
        ]);

        $this->assertSame(
            '{"Name":"galaxy-agent","Mode":{"Global":{}}}',
            json_encode($spec, JSON_UNESCAPED_SLASHES)
        );
    }

    public function testNormalizeReplicatedServiceSpecDoesNotChangeMode(): void
    {
        $relay = $this->createMock(AgentRelayService::class);
        $client = new SwarmApiClient($relay);
        $spec = ['Mode' => ['Replicated' => ['Replicas' => 2]]];

        $this->assertSame($spec, $client->normalizeServiceSpec($spec));
    }
}

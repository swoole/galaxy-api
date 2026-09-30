<?php

namespace HyperfTest\Cases;

use App\Services\Docker\AgentRelayService;
use App\Services\Docker\SwarmApiClient;
use GuzzleHttp\Client;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use HyperfTest\HttpTestCase;
use Psr\Http\Message\RequestInterface;

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

    public function testPutArchiveSetsDockerDirectoryOverwriteProtection(): void
    {
        $relay = $this->createMock(AgentRelayService::class);
        $api = new SwarmApiClient($relay);
        $request = null;
        $client = new Client([
            'base_uri' => 'http://docker',
            'handler' => static function (RequestInterface $received) use (&$request) {
                $request = $received;
                return Create::promiseFor(new Response(200));
            },
        ]);

        $api->putArchive($client, 'container-id', '/var/www/html', 'tar-content');

        $this->assertSame('PUT', $request->getMethod());
        $this->assertSame('/containers/container-id/archive', $request->getUri()->getPath());
        parse_str($request->getUri()->getQuery(), $query);
        $this->assertSame([
            'path' => '/var/www/html',
            'noOverwriteDirNonDir' => 'true',
        ], $query);
    }
}

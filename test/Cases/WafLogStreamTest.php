<?php

namespace HyperfTest\Cases;

use App\Services\Docker\DockerLogStreamReader;
use App\Services\Docker\AgentRelayService;
use App\Services\Docker\AgentSessionRegistry;
use App\Services\Docker\SwarmApiClient;
use App\Model\Cluster;
use App\Services\Gateway\WafLogBatch;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class WafLogStreamTest extends TestCase
{
    public function testChunkBoundariesCanSplitEveryByteOfDockerFrames(): void
    {
        $body = WafLogWindowTest::frame(1792800000000000001, "{\"id\":1}\n");
        $http = '';
        foreach (str_split($body, 7) as $chunk) $http .= dechex(strlen($chunk)) . "\r\n" . $chunk . "\r\n";
        $chunks = str_split($http . "0\r\n\r\n", 1);
        $actual = (new DockerLogStreamReader())->read(static function () use (&$chunks) { return array_shift($chunks) ?? false; }, 1024, microtime(true) + 1);
        self::assertSame($body, $actual);
        self::assertSame("{\"id\":1}\n", (new WafLogBatch())->decode($actual)[0]['data']);
    }

    public function testBodyLimitStopsBeforeReadingAnOversizedChunk(): void
    {
        $calls = 0;
        $result = (new DockerLogStreamReader())->read(static function () use (&$calls) { ++$calls; return "900000\r\n"; }, 8388608, microtime(true) + 1);
        self::assertNull($result); self::assertSame(1, $calls);
    }

    public function testCleanDockerFrameWithoutHttpCompletionIsNotConsideredSuccess(): void
    {
        $body = WafLogWindowTest::frame(1792800000000000001, "line\n");
        $chunks = [dechex(strlen($body)) . "\r\n" . $body . "\r\n"];
        $this->expectException(RuntimeException::class);
        (new DockerLogStreamReader())->read(static function () use (&$chunks) { return array_shift($chunks) ?? false; }, 1024, microtime(true) + 1);
    }

    public function testClientUsesTheOwningNodeAndClosesTheAgentStream(): void
    {
        $body = WafLogWindowTest::frame(1792800000000000001, "line\n");
        $server = new class($body) {
            public AgentRelayService $relay;
            public array $requests = [];
            public function __construct(private string $body) {}
            public function isEstablished(int $fd): bool { return true; }
            public function push(int $fd, string $json): bool
            {
                $request = json_decode($json, true); $this->requests[] = $request;
                if ($request['type'] === 'stream_open') {
                    $this->relay->resolve(['type' => 'stream_ready', 'id' => $request['id'], 'status' => 200], $fd);
                    $http = dechex(strlen($this->body)) . "\r\n" . $this->body . "\r\n0\r\n\r\n";
                    $this->relay->resolve(['type' => 'stream_data', 'id' => $request['id'], 'data' => base64_encode($http)], $fd);
                    $this->relay->resolve(['type' => 'stream_closed', 'id' => $request['id']], $fd);
                }
                return true;
            }
        };
        $sessions = new AgentSessionRegistry(null, null, false);
        $sessions->register(1, 'fixture-swarm', 'node2', 'worker', '1.0.0', 1, $server, 19);
        $server->relay = new AgentRelayService($sessions);
        $api = new SwarmApiClient($server->relay);
        $cluster = new Cluster(['org_id' => 1]); $cluster->id = 1;
        // Register before starting the scheduler: the fixture must not start a perpetual heartbeat.
        class_exists(\App\Services\Docker\AgentDockerStream::class);
        \Swoole\Coroutine\run(function () use ($api, $server, $cluster, $body): void {
            self::assertSame($body, $api->requestLogWindow($cluster, 'node2', 'fixture-container', ['since' => '1792800000.000000001', 'until' => '1792800001.000000000']));
            parse_str(parse_url($server->requests[0]['path'], PHP_URL_QUERY), $query);
            self::assertSame('all', $query['tail']); self::assertSame('1792800000.000000001', $query['since']);
            self::assertSame('stream_close', $server->requests[1]['type']);
        });
    }
}

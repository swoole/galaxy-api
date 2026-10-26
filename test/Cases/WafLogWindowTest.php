<?php

namespace HyperfTest\Cases;

use App\Services\Gateway\WafLogBatch;
use App\Services\Gateway\WafLogParser;
use App\Services\Gateway\WafLogWindowReader;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class WafLogWindowTest extends TestCase
{
    private const BASE = 1792800000000000000;

    public static function frame(int $ns, string $data): string
    {
        $payload = gmdate('Y-m-d\TH:i:s', intdiv($ns, 1000000000)) . '.' . str_pad((string) ($ns % 1000000000), 9, '0', STR_PAD_LEFT) . 'Z ' . $data;
        return "\1\0\0\0" . pack('N', strlen($payload)) . $payload;
    }

    public function testDenseWindowsAreSplitWithoutLosingMoreThanTwoThousandRecords(): void
    {
        $rows = [];
        for ($i = 0; $i < 2501; ++$i) $rows[] = [self::BASE + $i * 10000000, $i . "\n"];
        $seen = []; $checkpoints = [];
        $result = (new WafLogWindowReader())->read(self::BASE, self::BASE + 30000000000,
            static function ($from, $until, $tail) use ($rows): string {
                $matching = array_values(array_filter($rows, static fn ($row) => $row[0] >= $from && $row[0] < $until));
                return implode('', array_map(static fn ($row) => self::frame(...$row), array_slice($matching, -$tail)));
            }, static function ($plain, $checkpoint) use (&$seen, &$checkpoints): void {
                foreach (explode("\n", trim($plain)) as $line) if ($line !== '') $seen[] = (int) $line;
                $checkpoints[] = $checkpoint;
            }, microtime(true) + 5, 256);
        self::assertSame(range(0, 2500), $seen);
        self::assertSame(2501, $result['records']);
        self::assertSame(0, $result['backlog_seconds']);
        self::assertFalse($result['partial']);
        self::assertGreaterThan(1, $result['requests']);
        self::assertSame(self::BASE + 30000000000, end($checkpoints));
    }

    public function testNanosecondBoundariesAndPartialLinesKeepASafeCheckpoint(): void
    {
        $batch = new WafLogBatch();
        self::assertSame('', $batch->lines($batch->decode(self::frame(self::BASE, '{"id":')), self::BASE, self::BASE + 1));
        self::assertSame(self::BASE, $batch->checkpoint(self::BASE + 1));
        self::assertSame("{\"id\":1}\n", $batch->lines($batch->decode(self::frame(self::BASE + 1, "1}\n")), self::BASE + 1, self::BASE + 2));
        self::assertSame(self::BASE + 2, $batch->checkpoint(self::BASE + 2));
        self::assertSame('1792800000.000000001', WafLogBatch::timestamp(self::BASE + 1));
        self::assertSame(self::BASE + 1, $batch->decode(self::frame(self::BASE + 1, "ok\n"))[0]['ns']);
    }

    public function testTruncationNeverAdvancesCheckpoint(): void
    {
        $consumed = false;
        try {
            (new WafLogWindowReader())->read(self::BASE, self::BASE + 1,
                fn () => substr(self::frame(self::BASE, "data\n"), 0, -1),
                function () use (&$consumed): void { $consumed = true; }, microtime(true) + 1);
            self::fail('Truncated frame accepted');
        } catch (RuntimeException $e) { self::assertStringContainsString('截断', $e->getMessage()); }
        self::assertFalse($consumed);
    }

    public function testRequestBudgetReportsBacklogInsteadOfSkippingIt(): void
    {
        $result = (new WafLogWindowReader())->read(self::BASE, self::BASE + 90000000000,
            fn () => '', fn () => null, microtime(true) + 1, 512, 1);
        self::assertSame(self::BASE + 30000000000, $result['cursor_ns']);
        self::assertSame(60, $result['backlog_seconds']);
        self::assertTrue($result['partial']);
    }

    public function testAuditAndAccessCorrelateAcrossBatchesWithoutPersistingPayloads(): void
    {
        $parser = new WafLogParser(); $state = [];
        $audit = json_encode(['transaction' => ['unique_id' => 'r1', 'client_ip' => '192.0.2.1', 'time_stamp' => '2026-10-24T10:00:00Z',
            'request' => ['headers' => ['Authorization' => 'secret']], 'messages' => [['message' => 'SQL injection', 'details' => ['ruleId' => '942100', 'data' => 'secret-body']]]]]);
        self::assertSame(0, $parser->parse($audit, 'block', $state)[0]['status_code']);
        $state = $parser->pending($state);
        self::assertStringNotContainsString('secret', json_encode($state));
        $access = json_encode(['kind' => 'galaxy_waf_access', 'id' => 'r1', 'ip' => '192.0.2.1', 'status' => 403, 'origin_status' => '-', 'uri' => '/login?token=secret', 'method' => 'POST']);
        $events = $parser->parse($access, 'block', $state);
        self::assertSame(403, $events[0]['status_code']); self::assertSame('block', $events[0]['action']);
        self::assertSame('/login', $events[0]['uri']); self::assertCount(1, $events[0]['messages']);
        self::assertStringNotContainsString('secret', json_encode($parser->pending($state)));
    }
}

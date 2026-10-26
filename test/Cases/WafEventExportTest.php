<?php

namespace HyperfTest\Cases;

use App\Exception\AppException;
use App\Model\Cluster;
use App\Services\Gateway\WafEventFilters;
use App\Services\Gateway\WafEventService;
use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Database\Connection;
use Hyperf\Database\ConnectionResolver;
use Hyperf\Database\ConnectionResolverInterface;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WafEventExportTest extends TestCase
{
    private Connection $db;
    private mixed $oldResolver;
    private mixed $oldEnabled;
    private Cluster $cluster;
    private WafEventService $service;

    protected function setUp(): void
    {
        $container = ApplicationContext::getContainer();
        $this->oldResolver = $container->get(ConnectionResolverInterface::class);
        $config = $container->get(ConfigInterface::class);
        $this->oldEnabled = $config->get('waf.enabled', false);
        $config->set('waf.enabled', true);
        // Adapt only MySQL's JSON predicate to native SQLite JSON functions for the isolated store.
        // The original production SQL is also verified against development MySQL.
        $this->db = (new class(new PDO('sqlite::memory:'), ':memory:', 'galaxy_') extends Connection {
            public function select(string $query, array $bindings = [], bool $useReadPdo = true): array
            {
                $query = str_replace('JSON_CONTAINS(messages, ?) = 1',
                    "EXISTS (SELECT 1 FROM json_each(messages) AS entry WHERE json_extract(entry.value, '$.rule_id') = json_extract(?, '$.rule_id'))", $query);
                return parent::select($query, $bindings, $useReadPdo);
            }
        })->setTablePrefix('galaxy_');
        $container->set(ConnectionResolverInterface::class, new ConnectionResolver(['default' => $this->db]));
        $this->db->statement('CREATE TABLE galaxy_waf_event (id INTEGER PRIMARY KEY, org_id INTEGER, cluster_id INTEGER, policy_id INTEGER, occurred_at INTEGER, client_ip TEXT, action TEXT, messages TEXT, uri TEXT, method TEXT, status_code INTEGER)');
        $this->db->statement('CREATE TABLE galaxy_waf_policy (id INTEGER, org_id INTEGER, cluster_id INTEGER, route_source TEXT, route_id INTEGER)');
        $this->db->statement("INSERT INTO galaxy_waf_policy VALUES (7,1,1,'project',71), (8,1,1,'project',72), (9,1,1,'gateway',71)");
        $this->cluster = new Cluster(['org_id' => 1]); $this->cluster->id = 1;
        $this->service = (new \ReflectionClass(WafEventService::class))->newInstanceWithoutConstructor();
    }

    protected function tearDown(): void
    {
        $container = ApplicationContext::getContainer();
        $container->get(ConfigInterface::class)->set('waf.enabled', $this->oldEnabled);
        $container->set(ConnectionResolverInterface::class, $this->oldResolver);
        unset($this->db, $this->service);
    }

    public function testReportAndCsvShareFiltersAndNeverEscapeTheProject(): void
    {
        $now = time() - 10;
        $messages = [['rule_id' => '942100', 'message' => '=HYPERLINK("https://example.invalid")', 'details' => ['data' => 'private-payload']], ['rule_id' => 'CC', 'message' => 'rate limit']];
        foreach ([[1,1,1,7,$now,'/api/%_!/login','POST'], [2,1,1,7,$now,'/api/other','GET'],
            [3,1,1,8,$now,'/api/%_!/login','POST'], [4,1,1,9,$now,'/api/%_!/login','POST'],
            [5,2,1,7,$now,'/api/%_!/login','POST'], [6,1,2,7,$now,'/api/%_!/login','POST'],
            [7,1,1,7,$now-7200,'/api/%_!/login','POST'], [8,1,1,7,$now+1,'/api/%_!/login','POST'],
            [9,1,1,7,$now,'/api/XY!/login','POST']] as [$id,$org,$cluster,$policy,$at,$path,$method]) {
            $this->db->insert('INSERT INTO galaxy_waf_event VALUES (?,?,?,?,?,?,?, ?,?,?,?)', [$id,$org,$cluster,$policy,$at,'192.0.2.1','block',json_encode($messages),$path,$method,403]);
        }
        $filters = ['from' => $now - 3600, 'until' => $now, 'method' => 'POST', 'path' => '/%_!/', 'rule_id' => '942100'];
        $report = $this->service->report($this->cluster, $filters, [71]);
        self::assertSame(1, $report['total']);
        self::assertSame('/api/%_!/login', $report['top_paths'][0]['uri']);
        self::assertSame(1, array_sum(array_column($report['trend'], 'total')));
        $export = $this->service->export($this->cluster, $filters + ['page' => 2], [71]);
        self::assertSame(1, $export['count']);
        self::assertFalse($export['truncated']);
        $lines = explode("\n", trim(substr($export['content'], 3)));
        $row = str_getcsv($lines[1], ',', '"', '');
        self::assertSame('1', $row[0]);
        self::assertSame('942100 CC', $row[8]);
        self::assertStringStartsWith("'=HYPERLINK", $row[9]);
        self::assertStringNotContainsString('private-payload', $export['content']);
        self::assertSame(0, $this->service->export($this->cluster, $filters + ['policy_id' => 8], [71])['count']);
        self::assertSame(0, $this->service->export($this->cluster, $filters, [])['count']);
        self::assertSame(0, $this->service->report($this->cluster, array_replace($filters, ['rule_id' => '94210']), [71])['total']);
        self::assertSame(1, $this->service->report($this->cluster, array_replace($filters, ['rule_id' => 'CC']), [71])['total']);
    }

    public function testBrowserChallengesShareScopedReportAndExportFilters(): void
    {
        $now = time() - 1; $messages = json_encode([['rule_id' => 'BROWSER', 'message' => '浏览器验证未完成', 'tags' => []]]);
        foreach ([7,8] as $id => $policy) $this->db->insert("INSERT INTO galaxy_waf_event VALUES (?,1,1,?,?,'192.0.2.1','challenge',?,'/page','GET',401)", [$id + 1,$policy,$now,$messages]);
        $filters = ['action' => 'challenge', 'rule_id' => 'BROWSER'];
        $report = $this->service->report($this->cluster, $filters, [71]);
        self::assertSame(1, $report['total']); self::assertSame(0, $report['summary']['blocked']);
        $export = $this->service->export($this->cluster, $filters, [71]);
        self::assertSame(1, $export['count']); self::assertStringContainsString('BROWSER', $export['content']);
        self::assertSame(0, $this->service->export($this->cluster, $filters + ['policy_id' => 8], [71])['count']);
    }

    public function testCsvExportIsBoundedAndUsesKeysetPagination(): void
    {
        $now = time();
        $this->db->beginTransaction();
        for ($id = 1; $id <= WafEventService::EXPORT_LIMIT + 1; ++$id) {
            $this->db->insert("INSERT INTO galaxy_waf_event VALUES (?,1,1,7,?,'192.0.2.1','block','[]','/','GET',403)", [$id, $now]);
        }
        $this->db->commit();
        $export = $this->service->export($this->cluster, [], [71]);
        self::assertSame(WafEventService::EXPORT_LIMIT, $export['count']);
        self::assertTrue($export['truncated']);
        $lines = explode("\n", trim(substr($export['content'], 3)));
        self::assertCount(WafEventService::EXPORT_LIMIT + 1, $lines);
        self::assertSame('5001', str_getcsv($lines[1], ',', '"', '')[0]);
        self::assertSame('2', str_getcsv($lines[5000], ',', '"', '')[0]);
    }

    public static function invalidFilters(): array
    {
        return [[['from' => 100]], [['until' => 100]], [['from' => 200, 'until' => 100]],
            [['from' => 1, 'until' => 86400 * 31]], [['hours' => 721]], [['path' => 'no-slash']],
            [['rule_id' => '942100%']], [['rule_id' => '942100 OR 1']], [['method' => 'get']]];
    }

    #[DataProvider('invalidFilters')]
    public function testInvalidFiltersAreRejected(array $filters): void
    {
        $this->expectException(AppException::class);
        WafEventFilters::normalize($filters);
    }
}

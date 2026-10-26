<?php

namespace HyperfTest\Cases;

use App\Exception\AppException;
use App\Model\Cluster;
use App\Model\WafPolicy;
use App\Services\Docker\SwarmApiClient;
use App\Services\Gateway\SwarmGatewayMutationLock;
use App\Services\Gateway\WafAlertService;
use App\Services\Gateway\WafLogCollector;
use App\Services\Gateway\WafLogParser;
use App\Services\Gateway\WafLogWindowReader;
use App\Services\Gateway\WafRetirementService;
use App\Services\Gateway\WafRuntimeService;
use GuzzleHttp\Client;
use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\Database\Connection;
use Hyperf\Database\ConnectionResolver;
use Hyperf\Database\ConnectionResolverInterface;
use HyperfTest\Stub\WafMemoryRedis;
use HyperfTest\Stub\WafDynamicServices;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class WafLogCollectorTest extends TestCase
{
    private Connection $db;
    private mixed $oldResolver;
    private WafMemoryRedis $redis;
    private SwarmApiClient $docker;
    private WafRetirementService $retirement;
    private WafLogCollector $collector;
    private WafPolicy $policy;
    private Cluster $cluster;
    private int $at;

    protected function setUp(): void
    {
        $container = ApplicationContext::getContainer();
        $this->oldResolver = $container->get(ConnectionResolverInterface::class);
        $this->db = (new Connection(new PDO('sqlite::memory:'), ':memory:', 'galaxy_'))->setTablePrefix('galaxy_');
        $this->db->setQueryGrammar(new class extends \Hyperf\Database\Query\Grammars\Grammar {
            public function compileInsertOrIgnore(\Hyperf\Database\Query\Builder $query, array $values): string
            { return preg_replace('/^insert into /', 'insert or ignore into ', $this->compileInsert($query, $values)); }
        });
        $this->db->setTablePrefix('galaxy_');
        $container->set(ConnectionResolverInterface::class, new ConnectionResolver(['default' => $this->db]));
        $this->db->statement('CREATE TABLE galaxy_waf_event (id INTEGER PRIMARY KEY AUTOINCREMENT, event_key TEXT UNIQUE, org_id INTEGER, cluster_id INTEGER, policy_id INTEGER, occurred_at INTEGER, client_ip TEXT, action TEXT, messages TEXT, uri TEXT, method TEXT, status_code INTEGER)');
        $this->db->statement('CREATE TABLE galaxy_waf_policy (id INTEGER PRIMARY KEY, org_id INTEGER, cluster_id INTEGER, service_id TEXT)');
        $this->redis = new WafMemoryRedis();
        $this->docker = new class extends SwarmApiClient {
            public array $tasks = [];
            public array $calls = [];
            public array $logs = [];
            public array $services = [];
            public array $configs = [];
            public array $removed = [];
            public float $delay = 0;
            public function __construct() {}
            public function withCluster(Cluster $cluster, callable $callback, int $timeout = 30): mixed { return $callback(new Client(), $this); }
            public function withNode(Cluster $cluster, string $nodeId, callable $callback, int $timeout = 30): mixed { $this->calls[] = ['node' => $nodeId]; return $this->withCluster($cluster, $callback, $timeout); }
            public function request(Client $client, string $method, string $path, array $options = []): array
            {
                if ($method === 'DELETE') { $this->removed[] = $path; return []; }
                return match ($path) { '/tasks' => $this->tasks, '/services' => $this->services, '/configs' => $this->configs, default => throw new RuntimeException('Unexpected Docker path') };
            }
            public function requestRaw(Client $client, string $method, string $path, array $options = []): string
            {
                $this->calls[] = ['path' => $path, 'query' => $options['query']];
                if ($this->delay) usleep((int) ($this->delay * 1000000));
                $value = $this->logs[$path] ?? '';
                if ($value instanceof \Throwable) throw $value;
                $q = $options['query']; $from = (int) str_replace('.', '', $q['since']); $end = (int) str_replace('.', '', $q['until']);
                return implode('', array_map(fn ($row) => WafLogWindowTest::frame(...$row), array_slice(array_values(array_filter($value ?: [], fn ($row) => $row[0] >= $from && $row[0] <= $end)), -$q['tail'])));
            }
            public function requestLogWindow(Cluster $cluster, string $nodeId, string $containerId, array $query, int $maxBytes = 8388608): ?string
            { return $this->withNode($cluster, $nodeId, fn ($client) => $this->requestRaw($client, 'GET', '/containers/' . $containerId . '/logs', ['query' => $query + ['tail' => PHP_INT_MAX]])); }
        };
        $this->retirement = new WafRetirementService($this->redis, $this->docker, new SwarmGatewayMutationLock($this->redis));
        $alerts = (new \ReflectionClass(WafAlertService::class))->newInstanceWithoutConstructor();
        $this->collector = new WafLogCollector($this->docker, new WafLogParser(), new WafLogWindowReader(), $this->redis,
            $this->createMock(StdoutLoggerInterface::class), $alerts, $this->retirement,
            WafDynamicServices::registry(), WafDynamicServices::bans($this->redis));
        $this->policy = new WafPolicy(['org_id' => 1, 'cluster_id' => 1, 'service_id' => 'active', 'applied_mode' => 'block']); $this->policy->id = 7;
        $this->db->insert("INSERT INTO galaxy_waf_policy VALUES (7,1,1,'active')");
        $this->cluster = new Cluster(['org_id' => 1]); $this->cluster->id = 1;
        $this->at = (time() - 10) * 1000000000;
        $this->docker->tasks = [['NodeID' => 'node1', 'Status' => ['ContainerStatus' => ['ContainerID' => 'container1']]]];
        $this->redis->values['galaxy:waf:cursor:v2:active:container1'] = (string) ($this->at - 1000000000);
    }

    protected function tearDown(): void { ApplicationContext::getContainer()->set(ConnectionResolverInterface::class, $this->oldResolver); }

    private function audit(): string { return json_encode(['transaction' => ['unique_id' => 'request1', 'client_ip' => '192.0.2.1', 'messages' => [['message' => 'blocked', 'details' => ['ruleId' => '942100']]]]]) . "\n"; }
    private function access(): string { return json_encode(['kind' => 'galaxy_waf_access', 'id' => 'request1', 'uri' => '/login?secret=1', 'method' => 'POST', 'status' => 403, 'origin_status' => '-']) . "\n"; }

    public function testCursorReplayIsIdempotentAndLateAccessEnrichesEarlierEvent(): void
    {
        $this->docker->logs['/containers/container1/logs'] = [[$this->at, $this->audit()]];
        $first = $this->collector->collectPolicy($this->policy, $this->cluster, microtime(true) + 1);
        self::assertSame('', $first['error']); self::assertSame(1, $this->db->table('waf_event')->count());
        self::assertSame(0, $this->db->table('waf_event')->first()->status_code);
        $this->docker->logs['/containers/container1/logs'][] = [(time() - 3) * 1000000000, $this->access()];
        $this->collector->collectPolicy($this->policy, $this->cluster, microtime(true) + 1);
        self::assertSame(1, $this->db->table('waf_event')->count());
        self::assertSame(403, $this->db->table('waf_event')->first()->status_code);
        self::assertSame('/login', $this->db->table('waf_event')->first()->uri);
        self::assertSame('node1', $this->docker->calls[0]['node']);
        self::assertStringNotContainsString('secret', json_encode($this->redis->values));
    }

    public function testFailedCorrelationWriteLeavesCursorAndReplaysWithoutDuplicates(): void
    {
        $this->docker->logs['/containers/container1/logs'] = [[$this->at, $this->audit() . $this->access()]];
        $before = $this->redis->values['galaxy:waf:cursor:v2:active:container1'];
        $this->redis->failKey = 'galaxy:waf:pending:active:container1';
        $status = $this->collector->collectPolicy($this->policy, $this->cluster, microtime(true) + 1);
        self::assertStringContainsString('cache write failed', $status['error']);
        self::assertSame($before, $this->redis->values['galaxy:waf:cursor:v2:active:container1']);
        $this->redis->failKey = null;
        $this->collector->collectPolicy($this->policy, $this->cluster, microtime(true) + 1);
        self::assertSame(1, $this->db->table('waf_event')->count());
        self::assertGreaterThan((int) $before, (int) $this->redis->values['galaxy:waf:cursor:v2:active:container1']);
    }

    public function testPrunedOldContainerDoesNotBlockReplacementAndGapRemainsVisible(): void
    {
        $this->docker->tasks[] = ['NodeID' => 'node2', 'Status' => ['ContainerStatus' => ['ContainerID' => 'container2']]];
        $this->docker->logs['/containers/container1/logs'] = new AppException(502, 'gone', ['docker_status' => 404]);
        $this->docker->logs['/containers/container2/logs'] = [[$this->at, $this->audit() . $this->access()]];
        $status = $this->collector->collectPolicy($this->policy, $this->cluster, microtime(true) + 1);
        self::assertTrue($status['gap']); self::assertSame('', $status['error']);
        self::assertSame(1, $this->db->table('waf_event')->count());
        $status = $this->collector->collectPolicy($this->policy, $this->cluster, microtime(true) + 1);
        self::assertTrue($status['gap']);
        self::assertStringContainsString('container1', $status['last_gap']['reason']);
    }

    public function testContainersRotateWhenOneExhaustsTheTimeBudget(): void
    {
        $this->docker->tasks[] = ['NodeID' => 'node2', 'Status' => ['ContainerStatus' => ['ContainerID' => 'container2']]];
        $this->docker->delay = .015;
        $this->collector->collectPolicy($this->policy, $this->cluster, microtime(true) + .005);
        $this->docker->calls = [];
        $this->collector->collectPolicy($this->policy, $this->cluster, microtime(true) + .005);
        self::assertSame('node2', $this->docker->calls[0]['node']);
    }

    public function testDisabledDetectorReportsDisabledControlRatherThanUnknownHealth(): void
    {
        $this->policy->service_id = '';
        $status = $this->collector->collectPolicy($this->policy, $this->cluster, microtime(true) + 1);
        self::assertSame(['enabled' => false], $status['control']);
        self::assertSame([], $this->docker->calls);
    }

    public function testRetirementCannotDeleteAReactivatedGenerationOrForeignConfig(): void
    {
        $labels = ['com.codegalaxy.component' => 'waf', 'com.codegalaxy.org.id' => '1', 'com.codegalaxy.cluster.id' => '1', 'com.codegalaxy.waf.policy' => '7'];
        $service = ['ID' => 'old', 'Spec' => ['Name' => 'old-name', 'Labels' => $labels, 'TaskTemplate' => ['ContainerSpec' => ['Configs' => [['ConfigID' => 'old-config']]]]]];
        $this->retirement->register($this->cluster, $service);
        $generation = $this->retirement->pending($this->policy)[0];
        $this->docker->services = [$service]; $this->docker->configs = [['ID' => 'old-config', 'Spec' => ['Labels' => $labels]], ['ID' => 'active-config', 'Spec' => ['Labels' => $labels]]];
        $this->db->update("UPDATE galaxy_waf_policy SET service_id='old'");
        $this->retirement->remove($this->cluster, $this->policy, $generation);
        self::assertSame([], $this->docker->removed); self::assertSame([], $this->retirement->pending($this->policy));
        $this->db->update("UPDATE galaxy_waf_policy SET service_id='active'");
        $this->retirement->register($this->cluster, $service);
        $this->retirement->remove($this->cluster, $this->policy, $generation);
        self::assertSame(['/services/old', '/configs/old-config'], $this->docker->removed);
        $service['Spec']['Labels']['com.codegalaxy.org.id'] = '2';
        $this->expectException(RuntimeException::class);
        $this->retirement->register($this->cluster, $service);
    }

    public function testGenerationBudgetIncludesAllOwnedServicesAndIgnoresForeignOnes(): void
    {
        $runtime = (new \ReflectionClass(WafRuntimeService::class))->newInstanceWithoutConstructor();
        $check = (new \ReflectionMethod($runtime, 'assertGenerationCapacity'));
        $service = static fn ($policy, $org = 1) => ['Spec' => ['Labels' => ['com.codegalaxy.component' => 'waf',
            'com.codegalaxy.org.id' => (string) $org, 'com.codegalaxy.cluster.id' => '1', 'com.codegalaxy.waf.policy' => (string) $policy]]];
        $this->docker->services = array_merge([$service(7), $service(7)], array_fill(0, 100, $service(7, 2)));
        $check->invoke($runtime, new Client(), $this->docker, $this->cluster, 7);
        foreach ([array_fill(0, 3, $service(7)), array_map($service, range(100, 123))] as $full) {
            $this->docker->services = $full;
            try { $check->invoke($runtime, new Client(), $this->docker, $this->cluster, 7); self::fail('Generation budget exceeded'); }
            catch (AppException $e) { self::assertSame(409, $e->getCode()); }
        }
        self::assertSame([], $this->docker->removed);
    }
}

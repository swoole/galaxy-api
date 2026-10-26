<?php

namespace HyperfTest\Cases;

use App\Exception\AppException;
use App\Model\Cluster;
use App\Model\WafAlert;
use App\Model\WafPolicy;
use App\Model\WafPolicyRevision;
use App\Services\Gateway\WafAlertService;
use App\Services\Gateway\WafHistoryService;
use App\Services\Gateway\WafOperationsState;
use App\Services\Gateway\WafPolicyConfig;
use App\Services\Gateway\WafRuntimeService;
use App\Services\Notify;
use Hyperf\Context\ApplicationContext;
use Hyperf\Database\Connection;
use Hyperf\Database\ConnectionResolver;
use Hyperf\Database\ConnectionResolverInterface;
use PDO;
use PHPUnit\Framework\TestCase;

final class WafOperationsTest extends TestCase
{
    private Connection $db;
    private mixed $oldResolver;
    private WafPolicyConfig $config;
    private WafHistoryService $history;
    private WafAlertService $alerts;
    private Notify $notify;
    private WafPolicy $policy;
    private Cluster $cluster;

    protected function setUp(): void
    {
        $container = ApplicationContext::getContainer();
        $this->oldResolver = $container->get(ConnectionResolverInterface::class);
        $this->db = (new Connection(new PDO('sqlite::memory:'), ':memory:', 'galaxy_'))->setTablePrefix('galaxy_');
        $container->set(ConnectionResolverInterface::class, new ConnectionResolver(['default' => $this->db]));
        $this->db->statement('CREATE TABLE galaxy_waf_policy (id INTEGER PRIMARY KEY, org_id INTEGER, cluster_id INTEGER, route_source TEXT, route_id INTEGER, settings TEXT, status TEXT)');
        $this->db->statement('CREATE TABLE galaxy_waf_policy_revision (id INTEGER PRIMARY KEY AUTOINCREMENT, org_id INTEGER, cluster_id INTEGER, policy_id INTEGER, settings TEXT, settings_hash TEXT, actor_id INTEGER, rollback_of INTEGER, status TEXT, error TEXT, created_at INTEGER, completed_at INTEGER)');
        $this->db->statement('CREATE TABLE galaxy_waf_alert (id INTEGER PRIMARY KEY AUTOINCREMENT, org_id INTEGER, cluster_id INTEGER, policy_id INTEGER, kind TEXT, status TEXT, severity TEXT, title TEXT, message TEXT, context TEXT, occurrences INTEGER, first_seen_at INTEGER, last_seen_at INTEGER, resolved_at INTEGER, notified_at INTEGER, notification_error TEXT, acknowledged_at INTEGER, acknowledged_by INTEGER, UNIQUE(policy_id,kind))');
        $this->db->statement('CREATE TABLE galaxy_org_member (org_id INTEGER, uid INTEGER, role INTEGER)');
        $this->db->statement('CREATE TABLE galaxy_project_member (org_id INTEGER, group_id INTEGER, project_id INTEGER, uid INTEGER)');
        $this->db->statement('CREATE TABLE galaxy_project_route (id INTEGER, org_id INTEGER, cluster_id INTEGER, group_id INTEGER, project_id INTEGER)');
        $this->db->statement('CREATE TABLE galaxy_group_resource_grant (org_id INTEGER, group_id INTEGER, resource_type TEXT, resource_id INTEGER)');
        $this->config = new WafPolicyConfig();
        $operations = new WafOperationsState();
        $this->history = new WafHistoryService($operations, $this->config);
        $this->notify = new class extends Notify {
            public array $sent = [];
            public array $outcomes = ['notify' => ['sent' => true, 'error' => '']];
            public function __construct() {}
            public function sendTracked(array $uids, string $scene, array $params = [], array $channels = []): array
            { $this->sent[] = compact('uids', 'scene', 'params', 'channels'); return $this->outcomes; }
        };
        $runtime = (new \ReflectionClass(WafRuntimeService::class))->newInstanceWithoutConstructor();
        $this->alerts = new WafAlertService($operations, $this->config, $this->notify, $runtime);
        $this->policy = WafPolicy::create(['org_id' => 1, 'cluster_id' => 1, 'route_source' => 'project', 'route_id' => 71,
            'settings' => $this->config->normalize(['mode' => 'monitor']), 'status' => 'synced']);
        $this->cluster = new Cluster(['org_id' => 1]); $this->cluster->id = 1;
        $this->db->statement('INSERT INTO galaxy_project_route VALUES (71,1,1,2,10), (72,1,1,2,11)');
        $this->db->statement("INSERT INTO galaxy_group_resource_grant VALUES (1,2,'cluster',1)");
        $this->db->statement('INSERT INTO galaxy_project_member VALUES (1,2,10,101), (1,2,11,102), (2,2,10,103)');
    }

    protected function tearDown(): void
    {
        ApplicationContext::getContainer()->set(ConnectionResolverInterface::class, $this->oldResolver);
    }

    public function testHistoryCapturesBaselineAndFailedChangesAndRejectsStaleWrites(): void
    {
        $new = $this->config->normalize(['mode' => 'block']);
        $revision = $this->history->begin($this->policy, $new, 101, 0);
        self::assertCount(2, $this->history->list($this->cluster, 'project', 71, [71])['revisions']);
        self::assertSame('applied', WafPolicyRevision::orderBy('id')->first()->status);
        self::assertSame('monitor', WafPolicyRevision::orderBy('id')->first()->settings['mode']);
        self::assertSame(101, $revision->actor_id);
        $this->history->finish($revision, false, 'engine unavailable');
        self::assertSame('failed', $this->history->detail($this->cluster, $revision->id, [71])->status);
        self::assertSame('engine unavailable', $revision->error);
        try { $this->history->begin($this->policy, $new, 102, 0); self::fail('Stale revision accepted'); }
        catch (AppException $e) { self::assertSame(409, $e->getCode()); }
        self::assertSame(2, WafPolicyRevision::count());
        $restore = $this->history->begin($this->policy, $this->policy->settings, 101, $revision->id, 1);
        $this->history->finish($restore, true);
        self::assertSame(1, $restore->rollback_of); self::assertSame('applied', $restore->status);
        self::assertSame($restore->id, $this->history->latestId($this->policy));
        self::assertSame([], $this->history->list($this->cluster, 'project', 71, [72])['revisions']);
        $this->expectException(AppException::class);
        $this->history->detail($this->cluster, $restore->id, [72]);
    }

    public function testHistoryRetentionAndUnknownBaselineAreSafe(): void
    {
        $this->policy->status = 'error'; $this->policy->save();
        $first = $this->history->begin($this->policy, $this->policy->settings, 1, 0);
        self::assertSame('baseline', WafPolicyRevision::orderBy('id')->first()->status);
        for ($i = 0; $i < 100; ++$i) $last = $this->history->begin($this->policy, $this->policy->settings, 1, null);
        $this->history->finish($last, true);
        self::assertSame(100, WafPolicyRevision::count());
        self::assertFalse(WafPolicyRevision::where('id', $first->id)->exists());
    }

    public function testMissingOperationsTablesDoNotPreventExistingProtection(): void
    {
        $this->db->statement('DROP TABLE galaxy_waf_alert');
        $ops = new WafOperationsState();
        self::assertFalse($ops->initialized());
        $history = new WafHistoryService($ops, $this->config);
        self::assertNull($history->begin($this->policy, $this->policy->settings, 1, null));
        $this->expectException(AppException::class);
        $history->begin($this->policy, $this->policy->settings, 1, 0);
    }

    public function testOnlyAlertChangesDoNotDeployTheGatewayOrProjectRuntime(): void
    {
        foreach (['service_id TEXT', 'service_name TEXT', 'applied_mode TEXT', 'error TEXT', 'created_at INTEGER', 'updated_at INTEGER', 'synced_at INTEGER'] as $column) $this->db->statement('ALTER TABLE galaxy_waf_policy ADD COLUMN ' . $column);
        $this->db->statement('ALTER TABLE galaxy_project_route ADD COLUMN enabled INTEGER DEFAULT 1');
        $this->policy->service_id = 'detector'; $this->policy->applied_mode = 'monitor'; $this->policy->save();
        $management = (new \ReflectionClass(\App\Services\Gateway\WafManagementService::class))->newInstanceWithoutConstructor();
        foreach (['config' => $this->config, 'history' => $this->history] as $name => $value) (new \ReflectionProperty($management, $name))->setValue($management, $value);
        $settings = $this->policy->settings; $settings['alerts']['enabled'] = true;
        // Gateway/project dependencies deliberately remain uninitialized: invoking either fails this test.
        $result = (new \ReflectionMethod($management, 'saveUnlocked'))->invoke($management, $this->cluster, 'project', 71, $settings, 101, 0, 0);
        self::assertSame('detector', $result['policy']->service_id);
        self::assertSame('synced', $result['policy']->status);
        self::assertTrue($result['policy']->settings['alerts']['enabled']);
        self::assertSame('applied', WafPolicyRevision::where('id', $result['revision_id'])->first()->status);
    }

    public function testConnectionFailureIsNotCachedAsAMissingMigration(): void
    {
        $connection = new class(new PDO('sqlite::memory:')) extends Connection {
            public int $attempts = 0;
            public function select(string $query, array $bindings = [], bool $useReadPdo = true): array
            { ++$this->attempts; throw new \RuntimeException('connection unavailable'); }
        };
        ApplicationContext::getContainer()->set(ConnectionResolverInterface::class, new ConnectionResolver(['default' => $connection]));
        $ops = new WafOperationsState();
        for ($i = 0; $i < 2; ++$i) {
            try { $ops->initialized(); self::fail('Connection failure hidden'); }
            catch (\RuntimeException $e) { self::assertStringContainsString('connection unavailable', $e->getMessage()); }
        }
        self::assertSame(2, $connection->attempts);
    }

    public function testAlertThresholdCooldownAcknowledgementRecoveryAndReopen(): void
    {
        $rule = $this->config->defaults()['alerts'];
        $check = fn ($bad) => $this->alerts->condition($this->policy, 'collection', $bad, $rule, '采集异常', 'backlog', [], 2);
        $check(true); self::assertCount(0, $this->notify->sent);
        $check(true); self::assertCount(1, $this->notify->sent);
        self::assertSame([101], $this->notify->sent[0]['uids']);
        $check(true); self::assertCount(1, $this->notify->sent);
        WafAlert::query()->update(['notified_at' => time() - 3600]);
        $check(true); self::assertCount(2, $this->notify->sent);
        $alert = WafAlert::first();
        $this->alerts->acknowledge($this->cluster, $alert->id, [71], 101);
        WafAlert::query()->update(['notified_at' => time() - 3600]);
        $check(true); self::assertCount(2, $this->notify->sent);
        $check(false); self::assertCount(3, $this->notify->sent);
        self::assertStringStartsWith('已恢复', $this->notify->sent[2]['params']['title']);
        self::assertSame('resolved', WafAlert::first()->status);
        $check(true); $check(true); self::assertCount(4, $this->notify->sent);
        self::assertSame(0, WafAlert::first()->acknowledged_at);
        self::assertCount(0, $this->alerts->list($this->cluster, [72])['alerts']);
        self::assertCount(1, $this->alerts->list($this->cluster, [71])['alerts']);
    }

    public function testNotificationFailuresAreVisibleAndNeverFloodOrLeakToOtherProjects(): void
    {
        $this->notify->outcomes = ['email' => ['sent' => false, 'error' => 'unconfigured']];
        $rule = $this->config->defaults()['alerts'];
        $check = fn ($bad) => $this->alerts->condition($this->policy, 'detector', $bad, $rule, '实例异常', 'missing', [], 1);
        $check(true); $check(true);
        self::assertCount(1, $this->notify->sent);
        self::assertStringContainsString('unconfigured', WafAlert::first()->notification_error);
        $check(false); self::assertCount(1, $this->notify->sent);
        $this->db->statement('DELETE FROM galaxy_group_resource_grant');
        $check(true);
        self::assertCount(1, $this->notify->sent);
        self::assertStringContainsString('没有可用', WafAlert::first()->notification_error);
        $this->expectException(AppException::class);
        $this->alerts->acknowledge($this->cluster, WafAlert::first()->id, [72], 102);
    }

    public function testEvaluationScopesEventCountsAndReportsHealthyRecovery(): void
    {
        $this->db->statement('CREATE TABLE galaxy_waf_event (org_id INTEGER, cluster_id INTEGER, policy_id INTEGER, occurred_at INTEGER, action TEXT)');
        $now = time();
        foreach ([[1,1,$this->policy->id,$now,'block'], [1,1,999,$now,'block'], [2,1,$this->policy->id,$now,'block'],
            [1,2,$this->policy->id,$now,'block'], [1,1,$this->policy->id,$now-3600,'block']] as $row) {
            $this->db->insert('INSERT INTO galaxy_waf_event VALUES (?,?,?,?,?)', $row);
        }
        $docker = new class extends \App\Services\Docker\SwarmApiClient {
            public bool $healthy = false;
            public function __construct() {}
            public function withCluster(Cluster $cluster, callable $callback, int $timeout = 30): mixed { return $callback(new \GuzzleHttp\Client(), $this); }
            public function request(\GuzzleHttp\Client $client, string $method, string $path, array $options = []): array
            { return $this->healthy ? [['ServiceID' => 'detector', 'Status' => ['State' => 'running']]] : []; }
        };
        $runtime = (new \ReflectionClass(WafRuntimeService::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($runtime, 'docker'))->setValue($runtime, $docker);
        $alerts = new WafAlertService(new WafOperationsState(), $this->config, $this->notify, $runtime);
        $this->policy->service_id = 'detector'; $this->policy->applied_mode = 'block';
        $this->policy->settings = $this->config->normalize(['mode' => 'block', 'alerts' => ['enabled' => true, 'attack_threshold' => 2, 'failure_threshold' => 1, 'window_seconds' => 60]]);
        $alerts->evaluate($this->policy, $this->cluster, []);
        self::assertFalse(WafAlert::where('kind', 'attack_burst')->exists());
        self::assertSame('open', WafAlert::where('kind', 'detector')->first()->status);
        self::assertCount(1, $this->notify->sent);
        $docker->healthy = true;
        $alerts->evaluate($this->policy, $this->cluster, []);
        self::assertSame('resolved', WafAlert::where('kind', 'detector')->first()->status);
        self::assertSame('检测实例已恢复运行', $this->notify->sent[1]['params']['message']);
        $this->db->insert('INSERT INTO galaxy_waf_event VALUES (?,?,?,?,?)', [1,1,$this->policy->id,$now,'block']);
        $alerts->evaluate($this->policy, $this->cluster, []);
        self::assertSame('open', WafAlert::where('kind', 'attack_burst')->first()->status);
        self::assertCount(3, $this->notify->sent);
        $alerts->evaluate($this->policy, $this->cluster, ['control' => ['enabled' => true, 'status' => 502, 'at' => time()]]);
        self::assertSame('open', WafAlert::where('kind', 'control')->first()->status);
        self::assertSame('critical', WafAlert::where('kind', 'control')->first()->severity);
        $alerts->evaluate($this->policy, $this->cluster, []);
        self::assertSame('open', WafAlert::where('kind', 'control')->first()->status);
        $alerts->evaluate($this->policy, $this->cluster, ['control' => ['enabled' => true, 'status' => 204, 'at' => time()]]);
        self::assertSame('open', WafAlert::where('kind', 'control')->first()->status);
        $alerts->evaluate($this->policy, $this->cluster, ['control' => ['enabled' => true, 'status' => 204, 'protocol' => true, 'at' => time()]]);
        self::assertSame('resolved', WafAlert::where('kind', 'control')->first()->status);
        self::assertSame('最近请求的动态检查已恢复', $this->notify->sent[4]['params']['message']);
        // A permissive data policy still needs an operational alert on HTTP 204.
        $alerts->evaluate($this->policy, $this->cluster, ['control' => ['enabled' => true, 'status' => 204, 'protocol' => true, 'data_status' => 'degraded', 'at' => time()]]);
        self::assertSame('open', WafAlert::where('kind', 'data')->first()->status);
        $alerts->evaluate($this->policy, $this->cluster, []);
        self::assertSame('open', WafAlert::where('kind', 'data')->first()->status);
        $alerts->evaluate($this->policy, $this->cluster, ['control' => ['enabled' => true, 'status' => 204, 'protocol' => true, 'data_status' => 'ok', 'at' => time()]]);
        self::assertSame('resolved', WafAlert::where('kind', 'data')->first()->status);
    }
}

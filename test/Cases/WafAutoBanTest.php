<?php

namespace HyperfTest\Cases;

use App\Exception\AppException;
use App\Model\Cluster;
use App\Model\WafBan;
use App\Model\WafPolicy;
use App\Model\WafRuntimeConfig;
use App\Model\WafSignal;
use App\Services\Gateway\WafAutoBanService;
use App\Services\Gateway\WafPolicyConfig;
use App\Services\Gateway\WafRuntimeConfigRegistry;
use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Database\Connection;
use Hyperf\Database\ConnectionResolver;
use Hyperf\Database\ConnectionResolverInterface;
use HyperfTest\Stub\WafDynamicDatabase;
use HyperfTest\Stub\WafDynamicServices;
use HyperfTest\Stub\WafMemoryRedis;
use HyperfTest\Stub\WafControlHttpFixture;
use PHPUnit\Framework\TestCase;

final class WafAutoBanTest extends TestCase
{
    private Connection $db;
    private mixed $oldResolver;
    private ConfigInterface $configuration;
    private array $previous;
    private WafRuntimeConfigRegistry $registry;
    private WafAutoBanService $bans;
    private WafMemoryRedis $cache;
    private WafPolicy $policy;
    private WafRuntimeConfig $runtime;
    private Cluster $cluster;

    protected function setUp(): void
    {
        $container = ApplicationContext::getContainer();
        $this->oldResolver = $container->get(ConnectionResolverInterface::class);
        $this->db = WafDynamicDatabase::connect();
        $container->set(ConnectionResolverInterface::class, new ConnectionResolver(['default' => $this->db]));
        $this->configuration = $container->get(ConfigInterface::class);
        foreach (['enabled','collector_enabled','control_url','control_secret','max_auto_signals','max_auto_bans','max_auto_ban_records','max_control_configs'] as $key) $this->previous[$key] = $this->configuration->get('waf.' . $key);
        $this->configuration->set('waf.enabled', true);
        $this->configuration->set('waf.collector_enabled', true);
        $this->configuration->set('waf.control_url', 'http://fixture.example.test');
        $this->configuration->set('waf.control_secret', 'test-only-key-' . str_repeat('s', 40));
        $this->cache = new WafMemoryRedis(); $this->registry = WafDynamicServices::registry($this->cache);
        $this->bans = WafDynamicServices::bans($this->cache, $this->registry);
        $settings = (new WafPolicyConfig())->normalize(['mode' => 'block', 'auto_ban' => ['enabled' => true, 'attack_threshold' => 2,
            'not_found_enabled' => true, 'not_found_threshold' => 10, 'duration_seconds' => 60], 'ip_allowlist' => ['198.51.100.0/24']]);
        $this->policy = WafPolicy::create(['org_id' => 1, 'cluster_id' => 1, 'route_source' => 'project', 'route_id' => 71,
            'settings' => $settings, 'service_id' => 'fixture-service']);
        $binding = $this->registry->prepare($this->policy, $settings);
        $this->runtime = $this->registry->authenticate($binding['token']);
        $this->cluster = new Cluster(['org_id' => 1]); $this->cluster->id = 1;
    }

    protected function tearDown(): void
    {
        foreach ($this->previous as $key => $value) $this->configuration->set('waf.' . $key, $value);
        ApplicationContext::getContainer()->set(ConnectionResolverInterface::class, $this->oldResolver);
    }

    private function attack(string $id, string $ip = '192.0.2.1', int $offset = 0, string $rule = '942100'): array
    {
        $at = time() + $offset;
        return [['request_id' => $id, 'client_ip' => $ip, 'uri' => '/login', 'occurred_at' => $at, 'status_code' => 403,
            'action' => 'block', 'messages' => [['rule_id' => $rule, 'message' => 'fixture', 'tags' => []]]],
            ['id' => $id, 'ip' => $ip, 'uri' => '/login', 'status' => 403, 'origin_status' => '-', 'time' => gmdate('c', $at)]];
    }

    private function observe(array ...$requests): void
    {
        $this->bans->observe($this->policy, $this->runtime, array_column($requests, 0), array_column($requests, 1));
    }

    public function testThresholdDeduplicationCacheInvalidationAndAutomaticExpiry(): void
    {
        self::assertSame(204, $this->bans->decision($this->runtime, '192.0.2.1')['status']);
        $one = $this->attack('one'); $two = $this->attack('two');
        $this->observe($one); $this->observe($one);
        self::assertSame(1, WafSignal::count()); self::assertSame(0, WafBan::count());
        $this->observe($two);
        self::assertSame(2, WafSignal::count()); self::assertSame(1, WafBan::count());
        $ban = WafBan::first(); self::assertSame(2, $ban->events_count);
        self::assertSame(403, $this->bans->decision($this->runtime, '::ffff:192.0.2.1')['status']);
        $this->observe($two); self::assertSame($ban->expires_at, WafBan::first()->expires_at);
        // Simulate elapsed time and cache eviction; replayed old signals must not renew a ban.
        WafBan::query()->update(['expires_at' => time() - 1]);
        WafSignal::query()->update(['occurred_at' => time() - 2]); $this->cache->values = [];
        self::assertSame(204, $this->bans->decision($this->runtime, '192.0.2.1')['status']);
        $this->observe($one, $two);
        self::assertLessThanOrEqual(time(), WafBan::first()->expires_at);
        $this->observe($this->attack('three')); self::assertLessThanOrEqual(time(), WafBan::first()->expires_at);
        $this->observe($this->attack('four')); self::assertGreaterThan(time(), WafBan::first()->expires_at);
    }

    public function testReleaseResetsCountingAndRejectsOldReplayedRequests(): void
    {
        $one = $this->attack('one'); $two = $this->attack('two'); $this->observe($one, $two);
        $this->bans->release($this->cluster, WafBan::first()->id, [71], 101);
        self::assertSame('released', WafBan::first()->status); self::assertSame(101, WafBan::first()->released_by);
        self::assertSame(0, WafSignal::count());
        $this->observe($one, $two);
        self::assertSame(0, WafSignal::count()); self::assertSame('released', WafBan::first()->status);
        self::assertSame(204, $this->bans->decision($this->runtime, '192.0.2.1')['status']);
    }

    public function testOldSignalsWhitelistPassiveResponseDetectionsAndAutoBlocksCannotCauseBans(): void
    {
        $this->observe($this->attack('stale', '192.0.2.1', -3600), $this->attack('white', '198.51.100.1'),
            $this->attack('response', '192.0.2.1', 0, '12000'), $this->attack('auto', '192.0.2.1', 0, 'AUTO'),
            $this->attack('browser', '192.0.2.1', 0, 'BROWSER'));
        self::assertSame(0, WafSignal::count()); self::assertSame(0, WafBan::count());
        $events = []; $access = [];
        for ($i = 0; $i < 10; ++$i) $access[] = ['id' => 'nf-' . $i, 'ip' => '192.0.2.2', 'uri' => '/missing', 'status' => 404, 'origin_status' => '404', 'time' => gmdate('c')];
        $this->bans->observe($this->policy, $this->runtime, $events, $access);
        self::assertSame('not_found', WafBan::first()->reason); self::assertSame('192.0.2.2', WafBan::first()->client_ip);
        self::assertSame(403, $this->bans->decision($this->runtime, '192.0.2.2')['status']);
    }

    public function testServingSnapshotSurvivesDesiredPolicyEditsAndMonitorNeverBlocks(): void
    {
        $this->observe($this->attack('one'), $this->attack('two'));
        $desired = $this->policy->settings; $desired['mode'] = 'monitor'; $this->policy->settings = $desired; $this->policy->save();
        self::assertSame(403, $this->bans->decision($this->runtime, '192.0.2.1')['status']);
        $binding = $this->registry->prepare($this->policy, $desired);
        $monitor = $this->registry->authenticate($binding['token']);
        self::assertSame(204, $this->bans->decision($monitor, '192.0.2.1')['status']);
        self::assertSame('monitor', $this->bans->decision($monitor, '192.0.2.1')['action']);
        self::assertSame(2, WafRuntimeConfig::count());
    }

    public function testScopeIsolationAndCredentialRevocationAfterPolicyDeletion(): void
    {
        $this->observe($this->attack('one'), $this->attack('two'));
        self::assertSame(1, $this->bans->listing($this->cluster, [71])['total']);
        self::assertSame(0, $this->bans->listing($this->cluster, [72])['total']);
        self::assertSame(0, $this->bans->listing($this->cluster, [])['total']);
        try { $this->bans->release($this->cluster, WafBan::first()->id, [72], 102); self::fail('Cross-project release accepted'); }
        catch (AppException $e) { self::assertSame(404, $e->getCode()); }
        $binding = $this->registry->prepare($this->policy, $this->policy->settings);
        self::assertSame(1, WafRuntimeConfig::count());
        $this->policy->delete();
        // Cached authorization is bounded to five seconds; simulate eviction here.
        $this->cache->values = [];
        $this->expectException(AppException::class);
        $this->registry->authenticate($binding['token']);
    }

    public function testSignalCapacityIsBoundedAndVisible(): void
    {
        $this->configuration->set('waf.max_auto_signals', 1);
        $this->observe($this->attack('one'), $this->attack('two'));
        self::assertSame(1, WafSignal::count()); self::assertSame(0, WafBan::count());
        $result = $this->bans->listing($this->cluster, [71]);
        self::assertSame(1, $result['usage'][0]['signals']); self::assertSame(1, $result['limits']['signals']);
    }

    public function testMachineEndpointUsesHttpStatusesAndFailsClosedWithoutLeakingDetails(): void
    {
        $binding = $this->registry->prepare($this->policy, $this->policy->settings);
        $headers = ['X-Galaxy-Waf-Token' => $binding['token'], 'X-Galaxy-Waf-Client' => '192.0.2.1'];
        $response = WafControlHttpFixture::request($this->registry, $this->bans, $headers);
        self::assertSame(204, $response->getStatusCode()); self::assertSame('1', $response->getHeaderLine('X-Galaxy-Waf-Protocol'));
        $this->observe($this->attack('one'), $this->attack('two'));
        $response = WafControlHttpFixture::request($this->registry, $this->bans, $headers);
        self::assertSame(403, $response->getStatusCode()); self::assertSame('block', $response->getHeaderLine('X-Galaxy-Waf-Action'));
        self::assertSame('1', $response->getHeaderLine('X-Galaxy-Waf-Protocol'));
        self::assertGreaterThan(0, (int) $response->getHeaderLine('Retry-After')); self::assertSame('', (string) $response->getBody());
        $response = WafControlHttpFixture::request($this->registry, $this->bans, array_replace($headers, ['X-Galaxy-Waf-Token' => 'invalid']));
        self::assertSame(401, $response->getStatusCode()); self::assertSame('', (string) $response->getBody());
        $this->cache->values = [];
        $this->db->statement('DROP TABLE galaxy_waf_ban');
        $response = WafControlHttpFixture::request($this->registry, $this->bans, $headers);
        self::assertSame(503, $response->getStatusCode()); self::assertSame('', (string) $response->getBody());
    }

    public function testCacheWriteFailureReplaysSignalsWithoutExtendingTheIncident(): void
    {
        $this->bans->decision($this->runtime, '192.0.2.1');
        $keys = array_values(array_filter(array_keys($this->cache->values), static fn ($key) => str_starts_with($key, 'galaxy:waf:decision:')));
        $this->cache->failKey = $keys[0];
        try { $this->observe($this->attack('one'), $this->attack('two')); self::fail('Cache failure hidden'); }
        catch (\RuntimeException $e) { self::assertStringContainsString('cache write failed', $e->getMessage()); }
        $expires = WafBan::first()->expires_at;
        $this->cache->failKey = null;
        $this->observe($this->attack('one'), $this->attack('two'));
        self::assertSame(2, WafSignal::count()); self::assertSame($expires, WafBan::first()->expires_at);
        self::assertSame(403, $this->bans->decision($this->runtime, '192.0.2.1')['status']);
    }

    public function testSnapshotCapacityPrunesOnlyUnreferencedOldConfigurations(): void
    {
        $this->configuration->set('waf.max_control_configs', 1);
        $changed = $this->policy->settings; $changed['auto_ban']['attack_threshold'] = 3;
        try { $this->registry->prepare($this->policy, $changed); self::fail('Snapshot capacity ignored'); }
        catch (AppException $e) { self::assertSame(409, $e->getCode()); }
        WafRuntimeConfig::query()->update(['created_at' => time() - 86401]);
        $service = ['Spec' => ['Labels' => ['com.codegalaxy.component' => 'waf', 'com.codegalaxy.org.id' => '1',
            'com.codegalaxy.cluster.id' => '1', 'com.codegalaxy.waf.policy' => (string) $this->policy->id],
            'TaskTemplate' => ['ContainerSpec' => ['Env' => ['GALAXY_WAF_CONTROL_CONFIG=' . $this->runtime->config_hash]]]]];
        $this->registry->pruneUnreferenced($this->cluster, [$service]); self::assertSame(1, WafRuntimeConfig::count());
        $foreign = clone $this->cluster; $foreign->id = 2;
        $this->registry->pruneUnreferenced($foreign, []); self::assertSame(1, WafRuntimeConfig::count());
        $this->registry->pruneUnreferenced($this->cluster, []); self::assertSame(0, WafRuntimeConfig::count());
        $this->registry->prepare($this->policy, $changed); self::assertSame(1, WafRuntimeConfig::count());
    }
}

<?php

namespace HyperfTest\Cases;

use App\Exception\AppException;
use App\Model\WafDataSource;
use App\Model\WafPolicy;
use App\Services\Gateway\WafClientIp;
use App\Services\Gateway\WafDataCatalog;
use App\Services\Gateway\WafDataConfig;
use App\Services\Gateway\WafDataLease;
use App\Services\Gateway\WafDataStorage;
use App\Services\Gateway\WafDataTransport;
use App\Services\Gateway\WafDataUpdater;
use App\Services\Gateway\WafNetworkPolicy;
use App\Services\Gateway\WafPolicyConfig;
use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Database\ConnectionResolver;
use Hyperf\Database\ConnectionResolverInterface;
use HyperfTest\Stub\WafDataFixtures;
use HyperfTest\Stub\WafDynamicDatabase;
use HyperfTest\Stub\WafDynamicServices;
use HyperfTest\Stub\WafMemoryRedis;
use PHPUnit\Framework\TestCase;

final class WafDataPolicyTest extends TestCase
{
    private mixed $oldResolver;
    private ConfigInterface $config;
    private array $previous;
    private string $directory;
    private WafMemoryRedis $cache;
    private WafDataCatalog $catalog;
    private WafDataStorage $storage;
    private WafDataTransport $transport;
    private WafDataUpdater $updater;
    private WafNetworkPolicy $network;

    protected function setUp(): void
    {
        $container = ApplicationContext::getContainer();
        $this->oldResolver = $container->get(ConnectionResolverInterface::class);
        $container->set(ConnectionResolverInterface::class, new ConnectionResolver(['default' => WafDynamicDatabase::connect()]));
        $this->config = $container->get(ConfigInterface::class);
        $this->directory = '/tmp/galaxy-waf-data-test-' . bin2hex(random_bytes(8)); mkdir($this->directory, 0700);
        foreach (['enabled' => true, 'collector_enabled' => false, 'control_url' => 'http://fixture.test',
            'control_secret' => 'test-data-key-' . str_repeat('x',40), 'data_directory' => $this->directory] as $key => $value) {
            $this->previous[$key] = $this->config->get('waf.' . $key); $this->config->set('waf.' . $key, $value);
        }
        $this->cache = new WafMemoryRedis(); $this->catalog = WafDynamicServices::data($this->cache); $this->storage = WafDynamicServices::storage();
        $this->transport = new class extends WafDataTransport {
            public int $calls = 0; public string $body = "192.0.2.0/24\n"; public bool $fail = false; public int $status = 200;
            public ?\Closure $onFetch = null;
            public function __construct() {}
            public function fetch(string $url, string $path, array $conditional = []): array
            {
                ++$this->calls;
                if ($this->onFetch) ($this->onFetch)($url);
                if ($this->fail) throw new \RuntimeException('test download failure');
                file_put_contents($path, $this->status === 304 ? '' : $this->body);
                return ['status' => $this->status, 'headers' => ['ETag' => '"test-version"'], 'bytes' => strlen($this->body)];
            }
        };
        $this->updater = new WafDataUpdater($this->catalog, new WafDataConfig(), $this->storage, $this->transport, new WafDataLease($this->cache));
        $this->network = WafDynamicServices::network($this->cache);
    }

    protected function tearDown(): void
    {
        foreach ($this->previous as $key => $value) $this->config->set('waf.' . $key, $value);
        ApplicationContext::getContainer()->set(ConnectionResolverInterface::class, $this->oldResolver);
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        rmdir($this->directory);
    }

    private function source(string $key = 'test-intel', string $provider = 'custom-iplist', int $org = 1): WafDataSource
    {
        $row = $this->catalog->save($org, ['provider' => $provider, 'source_key' => $key, 'title' => 'Test feed',
            'url' => 'https://feeds.example/' . ($provider === 'custom-geoip' ? 'country.mmdb' : 'list?secret=private-value'),
            'license_url' => 'https://feeds.example/license', 'attribution' => 'Licensed test fixture', 'max_age' => 31536000], 101);
        return WafDataSource::find($row['id']);
    }

    private function snapshot(array $input): \App\Model\WafRuntimeConfig
    {
        $settings = (new WafPolicyConfig())->normalize(['mode' => 'block'] + $input);
        $policy = WafPolicy::create(['org_id' => 1, 'cluster_id' => 1, 'route_source' => 'project', 'route_id' => random_int(100,99999), 'settings' => $settings]);
        $registry = WafDynamicServices::registry($this->cache); $binding = $registry->prepare($policy, $settings);
        return $registry->authenticate($binding['token']);
    }

    private function allowNextFetch(WafDataSource $source): void
    {
        $url = (new WafDataConfig())->sourceUrl($source);
        $state = $this->storage->cached($url); $state['not_before'] = 0; $this->storage->cache($url, $state);
    }

    public function testOrganizationsShareDownloadCooldownButNeverConfigurationOrPrivateUrls(): void
    {
        $one = $this->source(); $two = $this->source(org: 2);
        $this->transport->onFetch = function ($url): void {
            $reservation = $this->storage->cached($url);
            self::assertGreaterThanOrEqual(time() + 3599, $reservation['not_before']);
            self::assertStringContainsString('未完成', $reservation['error']);
        };
        $this->updater->update($one); $this->updater->update($two);
        self::assertSame(1, $this->transport->calls); self::assertSame($one->fresh()->content_hash, $two->fresh()->content_hash);
        self::assertTrue($this->catalog->health($one->fresh())['usable']);
        $metadata = $this->storage->metadata($one->fresh()->content_hash);
        self::assertSame('Licensed test fixture', $metadata['attribution']);
        self::assertSame('https://feeds.example/license', $metadata['license_url']);
        $public = $this->catalog->listing(1, false); self::assertCount(1, $public['sources']);
        self::assertStringNotContainsString('private-value', json_encode($public)); self::assertArrayNotHasKey('url', $public['sources'][0]);
        $this->catalog->refresh(1, $one->id); $this->updater->update($one); self::assertSame(1, $this->transport->calls);
        $this->expectExceptionCode(404); $this->catalog->refresh(1, $two->id);
    }

    public function testFailedUpdatesAndInvalidReplacementKeepThePreviousValidVersion(): void
    {
        $source = $this->source(); $this->updater->update($source); $old = $source->fresh();
        $this->allowNextFetch($source); $this->transport->fail = true; $this->updater->update($source);
        self::assertSame($old->content_hash, $source->fresh()->content_hash); self::assertStringContainsString('failure', $source->fresh()->last_error);
        self::assertTrue($this->catalog->health($source->fresh())['usable']);
        $this->allowNextFetch($source); $this->transport->fail = false; $this->transport->body = '<html>not a feed</html>'; $this->updater->update($source);
        self::assertSame($old->content_hash, $source->fresh()->content_hash); self::assertNotSame('', $source->fresh()->last_error);
        self::assertFileExists($this->storage->usableFile($source->fresh()));
    }

    public function testConditionalRefreshDoesNotMakeAnOldPublishedDatabaseFreshAgain(): void
    {
        $source = $this->source('test-country', 'custom-geoip'); $this->transport->body = gzencode(WafDataFixtures::country());
        $this->updater->update($source); $published = $source->fresh()->published_at;
        self::assertTrue($this->catalog->health($source->fresh())['usable']);
        $this->allowNextFetch($source); $this->transport->status = 304; $this->updater->update($source);
        self::assertSame($published, $source->fresh()->published_at);
        WafDataSource::where('id', $source->id)->update(['published_at' => time() - 40000000]);
        self::assertFalse($this->catalog->health($source->fresh())['usable']);
    }

    public function testGeoUsesActualCountryNotRegistrationAndHandlesIpv6UnknownsAndAllowLists(): void
    {
        $source = $this->source('test-country', 'custom-geoip'); $this->transport->body = WafDataFixtures::country(); $this->updater->update($source);
        $snapshot = $this->snapshot(['geo' => ['enabled' => true, 'source' => 'test-country', 'countries' => ['GB']]]);
        foreach (['81.2.69.160','::ffff:81.2.69.160'] as $ip) { $result = $this->network->evaluate($snapshot, ['ip' => $ip, 'path' => '/']); self::assertSame('GB', $result['country']); self::assertSame(403, $result['decision']['status']); }
        $us = $this->network->evaluate($snapshot, ['ip' => '216.160.83.56', 'path' => '/']); self::assertSame('US', $us['country']); self::assertNull($us['decision']);
        self::assertSame('JP', $this->network->evaluate($snapshot, ['ip' => '2001:218::', 'path' => '/'])['country']);
        self::assertNull($this->network->evaluate($snapshot, ['ip' => '10.0.0.1', 'path' => '/'])['decision']);
        $settings = $snapshot->settings; $settings['geo']['mode'] = 'allow'; $settings['geo']['unknown'] = 'block'; $snapshot->settings = $settings;
        self::assertNull($this->network->evaluate($snapshot, ['ip' => '81.2.69.160', 'path' => '/'])['decision']);
        self::assertSame(403, $this->network->evaluate($snapshot, ['ip' => '216.160.83.56', 'path' => '/'])['decision']['status']);
        self::assertSame(403, $this->network->evaluate($snapshot, ['ip' => '10.0.0.1', 'path' => '/'])['decision']['status']);
    }

    public function testIntelWhitelistPathExceptionsMonitorAndExplicitDegradedMode(): void
    {
        $source = $this->source(); $this->updater->update($source);
        $snapshot = $this->snapshot(['intelligence' => ['enabled' => true, 'sources' => ['test-intel']], 'ip_allowlist' => ['192.0.2.1'], 'excluded_paths' => ['/healthz']]);
        self::assertSame('intel_block', $this->network->evaluate($snapshot, ['ip' => '192.0.2.9', 'path' => '/'])['decision']['action']);
        self::assertNull($this->network->evaluate($snapshot, ['ip' => '192.0.2.1', 'path' => '/'])['decision']);
        self::assertNull($this->network->evaluate($snapshot, ['ip' => '192.0.2.9', 'path' => '/healthz?secret=1'])['decision']);
        $settings = $snapshot->settings; $settings['mode'] = 'monitor'; $snapshot->settings = $settings;
        self::assertSame('intel_monitor', $this->network->evaluate($snapshot, ['ip' => '192.0.2.9', 'path' => '/'])['decision']['action']);
        WafDataSource::where('id', $source->id)->update(['validated_at' => time() - 40000000]); $this->catalog->invalidate(1, 'test-intel');
        try { $this->network->evaluate($snapshot, ['ip' => '192.0.2.9', 'path' => '/']); self::fail('Stale data silently used'); } catch (AppException $e) { self::assertSame(503, $e->getCode()); }
        $settings['intelligence']['unavailable'] = 'skip'; $snapshot->settings = $settings;
        $result = $this->network->evaluate($snapshot, ['ip' => '192.0.2.9', 'path' => '/']); self::assertNull($result['decision']); self::assertSame('degraded', $result['data_status']);
    }

    public function testSourcesRequireReadinessAndReferencedOrForeignDataCannotBeDeleted(): void
    {
        $source = $this->source();
        try { $this->snapshot(['intelligence' => ['enabled' => true, 'sources' => ['test-intel']]]); self::fail('Uninitialized feed activated'); } catch (AppException $e) { self::assertSame(409, $e->getCode()); }
        $this->updater->update($source); $this->snapshot(['intelligence' => ['enabled' => true, 'sources' => ['test-intel']]]);
        try { $this->catalog->delete(1, $source->id); self::fail('Referenced source deleted'); } catch (AppException $e) { self::assertSame(409, $e->getCode()); }
        $other = $this->source('other-source', org: 2);
        try { $this->catalog->delete(1, $other->id); self::fail('Foreign source deleted'); } catch (AppException $e) { self::assertSame(404, $e->getCode()); }
    }

    public function testGarbageCollectionPreservesReferencedAndRecentData(): void
    {
        $source = $this->source(); $this->updater->update($source); $old = $source->fresh()->content_hash;
        $this->allowNextFetch($source); $this->transport->body = "198.51.100.1\n"; $this->updater->update($source);
        $current = $source->fresh()->content_hash; self::assertNotSame($old, $current);
        touch($this->storage->object($old), time() - 604801); $work = $this->storage->temporary();
        file_put_contents($this->directory . '/unrelated.txt', 'keep'); $this->storage->prune();
        self::assertDirectoryDoesNotExist($this->storage->object($old)); self::assertDirectoryExists($this->storage->object($current));
        self::assertDirectoryExists($work); self::assertFileExists($this->directory . '/unrelated.txt');
    }
}

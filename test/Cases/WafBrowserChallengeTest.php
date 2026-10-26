<?php

namespace HyperfTest\Cases;

use App\Exception\AppException;
use App\Model\WafPolicy;
use App\Model\WafRuntimeConfig;
use App\Services\Gateway\WafBrowserChallenge;
use App\Services\Gateway\WafBrowserToken;
use App\Services\Gateway\WafControlConfig;
use App\Services\Gateway\WafClientIp;
use App\Services\Gateway\WafPolicyConfig;
use App\Services\Gateway\WafLogParser;
use App\Services\Gateway\WafRuntimeConfigRegistry;
use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Database\ConnectionResolver;
use Hyperf\Database\ConnectionResolverInterface;
use HyperfTest\Stub\WafControlHttpFixture;
use HyperfTest\Stub\WafDynamicDatabase;
use HyperfTest\Stub\WafDynamicServices;
use HyperfTest\Stub\WafMemoryRedis;
use PHPUnit\Framework\TestCase;

final class WafBrowserChallengeTest extends TestCase
{
    private mixed $previousResolver;
    private ConfigInterface $config;
    private array $previous = [];
    private WafMemoryRedis $cache;
    private WafBrowserChallenge $browser;
    private WafRuntimeConfigRegistry $registry;
    private WafRuntimeConfig $snapshot;
    private WafPolicy $policy;
    private array $binding;
    private array $client = ['ip' => '192.0.2.1', 'host' => 'web.example.test', 'ua' => 'Galaxy-Browser-Test',
        'path' => '/page', 'method' => 'GET', 'accept' => 'text/html'];

    protected function setUp(): void
    {
        $container = ApplicationContext::getContainer();
        $this->previousResolver = $container->get(ConnectionResolverInterface::class);
        $container->set(ConnectionResolverInterface::class, new ConnectionResolver(['default' => WafDynamicDatabase::connect()]));
        $this->config = $container->get(ConfigInterface::class);
        foreach (['enabled' => true, 'collector_enabled' => false, 'control_url' => 'http://fixture.test',
            'control_secret' => 'test-only-browser-key-' . str_repeat('b', 40), 'challenge_policy_rate' => 1200, 'challenge_client_rate' => 20] as $key => $value) {
            $this->previous[$key] = $this->config->get('waf.' . $key); $this->config->set('waf.' . $key, $value);
        }
        $this->cache = new WafMemoryRedis(); $this->registry = WafDynamicServices::registry($this->cache);
        $this->browser = WafDynamicServices::browser($this->cache);
        $settings = (new WafPolicyConfig())->normalize(['mode' => 'block', 'browser' => ['enabled' => true, 'difficulty' => 12]]);
        $this->policy = WafPolicy::create(['org_id' => 1, 'cluster_id' => 1, 'route_source' => 'project', 'route_id' => 71, 'settings' => $settings]);
        $this->binding = $this->registry->prepare($this->policy, $settings);
        $this->snapshot = $this->registry->authenticate($this->binding['token']);
    }

    protected function tearDown(): void
    {
        foreach ($this->previous as $key => $value) $this->config->set('waf.' . $key, $value);
        ApplicationContext::getContainer()->set(ConnectionResolverInterface::class, $this->previousResolver);
    }

    private function answer(array $challenge): string
    {
        for ($answer = 0; $answer < 1000000; ++$answer) if (WafBrowserChallenge::proof($challenge['nonce'], (string) $answer, $challenge['difficulty'])) return (string) $answer;
        self::fail('Fixture proof was not found');
    }

    private function headers(): array
    {
        return ['X-Galaxy-Waf-Token' => $this->binding['token'], 'X-Galaxy-Waf-Client' => $this->client['ip'],
            'X-Galaxy-Waf-Host' => $this->client['host'], 'X-Galaxy-Waf-Agent' => $this->client['ua'],
            'X-Galaxy-Waf-Method' => 'GET', 'X-Galaxy-Waf-Accept' => 'text/html', 'X-Galaxy-Waf-Path' => '/page', 'X-Galaxy-Waf-Scheme' => 'https'];
    }

    public function testBrowserCompletesOneUseProofAndGetsBoundHttpOnlyClearance(): void
    {
        $bans = WafDynamicServices::bans($this->cache, $this->registry); $headers = $this->headers();
        $probe = WafControlHttpFixture::request($this->registry, $bans, $headers + ['X-Galaxy-Waf-Probe' => '1'], browser: $this->browser);
        self::assertSame(204, $probe->getStatusCode());
        self::assertCount(0, array_filter(array_keys($this->cache->values), static fn ($key) => str_contains($key, ':challenge:nonce:')));
        $check = WafControlHttpFixture::request($this->registry, $bans, $headers, browser: $this->browser);
        self::assertSame(401, $check->getStatusCode()); self::assertSame('1', $check->getHeaderLine('X-Galaxy-Waf-Protocol'));
        $page = WafControlHttpFixture::request($this->registry, $bans, $headers, 'challenge', browser: $this->browser);
        self::assertSame(401, $page->getStatusCode()); self::assertSame('no-store', $page->getHeaderLine('Cache-Control'));
        self::assertStringContainsString("frame-ancestors 'none'", $page->getHeaderLine('Content-Security-Policy'));
        self::assertStringNotContainsString($this->binding['token'], (string) $page->getBody());
        preg_match('/const challenge=(\{[^\n]+\});/', (string) $page->getBody(), $match);
        $challenge = json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);
        $input = ['token' => $challenge['token'], 'answer' => $this->answer($challenge)];
        $solved = WafControlHttpFixture::request($this->registry, $bans, $headers, 'solve', $input, $this->browser);
        self::assertSame(200, $solved->getStatusCode()); self::assertSame(['ok' => true], json_decode((string) $solved->getBody(), true));
        $cookie = $solved->getHeaderLine('Set-Cookie'); self::assertStringContainsString('; HttpOnly; SameSite=Lax; Secure', $cookie);
        $clearance = explode('=', explode(';', $cookie)[0], 2)[1];
        $check = WafControlHttpFixture::request($this->registry, $bans, $headers + ['X-Galaxy-Waf-Clearance' => $clearance], browser: $this->browser);
        self::assertSame(204, $check->getStatusCode());
        foreach (['ip' => '192.0.2.2', 'host' => 'other.example.test', 'ua' => 'Different-Browser'] as $key => $value) {
            self::assertSame(401, $this->browser->decision($this->snapshot, array_replace($this->client, [$key => $value, 'clearance' => $clearance]))['status']);
        }
        $other = clone $this->snapshot; $other->policy_id++;
        self::assertSame(401, $this->browser->decision($other, $this->client + ['clearance' => $clearance])['status']);
        $replayed = WafControlHttpFixture::request($this->registry, $bans, $headers, 'solve', $input, $this->browser);
        self::assertSame(403, $replayed->getStatusCode()); self::assertSame('', $replayed->getHeaderLine('Set-Cookie'));
    }

    public function testWrongProofConsumesTheNonceAndCannotBeRetried(): void
    {
        $challenge = $this->browser->issue($this->snapshot, $this->client); $answer = $this->answer($challenge);
        foreach (['not-a-counter', $answer] as $attempt) {
            try { $this->browser->solve($this->snapshot, $this->client, $challenge['token'], $attempt); self::fail('Proof accepted after failed attempt'); }
            catch (AppException $e) { self::assertSame(403, $e->getCode()); }
        }
    }

    public function testApiMethodsStreamingAndPathExceptionsHaveExplicitBehavior(): void
    {
        self::assertSame(403, $this->browser->decision($this->snapshot, array_replace($this->client, ['method' => 'POST']))['status']);
        self::assertSame(403, $this->browser->decision($this->snapshot, array_replace($this->client, ['accept' => 'application/json']))['status']);
        self::assertSame(403, $this->browser->decision($this->snapshot, $this->client + ['upgrade' => 'websocket'])['status']);
        self::assertSame(403, $this->browser->decision($this->snapshot, array_replace($this->client, ['accept' => 'text/event-stream']))['status']);
        $settings = $this->snapshot->settings; $settings['browser']['excluded_paths'] = ['/healthz'];
        $settings['browser']['excluded_prefixes'] = ['/api']; $this->snapshot->settings = $settings;
        foreach (['/healthz?secret=value', '/api', '/api/v1', '/foo/../api/v1', '/%61pi/v1'] as $path) self::assertNull($this->browser->decision($this->snapshot, array_replace($this->client, ['path' => $path])));
        self::assertNull($this->browser->decision($this->snapshot, array_replace($this->client, ['path' => '/api/socket', 'upgrade' => 'websocket'])));
        self::assertNull($this->browser->decision($this->snapshot, array_replace($this->client, ['path' => '/api/stream', 'accept' => 'text/event-stream'])));
        self::assertSame(401, $this->browser->decision($this->snapshot, array_replace($this->client, ['path' => '/apix']))['status']);
        $settings['browser']['non_browser'] = 'bypass'; $this->snapshot->settings = $settings;
        self::assertNull($this->browser->decision($this->snapshot, array_replace($this->client, ['method' => 'POST'])));
        $settings['mode'] = 'monitor'; $this->snapshot->settings = $settings;
        self::assertSame(['status' => 204, 'action' => 'browser_monitor', 'retry_after' => 0], $this->browser->decision($this->snapshot, $this->client));
    }

    public function testCaptchaImageAndAnswerValidationDoNotExposeTheAnswerOrMachineCredential(): void
    {
        if (! extension_loaded('gd')) self::markTestSkipped('GD is required for captcha');
        $settings = $this->snapshot->settings; $settings['browser']['type'] = 'captcha'; $this->snapshot->settings = $settings;
        $challenge = $this->browser->issue($this->snapshot, $this->client);
        $size = getimagesizefromstring(base64_decode(substr($challenge['image'], strlen('data:image/png;base64,'))));
        self::assertSame([210,66], array_slice($size, 0, 2)); self::assertSame('image/png', $size['mime']);
        $key = 'galaxy:waf:challenge:nonce:' . $challenge['nonce'];
        $state = json_decode($this->cache->values[$key], true); $state['answer'] = hash_hmac('sha256', '123456', $challenge['nonce']);
        $this->cache->values[$key] = json_encode($state);
        self::assertArrayHasKey('token', $this->browser->solve($this->snapshot, $this->client, $challenge['token'], '123456'));
    }

    public function testClientRateLimitCannotBeBypassedByChangingUserAgent(): void
    {
        $this->config->set('waf.challenge_client_rate', 1);
        $this->browser->issue($this->snapshot, $this->client);
        $this->expectExceptionCode(429);
        $this->browser->issue($this->snapshot, array_replace($this->client, ['ua' => 'Different-Agent']));
    }

    public function testPolicyCapacityIsCheckedBeforeAllocatingAnotherClientCounter(): void
    {
        $this->config->set('waf.challenge_policy_rate', 2);
        foreach (['192.0.2.1','192.0.2.2','192.0.2.3'] as $i => $ip) {
            try { $this->browser->issue($this->snapshot, array_replace($this->client, ['ip' => $ip])); self::assertLessThan(2, $i); }
            catch (AppException $e) { self::assertSame(2, $i); self::assertSame(429, $e->getCode()); }
        }
        self::assertCount(2, array_filter(array_keys($this->cache->values), static fn ($key) => str_contains($key, ':rate:client:')));
    }

    public function testExpiredFutureAndCrossPurposeTokensAreRejected(): void
    {
        $tokens = new WafBrowserToken(new WafControlConfig(), new WafClientIp());
        $context = $tokens->context($this->snapshot, $this->client);
        foreach ([[-100,-1], [60,180]] as [$issued,$expiry]) {
            $claims = ['s' => $context['s'], 'c' => $context['c'], 'iat' => time() + $issued, 'exp' => time() + $expiry];
            $payload = rtrim(strtr(base64_encode(json_encode($claims)), '+/', '-_'), '=');
            $mac = rtrim(strtr(base64_encode(hash_hmac('sha256', 'galaxy-waf-browser-v1:clearance:' . $payload, $this->config->get('waf.control_secret'), true)), '+/', '-_'), '=');
            self::assertSame(401, $this->browser->decision($this->snapshot, $this->client + ['clearance' => $payload . '.' . $mac])['status']);
        }
        $challenge = $this->browser->issue($this->snapshot, $this->client);
        self::assertSame(401, $this->browser->decision($this->snapshot, $this->client + ['clearance' => $challenge['token']])['status']);
        $this->cache->expires['galaxy:waf:challenge:nonce:' . $challenge['nonce']] = time() - 1;
        $this->expectExceptionCode(403);
        $this->browser->solve($this->snapshot, $this->client, $challenge['token'], $this->answer($challenge));
    }

    public function testBrowserEventsAreReportedWithTheirOwnRuleAndAction(): void
    {
        $stream = json_encode(['kind' => 'galaxy_waf_access', 'id' => 'one', 'ip' => '192.0.2.1', 'uri' => '/page?secret=value', 'status' => 401, 'dynamic' => 'browser_challenge']);
        $events = (new WafLogParser())->parse($stream, 'block');
        self::assertSame('challenge', $events[0]['action']); self::assertSame('BROWSER', $events[0]['messages'][0]['rule_id']);
        self::assertSame('/page', $events[0]['uri']); self::assertStringNotContainsString('secret', json_encode($events));
    }

    public function testEncodedControlBytesAndBackslashesAreRejectedBeforeMatchingExceptions(): void
    {
        foreach (['/api%0afoo', '/api%00foo', '/api%5cfoo'] as $path) {
            try { $this->browser->decision($this->snapshot, array_replace($this->client, ['path' => $path])); self::fail('Unsafe path accepted'); }
            catch (AppException $e) { self::assertSame(400, $e->getCode()); }
        }
    }

    public function testMissingBrowserAssetFailsTheReadinessCheck(): void
    {
        $this->expectExceptionCode(503);
        (new \App\Services\Gateway\WafChallengePage('/tmp/missing-galaxy-proof-' . bin2hex(random_bytes(8))))->assertAvailable();
    }
}

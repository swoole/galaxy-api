<?php

namespace HyperfTest\Cases;

use App\Exception\AppException;
use App\Services\Gateway\WafClientIp;
use App\Services\Gateway\WafControlConfig;
use App\Services\Gateway\WafControlCredential;
use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ConfigInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WafControlConfigTest extends TestCase
{
    private ConfigInterface $config;
    private array $previous;

    protected function setUp(): void
    {
        $this->config = ApplicationContext::getContainer()->get(ConfigInterface::class);
        foreach (['control_url','control_secret'] as $key) $this->previous[$key] = $this->config->get('waf.' . $key);
        $this->config->set('waf.control_url', 'http://control.example.test:9501');
        $this->config->set('waf.control_secret', 'test-only-waf-key-' . str_repeat('k', 40));
    }

    protected function tearDown(): void { foreach ($this->previous as $key => $value) $this->config->set('waf.' . $key, $value); }

    public function testCredentialsAreScopedAndTamperingAndRotationInvalidateThem(): void
    {
        $credentials = new WafControlCredential(new WafControlConfig());
        $hash = hash('sha256', 'fixture'); $token = $credentials->issue(1, 2, 3, $hash);
        self::assertSame(['v' => 1, 'org' => 1, 'cluster' => 2, 'policy' => 3, 'hash' => $hash], $credentials->verify($token));
        self::assertSame($token, $credentials->issue(1, 2, 3, $hash));
        $other = $credentials->issue(2, 2, 3, $hash);
        self::assertNotSame($token, $other);
        [$payload,$signature] = explode('.', $token);
        $forgedPayload = rtrim(strtr(base64_encode(json_encode(['v' => 1, 'org' => 2, 'cluster' => 2, 'policy' => 3, 'hash' => $hash])), '+/', '-_'), '=');
        foreach ([$forgedPayload . '.' . $signature, $payload . '.' . str_repeat('x', 43), str_repeat('x', 2000)] as $invalid) {
            try { $credentials->verify($invalid); self::fail('Forged token accepted'); }
            catch (AppException $e) { self::assertSame(401, $e->getCode()); }
        }
        $this->config->set('waf.control_secret', str_repeat('rotated-key', 8));
        $this->expectException(AppException::class);
        $credentials->verify($token);
    }

    public function testControlUrlAcceptsSafeInternalAddressesAndNoCredentials(): void
    {
        $control = new WafControlConfig();
        self::assertSame('http://control.example.test:9501/internal/waf/check', $control->endpoint());
        $this->config->set('waf.control_url', 'https://[2001:db8::1]:9501/api/');
        self::assertSame('https://[2001:db8::1]:9501/api/internal/waf/check', $control->endpoint());
        $this->config->set('waf.control_secret', '');
        $this->expectException(AppException::class);
        $control->secret();
    }

    public static function invalidUrls(): array
    {
        return [[''], ['file:///etc/passwd'], ['ftp://example.test'], ['http://user:password@example.test'],
            ['http://example.test?token=value'], ['http://example.test/#fragment'], ["http://example.test/'; return 200; #"],
            ['http://example.test/${value}'], ['http://example.test:99999'], ["http://example.test/\nnginx"]];
    }

    #[DataProvider('invalidUrls')]
    public function testUnsafeUrlsAreRejected(string $url): void
    {
        $this->config->set('waf.control_url', $url);
        $this->expectException(AppException::class);
        (new WafControlConfig())->endpoint();
    }

    public function testClientIpCanonicalizationAndCidrBoundaries(): void
    {
        $ips = new WafClientIp();
        self::assertSame('192.0.2.1', $ips->normalize('::ffff:192.0.2.1'));
        self::assertSame('2001:db8::1', $ips->normalize('2001:DB8:0:0:0:0:0:1'));
        self::assertTrue($ips->matches('192.0.2.255', ['192.0.2.0/24']));
        self::assertFalse($ips->matches('192.0.3.1', ['192.0.2.0/24']));
        self::assertTrue($ips->matches('192.0.2.55', ['::ffff:192.0.2.0/120']));
        self::assertTrue($ips->matches('2001:db8:abcd::1', ['2001:db8::/32']));
        self::assertFalse($ips->matches('2001:db9::1', ['2001:db8::/32']));
        self::assertFalse($ips->matches('192.0.2.1', ['2001:db8::/32', '192.0.2.0/garbage']));
        self::assertFalse($ips->matches('not-an-ip', ['0.0.0.0/0']));
    }
}

<?php

namespace HyperfTest\Cases;

use App\Exception\AppException;
use App\Services\Gateway\WafClientIp;
use App\Services\Gateway\WafCountries;
use App\Services\Gateway\WafDataConfig;
use App\Services\Gateway\WafDataTransport;
use App\Services\Gateway\WafIpIndex;
use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ConfigInterface;
use PHPUnit\Framework\TestCase;

final class WafDataPrimitiveTest extends TestCase
{
    private string $directory;
    private ConfigInterface $config;
    private array $previous;
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/galaxy-waf-ip-index-' . bin2hex(random_bytes(8)); mkdir($this->directory, 0700);
        $this->config = ApplicationContext::getContainer()->get(ConfigInterface::class);
        foreach (['data_trusted_hosts','data_max_entries'] as $key) $this->previous[$key] = $this->config->get('waf.' . $key);
        $this->config->set('waf.data_trusted_hosts', []); $this->config->set('waf.data_max_entries', 100000);
    }
    protected function tearDown(): void
    {
        foreach ($this->previous as $key => $value) $this->config->set('waf.' . $key, $value);
        foreach (glob($this->directory . '/*') as $file) unlink($file); rmdir($this->directory);
    }
    private function index(string $body): array
    {
        file_put_contents($this->directory . '/raw', $body); $index = new WafIpIndex(new WafClientIp());
        return [$index, $index->build($this->directory . '/raw', $this->directory . '/index')];
    }

    public function testIndexMergesOverlapsAdjacencyAndMappedAddressesWithoutCrossingFamilies(): void
    {
        [$index,$metadata] = $this->index("# comment\n192.0.2.4/30\n192.0.2.1/32\n192.0.2.0/30\n192.0.2.8/31\n::ffff:192.0.2.1/128\n198.51.100.9\n2001:db8::/126\n2001:db8::4/126\nffff::/16\n");
        self::assertSame(4, $metadata['record_count']);
        foreach (['192.0.2.0','192.0.2.9','::ffff:192.0.2.9','198.51.100.9','2001:db8::','2001:db8::7','ffff:ffff:ffff:ffff:ffff:ffff:ffff:ffff'] as $ip) self::assertTrue($index->contains($this->directory . '/index', $ip), $ip);
        foreach (['0.0.0.0','192.0.1.255','192.0.2.10','198.51.100.8','2001:db8::8','::1','fffe:ffff::'] as $ip) self::assertFalse($index->contains($this->directory . '/index', $ip), $ip);
    }

    public function testSpamhausMetadataAndBoundedListsAreValidated(): void
    {
        [, $metadata] = $this->index(json_encode(['cidr' => '203.0.113.0/24', 'sblid' => 'TEST']) . "\n" . json_encode(['type' => 'metadata', 'timestamp' => time(), 'copyright' => 'Test source copyright']) . "\n");
        self::assertSame('Test source copyright', $metadata['copyright']); self::assertGreaterThan(0, $metadata['published_at']);
        foreach (["0.0.0.0/0\n", "::/0\n", "192.0.2.0/33\n", "::ffff:192.0.2.0/95\n", "<html>error</html>", "# empty\n"] as $bad) {
            try { $this->index($bad); self::fail('Invalid list accepted'); } catch (AppException $e) { self::assertSame(422, $e->getCode()); }
        }
        $this->config->set('waf.data_max_entries', 1);
        $this->expectExceptionCode(422); $this->index("192.0.2.1\n192.0.2.2\n");
    }

    public function testDownloaderRejectsPrivateReservedMixedDnsAndNumericHostForms(): void
    {
        $transport = new class(new WafDataConfig(), new WafClientIp()) extends WafDataTransport {
            public array $answers = ['8.8.8.8'];
            protected function lookup(string $host): array { return $this->answers; }
        };
        self::assertSame('8.8.8.8', $transport->target('https://public.example/data?key=secret')['address']);
        foreach (['http://public.example/list','https://127.0.0.1/x','https://[::1]/x','https://[::ffff:127.0.0.1]/x',
            'https://100.64.0.1/x','https://192.0.2.1/x','https://[64:ff9b::7f00:1]/x','https://224.0.0.1/x',
            'https://0x7f000001/x','https://127.1/x','https://0177.0.0.1/x','https://user:pass@public.example/x','https://public.example/#fragment'] as $url) {
            try { $transport->target($url); self::fail('Unsafe target accepted: ' . $url); } catch (AppException $e) { self::assertSame(422, $e->getCode()); }
        }
        $transport->answers = ['8.8.8.8','10.0.0.1'];
        try { $transport->target('https://public.example/list'); self::fail('Mixed DNS accepted'); } catch (AppException $e) { self::assertSame(422, $e->getCode()); }
        $this->config->set('waf.data_trusted_hosts', ['127.0.0.1']);
        self::assertSame('127.0.0.1', $transport->target('http://127.0.0.1:9000/list')['address']);
    }

    public function testKnownProviderTermsAndKeysCannotBeOverriddenAndCountriesAreBounded(): void
    {
        $config = new WafDataConfig();
        $preset = $config->normalize(['provider' => 'spamhaus-drop-v4', 'source_key' => 'fake', 'url' => 'https://other.example/', 'license_url' => 'https://other.example/', 'attribution' => 'fake']);
        self::assertSame('spamhaus-drop-v4', $preset['source_key']); self::assertSame('', $preset['url']);
        self::assertSame(WafDataConfig::PRESETS['spamhaus-drop-v4']['license_url'], $preset['license_url']);
        self::assertSame(250, count(WafCountries::codes())); self::assertTrue(WafCountries::valid('CN')); self::assertTrue(WafCountries::valid('XK')); self::assertFalse(WafCountries::valid('ZZ'));
        $this->expectExceptionCode(422); $config->normalize(['provider' => 'dbip-country', 'max_age' => 99999999]);
    }

    public function testMalformedProviderInputIsRejectedWithoutInternalTypeErrors(): void
    {
        $this->expectExceptionCode(422);
        (new WafDataConfig())->normalize(['provider' => ['custom-iplist']]);
    }
}

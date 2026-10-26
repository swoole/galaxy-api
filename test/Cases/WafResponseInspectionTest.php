<?php

namespace HyperfTest\Cases;

use App\Exception\AppException;
use App\Services\Gateway\WafConfigRenderer;
use App\Services\Gateway\WafPolicyConfig;
use App\Services\Gateway\WafResponseInspection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WafResponseInspectionTest extends TestCase
{
    public function testResponseInspectionIsBoundedOptionalAndNeverAddsDenyActions(): void
    {
        $config = new WafPolicyConfig(); $renderer = new WafConfigRenderer();
        $off = $config->normalize([]);
        self::assertContains('MODSEC_RESP_BODY_ACCESS=Off', $renderer->environment($off));
        self::assertSame('', (new WafResponseInspection())->rules($off['response']));
        $policy = $config->normalize(['mode' => 'block', 'response' => ['enabled' => true, 'excluded_paths' => ['/export', '/export']]]);
        self::assertSame($policy, $config->normalize($policy));
        self::assertSame(['/export'], $policy['response']['excluded_paths']);
        self::assertContains('MODSEC_RESP_BODY_ACCESS=On', $renderer->environment($policy));
        self::assertContains('MODSEC_RESP_BODY_LIMIT=65536', $renderer->environment($policy));
        self::assertContains('MODSEC_RESP_BODY_LIMIT_ACTION=ProcessPartial', $renderer->environment($policy));
        self::assertContains('MODSEC_RESP_BODY_MIMETYPE=text/plain text/html application/json', $renderer->environment($policy));
        $rules = (new WafResponseInspection())->rules($policy['response']);
        self::assertStringContainsString('id:12000,phase:4,t:none,pass,log', $rules);
        self::assertStringContainsString('id:12100,phase:1', $rules);
        self::assertStringContainsString('Content-Disposition', $rules);
        self::assertStringNotContainsString('deny', $rules);
        self::assertStringNotContainsString('ctl:ruleEngine=Off', $rules);
        self::assertStringNotContainsString('MATCHED_VAR', $rules);
    }

    public function testRuleIdsAreStableWhenOtherTypesAreDisabled(): void
    {
        $config = new WafPolicyConfig();
        $policy = $config->normalize(['response' => ['enabled' => true, 'types' => ['php_source']]]);
        $rules = (new WafResponseInspection())->rules($policy['response']);
        self::assertStringContainsString('id:12003,phase:4', $rules);
        self::assertStringNotContainsString('id:12000,', $rules);
    }

    public static function invalidSettings(): array
    {
        return [[['enabled' => 'true']], [['limit' => 0]], [['limit' => 262145]], [['types' => []]],
            [['types' => ['custom-regex']]], [['excluded_paths' => ["/ok\nSecRuleEngine Off"]]],
            [['excluded_paths' => array_fill(0, 101, '/export')]]];
    }

    #[DataProvider('invalidSettings')]
    public function testInvalidSettingsAreRejected(array $response): void
    {
        $this->expectException(AppException::class);
        (new WafPolicyConfig())->normalize(['response' => $response]);
    }
}

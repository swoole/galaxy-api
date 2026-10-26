<?php

namespace HyperfTest\Cases;

use App\Exception\AppException;
use App\Services\Gateway\WafConfigRenderer;
use App\Services\Gateway\WafLogParser;
use App\Services\Gateway\WafPolicyConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WafPolicyTest extends TestCase
{
    public function testPolicyIsBoundedAndNormalizesCidrsAndRuleIds(): void
    {
        $p = (new WafPolicyConfig())->normalize(['ip_allowlist' => ['2001:db8::/32', '192.0.2.1', '192.0.2.1'],
            'excluded_rule_ids' => ['942100']]);
        self::assertSame(['2001:db8::/32', '192.0.2.1'], $p['ip_allowlist']);
        self::assertSame([942100], $p['excluded_rule_ids']);
        self::assertSame('monitor', $p['mode']);
    }

    public static function invalidPolicies(): array
    {
        return [
            [['mode' => 'On']], [['ip_denylist' => ['0.0.0.0/33']]], [['ip_allowlist' => ['::/129']]],
            [['ip_allowlist' => [123]]], [['paranoia' => 5]], [['body_limit' => 0]], [['cc_enabled' => 'false']],
            [['excluded_paths' => ["/ok\nSecRuleEngine Off"]]], [['excluded_paths' => ['/a"b']]],
            [['excluded_paths' => ['/a\\b']]], [['excluded_paths' => ['/a${HOME}']]],
            [['excluded_paths' => ['/a%{REMOTE_ADDR}']]], [['excluded_paths' => ['no-slash']]],
            [['excluded_rule_ids' => ['10000']]], [['categories' => ['unknown']]],
            [['blocked_extensions' => ['php|.*']]], [['custom_rules' => [['field' => 'uri', 'operator' => 'contains', 'value' => "'", 'action' => 'deny']]]],
            [['allowed_methods' => []]], [['allowed_methods' => ['GET|.*']]],
            [['rule_exclusions' => [['path' => '/api', 'rule_id' => 10000]]]],
            [['rule_exclusions' => [['path' => "/api\nSecRuleEngine Off", 'rule_id' => 942100]]]],
            [['path_limits' => array_fill(0, 11, ['path' => '/api', 'match' => 'exact', 'rate' => 5, 'burst' => 1])]],
            [['path_limits' => [['path' => '/api', 'match' => 'regex', 'rate' => 5, 'burst' => 1]]]],
            [['path_limits' => [['path' => '/a${ip}', 'match' => 'exact', 'rate' => 5, 'burst' => 1]]]],
            [['path_limits' => [['path' => '/api', 'match' => 'exact', 'rate' => 0, 'burst' => 1]]]],
            [['custom_rules' => [['action' => 'deny', 'conditions' => []]]]],
            [['custom_rules' => [['action' => 'deny', 'conditions' => array_fill(0, 6, ['field' => 'uri', 'operator' => 'equals', 'value' => '/'])]]]],
            [['custom_rules' => [['action' => 'deny', 'enabled' => 'false', 'field' => 'uri', 'operator' => 'equals', 'value' => '/']]]],
            [['custom_rules' => [['action' => 'deny', 'conditions' => [['field' => 'header', 'name' => 'X-Test|.*', 'operator' => 'equals', 'value' => 'bad']]]]]],
            [['custom_rules' => [['action' => 'deny', 'conditions' => [['field' => 'cookie', 'name' => 'id\n', 'operator' => 'equals', 'value' => 'bad']]]]]],
            [['ip_bans' => [['ip' => '192.0.2.1', 'expires_at' => 0]]]],
            [['ip_bans' => [['ip' => 'bad-ip', 'expires_at' => 2000000000]]]],
            [['ip_bans' => [['ip' => '192.0.2.1', 'expires_at' => 9999999999]]]],
            [['ip_bans' => [['ip' => '192.0.2.1', 'expires_at' => 1, 'reason' => "line\nbreak"]]]],
            [['ip_bans' => [['ip' => '192.0.2.0/33', 'expires_at' => 1]]]],
            [['alerts' => ['enabled' => 'true']]], [['alerts' => ['cooldown_seconds' => 0]]],
            [['alerts' => ['channels' => []]]], [['alerts' => ['channels' => ['webhook']]]],
            [['alerts' => ['window_seconds' => 3601]]], [['alerts' => ['actions' => ['anything']]]],
            [['auto_ban' => 'on']], [['auto_ban' => ['enabled' => 'true']]],
            [['auto_ban' => ['enabled' => true, 'attack_enabled' => false, 'not_found_enabled' => false]]],
            [['auto_ban' => ['window_seconds' => 59]]], [['auto_ban' => ['window_seconds' => 3601]]],
            [['auto_ban' => ['attack_threshold' => 1]]], [['auto_ban' => ['not_found_threshold' => 9]]],
            [['auto_ban' => ['duration_seconds' => 86401]]], [['auto_ban' => ['excluded_paths' => ['/x${ip}']]]],
            [['browser' => 'yes']], [['browser' => ['enabled' => 'true']]], [['browser' => ['type' => 'cookie']]],
            [['browser' => ['difficulty' => 11]]], [['browser' => ['difficulty' => 21]]],
            [['browser' => ['challenge_seconds' => 301]]], [['browser' => ['clearance_seconds' => 0]]],
            [['browser' => ['non_browser' => 'ignore']]], [['browser' => ['excluded_prefixes' => ['/a${uri}']]]],
            [['browser' => ['excluded_paths' => array_fill(0, 51, '/api')]]],
        ];
    }

    #[DataProvider('invalidPolicies')]
    public function testPolicyRejectsInvalidOrInjectableValues(array $input): void
    {
        $this->expectException(AppException::class);
        (new WafPolicyConfig())->normalize($input);
    }

    public function testEngineConfigChecksBodiesAndKeepsStreamingAndWebsockets(): void
    {
        $p = (new WafPolicyConfig())->normalize(['mode' => 'block', 'categories' => ['xss'],
            'ip_allowlist' => ['192.0.2.1'], 'ip_denylist' => ['192.0.2.2'], 'cc_enabled' => true,
            'excluded_paths' => ['/hook'], 'custom_rules' => [['field' => 'args', 'operator' => 'equals', 'value' => 'blocked', 'action' => 'deny']]]);
        $renderer = new WafConfigRenderer();
        $files = $renderer->render($p, 'http://app:9501', true, '10.0.4.0/24');
        self::assertStringContainsString('modsecurity on;', $files['nginx.conf']);
        self::assertStringContainsString('real_ip_recursive off;', $files['nginx.conf']);
        self::assertStringContainsString('proxy_buffering off;', $files['nginx.conf']);
        self::assertStringContainsString('proxy_set_header Upgrade $http_upgrade;', $files['nginx.conf']);
        self::assertStringContainsString('proxy_ssl_verify on;', $files['nginx.conf']);
        self::assertStringContainsString('limit_req_dry_run off;', $files['nginx.conf']);
        self::assertStringContainsString('SecRuleRemoveByTag attack-sqli', $files['setup.conf']);
        self::assertStringNotContainsString('SecRuleRemoveByTag attack-xss', $files['setup.conf']);
        self::assertLessThan(strpos($files['rules.conf'], '@ipMatch 192.0.2.1'), strpos($files['rules.conf'], '@ipMatch 192.0.2.2'));
        self::assertMatchesRegularExpression('/SecRule ARGS "@streq blocked" "id:\\d+,phase:2/', $files['rules.conf']);
        self::assertContains('MODSEC_AUDIT_LOG_PARTS=AHZ', $renderer->environment($p));
        self::assertContains('MODSEC_RULE_ENGINE=On', $renderer->environment($p));
    }

    public function testMonitorModeDoesNotEnforceRateLimit(): void
    {
        $p = (new WafPolicyConfig())->normalize(['cc_enabled' => true]);
        $renderer = new WafConfigRenderer();
        self::assertStringContainsString('limit_req_dry_run on;', $renderer->render($p, 'http://app:80', true, '10.0.4.0/24')['nginx.conf']);
        self::assertContains('MODSEC_RULE_ENGINE=DetectionOnly', $renderer->environment($p));
    }

    public function testTemporaryBansExpireInEngineWithoutAConfigurationChange(): void
    {
        $config = new WafPolicyConfig();
        $policy = $config->normalize(['ip_allowlist' => ['192.0.2.1'], 'ip_bans' => [
            ['ip' => '192.0.2.1', 'expires_at' => time() + 3600, 'reason' => 'temporary'],
            ['ip' => '2001:db8::/32', 'expires_at' => 1],
        ]]);
        self::assertSame($policy, $config->normalize($policy));
        $rules = (new WafConfigRenderer())->render($policy, 'http://app:80', true, '10.0.4.0/24')['rules.conf'];
        self::assertStringContainsString('SecRule TIME_EPOCH "@lt 1" "t:none,setvar:\'tx.galaxy_ip_denied=1\'"', $rules);
        self::assertStringContainsString('SecRule &TX:galaxy_ip_denied "@eq 0" "t:none,ctl:ruleEngine=Off"', $rules);
        self::assertStringContainsString("tag:'galaxy-temp-ban',chain", $rules);
        self::assertLessThan(strpos($rules, 'ctl:ruleEngine=Off'), strpos($rules, 'Galaxy temporary IP ban'));
    }

    public function testLegacyRulesNormalizeToIdempotentCompoundRulesAndPresetsPreserveTuning(): void
    {
        $config = new WafPolicyConfig();
        $policy = $config->normalize(['custom_rules' => [['field' => 'uri', 'operator' => 'equals', 'value' => '/blocked', 'action' => 'deny']]]);
        self::assertSame('/blocked', $policy['custom_rules'][0]['conditions'][0]['value']);
        self::assertSame($policy, $config->normalize($policy));
        foreach ($config->presets() as $preset) {
            $tuned = $config->normalize(array_replace(['ip_denylist' => ['192.0.2.1'], 'rule_exclusions' => [['path' => '/editor', 'rule_id' => 941100]]], $preset['settings']));
            self::assertSame('monitor', $tuned['mode']);
            self::assertSame(['192.0.2.1'], $tuned['ip_denylist']);
            self::assertSame(941100, $tuned['rule_exclusions'][0]['rule_id']);
        }
    }

    public function testPathScopedRuleExclusionDoesNotDisableTheEngine(): void
    {
        $p = (new WafPolicyConfig())->normalize(['rule_exclusions' => [['path' => '/api/editor', 'rule_id' => '941100']]]);
        $files = (new WafConfigRenderer())->render($p, 'http://app:80', true, '10.0.4.0/24');
        self::assertStringContainsString('REQUEST_FILENAME "@streq /api/editor"', $files['rules.conf']);
        self::assertStringContainsString('ctl:ruleRemoveById=941100', $files['rules.conf']);
        self::assertStringNotContainsString('ctl:ruleEngine=Off', $files['rules.conf']);
        self::assertStringContainsString('!@rx ^(?:GET|HEAD|POST|PUT|PATCH|DELETE|OPTIONS)$', $files['rules.conf']);
    }

    public function testParserCorrelatesAuditAndAccessLogsAndDropsSensitivePayloads(): void
    {
        $audit = json_encode(['transaction' => ['unique_id' => 'request-1', 'client_ip' => '192.0.2.1',
            'request' => ['headers' => ['Authorization' => 'secret'], 'body' => 'private'],
            'messages' => [['message' => 'SQL injection', 'details' => ['ruleId' => '942100', 'data' => 'private SQL', 'tags' => ['attack-sqli']]]]]]);
        $access = json_encode(['kind' => 'galaxy_waf_access', 'id' => 'request-1', 'ip' => '192.0.2.1',
            'method' => 'POST', 'uri' => '/login?token=secret', 'status' => 403, 'time' => '2026-10-23T10:00:00+08:00', 'limit' => '']);
        $frame = static fn ($s) => "\x01\x00\x00\x00" . pack('N', strlen($s)) . $s;
        $events = (new WafLogParser())->parse($frame(substr($audit, 0, 30)) . $frame(substr($audit, 30) . "\n" . $access . "\n"), 'block');
        self::assertCount(1, $events);
        self::assertSame('/login', $events[0]['uri']);
        self::assertSame('block', $events[0]['action']);
        self::assertStringNotContainsString('private', json_encode($events));
        self::assertStringNotContainsString('secret', json_encode($events));
        self::assertStringNotContainsString('Authorization', json_encode($events));
    }

    public function testNormalOriginErrorsAreNotReportedAsAttacksAndCcHasItsOwnEvent(): void
    {
        $record = ['kind' => 'galaxy_waf_access', 'id' => 'normal', 'ip' => '192.0.2.2', 'uri' => '/', 'status' => 500, 'limit' => ''];
        $parser = new WafLogParser();
        self::assertSame([], $parser->parse(json_encode($record) . "\n", 'block'));
        $record['limit'] = 'REJECTED_DRY_RUN';
        $record['status'] = 200;
        $events = $parser->parse(json_encode($record) . "\n", 'monitor');
        self::assertSame('monitor', $events[0]['action']);
        self::assertSame('CC', $events[0]['messages'][0]['rule_id']);
    }

    public function testLoggedMatchWithOrigin403IsNotMisreportedAsWafBlocking(): void
    {
        $audit = ['transaction' => ['unique_id' => 'origin403', 'messages' => [['message' => 'Log rule', 'details' => ['ruleId' => 10001]]]]];
        $access = ['kind' => 'galaxy_waf_access', 'id' => 'origin403', 'uri' => '/', 'status' => 403, 'origin_status' => '403'];
        $events = (new WafLogParser())->parse(json_encode($audit) . "\n" . json_encode($access) . "\n", 'block');
        self::assertSame('detected', $events[0]['action']);
    }
}

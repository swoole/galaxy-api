<?php

namespace App\Services\Gateway;

/** Fixed detectors use constant msg/logdata; the event parser discards raw engine details. */
final class WafResponseInspection
{
    public const TYPES = ['private_key' => '私钥标记', 'aws_key' => 'AWS 访问密钥标识',
        'stack_trace' => '异常堆栈与数据库错误', 'php_source' => 'PHP 源码标记'];

    private const PATTERNS = [
        'private_key' => '-----BEGIN(?: (?:RSA|EC|DSA|OPENSSH|ENCRYPTED))? PRIVATE KEY-----',
        'aws_key' => '\\b(?:AKIA|ASIA)[A-Z0-9]{16}\\b',
        'stack_trace' => '(?i)(?:Fatal error:|Traceback \\(most recent call last\\)|Exception in thread|SQLSTATE\\[[0-9A-Z]{5}\\])',
        'php_source' => '<\\?(?:php[\\s]|=)',
    ];

    public function rules(array $settings): string
    {
        if (! $settings['enabled']) return '';
        $rules = [];
        // Keep inbound CRS checks active on downloads/encoded responses; skip only these response detectors.
        $rules[] = 'SecRule RESPONSE_HEADERS:Content-Disposition "@rx (?i)(?:^|;)\\s*attachment\\s*(?:;|$)" "id:12050,phase:3,t:none,pass,nolog,setvar:\'tx.galaxy_skip_response=1\'"';
        $rules[] = 'SecRule RESPONSE_HEADERS:Content-Encoding "!@rx (?i)^\\s*identity\\s*$" "id:12051,phase:3,t:none,pass,nolog,setvar:\'tx.galaxy_skip_response=1\'"';
        foreach ($settings['excluded_paths'] as $index => $path) {
            $rules[] = sprintf('SecRule REQUEST_FILENAME "@streq %s" "id:%d,phase:1,t:none,pass,nolog,setvar:\'tx.galaxy_skip_response=1\'"', $path, 12100 + $index);
        }
        foreach (array_keys(self::TYPES) as $index => $type) {
            if (! in_array($type, $settings['types'], true)) continue;
            $rules[] = sprintf('SecRule &TX:galaxy_skip_response "@eq 0" "id:%d,phase:4,t:none,pass,log,msg:\'Galaxy response sensitive data: %s\',logdata:\'response-match-redacted\',tag:\'galaxy-response\',chain"', 12000 + $index, $type);
            $rules[] = 'SecRule RESPONSE_BODY "@rx ' . self::PATTERNS[$type] . '" "t:none"';
        }
        return implode("\n", $rules) . "\n";
    }
}

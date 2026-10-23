<?php

namespace App\Services\Gateway;

/** Owns the Nginx/ModSecurity boundary; no user supplied raw directives. */
final class WafConfigRenderer
{
    public function render(array $p, string $upstream, bool $passHost, string $trustedSubnet): array
    {
        $rules = [];
        $id = 10000;
        $add = static function (string $variable, string $operator, string $actions) use (&$rules, &$id): void {
            $rules[] = sprintf('SecRule %s "%s" "id:%d,phase:1,t:none,%s"', $variable, $operator, $id++, $actions);
        };
        // Denies take precedence over allow-list and path exceptions.
        if ($p['ip_denylist'] !== []) {
            $add('REMOTE_ADDR', '@ipMatch ' . implode(',', $p['ip_denylist']), "deny,status:403,log,msg:'Galaxy IP denylist'");
        }
        if ($p['ip_allowlist'] !== []) {
            $add('REMOTE_ADDR', '@ipMatch ' . implode(',', $p['ip_allowlist']), 'pass,nolog,ctl:ruleEngine=Off');
        }
        foreach ($p['excluded_paths'] as $path) {
            $add('REQUEST_FILENAME', '@streq ' . $path, 'pass,nolog,ctl:ruleEngine=Off');
        }
        foreach ($p['rule_exclusions'] as $exclusion) {
            $add('REQUEST_FILENAME', '@streq ' . $exclusion['path'], 'pass,nolog,ctl:ruleRemoveById=' . $exclusion['rule_id']);
        }
        $add('REQUEST_METHOD', '!@rx ^(?:' . implode('|', $p['allowed_methods']) . ')$', "deny,status:403,log,msg:'Galaxy HTTP method not allowed'");
        if ($p['sensitive_paths']) {
            $add('REQUEST_FILENAME', '@rx (?i)(?:^|/)(?:\\.git|\\.svn|\\.env)(?:[/.]|$)', "deny,status:403,log,msg:'Galaxy sensitive path'");
        }
        foreach ($p['custom_rules'] as $i => $rule) {
            if (! $rule['enabled']) continue;
            // If any condition needs parsed JSON/form data, the complete chain runs in phase 2.
            $phase = in_array('args', array_column($rule['conditions'], 'field'), true) ? 2 : 1;
            $action = $rule['action'] === 'deny' ? 'deny,status:403' : 'pass';
            $name = $rule['name'] !== '' ? $rule['name'] : 'Galaxy custom rule ' . ($i + 1);
            foreach ($rule['conditions'] as $index => $condition) {
                $field = ['uri' => 'REQUEST_URI', 'path' => 'REQUEST_FILENAME', 'user_agent' => 'REQUEST_HEADERS:User-Agent',
                    'method' => 'REQUEST_METHOD', 'args' => 'ARGS', 'referer' => 'REQUEST_HEADERS:Referer',
                    'query' => 'QUERY_STRING', 'header' => 'REQUEST_HEADERS:' . $condition['name'],
                    'cookie' => 'REQUEST_COOKIES:' . $condition['name']][$condition['field']];
                $op = ['contains' => '@contains', 'equals' => '@streq', 'begins_with' => '@beginsWith',
                    'ends_with' => '@endsWith', 'not_contains' => '!@contains', 'not_equals' => '!@streq'][$condition['operator']];
                $actions = $index === 0 ? sprintf("id:%d,phase:%d,t:none,%s,log,msg:'%s'", $id++, $phase, $action, $name) : 't:none';
                if ($index < count($rule['conditions']) - 1) $actions .= ',chain';
                $rules[] = sprintf('SecRule %s "%s %s" "%s"', $field, $op, $condition['value'], $actions);
            }
        }
        if ($p['blocked_extensions'] !== []) {
            $extensions = implode('|', $p['blocked_extensions']);
            $rules[] = sprintf('SecRule FILES "@rx (?i)\\.(?:%s)(?:[.;]|$)" "id:%d,phase:2,t:none,deny,status:403,log,msg:\'Galaxy blocked upload extension\'"', $extensions, $id++);
        }
        $rules = implode("\n", $rules) . "\n";
        // Custom rules run before CRS so explicit allow/deny decisions are deterministic.
        $setup = "Include /etc/modsecurity.d/modsecurity.conf\nInclude /etc/modsecurity.d/modsecurity-override.conf\n"
            . "Include /etc/modsecurity.d/owasp-crs/crs-setup.conf\n"
            . "Include /etc/galaxy-waf/rules.conf\nInclude /etc/modsecurity.d/owasp-crs/rules/*.conf\n";
        $tags = ['sqli' => 'attack-sqli', 'xss' => 'attack-xss', 'rce' => 'attack-rce',
            'lfi' => 'attack-lfi', 'rfi' => 'attack-rfi', 'scanner' => 'attack-reputation-scanner', 'protocol' => 'attack-protocol'];
        foreach (array_diff(array_keys($tags), $p['categories']) as $category) {
            $setup .= 'SecRuleRemoveByTag ' . $tags[$category] . "\n";
        }
        if ($p['excluded_rule_ids'] !== []) {
            $setup .= 'SecRuleRemoveById ' . implode(' ', $p['excluded_rule_ids']) . "\n";
        }
        $host = $passHost ? '$http_host' : '$proxy_host';
        $dryRun = $p['mode'] === 'monitor' ? 'on' : 'off';
        $rate = $p['cc_rate'];
        $burst = $p['cc_burst'];
        $zones = "limit_req_zone \$binary_remote_addr zone=galaxy:2m rate={$rate}r/m;";
        $limits = $p['cc_enabled'] ? ["limit_req zone=galaxy burst={$burst} nodelay;"] : [];
        foreach ($p['path_limits'] as $i => $limit) {
            $path = preg_quote($limit['match'] === 'prefix' ? rtrim($limit['path'], '/') : $limit['path']);
            $pattern = $limit['match'] === 'exact' ? $path . '$' : ($path === '' ? '' : $path . '(?:/|$)');
            $zones .= sprintf("\nmap \$uri \$galaxy_path_%d { default ''; '~^%s' \$binary_remote_addr; }\nlimit_req_zone \$galaxy_path_%d zone=galaxy_path_%d:1m rate=%dr/m;", $i, $pattern, $i, $i, $limit['rate']);
            $limits[] = sprintf('limit_req zone=galaxy_path_%d burst=%d nodelay;', $i, $limit['burst']);
        }
        $cc = $limits === [] ? '' : implode("\n", $limits) . "\nlimit_req_dry_run {$dryRun};\nlimit_req_status 429;";
        // Trusted subnet is obtained from the actual gateway overlay, never from API input.
        $nginx = <<<NGINX
load_module modules/ngx_http_modsecurity_module.so;
worker_processes 1;
pid /tmp/nginx.pid;
error_log /dev/stderr crit;
events { worker_connections 1024; }
http {
    client_body_temp_path /tmp/client_temp;
    proxy_temp_path /tmp/proxy_temp;
    resolver 127.0.0.11 valid=10s ipv6=off;
    map \$http_upgrade \$connection_upgrade { default upgrade; '' close; }
    {$zones}
    log_format galaxy escape=json '{"kind":"galaxy_waf_access","time":"\$time_iso8601","id":"\$request_id","ip":"\$remote_addr","method":"\$request_method","uri":"\$uri","status":\$status,"origin_status":"\$upstream_status","limit":"\$limit_req_status"}';
    access_log /dev/stdout galaxy;
    server {
        listen 8080;
        server_name _;
        client_max_body_size {$p['body_limit']};
        set_real_ip_from {$trustedSubnet};
        real_ip_header X-Forwarded-For;
        real_ip_recursive off;
        location = /__galaxy_waf_health { access_log off; return 200 'ok'; }
        location / {
            {$cc}
            modsecurity on;
            modsecurity_transaction_id \$request_id;
            modsecurity_rules_file /etc/galaxy-waf/setup.conf;
            set \$galaxy_upstream '{$upstream}';
            proxy_pass \$galaxy_upstream;
            proxy_http_version 1.1;
            proxy_set_header Host {$host};
            proxy_set_header Proxy '';
            proxy_set_header Upgrade \$http_upgrade;
            proxy_set_header Connection \$connection_upgrade;
            proxy_set_header X-Real-IP \$remote_addr;
            proxy_set_header X-Forwarded-For \$remote_addr;
            proxy_set_header X-Forwarded-Proto \$http_x_forwarded_proto;
            proxy_set_header X-Forwarded-Port \$http_x_forwarded_port;
            proxy_ssl_server_name on;
            proxy_ssl_verify on;
            proxy_ssl_trusted_certificate /etc/ssl/certs/ca-certificates.crt;
            proxy_buffering off;
            proxy_read_timeout 3600s;
        }
    }
}
NGINX;
        return ['nginx.conf' => $nginx, 'setup.conf' => $setup, 'rules.conf' => $rules];
    }

    public function environment(array $p): array
    {
        return [
            'MODSEC_RULE_ENGINE=' . ($p['mode'] === 'block' ? 'On' : 'DetectionOnly'),
            'BLOCKING_PARANOIA=' . $p['paranoia'], 'DETECTION_PARANOIA=' . $p['paranoia'],
            'ANOMALY_INBOUND=' . $p['anomaly_threshold'],
            'ALLOWED_METHODS=' . implode(' ', $p['allowed_methods']),
            'MODSEC_REQ_BODY_LIMIT=' . $p['body_limit'], 'MODSEC_REQ_BODY_NOFILES_LIMIT=' . $p['body_limit'],
            'MODSEC_RESP_BODY_ACCESS=Off', 'MODSEC_AUDIT_ENGINE=RelevantOnly',
            'MODSEC_AUDIT_LOG=/dev/stdout', 'MODSEC_AUDIT_LOG_FORMAT=JSON',
            // Omit complete HTTP headers/bodies; H may still contain matched snippets.
            'MODSEC_AUDIT_LOG_PARTS=AHZ', 'MODSEC_AUDIT_LOG_TYPE=Serial',
            'MODSEC_AUDIT_LOG_RELEVANT_STATUS=^$', 'NGINX_WORKER_PROCESSES=1',
            'BACKEND=http://127.0.0.1:9', 'SERVER_NAME=localhost',
        ];
    }
}

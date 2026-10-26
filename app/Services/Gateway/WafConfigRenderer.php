<?php

namespace App\Services\Gateway;

/** Owns the Nginx/ModSecurity boundary; no user supplied raw directives. */
final class WafConfigRenderer
{
    public function render(array $p, string $upstream, bool $passHost, string $trustedSubnet, array $control = [], string $gatewayToken = ''): array
    {
        if ($p['auto_ban']['enabled'] && $control === []) throw new \App\Exception\AppException(409, '自动封禁需要先初始化动态防护检查服务');
        if ($p['browser']['enabled'] && $control === []) throw new \App\Exception\AppException(409, '浏览器验证需要先初始化动态防护检查服务');
        if (($p['geo']['enabled'] || $p['intelligence']['enabled']) && $control === []) throw new \App\Exception\AppException(409, '地域或 IP 情报防护需要先初始化动态检查和数据源');
        $dynamic = (new WafControlNginx())->render($control);
        $gatewayAuth = (new WafGatewayAuth(new WafSchemaState()))->render($gatewayToken);
        $rules = [];
        $id = 10000;
        $add = static function (string $variable, string $operator, string $actions) use (&$rules, &$id): void {
            $rules[] = sprintf('SecRule %s "%s" "id:%d,phase:1,t:none,%s"', $variable, $operator, $id++, $actions);
        };
        // Denies take precedence over allow-list and path exceptions.
        if ($p['ip_denylist'] !== []) {
            $add('REMOTE_ADDR', '@ipMatch ' . implode(',', $p['ip_denylist']), "deny,status:403,log,msg:'Galaxy IP denylist',setvar:'tx.galaxy_ip_denied=1'");
        }
        foreach ($p['ip_bans'] as $ban) {
            // Keep expiry in the rule: no cron, API availability or gateway reload is needed to expire it.
            $add('REMOTE_ADDR', '@ipMatch ' . $ban['ip'], "deny,status:403,log,msg:'Galaxy temporary IP ban',tag:'galaxy-temp-ban',chain");
            // setvar executes per chain member: mark it only after the expiry test matches.
            $rules[] = sprintf('SecRule TIME_EPOCH "@lt %d" "t:none,setvar:\'tx.galaxy_ip_denied=1\'"', $ban['expires_at']);
        }
        if ($p['ip_allowlist'] !== []) {
            $add('REMOTE_ADDR', '@ipMatch ' . implode(',', $p['ip_allowlist']), 'pass,nolog,chain');
            $rules[] = 'SecRule &TX:galaxy_ip_denied "@eq 0" "t:none,ctl:ruleEngine=Off"';
        }
        foreach ($p['excluded_paths'] as $path) {
            $add('REQUEST_FILENAME', '@streq ' . $path, 'pass,nolog,chain');
            $rules[] = 'SecRule &TX:galaxy_ip_denied "@eq 0" "t:none,ctl:ruleEngine=Off"';
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
        $rules = implode("\n", $rules) . "\n" . (new WafResponseInspection())->rules($p['response']);
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
        // Response inspection is observation-only: the connector can already have sent earlier chunks.
        // Keep the fixed response detectors independent of CRS outbound anomaly blocking.
        if ($p['response']['enabled']) $setup .= "SecRuleRemoveById 950000-959999\n";
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
    map_hash_bucket_size 128;
    client_body_temp_path /tmp/client_temp;
    proxy_temp_path /tmp/proxy_temp;
    resolver 127.0.0.11 valid=10s ipv6=off;
    map \$http_upgrade \$connection_upgrade { default upgrade; '' close; }
    {$zones}
    {$dynamic['http']}
    {$gatewayAuth['http']}
    map \$galaxy_gateway_authorized \$galaxy_log_client { default \$realip_remote_addr; 1 \$remote_addr; }
    geo \$realip_remote_addr \$galaxy_local_probe { default 0; 127.0.0.1/32 1; ::1/128 1; }
    log_format galaxy escape=json '{"kind":"galaxy_waf_access","time":"\$time_iso8601","msec":"\$msec","id":"\$request_id","ip":"\$galaxy_log_client","method":"\$request_method","uri":"\$uri","status":\$status,"origin_status":"\$upstream_status","limit":"\$limit_req_status","dynamic":"\$galaxy_dynamic_action","control_status":"\$galaxy_control_status","control_protocol":"\$galaxy_control_protocol","geo_country":"\$galaxy_geo_country","data_source":"\$galaxy_data_source","data_status":"\$galaxy_data_status","gateway":"\$galaxy_gateway_authorized"}';
    access_log /dev/stdout galaxy;
    server {
        listen 8080;
        server_name _;
        client_max_body_size {$p['body_limit']};
        large_client_header_buffers 4 16k;
        set_real_ip_from {$trustedSubnet};
        real_ip_header X-Forwarded-For;
        real_ip_recursive off;
        set \$galaxy_dynamic_action '';
        set \$galaxy_control_status '';
        set \$galaxy_control_protocol '';
        set \$galaxy_geo_country '';
        set \$galaxy_data_source '';
        set \$galaxy_data_status '';
        location = /__galaxy_waf_health { access_log off; return 200 'ok'; }
        {$dynamic['locations']}
        location / {
            {$gatewayAuth['access']}
            {$cc}
            {$dynamic['access']}
            modsecurity on;
            modsecurity_transaction_id \$request_id;
            modsecurity_rules_file /etc/galaxy-waf/setup.conf;
            set \$galaxy_upstream '{$upstream}';
            proxy_pass \$galaxy_upstream;
            proxy_http_version 1.1;
            proxy_set_header Host {$host};
            proxy_set_header Proxy '';
            proxy_set_header X-Galaxy-Waf-Gateway '';
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
            'MODSEC_RESP_BODY_ACCESS=' . ($p['response']['enabled'] ? 'On' : 'Off'),
            'MODSEC_RESP_BODY_LIMIT=' . $p['response']['limit'], 'MODSEC_RESP_BODY_LIMIT_ACTION=ProcessPartial',
            'MODSEC_RESP_BODY_MIMETYPE=text/plain text/html application/json', 'MODSEC_AUDIT_ENGINE=RelevantOnly',
            'MODSEC_AUDIT_LOG=/dev/stdout', 'MODSEC_AUDIT_LOG_FORMAT=JSON',
            // Omit complete HTTP headers/bodies; H may still contain matched snippets.
            'MODSEC_AUDIT_LOG_PARTS=AHZ', 'MODSEC_AUDIT_LOG_TYPE=Serial',
            'MODSEC_AUDIT_LOG_RELEVANT_STATUS=^$', 'NGINX_WORKER_PROCESSES=1',
            'BACKEND=http://127.0.0.1:9', 'SERVER_NAME=localhost',
        ];
    }
}

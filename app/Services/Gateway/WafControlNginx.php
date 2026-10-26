<?php

namespace App\Services\Gateway;

use App\Exception\AppException;

final class WafControlNginx
{
    public function render(array $binding): array
    {
        if ($binding === []) return ['access' => '', 'locations' => '', 'http' => ''];
        (new WafControlConfig())->safeUrl($binding['endpoint']);
        if (! preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]{43}$/D', $binding['token'])) throw new AppException(422, 'WAF control credential format invalid');
        if (! empty($binding['browser']) && (! is_int($binding['policy_id'] ?? null) || $binding['policy_id'] < 1 || ! str_ends_with($binding['endpoint'], '/check'))) throw new AppException(422, 'Browser challenge binding invalid');
        $access = <<<'NGINX'
auth_request /__galaxy_waf_control;
auth_request_set $galaxy_dynamic_action $upstream_http_x_galaxy_waf_action;
auth_request_set $galaxy_control_retry $upstream_http_retry_after;
auth_request_set $galaxy_control_status $upstream_status;
auth_request_set $galaxy_control_protocol $upstream_http_x_galaxy_waf_protocol;
auth_request_set $galaxy_geo_country $upstream_http_x_galaxy_waf_country;
auth_request_set $galaxy_data_source $upstream_http_x_galaxy_waf_source;
auth_request_set $galaxy_data_status $upstream_http_x_galaxy_waf_data;
error_page 403 = @galaxy_waf_control_denied;
error_page 500 502 503 504 = @galaxy_waf_control_unavailable;
NGINX;
        $access .= ! empty($binding['browser']) ? "\nerror_page 401 = @galaxy_waf_challenge;\nerror_page 418 = @galaxy_waf_challenge_solve;\nif (\$http_x_galaxy_waf_challenge = solve) { return 418; }\n" : "\nerror_page 401 = @galaxy_waf_control_unavailable;\n";
        $locations = $this->location('/__galaxy_waf_control', false, $binding)
            . $this->location('/__galaxy_waf_control_health', true, $binding)
            . <<<'NGINX'
location @galaxy_waf_control_denied {
    default_type text/plain;
    add_header Retry-After $galaxy_control_retry always;
    return 403 'Access restricted by security policy. Please contact the site administrator if this is unexpected.';
}
location @galaxy_waf_control_unavailable {
    default_type text/plain;
    return 503 'Security verification is temporarily unavailable. Please try again later.';
}
NGINX;
        if (! empty($binding['browser'])) {
            foreach (['challenge' => false, 'challenge/solve' => true] as $operation => $body) {
                $name = $body ? '@galaxy_waf_challenge_solve' : '@galaxy_waf_challenge';
                $method = $body ? "if (\$request_method != POST) { return 405; }\nclient_max_body_size 4k;" : '';
                $locations .= "\nlocation {$name} {\n{$method}\n" . $this->proxy($binding, $operation, false, $body) . "}\n";
            }
        }
        // Access handlers do not run in Nginx subrequests. A loopback main request
        // validates the backend response after auth_request finishes, before the
        // outer auth_request sees it. No additional public/container port is opened.
        $http = <<<'NGINX'
map $galaxy_check_protocol $galaxy_check_valid { default 0; "1" 1; }
map $request_uri $galaxy_check_probe { default 0; /probe 1; }
map $request_uri $galaxy_request_path { default /; "~^(?<galaxy_original_path>/[^?]*)" $galaxy_original_path; }
map $http_x_forwarded_proto $galaxy_request_scheme { default http; https https; }
map "$galaxy_check_status|$galaxy_check_valid" $galaxy_check_verdict {
    default 0;
    "204|1" 1;
    "403|1" 2;
    "401|1" 3;
}
map $galaxy_check_action $galaxy_checked_action { default ''; allow allow; block block; monitor monitor; browser_challenge browser_challenge; browser_block browser_block; browser_monitor browser_monitor; geo_block geo_block; geo_monitor geo_monitor; intel_block intel_block; intel_monitor intel_monitor; }
map $galaxy_check_country $galaxy_checked_country { default ''; "~^[A-Z]{2}$" $galaxy_check_country; }
map $galaxy_check_source $galaxy_checked_source { default ''; "~^[a-z][a-z0-9-]{1,63}$" $galaxy_check_source; }
map $galaxy_check_data $galaxy_checked_data { default ''; ok ok; degraded degraded; }
map $galaxy_check_retry $galaxy_checked_retry { default ''; "~^[1-9][0-9]{0,4}$" $galaxy_check_retry; }
server {
    listen 127.0.0.1:8081;
    server_name _;
    access_log off;
    location / {
        auth_request /__backend;
        auth_request_set $galaxy_check_status $upstream_status;
        auth_request_set $galaxy_check_protocol $upstream_http_x_galaxy_waf_protocol;
        auth_request_set $galaxy_check_action $upstream_http_x_galaxy_waf_action;
        auth_request_set $galaxy_check_retry $upstream_http_retry_after;
        auth_request_set $galaxy_check_country $upstream_http_x_galaxy_waf_country;
        auth_request_set $galaxy_check_source $upstream_http_x_galaxy_waf_source;
        auth_request_set $galaxy_check_data $upstream_http_x_galaxy_waf_data;
        error_page 401 403 = @verdict;
        error_page 500 502 503 504 = @unavailable;
        root /etc/galaxy-waf;
        try_files /__galaxy_waf_control_missing__ @verdict;
    }
    location @verdict {
        add_header X-Galaxy-Waf-Protocol $galaxy_check_valid always;
        add_header X-Galaxy-Waf-Action $galaxy_checked_action always;
        add_header Retry-After $galaxy_checked_retry always;
        add_header X-Galaxy-Waf-Country $galaxy_checked_country always;
        add_header X-Galaxy-Waf-Source $galaxy_checked_source always;
        add_header X-Galaxy-Waf-Data $galaxy_checked_data always;
        if ($galaxy_check_verdict = 1) { return 204; }
        if ($galaxy_check_verdict = 2) { return 403; }
        if ($galaxy_check_verdict = 3) { return 401; }
        return 503;
    }
    location @unavailable { return 503; }
NGINX;
        $http .= $this->backend($binding) . "}\n";
        return ['access' => $access, 'locations' => $locations, 'http' => $http];
    }

    private function location(string $path, bool $probe, array $binding): string
    {
        $restriction = $probe ? 'if ($galaxy_local_probe = 0) { return 403; }' : 'internal;';
        $target = $probe ? 'probe' : 'check';
        $headers = $this->clientHeaders($binding, false);
        return <<<NGINX
location = {$path} {
    {$restriction}
    access_log off;
    proxy_pass http://127.0.0.1:8081/{$target};
    proxy_method GET;
    proxy_pass_request_body off;
    proxy_pass_request_headers off;
    proxy_set_header Content-Length '';
    {$headers}
    proxy_next_upstream off;
    proxy_connect_timeout 1s;
    proxy_read_timeout 3s;
}

NGINX;
    }

    private function backend(array $binding): string
    {
        return "\nlocation = /__backend {\ninternal;\nproxy_set_header X-Galaxy-Waf-Probe \$galaxy_check_probe;\n" . $this->proxy($binding, 'check', true, false) . "}\n";
    }

    private function proxy(array $binding, string $operation, bool $loopback, bool $body): string
    {
        $endpoint = $operation === 'check' ? $binding['endpoint'] : substr($binding['endpoint'], 0, -5) . $operation;
        $headers = $this->clientHeaders($binding, $loopback);
        $method = $body ? 'POST' : 'GET';
        $requestBody = $body ? "proxy_set_header Content-Type application/json;\n" : "proxy_pass_request_body off;\nproxy_set_header Content-Length '';\n";
        return <<<NGINX
set \$galaxy_control_upstream '{$endpoint}';
proxy_pass \$galaxy_control_upstream;
proxy_method {$method};
proxy_pass_request_headers off;
{$requestBody}
proxy_set_header Host \$proxy_host;
proxy_set_header X-Galaxy-Waf-Token '{$binding['token']}';
{$headers}
proxy_next_upstream off;
proxy_connect_timeout 1s;
proxy_read_timeout 2s;
proxy_ssl_server_name on;
proxy_ssl_verify on;
proxy_ssl_trusted_certificate /etc/ssl/certs/ca-certificates.crt;
NGINX;
    }

    private function clientHeaders(array $binding, bool $loopback): string
    {
        $values = ['Client' => '$remote_addr'];
        if (! empty($binding['network'])) $values['Path'] = '$galaxy_request_path';
        if (! empty($binding['browser'])) $values += ['Host' => '$http_host', 'Agent' => '$http_user_agent',
            'Method' => '$request_method', 'Accept' => '$http_accept', 'Upgrade' => '$http_upgrade',
            'Path' => '$galaxy_request_path', 'Scheme' => '$galaxy_request_scheme',
            'Clearance' => '$cookie_' . WafBrowserToken::cookieName($binding['policy_id'])];
        $headers = '';
        foreach ($values as $name => $value) {
            if ($loopback) $value = '$http_x_galaxy_waf_' . strtolower($name);
            $headers .= 'proxy_set_header X-Galaxy-Waf-' . $name . ' ' . $value . ";\n";
        }
        return $headers;
    }
}

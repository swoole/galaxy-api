<?php

namespace App\Services\Gateway;

use App\Exception\AppException;

/** Converts the visual policy editor into bounded, injection-safe engine input. */
final class WafPolicyConfig
{
    public const CATEGORIES = [
        'sqli' => 'SQL 注入', 'xss' => '跨站脚本', 'rce' => '命令执行',
        'lfi' => '本地文件包含 / 路径穿越', 'rfi' => '远程文件包含',
        'scanner' => '恶意扫描', 'protocol' => 'HTTP 协议攻击',
    ];

    public function defaults(): array
    {
        return [
            'mode' => 'monitor', 'paranoia' => 1, 'anomaly_threshold' => 5,
            'categories' => array_keys(self::CATEGORIES), 'body_limit' => 10485760,
            'cc_enabled' => false, 'cc_rate' => 120, 'cc_burst' => 20,
            'path_limits' => [],
            'ip_allowlist' => [], 'ip_denylist' => [], 'excluded_paths' => [],
            'ip_bans' => [],
            'allowed_methods' => ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
            'rule_exclusions' => [],
            'excluded_rule_ids' => [], 'blocked_extensions' => ['php', 'phtml', 'jsp', 'asp', 'aspx'],
            'sensitive_paths' => true, 'custom_rules' => [],
            'auto_ban' => ['enabled' => false, 'window_seconds' => 300, 'attack_enabled' => true,
                'attack_threshold' => 20, 'not_found_enabled' => false, 'not_found_threshold' => 60,
                'duration_seconds' => 600, 'excluded_paths' => []],
            'browser' => ['enabled' => false, 'type' => 'javascript', 'difficulty' => 16,
                'challenge_seconds' => 180, 'clearance_seconds' => 3600, 'non_browser' => 'block',
                'excluded_paths' => [], 'excluded_prefixes' => []],
            'geo' => ['enabled' => false, 'source' => 'dbip-country', 'mode' => 'deny', 'countries' => [], 'unknown' => 'allow', 'unavailable' => 'block'],
            'intelligence' => ['enabled' => false, 'sources' => [], 'unavailable' => 'block'],
            'response' => ['enabled' => false, 'limit' => 65536, 'types' => ['private_key', 'aws_key', 'stack_trace', 'php_source'], 'excluded_paths' => []],
            'alerts' => ['enabled' => false, 'attack_threshold' => 50, 'window_seconds' => 300,
                'failure_threshold' => 2, 'cooldown_seconds' => 1800, 'notify_recovery' => true,
                'channels' => ['notify'], 'actions' => ['block', 'monitor']],
        ];
    }

    public function presets(): array
    {
        return [
            'website' => ['title' => '通用网站', 'description' => 'PL1 检测，允许浏览器常用方法，先观察业务流量。',
                'settings' => ['mode' => 'monitor', 'paranoia' => 1, 'anomaly_threshold' => 5,
                    'allowed_methods' => ['GET', 'HEAD', 'POST', 'OPTIONS'], 'cc_enabled' => true, 'cc_rate' => 120, 'cc_burst' => 20]],
            'api' => ['title' => 'REST API', 'description' => '保留 PUT/PATCH/DELETE，为接口设置较宽的整站限速。',
                'settings' => ['mode' => 'monitor', 'paranoia' => 1, 'anomaly_threshold' => 5,
                    'allowed_methods' => $this->defaults()['allowed_methods'], 'cc_enabled' => true, 'cc_rate' => 600, 'cc_burst' => 50]],
            'static' => ['title' => '静态站点', 'description' => '只允许读取，使用较宽的限速以兼容静态资源并发。',
                'settings' => ['mode' => 'monitor', 'paranoia' => 1, 'anomaly_threshold' => 5,
                    'allowed_methods' => ['GET', 'HEAD', 'OPTIONS'], 'cc_enabled' => true, 'cc_rate' => 1200, 'cc_burst' => 100]],
        ];
    }

    public function normalize(array $input): array
    {
        $p = array_replace($this->defaults(), $input);
        $p['alerts'] = $this->alerts($p['alerts']);
        $p['response'] = $this->response($p['response']);
        $p['auto_ban'] = $this->autoBan($p['auto_ban']);
        $p['browser'] = $this->browser($p['browser']);
        $p['geo'] = $this->network($p['geo'], 'geo');
        $p['intelligence'] = $this->network($p['intelligence'], 'intelligence');
        if (! in_array($p['mode'], ['off', 'monitor', 'block'], true)) {
            $this->invalid('防护模式无效');
        }
        foreach (['paranoia' => [1, 4], 'anomaly_threshold' => [1, 100],
            'body_limit' => [1024, 67108864], 'cc_rate' => [1, 60000], 'cc_burst' => [1, 1000]] as $key => [$min, $max]) {
            if (filter_var($p[$key], FILTER_VALIDATE_INT) === false || $p[$key] < $min || $p[$key] > $max) {
                $this->invalid($key . ' 超出允许范围');
            }
            $p[$key] = (int) $p[$key];
        }
        foreach (['cc_enabled', 'sensitive_paths'] as $key) {
            if (! in_array($p[$key], [true, false, 0, 1], true)) {
                $this->invalid($key . ' 必须为布尔值');
            }
            $p[$key] = (bool) $p[$key];
        }
        foreach (['categories', 'ip_allowlist', 'ip_denylist', 'excluded_paths',
            'excluded_rule_ids', 'blocked_extensions', 'custom_rules', 'allowed_methods', 'rule_exclusions', 'path_limits', 'ip_bans'] as $key) {
            if (! is_array($p[$key]) || ! array_is_list($p[$key]) || count($p[$key]) > 100) {
                $this->invalid($key . ' 必须为列表，最多 100 项');
            }
        }
        if (count($p['path_limits']) > 10) $this->invalid('路径限速最多 10 条');
        foreach ($p['path_limits'] as &$limit) {
            if (! is_array($limit) || ! in_array($limit['match'] ?? '', ['exact', 'prefix'], true)) $this->invalid('路径限速格式无效');
            $this->path($limit['path'] ?? null);
            // Nginx map uses generated regexes; restrict to unambiguous URL path characters.
            if (! preg_match('#^/[a-zA-Z0-9/_.~%:@+!&=,-]*$#D', $limit['path'])) $this->invalid('限速路径包含不支持的字符');
            foreach (['rate' => 60000, 'burst' => 1000] as $key => $max) {
                if (filter_var($limit[$key] ?? null, FILTER_VALIDATE_INT) === false || $limit[$key] < 1 || $limit[$key] > $max) $this->invalid('路径限速参数超出范围');
            }
            $limit = ['path' => $limit['path'], 'match' => $limit['match'], 'rate' => (int) $limit['rate'], 'burst' => (int) $limit['burst']];
        }
        unset($limit);
        foreach ($p['categories'] as $category) {
            if (! is_string($category) || ! isset(self::CATEGORIES[$category])) {
                $this->invalid('未知防护类型');
            }
        }
        foreach (['ip_allowlist', 'ip_denylist'] as $key) {
            foreach ($p[$key] as $cidr) {
                $this->ip($cidr);
            }
        }
        foreach ($p['ip_bans'] as &$ban) {
            if (! is_array($ban)) $this->invalid('临时封禁格式无效');
            $this->ip($ban['ip'] ?? null);
            $expires = $ban['expires_at'] ?? null;
            if (filter_var($expires, FILTER_VALIDATE_INT) === false || $expires < 1 || $expires > time() + 86400 * 30) {
                $this->invalid('封禁到期时间须为秒级时间戳，且不得超过未来 30 天');
            }
            $reason = $ban['reason'] ?? '';
            if (! is_string($reason) || mb_strlen($reason) > 200 || preg_match('/[\x00-\x1f\x7f]/', $reason)) $this->invalid('封禁备注最多 200 字且不含控制字符');
            $ban = ['ip' => $ban['ip'], 'expires_at' => (int) $expires, 'reason' => $reason];
        }
        unset($ban);
        foreach ($p['excluded_paths'] as $path) {
            $this->path($path);
        }
        if ($p['allowed_methods'] === []) $this->invalid('至少允许一种 HTTP 请求方法');
        foreach ($p['allowed_methods'] as $method) {
            if (! in_array($method, ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'TRACE', 'CONNECT'], true)) {
                $this->invalid('不支持的 HTTP 请求方法');
            }
        }
        foreach ($p['rule_exclusions'] as &$exclusion) {
            if (! is_array($exclusion)) $this->invalid('路径规则排除项无效');
            $this->path($exclusion['path'] ?? null);
            if ((! is_int($exclusion['rule_id'] ?? null) && ! is_string($exclusion['rule_id'] ?? null))
                || ! preg_match('/^9[0-9]{5}$/D', (string) $exclusion['rule_id'])) $this->invalid('只能排除六位 OWASP CRS 规则 ID');
            $exclusion = ['path' => $exclusion['path'], 'rule_id' => (int) $exclusion['rule_id']];
        }
        unset($exclusion);
        foreach ($p['excluded_rule_ids'] as &$id) {
            if ((! is_int($id) && ! is_string($id)) || ! preg_match('/^9[0-9]{5}$/D', (string) $id)) $this->invalid('只能排除六位 OWASP CRS 规则 ID');
            $id = (int) $id;
        }
        unset($id);
        foreach ($p['blocked_extensions'] as $ext) {
            if (! is_string($ext) || ! preg_match('/^[a-z0-9]{1,12}$/D', $ext)) $this->invalid('上传扩展名只能包含小写字母和数字');
        }
        if (count($p['custom_rules']) > 30) $this->invalid('自定义规则最多 30 条');
        foreach ($p['custom_rules'] as &$rule) {
            if (! is_array($rule) || ! in_array($rule['action'] ?? '', ['deny', 'log'], true)) $this->invalid('自定义规则格式无效');
            // Preserve existing single-condition policies without a database migration.
            $conditions = $rule['conditions'] ?? [array_intersect_key($rule, array_flip(['field', 'operator', 'value', 'name']))];
            if (! is_array($conditions) || ! array_is_list($conditions) || count($conditions) < 1 || count($conditions) > 5) $this->invalid('每条规则需要 1 到 5 个条件');
            foreach ($conditions as &$condition) $condition = $this->condition($condition);
            unset($condition);
            $enabled = $rule['enabled'] ?? true;
            if (! is_bool($enabled)) $this->invalid('规则启用状态必须为布尔值');
            $name = $rule['name'] ?? '';
            if ($name !== '') $this->literal($name, 60);
            $rule = ['name' => $name, 'enabled' => $enabled, 'action' => $rule['action'], 'conditions' => $conditions];
        }
        unset($rule);
        foreach (['categories', 'ip_allowlist', 'ip_denylist', 'excluded_paths', 'excluded_rule_ids', 'blocked_extensions', 'allowed_methods'] as $key) {
            $p[$key] = array_values(array_unique($p[$key]));
        }
        return array_intersect_key($p, $this->defaults());
    }

    private function condition(mixed $condition): array
    {
        if (! is_array($condition)
            || ! in_array($condition['field'] ?? '', ['uri', 'path', 'user_agent', 'method', 'args', 'header', 'cookie', 'referer', 'query'], true)
            || ! in_array($condition['operator'] ?? '', ['contains', 'equals', 'begins_with', 'ends_with', 'not_contains', 'not_equals'], true)) $this->invalid('规则匹配条件无效');
        $this->literal($condition['value'] ?? null, 256);
        $name = '';
        if (in_array($condition['field'], ['header', 'cookie'], true)) {
            $name = $condition['name'] ?? '';
            if (! is_string($name) || ! preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $name)) $this->invalid('请求头或 Cookie 名称无效');
        }
        return ['field' => $condition['field'], 'operator' => $condition['operator'], 'value' => $condition['value'], 'name' => $name];
    }

    private function network(mixed $input, string $type): array
    {
        if (! is_array($input)) $this->invalid('地域或 IP 情报设置无效');
        $settings = array_replace($this->defaults()[$type], $input);
        if (! is_bool($settings['enabled']) || ! in_array($settings['unavailable'], ['block','skip'], true)) $this->invalid('地域或情报开关及故障处置无效');
        $sources = $type === 'geo' ? [$settings['source']] : $settings['sources'];
        if (! is_array($sources) || ! array_is_list($sources) || count($sources) > 8 || ($settings['enabled'] && $sources === [])) $this->invalid('IP 情报最多选择 8 个数据源，启用时不得为空');
        foreach ($sources as $source) if (! is_string($source) || ! preg_match('/^[a-z][a-z0-9-]{1,63}$/D', $source)) $this->invalid('地域或情报数据源标识无效');
        if ($type === 'geo') {
            if (! in_array($settings['mode'], ['allow','deny'], true) || ! in_array($settings['unknown'], ['allow','block'], true)) $this->invalid('地域匹配方式或未知位置处置无效');
            if (! is_array($settings['countries']) || ! array_is_list($settings['countries']) || count($settings['countries']) > 250 || ($settings['enabled'] && $settings['countries'] === [])) $this->invalid('启用地域控制时请选择国家/地区');
            foreach ($settings['countries'] as $country) if (! WafCountries::valid($country)) $this->invalid('国家/地区代码无效');
            $settings['countries'] = array_values(array_unique($settings['countries'])); sort($settings['countries']);
        } else $settings['sources'] = array_values(array_unique($sources));
        return array_intersect_key($settings, $this->defaults()[$type]);
    }

    private function browser(mixed $input): array
    {
        if (! is_array($input)) $this->invalid('浏览器验证设置无效');
        $b = array_replace($this->defaults()['browser'], $input);
        if (! is_bool($b['enabled']) || ! in_array($b['type'], ['javascript','captcha'], true)
            || ! in_array($b['non_browser'], ['block','bypass'], true)) $this->invalid('浏览器验证类型或开关无效');
        foreach (['difficulty' => [12,20], 'challenge_seconds' => [30,300], 'clearance_seconds' => [60,86400]] as $key => [$min,$max]) {
            if (filter_var($b[$key], FILTER_VALIDATE_INT) === false || $b[$key] < $min || $b[$key] > $max) $this->invalid('浏览器验证参数超出范围：' . $key);
            $b[$key] = (int) $b[$key];
        }
        foreach (['excluded_paths','excluded_prefixes'] as $key) {
            if (! is_array($b[$key]) || ! array_is_list($b[$key]) || count($b[$key]) > 50) $this->invalid('浏览器验证排除路径最多各 50 项');
            foreach ($b[$key] as $path) $this->path($path);
            $b[$key] = array_values(array_unique($b[$key]));
        }
        return array_intersect_key($b, $this->defaults()['browser']);
    }

    private function autoBan(mixed $input): array
    {
        if (! is_array($input)) $this->invalid('自动封禁设置无效');
        $a = array_replace($this->defaults()['auto_ban'], $input);
        foreach (['enabled','attack_enabled','not_found_enabled'] as $key) if (! is_bool($a[$key])) $this->invalid('自动封禁开关必须为布尔值');
        if ($a['enabled'] && ! $a['attack_enabled'] && ! $a['not_found_enabled']) $this->invalid('请至少选择一种自动封禁条件');
        foreach (['window_seconds' => [60,3600], 'attack_threshold' => [2,10000],
            'not_found_threshold' => [10,10000], 'duration_seconds' => [60,86400]] as $key => [$min,$max]) {
            if (filter_var($a[$key], FILTER_VALIDATE_INT) === false || $a[$key] < $min || $a[$key] > $max) $this->invalid('自动封禁参数超出范围：' . $key);
            $a[$key] = (int) $a[$key];
        }
        if (! is_array($a['excluded_paths']) || ! array_is_list($a['excluded_paths']) || count($a['excluded_paths']) > 100) $this->invalid('自动封禁排除路径最多 100 项');
        foreach ($a['excluded_paths'] as $path) $this->path($path);
        $a['excluded_paths'] = array_values(array_unique($a['excluded_paths']));
        return array_intersect_key($a, $this->defaults()['auto_ban']);
    }

    private function response(mixed $input): array
    {
        if (! is_array($input)) $this->invalid('响应检测设置无效');
        $r = array_replace($this->defaults()['response'], $input);
        if (! is_bool($r['enabled'])) $this->invalid('响应检测开关必须为布尔值');
        if (filter_var($r['limit'], FILTER_VALIDATE_INT) === false || $r['limit'] < 1024 || $r['limit'] > 262144) $this->invalid('响应检测上限为 1–256 KiB');
        $r['limit'] = (int) $r['limit'];
        if (! is_array($r['types']) || ! array_is_list($r['types']) || $r['types'] === [] || count($r['types']) > 4) $this->invalid('请选择至少一种响应检测类型');
        foreach ($r['types'] as $type) if (! is_string($type) || ! isset(WafResponseInspection::TYPES[$type])) $this->invalid('响应检测类型无效');
        $r['types'] = array_values(array_unique($r['types']));
        if (! is_array($r['excluded_paths']) || ! array_is_list($r['excluded_paths']) || count($r['excluded_paths']) > 100) $this->invalid('响应检测排除路径最多 100 项');
        foreach ($r['excluded_paths'] as $path) $this->path($path);
        $r['excluded_paths'] = array_values(array_unique($r['excluded_paths']));
        return array_intersect_key($r, $this->defaults()['response']);
    }

    private function alerts(mixed $input): array
    {
        if (! is_array($input)) $this->invalid('告警设置无效');
        $a = array_replace($this->defaults()['alerts'], $input);
        foreach (['enabled', 'notify_recovery'] as $key) if (! is_bool($a[$key])) $this->invalid('告警开关必须为布尔值');
        foreach (['attack_threshold' => [1, 100000], 'window_seconds' => [60, 3600],
            'failure_threshold' => [1, 20], 'cooldown_seconds' => [300, 86400]] as $key => [$min, $max]) {
            if (filter_var($a[$key], FILTER_VALIDATE_INT) === false || $a[$key] < $min || $a[$key] > $max) $this->invalid('告警参数超出范围：' . $key);
            $a[$key] = (int) $a[$key];
        }
        foreach (['channels' => ['notify', 'email', 'browser'], 'actions' => ['block', 'monitor', 'detected', 'challenge']] as $key => $allowed) {
            if (! is_array($a[$key]) || ! array_is_list($a[$key]) || $a[$key] === [] || count($a[$key]) > 3) $this->invalid('告警渠道或动作列表无效');
            foreach ($a[$key] as $value) if (! in_array($value, $allowed, true)) $this->invalid('不支持的告警渠道或动作');
            $a[$key] = array_values(array_unique($a[$key]));
        }
        return array_intersect_key($a, $this->defaults()['alerts']);
    }

    private function ip(mixed $cidr): void
    {
        if (! is_string($cidr)) $this->invalid('IP 地址无效');
        $parts = explode('/', $cidr);
        if (count($parts) > 2 || filter_var($parts[0], FILTER_VALIDATE_IP) === false
            || (isset($parts[1]) && (! ctype_digit($parts[1]) || (int) $parts[1] > (str_contains($parts[0], ':') ? 128 : 32)))) {
            $this->invalid('请输入有效 IPv4 / IPv6 或 CIDR：' . $cidr);
        }
    }

    private function literal(mixed $value, int $max): void
    {
        if (! is_string($value) || $value === '' || strlen($value) > $max
            || preg_match('/[\x00-\x1f\x7f"\x27\\\\]/', $value)
            || str_contains($value, '%{') || str_contains($value, '${')) {
            $this->invalid('规则内容不可为空或包含控制字符、引号、反斜杠及变量表达式');
        }
    }

    private function path(mixed $value): void
    {
        $this->literal($value, 512);
        if (! str_starts_with($value, '/') || str_contains($value, '?')) $this->invalid('排除路径须以 / 开头，且不含查询参数');
    }

    private function invalid(string $message): never
    {
        throw new AppException(422, $message);
    }
}

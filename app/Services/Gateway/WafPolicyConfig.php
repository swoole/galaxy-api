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
            'allowed_methods' => ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
            'rule_exclusions' => [],
            'excluded_rule_ids' => [], 'blocked_extensions' => ['php', 'phtml', 'jsp', 'asp', 'aspx'],
            'sensitive_paths' => true, 'custom_rules' => [],
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
            'excluded_rule_ids', 'blocked_extensions', 'custom_rules', 'allowed_methods', 'rule_exclusions', 'path_limits'] as $key) {
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
                if (! is_string($cidr)) $this->invalid('IP 地址无效');
                $parts = explode('/', $cidr);
                if (count($parts) > 2 || filter_var($parts[0], FILTER_VALIDATE_IP) === false
                    || (isset($parts[1]) && (! ctype_digit($parts[1])
                        || (int) $parts[1] > (str_contains($parts[0], ':') ? 128 : 32)))) {
                    $this->invalid('请输入有效 IPv4 / IPv6 或 CIDR：' . $cidr);
                }
            }
        }
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

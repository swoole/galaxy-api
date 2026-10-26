<?php

namespace App\Services\Gateway;

/** Reduces engine logs to safe event metadata, never returning raw HTTP payloads. */
final class WafLogParser
{
    public function parse(string $stream, string $mode, array &$state = [], array &$observations = []): array
    {
        $observations = [];
        $plain = '';
        $length = strlen($stream);
        for ($offset = 0; $offset < $length;) {
            if ($offset + 8 <= $length && in_array(ord($stream[$offset]), [0, 1, 2], true)
                && substr($stream, $offset + 1, 3) === "\0\0\0") {
                $size = unpack('Nsize', substr($stream, $offset + 4, 4))['size'];
                if ($offset + 8 + $size > $length) break;
                $plain .= substr($stream, $offset + 8, $size);
                $offset += 8 + $size;
            } else {
                $plain .= substr($stream, $offset);
                break;
            }
        }
        $access = $state['access'] ?? [];
        $events = $state['events'] ?? [];
        $changed = [];
        foreach (explode("\n", $plain) as $line) {
            $record = json_decode($line, true);
            if (! is_array($record)) continue;
            if (($record['kind'] ?? '') === 'galaxy_waf_access' && ! empty($record['id'])) {
                $id = (string) $record['id'];
                $access[$id] = array_intersect_key($record, array_flip(['id','ip','method','uri','status','origin_status','time','msec','limit','dynamic','control_status','control_protocol','geo_country','data_source','data_status']));
                $access[$id]['uri'] = mb_substr(explode('?', (string) ($record['uri'] ?? ''))[0], 0, 2048);
                $observations[$id] = $access[$id] + ['_fresh' => true];
                if (($record['gateway'] ?? '') === '0') {
                    $events[$id]['messages'][] = ['rule_id' => 'GATEWAY', 'message' => '拒绝未认证的网关旁路请求', 'tags' => ['gateway-auth']];
                    $events[$id]['control_action'] = 'block';
                }
                if (in_array($record['dynamic'] ?? '', ['block','monitor'], true)) {
                    $events[$id]['messages'][] = ['rule_id' => 'AUTO', 'message' => '命中自动临时封禁', 'tags' => ['auto-ban']];
                    $events[$id]['control_action'] = $record['dynamic'];
                    $events[$id]['messages'] = array_slice(array_values(array_unique($events[$id]['messages'], SORT_REGULAR)), 0, 30);
                }
                if (in_array($record['dynamic'] ?? '', ['browser_challenge','browser_block','browser_monitor'], true)) {
                    $events[$id]['messages'][] = ['rule_id' => 'BROWSER', 'message' => '浏览器验证未完成', 'tags' => ['browser-challenge']];
                    $events[$id]['control_action'] = ['browser_challenge' => 'challenge', 'browser_block' => 'block', 'browser_monitor' => 'monitor'][$record['dynamic']];
                    $events[$id]['messages'] = array_slice(array_values(array_unique($events[$id]['messages'], SORT_REGULAR)), 0, 30);
                }
                if (in_array($record['dynamic'] ?? '', ['geo_block','geo_monitor','intel_block','intel_monitor'], true)) {
                    $geo = str_starts_with($record['dynamic'], 'geo_'); $tags = [$geo ? 'geo-policy' : 'ip-intelligence'];
                    if (WafCountries::valid($record['geo_country'] ?? null)) $tags[] = 'country:' . $record['geo_country'];
                    if (preg_match('/^[a-z][a-z0-9-]{1,63}$/D', $record['data_source'] ?? '')) $tags[] = 'source:' . $record['data_source'];
                    $events[$id]['messages'][] = ['rule_id' => $geo ? 'GEO' : 'INTEL', 'message' => $geo ? '命中国家/地区访问策略' : '命中 IP 情报名单', 'tags' => $tags];
                    $events[$id]['control_action'] = str_ends_with($record['dynamic'], '_block') ? 'block' : 'monitor';
                    $events[$id]['messages'] = array_slice(array_values(array_unique($events[$id]['messages'], SORT_REGULAR)), 0, 30);
                }
                if (($record['data_status'] ?? '') === 'degraded') {
                    $events[$id]['messages'][] = ['rule_id' => 'DATA', 'message' => '地域或情报数据不可用，按策略跳过该项检查', 'tags' => ['data-source-degraded']];
                    $events[$id]['control_action'] ??= 'detected';
                    $events[$id]['messages'] = array_slice(array_values(array_unique($events[$id]['messages'], SORT_REGULAR)), 0, 30);
                }
                if (in_array($record['limit'] ?? '', ['REJECTED', 'REJECTED_DRY_RUN'], true)) {
                    $events[$id]['messages'][] = ['rule_id' => 'CC', 'message' => 'CC 请求频率超出阈值', 'tags' => ['cc']];
                    $events[$id]['cc_action'] = $record['limit'] === 'REJECTED' ? 'block' : 'monitor';
                    $events[$id]['messages'] = array_slice(array_values(array_unique($events[$id]['messages'], SORT_REGULAR)), 0, 30);
                }
                if (isset($events[$id])) $changed[$id] = true;
                continue;
            }
            $tx = $record['transaction'] ?? [];
            $id = (string) ($tx['unique_id'] ?? '');
            if ($id === '') continue;
            foreach (($tx['messages'] ?? []) as $message) {
                $detail = $message['details'] ?? [];
                $events[$id]['messages'][] = [
                    'rule_id' => substr((string) ($detail['ruleId'] ?? ''), 0, 32),
                    'message' => mb_substr((string) ($message['message'] ?? ''), 0, 512),
                    'tags' => array_slice(array_values(array_filter((array) ($detail['tags'] ?? []), 'is_string')), 0, 20),
                ];
            }
            if (isset($events[$id])) {
                $events[$id]['messages'] = array_slice(array_values(array_unique($events[$id]['messages'], SORT_REGULAR)), 0, 30);
                $events[$id]['ip'] = (string) ($tx['client_ip'] ?? '');
                $events[$id]['time'] = (string) ($tx['time_stamp'] ?? '');
                $changed[$id] = true;
            }
        }
        $result = [];
        foreach ($changed as $id => $_) {
            $event = $events[$id];
            $a = $access[$id] ?? [];
            if ($a !== []) $observations[$id] ??= $a;
            $status = (int) ($a['status'] ?? 0);
            $ip = (string) ($a['ip'] ?? $event['ip'] ?? '');
            $result[] = [
                'request_id' => $id, 'occurred_at' => strtotime($a['time'] ?? $event['time'] ?? '') ?: time(),
                'client_ip' => filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '',
                'method' => substr((string) ($a['method'] ?? ''), 0, 16),
                'uri' => mb_substr(explode('?', (string) ($a['uri'] ?? ''))[0], 0, 2048),
                'status_code' => $status,
                'action' => $event['control_action'] ?? $event['cc_action'] ?? ($mode === 'monitor' ? 'monitor'
                    : ($status === 403 && ($a['origin_status'] ?? '') !== '403' ? 'block' : 'detected')),
                'messages' => array_slice($event['messages'], 0, 30),
            ];
        }
        $state = ['access' => array_slice($access, -512, null, true), 'events' => array_slice($events, -512, null, true)];
        return $result;
    }

    public function pending(array $state): array
    {
        $events = array_filter($state['events'] ?? [], static fn ($event, $id) => ! isset($state['access'][$id]), ARRAY_FILTER_USE_BOTH);
        return ['events' => array_slice($events, -128, null, true), 'access' => array_slice($state['access'] ?? [], -128, null, true)];
    }
}

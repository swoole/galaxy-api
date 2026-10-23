<?php

namespace App\Services\Gateway;

/** Reduces engine logs to safe event metadata, never returning raw HTTP payloads. */
final class WafLogParser
{
    public function parse(string $stream, string $mode): array
    {
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
        $access = [];
        $events = [];
        foreach (explode("\n", $plain) as $line) {
            $record = json_decode($line, true);
            if (! is_array($record)) continue;
            if (($record['kind'] ?? '') === 'galaxy_waf_access' && ! empty($record['id'])) {
                $id = (string) $record['id'];
                $access[$id] = $record;
                if (in_array($record['limit'] ?? '', ['REJECTED', 'REJECTED_DRY_RUN'], true)) {
                    $events[$id]['messages'][] = ['rule_id' => 'CC', 'message' => 'CC 请求频率超出阈值', 'tags' => ['cc']];
                    $events[$id]['cc_action'] = $record['limit'] === 'REJECTED' ? 'block' : 'monitor';
                }
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
                $events[$id]['ip'] = (string) ($tx['client_ip'] ?? '');
                $events[$id]['time'] = (string) ($tx['time_stamp'] ?? '');
            }
        }
        $result = [];
        foreach ($events as $id => $event) {
            $a = $access[$id] ?? [];
            $status = (int) ($a['status'] ?? 0);
            $ip = (string) ($a['ip'] ?? $event['ip'] ?? '');
            $result[] = [
                'request_id' => $id, 'occurred_at' => strtotime($a['time'] ?? $event['time'] ?? '') ?: time(),
                'client_ip' => filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '',
                'method' => substr((string) ($a['method'] ?? ''), 0, 16),
                'uri' => mb_substr(explode('?', (string) ($a['uri'] ?? ''))[0], 0, 2048),
                'status_code' => $status,
                'action' => $event['cc_action'] ?? ($mode === 'monitor' ? 'monitor'
                    : ($status === 403 && ($a['origin_status'] ?? '') !== '403' ? 'block' : 'detected')),
                'messages' => array_slice($event['messages'], 0, 30),
            ];
        }
        return $result;
    }
}

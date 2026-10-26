<?php

namespace App\Services\Gateway;

use App\Exception\AppException;
use RuntimeException;

/** Sorted, merged fixed-size intervals: bounded build memory and O(log n) disk lookups. */
final class WafIpIndex
{
    private const MAGIC = "GXIP1\0\0\0";
    private const RECORD = 33;
    public function __construct(private WafClientIp $ips) {}

    public function build(string $input, string $output): array
    {
        $handle = fopen($input, 'rb'); $ranges = []; $metadata = []; $lines = 0;
        try {
            while (($line = fgets($handle, 8194)) !== false) {
                if (++$lines > 250000 || strlen($line) > 8192) throw new AppException(422, '情报文件行数或行长度超限');
                $line = trim($line);
                if ($line === '' || in_array($line[0], ['#',';'], true)) continue;
                if ($line[0] === '{') {
                    $row = json_decode($line, true, 8, JSON_THROW_ON_ERROR);
                    if (($row['type'] ?? '') === 'metadata') { $metadata = array_intersect_key($row, array_flip(['timestamp','copyright'])); continue; }
                    $cidr = $row['cidr'] ?? null;
                } else $cidr = trim(preg_split('/[;#]/', $line, 2)[0]);
                if (! is_string($cidr)) throw new AppException(422, '情报文件须为逐行 IP/CIDR 或 Spamhaus JSONL');
                $ranges[] = $this->range($cidr);
                if (count($ranges) > (int) config('waf.data_max_entries', 100000)) throw new AppException(422, '情报网段数量超限');
            }
        } finally { fclose($handle); }
        if ($ranges === []) throw new AppException(422, '情报列表为空，不替换有效版本');
        sort($ranges, SORT_STRING);
        $file = fopen($output, 'w+b'); $count = 0; $current = null;
        try {
            $this->write($file, self::MAGIC . pack('NN', 0, self::RECORD));
            foreach ($ranges as $range) {
                if ($current !== null && $range[0] === $current[0] && strcmp(substr($range, 1, 16), $this->increment(substr($current, 17, 16))) <= 0) {
                    if (strcmp(substr($range, 17, 16), substr($current, 17, 16)) > 0) $current = substr($current, 0, 17) . substr($range, 17, 16);
                } else {
                    if ($current !== null) { $this->write($file, $current); ++$count; }
                    $current = $range;
                }
            }
            if ($current !== null) { $this->write($file, $current); ++$count; }
            fseek($file, 8); $this->write($file, pack('N', $count));
        } finally { fclose($file); }
        return ['record_count' => $count, 'published_at' => (int) ($metadata['timestamp'] ?? 0),
            'copyright' => mb_substr((string) ($metadata['copyright'] ?? ''), 0, 255)];
    }

    public function contains(string $file, string $ip): bool
    {
        $canonical = $this->ips->normalize($ip);
        if ($canonical === null) throw new AppException(400, 'Invalid client IP');
        $raw = inet_pton($canonical); $family = strlen($raw) === 4 ? "\x04" : "\x06";
        $key = $family . str_pad($raw, 16, "\0", STR_PAD_LEFT);
        $handle = @fopen($file, 'rb');
        if ($handle === false) throw new RuntimeException('情报索引文件不可用');
        try {
            $header = fread($handle, 16);
            if (strlen($header) !== 16 || substr($header, 0, 8) !== self::MAGIC) throw new RuntimeException('情报索引格式无效');
            $info = unpack('Ncount/Nsize', substr($header, 8));
            if ($info['size'] !== self::RECORD || $info['count'] < 1 || fstat($handle)['size'] !== 16 + self::RECORD * $info['count']) throw new RuntimeException('情报索引不完整');
            $low = 0; $high = $info['count'] - 1; $candidate = '';
            while ($low <= $high) {
                $mid = intdiv($low + $high, 2); fseek($handle, 16 + $mid * self::RECORD); $record = fread($handle, self::RECORD);
                if (strlen($record) !== self::RECORD) throw new RuntimeException('情报索引读取不完整');
                if (strcmp(substr($record, 0, 17), $key) <= 0) { $candidate = $record; $low = $mid + 1; } else $high = $mid - 1;
            }
            return $candidate !== '' && $candidate[0] === $family && strcmp(substr($key, 1), substr($candidate, 17, 16)) <= 0;
        } finally { fclose($handle); }
    }

    private function range(string $cidr): string
    {
        [$ip,$prefix] = array_pad(explode('/', $cidr, 2), 2, null); $canonical = $this->ips->normalize($ip);
        if ($canonical === null || ($prefix !== null && ! ctype_digit($prefix))) throw new AppException(422, '情报包含无效 IP/CIDR');
        $raw = inet_pton($canonical); $bits = $prefix === null ? strlen($raw) * 8 : (int) $prefix;
        if ($prefix !== null && strlen(inet_pton($ip)) === 16 && strlen($raw) === 4) $bits -= 96;
        if ($bits < (strlen($raw) === 4 ? 8 : 16) || $bits > strlen($raw) * 8) throw new AppException(422, '情报网段过宽或前缀无效（IPv4 最宽 /8，IPv6 最宽 /16）');
        $first = $last = '';
        for ($i = 0; $i < strlen($raw); ++$i) {
            $mask = max(0, min(8, $bits - $i * 8)); $mask = (0xff << (8 - $mask)) & 0xff;
            $first .= chr(ord($raw[$i]) & $mask); $last .= chr(ord($raw[$i]) | (0xff ^ $mask));
        }
        return (strlen($raw) === 4 ? "\x04" : "\x06") . str_pad($first, 16, "\0", STR_PAD_LEFT) . str_pad($last, 16, "\0", STR_PAD_LEFT);
    }

    private function increment(string $bytes): string
    {
        for ($i = strlen($bytes) - 1; $i >= 0; --$i) if (ord($bytes[$i]) < 255) { $bytes[$i] = chr(ord($bytes[$i]) + 1); return $bytes; } else $bytes[$i] = "\0";
        return str_repeat("\xff", 16); // Saturate at IPv6's final address.
    }
    private function write($file, string $bytes): void
    { if (fwrite($file, $bytes) !== strlen($bytes)) throw new RuntimeException('写入情报索引失败'); }
}

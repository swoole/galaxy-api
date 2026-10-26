<?php

namespace App\Services\Gateway;

use RuntimeException;

/** Decodes bounded, timestamped Docker frames and carries partial JSON lines across time windows. */
final class WafLogBatch
{
    private string $pending = '';
    private int $pendingSince = PHP_INT_MAX;

    public static function timestamp(int $ns): string
    {
        return intdiv($ns, 1000000000) . '.' . str_pad((string) ($ns % 1000000000), 9, '0', STR_PAD_LEFT);
    }

    public function decode(string $raw): array
    {
        $frames = [];
        for ($offset = 0, $length = strlen($raw); $offset < $length;) {
            if ($offset + 8 > $length || ! in_array(ord($raw[$offset]), [1, 2], true) || substr($raw, $offset + 1, 3) !== "\0\0\0") throw new RuntimeException('Docker 日志帧不完整，保留游标重试');
            $size = unpack('Nsize', substr($raw, $offset + 4, 4))['size'];
            if ($size > 1048576 || $offset + 8 + $size > $length) throw new RuntimeException('Docker 日志帧超限或截断，保留游标重试');
            $payload = substr($raw, $offset + 8, $size); $offset += 8 + $size;
            if (! preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.(\d{1,9}))?(Z|[+-]\d{2}:\d{2}) /', $payload, $match)) throw new RuntimeException('日志缺少 Docker 时间戳，拒绝推进游标');
            $seconds = strtotime($match[1] . $match[3]);
            if ($seconds === false) throw new RuntimeException('Docker 日志时间戳无效，保留游标重试');
            $ns = $seconds * 1000000000 + (int) str_pad($match[2] ?? '', 9, '0');
            $frames[] = ['ns' => $ns, 'data' => substr($payload, strlen($match[0]))];
        }
        return $frames;
    }

    public function lines(array $frames, int $from, int $until): string
    {
        $complete = '';
        foreach ($frames as $frame) {
            if ($frame['ns'] < $from || $frame['ns'] >= $until) continue;
            if ($this->pending === '') $this->pendingSince = $frame['ns'];
            $this->pending .= $frame['data'];
            if (strlen($this->pending) > 1048576) throw new RuntimeException('单行审计日志超过 1 MiB，保留游标并报告缺口');
            $last = strrpos($this->pending, "\n");
            if ($last !== false) {
                $complete .= substr($this->pending, 0, $last + 1);
                $this->pending = substr($this->pending, $last + 1);
                $this->pendingSince = $this->pending === '' ? PHP_INT_MAX : $frame['ns'];
            }
        }
        return $complete;
    }

    public function checkpoint(int $until): int
    {
        return min($until, $this->pendingSince);
    }
}

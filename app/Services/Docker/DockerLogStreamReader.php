<?php

namespace App\Services\Docker;

use RuntimeException;

/** Decode Docker's HTTP chunked log stream with a strict body budget and completion marker. */
final class DockerLogStreamReader
{
    public function read(callable $receive, int $limit, float $deadline): ?string
    {
        $buffer = ''; $body = ''; $remaining = null; $delimiter = false; $ended = false;
        while (true) {
            if ($ended && strlen($buffer) >= 2) {
                if ($buffer !== "\r\n") throw new RuntimeException('Docker 日志 HTTP 结束块无效，保留游标');
                return $body;
            }
            if ($delimiter && strlen($buffer) >= 2) {
                if (substr($buffer, 0, 2) !== "\r\n") throw new RuntimeException('Docker 日志 HTTP 分块边界损坏，保留游标');
                $buffer = substr($buffer, 2); $delimiter = false; $remaining = null;
                continue;
            }
            if (! $ended && ! $delimiter && $remaining === null && ($lineEnd = strpos($buffer, "\r\n")) !== false) {
                $header = substr($buffer, 0, $lineEnd);
                if (! preg_match('/^[0-9a-fA-F]{1,8}$/D', $header)) throw new RuntimeException('Docker 日志未返回有效 HTTP 分块，保留游标');
                $remaining = hexdec($header); $buffer = substr($buffer, $lineEnd + 2);
                if ($remaining === 0) { $ended = true; continue; }
                if (strlen($body) + $remaining > $limit) return null;
            }
            if ($remaining !== null && $remaining > 0 && $buffer !== '') {
                $take = min($remaining, strlen($buffer));
                $body .= substr($buffer, 0, $take); $buffer = substr($buffer, $take); $remaining -= $take;
                if ($remaining === 0) { $delimiter = true; continue; }
            }
            if ($remaining === null && strlen($buffer) > 80) throw new RuntimeException('Docker 日志 HTTP 分块头超限，保留游标');
            if (microtime(true) >= $deadline) throw new RuntimeException('Docker 日志读取超时，保留游标');
            $chunk = $receive(max(0.001, $deadline - microtime(true)));
            if ($chunk === false) throw new RuntimeException('Docker 日志流在结束块前中断，保留游标');
            if (strlen($chunk) > 1048576) throw new RuntimeException('Docker 日志传输分片超限，保留游标');
            $buffer .= $chunk;
        }
    }
}

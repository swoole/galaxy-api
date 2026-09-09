<?php

namespace App\Services\Docker;

/**
 * A small, serializable Swoole pipe frame.
 *
 * Custom process pipes are datagrams with a practical payload limit close to
 * 64 KiB, so Agent relay messages are split before they enter Swoole IPC.
 */
final class AgentIpcMessage
{
    public function __construct(
        public string $transferId,
        public int $index,
        public int $total,
        public string $payload
    ) {}
}

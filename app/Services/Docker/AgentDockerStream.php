<?php

namespace App\Services\Docker;

use Swoole\Coroutine\Channel;

class AgentDockerStream
{
    private bool $closed = false;

    public function __construct(
        private AgentRelayService $relay,
        private array $connection,
        private string $id,
        private Channel $channel
    ) {}

    public function send(string $data): bool
    {
        return ! $this->closed && $this->relay->sendStream($this->connection, $this->id, $data);
    }

    public function recv(float $timeout = 86400): string|false
    {
        while (! $this->closed) {
            $message = $this->channel->pop($timeout);
            if (! is_array($message) || ($message['type'] ?? '') === 'stream_closed') {
                $this->close();
                return false;
            }
            if (($message['type'] ?? '') === 'stream_data') {
                $data = base64_decode((string) ($message['data'] ?? ''), true);
                return $data === false ? '' : $data;
            }
        }
        return false;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->relay->closeStream($this->id, $this->connection);
    }
}

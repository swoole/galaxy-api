<?php

namespace App\Listener;

use App\Services\Docker\AgentIpcMessage;
use App\Services\Docker\AgentRelayService;
use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Framework\Event\OnPipeMessage;
use Hyperf\Process\Event\PipeMessage;

class DockerAgentIpcListener implements ListenerInterface
{
    public function __construct(private AgentRelayService $relay) {}

    public function listen(): array
    {
        return [OnPipeMessage::class, PipeMessage::class];
    }

    public function process(object $event): void
    {
        if ($event instanceof OnPipeMessage && $event->data instanceof AgentIpcMessage) {
            $this->relay->receiveIpcChunk($event->data, $event->server);
            return;
        }
        if ($event instanceof PipeMessage && $event->data instanceof AgentIpcMessage) {
            $this->relay->receiveIpcChunk($event->data);
        }
    }
}

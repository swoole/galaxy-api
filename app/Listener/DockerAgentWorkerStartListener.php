<?php

namespace App\Listener;

use App\Model\Cluster;
use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Framework\Event\AfterWorkerStart;

class DockerAgentWorkerStartListener implements ListenerInterface
{
    public function listen(): array
    {
        return [AfterWorkerStart::class];
    }

    public function process(object $event): void
    {
        if (! $event instanceof AfterWorkerStart || $event->workerId !== 0) {
            return;
        }
        Cluster::where(function ($query): void {
            $query->where('endpoint', 'like', 'agent://%');
        })->update(['status' => Cluster::STATUS_OFFLINE, 'agent_status' => 'offline']);
    }
}

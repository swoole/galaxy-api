<?php

namespace App\Listener;

use Hyperf\AsyncQueue\Event\QueueLength;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\Event\Contract\ListenerInterface;

class QueueClearTimeoutListener implements ListenerInterface
{
    /**
     * @var StdoutLoggerInterface
     */
    protected $logger;

    /**
     * @var string[]
     */
    protected $channels = [
        'timeout',
    ];

    public function __construct(StdoutLoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function listen(): array
    {
        return [
            QueueLength::class,
        ];
    }

    /**
     * @param QueueLength $event
     */
    public function process(object $event): void
    {
        if (! $event instanceof QueueLength) {
            return;
        }

        if (! in_array($event->key, $this->channels)) {
            return;
        }

        if ($event->length == 0) {
            return;
        }

        $event->driver->flush($event->key);

        $this->logger->info(sprintf('%s channel flush %d messages success.', $event->key, $event->length));
    }
}

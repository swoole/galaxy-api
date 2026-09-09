<?php

/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */
namespace App\Listener;

use Hyperf\Database\Events\StatementPrepared;
use Hyperf\Event\Annotation\Listener;
use Hyperf\Event\Contract\ListenerInterface;
use PDO;

class FetchModeListener implements ListenerInterface
{
    public function listen(): array
    {
        return [
            StatementPrepared::class,
        ];
    }

    public function process(object $event): void
    {
        if ($event instanceof StatementPrepared) {
            $event->statement->setFetchMode(PDO::FETCH_ASSOC);
        }
    }
}

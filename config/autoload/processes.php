<?php

declare(strict_types=1);
/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */
return [
    Hyperf\AsyncQueue\Process\ConsumerProcess::class,
    App\Process\AgentPresenceProcess::class,
    App\Process\SwarmImageInventoryProcess::class,
    App\Process\AcmeBackupProcess::class,
    App\Process\ProjectObserverProcess::class,
    App\Process\ProjectRetentionProcess::class,
];

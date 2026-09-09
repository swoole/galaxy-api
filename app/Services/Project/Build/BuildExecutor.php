<?php

namespace App\Services\Project\Build;

use App\Model\Build;

interface BuildExecutor
{
    public function orchestratorType(): string;

    public function run(Build $build): array;

    public function cancel(Build $build): void;

    public function reconcileRunner(Build $build): bool;
}

<?php

namespace App\Command;

use Hyperf\Command\Command as HyperfCommand;

abstract class AbstractCommand extends HyperfCommand
{
    protected function infof($message, ...$arguments)
    {
        return $this->output->info(sprintf($message, ...$arguments));
    }

    protected function warningf($message, ...$arguments)
    {
        return $this->output->warning(sprintf($message, ...$arguments));
    }

    protected function errorf($message, ...$arguments)
    {
        return $this->output->error(sprintf($message, ...$arguments));
    }
}

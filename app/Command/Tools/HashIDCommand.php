<?php

namespace App\Command\Tools;

use App\Command\AbstractCommand;
use App\Support\Functions;
use Hyperf\Command\Annotation\Command;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * @Command
 */
class HashIDCommand extends AbstractCommand
{
    public function __construct(ContainerInterface $container)
    {
        parent::__construct('tools:hashid');
    }

    public function configure()
    {
        parent::configure();
        $this->setDescription('Hash ID encode or decode.');
        $this->addOption('encode', 'E', InputOption::VALUE_NONE, 'use the encode mode');
        $this->addArgument('id', InputArgument::REQUIRED, 'the ID');
    }

    public function handle()
    {
        $id = $this->input->getArgument('id');
        if ($this->input->getOption('encode')) {
            // encode mode
            $encoded = Functions::encodeID($id);
            $this->infof('encode %s to %s', $id, $encoded);
        } else {
            // decode mode
            $decoded = Functions::decodeID($id, false, false);
            $this->infof('decode %s to %s', $id, json_encode($decoded));
        }
    }
}

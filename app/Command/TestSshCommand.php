<?php

/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */
namespace App\Command;

use App\Model\UserSshKey;
use Hyperf\Command\Annotation\Command;
use Hyperf\Command\Command as HyperfCommand;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * @Command
 */
class TestSshCommand extends HyperfCommand
{
    /**
     * @var ContainerInterface
     */
    protected $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;

        parent::__construct('test:ssh');
    }

    public function configure()
    {
        parent::configure();
        $this->setDescription('Create a new service class');
        $this->addOption('uid', null, InputOption::VALUE_REQUIRED, 'uid');
        $this->addOption('repo', null, InputOption::VALUE_REQUIRED, 'repo');
        $this->addOption('times', null, InputOption::VALUE_OPTIONAL, 'times');
    }

    public function handle()
    {
        $uid = $this->input->getOption('uid');
        $repo = $this->input->getOption('repo');
        $times = $this->input->getOption('times');
        if (empty($times)) {
            $times = 20;
        }

        $fails = 0;
        /** @var UserSshKey */
        $sshKey = $this->container->get(UserSshKey::class);
        while ($times-- > 0) {
            $authed = $sshKey->authed($uid, $repo);
            if ($authed == false) {
                $fails++;
            }
        }

        $this->output->info('end with fails: ' . $fails);
    }
}

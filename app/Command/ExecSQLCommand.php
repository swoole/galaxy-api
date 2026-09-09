<?php

namespace App\Command;

use Hyperf\Command\Command as HyperfCommand;
use Hyperf\Command\Annotation\Command;
use Hyperf\DbConnection\Db;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * @Command
 */
class ExecSQLCommand extends HyperfCommand
{
    /**
     * @var ContainerInterface
     */
    protected $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;

        parent::__construct('exec:sql');
    }

    public function configure()
    {
        parent::configure();
        $this->setDescription('Execure the SQL.');
        $this->addOption('sql', null, InputOption::VALUE_REQUIRED, 'the SQL to execure');
        $this->addOption('method', null, InputOption::VALUE_OPTIONAL, 'the method: select, update, insert, delete, statement');
        $this->addOption('pool', null, InputOption::VALUE_OPTIONAL, 'Which the mysql pool to execure');
    }

    public function handle()
    {
        $pool = (int) $this->input->getOption('pool') ?: 'default';
        $method = $this->input->getOption('method') ?: 'select';
        if (!in_array($method, ['select', 'update', 'insert', 'delete', 'statement'])) {
            return $this->output->error('the method must be one of `select, update, insert, delete, statement`');
        }

        $sql = $this->input->getOption('sql');

        $result = Db::connection($pool)->{$method}($sql);
        if (is_array($result) && !empty($result[0])) {
            $headers = array_merge(['No.'], array_keys($result[0]));
            $rows = [];
            foreach ($result as $index => $item) {
                $rows[] = array_merge([$index + 1], array_values($item));
            }
            $this->output->table($headers, $rows);
        } else {
            var_dump($result);
        }
    }
}

<?php
/**
 * Created by PhpStorm.
 * User: wangkaihui
 * Date: 16/7/22
 * Time: 下午6:27
 */

namespace Trendi\Foundation\Command\Pool;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Start extends Command
{
    protected function configure()
    {
        $this->setName('pool:start')
            ->setDescription('start the pool server ');
        $this->addOption('--daemonize', '-d', InputOption::VALUE_NONE, 'Is daemonize ?');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        PoolBase::operate("start", $output, $input);
    }
}

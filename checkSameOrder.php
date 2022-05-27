#!/usr/bin/env php
<?php

require_once __DIR__ . '/vendor/autoload.php';

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Helper\Table;
use totum\config\Conf;

class CheckSameOrder extends Command
{
    protected function configure()
    {
        $this->setName('get-order-duplicated-fields');
        $this->addOption('all', '', InputOption::VALUE_NONE, 'Display oll duplicated by sort fields - with correct order by name');
        $this->addOption('schema', '', InputOption::VALUE_OPTIONAL, 'Schema name to search');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $Conf = new Conf();
        if ($input->getOption('schema')) {
            $schemas = [$input->getOption('schema')];
        } elseif (!method_exists($Conf, 'setHostSchema')) {
            $schemas = [$Conf->getSchema(false)];
        } else {
            $schemas = array_unique(array_values($Conf->getSchemas()));
        }
        $simple = !$input->getOption('all');

        foreach ($schemas as $s) {
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion('Check same order fields from schema "' . $s . '"?', true);
            if ($helper->ask($input, $output, $question)) {
                $r = $Conf->getSql(null, false)->getAll("select t1.table_name->>'v' as table, 
       
       CASE WHEN t1.category->>'v'='footer' AND t1.data->'v'->>'column'!='' THEN 'c-footer' ELSE t1.category->>'v' END as category, 
       
       t1.ord->>'v' as ord, t2.name->>'v' as name1, t1.name->>'v' as name2, t1.version->>'v' as version 
from \"$s\".tables_fields t1 left join \"$s\".tables_fields t2 ON t1.table_id->>'v'=t2.table_id->>'v' AND t1.ord->>'v'=t2.ord->>'v' AND t1.category->>'v'=t2.category->>'v' 
                                                                            AND  (t2.category->>'v'!='footer' OR ((t1.data->'v'->>'column'='' AND t2.data->'v'->>'column'='') OR (t1.data->'v'->>'column'!='' AND t2.data->'v'->>'column'!='')))   
                                                                            AND t1.id>t2.id
                                                                            AND ((t1.version->>'v'=t2.version->>'v') OR (t1.version->>'v' is null AND t2.version->>'v' is null))
where t2.ord is not null".($simple?" AND t1.name->>'v'<t2.name->>'v' AND t1.table_name->>'v' not in ('ttm__search_settings')":''));

                array_multisort(array_column($r, 'table'), array_column($r, 'category'), array_column($r, 'ord'), $r);

                if (empty($r)) {
                    $output->writeln('<info>Duplicated by Sort fields are not found in schema "' . $s . '".</info>');
                } else {
                    $table = new Table($output);
                    $table
                        ->setHeaders(['table_name', 'category', 'sort', 'name1', 'name2', 'version'])
                        ->setRows($r);
                    $table->render();


                }

            }
        }
    }
}


$app = new Application();
$app->add($o = new CheckSameOrder());
$app->setDefaultCommand($o->getName());
$app->run();

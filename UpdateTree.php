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
use totum\common\Auth;
use totum\common\Totum;
use totum\common\TotumInstall;
use totum\common\User;
use totum\config\Conf;
use totum\common\FormatParamsForSelectFromTable;

class UpdateTree extends Command
{


    protected function configure()
    {
        $this->setName('update-tree');
        $this->addOption('schema', '', InputOption::VALUE_OPTIONAL, 'Schema name to update');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $Conf = new Conf();
        if ($input->getOption('schema')) {
            $schemas = [$input->getOption('schema')];
        } elseif (!method_exists($Conf, 'setHostSchema')) {
            $schemas = [$Conf->getSchema(false)];
        } else {
            $schemas = array_unique(array_values($Conf->getSchemas()));
        }


        foreach ($schemas as $s) {
            $this->updateSchema($Conf, $s, $output);
        }
        return 0;
    }

    protected function updateSchema($Conf, $schemaName, OutputInterface $output)
    {
        if (method_exists($Conf, 'setHostSchema')) {
            $Conf->setHostSchema(null, $schemaName);
        }

        $path = dirname(__FILE__) . DIRECTORY_SEPARATOR . 'totum' . DIRECTORY_SEPARATOR . 'moduls' . DIRECTORY_SEPARATOR . 'install' . DIRECTORY_SEPARATOR;
        $TotumInstall = new TotumInstall(
            $Conf,
            new User(['login' => 'service', 'roles' => ["1"], 'id' => 1], $Conf),
            $output
        );
        $cont = $TotumInstall->getDataFromFile($path . 'start.json.gz.ttm');
        $cont = $TotumInstall->schemaTranslate($cont, $path . $Conf->getLang() . '.json', $Conf->getLang() !== 'en' ? $path . 'en.json' : null);

        $tree = $this->getTree($cont['tree']);

        $Totum = new Totum($Conf, Auth::loadAuthUserByLogin($Conf, 'service', false));
        $ttmUpdates = $Totum->getTable('ttm__updates');
        $matchesName = 'totum_' . (new Conf())->getLang();
        $matchesAll = $ttmUpdates->getTbl()['params']['h_matches']['v'];

        if (!$matchesAll[$matchesName] ?? []) {
            die('matches "' . $matchesName . '" not found');
        }
        $matches = $matchesAll[$matchesName]['tree'];

        $schemaSortedTree = [];
        $reloadSchemaTree = function () use ($Totum, &$schemaSortedTree) {
            foreach ($Totum->getNamedModel(\totum\models\TreeV::class)->getAll([], '*', 'ord') as $b) {
                $schemaSortedTree[$b['id']] = $b;
            }
        };

        $reloadSchemaTree();


        $schemaTreeBranchId = $matches[1] ?? null;
        if (!$schemaTreeBranchId) {
            die('Tree by matches not found');
        }
        $treeTable = $Totum->getTable('tree');
        $processBranch = function ($treeChildren, $schemaTreeBranchId) use ($output, $treeTable, &$processBranch, &$schemaSortedTree, &$matches, &$reloadSchemaTree) {


            $sortedBranches = array_keys($treeChildren);

            $insertedBranches = [];
            $schemaIds = [];
            $matchedSortedBranches = [];
            foreach ($sortedBranches as $inIds) {
                if (!($matches[$inIds] ?? null) || !($schemaSortedTree[$matches[$inIds]] ?? null)) {
                    //Добавить ветку
                    $branchForInsert = $treeChildren[$inIds];
                    $branchForInsert['parent_id'] = $schemaTreeBranchId;
                    unset($branchForInsert['id']);

                    $id = $treeTable->actionInsert(
                        $branchForInsert
                    )[0];
                    $output->writeln('Added branch: ' . $branchForInsert['title']);
                    //Добавить match
                    $matches[$inIds] = $id;
                    $reloadSchemaTree();
                    $insertedBranches[] = $id;
                } else {
                    if ($treeChildren[$inIds]['title'] != $schemaSortedTree[$matches[$inIds]]['title']) {
                        $treeTable->reCalculateFromOvers(
                            ['modify' => [$matches[$inIds] => ['title' => $treeChildren[$inIds]['title']]]]
                        );
                        $output->writeln('Renamed: ' . $schemaSortedTree[$matches[$inIds]]['title'] . ' -> ' . $treeChildren[$inIds]['title']);
                    }
                    $schemaIds[] = $matches[$inIds];
                    $matchedSortedBranches[] = $matches[$inIds];
                }
            }
            $schemaIds = [...$schemaIds, ...$insertedBranches];
            $schemaBranchSortedChildren = [];

            foreach ($schemaSortedTree as $b) {
                if (in_array($b['id'], $schemaIds)) {
                    if ($b['parent_id'] != $schemaTreeBranchId) {
                        $treeTable->actionSet(['parent_id' => $schemaTreeBranchId], [['field' => 'id', 'value' => $b['id'], 'operator' => '=']]);
                    }
                    $schemaBranchSortedChildren[] = $b['id'];
                }
            }
            if ($schemaBranchSortedChildren != $matchedSortedBranches) {
                $modify = [];
                $i = 0;
                foreach ($sortedBranches as $id) {
                    $i += 10;
                    $modify[$matches[$id]] = ['ord' => $i];
                }
                $treeTable->reCalculateFromOvers(['modify' => $modify]);
                $output->writeln('Reordered branch: ' . $schemaSortedTree[$schemaTreeBranchId]['title']);
            }

            foreach ($treeChildren as $ch) {
                if ($ch['children'] ?? false) {
                    $processBranch($ch['children'], $matches[$ch['id']]);
                }
            }
        };

        $Totum->transactionStart();
        $output->writeln('--- Schema ' . $schemaName . ' start');
        $processBranch($tree[1]['children'], $schemaTreeBranchId);


        $inTables = [];
        foreach ($cont['tables'] as $t) {
            $inTables[$t['table']] = $matches[$t['settings']['tree_node_id']];
        }

        $TablesTables = $Totum->getTable('tables');
        $tables = $TablesTables->getByParams((new FormatParamsForSelectFromTable)->where('name', array_keys($inTables))->field('name')->field('id')->field('tree_node_id')->params(), 'rows');
        $schemaTables = [];
        foreach ($tables as $table) {
            $schemaTables[$table['name']] = $table;
        }

        $tablesForMove = [];
        $tablesForMoveNames = [];
        foreach ($inTables as $name => $treeId) {
            $table = $schemaTables[$name];
            if ($table['tree_node_id'] != $treeId) {
                $tablesForMove[$table['id']] = ['tree_node_id' => $treeId];
                $tablesForMoveNames[] = $name;
            }
        }
        if ($tablesForMove) {
            $TablesTables->reCalculateFromOvers([
                    'modify'=> $tablesForMove
            ]);
            $output->writeln('Moved in tree tables: ' . implode(', ', $tablesForMoveNames));
        }


        $matchesAll[$matchesName]['tree'] = $matches;
        $ttmUpdates->actionSet(['h_matches' => $matchesAll], []);
        $Totum->transactionCommit();
        $output->writeln('--- Schema ' . $schemaName . ' done');
    }

    /**
     * @param $tree1
     * @return array
     * @throws Exception
     */
    protected function getTree($tree1): array
    {
        $tree = [];
        $treeIndex = [];
        $InTree = $tree1;
        $check = 1000;

        while (count($InTree) && $check--) {
            foreach ($InTree as $i => $branch) {
                if (!$branch['parent_id']) {
                    $tree[$branch['id']] = $branch;
                    $treeIndex[$branch['id']] = &$tree[$branch['id']];
                    unset($InTree[$i]);
                } elseif ($treeIndex[$branch['parent_id']] ?? null) {
                    $treeIndex[$branch['parent_id']]['children'] = $treeIndex[$branch['parent_id']]['children'] ?? [];
                    $treeIndex[$branch['parent_id']]['children'][$branch['id']] = $branch;
                    $treeIndex[$branch['id']] = &$treeIndex[$branch['parent_id']]['children'][$branch['id']];
                    unset($InTree[$i]);
                }
            }
        }
        if (!$check) {
            throw new Exception('Not valid tree in schema');
        }
        return $tree;
    }
}


$app = new Application();
$app->add($o = new UpdateTree());
$app->setDefaultCommand($o->getName());
$app->run();

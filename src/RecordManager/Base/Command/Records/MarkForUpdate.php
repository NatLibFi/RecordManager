<?php

/**
 * Mark Records For Solr Update
 *
 * PHP version 8
 *
 * Copyright (C) The National Library of Finland 2011-2021.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */

namespace RecordManager\Base\Command\Records;

use RecordManager\Base\Command\AbstractBase;
use RecordManager\Base\Utils\PerformanceCounter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Mark Records For Solr Update
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class MarkForUpdate extends AbstractBase
{
    /**
     * Configure the command.
     *
     * @return void
     */
    protected function configure()
    {
        $this
            ->setDescription('Mark records of a data source to be updated in Solr')
            ->addOption(
                'source',
                null,
                InputOption::VALUE_REQUIRED,
                'A comma-separated list of data sources to mark'
            )->addOption(
                'single',
                null,
                InputOption::VALUE_REQUIRED,
                'Mark only the specified record'
            );
    }

    /**
     * Mark records to be updated
     *
     * @param InputInterface  $input  Console input
     * @param OutputInterface $output Console output
     *
     * @return int 0 if everything went fine, or an exit code
     */
    protected function doExecute(InputInterface $input, OutputInterface $output)
    {
        $sourceId = $input->getOption('source');
        $singleId = $input->getOption('single') ?: '';

        if (empty($sourceId) && empty($singleId)) {
            $this->logger
                ->logFatal('markForUpdate', 'No source or record id specified');
            return Command::INVALID;
        }

        if (empty($sourceId)) {
            $this->logger
                ->logInfo('marForUpdate', "Marking record $singleId updated");
            $this->touchRecords('', $singleId);
        } else {
            foreach (explode(',', $sourceId) as $source) {
                $this->logger
                    ->logInfo('markDeleted', "Marking $source updated");
                $this->touchRecords($source, $singleId);
            }
        }

        return Command::SUCCESS;
    }

    /**
     * Touch record(s)
     *
     * @param string $sourceId Data source id
     * @param string $singleId Single record id
     *
     * @return void
     */
    protected function touchRecords(string $sourceId, string $singleId): void
    {
        $params = ['deleted' => false, 'update_needed' => false];
        if ($sourceId) {
            $params['source_id'] = $sourceId;
        }
        if ($singleId) {
            $params['_id'] = $singleId;
        }
        $total = $this->db->countRecords($params);
        $count = 0;
        $this->logger->logInfo('markForUpdate', "Marking $total records for update");
        $pc = new PerformanceCounter();

        $this->db->iterateRecords(
            $params,
            [],
            function ($record) use (&$count, $pc) {
                $this->db->updateRecord(
                    $record['_id'],
                    [
                        'updated' => $this->db->getTimestamp(),
                    ]
                );

                ++$count;
                if ($count % 1000 == 0) {
                    $pc->add($count);
                    $avg = $pc->getSpeed();
                    $this->logger->logInfo(
                        'markForUpdate',
                        "$count records marked for update, $avg records/sec"
                    );
                }
            }
        );
        $this->logger->logInfo(
            'markForUpdate',
            "Completed with $count records marked for update"
        );
    }
}

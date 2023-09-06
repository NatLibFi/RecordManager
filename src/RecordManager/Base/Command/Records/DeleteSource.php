<?php

/**
 * Delete Records
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
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Delete Records
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class DeleteSource extends AbstractBase
{
    /**
     * Configure the command.
     *
     * @return void
     */
    protected function configure()
    {
        $this
            ->setDescription('Delete a data source from database')
            ->setHelp(
                "Deletes all records of a data source from RecordManager's database"
            )->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                'Force deletion even if deduplication is enabled for the source'
                . ' (see also markdeleted)'
            )
            ->addArgument(
                'source',
                InputArgument::REQUIRED,
                'Data source id'
            );
    }

    /**
     * Delete records of a single data source from the database
     *
     * @param InputInterface  $input  Console input
     * @param OutputInterface $output Console output
     *
     * @return int 0 if everything went fine, or an exit code
     */
    protected function doExecute(InputInterface $input, OutputInterface $output)
    {
        $sourceId = $input->getArgument('source');
        $force = $input->getOption('force');

        if ($settings = $this->dataSourceConfig[$sourceId] ?? null) {
            if ($settings['dedup'] ?? false) {
                if ($force) {
                    $this->logger->logWarning(
                        'deleteSource',
                        "Deduplication enabled for '$sourceId' but deletion forced "
                        . ' - may lead to orphaned dedup records'
                    );
                } else {
                    $this->logger->logError(
                        'deleteSource',
                        "Deduplication enabled for '$sourceId', aborting "
                        . '(use markdeleted instead)'
                    );
                    return Command::INVALID;
                }
            }
        }

        $params = [];
        $params['source_id'] = $sourceId;
        $this->logger
            ->logInfo('deleteSource', "Creating record list for '$sourceId'");

        $params = ['source_id' => $sourceId];
        $total = $this->db->countRecords($params);
        $count = 0;
        $this->logger->logInfo(
            'deleteSource',
            "Deleting $total records from '$sourceId'"
        );
        $pc = new PerformanceCounter();
        $dedupHandler = $this->dedupHandler;
        $this->db->iterateRecords(
            $params,
            [],
            function ($record) use (&$count, $pc, $sourceId, $dedupHandler) {
                if (isset($record['dedup_id'])) {
                    $dedupHandler->removeFromDedupRecord(
                        $record['dedup_id'],
                        $record['_id']
                    );
                }
                $this->db->deleteRecord($record['_id']);

                ++$count;
                if ($count % 1000 == 0) {
                    $pc->add($count);
                    $avg = $pc->getSpeed();
                    $this->logger->logInfo(
                        'deleteSource',
                        "$count records deleted from '$sourceId', $avg records/sec"
                    );
                }
            }
        );
        $this->logger->logInfo(
            'deleteSource',
            "Completed with $count records deleted from '$sourceId'"
        );

        $this->logger->logInfo(
            'deleteSource',
            "Deleting last harvest date from data source '$sourceId'"
        );
        $this->db->deleteState("Last Harvest Date $sourceId");
        $this->logger->logInfo('deleteSource', "Deletion of $sourceId completed");

        return Command::SUCCESS;
    }
}

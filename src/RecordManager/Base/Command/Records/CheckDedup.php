<?php

/**
 * Check Dedup Records
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
 * Check Dedup Records
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class CheckDedup extends AbstractBase
{
    /**
     * Configure the command.
     *
     * @return void
     */
    protected function configure()
    {
        $this
            ->setDescription('Check consistency of deduplicated records')
            ->setHelp(
                'Checks the consistency of deduplication records and links between'
                . ' the records.'
            )->addOption(
                'single',
                null,
                InputOption::VALUE_REQUIRED,
                'Process only the specified record'
            )->addOption(
                'strict',
                null,
                InputOption::VALUE_NONE,
                'Check all dedup groups for compatible member records'
            );
    }

    /**
     * Verify consistency of dedup records links with actual records
     *
     * @param InputInterface  $input  Console input
     * @param OutputInterface $output Console output
     *
     * @return int 0 if everything went fine, or an exit code
     */
    protected function doExecute(InputInterface $input, OutputInterface $output)
    {
        $singleId = $input->getOption('single');
        $strict = $input->getOption('strict');
        $this->logger->logInfo(
            'checkDedup',
            'Checking dedup record consistency '
                . ($strict ? 'including' : 'excluding')
                . ' member record compatibility'
        );

        $params = [];
        if ($singleId) {
            $record = $this->db->getRecord($singleId);
            if (!$record) {
                $this->logger->logInfo(
                    'checkDedup',
                    'No record found with the given ID'
                );
                return Command::SUCCESS;
            }
            if (empty($record['dedup_id'])) {
                $this->logger->logInfo(
                    'checkDedup',
                    "Record $singleId not deduplicated"
                );
                return Command::SUCCESS;
            }
            $params['_id'] = $record['dedup_id'];
        }

        $this->logger->logInfo('checkDedup', 'Checking dedup records');
        $count = 0;
        $fixed = 0;
        $pc = new PerformanceCounter();
        $this->db->iterateDedups(
            $params,
            ['projection' => ['_id' => 1]],
            function (array $dedupRecordId) use (
                &$count,
                &$fixed,
                $pc,
                $strict
            ) {
                // Avoid stale data by reading the record just before processing
                $dedupRecord = $this->db->getDedup($dedupRecordId['_id']);
                if (null === $dedupRecord) {
                    return true;
                }
                $results = $this->dedupHandler
                    ->checkDedupRecord($dedupRecord, $strict);
                if ($results) {
                    $fixed += count($results);
                    foreach ($results as $result) {
                        $this->logger->logInfo('checkDedup', $result);
                    }
                }
                ++$count;
                if ($count % 1000 == 0) {
                    $pc->add($count);
                    $avg = $pc->getSpeed();
                    $this->logger->logInfo(
                        'checkDedup',
                        "$count records checked with $fixed links fixed, "
                        . "$avg records/sec"
                    );
                }
            }
        );
        $this->logger->logInfo(
            'checkDedup',
            "Completed dedup check with $count records checked, $fixed links fixed"
        );

        $this->logger->logInfo('checkDedup', 'Checking record links');

        $params = [];
        if ($singleId) {
            $params['_id'] = $singleId;
        } else {
            $params['dedup_id'] = ['$exists' => true];
        }
        $count = 0;
        $fixed = 0;
        $pc = new PerformanceCounter();
        $this->db->iterateRecords(
            $params,
            ['projection' => ['_id' => 1]],
            function (array $recordId) use (&$count, &$fixed, $pc) {
                $record = $this->db->getRecord($recordId['_id']);
                $result = $this->dedupHandler->checkRecordLinks($record);
                if ($result) {
                    ++$fixed;
                    $this->logger->logInfo('checkDedup', $result);
                }
                ++$count;
                if ($count % 1000 == 0) {
                    $pc->add($count);
                    $avg = $pc->getSpeed();
                    $this->logger->logInfo(
                        'checkDedup',
                        "$count links checked with $fixed links fixed, "
                        . "$avg records/sec"
                    );
                }
            }
        );
        $this->logger->logInfo(
            'checkDedup',
            "Completed link check with $count records checked, $fixed links fixed"
        );

        return Command::SUCCESS;
    }
}

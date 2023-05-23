<?php

/**
 * Suppress records
 *
 * PHP version 8
 *
 * Copyright (C) The National Library of Finland 2020-2022.
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
 * Suppress records
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class Suppress extends AbstractBase
{
    /**
     * Configure the command.
     *
     * @return void
     */
    protected function configure()
    {
        $this
            ->setDescription('Suppress records')
            ->addOption(
                'source',
                null,
                InputOption::VALUE_REQUIRED,
                'A comma-separated list of data sources to suppress'
            )->addOption(
                'single',
                null,
                InputOption::VALUE_REQUIRED,
                'Suppress only the specified record'
            )
            ->setHelp('Suppresses records from the Solr index');
    }

    /**
     * Suppress records
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
                ->logFatal('suppress', 'No source or record id specified');
            return Command::INVALID;
        }

        if (empty($sourceId)) {
            $this->logger->logInfo('suppress', "Suppressing record $singleId");
            $this->suppress('', $singleId);
        } else {
            foreach (explode(',', $sourceId) as $source) {
                $this->logger->logInfo('suppress', "Suppressing $source");
                $this->suppress($source, $singleId);
            }
        }

        return Command::SUCCESS;
    }

    /**
     * Suppress records in a data source
     *
     * @param string $sourceId Source ID to process
     * @param string $singleId Process only a single record with the given ID
     *
     * @return void
     */
    protected function suppress($sourceId, $singleId)
    {
        $params = [
            'deleted' => false,
            'suppressed' => ['$in' => [null, false]],
        ];
        if ($sourceId) {
            $params['source_id'] = $sourceId;
        }
        if ($singleId) {
            $params['_id'] = $singleId;
        }
        $total = $this->db->countRecords($params);
        $count = 0;
        $this->logger->logInfo('suppress', "Processing $total records");
        $pc = new PerformanceCounter();

        $this->db->iterateRecords(
            $params,
            [],
            function ($record) use (
                &$count,
                $pc
            ) {
                $source = $record['source_id'];
                if (!($settings = $this->dataSourceConfig[$source] ?? null)) {
                    $this->logger->logFatal(
                        'suppress',
                        "Data source configuration missing for '$source'"
                    );
                    return false;
                }
                $record['suppressed'] = true;
                if (($settings['dedup'] ?? false) && isset($record['dedup_id'])) {
                    $this->dedupHandler->removeFromDedupRecord(
                        $record['dedup_id'],
                        $record['_id']
                    );
                    unset($record['dedup_id']);
                }
                $record['update_needed'] = false;
                $record['updated'] = $this->db->getTimestamp();
                $this->db->saveRecord($record);

                ++$count;
                if ($count % 1000 == 0) {
                    $pc->add($count);
                    $avg = $pc->getSpeed();
                    $this->logger->logInfo(
                        'suppress',
                        "$count records processed from, $avg records/sec"
                    );
                }
            }
        );
        $this->logger->logInfo(
            'suppress',
            "Completed with $count records processed"
        );
    }
}

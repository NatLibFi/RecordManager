<?php

/**
 * Record Renormalization
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
 * Record Renormalization
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class Renormalize extends AbstractBase
{
    /**
     * Configure the command.
     *
     * @return void
     */
    protected function configure()
    {
        $this
            ->setDescription('Renormalize records')
            ->addOption(
                'source',
                null,
                InputOption::VALUE_REQUIRED,
                'A comma-separated list of data sources to renormalize'
            )->addOption(
                'single',
                null,
                InputOption::VALUE_REQUIRED,
                'Renormalize only the specified record'
            );
    }

    /**
     * Mark records of a single data source to be updated
     *
     * @param InputInterface  $input  Console input
     * @param OutputInterface $output Console output
     *
     * @return int 0 if everything went fine, or an exit code
     */
    protected function doExecute(InputInterface $input, OutputInterface $output)
    {
        $sourceId = $input->getOption('source') ?: '';
        $singleId = $input->getOption('single') ?: '';

        if (!empty($singleId)) {
            $this->logger->logInfo('renormalize', "Renormalizing record $singleId");
            $this->process('', $singleId);
        } else {
            foreach (explode(',', $sourceId) as $source) {
                $this->logger->logInfo(
                    'renormalize',
                    "Renormalizing " . ($source ?: 'all records')
                );
                $this->process($source, $singleId);
            }
        }

        return Command::SUCCESS;
    }

    /**
     * Renormalize records in a data source
     *
     * @param string $sourceId Source ID to renormalize
     * @param string $singleId Renormalize only a single record with the given ID
     *
     * @return void
     */
    protected function process($sourceId, $singleId)
    {
        $params = ['deleted' => false];
        if ($sourceId) {
            $params['source_id'] = $sourceId;
        }
        if ($singleId) {
            $params['_id'] = $singleId;
        }
        $this->logger->logInfo('renormalize', 'Creating record list');

        $total = $this->db->countRecords($params);
        $count = 0;
        $this->logger->logInfo('renormalizing', "Renormalizing $total records");
        $pc = new PerformanceCounter();

        $count = 0;
        $this->db->iterateRecords(
            $params,
            [],
            function ($record) use (
                $pc,
                &$count
            ) {
                $source = $record['source_id'];
                if (!isset($this->dataSourceConfig[$source])) {
                    $this->logger->logFatal(
                        'renormalize',
                        "Data source configuration missing for '$source'"
                    );
                    return false;
                }
                $settings = $this->dataSourceConfig[$source];
                $originalData = $this->metadataUtils->getRecordData($record, false);
                $normalizedData = $originalData;
                if (null !== $settings['normalizationXSLT']) {
                    $origMetadataRecord = $this->createRecord(
                        $record['format'],
                        $originalData,
                        $record['oai_id'],
                        $record['source_id']
                    );
                    $normalizedData = $settings['normalizationXSLT']->transform(
                        $origMetadataRecord->toXML(),
                        ['oai_id' => $record['oai_id']]
                    );
                }

                $metadataRecord = $this->createRecord(
                    $record['format'],
                    $normalizedData,
                    $record['oai_id'],
                    $record['source_id']
                );
                $metadataRecord->normalize();

                if ($metadataRecord->getSuppressed()) {
                    $record['deleted'] = true;
                }

                $hostIDs = $metadataRecord->getHostRecordIDs();
                $normalizedData = $metadataRecord->serialize();
                if ($settings['dedup'] && !$hostIDs && !$record['deleted']) {
                    $record['update_needed'] = $this->dedupHandler
                        ->updateDedupCandidateKeys($record, $metadataRecord);
                } else {
                    if (isset($record['title_keys'])) {
                        unset($record['title_keys']);
                    }
                    if (isset($record['isbn_keys'])) {
                        unset($record['isbn_keys']);
                    }
                    if (isset($record['id_keys'])) {
                        unset($record['id_keys']);
                    }
                    if (isset($record['dedup_id'])) {
                        $this->dedupHandler->removeFromDedupRecord(
                            $record['dedup_id'],
                            $record['_id']
                        );
                        unset($record['dedup_id']);
                    }
                    $record['update_needed'] = false;
                }

                $record['original_data'] = $originalData;
                if ($normalizedData == $originalData) {
                    $record['normalized_data'] = '';
                } else {
                    $record['normalized_data'] = $normalizedData;
                }
                $record['linking_id'] = $metadataRecord->getLinkingIDs();
                if ($hostIDs) {
                    $record['host_record_id'] = $hostIDs;
                } elseif (isset($record['host_record_id'])) {
                    unset($record['host_record_id']);
                }
                $record['updated'] = $this->db->getTimestamp();
                $this->db->saveRecord($record);

                $this->logger->writelnVerbose(
                    function () use ($record) {
                        $record['normalized_data']
                            = $this->metadataUtils->getRecordData($record, true);
                        $record['original_data']
                            = $this->metadataUtils->getRecordData($record, false);
                        if (
                            $record['normalized_data'] === $record['original_data']
                        ) {
                            $record['normalized_data'] = '';
                        }
                        return "Metadata for record {$record['_id']}:" . PHP_EOL
                            . print_r($record, true);
                    }
                );

                ++$count;
                if ($count % 1000 == 0) {
                    $pc->add($count);
                    $avg = $pc->getSpeed();
                    $this->logger->logInfo(
                        'renormalize',
                        "$count records processed, $avg records/sec"
                    );
                }
            }
        );
        $this->logger
            ->logInfo('renormalize', "Completed with $count records processed");
    }
}

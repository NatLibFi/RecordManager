<?php
/**
 * Deduplication
 *
 * PHP version 7
 *
 * Copyright (C) The National Library of Finland 2011-2020.
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
namespace RecordManager\Base\Controller;

use RecordManager\Base\Utils\PerformanceCounter;

/**
 * Deduplication
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class Deduplicate extends AbstractBase
{
    /**
     * Termination flag
     *
     * @var bool
     */
    protected $terminate = false;

    /**
     * Find duplicate records and give them dedup keys
     *
     * @param string $sourceId   Source ID to process, or empty or * for all sources
     *                           where dedup is enabled
     * @param bool   $allRecords If true, process all records regardless of their
     *                           status (otherwise only freshly imported or updated
     *                           records are processed)
     * @param string $singleId   Process only a record with the given ID
     * @param bool   $markOnly   If true, just mark the records for deduplication
     *
     * @return void
     */
    public function launch($sourceId, $allRecords = false, $singleId = '',
        $markOnly = false
    ) {
        $this->terminate = false;

        $this->logger->logInfo('deduplicate', 'Deduplication started');

        // Install a signal handler so that we can exit cleanly if interrupted
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, [$this, 'sigIntHandler']);
            pcntl_signal(SIGTERM, [$this, 'sigIntHandler']);
            $this->logger->logInfo('deduplicate', 'Interrupt handler set');
        } else {
            $this->logger->logInfo(
                'deduplicate',
                'Could not set an interrupt handler -- pcntl not available'
            );
        }

        $this->initSourceSettings();

        if ($allRecords || $markOnly) {
            foreach ($this->dataSourceSettings as $source => $settings) {
                if ($sourceId && $sourceId != '*' && $source != $sourceId) {
                    continue;
                }
                if (empty($source) || empty($settings)) {
                    continue;
                }
                $this->logger->logInfo(
                    'deduplicate', "Marking all records for processing in '$source'"
                );
                $filter = [
                    'source_id' => $source,
                    'host_record_id' => ['$exists' => false],
                    'deleted' => false,
                    'suppressed' => ['$in' => [null, false]],
                ];
                $pc = new PerformanceCounter();
                $count = 0;
                $this->db->iterateRecords(
                    $filter,
                    [],
                    function ($record) use ($pc, &$count, $source) {
                        if ($this->terminate) {
                            return false;
                        }

                        $this->db->updateRecord(
                            $record['_id'], ['update_needed' => true]
                        );

                        ++$count;
                        if ($count % 1000 == 0) {
                            $pc->add($count);
                            $avg = $pc->getSpeed();
                            $this->logger->logInfo(
                                'deduplicate',
                                "$count records marked for processing in '$source', "
                                    . "$avg records/sec"
                            );
                        }
                        return true;
                    }
                );
                if ($this->terminate) {
                    $this->logger
                        ->logInfo('deduplicate', 'Termination upon request');
                    exit(1);
                }

                $this->logger->logInfo(
                    'deduplicate',
                    "Completed with $count records marked for processing "
                        . " in '$source'"
                );
            }
            if ($markOnly) {
                return;
            }
        }

        foreach ($this->dataSourceSettings as $source => $settings) {
            try {
                if ($sourceId && $sourceId != '*' && $source != $sourceId) {
                    continue;
                }
                if (empty($source) || empty($settings)) {
                    continue;
                }

                $this->logger->logInfo(
                    'deduplicate',
                    "Creating record list for '$source'"
                        . ($allRecords ? ' (all records)' : '')
                );

                $params = ['source_id' => $source];
                if ($singleId) {
                    $params['_id'] = $singleId;
                } else {
                    $params['update_needed'] = true;
                }
                $total = $this->db->countRecords($params);
                $dedupHandler = $this->dedupHandler;
                $count = 0;
                $deduped = 0;
                $pc = new PerformanceCounter();
                $this->logger->logInfo(
                    'deduplicate', "Processing $total records for '$source'"
                );
                $verbose = $this->verbose;
                $this->db->iterateRecords(
                    $params,
                    [],
                    function ($record) use ($singleId, $dedupHandler,
                        &$count, &$deduped, $pc, $source, $verbose
                    ) {
                        if (!$singleId && empty($record['update_needed'])) {
                            return true;
                        }
                        if ($this->terminate) {
                            return false;
                        }
                        $startRecordTime = microtime(true);
                        if ($dedupHandler->dedupRecord($record)) {
                            if ($verbose) {
                                echo '+';
                            }
                            ++$deduped;
                        } else {
                            if ($verbose) {
                                echo '.';
                            }
                        }
                        if ($verbose
                            && microtime(true) - $startRecordTime > 0.7
                        ) {
                            echo "\nDeduplication of " . $record['_id'] . ' took '
                                . (microtime(true) - $startRecordTime) . "\n";
                        }
                        ++$count;
                        if ($count % 1000 == 0) {
                            $pc->add($count);
                            $avg = $pc->getSpeed();
                            if ($this->verbose) {
                                echo "\n";
                            }
                            $this->logger->logInfo(
                                'deduplicate',
                                "$count records processed for '$source', $deduped "
                                    . "deduplicated, $avg records/sec"
                            );
                        }
                        return true;
                    }
                );
                if ($this->terminate) {
                    $this->logger
                        ->logInfo('deduplicate', 'Termination upon request');
                    exit(1);
                }
                $this->logger->logInfo(
                    'deduplicate',
                    "Total $count records processed for '$source', "
                    . "$deduped deduplicated"
                );
            } catch (\Exception $e) {
                $this->logger->logFatal(
                    'deduplicate', 'Exception: ' . $e->getMessage()
                );
                throw $e;
            }
        }
        $this->logger->logInfo('deduplicate', 'Deduplication completed');
    }

    /**
     * Catch the SIGINT signal and signal the main thread to terminate
     *
     * Note: this needs to be public so that the int handler can call it.
     *
     * @param int $signal Signal ID
     *
     * @return void
     */
    public function sigIntHandler($signal)
    {
        $this->terminate = true;
        echo "Termination requested\n";
    }
}

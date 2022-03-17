<?php
/**
 * Deduplication
 *
 * PHP version 7
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
     * Configure the command.
     *
     * @return void
     */
    protected function configure()
    {
        $this
            ->setDescription('Deduplicate records')
            ->setHelp(
                'Runs the deduplication process to find duplicate records and create'
                . ' links between them'
            )->addOption(
                'all',
                null,
                InputOption::VALUE_NONE,
                'Process all records regardless of their status (otherwise only '
                . ' modified records are processed)'
            )->addOption(
                'mark',
                null,
                InputOption::VALUE_NONE,
                'Mark all records to be deduplicated instead of processing them'
                . ' (useful when there is a regular deduplication process scheduled)'
            )->addOption(
                'single',
                null,
                InputOption::VALUE_REQUIRED,
                'Process only the specified record'
            )->addOption(
                'source',
                null,
                InputOption::VALUE_REQUIRED,
                'Process only a comma-separated list of data sources',
                '*'
            );
    }

    /**
     * Find duplicate records and give them dedup keys
     *
     * @param InputInterface  $input  Console input
     * @param OutputInterface $output Console output
     *
     * @return int 0 if everything went fine, or an exit code
     *
     * @psalm-suppress TypeDoesNotContainType
     */
    protected function doExecute(InputInterface $input, OutputInterface $output)
    {
        $allRecords = $input->getOption('all');
        $singleId = $input->getOption('single');
        $markOnly = $input->getOption('mark');
        $includedSources = explode(',', $input->getOption('source'));
        if (in_array('*', $includedSources)) {
            $includedSources = [];
        }

        $this->terminate = false;

        $this->logger->logInfo('deduplicate', 'Deduplication started');

        // Install a signal handler so that we can exit cleanly if interrupted.
        // This makes sure deduplication of a single record is not interrupted in
        // the middle.
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

        if ($allRecords || $markOnly) {
            foreach ($this->dataSourceConfig as $source => $settings) {
                if (empty($source) || empty($settings)
                    || ($includedSources && !in_array($source, $includedSources))
                ) {
                    continue;
                }

                $this->logger->logInfo(
                    'deduplicate',
                    "Marking all records for processing in '$source'"
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
                            $record['_id'],
                            ['update_needed' => true]
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
                // @phpstan-ignore-next-line
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
                return Command::SUCCESS;
            }
        }

        foreach ($this->dataSourceConfig as $source => $settings) {
            try {
                if (empty($source) || empty($settings)
                    || ($includedSources && !in_array($source, $includedSources))
                ) {
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
                    'deduplicate',
                    "Processing $total records for '$source'"
                );
                $this->db->iterateRecords(
                    $params,
                    [],
                    function ($record) use (
                        $singleId,
                        $dedupHandler,
                        &$count,
                        &$deduped,
                        $pc,
                        $source
                    ) {
                        if (!$singleId && empty($record['update_needed'])) {
                            return true;
                        }
                        if ($this->terminate) {
                            return false;
                        }
                        $startRecordTime = microtime(true);
                        if ($dedupHandler->dedupRecord($record)) {
                            $this->logger->writeConsole(
                                '+',
                                OutputInterface::VERBOSITY_VERY_VERBOSE
                            );
                            ++$deduped;
                        } else {
                            $this->logger->writeConsole(
                                '.',
                                OutputInterface::VERBOSITY_VERY_VERBOSE
                            );
                        }
                        if (microtime(true) - $startRecordTime > 0.7) {
                            $this->logger->writelnVerbose(
                                PHP_EOL . 'Deduplication of ' . $record['_id']
                                . ' took ' . (microtime(true) - $startRecordTime)
                            );
                        }
                        ++$count;
                        if ($count % 1000 == 0) {
                            $pc->add($count);
                            $avg = $pc->getSpeed();
                            $this->logger->writelnVeryVerbose('');
                            $this->logger->logInfo(
                                'deduplicate',
                                "$count records processed for '$source', $deduped "
                                    . "deduplicated, $avg records/sec"
                            );
                        }
                        return true;
                    }
                );
                // @phpstan-ignore-next-line
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
                    'deduplicate',
                    'Exception: ' . $e->getMessage()
                );
                throw $e;
            }
        }
        $this->logger->logInfo('deduplicate', 'Deduplication completed');
        return Command::SUCCESS;
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
        $this->logger->writelnConsole('Termination requested');
    }
}

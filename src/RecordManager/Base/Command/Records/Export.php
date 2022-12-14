<?php
/**
 * Export
 *
 * PHP version 7
 *
 * Copyright (C) The National Library of Finland 2011-2022.
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
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Export
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class Export extends AbstractBase
{
    /**
     * Batch size
     *
     * @var int
     */
    protected $batchSize = 0;

    /**
     * Current batch sequence
     *
     * @var int
     */
    protected $currentBatch = 0;

    /**
     * Current batch record count
     *
     * @var int
     */
    protected $currentBatchCount = 0;

    /**
     * File name template
     *
     * @var string
     */
    protected $fileTemplate = '';

    /**
     * Current file name
     *
     * @var ?string
     */
    protected $currentFile = null;

    /**
     * Configure the command.
     *
     * @return void
     */
    protected function configure()
    {
        $this
            ->setDescription('Export records')
            ->addArgument(
                'file',
                InputArgument::REQUIRED,
                'Output file (use - for stdout)'
            )->addOption(
                'deleted',
                null,
                InputOption::VALUE_REQUIRED,
                'Output file for deleted record IDs'
            )->addOption(
                'from',
                null,
                InputOption::VALUE_REQUIRED,
                'Update date and optional time where to start the export'
            )->addOption(
                'until',
                null,
                InputOption::VALUE_REQUIRED,
                'Update date and optional time where to end the export'
            )->addOption(
                'created-from',
                null,
                InputOption::VALUE_REQUIRED,
                'Creation date and optional time where to start the export'
            )->addOption(
                'created-until',
                null,
                InputOption::VALUE_REQUIRED,
                'Creation date and optional time where to end the export'
            )->addOption(
                'source',
                null,
                InputOption::VALUE_REQUIRED,
                'Process only a comma-separated list of data sources',
                '*'
            )->addOption(
                'single',
                null,
                InputOption::VALUE_REQUIRED,
                'Process only the specified record'
            )->addOption(
                'xpath',
                null,
                InputOption::VALUE_REQUIRED,
                'Export only records matching an XPath expression'
            )->addOption(
                'batch-size',
                null,
                InputOption::VALUE_REQUIRED,
                'Export multiple files with a batch of records in each one. The file'
                . ' argument is used as a template. If it contains {n}, that will be'
                . ' replaced with the file number (example: "export-{n}.xml").'
                . ' Otherwise a dash and the file number is appended before any file'
                . ' extension.'
            )->addOption(
                'skip',
                null,
                InputOption::VALUE_REQUIRED,
                'Skip every SKIP records to export only a "representative" subset'
            )->addOption(
                'sort-dedup',
                null,
                InputOption::VALUE_NONE,
                'Sort export file by dedup id'
            )->addOption(
                'dedup-id',
                null,
                InputOption::VALUE_REQUIRED,
                "Whether to include dedup id's in exported records. Supported"
                . ' values: deduped = if duplicates exist, always = always. '
                . " Default is to not include the dedup id's."
            );
    }

    /**
     * Export records from the database to a file
     *
     * @param InputInterface  $input  Console input
     * @param OutputInterface $output Console output
     *
     * @return int 0 if everything went fine, or an exit code
     */
    protected function doExecute(InputInterface $input, OutputInterface $output)
    {
        $this->fileTemplate = $input->getArgument('file');
        $deletedFile = $input->getOption('deleted');
        $fromDate = $input->getOption('from');
        $untilDate = $input->getOption('until');
        $fromCreateDate = $input->getOption('created-from');
        $untilCreateDate = $input->getOption('created-until');
        $skipRecords = $input->getOption('skip');
        $sourceId = $input->getOption('source');
        $singleId = $input->getOption('single');
        $xpath = $input->getOption('xpath');
        $sortDedup = $input->getOption('sort-dedup');
        $addDedupId = $input->getOption('dedup-id');
        $this->batchSize = $input->getOption('batch-size') ?: 0;

        $returnCode = Command::SUCCESS;

        $this->startNewBatch();
        if ($deletedFile && file_exists($deletedFile)) {
            unlink($deletedFile);
        }

        try {
            $this->logger->logInfo('exportRecords', 'Creating record list');

            $params = [];
            if ($singleId) {
                $params['_id'] = $singleId;
            } else {
                if ($fromDate && $untilDate) {
                    $params['$and'] = [
                        [
                            'updated' => [
                                '$gte'
                                    => $this->db->getTimestamp(strtotime($fromDate))
                            ]
                        ],
                        [
                            'updated' => [
                                '$lte'
                                    => $this->db->getTimestamp(strtotime($untilDate))
                            ]
                        ]
                    ];
                } elseif ($fromDate) {
                    $params['updated']
                        = ['$gte' => $this->db->getTimestamp(strtotime($fromDate))];
                } elseif ($untilDate) {
                    $params['updated']
                        = ['$lte' => $this->db->getTimestamp(strtotime($untilDate))];
                }
                if ($fromCreateDate && $untilCreateDate) {
                    $params['$and'] = [
                        [
                            'created' => [
                                '$gte' => $this->db->getTimestamp(
                                    strtotime($fromCreateDate)
                                )
                            ]
                        ],
                        [
                            'created' => [
                                '$lte' => $this->db->getTimestamp(
                                    strtotime($untilCreateDate)
                                )
                            ]
                        ]
                    ];
                } elseif ($fromCreateDate) {
                    $params['created'] = [
                        '$gte' => $this->db->getTimestamp(strtotime($fromCreateDate))
                    ];
                } elseif ($untilDate) {
                    $params['created'] = [
                        '$lte'
                            => $this->db->getTimestamp(strtotime($untilCreateDate))
                    ];
                }
                if ($sourceId && $sourceId !== '*') {
                    $sources = explode(',', $sourceId);
                    if (count($sources) == 1) {
                        $params['source_id'] = $sourceId;
                    } else {
                        $sourceParams = [];
                        foreach ($sources as $source) {
                            $sourceParams[] = ['source_id' => $source];
                        }
                        $params['$or'] = $sourceParams;
                    }
                }
            }
            $options = [];
            if ($sortDedup) {
                $options['sort'] = ['dedup_id' => 1];
            }

            $total = $this->db->countRecords($params, $options);
            $deduped = 0;
            $deleted = 0;
            $count = 0;
            $this->logger->logInfo('exportRecords', "Exporting $total records");
            if ($skipRecords) {
                $this->logger->logInfo(
                    'exportRecords',
                    "(1 per each $skipRecords records)"
                );
            }
            $this->db->iterateRecords(
                $params,
                $options,
                function ($record) use (
                    &$deduped,
                    &$deleted,
                    &$count,
                    $skipRecords,
                    $xpath,
                    $deletedFile,
                    $addDedupId
                ) {
                    $metadataRecord = $this->createRecord(
                        $record['format'],
                        $this->metadataUtils->getRecordData($record, true),
                        $record['oai_id'],
                        $record['source_id']
                    );
                    if ($xpath) {
                        $xml = $metadataRecord->toXML();
                        $dom = $this->metadataUtils->loadXML($xml);
                        if (!$dom) {
                            throw new \Exception(
                                "Failed to parse record '${$record['_id']}'"
                            );
                        }
                        $xpathResult = $dom->xpath($xpath);
                        if ($xpathResult === false) {
                            throw new \Exception(
                                "Failed to evaluate XPath expression '$xpath'"
                            );
                        }
                        if (!$xpathResult) {
                            return true;
                        }
                    }
                    ++$count;
                    if ($record['deleted']) {
                        if ($deletedFile) {
                            file_put_contents(
                                $deletedFile,
                                "{$record['_id']}\n",
                                FILE_APPEND
                            );
                        }
                        ++$deleted;
                    } else {
                        if ($skipRecords > 0 && $count % $skipRecords != 0) {
                            return true;
                        }
                        if (isset($record['dedup_id'])) {
                            ++$deduped;
                        }
                        if ($addDedupId == 'always') {
                            $metadataRecord->addDedupKeyToMetadata(
                                $record['dedup_id']
                                ?? $record['_id']
                            );
                        } elseif ($addDedupId == 'deduped') {
                            $metadataRecord->addDedupKeyToMetadata(
                                $record['dedup_id']
                                ?? ''
                            );
                        }
                        $xml = $metadataRecord->toXML();
                        $xml = preg_replace('/^<\?xml.*?\?>[\n\r]*/', '', $xml);
                        $this->writeRecord($xml);
                    }
                    if ($count % 1000 == 0) {
                        $this->logger->logInfo(
                            'exportRecords',
                            "$count records (of which $deduped deduped, $deleted "
                            . "deleted) exported"
                        );
                    }
                }
            );
            $this->logger->logInfo(
                'exportRecords',
                "Completed with $count records (of which $deduped deduped, $deleted "
                . "deleted) exported"
            );
        } catch (\Exception $e) {
            $this->logger->logFatal(
                'exportRecords',
                'Exception: ' . (string)$e
            );
            $returnCode = Command::FAILURE;
        }
        $this->finishBatch();

        return $returnCode;
    }

    /**
     * Start a new batch
     *
     * @return void
     */
    protected function startNewBatch(): void
    {
        if ($this->currentFile) {
            $this->finishBatch();
        }
        $this->currentBatch += 1;
        $this->currentBatchCount = 0;
        $this->currentFile = $this->getBatchFileName();
        $this->logger
            ->logInfo('exportRecords', "Exporting to file: $this->currentFile");
        if (file_exists($this->currentFile)) {
            unlink($this->currentFile);
        }
        file_put_contents(
            $this->currentFile,
            "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n\n<collection>\n",
            FILE_APPEND
        );
    }

    /**
     * Finish writing to a batch
     *
     * @return void
     */
    protected function finishBatch(): void
    {
        if (!$this->currentFile) {
            throw new \Exception('Batch not properly started');
        }
        file_put_contents($this->currentFile, "</collection>\n", FILE_APPEND);
        $this->currentFile = null;
    }

    /**
     * Get a batch file name
     *
     * @return string
     */
    protected function getBatchFileName(): string
    {
        if ('-' === $this->fileTemplate) {
            return 'php://stdout';
        }

        if (!$this->batchSize) {
            return $this->fileTemplate;
        }
        $result
            = str_replace('{n}', (string)$this->currentBatch, $this->fileTemplate);
        if ($result === $this->fileTemplate) {
            // Add the batch number before file extension, if present:
            if (false !== ($p = strrpos($result, '.'))) {
                $result = substr($result, 0, $p) . "-$this->currentBatch"
                    . substr($result, $p);
            } else {
                // No extension, just append to the end:
                $result .= "-$this->currentBatch";
            }
        }
        return $result;
    }

    /**
     * Write a record
     *
     * Start a new batch if necessary.
     *
     * @param string $record Record
     *
     * @return void
     */
    protected function writeRecord(string $record): void
    {
        if (!$this->currentFile
            || ($this->batchSize && $this->currentBatchCount >= $this->batchSize)
        ) {
            $this->startNewBatch();
        }
        ++$this->currentBatchCount;
        file_put_contents($this->currentFile, $record . "\n", FILE_APPEND);
    }
}

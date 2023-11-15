<?php

/**
 * Export
 *
 * PHP version 8
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

use function count;
use function strlen;

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
     * Output file for deleted record IDs
     *
     * @var ?string
     */
    protected $deletedFile;

    /**
     * Update date and optional time where to start the export
     *
     * @var ?string
     */
    protected $fromDate;

    /**
     * Update date and optional time where to end the export
     *
     * @var ?string
     */
    protected $untilDate;

    /**
     * Creation date and optional time where to start the export
     *
     * @var ?string
     */
    protected $fromCreateDate;

    /**
     * Creation date and optional time where to end the export
     *
     * @var ?string
     */
    protected $untilCreateDate;

    /**
     * Skip every SKIP records to export only a "representative" subset
     *
     * @var ?int
     */
    protected $skipRecords;

    /**
     * ID of a single record to export (overrides most other options)
     *
     * @var ?string
     */
    protected $singleId;

    /**
     * File containing a list of IDs to export (overrides most other options)
     *
     * @var ?string
     */
    protected $idFile;

    /**
     * Number of records to export per query when writing to $idFile.
     *
     * @var int
     */
    protected $idFileBatchSize = 1000;

    /**
     * Prefix to add to each value read from $idFile.
     *
     * @var ?string
     */
    protected $idFilePrefix;

    /**
     * Export only records matching this XPath expression (if defined)
     *
     * @var ?string
     */
    protected $xpath;

    /**
     * Should we sort export file by dedup id?
     *
     * @var ?bool
     */
    protected $sortDedup;

    /**
     * Whether to include dedup id's in exported records. Supported values:
     *   - deduped = if duplicates exist
     *   - always = always
     * Default is to not include the dedup id's.
     *
     * @var ?string
     */
    protected $addDedupId;

    /**
     * Process only a comma-separated list of data sources
     *
     * @var string
     */
    protected $sourceId;

    /**
     * Inject record ID without source prefix to the given XML field
     *
     * @var ?string
     */
    protected $injectId;

    /**
     * Inject record ID with source prefix to the given XML field
     *
     * @var ?string
     */
    protected $injectIdPrefixed;

    /**
     * Total number of records processed
     *
     * @var int
     */
    protected $count = 0;

    /**
     * Total number of deleted records processed
     *
     * @var int
     */
    protected $deleted = 0;

    /**
     * Total number of deduplicated records processed
     *
     * @var int
     */
    protected $deduped = 0;

    /**
     * Callback to support the iterateRecords method of the database object.
     *
     * @param array $record Record details
     *
     * @return bool
     */
    public function iterateRecordsCallback($record): bool
    {
        $metadataRecord = $this->createRecord(
            $record['format'],
            $this->metadataUtils->getRecordData($record, true),
            $record['oai_id'],
            $record['source_id']
        );
        if (!$record['deleted']) {
            if ($this->addDedupId == 'always') {
                $metadataRecord->addDedupKeyToMetadata(
                    $record['dedup_id']
                    ?? $record['_id']
                );
            } elseif ($this->addDedupId == 'deduped') {
                $metadataRecord->addDedupKeyToMetadata(
                    $record['dedup_id']
                    ?? ''
                );
            }
        }
        $xml = $metadataRecord->toXML();
        if ($this->xpath || (($this->injectId || $this->injectIdPrefixed) && !$record['deleted'])) {
            $errors = '';
            $dom = $this->metadataUtils->loadXML($xml, null, 0, $errors);
            if (false === $dom) {
                throw new \Exception(
                    "Failed to parse record '{$record['_id']}': $errors"
                );
            }
            if ($this->xpath) {
                $xpathResult = $dom->xpath($this->xpath);
                if ($xpathResult === false) {
                    throw new \Exception(
                        "Failed to evaluate XPath expression '$this->xpath'"
                    );
                }
                if (!$xpathResult) {
                    return true;
                }
            }
            if (!$record['deleted']) {
                if ($this->injectId) {
                    $id = $record['_id'];
                    $id = substr($id, strlen("$this->sourceId."));
                    $dom->addChild($this->injectId, htmlspecialchars($id, ENT_NOQUOTES));
                    $xml = $dom->saveXML();
                }
                if ($this->injectIdPrefixed) {
                    $dom->addChild(
                        $this->injectIdPrefixed,
                        htmlspecialchars($record['_id'], ENT_NOQUOTES)
                    );
                    $xml = $dom->saveXML();
                }
            }
        }
        ++$this->count;
        if ($record['deleted']) {
            if ($this->deletedFile) {
                file_put_contents(
                    $this->deletedFile,
                    "{$record['_id']}\n",
                    FILE_APPEND
                );
            }
            ++$this->deleted;
        } else {
            if ($this->skipRecords > 0 && $this->count % $this->skipRecords != 0) {
                return true;
            }
            if (isset($record['dedup_id'])) {
                ++$this->deduped;
            }
            if ($this->addDedupId == 'always') {
                $metadataRecord->addDedupKeyToMetadata(
                    $record['dedup_id']
                    ?? $record['_id']
                );
            } elseif ($this->addDedupId == 'deduped') {
                $metadataRecord->addDedupKeyToMetadata(
                    $record['dedup_id']
                    ?? ''
                );
            }
            $xml = preg_replace('/^<\?xml.*?\?>[\n\r]*/', '', $xml);
            $this->writeRecord($xml);
        }
        if ($this->count % 1000 == 0) {
            $this->logger->logInfo(
                'exportRecords',
                "$this->count records (of which $this->deduped deduped, $this->deleted "
                . 'deleted) exported'
            );
        }
        return true;
    }

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
                'id-file',
                null,
                InputOption::VALUE_REQUIRED,
                'Process only the records whose IDs are listed in the provided text file'
            )->addOption(
                'id-file-batch-size',
                '1000',
                InputOption::VALUE_REQUIRED,
                'Number of records to export per database request when using --id-file'
            )->addOption(
                'id-file-prefix',
                null,
                InputOption::VALUE_REQUIRED,
                'Source prefix to add to each line read from file when using --id-file'
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
            )->addOption(
                'inject-id',
                null,
                InputOption::VALUE_REQUIRED,
                'Inject record ID without source prefix to the given XML field'
            )->addOption(
                'inject-id-prefixed',
                null,
                InputOption::VALUE_REQUIRED,
                'Inject record ID with source prefix to the given XML field'
            );
    }

    /**
     * Set all user input into class properties.
     *
     * @param InputInterface $input Console input
     *
     * @return void
     */
    protected function collectArgumentsAndOptions(InputInterface $input): void
    {
        $this->fileTemplate = $input->getArgument('file');
        $this->deletedFile = $input->getOption('deleted');
        $this->fromDate = $input->getOption('from');
        $this->untilDate = $input->getOption('until');
        $this->fromCreateDate = $input->getOption('created-from');
        $this->untilCreateDate = $input->getOption('created-until');
        $this->skipRecords = $input->getOption('skip');
        $this->sourceId = $input->getOption('source');
        $this->singleId = $input->getOption('single');
        $this->idFile = $input->getOption('id-file');
        $this->idFileBatchSize = $input->getOption('id-file-batch-size') ?? $this->idFileBatchSize;
        $this->idFilePrefix = $input->getOption('id-file-prefix');
        $this->xpath = $input->getOption('xpath');
        $this->sortDedup = $input->getOption('sort-dedup');
        $this->addDedupId = $input->getOption('dedup-id');
        $this->batchSize = $input->getOption('batch-size') ?: 0;
        $this->injectId = $input->getOption('inject-id');
        $this->injectIdPrefixed = $input->getOption('inject-id-prefixed');
    }

    /**
     * Collect range and source parameters from user-provided options.
     *
     * @return array
     */
    protected function gatherRangeAndSourceParams(): array
    {
        $params = [];
        if ($this->fromDate && $this->untilDate) {
            $params['$and'] = [
                [
                    'updated' => [
                        '$gte'
                            => $this->db->getTimestamp(strtotime($this->fromDate)),
                    ],
                ],
                [
                    'updated' => [
                        '$lte'
                            => $this->db->getTimestamp(strtotime($this->untilDate)),
                    ],
                ],
            ];
        } elseif ($this->fromDate) {
            $params['updated']
                = ['$gte' => $this->db->getTimestamp(strtotime($this->fromDate))];
        } elseif ($this->untilDate) {
            $params['updated']
                = ['$lte' => $this->db->getTimestamp(strtotime($this->untilDate))];
        }
        if ($this->fromCreateDate && $this->untilCreateDate) {
            $params['$and'] = [
                [
                    'created' => [
                        '$gte' => $this->db->getTimestamp(
                            strtotime($this->fromCreateDate)
                        ),
                    ],
                ],
                [
                    'created' => [
                        '$lte' => $this->db->getTimestamp(
                            strtotime($this->untilCreateDate)
                        ),
                    ],
                ],
            ];
        } elseif ($this->fromCreateDate) {
            $params['created'] = [
                '$gte' => $this->db->getTimestamp(strtotime($this->fromCreateDate)),
            ];
        } elseif ($this->untilCreateDate) {
            $params['created'] = [
                '$lte'
                    => $this->db->getTimestamp(strtotime($this->untilCreateDate)),
            ];
        }
        if ($this->sourceId && $this->sourceId !== '*') {
            $sources = explode(',', $this->sourceId);
            if (count($sources) == 1) {
                $params['source_id'] = $this->sourceId;
            } else {
                $sourceParams = [];
                foreach ($sources as $source) {
                    $sourceParams[] = ['source_id' => $source];
                }
                $params['$or'] = $sourceParams;
            }
        }
        return $params;
    }

    /**
     * Get parameters for the next chunk of records to export
     *
     * @return \Generator
     */
    protected function getNextParams(): \Generator
    {
        if ($this->singleId && $this->idFile) {
            throw new \Exception('--single and --id-file options are incompatible');
        } elseif ($this->idFile) {
            if (!file_exists($this->idFile) || !($handle = fopen($this->idFile, 'r'))) {
                throw new \Exception("Cannot read $this->idFile");
            }
            $ids = [];
            while ($id = fgets($handle)) {
                if ($this->idFilePrefix) {
                    $id = "$this->idFilePrefix.$id";
                }
                $ids[] = trim($id);
                if (count($ids) >= $this->idFileBatchSize) {
                    yield ['_id' => ['$in' => $ids]];
                    $ids = [];
                }
            }
            fclose($handle);
            if (!empty($ids)) {
                yield ['_id' => ['$in' => $ids]];
            }
        } elseif ($this->singleId) {
            yield ['_id' => $this->singleId];
        } else {
            yield $this->gatherRangeAndSourceParams();
        }
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
        $this->collectArgumentsAndOptions($input);

        $returnCode = Command::SUCCESS;

        $this->startNewBatch();
        if ($this->deletedFile && file_exists($this->deletedFile)) {
            unlink($this->deletedFile);
        }

        try {
            $this->logger->logInfo('exportRecords', 'Creating record list');

            $options = [];
            if ($this->sortDedup) {
                $options['sort'] = ['dedup_id' => 1];
            }
            foreach ($this->getNextParams() as $params) {
                $total = $this->db->countRecords($params, $options);
                $this->logger->logInfo('exportRecords', "Exporting $total records");
                if ($this->skipRecords) {
                    $this->logger->logInfo(
                        'exportRecords',
                        "(1 per each $this->skipRecords records)"
                    );
                }
                $this->db->iterateRecords(
                    $params,
                    $options,
                    [$this, 'iterateRecordsCallback']
                );
            }
            $this->logger->logInfo(
                'exportRecords',
                "Completed with $this->count records (of which $this->deduped deduped, $this->deleted "
                . 'deleted) exported'
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
        if (
            !$this->currentFile
            || ($this->batchSize && $this->currentBatchCount >= $this->batchSize)
        ) {
            $this->startNewBatch();
        }
        ++$this->currentBatchCount;
        file_put_contents($this->currentFile, $record . "\n", FILE_APPEND);
    }
}

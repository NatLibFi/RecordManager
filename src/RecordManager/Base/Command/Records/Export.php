<?php

/**
 * Export
 *
 * PHP version 8
 *
 * Copyright (C) The National Library of Finland 2011-2024.
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
use RecordManager\Base\Utils\XslTransformation;
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
     * Inject record ID without source prefix to the given XML path
     *
     * @var array
     */
    protected $injectId;

    /**
     * Inject record ID with source prefix to the given XML path
     *
     * @var array
     */
    protected $injectIdPrefixed;

    /**
     * Inject creation (initial harvest) timestamp to the given XML path
     *
     * @var array
     */
    protected $injectCreationTimestamp;

    /**
     * Inject timestamp of current timestamp (OAI-PMH timestamp or last import date) to the given XML path
     *
     * @var array
     */
    protected $injectCurrentTimestamp;

    /**
     * Inject timestamp of last internal update to the given XML path
     *
     * @var array
     */
    protected $injectInternalTimestamp;

    /**
     * Namespaces to add to each record for injected fields
     *
     * @var array
     */
    protected $additionalNamespaces = [];

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
     * Do not add a root element around the XML
     *
     * @var ?bool
     */
    protected $noRoot;

    /**
     * Optional XSL transformation to be applied to records
     *
     * @var ?XslTransformation
     */
    protected $xslTransformation = null;

    /**
     * Apply XSL transformation before trying to match XPath expression
     *
     * @var ?bool
     */
    protected $transformBeforeXPath;

    /**
     * Callback to support the iterateRecords method of the database object.
     *
     * @param array $record Record details
     *
     * @return bool
     */
    public function iterateRecordsCallback($record): bool
    {
        $metadataRecord = $this->createRecordFromDbRecord($record);
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
        $xmlStr = $metadataRecord->toXML();
        if ($this->transformBeforeXPath && null !== $this->xslTransformation) {
            $xmlStr = $this->xslTransformation->transform($xmlStr);
        }
        $needsInjection = $this->injectId
            || $this->injectIdPrefixed
            || $this->injectCreationTimestamp
            || $this->injectCurrentTimestamp
            || $this->injectInternalTimestamp;
        if ($this->xpath || ($needsInjection && !$record['deleted'])) {
            $errors = '';
            $xml = $this->metadataUtils->loadXML($xmlStr, null, 0, $errors);
            if (false === $xml) {
                throw new \Exception(
                    "Failed to parse record '{$record['_id']}': $errors"
                );
            }
            if ($this->xpath) {
                $xpathResult = $xml->xpath($this->xpath);
                if ($xpathResult === false) {
                    throw new \Exception(
                        "Failed to evaluate XPath expression '$this->xpath'"
                    );
                }
                if (!$xpathResult) {
                    return true;
                }
            }
            if (!$record['deleted'] && $needsInjection) {
                if ($this->additionalNamespaces) {
                    // Add namespace prefixes via DOM because adding them with
                    // SimpleXML's addAttribute() wouldn't register them properly:
                    $dom = dom_import_simplexml($xml);
                    foreach ($this->additionalNamespaces as $prefix => $ns) {
                        $dom->setAttributeNS('http://www.w3.org/2000/xmlns/', "xmlns:$prefix", $ns);
                    }
                }
                if ($this->injectId) {
                    $id = $record['_id'];
                    $id = substr($id, strlen("$this->sourceId."));
                    $this->addXmlNode($xml, $this->injectId, $id);
                }
                if ($this->injectIdPrefixed) {
                    $this->addXmlNode($xml, $this->injectId, $record['_id']);
                }
                if ($this->injectCreationTimestamp) {
                    $this->addXmlNode(
                        $xml,
                        $this->injectCreationTimestamp,
                        $this->metadataUtils->formatTimestamp(
                            $this->db->getUnixTime($record['created'])
                        )
                    );
                }
                if ($this->injectCurrentTimestamp) {
                    $this->addXmlNode(
                        $xml,
                        $this->injectCurrentTimestamp,
                        $this->metadataUtils->formatTimestamp(
                            $this->db->getUnixTime($record['date'])
                        )
                    );
                }
                if ($this->injectInternalTimestamp) {
                    $this->addXmlNode(
                        $xml,
                        $this->injectInternalTimestamp,
                        $this->metadataUtils->formatTimestamp(
                            $this->db->getUnixTime($record['updated'])
                        )
                    );
                }
                $xmlStr = $xml->saveXML();
            }
        }
        if (!$this->transformBeforeXPath && null !== $this->xslTransformation) {
            $xmlStr = $this->xslTransformation->transform($xmlStr);
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
            $xmlStr = preg_replace('/^<\?xml.*?\?>[\n\r]*/', '', $xmlStr);
            $this->writeRecord($xmlStr);
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
                null,
                InputOption::VALUE_REQUIRED,
                'Number of records to export per database request when using <info>--id-file</info>',
                1000
            )->addOption(
                'id-file-prefix',
                null,
                InputOption::VALUE_REQUIRED,
                'Source prefix to add to each line read from file when using <info>--id-file</info>'
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
                . ' argument is used as a template. If it contains <comment>{n}</comment>, that will be'
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
                . ' values: <comment>deduped</comment> = if duplicates exist, <comment>always</comment> = always. '
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
            )->addOption(
                'inject-created',
                null,
                InputOption::VALUE_REQUIRED,
                'Inject creation (initial harvest or import) timestamp of the record'
                . ' to the given XML field (ISO 8601 format). The field may contain'
                . ' an XPath-like path with attributes and namespace prefixes'
                . ' (e.g. <comment>\'custom:elem/subelem[@type="some"]\'</comment>). Additional'
                . ' namespace prefixes can be defined with the <info>--add-namespace</info>'
                . ' parameter.'
            )->addOption(
                'inject-date',
                null,
                InputOption::VALUE_REQUIRED,
                'Inject current (last harvest or import) timestamp of the record'
                . ' (see <info>--inject-created</info> for more information)'
            )->addOption(
                'inject-internal-timestamp',
                null,
                InputOption::VALUE_REQUIRED,
                'Inject last internal update timestamp of the record'
                . ' (see <info>--inject-created</info> for more information)'
            )->addOption(
                'add-namespace',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Define an additional XML namespace for injected fields (prefix=namespace_identifier).'
            )->addOption(
                'no-root',
                null,
                InputOption::VALUE_NONE,
                'Do not add a root element around the XML. Works only'
                . ' when exporting single records or with batch-size 1.'
            )->addOption(
                'xslt',
                null,
                InputOption::VALUE_REQUIRED,
                'Optional XSL transformation to be applied to records'
            )->addOption(
                'xslt-first',
                null,
                InputOption::VALUE_NONE,
                'Apply XSL transformation before trying to match XPath expression'
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
        $this->injectId = $this->parseXmlPath($input->getOption('inject-id'));
        $this->injectIdPrefixed = $this->parseXmlPath($input->getOption('inject-id-prefixed'));
        $this->injectCreationTimestamp = $this->parseXmlPath($input->getOption('inject-created'));
        $this->injectCurrentTimestamp = $this->parseXmlPath($input->getOption('inject-date'));
        $this->injectInternalTimestamp = $this->parseXmlPath($input->getOption('inject-internal-timestamp'));
        foreach ((array)($input->getOption('add-namespace') ?? []) as $namespace) {
            $parts = explode('=', $namespace, 2);
            if (!isset($parts[1])) {
                throw new \Exception("Invalid namespace declaration: $namespace");
            }
            $this->additionalNamespaces[$parts[0]] = $parts[1];
        }
        $this->noRoot = ($input->getOption('no-root') && ($this->batchSize == 1 || $this->singleId));
        if ($config = $input->getOption('xslt')) {
            $this->xslTransformation = new XslTransformation(
                RECMAN_BASE_PATH . '/transformations',
                $config
            );
        }
        $this->transformBeforeXPath = $input->getOption('xslt-first');
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
                        '$gte' => $this->db->getTimestamp(strtotime($this->fromDate)),
                    ],
                ],
                [
                    'updated' => [
                        '$lte' => $this->db->getTimestamp(strtotime($this->untilDate)),
                    ],
                ],
            ];
        } elseif ($this->fromDate) {
            $params['updated'] = ['$gte' => $this->db->getTimestamp(strtotime($this->fromDate))];
        } elseif ($this->untilDate) {
            $params['updated'] = ['$lte' => $this->db->getTimestamp(strtotime($this->untilDate))];
        }
        if ($this->fromCreateDate && $this->untilCreateDate) {
            $params['$and'] = [
                [
                    'created' => [
                        '$gte' => $this->db->getTimestamp(strtotime($this->fromCreateDate)),
                    ],
                ],
                [
                    'created' => [
                        '$lte' => $this->db->getTimestamp(strtotime($this->untilCreateDate)),
                    ],
                ],
            ];
        } elseif ($this->fromCreateDate) {
            $params['created'] = [
                '$gte' => $this->db->getTimestamp(strtotime($this->fromCreateDate)),
            ];
        } elseif ($this->untilCreateDate) {
            $params['created'] = [
                '$lte' => $this->db->getTimestamp(strtotime($this->untilCreateDate)),
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
        $content = "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n\n";
        if (!$this->noRoot) {
            $content .= "<collection>\n";
        }
        file_put_contents(
            $this->currentFile,
            $content,
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
        if (!$this->noRoot) {
            file_put_contents($this->currentFile, "</collection>\n", FILE_APPEND);
        }
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

    /**
     * Add an node to the XML record
     *
     * @param \SimpleXMLElement $xml       XML record
     * @param array             $path      XML path from parseXmlPath
     * @param string            $nodeValue Node value
     *
     * @return void
     */
    protected function addXmlNode(\SimpleXMLElement $xml, array $path, string $nodeValue): void
    {
        $currentNode = $xml;
        foreach ($path as $pathPart) {
            // Check for existing node:
            if ($node = $this->findXmlNode($currentNode, $pathPart)) {
                $currentNode = $node;
                continue;
            }

            // Add a new node:
            $currentNode = $currentNode->addChild(
                $pathPart['nodeName'],
                null,
                $this->getXmlNamespaceFromPrefix($xml, $pathPart['prefix'])
            );
            foreach ($pathPart['attrs'] as $attr => $value) {
                $currentNode->addAttribute($attr, $value);
            }
        }
        // See https://github.com/phpstan/phpstan/issues/8236
        // @phpstan-ignore-next-line
        $currentNode[0] = $nodeValue;
    }

    /**
     * Try to find a node in XML element
     *
     * @param \SimpleXMLElement $xml      XML node
     * @param array             $pathPart Path item (an element from parseXmlPath)
     *
     * @return ?\SimpleXMLElement
     */
    protected function findXmlNode(\SimpleXMLElement $xml, array $pathPart): ?\SimpleXMLElement
    {
        $ns = $this->getXmlNamespaceFromPrefix($xml, $pathPart['prefix']);
        foreach ($xml->children($ns, true)->{$pathPart['nodeName']} as $candidate) {
            if ($this->xmlNodeAttributesMatch($candidate, $pathPart['attrs'])) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * Check if node attributes match the given array of attributes
     *
     * @param \SimpleXMLElement $node       Node
     * @param array             $attributes Attributes to check
     *
     * @return bool
     */
    protected function xmlNodeAttributesMatch(\SimpleXMLElement $node, array $attributes): bool
    {
        foreach ($node->attributes() as $attr => $value) {
            if (($attributes[$attr] ?? null) !== $value) {
                return false;
            }
            unset($attributes[$attr]);
        }
        return !$attributes;
    }

    /**
     * Get namespace from prefix
     *
     * @param \SimpleXMLElement $xml    Node
     * @param ?string           $prefix Prefix
     *
     * @return string
     */
    protected function getXmlNamespaceFromPrefix(\SimpleXMLElement $xml, ?string $prefix): ?string
    {
        if (null !== $prefix) {
            // Check additional namespaces too since SimpleXML doesn't return newly added prefixes:
            $namespaces = $xml->getNamespaces();
            return $namespaces[$prefix] ?? $this->additionalNamespaces[$prefix] ?? null;
        }
        return null;
    }

    /**
     * Parse XML path parameter
     *
     * @param ?string $path XPath-like path
     *
     * @return array
     */
    protected function parseXmlPath(?string $path): array
    {
        if (!$path) {
            return [];
        }
        $result = [];
        $pathParts = explode('/', $path);
        foreach ($pathParts as $pathPart) {
            // Extract node name and attributes:
            $parts = explode('[', $pathPart);
            $nodeParts = explode(':', $parts[0], 2);
            if (isset($nodeParts[1])) {
                $prefix = $nodeParts[0];
                $nodeName = $nodeParts[1];
            } else {
                $prefix = null;
                $nodeName = $nodeParts[0];
            }
            $attrs = [];
            foreach (isset($parts[1]) ? str_getcsv(rtrim($parts[1], ']'), ' ') : [] as $attr) {
                if (preg_match('/\@([^=]+)="(.*)"$/', $attr, $matches)) {
                    $attrs[$matches[1]] = $matches[2];
                }
            }
            $result[] = compact('prefix', 'nodeName', 'attrs');
        }
        return $result;
    }
}

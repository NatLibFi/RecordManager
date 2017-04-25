<?php
/**
 * Record Manager
 *
 * PHP version 5
 *
 * Copyright (C) The National Library of Finland 2011-2017.
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
 * @link     https://github.com/KDK-Alli/RecordManager
 */
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
} else {
    declare(ticks = 1);
}

require_once 'PEAR.php';
require_once 'HTTP/Request2.php';
require_once 'Logger.php';
require_once 'RecordFactory.php';
require_once 'FileSplitter.php';
require_once 'HarvestOaiPmh.php';
require_once 'HarvestMetaLib.php';
require_once 'HarvestMetaLibExport.php';
require_once 'HarvestSfx.php';
require_once 'HarvestSierraApi.php';
require_once 'XslTransformation.php';
require_once 'MetadataUtils.php';
require_once 'SolrUpdater.php';
require_once 'PerformanceCounter.php';

/**
 * RecordManager Class
 *
 * This is the main class for RecordManager.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class RecordManager
{
    /**
     * Quiet mode
     *
     * @var bool
     */
    public $quiet = false;

    /**
     * Verbose mode
     *
     * @var bool
     */
    protected $verbose = false;

    /**
     * Base path of Record Manager
     *
     * @var string
     */
    protected $basePath;

    /**
     * Logger
     *
     * @var Logger
     */
    protected $log;

    /**
     * Mongo Database
     *
     * @var MongoDB
     */
    protected $db;

    /**
     * Data source settings
     *
     * @var array
     */
    protected $dataSourceSettings;

    /**
     * Dedup Handler
     *
     * @var DedupHandler
     */
    protected $dedupHandler = null;

    /**
     * Harvested MetaLib Records
     *
     * @var array
     */
    protected $metaLibRecords = [];

    // TODO: refactor data source setting handling
    protected $harvestType = '';
    protected $format = '';
    protected $idPrefix = '';
    protected $sourceId = '';
    protected $institution = '';
    protected $recordXPath = '';
    protected $oaiIDXPath = '';
    protected $componentParts = '';
    protected $dedup = false;
    protected $normalizationXSLT = null;
    protected $solrTransformationXSLT = null;
    protected $recordSplitter = null;
    protected $keepMissingHierarchyMembers = false;
    protected $pretransformation = '';
    protected $indexMergedParts = true;
    protected $counts = false;
    protected $compressedRecords = true;
    protected $nonInheritedFields = [];
    protected $prependParentTitleWithUnitId = null;
    protected $previousId = '[none]';

    /**
     * Constructor
     *
     * @param boolean $console Specify whether RecordManager is executed on the
     * console so that log output is also output to the console.
     * @param boolean $verbose Whether verbose output is enabled
     */
    public function __construct($console = false, $verbose = false)
    {
        global $configArray;
        global $logger;

        date_default_timezone_set($configArray['Site']['timezone']);

        $this->log = new Logger();
        if ($console) {
            $this->log->logToConsole = true;
        }
        $this->verbose = $verbose;
        // Store logger in a global so that others can access it easily
        $logger = $this->log;

        if (isset($configArray['Mongo']['counts'])
            && $configArray['Mongo']['counts']
        ) {
            $this->counts = true;
        }
        if (isset($configArray['Mongo']['compressed_records'])
            && !$configArray['Mongo']['compressed_records']
        ) {
            $this->compressedRecords = false;
        }

        $basePath = substr(__FILE__, 0, strrpos(__FILE__, DIRECTORY_SEPARATOR));
        $basePath = substr($basePath, 0, strrpos($basePath, DIRECTORY_SEPARATOR));
        $this->dataSourceSettings = $configArray['dataSourceSettings']
            = parse_ini_file("$basePath/conf/datasources.ini", true);
        $this->basePath = $basePath;

        try {
            $connectTimeout = isset($configArray['Mongo']['connect_timeout'])
                ? $configArray['Mongo']['connect_timeout'] : 300000;
            $socketTimeout = isset($configArray['Mongo']['socket_timeout'])
                ? $configArray['Mongo']['socket_timeout'] : 300000;
            $mongo = new \MongoDB\Client(
                $configArray['Mongo']['url'],
                [
                    'connectTimeoutMS' => $connectTimeout,
                    'socketTimeoutMS' => $socketTimeout
                ]
            );
            $this->db = $mongo->{$configArray['Mongo']['database']};
        } catch (Exception $e) {
            $this->log->log(
                'startup',
                'Failed to connect to MongoDB: ' . $e->getMessage(),
                Logger::FATAL
            );
            throw $e;
        }

        // Used for format mapping in dedup handler
        $solrUpdater = new SolrUpdater(
            $this->db, $this->basePath, $this->log, $this->verbose
        );
        $dedupClass = isset($configArray['Site']['dedup_handler'])
            ? $configArray['Site']['dedup_handler']
            : 'DedupHandler';
        include_once "$dedupClass.php";
        $this->dedupHandler = new $dedupClass(
            $this->db, $this->log, $this->verbose, $solrUpdater,
            $this->dataSourceSettings
        );

        if (isset($configArray['Site']['full_title_prefixes'])) {
            MetadataUtils::$fullTitlePrefixes = array_map(
                ['MetadataUtils', 'normalize'],
                file(
                    "$basePath/conf/{$configArray['Site']['full_title_prefixes']}",
                    FILE_IGNORE_NEW_LINES
                )
            );
        }

        // Read the abbreviations file
        MetadataUtils::$abbreviations = isset($configArray['Site']['abbreviations'])
            ? $this->readListFile($configArray['Site']['abbreviations']) : [];

        // Read the artices file
        MetadataUtils::$articles = isset($configArray['Site']['articles'])
            ? $this->readListFile($configArray['Site']['articles']) : [];
    }

    /**
     * Catch the SIGINT signal and signal the main thread to terminate
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

    /**
     * Load records into the database from a file
     *
     * @param string $source Source id
     * @param string $files  Wildcard pattern of files containing the records
     *
     * @throws Exception
     * @return int Number of records loaded
     */
    public function loadFromFile($source, $files)
    {
        $this->loadSourceSettings($source);
        if (!$this->recordXPath) {
            $this->log->log(
                'loadFromFile', 'recordXPath not defined', Logger::FATAL
            );
            throw new Exception('recordXPath not defined');
        }
        $count = 0;
        foreach (glob($files) as $file) {
            $this->log->log(
                'loadFromFile', "Loading records from '$file' into '$source'"
            );
            $data = file_get_contents($file);
            if ($data === false) {
                throw new Exception("Could not read file '$file'");
            }

            if ($this->pretransformation) {
                if ($this->verbose) {
                    echo "Executing pretransformation\n";
                }
                $data = $this->pretransform($data);
            }

            if ($this->verbose) {
                echo "Creating FileSplitter\n";
            }
            $splitter = new FileSplitter(
                $data, $this->recordXPath, $this->oaiIDXPath
            );

            if ($this->verbose) {
                echo "Storing records\n";
            }
            while (!$splitter->getEOF()) {
                $oaiID = '';
                $data = $splitter->getNextRecord($oaiID);
                $count += $this->storeRecord($oaiID, false, $data);
                if ($this->verbose) {
                    echo "Stored records: $count\n";
                }
            }
            $this->log->log('loadFromFile', "$count records loaded");
        }

        $this->log->log('loadFromFile', "Total $count records loaded");
        return $count;
    }

    /**
     * Export records from the database to a file
     *
     * @param string $file        File name where to write exported records
     * @param string $deletedFile File name where to write ID's of deleted records
     * @param string $fromDate    Starting date (e.g. 2011-12-24)
     * @param int    $skipRecords Export only one per each $skipRecords records for
     * a sample set
     * @param string $sourceId    Source ID to export, or empty or * for all
     * @param string $singleId    Export only a record with the given ID
     * @param string $xpath       Optional XPath expression to limit the export with
     * @param bool   $sortDedup   Whether to sort the records by dedup id
     * @param string $addDedupId  When to add dedup id to each record
     * ('deduped' = when the record has duplicates, 'always' = even if the record
     * doesn't have duplicates, otherwise never)
     *
     * @return void
     */
    public function exportRecords($file, $deletedFile, $fromDate, $skipRecords = 0,
        $sourceId = '', $singleId = '', $xpath = '', $sortDedup = false,
        $addDedupId = ''
    ) {
        if ($file == '-') {
            $file = 'php://stdout';
        }

        if (file_exists($file)) {
            unlink($file);
        }
        if ($deletedFile && file_exists($deletedFile)) {
            unlink($deletedFile);
        }
        file_put_contents(
            $file,
            "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n\n<collection>\n",
            FILE_APPEND
        );

        try {
            $this->log->log(
                'exportRecords',
                "Creating record list (from "
                . ($fromDate ? $fromDate : 'the beginning') . ')'
            );

            $params = [];
            if ($singleId) {
                $params['_id'] = $singleId;
            } else {
                if ($fromDate) {
                    $params['updated']
                        = ['$gte' => strtotime($fromDate)];
                }
                $params['update_needed'] = false;
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
            $options = ['noCursorTimeout' => true];
            if ($sortDedup) {
                $options['sort'] = ['dedup_id' => 1];
            }
            $records = $this->db->record->find($params, $options);
            $total = $this->counts ? $this->db->record->count($params, $options)
                : 'the';
            $count = 0;
            $deduped = 0;
            $deleted = 0;
            $this->log->log('exportRecords', "Exporting $total records");
            if ($skipRecords) {
                $this->log->log(
                    'exportRecords', "(1 per each $skipRecords records)"
                );
            }
            foreach ($records as $record) {
                $metadataRecord = RecordFactory::createRecord(
                    $record['format'],
                    MetadataUtils::getRecordData($record, true),
                    $record['oai_id'],
                    $record['source_id']
                );
                if ($xpath) {
                    $xml = $metadataRecord->toXML();
                    $xpathResult = simplexml_load_string($xml)->xpath($xpath);
                    if ($xpathResult === false) {
                        throw new Exception(
                            "Failed to evaluate XPath expression '$xpath'"
                        );
                    }
                    if (!$xpathResult) {
                        continue;
                    }
                }
                ++$count;
                if ($record['deleted']) {
                    if ($deletedFile) {
                        file_put_contents(
                            $deletedFile, "{$record['_id']}\n", FILE_APPEND
                        );
                    }
                    ++$deleted;
                } else {
                    if ($skipRecords > 0 && $count % $skipRecords != 0) {
                        continue;
                    }
                    if (isset($record['dedup_id'])) {
                        ++$deduped;
                    }
                    if ($addDedupId == 'always') {
                        $metadataRecord->addDedupKeyToMetadata(
                            isset($record['dedup_id'])
                            ? $record['dedup_id']
                            : $record['_id']
                        );
                    } elseif ($addDedupId == 'deduped') {
                        $metadataRecord->addDedupKeyToMetadata(
                            isset($record['dedup_id'])
                            ? $record['dedup_id']
                            : ''
                        );
                    }
                    $xml = $metadataRecord->toXML();
                    $xml = preg_replace('/^<\?xml.*?\?>[\n\r]*/', '', $xml);
                    file_put_contents($file, $xml . "\n", FILE_APPEND);
                }
                if ($count % 1000 == 0) {
                    $this->log->log(
                        'exportRecords',
                        "$count records (of which $deduped deduped, $deleted "
                        . "deleted) exported"
                    );
                }
            }
            $this->log->log(
                'exportRecords',
                "Completed with $count records (of which $deduped deduped, $deleted "
                . "deleted) exported"
            );
        } catch (Exception $e) {
            $this->log->log(
                'exportRecords', 'Exception: ' . $e->getMessage(), Logger::FATAL
            );
        }
        file_put_contents($file, "</collection>\n", FILE_APPEND);
    }

    /**
     * Send updates to a Solr index (e.g. VuFind)
     *
     * @param string|null $fromDate   Starting date for updates (if empty
     *                                string, last update date stored in the database
     *                                is used and if null, all records are processed)
     * @param string      $sourceId   Source ID to update, or empty or * for all
     *                                sources (ignored if record merging is enabled)
     * @param string      $singleId   Export only a record with the given ID
     * @param bool        $noCommit   If true, changes are not explicitly committed
     * @param string      $compare    If set, just compare records and write
     *                                differences into the file in this parameter
     * @param string      $dumpPrefix If set, dump Solr records into a file instead
     *                                sending them to Solr
     *
     * @return void
     */
    public function updateSolrIndex($fromDate = null, $sourceId = '', $singleId = '',
        $noCommit = false, $compare = '', $dumpPrefix = ''
    ) {
        $updater = new SolrUpdater(
            $this->db, $this->basePath, $this->log, $this->verbose
        );
        $updater->updateRecords(
            $fromDate, $sourceId, $singleId, $noCommit, false, $compare, $dumpPrefix
        );
    }

    /**
     * Renormalize records in a data source
     *
     * @param string $sourceId Source ID to renormalize
     * @param string $singleId Renormalize only a single record with the given ID
     *
     * @return void
     */
    public function renormalize($sourceId, $singleId)
    {
        foreach ($this->dataSourceSettings as $source => $settings) {
            if ($sourceId && $sourceId != '*' && $source != $sourceId) {
                continue;
            }
            if (empty($source) || empty($settings)) {
                continue;
            }
            $this->loadSourceSettings($source);
            $this->log->log('renormalize', "Creating record list for '$source'");

            $params = ['deleted' => false];
            if ($singleId) {
                $params['_id'] = $singleId;
                $params['source_id'] = $source;
            } else {
                $params['source_id'] = $source;
            }
            $records = $this->db->record->find(
                $params, ['batchSize' => 5000, 'noCursorTimeout' => true]
            );
            $total = $this->counts ? $this->db->record->count($params) : 'the';
            $count = 0;

            $this->log->log(
                'renormalize', "Processing $total records from '$source'"
            );
            $pc = new PerformanceCounter();
            foreach ($records as $record) {
                $originalData = MetadataUtils::getRecordData($record, false);
                $normalizedData = $originalData;
                if (isset($this->normalizationXSLT)) {
                    $origMetadataRecord = RecordFactory::createRecord(
                        $record['format'],
                        $originalData,
                        $record['oai_id'],
                        $record['source_id']
                    );
                    $normalizedData = $this->normalizationXSLT->transform(
                        $origMetadataRecord->toXML(), ['oai_id' => $record['oai_id']]
                    );
                }

                $metadataRecord = RecordFactory::createRecord(
                    $record['format'],
                    $normalizedData,
                    $record['oai_id'],
                    $record['source_id']
                );
                $metadataRecord->normalize();
                $hostID = $metadataRecord->getHostRecordID();
                $normalizedData = $metadataRecord->serialize();
                if ($this->dedup && !$hostID) {
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
                        unset($record['dedup_id']);
                    }
                    $record['update_needed'] = false;
                }

                $record['original_data'] = $this->compressedRecords
                    ? new \MongoDB\BSON\Binary(gzdeflate($originalData), 2)
                    : $originalData;
                if ($normalizedData == $originalData) {
                    $record['normalized_data'] = '';
                } else {
                    $record['normalized_data'] = $this->compressedRecords
                        ? new \MongoDB\BSON\Binary(gzdeflate($normalizedData), 2)
                        : $normalizedData;
                }
                $record['linking_id'] = $metadataRecord->getLinkingID();
                if ($hostID) {
                    $record['host_record_id'] = $hostID;
                } elseif (isset($record['host_record_id'])) {
                    unset($record['host_record_id']);
                }
                $record['updated'] = new \MongoDB\BSON\UTCDateTime(time() * 1000);
                $this->db->record->replaceOne(['_id' => $record['_id']], $record);

                if ($this->verbose) {
                    echo "Metadata for record {$record['_id']}: \n";
                    $record['normalized_data']
                        = MetadataUtils::getRecordData($record, true);
                    $record['original_data']
                        = MetadataUtils::getRecordData($record, false);
                    if ($record['normalized_data'] === $record['original_data']) {
                        $record['normalized_data'] = '';
                    }
                    print_r($record);
                }

                ++$count;
                if ($count % 1000 == 0) {
                    $pc->add($count);
                    $avg = $pc->getSpeed();
                    $this->log->log(
                        'renormalize',
                        "$count records processed from '$source', $avg records/sec"
                    );
                }
            }
            $this->log->log(
                'renormalize',
                "Completed with $count records processed from '$source'"
            );
        }
    }

    /**
     * Find duplicate records and give them dedup keys
     *
     * @param string $sourceId   Source ID to process, or empty or * for all sources
     * where dedup is enabled
     * @param bool   $allRecords If true, process all records regardless of their
     * status (otherwise only freshly imported or updated records are processed)
     * @param string $singleId   Process only a record with the given ID
     * @param bool   $markOnly   If true, just mark the records for deduplication
     *
     * @return void
     */
    public function deduplicate($sourceId, $allRecords = false, $singleId = '',
        $markOnly = false
    ) {
        // Install a signal handler so that we can exit cleanly if interrupted
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, [$this, 'sigIntHandler']);
            $this->log->log('deduplicate', 'Interrupt handler set');
        } else {
            $this->log->log(
                'deduplicate',
                'Could not set an interrupt handler -- pcntl not available'
            );
        }

        if ($allRecords || $markOnly) {
            foreach ($this->dataSourceSettings as $source => $settings) {
                if ($sourceId && $sourceId != '*' && $source != $sourceId) {
                    continue;
                }
                if (empty($source) || empty($settings) || !isset($settings['dedup'])
                    || !$settings['dedup']
                ) {
                    continue;
                }
                $this->log->log(
                    'deduplicate', "Marking all records for processing in '$source'"
                );
                $records = $this->db->record->find(
                    [
                        'source_id' => $source,
                        'host_record_id' => ['$exists' => false],
                        'deleted' => false
                    ],
                    ['noCursorTimeout' => true]
                );
                $pc = new PerformanceCounter();
                $count = 0;
                foreach ($records as $record) {
                    if (isset($this->terminate)) {
                        $this->log->log('deduplicate', 'Termination upon request');
                        exit(1);
                    }

                    $this->db->record->updateOne(
                        ['_id' => $record['_id']],
                        ['$set' => ['update_needed' => true]]
                    );

                    ++$count;
                    if ($count % 1000 == 0) {
                        $pc->add($count);
                        $avg = $pc->getSpeed();
                        if ($this->verbose) {
                            echo "\n";
                        }
                        $this->log->log(
                            'deduplicate',
                            "$count records marked for processing in '$source', "
                            . "$avg records/sec"
                        );
                    }
                }
                if (isset($this->terminate)) {
                    $this->log->log('deduplicate', 'Termination upon request');
                    exit(1);
                }

                $this->log->log(
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
                if (empty($source) || empty($settings) || !isset($settings['dedup'])
                    || !$settings['dedup']
                ) {
                    continue;
                }

                $this->loadSourceSettings($source);
                $this->log->log(
                    'deduplicate',
                    "Creating record list for '$source'"
                    . ($allRecords ? ' (all records)' : '')
                );

                $params = ['deleted' => false, 'source_id' => $source];
                if ($singleId) {
                    $params['_id'] = $singleId;
                } else {
                    $params['update_needed'] = true;
                }
                $records = $this->db->record->find(
                    $params, ['batchSize' => 5000, 'noCursorTimeout' => true]
                );
                $total = $this->counts ? $this->db->record->count($params) : 'the';
                $count = 0;
                $deduped = 0;
                $pc = new PerformanceCounter();
                $this->log->log(
                    'deduplicate', "Processing $total records for '$source'"
                );
                foreach ($records as $record) {
                    if (isset($this->terminate)) {
                        $this->log->log('deduplicate', 'Termination upon request');
                        exit(1);
                    }
                    $startRecordTime = microtime(true);
                    if ($this->dedupHandler->dedupRecord($record)) {
                        if ($this->verbose) {
                            echo '+';
                        }
                        ++$deduped;
                    } else {
                        if ($this->verbose) {
                            echo '.';
                        }
                    }
                    if ($this->verbose && microtime(true) - $startRecordTime > 0.7) {
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
                        $this->log->log(
                            'deduplicate',
                            "$count records processed for '$source', $deduped "
                            . "deduplicated, $avg records/sec"
                        );
                    }
                }
                if (isset($this->terminate)) {
                    $this->log->log('deduplicate', 'Termination upon request');
                    exit(1);
                }
                $this->log->log(
                    'deduplicate',
                    "Completed with $count records processed for '$source', "
                    . "$deduped deduplicated"
                );
            } catch (Exception $e) {
                $this->log->log(
                    'deduplicate', 'Exception: ' . $e->getMessage(), Logger::FATAL
                );
            }
            if (isset($this->terminate)) {
                $this->log->log('deduplicate', 'Termination upon request');
                exit(1);
            }
        }
    }

    /**
     * Harvest records from a data source
     *
     * @param string      $repository           Source ID to harvest
     * @param string      $harvestFromDate      Override start date (otherwise
     * harvesting is done from the previous harvest date)
     * @param string      $harvestUntilDate     Override end date (otherwise
     * current date is used)
     * @param string      $startResumptionToken Override OAI-PMH resumptionToken to
     * resume interrupted harvesting process (note
     *                                     that tokens may have a limited lifetime)
     * @param string      $exclude              Source ID's to exclude whe using '*'
     * for repository
     * @param bool|string $reharvest            Whether to consider this a full
     * reharvest where sets may have changed
     *                                          (deletes records not received during
     * this harvesting)
     *
     * @return void
     * @throws Exception
     */
    public function harvest($repository = '', $harvestFromDate = null,
        $harvestUntilDate = null, $startResumptionToken = '', $exclude = null,
        $reharvest = false
    ) {
        if (empty($this->dataSourceSettings)) {
            $this->log->log(
                'harvest',
                'Please add data source settings to datasources.ini',
                Logger::FATAL
            );
            throw new Exception("Data source settings missing in datasources.ini");
        }

        if ($reharvest && !is_string($reharvest) && $startResumptionToken) {
            $this->log->log(
                'harvest',
                'Reharvest start date must be specified when used with the'
                . ' resumption token override option',
                Logger::FATAL
            );
            throw new Exception(
                'Reharvest start date must be specified when used with the'
                . ' resumption token override option'
            );
        }

        $excludedSources = isset($exclude) ? explode(',', $exclude) : [];

        // Loop through all the sources and perform harvests
        foreach ($this->dataSourceSettings as $source => $settings) {
            try {
                if ($repository && $repository != '*' && $source != $repository) {
                    continue;
                }
                if ((!$repository || $repository == '*')
                    && in_array($source, $excludedSources)
                ) {
                    continue;
                }
                if (empty($source) || empty($settings) || !isset($settings['url'])) {
                    continue;
                }
                $this->log->log(
                    'harvest',
                    "Harvesting from '{$source}'"
                    . ($reharvest ? ' (full reharvest)' : '')
                );

                $this->loadSourceSettings($source);

                if ($this->verbose) {
                    $settings['verbose'] = true;
                }

                if ($this->harvestType == 'metalib') {
                    // MetaLib doesn't handle deleted records, so we'll just fetch
                    // everything and compare with what we have
                    $this->log->log('harvest', "Fetching records from MetaLib");
                    $harvest = new HarvestMetaLib(
                        $this->log, $this->db, $source, $this->basePath, $settings
                    );
                    $harvestedRecords = $harvest->harvest();
                    $this->processFullRecordSet($source, $harvestedRecords);
                } elseif ($this->harvestType == 'metalib_export') {
                    // MetaLib doesn't handle deleted records, so we'll just fetch
                    // everything and delete whatever we didn't get
                    $harvest = new HarvestMetaLibExport(
                        $this->log, $this->db, $source, $this->basePath, $settings
                    );
                    if (isset($harvestFromDate)) {
                        $harvest->setStartDate($harvestFromDate);
                    }
                    if (isset($harvestUntilDate)) {
                        $harvest->setEndDate($harvestUntilDate);
                    }
                    $this->metaLibRecords = [];
                    $harvest->harvest([$this, 'storeMetaLibRecord']);
                    if ($this->metaLibRecords) {
                        $this->processFullRecordSet($source, $this->metaLibRecords);
                    }
                } elseif ($this->harvestType == 'sfx') {
                    $harvest = new HarvestSfx(
                        $this->log, $this->db, $source, $this->basePath, $settings
                    );
                    if (isset($harvestFromDate)) {
                        $harvest->setStartDate($harvestFromDate);
                    }
                    if (isset($harvestUntilDate)) {
                        $harvest->setEndDate($harvestUntilDate);
                    }
                    $harvest->harvest([$this, 'storeRecord']);
                } else {
                    $dateThreshold = null;
                    if ($reharvest) {
                        if (is_string($reharvest)) {
                            $dateThreshold = new \MongoDB\BSON\UTCDateTime(
                                strtotime($reharvest)
                            );
                        } else {
                            $dateThreshold = new \MongoDB\BSON\UTCDateTime(
                                time() * 1000
                            );
                        }
                        $this->log->log(
                            'harvest',
                            'Reharvest date threshold: '
                            . $dateThreshold->toDatetime()->format('Y-m-d H:i:s')
                        );
                    }

                    if ($this->harvestType == 'sierra') {
                        $harvest = new HarvestSierraApi(
                            $this->log,
                            $this->db,
                            $source,
                            $this->basePath,
                            $settings,
                            $startResumptionToken
                        );
                    } else {
                        $harvest = new HarvestOAIPMH(
                            $this->log,
                            $this->db,
                            $source,
                            $this->basePath,
                            $settings,
                            $startResumptionToken
                        );
                    }
                    if (isset($harvestFromDate)) {
                        $harvest->setStartDate(
                            $harvestFromDate == '-' ? null : $harvestFromDate
                        );
                    }
                    if (isset($harvestUntilDate)) {
                        $harvest->setEndDate($harvestUntilDate);
                    }

                    $harvest->harvest([$this, 'storeRecord']);

                    if ($reharvest) {
                        if ($harvest->getHarvestedRecordCount() == 0) {
                            $this->log->log(
                                'harvest',
                                "No records received from '$source' during"
                                . ' reharvesting -- assuming an error and skipping'
                                . ' marking records deleted',
                                Logger::FATAL
                            );
                        } else {
                            $this->log->log(
                                'harvest',
                                'Marking deleted all records not received during'
                                . ' the harvesting'
                            );
                            $records = $this->db->record->find(
                                [
                                    'source_id' => $this->sourceId,
                                    'deleted' => false,
                                    'updated' => ['$lt' => $dateThreshold]
                                ],
                                ['noCursorTimeout' => true]
                            );
                            $count = 0;
                            foreach ($records as $record) {
                                $this->storeRecord($record['oai_id'], true, '');
                                if (++$count % 1000 == 0) {
                                    $this->log->log(
                                        'harvest', "Deleted $count records"
                                    );
                                }
                            }
                            $this->log->log('harvest', "Deleted $count records");
                        }
                    }

                    if (!$reharvest && isset($settings['deletions'])
                        && strncmp(
                            $settings['deletions'], 'ListIdentifiers', 15
                        ) == 0
                    ) {
                        // The repository doesn't support reporting deletions, so
                        // list all identifiers and mark deleted records that were
                        // not found

                        if (!is_callable([$harvest, 'listIdentifiers'])) {
                            throw new Exception(
                                get_class($harvest)
                                . ' does not support listing identifiers'
                            );
                        }

                        $processDeletions = true;
                        $interval = null;
                        $deletions = explode(':', $settings['deletions']);
                        if (isset($deletions[1])) {
                            $state = $this->db->state->findOne(
                                ['_id' => "Last Deletion Processing Time $source"]
                            );
                            if (isset($state)) {
                                $interval
                                    = round((time() - $state['value']) / 3600 / 24);
                                if ($interval < $deletions[1]) {
                                    $this->log->log(
                                        'harvest',
                                        "Not processing deletions, $interval days "
                                        . "since last time"
                                    );
                                    $processDeletions = false;
                                }
                            }
                        }

                        if ($processDeletions) {
                            $this->log->log(
                                'harvest',
                                'Processing deletions' . (isset($interval)
                                    ? " ($interval days since last time)" : '')
                            );

                            $this->log->log('harvest', 'Unmarking records');
                            $this->db->record->updateMany(
                                ['source_id' => $this->sourceId, 'deleted' => false],
                                ['$unset' => ['mark' => 1]]
                            );

                            $this->log->log('harvest', "Fetching identifiers");
                            $harvest->listIdentifiers([$this, 'markRecord']);

                            $this->log->log('harvest', "Marking deleted records");

                            $records = $this->db->record->find(
                                [
                                    'source_id' => $this->sourceId,
                                    'deleted' => false,
                                    'mark' => ['$exists' => false]
                                ],
                                ['noCursorTimeout' => true]
                            );
                            $count = 0;
                            foreach ($records as $record) {
                                $this->storeRecord($record['oai_id'], true, '');
                                if (++$count % 1000 == 0) {
                                    $this->log->log(
                                        'harvest', "Deleted $count records"
                                    );
                                }
                            }
                            $this->log->log('harvest', "Deleted $count records");

                            $state = [
                                '_id' => "Last Deletion Processing Time $source",
                                'value' => time()
                            ];
                            $this->db->state->replaceOne(
                                ['_id' => $state['_id']],
                                $state,
                                ['upsert' => true]
                            );
                        }
                    }
                }
                $this->log->log('harvest', "Harvesting from '{$source}' completed");
            } catch (Exception $e) {
                $this->log->log(
                    'harvest', 'Exception: ' . $e->getMessage(), Logger::FATAL
                );
            }
        }
    }

    /**
     * Dump a single record to console
     *
     * @param string $recordID ID of the record to be dumped
     *
     * @return void
     * @throws Exception
     */
    public function dumpRecord($recordID)
    {
        if (!$recordID) {
            throw new Exception('dump: record id must be specified');
        }
        $records = $this->db->record->find(
            ['_id' => $recordID], ['noCursorTimeout' => true]
        );
        foreach ($records as $record) {
            $record['original_data'] = MetadataUtils::getRecordData($record, false);
            $record['normalized_data'] = MetadataUtils::getRecordData($record, true);
            if ($record['original_data'] == $record['normalized_data']) {
                $record['normalized_data'] = '';
            }
            print_r($record);
        }
    }

    /**
     * Mark deleted records of a single data source
     *
     * @param string $sourceId Source ID
     *
     * @return void
     */
    public function markDeleted($sourceId)
    {
        $this->log->log('markDeleted', "Creating record list for '$sourceId'");

        $params = ['deleted' => false, 'source_id' => $sourceId];
        $records = $this->db->record->find($params, ['noCursorTimeout' => true]);
        $total = $this->counts ? $this->db->record->count($params) : 'the';
        $count = 0;

        $this->log->log(
            'markDeleted', "Marking deleted $total records from '$sourceId'"
        );
        $pc = new PerformanceCounter();
        foreach ($records as $record) {
            if (isset($record['dedup_id'])) {
                $this->dedupHandler->removeFromDedupRecord(
                    $record['dedup_id'], $record['_id']
                );
                unset($record['dedup_id']);
            }
            $record['deleted'] = true;
            $record['updated'] = new \MongoDB\BSON\UTCDateTime(time() * 1000);
            $this->db->record->replaceOne(['_id' => $record['_id']], $record);

            ++$count;
            if ($count % 1000 == 0) {
                $pc->add($count);
                $avg = $pc->getSpeed();
                $this->log->log(
                    'markDeleted',
                    "$count records marked deleted from '$sourceId', "
                    . "$avg records/sec"
                );
            }
        }
        $this->log->log(
            'markDeleted',
            "Completed with $count records marked deleted from '$sourceId'"
        );

        $this->log->log(
            'markDeleted',
            "Deleting last harvest date from data source '$sourceId'"
        );
        $this->db->state->deleteOne(
            ['_id' => "Last Harvest Date $sourceId"]
        );
        $this->log->log('markDeleted', "Marking of $sourceId completed");
    }

    /**
     * Delete records of a single data source from the Mongo database
     *
     * @param string  $sourceId Source ID
     * @param boolean $force    Force deletion even if dedup is enable for the source
     *
     * @return void
     */
    public function deleteRecords($sourceId, $force = false)
    {
        if (isset($this->dataSourceSettings[$sourceId])) {
            $settings = $this->dataSourceSettings[$sourceId];
            if (isset($settings['dedup']) && $settings['dedup']) {
                if ($force) {
                    $this->log->log(
                        'deleteRecords',
                        "Deduplication enabled for '$sourceId' but deletion forced "
                        . " - may lead to orphaned dedup records",
                        Logger::WARNING
                    );
                } else {
                    $this->log->log(
                        'deleteRecords',
                        "Deduplication enabled for '$sourceId', aborting "
                        . "(use markdeleted instead)",
                        Logger::ERROR
                    );
                    return;
                }
            }
        }

        $params = [];
        $params['source_id'] = $sourceId;
        $this->log->log('deleteRecords', "Creating record list for '$sourceId'");

        $params = ['source_id' => $sourceId];
        $records = $this->db->record->find($params, ['noCursorTimeout' => true]);
        $total = $this->counts ? $this->db->record->count($params) : 'the';
        $count = 0;

        $this->log->log('deleteRecords', "Deleting $total records from '$sourceId'");
        $pc = new PerformanceCounter();
        foreach ($records as $record) {
            if (isset($record['dedup_id'])) {
                $this->dedupHandler->removeFromDedupRecord(
                    $record['dedup_id'], $record['_id']
                );
            }
            $this->db->record->deleteOne(['_id' => $record['_id']]);

            ++$count;
            if ($count % 1000 == 0) {
                $pc->add($count);
                $avg = $pc->getSpeed();
                $this->log->log(
                    'deleteRecords',
                    "$count records deleted from '$sourceId', $avg records/sec"
                );
            }
        }
        $this->log->log(
            'deleteRecords', "Completed with $count records deleted from '$sourceId'"
        );

        $this->log->log(
            'deleteRecords',
            "Deleting last harvest date from data source '$sourceId'"
        );
        $this->db->state->deleteOne(['_id' => "Last Harvest Date $sourceId"]);
        $this->log->log('deleteRecords', "Deletion of $sourceId completed");
    }

    /**
     * Delete records of a single data source from the Solr index
     *
     * @param string $sourceId Source ID
     *
     * @return void
     */
    public function deleteSolrRecords($sourceId)
    {
        global $configArray;

        $updater = new SolrUpdater(
            $this->db, $this->basePath, $this->log, $this->verbose
        );
        if (isset($configArray['Solr']['merge_records'])
            && $configArray['Solr']['merge_records']
        ) {
            $this->log->log(
                'deleteSolrRecords',
                "Deleting data source '$sourceId' from merged records via Solr "
                . "update for merged records"
            );
            $updater->updateRecords('', $sourceId, '', false, true);
        }
        $this->log->log(
            'deleteSolrRecords',
            "Deleting data source '$sourceId' directly from Solr"
        );
        $updater->deleteDataSource($sourceId);
        $this->log->log(
            'deleteSolrRecords', "Deletion of '$sourceId' from Solr completed"
        );
    }

    /**
     * Purge deleted records from the database
     *
     * @param int    $daysToKeep Days to keep
     * @param string $sourceId   Optional source ID
     *
     * @return void
     */
    public function purgeDeletedRecords($daysToKeep = 0, $sourceId = '')
    {
        global $configArray;

        // Process normal records
        $dateStr = '';
        $params = ['deleted' => true];
        if ($daysToKeep) {
            $date = strtotime("-$daysToKeep day");
            $dateStr = ' until ' . date('Y-m-d', $date);
            $params['updated'] = ['$lt' => new \MongoDB\BSON\UTCDateTime($date)];
        }
        if ($sourceId) {
            $params['source_id'] = $sourceId;
        }
        $this->log->log(
            'purgeDeletedRecords',
            "Creating record list$dateStr" . ($sourceId ? " for '$sourceId'" : '')
        );
        $records = $this->db->record->find($params, ['noCursorTimeout' => true]);
        $total = $this->counts ? $this->db->record->count($params) : 'the';
        $count = 0;

        $this->log->log('purgeDeletedRecords', "Purging $total records");
        $pc = new PerformanceCounter();
        foreach ($records as $record) {
            $this->db->record->deleteOne(['_id' => $record['_id']]);
            ++$count;
            if ($count % 1000 == 0) {
                $pc->add($count);
                $avg = $pc->getSpeed();
                $this->log->log(
                    'purgeDeletedRecords',
                    "$count records purged, $avg records/sec"
                );
            }
        }
        $this->log->log(
            'purgeDeletedRecords', "Total $count records purged"
        );

        if ($sourceId) {
            $this->log->log(
                'purgeDeletedRecords', 'Source specified -- skipping dedup records'
            );
            return;
        }

        // Process dedup records
        $params = ['deleted' => true];
        if ($daysToKeep) {
            $params['changed'] = ['$lt' => new \MongoDB\BSON\UTCDateTime($date)];
        }
        $this->log->log('purgeDeletedRecords', "Creating dedup record list$dateStr");
        $records = $this->db->dedup->find($params, ['noCursorTimeout' => true]);
        $total = $this->counts ? $this->db->dedup->count($params) : 'the';
        $count = 0;

        $this->log->log('purgeDeletedRecords', "Purging $total dedup records");
        $pc = new PerformanceCounter();
        foreach ($records as $record) {
            $this->db->dedup->deleteOne(['_id' => $record['_id']]);
            ++$count;
            if ($count % 1000 == 0) {
                $pc->add($count);
                $avg = $pc->getSpeed();
                $this->log->log(
                    'purgeDeletedRecords',
                    "$count dedup records purged, $avg records/sec"
                );
            }
        }
        $this->log->log(
            'purgeDeletedRecords', "Total $count dedup records purged"
        );
    }

    /**
     * Optimize the Solr index
     *
     * @return void
     */
    public function optimizeSolr()
    {
        $updater = new SolrUpdater(
            $this->db, $this->basePath, $this->log, $this->verbose
        );

        $this->log->log('optimizeSolr', 'Optimizing Solr index');
        $updater->optimizeIndex();
        $this->log->log('optimizeSolr', 'Solr optimization completed');
    }

    /**
     * Save a record into the database. Used by e.g. OAI-PMH harvesting.
     *
     * @param string $oaiID      ID of the record as received from OAI-PMH
     * @param bool   $deleted    Whether the record is to be deleted
     * @param string $recordData Record metadata
     *
     * @throws Exception
     * @return integer Number of records processed (can be > 1 for split records)
     */
    public function storeRecord($oaiID, $deleted, $recordData)
    {
        if ($deleted) {
            // A single OAI-PMH record may have been split to multiple records. Find
            // all occurrences.
            $records = $this->db->record->find(
                ['source_id' => $this->sourceId, 'oai_id' => $oaiID],
                ['noCursorTimeout' => true]
            );
            $count = 0;
            foreach ($records as $record) {
                if (isset($record['dedup_id'])) {
                    $this->dedupHandler->removeFromDedupRecord(
                        $record['dedup_id'], $record['_id']
                    );
                    unset($record['dedup_id']);
                }
                $record['deleted'] = true;
                $record['updated'] = new \MongoDB\BSON\UTCDateTime(time() * 1000);
                $record['update_needed'] = false;
                $this->db->record->replaceOne(['_id' => $record['_id']], $record);
                ++$count;
            }
            return $count;
        }

        $dataArray = [];
        if ($this->recordSplitter) {
            if ($this->verbose) {
                echo "Splitting records\n";
            }
            if (is_string($this->recordSplitter)) {
                include_once $this->recordSplitter;
                $className = substr($this->recordSplitter, 0, -4);
                $splitter = new $className($recordData);
                while (!$splitter->getEOF()) {
                    $dataArray[] = $splitter->getNextRecord(
                        $this->prependParentTitleWithUnitId,
                        $this->nonInheritedFields
                    );
                }
            } else {
                $doc = new DOMDocument();
                $doc->loadXML($recordData);
                if ($this->verbose) {
                    echo "XML Doc Created\n";
                }
                $transformedDoc = $this->recordSplitter->transformToDoc($doc);
                if ($this->verbose) {
                    echo "XML Transformation Done\n";
                }
                $records = simplexml_import_dom($transformedDoc);
                if ($this->verbose) {
                    echo "Creating record array\n";
                }
                foreach ($records as $record) {
                    $dataArray[] = $record->saveXML();
                }
            }
        } else {
            $dataArray = [$recordData];
        }

        if ($this->verbose) {
            echo "Storing array of " . count($dataArray) . " records\n";
        }

        // Store start time so that we can mark deleted any child records not
        // present anymore
        $startTime = new \MongoDB\BSON\UTCDateTime(time() * 1000);

        $count = 0;
        $mainID = '';
        foreach ($dataArray as $data) {
            if (isset($this->normalizationXSLT)) {
                $metadataRecord = RecordFactory::createRecord(
                    $this->format,
                    $this->normalizationXSLT->transform($data, ['oai_id' => $oaiID]),
                    $oaiID,
                    $this->sourceId
                );
                $metadataRecord->normalize();
                $normalizedData = $metadataRecord->serialize();
                $originalData = RecordFactory::createRecord(
                    $this->format, $data, $oaiID, $this->sourceId
                )->serialize();
            } else {
                $metadataRecord = RecordFactory::createRecord(
                    $this->format, $data, $oaiID, $this->sourceId
                );
                $originalData = $metadataRecord->serialize();
                $metadataRecord->normalize();
                $normalizedData = $metadataRecord->serialize();
            }

            $hostID = $metadataRecord->getHostRecordID();
            $id = $metadataRecord->getID();
            if (!$id) {
                if (!$oaiID) {
                    throw new Exception(
                        'Empty ID returned for record, and no OAI ID '
                        . "(previous record ID: $this->previousId)"
                    );
                }
                $id = $oaiID;
            }
            $this->previousId = $id;
            $id = $this->idPrefix . '.' . $id;
            $dbRecord = $this->db->record->findOne(['_id' => $id]);
            if ($dbRecord) {
                $dbRecord['updated'] = new \MongoDB\BSON\UTCDateTime(time() * 1000);
                if ($this->verbose) {
                    echo "Updating record $id\n";
                }
            } else {
                $dbRecord = [];
                $dbRecord['source_id'] = $this->sourceId;
                $dbRecord['_id'] = $id;
                $dbRecord['created'] = $dbRecord['updated']
                    = new \MongoDB\BSON\UTCDateTime(time() * 1000);
                if ($this->verbose) {
                    echo "Adding record $id\n";
                }
            }
            $dbRecord['date'] = $dbRecord['updated'];
            if ($normalizedData) {
                if ($originalData == $normalizedData) {
                    $normalizedData = '';
                }
            }
            if ($this->compressedRecords) {
                $originalData = new \MongoDB\BSON\Binary(
                    gzdeflate($originalData), 2
                );
                if ($normalizedData) {
                    $normalizedData = new \MongoDB\BSON\Binary(
                        gzdeflate($normalizedData), 2
                    );
                }
            }
            $dbRecord['oai_id'] = $oaiID;
            $dbRecord['deleted'] = false;
            $dbRecord['linking_id'] = $metadataRecord->getLinkingID();
            if ($mainID) {
                $dbRecord['main_id'] = $mainID;
            }
            if ($hostID) {
                $dbRecord['host_record_id'] = $hostID;
            } elseif (isset($dbRecord['host_record_id'])) {
                unset($dbRecord['host_record_id']);
            }
            $dbRecord['format'] = $this->format;
            $dbRecord['original_data'] = $originalData;
            $dbRecord['normalized_data'] = $normalizedData;
            if ($this->dedup) {
                // If this is a host record, mark it to be deduplicated.
                // If this is a component part, mark its host record to be
                // deduplicated.
                if (!$hostID) {
                    $dbRecord['update_needed']
                        = $this->dedupHandler->updateDedupCandidateKeys(
                            $dbRecord, $metadataRecord
                        );
                } else {
                    $this->db->record->updateOne(
                        ['_id' => $hostID], ['$set' => ['update_needed' => true]]
                    );
                    $dbRecord['update_needed'] = false;
                }
            } else {
                if (isset($dbRecord['title_keys'])) {
                    unset($dbRecord['title_keys']);
                }
                if (isset($dbRecord['isbn_keys'])) {
                    unset($dbRecord['isbn_keys']);
                }
                if (isset($dbRecord['id_keys'])) {
                    unset($dbRecord['id_keys']);
                }
                $dbRecord['update_needed'] = false;
            }
            $this->db->record->replaceOne(
                ['_id' => $dbRecord['_id']], $dbRecord, ['upsert' => true]
            );
            ++$count;
            if (!$mainID) {
                $mainID = $id;
            }
        }

        if ($count > 1 && $mainID && !$this->keepMissingHierarchyMembers) {
            // We processed a hierarchical record. Mark deleted any children that
            // were not updated.
            $this->db->record->updateMany(
                [
                    'source_id' => $this->sourceId,
                    'main_id' => $mainID,
                    'updated' => ['$lt' => $startTime]
                ],
                ['$set' => [
                    'deleted' => true,
                    'updated' => new \MongoDB\BSON\UTCDateTime(time() * 1000),
                    'update_needed' => false
                ]]
            );
        }

        return $count;
    }

    /**
     * Save a record temporarily to an array. Used by MetaLib harvesting.
     *
     * @param string $oaiID      ID of the record as received from OAI-PMH
     * @param bool   $deleted    Whether the record is to be deleted
     * @param string $recordData Record metadata
     *
     * @throws Exception
     * @return integer Number of records processed (can be > 1 for split records)
     */
    public function storeMetaLibRecord($oaiID, $deleted, $recordData)
    {
        $this->metaLibRecords[] = $recordData;
        return 1;
    }

    /**
     * Count distinct values in the specified field (that would be added to the
     * Solr index)
     *
     * @param string $sourceId Source ID
     * @param string $field    Field name
     * @param bool   $mapped   Whether to count values after any mapping files are
     *                         are processed
     *
     * @return void
     */
    public function countValues($sourceId, $field, $mapped)
    {
        if (!$field) {
            echo "Field must be specified\n";
            exit;
        }
        $updater = new SolrUpdater(
            $this->db, $this->basePath, $this->log, $this->verbose
        );
        $updater->countValues($sourceId, $field, $mapped);
    }

    /**
     * Mark a record "seen". Used by OAI-PMH harvesting when deletions are not
     * supported.
     *
     * @param string $oaiID   ID of the record as received from OAI-PMH
     * @param bool   $deleted Whether the record is to be deleted
     *
     * @throws Exception
     * @return void
     */
    public function markRecord($oaiID, $deleted)
    {
        if ($deleted) {
            // Don't mark deleted records...
            return;
        }
        $this->db->record->updateMany(
            ['source_id' => $this->sourceId, 'oai_id' => $oaiID],
            ['$set' => ['mark' => true]]
        );
    }

    /**
     * Verify consistency of dedup records links with actual records
     *
     * @return void
     */
    public function checkDedupRecords()
    {
        $this->log->log('checkDedupRecords', "Checking dedup record consistency");

        $dedupRecords = $this->db->dedup->find([], ['noCursorTimeout' => true]);
        $count = 0;
        $fixed = 0;
        $pc = new PerformanceCounter();
        foreach ($dedupRecords as $dedupRecord) {
            $results = $this->dedupHandler->checkDedupRecord($dedupRecord);
            if ($results) {
                $fixed += count($results);
                foreach ($results as $result) {
                    $this->log->log('checkDedupRecords', $result);
                }
            }
            ++$count;
            if ($count % 1000 == 0) {
                $pc->add($count);
                $avg = $pc->getSpeed();
                $this->log->log(
                    'checkDedupRecords',
                    "$count records checked with $fixed links fixed, "
                    . "$avg records/sec"
                );
            }
        }
        $this->log->log(
            'checkDedupRecords',
            "Completed with $count records checked with $fixed links fixed"
        );
    }

    /**
     * Search for $regexp in data sources
     *
     * @param string $regexp Regular expression
     *
     * @return void
     */
    public function searchDataSources($regexp)
    {
        if (substr($regexp, 0, 1) !== '/') {
            $regexp = "/$regexp/";
        }
        $matches = '';
        foreach ($this->dataSourceSettings as $source => $settings) {
            foreach ($settings as $setting => $value) {
                foreach (is_array($value) ? $value : [$value] as $single) {
                    if (!is_string($single)) {
                        continue;
                    }
                    if (preg_match($regexp, "$setting=$single")) {
                        if ($matches) {
                            $matches .= ',';
                        }
                        $matches .= $source;
                        break 2;
                    }
                }
            }
        }
        echo "$matches\n";
    }

    /**
     * Creates a preview of the given metadata and returns it
     *
     * @param string $metadata The metadata to process
     * @param string $format   Metadata format
     * @param string $source   Source identifier
     *
     * @return array Solr record fields
     */
    public function previewRecord($metadata, $format, $source)
    {
        include_once 'PreviewCreator.php';
        $preview = new PreviewCreator(
            $this->db, $this->basePath, $this->log, $this->verbose
        );
        return $preview->preview($metadata, $format, $source);
    }

    /**
     * Execute a pretransformation on data before it is split into records and
     * loaded. Used when loading from a file.
     *
     * @param string $data The original data
     *
     * @return string Transformed data
     */
    protected function pretransform($data)
    {
        if (!isset($this->preXSLT)) {
            $style = new DOMDocument();
            $style->load(
                $this->basePath . '/transformations/' . $this->pretransformation
            );
            $this->preXSLT = new XSLTProcessor();
            $this->preXSLT->importStylesheet($style);
            $this->preXSLT->setParameter('', 'source_id', $this->sourceId);
            $this->preXSLT->setParameter('', 'institution', $this->institution);
            $this->preXSLT->setParameter('', 'format', $this->format);
            $this->preXSLT->setParameter('', 'id_prefix', $this->idPrefix);
        }
        $doc = new DOMDocument();
        $doc->loadXML($data, LIBXML_PARSEHUGE);
        return $this->preXSLT->transformToXml($doc);
    }

    /**
     * Load the data source settings and setup some functions
     *
     * @param string $source Source ID
     *
     * @throws Exception
     * @return void
     */
    protected function loadSourceSettings($source)
    {
        if (!isset($this->dataSourceSettings[$source])) {
            $this->log->log(
                'loadSourceSettings',
                "Settings not found for data source $source",
                Logger::FATAL
            );
            throw new Exception("Error: settings not found for $source\n");
        }
        $settings = $this->dataSourceSettings[$source];
        if (!isset($settings['institution'])) {
            $this->log->log(
                'loadSourceSettings',
                "institution not set for $source",
                Logger::FATAL
            );
            throw new Exception("Error: institution not set for $source\n");
        }
        if (!isset($settings['format'])) {
            $this->log->log(
                'loadSourceSettings', "format not set for $source", Logger::FATAL
            );
            throw new Exception("Error: format not set for $source\n");
        }
        $this->format = $settings['format'];
        $this->sourceId = $source;
        $this->idPrefix = isset($settings['idPrefix']) && $settings['idPrefix']
            ? $settings['idPrefix'] : $source;
        $this->institution = $settings['institution'];
        $this->recordXPath
            = isset($settings['recordXPath']) ? $settings['recordXPath'] : '';
        $this->oaiIDXPath
            = isset($settings['oaiIDXPath']) ? $settings['oaiIDXPath'] : '';
        $this->dedup = isset($settings['dedup']) ? $settings['dedup'] : false;
        $this->componentParts = isset($settings['componentParts'])
            && $settings['componentParts'] ? $settings['componentParts'] : 'as_is';
        $this->pretransformation = isset($settings['preTransformation'])
            ? $settings['preTransformation'] : '';
        $this->indexMergedParts = isset($settings['indexMergedParts'])
            ? $settings['indexMergedParts'] : true;
        $this->harvestType = isset($settings['type']) ? $settings['type'] : '';
        $this->nonInheritedFields = isset($settings['non_inherited_fields'])
            ? $settings['non_inherited_fields'] : [];
        $this->prependParentTitleWithUnitId
            = isset($settings['prepend_parent_title_with_unitid'])
            ? $settings['prepend_parent_title_with_unitid'] : true;

        $params = [
            'source_id' => $this->sourceId,
            'institution' => $this->institution,
            'format' => $this->format,
            'id_prefix' => $this->idPrefix
        ];
        $this->normalizationXSLT = isset($settings['normalization'])
            && $settings['normalization']
            ? new XslTransformation(
                $this->basePath . '/transformations',
                $settings['normalization'],
                $params
            ) : null;
        $this->solrTransformationXSLT = isset($settings['solrTransformation'])
            && $settings['solrTransformation']
            ? new XslTransformation(
                $this->basePath . '/transformations',
                $settings['solrTransformation'],
                $params
            ) : null;

        if (isset($settings['recordSplitter'])) {
            if (substr($settings['recordSplitter'], -4) == '.php') {
                $this->recordSplitter = $settings['recordSplitter'];
            } else {
                $style = new DOMDocument();
                $xslFile = $this->basePath . '/transformations/'
                    . $settings['recordSplitter'];
                if ($style->load($xslFile) === false) {
                    throw new Exception("Could not load $xslFile");
                }
                $this->recordSplitter = new XSLTProcessor();
                $this->recordSplitter->importStylesheet($style);
            }
        } else {
            $this->recordSplitter = null;
        }

        $this->keepMissingHierarchyMembers
            = isset($settings['keepMissingHierarchyMembers'])
            ? $settings['keepMissingHierarchyMembers']
            : false;
    }

    /**
     * Read a list file into an array
     *
     * @param string $filename List file name
     *
     * @return string[]
     */
    protected function readListFile($filename)
    {
        global $basePath;

        $filename = "$basePath/conf/$filename";
        $lines = file($filename, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            $this->log->log(
                'readListFile', "Could not open list file '$filename'", Logger::ERROR
            );
            return [];
        }
        array_walk(
            $lines,
            function (&$value) {
                $value = trim($value, "'");
            }
        );

        return $lines;
    }

    /**
     * Process a complete record set harvested e.g. from MetaLib
     *
     * @param string   $source           Source ID
     * @param string[] $harvestedRecords Array of records
     *
     * @return void
     */
    protected function processFullRecordSet(
        $source, $harvestedRecords
    ) {
        $this->log->log(
            'processFullRecordSet', "[$source] Processing complete record set"
        );
        // Create keyed array
        $records = [];
        foreach ($harvestedRecords as $record) {
            $marc = RecordFactory::createRecord('marc', $record, '', $source);
            $id = $marc->getID();
            $records["$source.$id"] = $record;
        }

        $this->log->log(
            'processFullRecordSet',
            "[$source] Merging results with the records in database"
        );
        $deleted = 0;
        $unchanged = 0;
        $changed = 0;
        $added = 0;
        $dbRecords = $this->db->record->find(
            ['deleted' => false, 'source_id' => $source],
            ['noCursorTimeout' => true]
        );
        foreach ($dbRecords as $dbRecord) {
            $id = $dbRecord['_id'];
            if (!isset($records[$id])) {
                // Record not in harvested records, mark deleted
                $this->storeRecord($id, true, '');
                unset($records[$id]);
                ++$deleted;
                continue;
            }
            // Check if the record has changed
            $marc = RecordFactory::createRecord('marc', $records[$id], '', $source);
            if ($marc->serialize() != MetadataUtils::getRecordData($dbRecord, false)
            ) {
                // Record changed, update...
                $this->storeRecord($id, false, $records[$id]);
                ++$changed;
            } else {
                ++$unchanged;
            }
            unset($records[$id]);
        }
        $this->log->log('processFullRecordSet', "[$source] Adding new records");
        foreach ($records as $id => $record) {
            $this->storeRecord($id, false, $record);
            ++$added;
        }
        $this->log->log(
            'processFullRecordSet',
            "[$source] $added new, $changed changed, $unchanged unchanged and "
            . "$deleted deleted records processed"
        );
    }
}

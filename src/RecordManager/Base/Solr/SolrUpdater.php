<?php
/**
 * SolrUpdater Class
 *
 * PHP version 7
 *
 * Copyright (C) The National Library of Finland 2012-2021.
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
namespace RecordManager\Base\Solr;

use RecordManager\Base\Database\DatabaseInterface as Database;
use RecordManager\Base\Enrichment\PluginManager as EnrichmentPluginManager;
use RecordManager\Base\Http\ClientManager as HttpClientManager;
use RecordManager\Base\Record\AbstractRecord;
use RecordManager\Base\Record\PluginManager as RecordPluginManager;
use RecordManager\Base\Settings\Ini;
use RecordManager\Base\Utils\FieldMapper;
use RecordManager\Base\Utils\Logger;
use RecordManager\Base\Utils\MetadataUtils;
use RecordManager\Base\Utils\PerformanceCounter;
use RecordManager\Base\Utils\WorkerPoolManager;

if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
} else {
    declare(ticks = 10);
}

/**
 * SolrUpdater Class
 *
 * This is a class for updating the Solr index.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class SolrUpdater
{
    use \RecordManager\Base\Record\CreateRecordTrait;
    use \RecordManager\Base\Utils\ParentProcessCheckTrait;

    /**
     * Database
     *
     * @var \RecordManager\Base\Database\AbstractDatabase
     */
    protected $db;

    /**
     * Logger
     *
     * @var Logger
     */
    protected $log;

    /**
     * Verbose mode
     *
     * @var bool
     */
    protected $verbose;

    /**
     * Main configuration
     *
     * @var array
     */
    protected $config;

    /**
     * Data source settings
     *
     * @var array
     */
    protected $settings;

    /**
     * Record plugin manager
     *
     * @var RecordPluginManager
     */
    protected $recordPluginManager;

    /**
     * Enrichment plugin manager
     *
     * @var EnrichmentPluginManager
     */
    protected $enrichmentPluginManager;

    /**
     * HTTP client manager
     *
     * @var HttpClientManager
     */
    protected $httpClientManager;

    /**
     * Metadata utilities
     *
     * @var MetadataUtils
     */
    protected $metadataUtils;

    /**
     * Whether building field is hierarchical
     *
     * @var bool
     */
    protected $buildingHierarchy;

    /**
     * Formats that denote journals
     *
     * @var array
     */
    protected $journalFormats;

    /**
     * Formats that denote ejournals
     *
     * @var array
     */
    protected $eJournalFormats;

    /**
     * Formats that denote journals and ejournals
     *
     * @var array
     */
    protected $allJournalFormats;

    /**
     * Termination flag
     *
     * @var bool
     */
    protected $terminate;

    /**
     * File name prefix when dumping records
     *
     * @var string
     */
    protected $dumpPrefix = '';

    /**
     * Commit interval (number of record updates before a forced commit)
     *
     * @var int
     */
    protected $commitInterval;

    /**
     * Maximum number of records in a single update batch
     *
     * @var int
     */
    protected $maxUpdateRecords;

    /**
     * Maximum size of a single update batch in bytes
     *
     * @var int
     */
    protected $maxUpdateSize;

    /**
     * Maximum number attempts to send the update request to Solr
     *
     * @var int
     */
    protected $maxUpdateTries;

    /**
     * Seconds to wait between any Solr request retries
     *
     * @var int
     */
    protected $updateRetryWait;

    /**
     * Whether to run merged record update in parallel with single records
     *
     * @var bool
     */
    protected $threadedMergedRecordUpdate;

    /**
     * Solr Update Buffer
     *
     * @var string
     */
    protected $buffer;

    /**
     * Characters in the Buffer
     *
     * @var int
     */
    protected $bufferLen;

    /**
     * Count of Records in the Buffer
     *
     * @var int
     */
    protected $buffered;

    /**
     * Deletion Buffer
     *
     * @var string[]
     */
    protected $bufferedDeletions;

    /**
     * HTTP Client
     *
     * @var \HTTP_Request2
     */
    protected $request = null;

    /**
     * Fields to merge when merging deduplicated records
     *
     * @var array
     */
    protected $mergedFields = [
        'institution', 'collection', 'building', 'language', 'physical', 'publisher',
        'publishDate', 'contents', 'edition', 'description', 'url',
        'ctrlnum', 'oclc_num',
        'callnumber-raw', 'callnumber-search',
        'dewey-hundreds', 'dewey-tens', 'dewey-ones', 'dewey-full', 'dewey-raw',
        'dewey-search',
        'author', 'author_variant', 'author_role', 'author_fuller', 'author_sort',
        'author2', 'author2_variant', 'author2_role', 'author2_fuller',
        'author_corporate', 'author_corporate_role', 'author_additional',
        'title_alt', 'title_old', 'title_new', 'dateSpan', 'series', 'series2',
        'topic', 'genre', 'geographic', 'era',
        'long_lat', 'long_lat_display', 'long_lat_label',
        'isbn', 'issn',
    ];

    /**
     * Fields to use only once if not already set when merging deduplicated records
     *
     * @var array
     */
    protected $singleFields = [
        'title', 'title_short', 'title_full', 'title_sort',
        'author_sort',
        'format',
        'thumbnail',
        'description', 'fulltext',
        'publishDateSort',
        'callnumber-first', 'callnumber-subject', 'callnumber-label',
        'callnumber-sort',
        'lccn',
        'dewey-sort',
        'illustrated',
        'first_indexed', 'last-indexed',
        'container_title', 'container_volume', 'container_issue',
        'container_start_page', 'container_reference',
    ];

    /**
     * Fields to copy back from the merged record to all the member records
     *
     * @var array
     */
    protected $copyFromMergedRecord = [];

    /**
     * Fields to copy from a parent record to all child records
     *
     * @var array
     */
    protected $copyFromParentRecord = [];

    /**
     * Fields that are analyzed when scoring records for merging order
     */
    protected $scoredFields = [
        'title', 'author', 'author2', 'author_corporate', 'topic', 'contents',
        'series', 'genre', 'era', 'allfields', 'publisher'
    ];

    /**
     * Fields handled as containing building data
     *
     * @var array
     */
    protected $buildingFields = [
        'building'
    ];

    /**
     * Field used for warnings about metadata
     *
     * @var string
     */
    protected $warningsField = '';

    /**
     * Available enrichments
     *
     * @var array
     */
    protected $enrichments = [];

    /**
     * Data sources that are completely ignored when updating the Solr index
     *
     * @var array
     */
    protected $nonIndexedSources = [];

    /**
     * SolrCloud cluster state check interval
     *
     * @var int
     */
    protected $clusterStateCheckInterval = 0;

    /**
     * Time of last SolrCloud cluster state check
     *
     * @var int
     */
    protected $lastClusterStateCheck = 0;

    /**
     * SolrCloud cluster state
     *
     * @var string
     */
    protected $clusterState = 'ok';

    /**
     * Hierarchical facets
     *
     * @var array
     */
    protected $hierarchicalFacets = [];

    /**
     * How many record worker processes to use
     *
     * @var int
     */
    protected $recordWorkers;

    /**
     * How many Solr update worker processes to use
     *
     * @var int
     */
    protected $solrUpdateWorkers;

    /**
     * Worker pool manager
     *
     * @var WorkerPoolManager
     */
    protected $workerPoolManager = null;

    /**
     * Field mapper
     *
     * @var FieldMapper
     */
    protected $fieldMapper = null;

    /**
     * Whether to disable field mappings
     *
     * @var bool
     */
    protected $disableMappings = false;

    /**
     * Whether to track last update date per server's update url
     *
     * @var bool
     */
    protected $datePerServer;

    /**
     * UNICODE normalization form
     *
     * @var string
     */
    protected $unicodeNormalizationForm;

    /**
     * Shard statuses considered normal in cluster state check
     *
     * @var array
     */
    protected $normalShardStatuses = ['active', 'inactive', 'construction'];

    /**
     * Solr field for dedup id
     *
     * @var string
     */
    protected $dedupIdField = 'dedup_id_str_mv';

    /**
     * Solr field for container title
     *
     * @var string
     */
    protected $containerTitleField = 'container_title';

    /**
     * Solr field for container volume
     *
     * @var string
     */
    protected $containerVolumeField = 'container_volume';

    /**
     * Solr field for container issue
     *
     * @var string
     */
    protected $containerIssueField = 'container_issue';

    /**
     * Solr field for container start page
     *
     * @var string
     */
    protected $containerStartPageField = 'container_start_page';

    /**
     * Solr field for container reference
     *
     * @var string
     */
    protected $containerReferenceField = 'container_reference';

    /**
     * Solr field for "is hierarchy id"
     *
     * @var string
     */
    protected $isHierarchyIdField = 'is_hierarchy_id';

    /**
     * Solr field for "is hierarchy title"
     *
     * @var string
     */
    protected $isHierarchyTitleField = 'is_hierarchy_title';

    /**
     * Solr field for hierarchy top id
     *
     * @var string
     */
    protected $hierarchyTopIdField = 'hierarchy_top_id';

    /**
     * Solr field for hierarchy parent id
     *
     * @var string
     */
    protected $hierarchyParentIdField = 'hierarchy_parent_id';

    /**
     * Solr field for hierarchy parent title
     *
     * @var string
     */
    protected $hierarchyParentTitleField = 'hierarchy_parent_title';

    /**
     * Solr field for work identification keys
     *
     * @var string
     */
    protected $workKeysField = 'work_keys_str_mv';

    /**
     * Maximum field lengths. Key is a regular expression. __default__ is applied
     * unless overridden. 0 as the value means the field length is unlimited.
     *
     * @var array
     */
    protected $maxFieldLengths = [];

    /**
     * Cache for recent metadata records
     *
     * @var \cash\LRUCache
     */
    protected $metadataRecordCache;

    /**
     * Cache for recent record data
     *
     * @var \cash\LRUCache
     */
    protected $recordDataCache;

    /**
     * Configuration reader
     *
     * @var Ini
     */
    protected $configReader;

    /**
     * Constructor
     *
     * @param array                   $config           Main configuration
     * @param array                   $dataSourceConfig Data source settings
     * @param Database                $db               Database connection
     * @param object                  $log              Logger
     * @param RecordPluginManager     $recordPM         Record plugin manager
     * @param EnrichmentPluginManager $enrichmentPM     Enrichment plugin manager
     * @param HttpClientManager       $httpManager      HTTP client manager
     * @param Ini                     $configReader     Configuration reader
     * @param FieldMapper             $fieldMapper      Field mapper
     * @param MetadataUtils           $metadataUtils    Metadata utilities
     *
     * @throws \Exception
     */
    public function __construct(
        array $config,
        array $dataSourceConfig,
        ?Database $db,
        Logger $log,
        RecordPluginManager $recordPM,
        EnrichmentPluginManager $enrichmentPM,
        HttpClientManager $httpManager,
        Ini $configReader,
        FieldMapper $fieldMapper,
        MetadataUtils $metadataUtils
    ) {
        $this->config = $config;
        $this->db = $db;
        $this->log = $log;
        $this->verbose = $config['Log']['verbose'] ?? false;
        $this->recordPluginManager = $recordPM;
        $this->enrichmentPluginManager = $enrichmentPM;
        $this->httpClientManager = $httpManager;
        $this->configReader = $configReader;
        $this->fieldMapper = $fieldMapper;
        $this->metadataUtils = $metadataUtils;

        $this->metadataRecordCache = new \cash\LRUCache(100);
        $this->recordDataCache = new \cash\LRUCache(100);

        $this->journalFormats = $config['Solr']['journal_formats']
            ?? ['Journal', 'Serial', 'Newspaper'];

        $this->eJournalFormats = isset($config['Solr']['ejournal_formats'])
            ? $config['Solr']['journal_formats']
            : ['eJournal'];

        $this->allJournalFormats
            = array_merge($this->journalFormats, $this->eJournalFormats);

        if (isset($config['Solr']['hierarchical_facets'])) {
            $this->hierarchicalFacets = $config['Solr']['hierarchical_facets'];
        }
        // Special case: building hierarchy
        $this->buildingHierarchy = in_array('building', $this->hierarchicalFacets);

        if (isset($config['Solr']['merged_fields'])) {
            $this->mergedFields = explode(',', $config['Solr']['merged_fields']);
        }
        $this->mergedFields = array_flip($this->mergedFields);

        if (isset($config['Solr']['copy_from_merged_record'])) {
            $this->copyFromMergedRecord
                = explode(',', $config['Solr']['copy_from_merged_record']);
        }

        if (isset($config['Solr']['copy_from_parent_record'])) {
            $this->copyFromParentRecord
                = explode(',', $config['Solr']['copy_from_parent_record']);
        }

        if (isset($config['Solr']['single_fields'])) {
            $this->singleFields = explode(',', $config['Solr']['single_fields']);
        }
        $this->singleFields = array_flip($this->singleFields);

        if (isset($config['Solr']['scored_fields'])) {
            $this->scoredFields = explode(',', $config['Solr']['scored_fields']);
        }
        $this->scoredFields = array_flip($this->scoredFields);

        if (isset($config['Solr']['building_fields'])) {
            $this->buildingFields = explode(',', $config['Solr']['building_fields']);
        }

        if (isset($config['Solr']['warnings_field'])) {
            $this->warningsField = $config['Solr']['warnings_field'];
        }

        $this->commitInterval = $config['Solr']['max_commit_interval'] ?? 50000;
        $this->maxUpdateRecords = $config['Solr']['max_update_records'] ?? 5000;
        $this->maxUpdateSize = $config['Solr']['max_update_size'] ?? 1024;
        $this->maxUpdateSize *= 1024;
        $this->maxUpdateTries = $config['Solr']['max_update_tries'] ?? 15;
        $this->updateRetryWait = $config['Solr']['update_retry_wait'] ?? 60;
        $this->recordWorkers = $config['Solr']['record_workers'] ?? 0;
        $this->solrUpdateWorkers = $config['Solr']['solr_update_workers'] ?? 0;
        $this->threadedMergedRecordUpdate
            = $config['Solr']['threaded_merged_record_update'] ?? false;
        $this->clusterStateCheckInterval
            = $config['Solr']['cluster_state_check_interval'] ?? 0;
        if (empty($config['Solr']['admin_url'])) {
            $this->clusterStateCheckInterval = 0;
            $this->log->logWarning(
                'SolrUpdater',
                'admin_url not defined, cluster state check disabled'
            );
        }
        $this->datePerServer
            = !empty($config['Solr']['track_updates_per_update_url']);

        $this->unicodeNormalizationForm
            = $config['Solr']['unicode_normalization_form'] ?? '';

        $fields = $config['Solr Fields'] ?? [];

        if (isset($fields['dedup_id'])) {
            $this->dedupIdField = $fields['dedup_id'];
        }
        if (isset($fields['container_title'])) {
            $this->containerTitleField = $fields['container_title'];
        }
        if (isset($fields['container_volume'])) {
            $this->containerVolumeField = $fields['container_volume'];
        }
        if (isset($fields['container_issue'])) {
            $this->containerIssueField = $fields['container_issue'];
        }
        if (isset($fields['container_start_page'])) {
            $this->containerStartPageField = $fields['container_start_page'];
        }
        if (isset($fields['container_reference'])) {
            $this->containerReferenceField = $fields['container_reference'];
        }
        if (isset($fields['is_hierarchy_id'])) {
            $this->isHierarchyIdField = $fields['is_hierarchy_id'];
        }
        if (isset($fields['is_hierarchy_title'])) {
            $this->isHierarchyTitleField = $fields['is_hierarchy_title'];
        }
        if (isset($fields['hierarchy_top_id'])) {
            $this->hierarchyTopIdField = $fields['hierarchy_top_id'];
        }
        if (isset($fields['hierarchy_parent_id'])) {
            $this->hierarchyParentIdField = $fields['hierarchy_parent_id'];
        }
        if (isset($fields['hierarchy_parent_title'])) {
            $this->hierarchyParentTitleField = $fields['hierarchy_parent_title'];
        }
        if (isset($fields['work_keys'])) {
            $this->workKeysField = $fields['work_keys'];
        }
        if (isset($config['Solr Field Limits'])) {
            $this->maxFieldLengths = $config['Solr Field Limits'];
        }

        // Load settings
        $this->initDatasources($dataSourceConfig);
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
        echo getmypid() . " Termination requested\n";
        $this->terminate = true;
    }

    /**
     * Initialize worker pool manager
     *
     * @return void
     */
    protected function initWorkerPoolManager()
    {
        if (null === $this->workerPoolManager) {
            $this->workerPoolManager = new WorkerPoolManager();
        }
    }

    /**
     * Deinitialize worker pool manager
     *
     * @return void
     */
    protected function deInitWorkerPoolManager()
    {
        if (null !== $this->workerPoolManager) {
            $this->workerPoolManager->destroyWorkerPools();
            unset($this->workerPoolManager);
            $this->workerPoolManager = null;
        }
    }

    /**
     * Update Solr index (merged records and individual records)
     *
     * @param string|null $fromDate      Starting date for updates (if null, last
     *                                   update date stored in the database is used
     *                                   and if an empty string, all records are
     *                                   processed)
     * @param string      $sourceId      Comma-separated list of source IDs to
     *                                   update, or empty or * for all sources
     * @param string      $singleId      Process only the record with the given ID
     * @param bool        $noCommit      If true, changes are not explicitly
     *                                   committed
     * @param bool        $delete        If true, records in the given $sourceId are
     *                                   all deleted
     * @param string      $dumpPrefix    If specified, the Solr records are dumped
     *                                   into files and not sent to Solr
     * @param bool        $datePerServer Track last Solr update date per server url
     *
     * @return void
     */
    public function updateRecords(
        $fromDate = null,
        $sourceId = '',
        $singleId = '',
        $noCommit = false,
        $delete = false,
        $dumpPrefix = '',
        $datePerServer = false
    ) {
        if ('*' === $sourceId) {
            $sourceId = '';
        }
        // Install a signal handler so that we can exit cleanly if interrupted
        unset($this->terminate);
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, [$this, 'sigIntHandler']);
            pcntl_signal(SIGTERM, [$this, 'sigIntHandler']);
            $this->log
                ->logInfo('updateRecords', 'Interrupt handler set');
        } else {
            $this->log->logInfo(
                'updateRecords',
                'Could not set an interrupt handler -- pcntl not available'
            );
        }

        $lastUpdateKey = 'Last Index Update';
        if ($datePerServer || $this->datePerServer) {
            $lastUpdateKey .= ' ' . $this->config['Solr']['update_url'];
        }

        $this->dumpPrefix = $dumpPrefix;

        $verb = $this->dumpPrefix ? 'dumped' : 'indexed';
        $initVerb = $this->dumpPrefix ? 'Dumping' : 'Indexing';

        $childPid = null;
        $fromTimestamp = null;
        try {
            if ($this->recordWorkers) {
                $this->log->logInfo(
                    'updateRecords',
                    "Using {$this->recordWorkers} record workers"
                );
            }
            if ($this->solrUpdateWorkers) {
                $this->log->logInfo(
                    'updateRecords',
                    "Using {$this->solrUpdateWorkers} Solr workers"
                );
            }

            $needCommit = false;
            // Take the last indexing date now and store it when done
            if (!$sourceId && !$singleId && null === $fromDate) {
                $lastIndexingDate = time();
            } else {
                $lastIndexingDate = null;
            }

            // Only process merged records if any of the selected sources has
            // deduplication enabled
            $processDedupRecords = true;
            if ($sourceId) {
                $sources = explode(',', $sourceId);
                foreach ($sources as $source) {
                    if (strncmp($source, '-', 1) === 0
                        || '' === trim($source)
                    ) {
                        continue;
                    }
                    $processDedupRecords = false;
                    if (isset($this->settings[$source]['dedup'])
                        && $this->settings[$source]['dedup']
                    ) {
                        $processDedupRecords = true;
                        break;
                    }
                }
            }

            if (!$this->threadedMergedRecordUpdate) {
                // Create worker pools before merged records are processed to avoid
                // sharing the database connection between processes
                $this->initSingleRecordWorkerPools();
            }

            if ($processDedupRecords) {
                if (!$delete && $this->threadedMergedRecordUpdate) {
                    $this->log->logInfo(
                        'updateRecords',
                        'Running merged and individual record processing in'
                        . ' parallel'
                    );
                    $childPid = pcntl_fork();
                    if ($childPid == -1) {
                        throw new \Exception(
                            "Could not fork merged record background update child"
                        );
                    }
                }

                if (!$childPid) {
                    $this->initWorkerPoolManager();
                    try {
                        $needCommit = $this->processMerged(
                            $fromDate,
                            $sourceId,
                            $singleId,
                            $noCommit,
                            $delete,
                            null !== $childPid,
                            $lastUpdateKey
                        );
                        if (null !== $childPid) {
                            $this->deInitWorkerPoolManager();
                        }

                        if (null !== $childPid) {
                            exit($needCommit ? 1 : 0);
                        }
                    } catch (\Exception $e) {
                        $this->log->logError(
                            'updateRecords',
                            'Exception from merged record processing: '
                                . $e->getMessage() . ' at ' . $e->getFile() . ':'
                                . $e->getLine()
                        );
                        if (null === $childPid) {
                            throw $e;
                        }
                        if (null !== $childPid) {
                            $this->deInitWorkerPoolManager();
                        }
                        exit(2);
                    }
                }
            }

            if ($delete) {
                return;
            }

            if ($this->threadedMergedRecordUpdate) {
                // Create worker pools only after merged record forked process has
                // been started to avoid sharing the worker pool manager
                $this->initSingleRecordWorkerPools();
            }

            $fromTimestamp = $this->getStartTimestamp($fromDate, $lastUpdateKey);
            $from = null !== $fromTimestamp
                ? gmdate('Y-m-d H:i:s\Z', $fromTimestamp) : 'the beginning';

            $this->log->logInfo(
                'updateRecords',
                "Creating individual record list (from $from)"
            );
            $params = [];
            if ($singleId) {
                $params['_id'] = $singleId;
                $params['dedup_id'] = ['$exists' => false];
                $lastIndexingDate = null;
            } else {
                if (null !== $fromTimestamp) {
                    $params['updated']
                        = ['$gte' => $this->db->getTimestamp($fromTimestamp)];
                }
                [$sourceOr, $sourceNor] = $this->createSourceFilter($sourceId);
                if ($sourceOr) {
                    $params['$or'] = $sourceOr;
                }
                if ($sourceNor) {
                    $params['$nor'] = $sourceNor;
                }
                $params['dedup_id'] = ['$exists' => false];
            }
            $total = $this->db->countRecords($params);
            $count = 0;
            $lastDisplayedCount = 0;
            $mergedComponents = 0;
            $deleted = 0;
            if ($noCommit) {
                $this->log->logInfo(
                    'updateRecords',
                    "$initVerb $total individual records (with no forced commits)"
                );
            } else {
                $this->log->logInfo(
                    'updateRecords',
                    "$initVerb $total individual records (max commit interval "
                        . "{$this->commitInterval} records)"
                );
            }
            $pc = new PerformanceCounter();
            $this->initBufferedUpdate();
            $this->db->iterateRecords(
                $params,
                [],
                function ($record) use (
                    $childPid,
                    $pc,
                    &$mergedComponents,
                    &$count,
                    &$deleted,
                    $verb,
                    $noCommit,
                    &$lastDisplayedCount,
                    &$needCommit
                ) {
                    if (isset($this->terminate)) {
                        return false;
                    }
                    if (in_array($record['source_id'], $this->nonIndexedSources)) {
                        return true;
                    }

                    $this->workerPoolManager->addRequest('record', $record);

                    while ($this->workerPoolManager->checkForResults('record')) {
                        $result = $this->workerPoolManager->getResult('record');
                        $mergedComponents += $result['mergedComponents'];
                        foreach ($result['deleted'] as $id) {
                            ++$deleted;
                            $this->bufferedDelete($id);
                        }
                        foreach ($result['records'] as $record) {
                            ++$count;
                            $this->bufferedUpdate($record, $count, $noCommit);
                        }
                    }
                    if ($count + $deleted >= $lastDisplayedCount + 1000) {
                        $lastDisplayedCount = $count + $deleted;
                        $pc->add($lastDisplayedCount);
                        $avg = $pc->getSpeed();
                        $this->log->logInfo(
                            'updateRecords',
                            "$count individual, $deleted deleted and"
                                . " $mergedComponents included child records $verb"
                                . ", $avg records/sec"
                        );
                    }

                    // Check child status
                    if ($childPid) {
                        $pid = pcntl_waitpid($childPid, $status, WNOHANG);
                        if (0 !== $pid) {
                            $exitCode = $pid > 0 ? pcntl_wexitstatus($status) : null;
                            $childPid = null;
                            if ($exitCode == 1) {
                                $needCommit = true;
                            } elseif ($exitCode) {
                                $this->log->logError(
                                    'updateRecords',
                                    'Merged record update process failed, aborting'
                                );
                                throw new \Exception(
                                    'Merged record update process failed'
                                );
                            }
                        }
                    }
                }
            );

            if (isset($this->terminate)) {
                if ($childPid) {
                    $this->log->logInfo(
                        'updateRecords',
                        'Waiting for child process to terminate...'
                    );
                    while (1) {
                        $pid = pcntl_waitpid($childPid, $status, WNOHANG);
                        if ($pid !== 0) {
                            break;
                        }
                        sleep(1);
                    }
                }
                $this->log->logInfo(
                    'updateRecords',
                    'Termination upon request (individual record handler)'
                );
                exit(1);
            }

            while ($this->workerPoolManager->requestsPending('record')
                || $this->workerPoolManager->checkForResults('record')
            ) {
                while ($this->workerPoolManager->checkForResults('record')) {
                    $result = $this->workerPoolManager->getResult('record');
                    $mergedComponents += $result['mergedComponents'];
                    foreach ($result['deleted'] as $id) {
                        ++$deleted;
                        $this->bufferedDelete($id);
                    }
                    foreach ($result['records'] as $record) {
                        ++$count;
                        $this->bufferedUpdate($record, $count, $noCommit);
                    }
                }
                usleep(10);
            }

            // Flush update buffer and wait for any subsequent pending Solr updates
            // to complete.
            $this->flushUpdateBuffer();

            $this->log->logInfo(
                'updateRecords',
                'Waiting for any pending requests to complete...'
            );
            $this->workerPoolManager->waitUntilDone('solr');
            $this->log->logInfo(
                'updateRecords',
                'All requests complete'
            );

            if ($count > 0) {
                $needCommit = true;
            }
            if (isset($lastIndexingDate)) {
                $state = [
                    '_id' => $lastUpdateKey,
                    'value' => $lastIndexingDate
                ];
                $this->db->saveState($state);
            }

            $this->log->logInfo(
                'updateRecords',
                "Total $count individual, $deleted deleted and"
                    . " $mergedComponents included child records $verb"
            );

            if ($childPid) {
                // Wait for child to finish
                while (1) {
                    $pid = pcntl_waitpid($childPid, $status, WNOHANG);
                    if ($pid > 0) {
                        if (pcntl_wexitstatus($status) == 1) {
                            $needCommit = true;
                        }
                        break;
                    } elseif ($pid < 0) {
                        $exitCode = $this->workerPoolManager
                            ->getExternalProcessExitCode($childPid);
                        if (null !== $exitCode) {
                            if (1 === $exitCode) {
                                $needCommit = true;
                            } elseif ($exitCode) {
                                $this->log->logError(
                                    'updateRecords',
                                    'Merged record update process failed, aborting'
                                );
                                throw new \Exception(
                                    'Merged record update process failed'
                                );
                            }
                        } else {
                            $this->log->logError(
                                'updateRecords',
                                'Could not get merged record handler results'
                            );
                            $needCommit = true;
                        }
                        break;
                    }
                    sleep(1);
                }
            }

            if (isset($lastIndexingDate)) {
                $state = [
                    '_id' => $lastUpdateKey,
                    'value' => $lastIndexingDate
                ];
                $this->db->saveState($state);
            }

            if (!$noCommit && $needCommit && !$this->dumpPrefix) {
                $this->log->logInfo('updateRecords', 'Final commit...');
                $this->solrRequest('{ "commit": {} }', 3600);
                $this->log->logInfo('updateRecords', 'Commit complete');
            }
        } catch (\Exception $e) {
            $this->log->logFatal(
                'updateRecords',
                'Exception: ' . $e->getMessage() . ' at ' . $e->getFile() . ':'
                    . $e->getLine()
            );
            if ($childPid) {
                // Kill the child process too
                posix_kill($childPid, SIGINT);
                // Wait for child to finish
                while (1) {
                    $pid = pcntl_waitpid($childPid, $status, WNOHANG);
                    if (0 != $pid) {
                        break;
                    }
                    usleep(1000);
                }
            }

            if ($this->threadedMergedRecordUpdate && !$childPid) {
                exit(2);
            } else {
                $this->deInitWorkerPoolManager();
            }
        }
        $this->deInitWorkerPoolManager();
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, SIG_DFL);
            pcntl_signal(SIGTERM, SIG_DFL);
        }
    }

    /**
     * Toggle field mappings
     *
     * @param bool $disable Whether to disable mappings
     *
     * @return void
     */
    public function disableFieldMappings(bool $disable): void
    {
        $this->disableMappings = $disable;
    }

    /**
     * Process merged (deduplicated) records
     *
     * @param string|null $fromDate      Start date
     * @param string      $sourceId      Comma-separated list of source IDs to
     *                                   update, or empty or * for all sources
     * @param string      $singleId      Process only the record with the given ID
     * @param bool        $noCommit      If true, changes are not explicitly
     *                                   committed
     * @param bool        $delete        If true, records in the given $sourceId are
     *                                   all deleted
     * @param bool        $checkParent   Whether to check that a parent process is
     *                                   alive
     * @param string      $lastUpdateKey Database state key for last index update
     *
     * @throws \Exception
     * @return boolean Whether anything was updated
     */
    protected function processMerged(
        $fromDate,
        $sourceId,
        $singleId,
        $noCommit,
        $delete,
        $checkParent,
        $lastUpdateKey
    ) {
        // Create workers first before we need the database
        $this->workerPoolManager->createWorkerPool(
            'solr',
            $this->solrUpdateWorkers,
            $this->solrUpdateWorkers,
            [$this, 'solrRequest']
        );
        $this->workerPoolManager->createWorkerPool(
            'merge',
            $this->recordWorkers,
            $this->recordWorkers,
            [$this, 'processDedupRecord']
        );

        $verb = $this->dumpPrefix ? 'dumped' : 'indexed';
        $initVerb = $this->dumpPrefix ? 'Dumping' : 'Indexing';

        $count = 0;
        $lastDisplayedCount = 0;
        $mergedComponents = 0;
        $deleted = 0;
        $this->initBufferedUpdate();
        if ($noCommit) {
            $this->log->logInfo(
                'processMerged',
                "$initVerb the merged records (with no forced commits)"
            );
        } else {
            $this->log->logInfo(
                'processMerged',
                "$initVerb the merged records (max commit interval "
                . "{$this->commitInterval} records)"
            );
        }
        $pc = new PerformanceCounter();
        $this->iterateMergedRecords(
            $fromDate,
            $sourceId,
            $singleId,
            $lastUpdateKey,
            $checkParent,
            function (string $mergeId) use (
                $sourceId,
                $delete,
                &$mergedComponents,
                &$deleted,
                &$count,
                $noCommit,
                &$lastDisplayedCount,
                $pc,
                $verb
            ) {
                $this->workerPoolManager->addRequest(
                    'merge',
                    $mergeId,
                    $sourceId,
                    $delete
                );

                while (!isset($this->terminate)
                    && $this->workerPoolManager->checkForResults('merge')
                ) {
                    $result = $this->workerPoolManager->getResult('merge');
                    $mergedComponents += $result['mergedComponents'];
                    foreach ($result['deleted'] as $id) {
                        ++$deleted;
                        ++$count;
                        $this->bufferedDelete($id);
                    }
                    foreach ($result['records'] as $record) {
                        ++$count;
                        $this->bufferedUpdate($record, $count, $noCommit);
                    }
                }
                if ($count + $deleted >= $lastDisplayedCount + 1000) {
                    $lastDisplayedCount = $count + $deleted;
                    $pc->add($lastDisplayedCount);
                    $avg = $pc->getSpeed();
                    $this->log->logInfo(
                        'processMerged',
                        "$count merged, $deleted deleted and $mergedComponents"
                            . " included child records $verb, $avg records/sec"
                    );
                }
            }
        );

        while (!isset($this->terminate)
            && ($this->workerPoolManager->requestsPending('merge')
            || $this->workerPoolManager->checkForResults('merge'))
        ) {
            while (!isset($this->terminate)
                && $this->workerPoolManager->checkForResults('merge')
            ) {
                $result = $this->workerPoolManager->getResult('merge');
                $mergedComponents += $result['mergedComponents'];
                foreach ($result['deleted'] as $id) {
                    ++$deleted;
                    ++$count;
                    $this->bufferedDelete($id);
                }
                foreach ($result['records'] as $record) {
                    ++$count;
                    $this->bufferedUpdate($record, $count, $noCommit);
                }
            }
            usleep(1000);
        }

        // Flush update buffer and wait for any subsequent pending Solr updates
        // to complete.
        $this->flushUpdateBuffer();

        $this->log->logInfo(
            'processMerged',
            'Waiting for any pending requests to complete...'
        );
        $this->workerPoolManager->waitUntilDone('solr');
        $this->log->logInfo(
            'processMerged',
            'All requests complete'
        );

        $this->log->logInfo(
            'processMerged',
            "Total $count merged, $deleted deleted and $mergedComponents"
                . " included child records $verb"
        );

        return $count > 0;
    }

    /**
     * Process a dedup record and return results
     *
     * @param string $dedupId  Dedup record id
     * @param string $sourceId Source id, if any
     * @param bool   $delete   Whether a data source deletion is in progress
     *
     * @return array
     */
    public function processDedupRecord($dedupId, $sourceId, $delete)
    {
        $result = [
            'deleted' => [],
            'records' => [],
            'mergedComponents' => 0
        ];
        $dedupRecord = $this->db->getDedup($dedupId);
        if (empty($dedupRecord)) {
            $this->log->logError(
                'processDedupRecord',
                "Dedup record with id $dedupId missing"
            );
            return $result;
        }
        if ($dedupRecord['deleted']) {
            $result['deleted'][] = $dedupRecord['_id'];
            return $result;
        }

        $merged = [];
        $members = [];
        $this->db->iterateRecords(
            ['_id' => ['$in' => (array)$dedupRecord['ids']]],
            [],
            function ($record) use (
                $sourceId,
                $delete,
                &$mergedComponents,
                $dedupRecord,
                &$result,
                &$members
            ) {
                if (in_array($record['source_id'], $this->nonIndexedSources)) {
                    return true;
                }
                if ($record['deleted'] || ($record['suppressed'] ?? false)
                    || ($sourceId && $delete && $record['source_id'] == $sourceId)
                ) {
                    $result['deleted'][] = $record['_id'];
                    return true;
                }
                $data = $this
                    ->createSolrArray($record, $mergedComponents, $dedupRecord);
                if ($data === false) {
                    return true;
                }
                $result['mergedComponents'] += $mergedComponents;
                $members[] = ['database' => $record, 'solr' => $data];
            }
        );

        $merged = $this->mergeRecords($members);
        $this->copyMergedDataToMembers($merged, $members);

        if (empty($members)) {
            $this->log->logInfo(
                'processMerged',
                "Found no records with dedup id: $dedupId, ids: "
                    . implode(',', (array)$dedupRecord['ids'])
            );
            $result['deleted'][] = $dedupRecord['_id'];
        } elseif (count($members) == 1) {
            // A dedup key exists for a single record. This should only happen
            // when a data source is being deleted...
            $member = $members[0];
            if (!$delete) {
                $this->log->logWarning(
                    'processMerged',
                    'Found a single record with a dedup id: '
                        . $member['solr']['id']
                );
            }
            if ($this->verbose) {
                echo 'Original deduplicated but single record '
                    . $member['solr']['id'] . ":\n";
                print_r($member['solr']);
            }

            $result['records'][] = $member['solr'];
            $result['deleted'][] = $dedupRecord['_id'];
        } else {
            foreach ($members as $member) {
                $member['solr']['merged_child_boolean'] = true;

                if ($this->verbose) {
                    echo 'Original deduplicated record '
                        . $member['solr']['id'] . ":\n";
                    $this->prettyPrint($member['solr']);
                }

                $result['records'][] = $member['solr'];
            }

            // Remove duplicate fields from the merged record
            foreach (array_keys($merged) as $fieldkey) {
                if ($fieldkey == 'author=author2') {
                    $fieldkey = 'author2';
                }
                if (substr($fieldkey, -3, 3) == '_mv'
                    || isset($this->mergedFields[$fieldkey])
                ) {
                    // For hierarchical fields we need to store all combinations
                    // of character cases
                    if (in_array($fieldkey, $this->hierarchicalFacets)) {
                        $merged[$fieldkey] = array_values(
                            array_unique($merged[$fieldkey])
                        );
                    } else {
                        $merged[$fieldkey] = array_values(
                            $this->metadataUtils->array_iunique($merged[$fieldkey])
                        );
                    }
                }
            }
            if (isset($merged['allfields'])) {
                $merged['allfields'] = array_values(
                    $this->metadataUtils->array_iunique($merged['allfields'])
                );
            } else {
                $this->log->logWarning(
                    'processMerged',
                    "allfields missing in merged record for dedup key $dedupId"
                );
            }

            $mergedId = (string)$dedupId;
            if (empty($merged)) {
                $result['deleted'][] = $mergedId;
            } else {
                $merged['id'] = $mergedId;
                $merged['record_format'] = 'merged';
                $merged['merged_boolean'] = true;

                if ($this->verbose) {
                    echo "Merged record {$merged['id']}:\n";
                    $this->prettyPrint($merged);
                }

                $result['records'][] = $merged;
            }
        }
        return $result;
    }

    /**
     * Process a single record and return results
     *
     * @param array $record Record
     *
     * @return array
     */
    public function processSingleRecord(array $record): array
    {
        $result = [
            'deleted' => [],
            'records' => [],
            'mergedComponents' => 0
        ];

        if ($record['deleted'] || ($record['suppressed'] ?? false)) {
            $result['deleted'][] = (string)$record['_id'];
        } else {
            $mergedComponents = 0;
            $data = $this->createSolrArray($record, $mergedComponents);
            if ($data !== false) {
                if ($this->verbose) {
                    echo "Metadata for record {$record['_id']}: \n";
                    $this->prettyPrint($data);
                }

                $result['records'][] = $data;
                $result['mergedComponents'] = $mergedComponents;
            }
        }
        return $result;
    }

    /**
     * Delete all records belonging to the given source from the index
     *
     * @param string $sourceId Source ID
     *
     * @return void
     */
    public function deleteDataSource($sourceId)
    {
        $this->solrRequest('{ "delete": { "query": "id:' . $sourceId . '.*" } }');
        $this->solrRequest('{ "commit": {} }', 4 * 60 * 60);
    }

    /**
     * Optimize the Solr index
     *
     * @return void
     */
    public function optimizeIndex()
    {
        $this->log->logInfo('optimizeIndex', 'Optimizing Solr index');
        $this->solrRequest('{ "optimize": {} }', 4 * 60 * 60);
        $this->log->logInfo('optimizeIndex', 'Solr optimization completed');
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
    public function countValues($sourceId, $field, $mapped = false)
    {
        $this->log->logInfo('countValues', "Creating record list");
        $params = ['deleted' => false];
        if ($sourceId) {
            $params['source_id'] = $sourceId;
        }
        $this->log->logInfo('countValues', "Counting values");
        $values = [];
        $count = 0;
        $this->db->iterateRecords(
            $params,
            [],
            function ($record) use (&$values, &$count, $mapped, $field) {
                $source = $record['source_id'];
                if (!isset($this->settings[$source])) {
                    // Try to reload data source settings as they might have been
                    // updated during a long run
                    $this->initDatasources();
                    if (!isset($this->settings[$source])) {
                        $this->log->logError(
                            'countValues',
                            "No settings found for data source '$source', record "
                                . $record['_id']
                        );
                    }
                }
                $settings = $this->settings[$source] ?? [];
                $mergedComponents = 0;
                if ($mapped) {
                    $data = $this->createSolrArray($record, $mergedComponents);
                } else {
                    $metadataRecord = $this->createRecord(
                        $record['format'],
                        $this->metadataUtils->getRecordData($record, true),
                        $record['oai_id'],
                        $record['source_id']
                    );
                    if (isset($settings['solrTransformationXSLT'])) {
                        $params = [
                            'source_id' => $source,
                            'institution' => $settings['institution'],
                            'format' => $settings['format'],
                            'id_prefix' => $settings['idPrefix']
                        ];
                        $data = $settings['solrTransformationXSLT']
                            ->transformToSolrArray(
                                $metadataRecord->toXML(),
                                $params
                            );
                    } else {
                        $data = $metadataRecord->toSolrArray($this->db);
                        $this->enrich($source, $settings, $metadataRecord, $data);
                    }
                }
                if (isset($data[$field])) {
                    $fieldArray = is_array($data[$field])
                        ? $data[$field] : [$data[$field]];
                    foreach ($fieldArray as $value) {
                        if (!isset($values[$value])) {
                            $values[$value] = 1;
                        } else {
                            ++$values[$value];
                        }
                    }
                }
                ++$count;
                if ($count % 1000 == 0) {
                    $this->log->logInfo('countValues', "$count records processed");
                    if ($this->verbose) {
                        echo "Current list:\n";
                        arsort($values, SORT_NUMERIC);
                        foreach ($values as $key => $value) {
                            echo str_pad($value, 10, ' ', STR_PAD_LEFT) . ": $key\n";
                        }
                        echo "\n";
                    }
                }
            }
        );
        arsort($values, SORT_NUMERIC);
        echo "Result list:\n";
        foreach ($values as $key => $value) {
            echo str_pad($value, 10, ' ', STR_PAD_LEFT) . ": $key\n";
        }
    }

    /**
     * Check Solr index for orphaned records
     *
     * @return void
     */
    public function checkIndexedRecords()
    {
        $request = $this->initSolrRequest(\HTTP_Request2::METHOD_GET);
        $baseUrl = $this->config['Solr']['search_url']
            . '?q=*:*&sort=id+asc&wt=json&fl=id,record_format&rows=1000';

        $this->initBufferedUpdate();
        $count = 0;
        $orphanRecordCount = 0;
        $orphanDedupCount = 0;
        $lastDisplayedCount = 0;
        $pc = new PerformanceCounter();
        $lastCursorMark = '';
        $cursorMark = '*';
        while ($cursorMark && $cursorMark !== $lastCursorMark) {
            $url = $baseUrl . '&cursorMark=' . urlencode($cursorMark);
            $request->setUrl($url);
            $response = $request->send();
            if ($response->getStatus() != 200) {
                $this->log->logInfo(
                    'SolrCheck',
                    "Could not scroll cursor mark (url $url), status code "
                        . $response->getStatus()
                );
                throw new \Exception('Solr request failed');
            }
            $json = json_decode($response->getBody(), true);
            $records = $json['response']['docs'];

            foreach ($records as $record) {
                $id = $record['id'];
                if ('merged' === $record['record_format'] ?? $record['recordtype']) {
                    $dbRecord = $this->db->getDedup($id);
                } else {
                    $dbRecord = $this->db->getRecord($id);
                }
                if (!$dbRecord || !empty($dbRecord['deleted'])) {
                    $this->bufferedDelete($id);
                    ++$orphanRecordCount;
                    if ('merged' === $record['record_format']) {
                        ++$orphanDedupCount;
                    }
                }
            }

            $count += count($records);
            $lastCursorMark = $cursorMark;
            $cursorMark = $json['nextCursorMark'];
            if ($count >= $lastDisplayedCount + 1000) {
                $lastDisplayedCount = $count;
                $pc->add($count);
                $avg = $pc->getSpeed();
                $this->log->logInfo(
                    'checkIndexedRecords',
                    "$count records checked with $orphanRecordCount orphaned records"
                        . " (of which $orphanDedupCount dedup records) deleted,"
                        . " $avg records/sec"
                );
            }
        }
        $this->flushUpdateBuffer();

        $this->log->logInfo(
            'checkIndexedRecords',
            "$count records checked with $orphanRecordCount orphaned records"
            . " (of which $orphanDedupCount dedup records) deleted,"
            . " $avg records/sec"
        );

        if ($orphanRecordCount) {
            $this->log->logInfo('checkIndexedRecords', 'Final commit...');
            $this->solrRequest('{ "commit": {} }', 3600);
            $this->log->logInfo('checkIndexedRecords', 'Commit complete');
        }
    }

    /**
     * Initialize worker pool manager and the pools for processing single records
     *
     * @return void
     */
    protected function initSingleRecordWorkerPools()
    {
        $this->initWorkerPoolManager();
        $this->workerPoolManager->createWorkerPool(
            'solr',
            $this->solrUpdateWorkers,
            $this->solrUpdateWorkers,
            [$this, 'solrRequest']
        );
        $this->workerPoolManager->createWorkerPool(
            'record',
            $this->recordWorkers,
            $this->recordWorkers,
            [$this, 'processSingleRecord']
        );
    }

    /**
     * Iterate all merged records
     *
     * @param string|null $fromDate      Start date
     * @param string      $sourceId      Comma-separated list of source IDs to
     *                                   update, or empty or * for all sources
     * @param string      $singleId      Process only the record with the given ID
     * @param string      $lastUpdateKey Database state key for last index update
     * @param bool        $checkParent   Whether to check that a parent process is
     *                                   alive
     * @param Callable    $callback      Callback to call for each record
     *
     * @return void
     *
     * @throws \Exception
     */
    protected function iterateMergedRecords(
        $fromDate,
        $sourceId,
        $singleId,
        $lastUpdateKey,
        $checkParent,
        $callback
    ) {
        // Clean up any left over tracking collections
        $res = $this->db->cleanupTrackingCollections();
        if ($res['removed']) {
            $this->log->logInfo(
                'processMerged',
                'Cleanup: dropped old tracking collections: '
                    . implode(', ', $res['removed'])
            );
        }
        if ($res['failed']) {
            $this->log->logWarning(
                'processMerged',
                'Failed to drop old tracking collections: '
                    . implode(', ', $res['failed'])
            );
        }

        $fromTimestamp = $this->getStartTimestamp($fromDate, $lastUpdateKey);
        $params = [];
        if ($singleId) {
            $params['_id'] = $singleId;
            $params['dedup_id'] = ['$exists' => true];
        } else {
            if (null !== $fromTimestamp) {
                $params['updated']
                    = ['$gte' => $this->db->getTimestamp($fromTimestamp)];
            }
            [$sourceOr, $sourceNor] = $this->createSourceFilter($sourceId);
            if ($sourceOr) {
                $params['$or'] = $sourceOr;
            }
            if ($sourceNor) {
                $params['$nor'] = $sourceNor;
            }
            $params['dedup_id'] = ['$exists' => true];
        }

        $trackingName = $this->db->getNewTrackingCollection();
        $this->log->logInfo(
            'iterateMergedRecords',
            "Tracking progress with collection $trackingName"
        );

        $from = null !== $fromTimestamp
            ? gmdate('Y-m-d H:i:s\Z', $fromTimestamp) : 'the beginning';

        $this->log->logInfo(
            'iterateMergedRecords',
            "Processing records (from $from, stage 1/2)"
        );

        $prevId = null;
        $count = 0;
        $this->db->iterateRecords(
            $params,
            ['projection' => ['_id' => 1, 'dedup_id' => 1]],
            function ($record) use (
                $checkParent,
                $trackingName,
                &$count,
                &$prevId,
                $callback
            ) {
                if ($checkParent) {
                    $this->checkParentIsAlive();
                }
                if (isset($this->terminate)) {
                    return false;
                }
                $id = (string)$record['dedup_id'];

                if ($prevId !== $id
                    && $this->db->addIdToTrackingCollection($trackingName, $id)
                ) {
                    $callback($id);
                    ++$count;
                }
                $prevId = $id;
            }
        );
        if (isset($this->terminate)) {
            $this->log->logInfo('iterateMergedRecords', 'Termination upon request');
            $this->db->dropTrackingCollection($trackingName);
            exit(1);
        }
        $this->log->logInfo('iterateMergedRecords', "$count records processed");

        $this->log->logInfo(
            'iterateMergedRecords',
            "Processing merge records (from $from, stage 2/2)"
        );

        $dedupParams = [];
        if ($singleId) {
            $dedupParams['ids'] = $singleId;
        } elseif (null !== $fromTimestamp) {
            $dedupParams['changed']
                = ['$gte' => $this->db->getTimestamp($fromTimestamp)];
        } else {
            $this->log->logWarning(
                'iterateMergedRecords',
                'Processing all merge records -- this may be a lengthy process'
                    . ' if deleted records have not been purged regularly'
            );
        }

        $count = 0;
        $this->db->iterateDedups(
            $dedupParams,
            [],
            function ($record) use (
                $checkParent,
                &$count,
                $trackingName,
                $callback
            ) {
                if ($checkParent) {
                    $this->checkParentIsAlive();
                }
                if (isset($this->terminate)) {
                    return false;
                }
                $id = (string)$record['_id'];
                if ($this->db->addIdToTrackingCollection($trackingName, $id)) {
                    $callback($id);
                    ++$count;
                }
            }
        );
        $this->log
            ->logInfo('iterateMergedRecords', "$count merge records processed");

        $this->db->dropTrackingCollection($trackingName);

        if (isset($this->terminate)) {
            $this->log->logInfo('iterateMergedRecords', 'Termination upon request');
            exit(1);
        }
    }

    /**
     * Initialize or reload data source settings
     *
     * @param array $dataSourceConfig Optional data source settings to use instead
     *                                of reading them from the ini file
     *
     * @return void
     */
    protected function initDatasources($dataSourceConfig = null)
    {
        if (null === $dataSourceConfig) {
            $dataSourceConfig = $this->configReader->get('datasources.ini', true);
            $this->fieldMapper->initdataSourceConfig($dataSourceConfig);
        }
        $this->settings = [];
        foreach ($dataSourceConfig as $source => $settings) {
            if (!isset($settings['format'])) {
                throw new \Exception("Error: format not set for $source\n");
            }
            $this->settings[$source] = $settings;
            $this->settings[$source]['idPrefix'] = isset($settings['idPrefix'])
                && $settings['idPrefix'] ? $settings['idPrefix'] : $source;
            $this->settings[$source]['componentParts']
                = isset($settings['componentParts']) && $settings['componentParts']
                    ? $settings['componentParts'] : 'as_is';
            $this->settings[$source]['indexMergedParts']
                = $settings['indexMergedParts'] ?? true;
            $this->settings[$source]['solrTransformationXSLT']
                = isset($settings['solrTransformation'])
                    && $settings['solrTransformation']
                    ? new \RecordManager\Base\Utils\XslTransformation(
                        RECMAN_BASE_PATH . '/transformations',
                        $settings['solrTransformation']
                    ) : null;
            if (!isset($this->settings[$source]['dedup'])) {
                $this->settings[$source]['dedup'] = false;
            }

            $this->settings[$source]['extraFields'] = [];
            foreach ($settings['extraFields'] ?? $settings['extrafields'] ?? []
                as $extraField
            ) {
                [$field, $value] = explode(':', $extraField, 2);
                $this->settings[$source]['extraFields'][] = [$field => $value];
            }

            if (isset($settings['index']) && !$settings['index']) {
                $this->nonIndexedSources[] = $source;
            }
        }
    }

    /**
     * Create Solr array for the given record
     *
     * @param array $record           Database record
     * @param int   $mergedComponents Number of component parts merged to the
     *                                record
     * @param array $dedupRecord      Database dedup record
     *
     * @return array|false
     * @throws \Exception
     */
    protected function createSolrArray(
        array $record,
        &$mergedComponents,
        $dedupRecord = null
    ) {
        $mergedComponents = 0;

        $source = $record['source_id'];
        if (!isset($this->settings[$source])) {
            // Try to reload data source settings as they might have been updated
            // during a long run
            $this->initDatasources();
            if (!isset($this->settings[$source])) {
                $this->log->logError(
                    'createSolrArray',
                    "No settings found for data source '$source', record "
                        . $record['_id']
                );
                return false;
            }
        }

        $metadataRecord = $this->createRecord(
            $record['format'],
            $this->metadataUtils->getRecordData($record, true),
            $record['oai_id'],
            $record['source_id']
        );

        $settings = $this->settings[$source];
        $hiddenComponent = $this->metadataUtils->isHiddenComponentPart(
            $settings,
            $record,
            $metadataRecord
        );

        if ($hiddenComponent && !$settings['indexMergedParts']) {
            return false;
        }

        $warnings = [];

        $hasComponentParts = false;
        $components = null;
        if (!isset($record['host_record_id'])) {
            // Fetch info whether component parts exist and need to be merged
            if (!$record['linking_id']) {
                if ($this->db) {
                    $this->log->logError(
                        'createSolrArray',
                        "linking_id missing for record '{$record['_id']}'"
                    );
                    $warnings[] = 'linking_id missing';
                }
            } else {
                $params = [
                    'host_record_id' => [
                        '$in' => array_values((array)$record['linking_id'])
                    ],
                    'deleted' => false,
                    'suppressed' => ['$in' => [null, false]],
                ];
                if (!empty($settings['componentPartSourceId'])) {
                    $sourceParams = [];
                    foreach ($settings['componentPartSourceId'] as $componentSource
                    ) {
                        $sourceParams[] = ['source_id' => $componentSource];
                    }
                    $params['$or'] = $sourceParams;
                } else {
                    $params['source_id'] = $record['source_id'];
                }
                $component = $this->db ? $this->db->findRecord($params) : null;
                $hasComponentParts = !empty($component);

                $format = $metadataRecord->getFormat();
                $merge = false;
                if ($settings['componentParts'] == 'merge_all') {
                    $merge = true;
                } elseif (!in_array($format, $this->allJournalFormats)) {
                    $merge = true;
                } elseif (in_array($format, $this->journalFormats)
                    && $settings['componentParts'] == 'merge_non_earticles'
                ) {
                    $merge = true;
                }

                if ($merge && $hasComponentParts) {
                    $components = $this->db->findRecords(
                        $params,
                        ['limit' => 10000] // An arbitrary limit, but we something
                    );
                }
            }
        }

        if ($hasComponentParts && null !== $components) {
            $changeDate = null;
            $mergedComponents += $metadataRecord->mergeComponentParts(
                $components,
                $changeDate
            );
            // Use latest date as the host record date
            if (null !== $changeDate && $changeDate > $record['date']) {
                $record['date'] = $changeDate;
            }
        }
        if (isset($settings['solrTransformationXSLT'])) {
            $params = [
                'source_id' => $source,
                'institution' => $settings['institution'],
                'format' => $settings['format'],
                'id_prefix' => $settings['idPrefix']
            ];
            $data = $settings['solrTransformationXSLT']
                ->transformToSolrArray($metadataRecord->toXML(), $params);
        } else {
            $data = $metadataRecord->toSolrArray($this->db);
        }

        $data['id'] = $this->createSolrId($record['_id']);

        $this->enrich($source, $settings, $metadataRecord, $data, '');

        if (null !== $dedupRecord && $this->dedupIdField) {
            $data[$this->dedupIdField] = (string)$dedupRecord['_id'];
        }

        // Record links between host records and component parts
        $hostDataToCopy = [];
        if ($metadataRecord->getIsComponentPart()) {
            $hostRecords = [];
            if ($this->db && !empty($record['host_record_id'])) {
                $hostRecords = $this->db->findRecords(
                    [
                        'source_id' => $record['source_id'],
                        'linking_id' => [
                            '$in' => array_values((array)$record['host_record_id'])
                        ]
                    ],
                    ['limit' => 10000] // An arbitrary limit, but we need something
                );
                if (!$hostRecords) {
                    $this->log->logWarning(
                        'createSolrArray',
                        "Any of host records ["
                            . implode(', ', (array)$record['host_record_id'])
                            . "] not found for record '" . $record['_id'] . "'"
                    );
                    $warnings[] = 'host record missing';
                    if ($this->containerTitleField) {
                        $data[$this->containerTitleField]
                            = $metadataRecord->getContainerTitle();
                    }
                }
            }
            if ($hostRecords) {
                foreach ($hostRecords as $hostRecord) {
                    if ($this->hierarchyParentIdField) {
                        $data[$this->hierarchyParentIdField][]
                            = $this->createSolrId($hostRecord['_id']);
                    }
                    $hostMetadataRecord = $this->metadataRecordCache
                        ->get($hostRecord['_id']);
                    if (null === $hostMetadataRecord) {
                        $hostMetadataRecord = $this->createRecord(
                            $hostRecord['format'],
                            $this->metadataUtils->getRecordData($hostRecord, true),
                            $hostRecord['oai_id'],
                            $hostRecord['source_id']
                        );
                        $this->metadataRecordCache
                            ->put($hostRecord['_id'], $hostMetadataRecord);
                    }
                    $hostTitle = $hostMetadataRecord->getTitle();
                    if ($this->hierarchyParentTitleField) {
                        $data[$this->hierarchyParentTitleField][] = $hostTitle;
                    }
                    if ($this->containerTitleField
                        && empty($data[$this->containerTitleField])
                    ) {
                        $data[$this->containerTitleField] = $hostTitle;
                    }
                    if ($this->copyFromParentRecord) {
                        // Collect data to copy here, but do the actual copying in
                        // the end to avoid duplicate mapping etc.
                        $hostId = 'host_' . $hostRecord['_id'];
                        $hostData = $this->recordDataCache->get($hostId);
                        if (null === $hostData) {
                            $hostData = $hostMetadataRecord->toSolrArray($this->db);
                            $this->augmentAndProcessFields(
                                $hostData,
                                $hostRecord,
                                $hostMetadataRecord,
                                $source,
                                $settings
                            );
                            $this->recordDataCache->put($hostId, $hostData);
                        }
                        $hostDataToCopy[] = $hostData;
                    }
                }
            }
            if ($this->containerVolumeField) {
                $data[$this->containerVolumeField] = $metadataRecord->getVolume();
            }
            if ($this->containerIssueField) {
                $data[$this->containerIssueField] = $metadataRecord->getIssue();
            }
            if ($this->containerStartPageField) {
                $data[$this->containerStartPageField]
                    = $metadataRecord->getStartPage();
            }
            if ($this->containerReferenceField) {
                $data[$this->containerReferenceField]
                    = $metadataRecord->getContainerReference();
            }
        } else {
            // Add prefixes to hierarchy linking fields
            $hierarchyFields = [
                $this->hierarchyTopIdField,
                $this->hierarchyParentIdField,
                $this->isHierarchyIdField
            ];
            foreach ($hierarchyFields as $field) {
                if (!$field) {
                    continue;
                }
                if (isset($data[$field]) && $data[$field]) {
                    $data[$field] = $this->createSolrId(
                        ($settings['idPrefix'] ?? $record['source_id'])
                        . '.' . $data[$field]
                    );
                }
            }
        }
        if ($hasComponentParts) {
            if ($this->isHierarchyIdField) {
                $data[$this->isHierarchyIdField]
                    = $this->createSolrId($record['_id']);
            }
            if ($this->isHierarchyTitleField) {
                $data[$this->isHierarchyTitleField] = $metadataRecord->getTitle();
            }
        }

        if ($hiddenComponent) {
            $data['hidden_component_boolean'] = true;
        }

        // Work identification keys
        $this->addWorkKeys($data, $metadataRecord);

        $this->augmentAndProcessFields(
            $data,
            $record,
            $metadataRecord,
            $source,
            $settings
        );

        foreach ($hostDataToCopy as $hostData) {
            $this->copyParentDataToChild($hostData, $data);
        }

        if (!empty($this->warningsField)) {
            $warnings = array_merge(
                $warnings,
                $metadataRecord->getProcessingWarnings()
            );
            if ($warnings) {
                $data[$this->warningsField] = $warnings;
            }
        }

        $this->enrich($source, $settings, $metadataRecord, $data, 'final');

        return $data;
    }

    /**
     * Add work identification keys
     *
     * @param array          $data           Field array
     * @param AbstractRecord $metadataRecord Metadata record
     *
     * @return void
     */
    protected function addWorkKeys(array &$data, AbstractRecord $metadataRecord)
    {
        if ($this->workKeysField
            && $workIds = $metadataRecord->getWorkIdentificationData()
        ) {
            $keys = [];
            foreach ($workIds['titles'] ?? [] as $titleData) {
                $title = $this->metadataUtils->normalizeKey(
                    $titleData['value'],
                    $this->unicodeNormalizationForm
                );
                if ('uniform' === $titleData['type']) {
                    $keys[] = "UT $title";
                } else {
                    foreach ($workIds['authors'] ?? [] as $authorData) {
                        $author = $this->metadataUtils->normalizeKey(
                            $authorData['value'],
                            $this->unicodeNormalizationForm
                        );
                        $keys[] = "AT $author $title";
                    }
                }
            }
            foreach ($workIds['titlesAltScript'] ?? [] as $titleData) {
                $title = $this->metadataUtils->normalizeKey(
                    $titleData['value'],
                    $this->unicodeNormalizationForm
                );
                if ('uniform' === $titleData['type']) {
                    $keys[] = "UT $title";
                } else {
                    foreach ($workIds['authorsAltScript'] ?? [] as $authorData) {
                        $author = $this->metadataUtils->normalizeKey(
                            $authorData['value'],
                            $this->unicodeNormalizationForm
                        );
                        $keys[] = "AT $author $title";
                    }
                }
            }
            if ($keys) {
                $data[$this->workKeysField] = $keys;
            }
        }
    }

    /**
     * Add extra fields from settings etc. and map the values
     *
     * @param array          $data           Field array
     * @param mixed          $record         Database record
     * @param AbstractRecord $metadataRecord Metadata record
     * @param string         $source         Source ID
     * @param array          $settings       Settings
     *
     * @return void
     */
    protected function augmentAndProcessFields(
        array &$data,
        $record,
        AbstractRecord $metadataRecord,
        string $source,
        array $settings
    ): void {
        if (!isset($data['institution']) && !empty($settings['institution'])) {
            $data['institution'] = $settings['institution'];
        }

        foreach ($settings['extraFields'] as $extraField) {
            $fieldName = key($extraField);
            $fieldValue = current($extraField);
            if (isset($data[$fieldName])) {
                if (!is_array($data[$fieldName])) {
                    $data[$fieldName] = [$data[$fieldName]];
                }
                $data[$fieldName][] = $fieldValue;
            } else {
                $data[$fieldName] = $fieldValue;
            }
        }

        // Special case: Special values for building (institution/location).
        // Used by default if building is set as a hierarchical facet.
        // This version adds institution to building before mapping files are
        // processed.
        if (($this->buildingHierarchy || isset($settings['institutionInBuilding']))
            && !empty($settings['addInstitutionToBuildingBeforeMapping'])
        ) {
            $this->addInstitutionToBuilding($data, $source, $settings);
        }

        // Map field values according to any mapping files
        if (!$this->disableMappings) {
            $data = $this->fieldMapper->mapValues($source, $data);
        }

        // Special case: Special values for building (institution/location).
        // Used by default if building is set as a hierarchical facet.
        // This version adds institution to building after mapping files are
        // processed.
        if (($this->buildingHierarchy || isset($settings['institutionInBuilding']))
            && empty($settings['addInstitutionToBuildingBeforeMapping'])
        ) {
            $this->addInstitutionToBuilding($data, $source, $settings);
        }

        // Hierarchical facets
        foreach ($this->hierarchicalFacets as $facet) {
            if (!isset($data[$facet])) {
                continue;
            }
            $array = [];
            if (!is_array($data[$facet])) {
                $data[$facet] = [$data[$facet]];
            }
            foreach ($data[$facet] as $datavalue) {
                if ($datavalue === '') {
                    continue;
                }
                $values = is_array($datavalue) ? $datavalue
                    : explode('/', $datavalue);
                $hierarchyString = '';
                $valueCount = count($values);
                for ($i = 0; $i < $valueCount; $i++) {
                    $hierarchyString .= '/' . $values[$i];
                    $array[] = ($i) . $hierarchyString . '/';
                }
            }
            $data[$facet] = $array;
        }

        if (!isset($data['allfields'])) {
            $all = [];
            foreach ($data as $key => $field) {
                if (in_array(
                    $key,
                    [
                        'fullrecord', 'thumbnail', 'id', 'recordtype',
                        'record_format', 'ctrlnum'
                    ]
                )
                ) {
                    continue;
                }
                if (is_array($field)) {
                    $all = array_merge($all, $field);
                } else {
                    $all[] = $field;
                }
            }
            $data['allfields'] = $this->metadataUtils->array_iunique($all);
        }

        $data['first_indexed']
            = $this->metadataUtils->formatTimestamp(
                $this->db ? $this->db->getUnixTime($record['created'])
                    : $record['created']
            );
        $data['last_indexed'] = $this->metadataUtils->formatTimestamp(
            $this->db ? $this->db->getUnixTime($record['date']) : $record['date']
        );
        if (!isset($data['fullrecord'])) {
            $data['fullrecord'] = $metadataRecord->toXML();
        }

        if (isset($this->config['Solr']['format_in_allfields'])
            && $this->config['Solr']['format_in_allfields']
        ) {
            if (!is_array($data['format'])) {
                $data['format'] = [$data['format']];
            }
            foreach ($data['format'] as $format) {
                // Replace numbers since they may be be considered word boundaries
                $data['allfields'][] = str_replace(
                    ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'],
                    ['ax', 'bx', 'cx', 'dx', 'ex', 'fx', 'gx', 'hx', 'ix', 'jx'],
                    $this->metadataUtils->normalizeKey(
                        $format,
                        $this->unicodeNormalizationForm
                    )
                );
            }
        }

        $this->normalizeFields($data);
    }

    /**
     * Normalize and clean up fields
     *
     * @param array $data Field array
     *
     * @return void
     */
    protected function normalizeFields(array &$data)
    {
        foreach ($data as $key => &$values) {
            if (is_array($values)) {
                foreach ($values as $key2 => &$value) {
                    $value = $this->metadataUtils->normalizeUnicode(
                        $value,
                        $this->unicodeNormalizationForm
                    );
                    $value = $this->trimFieldLength($key, $value);
                    if (empty($value) || $value === 0 || $value === 0.0
                        || $value === '0'
                    ) {
                        unset($values[$key2]);
                    }
                }
                $values = array_values(array_unique($values));
            } elseif ($key != 'fullrecord') {
                $values = $this->metadataUtils->normalizeUnicode(
                    $values,
                    $this->unicodeNormalizationForm
                );
                $values = $this->trimFieldLength($key, $values);
            }
            if (empty($values) || $values === 0 || $values === 0.0 || $values === '0'
            ) {
                unset($data[$key]);
            }
        }
    }

    /**
     * Prefix building with institution code according to the settings
     *
     * @param array  $data     Record data
     * @param string $source   Source ID
     * @param array  $settings Data source settings
     *
     * @return void
     */
    protected function addInstitutionToBuilding(&$data, $source, $settings)
    {
        $useInstitution = $settings['institutionInBuilding'] ?? 'institution';
        switch ($useInstitution) {
        case 'driver':
            $institutionCode = $data['institution'];
            break;
        case 'none':
            $institutionCode = '';
            break;
        case 'source':
            $institutionCode = $source;
            break;
        case 'institution/source':
            $institutionCode = isset($settings['institution'])
                ? $settings['institution'] . '/' . $source
                : '/' . $source;
            break;
        default:
            $institutionCode = $settings['institution'] ?? '';
            break;
        }
        if ($institutionCode) {
            foreach ($this->buildingFields as $field) {
                if (!empty($data[$field])) {
                    if (is_array($data[$field])) {
                        foreach ($data[$field] as &$building) {
                            // Allow also empty values that might result from
                            // mapping tables
                            if (is_array($building)) {
                                // Predefined hierarchy, prepend to it
                                if (!empty($building)) {
                                    array_unshift($building, $institutionCode);
                                }
                            } elseif ($building !== '') {
                                $building = "$institutionCode/$building";
                            } elseif ('building' === $field) {
                                $building = $institutionCode;
                            }
                        }
                    } else {
                        $data[$field] = $institutionCode . '/' . $data[$field];
                    }
                } elseif ('building' === $field) {
                    $data[$field] = [$institutionCode];
                }
            }
        }
    }

    /**
     * Merge Solr records into a merged record
     *
     * @param array $records Array of records to merge including the database record
     *                       and Solr array
     *
     * @return array Merged Solr array
     */
    protected function mergeRecords($records)
    {
        // Analyze the records to find the best record to be used as the base
        foreach ($records as &$record) {
            $fieldCount = 0;
            $capsRatios = 0;
            $titleLen = isset($record['solr']['title'])
                ? mb_strlen($record['solr']['title'], 'UTF-8') : 0;
            $fields = array_intersect_key($record['solr'], $this->scoredFields);
            array_walk_recursive(
                $fields,
                function ($field) use (&$fieldCount, &$capsRatios) {
                    ++$fieldCount;

                    $uppercase = preg_match_all('/[\p{Lu}]/u', $field);
                    $length = mb_strlen($field, 'UTF-8');
                    if ($length) {
                        $capsRatios += $uppercase / $length;
                    }
                }
            );
            if (0 === $fieldCount) {
                $record['score'] = 0;
            } else {
                $baseScore = $fieldCount + $titleLen;
                $capsRatio = $capsRatios / $fieldCount;
                $record['score'] = 0 == $capsRatio ? $fieldCount
                    : $baseScore / $capsRatio;
            }
        }
        unset($record);

        // Sort records
        usort(
            $records,
            function ($a, $b) {
                return $b['score'] - $a['score'];
            }
        );

        $merged = [];

        foreach ($records as $record) {
            $add = $record['solr'];

            if (empty($merged)) {
                $merged['local_ids_str_mv'] = [$add['id']];
            } else {
                $merged['local_ids_str_mv'][] = $add['id'];
            }
            foreach ($add as $key => $value) {
                $authorSpecial = $key == 'author'
                    && isset($this->mergedFields['author=author2']);
                if (substr($key, -3, 3) == '_mv' || isset($this->mergedFields[$key])
                    || ($authorSpecial && isset($merged['author'])
                    && $merged['author'] !== $value)
                ) {
                    if ($authorSpecial) {
                        $key = 'author2';
                    }
                    if (!isset($merged[$key])) {
                        $merged[$key] = [];
                    } elseif (!is_array($merged[$key])) {
                        $merged[$key] = [$merged[$key]];
                    }
                    $values = is_array($value) ? $value : [$value];
                    $merged[$key] = array_values(
                        array_merge($merged[$key], $values)
                    );
                } elseif (isset($this->singleFields[$key])
                    || ($authorSpecial && !isset($merged[$key]))
                ) {
                    if (empty($merged[$key])) {
                        $merged[$key] = $value;
                    }
                } elseif ($key == 'allfields') {
                    if (!isset($merged['allfields'])) {
                        $merged['allfields'] = [];
                    }
                    $merged['allfields'] = array_values(
                        array_merge($merged['allfields'], $add['allfields'])
                    );
                }
            }
        }

        return $merged;
    }

    /**
     * Copy configured fields from merged record to the member records
     *
     * @param array $merged  Merged record
     * @param array $records Array of member records
     *
     * @return void
     */
    protected function copyMergedDataToMembers($merged, &$records)
    {
        foreach ($this->copyFromMergedRecord as $copyField) {
            if (empty($merged[$copyField])) {
                continue;
            }
            foreach ($records as &$member) {
                $member['solr'][$copyField] = array_values(
                    array_unique(
                        array_merge(
                            (array)($member['solr'][$copyField] ?? []),
                            (array)$merged[$copyField]
                        )
                    )
                );
            }
        }
    }

    /**
     * Copy configured fields from a parent record to the child records
     *
     * This may add duplicate fields.
     *
     * @param array $parent Parent record
     * @param array $child  Child record array
     *
     * @return void
     */
    protected function copyParentDataToChild($parent, &$child)
    {
        foreach ($this->copyFromParentRecord as $copyField) {
            if (empty($parent[$copyField])) {
                continue;
            }
            if (empty($child[$copyField])) {
                $child[$copyField] = (array)$parent[$copyField];
            } else {
                $child[$copyField] = array_merge(
                    (array)($child[$copyField] ?? []),
                    (array)$parent[$copyField]
                );
            }
        }
    }

    /**
     * Initialize a Solr request object
     *
     * @param string $method  HTTP method
     * @param int    $timeout Timeout in seconds (optional)
     *
     * @return \HTTP_Request2
     */
    protected function initSolrRequest($method, $timeout = null)
    {
        $request = $this->httpClientManager->createClient(
            $this->config['Solr']['update_url'],
            $method
        );
        if ($timeout !== null) {
            $request->setConfig('timeout', $timeout);
        }
        $request->setHeader('Connection', 'Keep-Alive');
        // At least some combinations of PHP + curl cause both Transfer-Encoding and
        // Content-Length to be set in certain cases. Set follow_redirects to true to
        // invoke the PHP workaround in the curl adapter.
        $request->setConfig('follow_redirects', true);
        if (isset($this->config['Solr']['username'])
            && isset($this->config['Solr']['password'])
        ) {
            $request->setAuth(
                $this->config['Solr']['username'],
                $this->config['Solr']['password'],
                \HTTP_Request2::AUTH_BASIC
            );
        }
        return $request;
    }

    /**
     * Make a JSON request to the Solr server
     *
     * Public visibility so that the workers can call this
     *
     * @param string       $body    The JSON request
     * @param integer|null $timeout If specified, the HTTP call timeout in seconds
     *
     * @return void
     */
    public function solrRequest($body, $timeout = null)
    {
        if (null === $this->request) {
            $this->request
                = $this->initSolrRequest(\HTTP_Request2::METHOD_POST, $timeout);
        }

        if (!$this->waitForClusterStateOk()) {
            throw new \Exception('Failed to check that the cluster state is ok');
        }

        $this->request->setHeader('Content-Type', 'application/json');
        $this->request->setBody($body);

        $response = null;
        $maxTries = $this->maxUpdateTries;
        for ($try = 1; $try <= $maxTries; $try++) {
            try {
                if (!$this->waitForClusterStateOk()) {
                    throw new \Exception(
                        'Failed to check that the cluster state is ok'
                    );
                }
                $response = $this->request->send();
            } catch (\Exception $e) {
                if ($try < $maxTries) {
                    $this->log->logWarning(
                        'solrRequest',
                        'Solr server request failed (' . $e->getMessage()
                            . "), retrying in {$this->updateRetryWait} seconds..."
                    );
                    sleep($this->updateRetryWait);
                    continue;
                }
                throw $e;
            }
            if ($try < $maxTries) {
                $code = null === $response ? 999 : $response->getStatus();
                if ($code >= 300) {
                    $this->log->logWarning(
                        'solrRequest',
                        "Solr server request failed ($code), retrying in "
                            . "{$this->updateRetryWait} seconds..."
                            . "Beginning of response: "
                            . substr($response->getBody(), 0, 1000)
                    );
                    sleep($this->updateRetryWait);
                    continue;
                }
            }
            break;
        }
        $code = null === $response ? 999 : $response->getStatus();
        if ($code >= 300) {
            throw new \Exception(
                "Solr server request failed ($code). URL:\n"
                . $this->config['Solr']['update_url']
                . "\nRequest:\n$body\n\nResponse:\n"
                . (null !== $response ? $response->getBody() : '')
            );
        }
    }

    /**
     * Wait until SolrCloud cluster state is ok
     *
     * @return bool
     */
    protected function waitForClusterStateOk()
    {
        if ($this->clusterStateCheckInterval <= 0) {
            return true;
        }
        $errors = 0;
        while (true) {
            $state = $this->checkClusterState();
            if ('ok' === $state) {
                return true;
            }
            if ('error' === $state) {
                ++$errors;
                if ($errors > $this->maxUpdateTries) {
                    $this->log->logError(
                        'waitForClusterStateOk',
                        "Cluster state check failed after {$this->maxUpdateTries}"
                            . ' attempts'
                    );
                    return false;
                }
            }
            $this->log->logWarning(
                'waitForClusterStateOk',
                'Retrying cluster state check in'
                    . " {$this->clusterStateCheckInterval} seconds..."
            );
            sleep($this->clusterStateCheckInterval);
        }
    }

    /**
     * Check SolrCloud cluster state
     *
     * Returns one of the following strings:
     * - ok       Everything is good
     * - error    Checking cluster state failed
     * - degraded Cluster is degraded or down
     *
     * @return string
     */
    protected function checkClusterState()
    {
        $lastCheck = time() - $this->lastClusterStateCheck;
        if ($lastCheck < $this->clusterStateCheckInterval) {
            return $this->clusterState;
        }
        $this->lastClusterStateCheck = time();
        $request = $this->initSolrRequest(\HTTP_Request2::METHOD_GET);
        $url = $this->config['Solr']['admin_url'] . '/zookeeper'
            . '?wt=json&detail=true&path=%2Fclusterstate.json&view=graph';
        $request->setUrl($url);
        try {
            $response = $request->send();
        } catch (\Exception $e) {
            $this->log->logError(
                'checkClusterState',
                "Solr admin request '$url' failed (" . $e->getMessage() . ')'
            );
            $this->clusterState = 'error';
            return 'error';
        }

        $code = null === $response ? 999 : $response->getStatus();
        if (200 !== $code) {
            $this->log->logError(
                'checkClusterState',
                "Solr admin request '$url' failed ($code): " . $response->getBody()
            );
            $this->clusterState = 'error';
            return 'error';
        }
        $state = json_decode($response->getBody(), true);
        if (null === $state) {
            $this->log->logError(
                'checkClusterState',
                'Unable to decode zookeeper status from response: '
                    . (null !== $response ? $response->getBody() : '')
            );
            $this->clusterState = 'error';
            return 'error';
        }
        $data = json_decode($state['znode']['data'], true);
        if (null === $data) {
            $this->log->logError(
                'checkClusterState',
                'Unable to decode node data from ' . $state['znode']['data']
            );
            $this->clusterState = 'error';
            return 'error';
        }
        foreach ($data as $collectionName => $collection) {
            foreach ($collection['shards'] as $shardName => $shard) {
                if (!in_array($shard['state'], $this->normalShardStatuses)) {
                    $this->log->logWarning(
                        'checkClusterState',
                        "Collection $collectionName shard $shardName:"
                            . " Not in usable state: {$shard['state']}"
                    );
                    $this->clusterState = 'degraded';
                    return 'degraded';
                }
                foreach ($shard['replicas'] as $replica) {
                    if ('active' !== $replica['state']) {
                        $this->log->logWarning(
                            'checkClusterState',
                            "Collection $collectionName shard $shardName: Core"
                            . " {$replica['core']} at {$replica['node_name']}"
                            . " not in active state: {$replica['state']}"
                        );
                        $this->clusterState = 'degraded';
                        return 'degraded';
                    }
                }
            }
        }
        $this->clusterState = 'ok';
        return 'ok';
    }

    /**
     * Initialize the record update buffer
     *
     * @return void
     */
    protected function initBufferedUpdate()
    {
        $this->buffer = '';
        $this->bufferLen = 0;
        $this->buffered = 0;
        $this->bufferedDeletions = [];
    }

    /**
     * Update Solr index in a batch
     *
     * @param array $data     Record metadata
     * @param int   $count    Number of records processed so far
     * @param bool  $noCommit Whether to not do any explicit commits
     *
     * @return boolean        False when buffering, true when buffer is flushed
     */
    protected function bufferedUpdate($data, $count, $noCommit)
    {
        $result = false;

        $jsonData = json_encode($data, JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($jsonData === false) {
            $this->log->logFatal(
                'bufferedUpdate',
                'Could not convert to JSON: ' . var_export($data, true)
            );
            throw new \Exception('Could not convert record to JSON');
        }
        if ($this->buffered > 0) {
            $this->buffer .= ",\n";
        }
        $this->buffer .= $jsonData;
        $this->bufferLen += strlen($jsonData);
        if (++$this->buffered >= $this->maxUpdateRecords
            || $this->bufferLen > $this->maxUpdateSize
        ) {
            $request = "[\n{$this->buffer}\n]";
            if ($this->dumpPrefix) {
                file_put_contents(
                    $this->getDumpFileName($this->dumpPrefix),
                    $request,
                    FILE_APPEND | LOCK_EX
                );
            } else {
                $this->workerPoolManager->addRequest(
                    'solr',
                    $request
                );
            }
            $this->buffer = '';
            $this->bufferLen = 0;
            $this->buffered = 0;
            $result = true;
        }
        if (!$noCommit && !$this->dumpPrefix && $count % $this->commitInterval == 0
        ) {
            $this->log->logInfo(
                'bufferedUpdate',
                'Waiting for any pending requests to complete...'
            );
            $this->workerPoolManager->waitUntilDone('solr');
            $this->log->logInfo('bufferedUpdate', 'Intermediate commit...');
            $this->solrRequest('{ "commit": {} }', 3600);
            $this->log->logInfo('bufferedUpdate', 'Intermediate commit complete');
        }
        return $result;
    }

    /**
     * Delete Solr records in a batch
     *
     * @param string $id Record ID
     *
     * @return boolean False when buffering, true when buffer is flushed
     */
    protected function bufferedDelete($id)
    {
        if ($this->dumpPrefix) {
            return false;
        }
        $id = $this->createSolrId($id);
        $this->bufferedDeletions[] = '"delete":{"id":"' . $id . '"}';
        if (count($this->bufferedDeletions) >= 1000) {
            $request = "{" . implode(',', $this->bufferedDeletions) . "}";
            if (null !== $this->workerPoolManager
                && $this->workerPoolManager->hasWorkerPool('solr')
            ) {
                $this->workerPoolManager->addRequest(
                    'solr',
                    $request
                );
            } else {
                $this->solrRequest($request);
            }
            $this->bufferedDeletions = [];
            return true;
        }
        return false;
    }

    /**
     * Flush the buffered updates to Solr
     *
     * @return void
     */
    protected function flushUpdateBuffer()
    {
        if ($this->buffered > 0) {
            $request = "[\n{$this->buffer}\n]";
            if ($this->dumpPrefix) {
                file_put_contents(
                    $this->getDumpFileName($this->dumpPrefix),
                    $request,
                    FILE_APPEND | LOCK_EX
                );
            } else {
                $this->solrRequest($request);
            }
        }
        if (!empty($this->bufferedDeletions)) {
            $this->solrRequest("{" . implode(',', $this->bufferedDeletions) . "}");
            $this->bufferedDeletions = [];
        }
    }

    /**
     * Enrich record according to the global and data source settings
     *
     * @param string $source   Source ID
     * @param array  $settings Data source settings
     * @param object $record   Metadata record
     * @param array  $data     Array of Solr fields
     * @param string $stage    Stage of record processing
     *                         - empty is for default, i.e. right after record has
     *                         been converted to a Solr array but not mapped
     *                         - "final" is for final Solr array after mappings etc.
     *
     * @return void
     */
    protected function enrich($source, $settings, $record, &$data, $stage = '')
    {
        $enrichments = array_unique(
            array_merge(
                (array)($this->config['Solr']['enrichment'] ?? []),
                (array)($settings['enrichments'] ?? [])
            )
        );
        foreach ($enrichments as $enrichmentSettings) {
            $parts = explode(',', $enrichmentSettings);
            $enrichment = $parts[0];
            $enrichmentStage = $parts[1] ?? '';
            if ($stage !== $enrichmentStage) {
                continue;
            }
            if (!isset($this->enrichments[$enrichment])) {
                $this->enrichments[$enrichment]
                    = $this->enrichmentPluginManager->get($enrichment);
            }
            $this->enrichments[$enrichment]->enrich($source, $record, $data);
        }
    }

    /**
     * Get a dump file name for a given prefix
     *
     * @param string $prefix File name prefix, may include path
     *
     * @return string File name for a newly created temp file
     */
    protected function getDumpFileName($prefix)
    {
        for ($i = 1; $i < 1000000; $i++) {
            $filename = "$prefix-$i.json";
            if (!file_exists($filename)) {
                touch($filename);
                return $filename;
            }
        }
        throw new \Exception('Could not find a free dump file slot');
    }

    /**
     * Pretty-print a record
     *
     * @param array $data   Record data to print
     * @param bool  $return If true, the pretty-printed record is returned instead
     *                      of being echoed to screen.
     *
     * @return string
     */
    protected function prettyPrint($data, $return = false)
    {
        if (defined('JSON_PRETTY_PRINT') && defined('JSON_UNESCAPED_UNICODE')) {
            $res = json_encode($data, JSON_PRETTY_PRINT + JSON_UNESCAPED_UNICODE);
            if (false !== $res) {
                $res .= "\n";
            } else {
                $res = print_r($data, true);
            }
        } else {
            $res = print_r($data, true);
        }
        if ($return) {
            return $res;
        }
        echo $res;
        return $res;
    }

    /**
     * Create a Solr id field from a record id
     *
     * @param string $recordId Record id including a source prefix
     *
     * @return string
     */
    protected function createSolrId($recordId)
    {
        $parts = explode('.', $recordId, 2);
        if ($id = ($parts[1] ?? null)) {
            $sourceSettings = $this->settings[$parts[0]] ?? [];
            if (!empty($sourceSettings['indexUnprefixedIds'])) {
                return $id;
            } else {
                if ($solrIdPrefix = ($sourceSettings['solrIdPrefix'] ?? null)) {
                    return "{$solrIdPrefix}.{$id}";
                }
            }
        }
        return $recordId;
    }

    /**
     * Parse source parameter to database selectors
     *
     * @param string $sourceIds A single source id or a comma-separated list of
     *                          sources or exclusion filters
     *
     * @return array of arrays $or and $nor filters
     */
    protected function createSourceFilter($sourceIds)
    {
        if (!$sourceIds || '*' === $sourceIds) {
            return [null, null];
        }
        $sources = explode(',', $sourceIds);
        $sourceParams = [];
        $sourceExclude = [];
        foreach ($sources as $source) {
            if ('' === trim($source)) {
                continue;
            }
            if (strncmp($source, '-', 1) === 0) {
                if (preg_match('/^-\/(.+)\/$/', $source, $matches)) {
                    $regex = new \RecordManager\Base\Database\Regex($matches[1]);
                    $sourceExclude[] = [
                        'source_id' => $regex
                    ];
                } else {
                    $sourceExclude[] = [
                        'source_id' => substr($source, 1)
                    ];
                }
            } else {
                $sourceParams[] = ['source_id' => $source];
            }
        }
        return [$sourceParams, $sourceExclude];
    }

    /**
     * Trim fields to their maximum lengths according to the configuration
     *
     * @param string $field Field
     * @param string $value Value to trim
     *
     * @return string
     */
    protected function trimFieldLength($field, $value)
    {
        if (empty($this->maxFieldLengths)) {
            return $value;
        }

        $foundLimit = $this->maxFieldLengths[$field] ?? null;
        if (null === $foundLimit) {
            foreach ($this->maxFieldLengths as $key => $limit) {
                if ('__default__' === $key) {
                    continue;
                }
                $left = strncmp('*', $key, 1) === 0;
                if ($left) {
                    $key = substr($key, 1);
                }
                $right = substr($key, -1) === '*';
                if ($right) {
                    $key = substr($key, 0, -1);
                }

                if ($left && $right) {
                    if (strpos($field, $key) !== false) {
                        $foundLimit = $limit;
                        break;
                    }
                } elseif ($left) {
                    if ($key === substr($field, -strlen($key))) {
                        $foundLimit = $limit;
                        break;
                    }
                } elseif ($right) {
                    if (strncmp($key, $field, strlen($key)) === 0) {
                        $foundLimit = $limit;
                        break;
                    }
                }
            }
            if (null === $foundLimit) {
                $foundLimit = $this->maxFieldLengths['__default__'] ?? null;
            }
            // Store the result for easier lookup further on
            $this->maxFieldLengths[$field] = $foundLimit;
        }

        if ($foundLimit) {
            $value = mb_substr($value, 0, $foundLimit, 'UTF-8');
        }
        return $value;
    }

    /**
     * Get a unix timestamp for the update start time
     *
     * @param string $fromDate      User-given start date
     * @param string $lastUpdateKey Last index update key in database
     *
     * @return int|null
     */
    protected function getStartTimestamp($fromDate, $lastUpdateKey)
    {
        if (null !== $fromDate) {
            if ($fromDate) {
                return strtotime($fromDate);
            }
        } else {
            if (!$lastUpdateKey) {
                return null;
            }
            $state = $this->db->getState($lastUpdateKey);
            if (null !== $state) {
                // Back-compatibility check:
                if (is_a($state['value'], 'MongoDB\BSON\UTCDateTime')) {
                    return $state['value']->toDateTime()->getTimestamp();
                }
                return $state['value'];
            }
        }
        return null;
    }
}

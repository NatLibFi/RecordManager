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
use RecordManager\Base\Exception\HttpRequestException;
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

    /**
     * Database
     *
     * @var ?\RecordManager\Base\Database\DatabaseInterface
     */
    protected $db;

    /**
     * Logger
     *
     * @var Logger
     */
    protected $log;

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
     * Count of records processed when last commit was done
     *
     * @var int
     */
    protected $lastCommitRecords;

    /**
     * Count of records deleted
     *
     * @var int
     */
    protected $deletedRecords = 0;

    /**
     * Count of records updated
     *
     * @var int
     */
    protected $updatedRecords = 0;

    /**
     * Count of merged component parts
     *
     * @var int
     */
    protected $mergedComponents = 0;

    /**
     * HTTP Client
     *
     * @var \HTTP_Request2
     */
    protected $request = null;

    /**
     * Fields to merge when processing deduplicated records
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
        'author', 'author_variant', 'author_role', 'author_sort',
        'author2', 'author2_variant', 'author2_role',
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
     * Fields to copy back from the merged dedup record to all the member records
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
     * How many individual record worker processes to use
     *
     * @var int
     */
    protected $recordWorkers;

    /**
     * How many deduplicated record worker processes to use
     *
     * @var int
     */
    protected $dedupWorkers;

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
    protected $workerPoolManager;

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
     * @param array                   $config            Main configuration
     * @param array                   $dataSourceConfig  Data source settings
     * @param ?Database               $db                Database connection
     * @param Logger                  $log               Logger
     * @param RecordPluginManager     $recordPM          Record plugin manager
     * @param EnrichmentPluginManager $enrichmentPM      Enrichment plugin manager
     * @param HttpClientManager       $httpManager       HTTP client manager
     * @param Ini                     $configReader      Configuration reader
     * @param FieldMapper             $fieldMapper       Field mapper
     * @param MetadataUtils           $metadataUtils     Metadata utilities
     * @param WorkerPoolManager       $workerPoolManager Worker pool manager
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
        MetadataUtils $metadataUtils,
        WorkerPoolManager $workerPoolManager
    ) {
        $this->config = $config;
        $this->db = $db;
        $this->log = $log;
        $this->recordPluginManager = $recordPM;
        $this->enrichmentPluginManager = $enrichmentPM;
        $this->httpClientManager = $httpManager;
        $this->configReader = $configReader;
        $this->fieldMapper = $fieldMapper;
        $this->metadataUtils = $metadataUtils;
        $this->workerPoolManager = $workerPoolManager;

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
        $this->dedupWorkers = $config['Solr']['dedup_workers']
            ?? $this->recordWorkers;
        $this->solrUpdateWorkers = $config['Solr']['solr_update_workers'] ?? 0;
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
     * Backtrace signal handler
     *
     * @param int $signo Signal number
     *
     * @return void
     */
    public function backtraceSignalHandler($signo)
    {
        debug_print_backtrace(0, 5);
    }

    /**
     * Update Solr index
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
     *
     * @psalm-suppress TypeDoesNotContainType
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
        if ($delete && !$sourceId) {
            throw new \Exception('Delete without source id specified');
        }

        $lastUpdateKey = $this->getLastUpdateStateKey(
            $datePerServer || $this->datePerServer
        );

        $this->dumpPrefix = $dumpPrefix;

        $verb = $this->dumpPrefix ? 'dumped' : 'indexed';
        $initVerb = $this->dumpPrefix ? 'Dumping' : 'Indexing';

        $fromTimestamp = null;
        try {
            if ($this->recordWorkers) {
                $this->log->logInfo(
                    'updateRecords',
                    "Using {$this->recordWorkers} individual record workers"
                );
            }
            if ($this->dedupWorkers) {
                $this->log->logInfo(
                    'updateRecords',
                    "Using {$this->dedupWorkers} deduplicated record workers"
                );
            }
            if ($this->solrUpdateWorkers) {
                $this->log->logInfo(
                    'updateRecords',
                    "Using {$this->solrUpdateWorkers} Solr workers"
                );
            }

            // Take the last indexing date now and store it when done
            if (!$sourceId && !$singleId && null === $fromDate) {
                $lastIndexingDate = time();
            } else {
                $lastIndexingDate = null;
            }

            // Init worker pools before accessing the database:
            $this->initWorkerPools();

            $trackingName = $this->db->getNewTrackingCollection();
            $this->log->logInfo(
                'updateRecords',
                "Tracking deduplicated records with collection $trackingName"
            );

            $fromTimestamp = $this->getStartTimestamp($fromDate, $lastUpdateKey);
            $from = null !== $fromTimestamp
                ? gmdate('Y-m-d H:i:s\Z', $fromTimestamp) : 'the beginning';

            $this->log
                ->logInfo('updateRecords', "Creating record list (from $from)");
            $params = [];
            if ($singleId) {
                $params['_id'] = $singleId;
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
                if ($delete) {
                    // Process only deduplicated records for deletion:
                    $params['dedup_id'] = ['$exists' => true];
                }
            }
            $total = $this->db->countRecords($params);
            $lastDisplayedCount = 0;
            $this->updatedRecords = 0;
            $this->deletedRecords = 0;
            $this->mergedComponents = 0;
            if ($noCommit) {
                $this->log->logInfo(
                    'updateRecords',
                    "$initVerb $total records (with no forced commits)"
                );
            } else {
                $this->log->logInfo(
                    'updateRecords',
                    "$initVerb $total records (max commit interval "
                        . "{$this->commitInterval} records)"
                );
            }
            $pc = new PerformanceCounter();
            $this->initBufferedUpdate();
            $prevId = null;
            $earliestRecordTimestamp = null;

            $handler = function ($record) use (
                $pc,
                $verb,
                $noCommit,
                &$lastDisplayedCount,
                $trackingName,
                &$prevId,
                $sourceId,
                $delete,
                &$earliestRecordTimestamp
            ) {
                // Track earliest encountered timestamp:
                if (isset($record['updated'])) {
                    $recordTS = $this->db->getUnixTime($record['updated']);
                    if (null === $earliestRecordTimestamp
                        || $recordTS < $earliestRecordTimestamp
                    ) {
                        $earliestRecordTimestamp = $recordTS;
                    }
                }
                // Add deduplicated records to their own processing pool:
                if (isset($record['dedup_id'])) {
                    $id = (string)$record['dedup_id'];
                    if ($prevId !== $id
                        && $this->db->addIdToTrackingCollection($trackingName, $id)
                    ) {
                        $this->workerPoolManager->addRequest(
                            'dedup',
                            $id,
                            $sourceId,
                            $delete
                        );
                        $prevId = $id;
                    }
                } else {
                    if (in_array($record['source_id'], $this->nonIndexedSources)) {
                        return true;
                    }
                    $this->workerPoolManager->addRequest('record', $record);
                }

                // Handle any results in the record pools:
                $this->handleRecords(false, $noCommit);

                $total = $this->deletedRecords + $this->updatedRecords;
                if ($total >= $lastDisplayedCount + 1000) {
                    $lastDisplayedCount = $total;
                    $pc->add($lastDisplayedCount);
                    $avg = $pc->getSpeed();
                    $this->log->logInfo(
                        'updateRecords',
                        "{$this->updatedRecords} updated,"
                            . " {$this->deletedRecords} deleted and"
                            . " {$this->mergedComponents} included child records"
                            . " $verb, $avg records/sec"
                    );
                }
            };

            $this->db->iterateRecords($params, [], $handler);

            $this->handleRecords(true, $noCommit);

            // Process dedup records if necessary:
            if (!$delete && $this->needToProcessDedupRecords($sourceId)) {
                // Note about this implementation: Since dedup records don't contain
                // information about members that are no longer part of it, we may
                // need to process a large number of records "just in case".
                $dedupParams = [];
                if ($singleId) {
                    // Cheat a bit when updating a single record, since that's
                    // usually done for testing purposes.
                    $this->log->logInfo('updateRecords', 'Processing dedup record');
                    $dedupParams['ids'] = $singleId;
                } elseif (null !== $earliestRecordTimestamp) {
                    // Offset the timestamp a few seconds to account for any
                    // delay between dedup record update timestamp and record update
                    // timestamp:
                    $earliestRecordTimestamp -= 5;
                    $dedupParams['changed']
                        = ['$gte' => $this->db->getTimestamp($fromTimestamp)];
                    $this->log->logInfo(
                        'updateRecords',
                        'Processing dedup records from '
                        . gmdate('Y-m-d\TH:i:s\Z', $earliestRecordTimestamp)
                    );
                } elseif (null !== $fromTimestamp) {
                    $dedupParams['changed']
                        = ['$gte' => $this->db->getTimestamp($fromTimestamp)];
                    $this->log->logInfo(
                        'updateRecords',
                        'Processing dedup records from '
                        . gmdate('Y-m-d\TH:i:s\Z', $fromTimestamp)
                    );
                } else {
                    $this->log->logWarning(
                        'updateRecords',
                        'Processing all dedup records -- this may be a lengthy'
                            . ' process'
                    );
                }

                $count = 0;
                $this->db->iterateDedups(
                    $dedupParams,
                    [],
                    function ($record) use ($handler, &$count) {
                        $record = [
                            'dedup_id' => (string)$record['_id']
                        ];
                        $result = $handler($record);

                        if (++$count % 10000 === 0) {
                            $this->log->logInfo(
                                'updateRecords',
                                "$count dedup records processed"
                            );
                        }

                        return $result;
                    }
                );
                $this->log->logInfo(
                    'updateRecords',
                    "Total $count dedup records processed"
                );
            }

            $this->db->dropTrackingCollection($trackingName);

            $this->handleRecords(true, $noCommit);

            // Flush update buffer and wait for any subsequent pending Solr updates
            // to complete.
            $this->flushUpdateBuffer();

            $this->log->logInfo(
                'updateRecords',
                'Waiting for any pending requests to complete...'
            );
            $this->workerPoolManager->waitUntilDone('solr');
            $this->log->logInfo('updateRecords', 'All requests complete');

            $this->log->logInfo(
                'updateRecords',
                "Total {$this->updatedRecords} updated,"
                    . " {$this->deletedRecords} deleted and"
                    . " {$this->mergedComponents} included child records"
                    . " $verb"
            );

            if (isset($lastIndexingDate)) {
                // Reset database connection since it could have timed out during
                // the process:
                $this->db->resetConnection();
                $this->setLastUpdateDate($lastUpdateKey, $lastIndexingDate);
            }

            if (!$noCommit && !$this->dumpPrefix
                && ($this->deletedRecords > 0 || $this->updatedRecords > 0)
            ) {
                $this->log->logInfo('updateRecords', 'Final commit...');
                $this->solrRequest('{ "commit": {} }', 3600);
                $this->log->logInfo('updateRecords', 'Commit complete');
            }
        } catch (\Exception $e) {
            $this->log->logFatal(
                'updateRecords',
                'Exception: ' . (string)$e
            );
        }
        $this->workerPoolManager->destroyWorkerPools();
    }

    /**
     * Determine if processing dedup records is needed for the given source
     * specification
     *
     * @param string $sourceId Source specification
     *
     * @return bool
     */
    protected function needToProcessDedupRecords(string $sourceId): bool
    {
        if (!$sourceId) {
            return true;
        }
        $sources = explode(',', $sourceId);
        foreach ($sources as $source) {
            $source = trim($source);
            if ('' === $source) {
                continue;
            }
            if (strncmp($source, '-', 1) === 0) {
                return true;
            }
            if ($this->settings[$source]['dedup'] ?? false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Handle records processed by record workers
     *
     * @param bool $block    Whether to block until all requests are completed
     * @param bool $noCommit Whether to disable automatic commits
     *
     * @return void
     */
    protected function handleRecords(bool $block, bool $noCommit): void
    {
        while ($this->workerPoolManager->checkForResults('record')
            || $this->workerPoolManager->requestsPending('record')
        ) {
            while ($this->workerPoolManager->checkForResults('record')) {
                $result = $this->workerPoolManager->getResult('record');
                $this->mergedComponents += $result['mergedComponents'];
                foreach ($result['deleted'] as $id) {
                    ++$this->deletedRecords;
                    $this->bufferedDelete($id);
                }
                foreach ($result['records'] as $record) {
                    ++$this->updatedRecords;
                    $this->bufferedUpdate($record, $noCommit);
                }
            }
            if ($block) {
                usleep(10);
            } else {
                break;
            }
        }

        // Check for results in the deduplicated record pool:
        while ($this->workerPoolManager->checkForResults('dedup')
            || $this->workerPoolManager->requestsPending('dedup')
        ) {
            while ($this->workerPoolManager->checkForResults('dedup')) {
                $result = $this->workerPoolManager->getResult('dedup');
                $this->mergedComponents += $result['mergedComponents'];
                foreach ($result['deleted'] as $id) {
                    ++$this->deletedRecords;
                    $this->bufferedDelete($id);
                }
                foreach ($result['records'] as $record) {
                    ++$this->updatedRecords;
                    $this->bufferedUpdate($record, $noCommit);
                }
            }
            if ($block) {
                usleep(10);
            } else {
                break;
            }
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
     * Process a dedup record and return results
     *
     * @param string $dedupId  Dedup record id
     * @param string $sourceId Source id to process, if any
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
                'updateRecords',
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
                    'updateRecords',
                    'Found a single record with a dedup id: '
                        . $member['solr']['id']
                );
            }
            $this->log->writelnVeryVerbose(
                'Original deduplicated but single record '
                . $member['solr']['id']
            );
            $this->log->writelnVeryVerbose(
                function () use ($member) {
                    return $this->prettyPrint($member['solr'], true);
                }
            );

            $result['records'][] = $member['solr'];
            $result['deleted'][] = $dedupRecord['_id'];
        } else {
            foreach ($members as $member) {
                $member['solr']['merged_child_boolean'] = true;

                $this->log->writelnVeryVerbose(
                    'Original deduplicated record ' . $member['solr']['id']
                );
                $this->log->writelnVeryVerbose(
                    function () use ($member) {
                        return $this->prettyPrint($member['solr'], true);
                    }
                );

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
                    'updateRecords',
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

                $this->log->writelnVerbose(
                    "Dedup record {$merged['id']}"
                );
                $this->log->writelnVeryVerbose(
                    function () use ($merged) {
                        return $this->prettyPrint($merged, true);
                    }
                );

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
                $this->log->writelnVerbose("Single record {$record['_id']}");
                $this->log->writelnVeryVerbose(
                    function () use ($data) {
                        return $this->prettyPrint($data, true);
                    }
                );

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
     *
     * @psalm-suppress RedundantCondition
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
                    $this->log->writelnVerbose(
                        'Current list has ' . count($values) . ' entries'
                    );

                    $this->log->writelnVeryVerbose(
                        function () use (&$values) {
                            $result = [];
                            arsort($values, SORT_NUMERIC);
                            foreach ($values as $key => $value) {
                                $result[] = str_pad($value, 10, ' ', STR_PAD_LEFT)
                                    . ": $key";
                            }
                            return implode(PHP_EOL, $result) . PHP_EOL . PHP_EOL;
                        }
                    );
                }
            }
        );
        arsort($values, SORT_NUMERIC);
        $this->log
            ->writelnConsole('Result list has ' . count($values) . ' entries:');
        foreach ($values as $key => $value) {
            $this->log->writelnConsole(
                str_pad($value, 10, ' ', STR_PAD_LEFT) . ": $key"
            );
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
                if ('merged' === ($record['record_format'] ?? $record['recordtype'])
                ) {
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

        $avg = $pc->getSpeed();
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
     * Get last update date (unix timestamp) by key
     *
     * @param string $stateKey State key
     *
     * @return ?int
     */
    public function getLastUpdateDate(string $stateKey)
    {
        $state = $this->db->getState($stateKey);
        if (null !== $state) {
            // Back-compatibility check:
            if (is_a($state['value'], 'MongoDB\BSON\UTCDateTime')) {
                return $state['value']->toDateTime()->getTimestamp();
            }
            return $state['value'];
        }
        return null;
    }

    /**
     * Set last update date (unix timestamp) by key
     *
     * @param string $stateKey  State key
     * @param ?int   $timestamp New timestamp or null to erase existing one
     *
     * @return void
     */
    public function setLastUpdateDate(string $stateKey, ?int $timestamp): void
    {
        if (null === $timestamp) {
            $this->db->deleteState($stateKey);
        } else {
            $this->db->saveState(
                [
                    '_id' => $stateKey,
                    'value' => $timestamp
                ]
            );
        }
    }

    /**
     * Get state key for last Solr update date
     *
     * @param bool $datePerServer Whether to use server-specific date
     *
     * @return string
     */
    public function getLastUpdateStateKey(bool $datePerServer): string
    {
        $result = 'Last Index Update';
        if ($datePerServer) {
            $result .= ' ' . $this->config['Solr']['update_url'];
        }
        return $result;
    }

    /**
     * Initialize worker pool manager and the pools for processing records and
     * Solr updates
     *
     * @return void
     */
    protected function initWorkerPools()
    {
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
        $this->workerPoolManager->createWorkerPool(
            'dedup',
            $this->dedupWorkers,
            $this->dedupWorkers,
            [$this, 'processDedupRecord']
        );
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
            $this->fieldMapper->initDataSourceConfig($dataSourceConfig);
        }
        $this->settings = [];
        foreach ($dataSourceConfig as $source => $settings) {
            if (!isset($settings['format'])) {
                throw new \Exception(
                    "Error: format not set for data source $source"
                );
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
     *
     * @psalm-suppress RedundantCondition
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
            // @phpstan-ignore-next-line
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
                $hostRecordsFound = false;
                foreach ($hostRecords as $hostRecord) {
                    $hostRecordsFound = true;
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

                if (!$hostRecordsFound) {
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
        if (!$this->workKeysField
            || !($workIdSets = $metadataRecord->getWorkIdentificationData())
        ) {
            return;
        }
        $keys = [];
        $addAnalytical = $this->config['Solr']['work_keys_from_analytical_entries']
            ?? false;
        foreach ($workIdSets as $workIds) {
            $setType = $workIds['type'] ?? 'main';
            if (!$addAnalytical && 'analytical' === $setType) {
                continue;
            }
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
        }
        if ($keys) {
            $data[$this->workKeysField] = $keys;
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
                if (is_array($datavalue)) {
                    $values = array_map(
                        function ($s) {
                            return str_replace('/', ' ', $s);
                        },
                        $datavalue
                    );
                } else {
                    $values = explode('/', $datavalue);
                }
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
                    if ('' === $value || '0' === $value || '0.0' === $value) {
                        unset($values[$key2]);
                    }
                }
                if (empty($values)) {
                    unset($data[$key]);
                } else {
                    $values = array_values(array_unique($values));
                }
            } elseif ($key !== 'fullrecord') {
                $values = $this->metadataUtils->normalizeUnicode(
                    $values,
                    $this->unicodeNormalizationForm
                );
                $values = $this->trimFieldLength($key, $values);

                if ('' === $values || '0' === $values || '0.0' === $values
                ) {
                    unset($data[$key]);
                }
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
     * Merge Solr records into a dedup record
     *
     * @param array $records Array of records to merge including the database record
     *                       and Solr array
     *
     * @return array Dedup record Solr array
     */
    protected function mergeRecords($records)
    {
        // Analyze the records to find the best record to be used as the base
        foreach ($records as &$record) {
            $fieldCount = 0;
            $uppercase = 0;
            $titleLen = isset($record['solr']['title'])
                ? mb_strlen($record['solr']['title'], 'UTF-8') : 0;
            $fields = array_intersect_key($record['solr'], $this->scoredFields);
            array_walk_recursive(
                $fields,
                function ($field) use (&$fieldCount, &$uppercase) {
                    ++$fieldCount;

                    $upper = preg_match_all('/[\p{Lu}]/u', $field);
                    $all = preg_match_all('/[\p{L}0-9]/u', $field);
                    if ($all && $upper / $all > 0.95) {
                        ++$uppercase;
                    }
                }
            );
            if (0 === $fieldCount) {
                $record['score'] = 0;
            } else {
                $baseScore = $fieldCount + $titleLen;
                $uppercaseRatio = $uppercase / $fieldCount;
                $record['score'] = 0 == $uppercaseRatio ? $fieldCount
                    : $baseScore / $uppercaseRatio;
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
     * Copy configured fields from merged dedup record to the member records
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
                // @phpstan-ignore-next-line
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
                throw HttpRequestException::fromException($e);
            }
            if ($try < $maxTries) {
                $code = $response->getStatus();
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
            throw new HttpRequestException(
                "Solr server request failed ($code). URL:\n"
                . $this->config['Solr']['update_url']
                . "\nRequest:\n$body\n\nResponse:\n"
                . (null !== $response ? $response->getBody() : ''),
                $code
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

        $code = $response->getStatus();
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
                    . $response->getBody()
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
        $this->lastCommitRecords = 0;
    }

    /**
     * Update Solr index in a batch
     *
     * @param array $data     Record metadata
     * @param bool  $noCommit Whether to not do any explicit commits
     *
     * @return bool           False when buffering, true when buffer is flushed
     */
    protected function bufferedUpdate($data, $noCommit)
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
                $this->workerPoolManager->addRequest('solr', $request);
            }
            $this->buffer = '';
            $this->bufferLen = 0;
            $this->buffered = 0;
            $result = true;
        }
        $sinceLastCommit = $this->updatedRecords - $this->lastCommitRecords;
        if (!$noCommit && !$this->dumpPrefix
            && $sinceLastCommit >= $this->commitInterval
        ) {
            $this->lastCommitRecords = $this->updatedRecords;
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
                $this->workerPoolManager->addRequest('solr', $request);
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
        if (!$return) {
            $this->log->writelnConsole($res);
        }
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
     * @param string|null $fromDate      User-given start date
     * @param string      $lastUpdateKey Last index update key in database
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
            return $this->getLastUpdateDate($lastUpdateKey);
        }
        return null;
    }
}

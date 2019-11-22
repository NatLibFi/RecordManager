<?php
/**
 * SolrUpdater Class
 *
 * PHP version 5
 *
 * Copyright (C) The National Library of Finland 2012-2019.
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
namespace RecordManager\Base\Solr;

use RecordManager\Base\Record\Factory as RecordFactory;
use RecordManager\Base\Utils\FieldMapper;
use RecordManager\Base\Utils\Logger;
use RecordManager\Base\Utils\MetadataUtils;
use RecordManager\Base\Utils\PerformanceCounter;
use RecordManager\Base\Utils\WorkerPoolManager;
use MongoDB\BSON\UTCDateTime;

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
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class SolrUpdater
{
    /**
     * Base path of Record Manager
     *
     * @var string
     */
    protected $basePath;

    /**
     * Database
     *
     * @var Database
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
     * Record factory
     *
     * @var RecordFactory
     */
    protected $recordFactory;

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
     * HTTP_Request2 configuration params
     *
     * @array
     */
    protected $httpParams = [];

    /**
     * Fields to merge when merging deduplicated records
     *
     * @var array
     */
    protected $mergedFields = [
        'institution', 'collection', 'building', 'language', 'physical', 'publisher',
        'publishDate', 'contents', 'url', 'ctrlnum', 'callnumber-raw',
        'callnumber-search',
        'author', 'author_variant', 'author_role', 'author_fuller', 'author_sort',
        'author2', 'author2_variant', 'author2_role', 'author2_fuller',
        'author_corporate', 'author_corporate_role', 'author_additional',
        'title_alt', 'title_old', 'title_new', 'dateSpan', 'series', 'series2',
        'topic', 'genre', 'geographic', 'era', 'long_lat', 'isbn', 'issn'
    ];

    /**
     * Fields to use only once if not already set when merging deduplicated records
     *
     * @var array
     */
    protected $singleFields = [
        'title', 'title_short', 'title_full', 'title_sort', 'author_sort', 'format',
        'publishDateSort', 'callnumber-first', 'callnumber-subject',
        'callnumber-label', 'callnumber-sort', 'illustrated', 'first_indexed',
        'last-indexed'
    ];

    /**
     * Fields to copy back from the merged record to all the child records
     *
     * @var array
     */
    protected $copyFromMergedRecord = [];

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
     * Field mapper class name
     *
     * @var string
     */
    protected $fieldMapperClass = '\RecordManager\Base\Utils\FieldMapper';

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
     * Constructor
     *
     * @param MongoDB       $db                 Database connection
     * @param string        $basePath           RecordManager main directory
     * @param object        $log                Logger
     * @param boolean       $verbose            Whether to output verbose messages
     * @param array         $config             Main configuration
     * @param array         $dataSourceSettings Data source settings
     * @param RecordFactory $recordFactory      Record Factory
     *
     * @throws Exception
     */
    public function __construct($db, $basePath, $log, $verbose, $config,
        $dataSourceSettings, $recordFactory
    ) {
        $this->config = $config;
        $this->db = $db;
        $this->basePath = $basePath;
        $this->log = $log;
        $this->verbose = $verbose;
        $this->recordFactory = $recordFactory;

        $this->journalFormats = isset($config['Solr']['journal_formats'])
            ? $config['Solr']['journal_formats']
            : ['Journal', 'Serial', 'Newspaper'];

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

        $this->commitInterval = isset($config['Solr']['max_commit_interval'])
            ? $config['Solr']['max_commit_interval'] : 50000;
        $this->maxUpdateRecords = isset($config['Solr']['max_update_records'])
            ? $config['Solr']['max_update_records'] : 5000;
        $this->maxUpdateSize = isset($config['Solr']['max_update_size'])
            ? $config['Solr']['max_update_size'] : 1024;
        $this->maxUpdateSize *= 1024;
        $this->maxUpdateTries = isset($config['Solr']['max_update_tries'])
            ? $config['Solr']['max_update_tries'] : 15;
        $this->updateRetryWait = isset($config['Solr']['update_retry_wait'])
            ? $config['Solr']['update_retry_wait'] : 60;
        $this->recordWorkers = isset($config['Solr']['record_workers'])
            ? $config['Solr']['record_workers'] : 0;
        $this->solrUpdateWorkers = isset($config['Solr']['solr_update_workers'])
            ? $config['Solr']['solr_update_workers'] : 0;
        $this->threadedMergedRecordUpdate
            = isset($config['Solr']['threaded_merged_record_update'])
                ? $config['Solr']['threaded_merged_record_update'] : false;
        $this->clusterStateCheckInterval
            = isset($config['Solr']['cluster_state_check_interval'])
                ? $config['Solr']['cluster_state_check_interval'] : 0;
        if (empty($config['Solr']['admin_url'])) {
            $this->clusterStateCheckInterval = 0;
            $this->log->log(
                'SolrUpdater',
                'admin_url not defined, cluster state check disabled',
                Logger::WARNING
            );
        }
        $this->datePerServer
            = !empty($config['Solr']['track_updates_per_update_url']);

        if (isset($config['HTTP'])) {
            $this->httpParams += $config['HTTP'];
        }

        if (!empty($config['Solr']['field_mapper'])) {
            $this->fieldMapperClass = $config['Solr']['field_mapper'];
        }

        $this->unicodeNormalizationForm
            = isset($config['Solr']['unicode_normalization_form'])
            ? $config['Solr']['unicode_normalization_form'] : '';

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

        // Load settings
        $this->initDatasources($dataSourceSettings);
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
     * @param string|null $fromDate      Starting date for updates (if empty
     *                                   string, last update date stored in the
     *                                   database is used and if null, all records
     *                                   are processed)
     * @param string      $sourceId      Comma-separated list of source IDs to
     *                                   update, or empty or * for all sources
     * @param string      $singleId      Process only the record with the given ID
     * @param bool        $noCommit      If true, changes are not explicitly
     *                                   committed
     * @param bool        $delete        If true, records in the given $sourceId are
     *                                   all deleted
     * @param string      $compare       If set, just compare the records with the
     *                                   ones already in the Solr index and write any
     *                                   differences in a file given in this
     *                                   parameter
     * @param string      $dumpPrefix    If specified, the Solr records are dumped
     *                                   into files and not sent to Solr
     * @param bool        $datePerServer Track last Solr update date per server url
     *
     * @return void
     */
    public function updateRecords($fromDate = null, $sourceId = '', $singleId = '',
        $noCommit = false, $delete = false, $compare = false, $dumpPrefix = '',
        $datePerServer = false
    ) {
        if ($compare && $compare != '-') {
            file_put_contents($compare, '');
        }

        $lastUpdateKey = 'Last Index Update';
        if ($datePerServer || $this->datePerServer) {
            $lastUpdateKey .= ' ' . $this->config['Solr']['update_url'];
        }

        $this->dumpPrefix = $dumpPrefix;

        $verb = $compare ? 'compared' : ($this->dumpPrefix ? 'dumped' : 'indexed');
        $initVerb = $compare
            ? 'Comparing'
            : ($this->dumpPrefix ? 'Dumping' : 'Indexing');

        $childPid = null;
        try {
            if ($this->recordWorkers) {
                $this->log->log(
                    'updateRecords', "Using {$this->recordWorkers} record workers"
                );
            }
            if ($this->solrUpdateWorkers) {
                $this->log->log(
                    'updateRecords',
                    "Using {$this->solrUpdateWorkers} Solr workers"
                );
            }

            $needCommit = false;

            if (isset($fromDate) && $fromDate) {
                $mongoFromDate = $this->db->getTimestamp(strtotime($fromDate));
            }

            if (!isset($fromDate)) {
                $state = $this->db->getState($lastUpdateKey);
                if (null !== $state) {
                    $mongoFromDate = $state['value'];
                } else {
                    unset($mongoFromDate);
                }
            }
            $from = isset($mongoFromDate)
                ? $mongoFromDate->toDatetime()->format('Y-m-d H:i:s\Z')
                : 'the beginning';
            // Take the last indexing date now and store it when done
            $lastIndexingDate = $this->db->getTimestamp();

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

            if ($processDedupRecords) {
                if (!$delete && $this->threadedMergedRecordUpdate && !$compare) {
                    $this->log->log(
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
                    if (null !== $childPid) {
                        $this->reconnectDatabase();
                    }
                    $this->InitWorkerPoolManager();
                    try {
                        $needCommit = $this->processMerged(
                            isset($mongoFromDate) ? $mongoFromDate : null,
                            $sourceId,
                            $singleId,
                            $noCommit,
                            $delete,
                            $compare
                        );
                        if (null !== $childPid) {
                            $this->deInitWorkerPoolManager();
                        }

                        if (null !== $childPid) {
                            exit($needCommit ? 1 : 0);
                        }
                    } catch (\Exception $e) {
                        $this->log->log(
                            'updateRecords',
                            'Exception from merged record processing: '
                            . $e->getMessage() . ' at ' . $e->getFile() . ':'
                            . $e->getLine(),
                            Logger::ERROR
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

            // Create Solr worker pool only after any merged record forked process
            // has been started
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
                [$this, 'processSingleRecord'],
                [$this, 'reconnectDatabase']
            );

            // Reconnect MongoDB to be sure it's used just by us
            $this->reconnectDatabase();

            $this->log->log(
                'updateRecords', "Creating individual record list (from $from)"
            );
            $params = [];
            if ($singleId) {
                $params['_id'] = $singleId;
                $params['dedup_id'] = ['$exists' => false];
                $lastIndexingDate = null;
            } else {
                if (isset($mongoFromDate)) {
                    $params['updated'] = ['$gte' => $mongoFromDate];
                }
                list($sourceOr, $sourceNor) = $this->createSourceFilter($sourceId);
                if ($sourceOr) {
                    $params['$or'] = $sourceOr;
                }
                if ($sourceNor) {
                    $params['$nor'] = $sourceNor;
                }
                $params['dedup_id'] = ['$exists' => false];
            }
            $records = $this->db->findRecords($params);
            $total = $this->db->countRecords($params);
            $count = 0;
            $lastDisplayedCount = 0;
            $mergedComponents = 0;
            $deleted = 0;
            if ($noCommit) {
                $this->log->log(
                    'updateRecords',
                    "$initVerb $total individual records (with no forced commits)"
                );
            } else {
                $this->log->log(
                    'updateRecords',
                    "$initVerb $total individual records (max commit interval "
                    . "{$this->commitInterval} records)"
                );
            }
            $pc = new PerformanceCounter();
            $this->initBufferedUpdate();
            foreach ($records as $record) {
                if (isset($this->terminate)) {
                    if ($childPid) {
                        $this->log->log(
                            'updateRecords',
                            'Waiting for child process to terminate...'
                        );
                        while (1) {
                            $pid = pcntl_waitpid($childPid, $status, WNOHANG);
                            if ($pid > 0) {
                                break;
                            }
                            sleep(1);
                        }
                    }
                    $this->log->log(
                        'updateRecords',
                        'Termination upon request (individual record handler)'
                    );
                    exit(1);
                }
                if (in_array($record['source_id'], $this->nonIndexedSources)) {
                    continue;
                }

                $this->workerPoolManager->addRequest('record', $record);

                while ($this->workerPoolManager->checkForResults('record')) {
                    $result = $this->workerPoolManager->getResult('record');
                    $mergedComponents += $result['mergedComponents'];
                    if (!$compare) {
                        foreach ($result['deleted'] as $id) {
                            ++$deleted;
                            ++$count;
                            $this->bufferedDelete($id);
                        }
                    }
                    foreach ($result['records'] as $record) {
                        ++$count;
                        if (!$compare) {
                            $this->bufferedUpdate($record, $count, $noCommit);
                        } else {
                            $this->compareWithSolrRecord($record, $compare);
                        }
                    }
                }
                if ($count >= $lastDisplayedCount + 1000) {
                    $lastDisplayedCount = $count;
                    $pc->add($count);
                    $avg = $pc->getSpeed();
                    $this->log->log(
                        'updateRecords',
                        "$count individual records (of which $deleted deleted) with "
                        . "$mergedComponents merged parts $verb, $avg records/sec"
                    );
                }

                // Check child status
                if ($childPid) {
                    $pid = pcntl_waitpid($childPid, $status, WNOHANG);
                    if (0 !== $pid) {
                        $exitCode = $pid > 0 ? pcntl_wexitstatus($status)
                            : $this->workerPoolManager
                            ->getExternalProcessExitCode($childPid);
                        $childPid = null;
                        if ($exitCode == 1) {
                            $needCommit = true;
                        } elseif ($exitCode || null === $exitCode) {
                            $this->log->log(
                                'updateRecords',
                                'Merged record update process failed, aborting',
                                Logger::ERROR
                            );
                            throw new \Exception(
                                'Merged record update process failed'
                            );
                        }
                    }
                }
            }

            while ($this->workerPoolManager->requestsPending('record')
                || $this->workerPoolManager->checkForResults('record')
            ) {
                while ($this->workerPoolManager->checkForResults('record')) {
                    $result = $this->workerPoolManager->getResult('record');
                    $mergedComponents += $result['mergedComponents'];
                    if (!$compare) {
                        foreach ($result['deleted'] as $id) {
                            ++$deleted;
                            ++$count;
                            $this->bufferedDelete($id);
                        }
                    }
                    foreach ($result['records'] as $record) {
                        ++$count;
                        if (!$compare) {
                            $this->bufferedUpdate($record, $count, $noCommit);
                        } else {
                            $this->compareWithSolrRecord($record, $compare);
                        }
                    }
                }
                usleep(10);
            }

            $this->flushUpdateBuffer();

            if (isset($lastIndexingDate) && !$compare) {
                $state = [
                    '_id' => $lastUpdateKey,
                    'value' => $lastIndexingDate
                ];
                $this->db->saveState($state);
            }
            if ($count > 0) {
                $needCommit = true;
            }
            $this->log->log(
                'updateRecords',
                "Total $count individual records (of which $deleted deleted) with "
                . "$mergedComponents merged parts $verb"
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
                            } elseif ($exitCode || null === $exitCode) {
                                $this->log->log(
                                    'updateRecords',
                                    'Merged record update process failed, aborting',
                                    Logger::ERROR
                                );
                                throw new \Exception(
                                    'Merged record update process failed'
                                );
                            }
                        } else {
                            $this->log->log(
                                'updateRecords',
                                'Could not get merged record handler results',
                                Logger::ERROR
                            );
                            $needCommit = true;
                        }
                        break;
                    }
                    sleep(1);
                }
            }

            if (!$noCommit && $needCommit && !$compare && !$this->dumpPrefix) {
                $this->log->log(
                    'updateRecords',
                    'Waiting for any pending requests to complete...'
                );
                $this->workerPoolManager->waitUntilDone('solr');
                $this->log->log('updateRecords', 'Final commit...');
                $this->solrRequest('{ "commit": {} }', 3600);
                $this->log->log('updateRecords', 'Commit complete');
            }
        } catch (\Exception $e) {
            $this->log->log(
                'updateRecords',
                'Exception: ' . $e->getMessage() . ' at ' . $e->getFile() . ':'
                . $e->getLine(),
                Logger::FATAL
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
    }

    /**
     * Process merged (deduplicated) records
     *
     * @param UTCDateTime $mongoFromDate Start date
     * @param string      $sourceId      Comma-separated list of source IDs to
     *                                   update, or empty or * for all sources
     * @param string      $singleId      Process only the record with the given ID
     * @param bool        $noCommit      If true, changes are not explicitly
     *                                   committed
     * @param bool        $delete        If true, records in the given $sourceId are
     *                                   all deleted
     * @param string      $compare       If set, just compare the records with the
     *                                   ones already in the Solr index and write any
     *                                   differences in a file given in this
     *                                   parameter
     *
     * @throws Exception
     * @return boolean Whether anything was updated
     */
    protected function processMerged(
        $mongoFromDate, $sourceId, $singleId, $noCommit, $delete, $compare
    ) {
        $verb = $compare ? 'compared' : ($this->dumpPrefix ? 'dumped' : 'indexed');
        $initVerb = $compare
            ? 'Comparing'
            : ($this->dumpPrefix ? 'Dumping' : 'Indexing');

        $params = [];
        if ($singleId) {
            $params['_id'] = $singleId;
            $params['dedup_id'] = ['$exists' => true];
            $lastIndexingDate = null;
        } else {
            if (isset($mongoFromDate)) {
                $params['updated'] = ['$gte' => $mongoFromDate];
            }
            list($sourceOr, $sourceNor) = $this->createSourceFilter($sourceId);
            if ($sourceOr) {
                $params['$or'] = $sourceOr;
            }
            if ($sourceNor) {
                $params['$nor'] = $sourceNor;
            }
            $params['dedup_id'] = ['$exists' => true];
        }

        $record = $this->db->findRecord([], ['sort' => ['updated' => -1]]);
        if (empty($record)) {
            $this->log->log('processMerged', 'No records found');
            return;
        }

        $lastRecordTime = $record['updated']->toDateTime()->getTimestamp();

        $res = $this->db->cleanupQueueCollections($lastRecordTime);

        if ($res['removed']) {
            $this->log->log(
                'processMerged',
                'Cleanup: dropped old queue collections: '
                    . implode(', ', $res['removed'])
            );
        }
        if ($res['failed']) {
            $this->log->log(
                'processMerged',
                'Failed to drop collections: ' . implode(', ', $res['failed']),
                Logger::WARNING
            );
        }

        $collectionName = $this->db->getExistingQueueCollection(
            md5(json_encode($params)),
            isset($mongoFromDate) ? $mongoFromDate->toDateTime()->format('U') : '0',
            $lastRecordTime
        );

        $from = isset($mongoFromDate)
            ? $mongoFromDate->toDateTime()->format('Y-m-d H:i:s\Z')
            : 'the beginning';

        if (!$collectionName) {
            // Install a signal handler so that we can exit cleanly if interrupted
            unset($this->terminate);
            if (function_exists('pcntl_signal')) {
                pcntl_signal(SIGINT, [$this, 'sigIntHandler']);
                $this->log->log('updateRecords', 'Interrupt handler set');
            } else {
                $this->log->log(
                    'updateRecords',
                    'Could not set an interrupt handler -- pcntl not available'
                );
            }

            $collectionName = $this->db->getNewQueueCollection(
                md5(json_encode($params)),
                isset($mongoFromDate)
                    ? $mongoFromDate->toDateTime()->format('U') : 0,
                $lastRecordTime
            );
            $this->log->log(
                'processMerged',
                "Creating queue collection $collectionName (from $from, stage 1/2)"
            );

            $prevId = null;
            $count = 0;
            $totalMergeCount = 0;
            $records = $this->db->findRecords(
                $params,
                ['projection' => ['dedup_id' => 1]]
            );
            foreach ($records as $record) {
                if (isset($this->terminate)) {
                    $this->log->log(
                        'processMerged',
                        'Termination upon request (queue collection creation)'
                    );
                    $this->db->dropQueueCollection($collectionName);
                    exit(1);
                }
                $id = $record['dedup_id'];

                if (!isset($prevId) || $prevId != $id) {
                    $this->db->addIdToQueue($collectionName, $id);
                    ++$totalMergeCount;
                    if (++$count % 10000 == 0) {
                        $this->log->log('processMerged', "$count id's processed");
                    }
                }
                $prevId = $id;
            }
            $this->log->log('processMerged', "$count id's processed");

            $this->log->log(
                'processMerged',
                "Creating queue collection $collectionName"
                . " (from $from, stage 2/2)"
            );
            $dedupParams = [];
            if ($singleId) {
                $dedupParams['ids'] = $singleId;
            } elseif (isset($mongoFromDate)) {
                $dedupParams['changed'] = ['$gte' => $mongoFromDate];
            } else {
                $this->log->log(
                    'processMerged',
                    'Processing all merge records -- this may be a lengthy process'
                    . ' if deleted records have not been purged regularly',
                    Logger::WARNING
                );
            }

            $records = $this->db->findDedups($dedupParams);
            $count = 0;
            foreach ($records as $record) {
                if (isset($this->terminate)) {
                    $this->log->log('processMerged', 'Termination upon request');
                    $this->db->dropQueueCollection($collectionName);
                    exit(1);
                }
                $id = $record['_id'];
                if (!isset($prevId) || $prevId != $id) {
                    $this->db->addIdToQueue($collectionName, $id);

                    ++$totalMergeCount;
                    if (++$count % 10000 == 0) {
                        $this->log->log(
                            'processMerged',
                            "$count merge record id's processed"
                        );
                    }
                }
                $prevId = $id;
            }
            $this->log->log(
                'processMerged',
                "$count merge record id's processed"
            );

            if ($totalMergeCount > 0) {
                $collectionName = $this->db
                    ->finalizeQueueCollection($collectionName);
            }
            $this->log->log(
                'processMerged',
                "Queue collection $collectionName complete"
            );
        } else {
            $this->log->log(
                'processMerged',
                "Using existing queue collection $collectionName"
            );
        }

        $this->workerPoolManager->createWorkerPool(
            'solr',
            $this->solrUpdateWorkers,
            $this->solrUpdateWorkers,
            [$this, 'solrRequest']
        );

        $keys = $this->db->getQueuedIds($collectionName);
        $count = 0;
        $lastDisplayedCount = 0;
        $mergedComponents = 0;
        $deleted = 0;
        $this->initBufferedUpdate();
        if ($noCommit) {
            $this->log->log(
                'processMerged',
                "$initVerb the merged records (with no forced commits)"
            );
        } else {
            $this->log->log(
                'processMerged',
                "$initVerb the merged records (max commit interval "
                . "{$this->commitInterval} records)"
            );
        }
        $pc = new PerformanceCounter();
        $this->workerPoolManager->createWorkerPool(
            'merge',
            $this->recordWorkers,
            $this->recordWorkers,
            [$this, 'processDedupRecord'],
            [$this, 'reconnectDatabase']
        );
        foreach ($keys as $key) {
            if (isset($this->terminate)) {
                throw new \Exception('Execution termination requested');
            }
            if (empty($key['_id'])) {
                continue;
            }

            $this->workerPoolManager->addRequest(
                'merge',
                (string)$key['_id'],
                $sourceId,
                $delete
            );

            while (!isset($this->terminate)
                && $this->workerPoolManager->checkForResults('merge')
            ) {
                $result = $this->workerPoolManager->getResult('merge');
                $mergedComponents += $result['mergedComponents'];
                if (!$compare) {
                    foreach ($result['deleted'] as $id) {
                        ++$deleted;
                        ++$count;
                        $this->bufferedDelete($id);
                    }
                }
                foreach ($result['records'] as $record) {
                    ++$count;
                    if (!$compare) {
                        $this->bufferedUpdate($record, $count, $noCommit);
                    } else {
                        $this->compareWithSolrRecord($record, $compare);
                    }
                }
            }
            if ($count >= $lastDisplayedCount + 1000) {
                $lastDisplayedCount = $count;
                $pc->add($count);
                $avg = $pc->getSpeed();
                $this->log->log(
                    'processMerged',
                    "$count merged records (of which $deleted deleted) with "
                    . "$mergedComponents merged parts $verb, $avg records/sec"
                );
            }
        }

        while (!isset($this->terminate)
            && ($this->workerPoolManager->requestsPending('merge')
            || $this->workerPoolManager->checkForResults('merge'))
        ) {
            while (!isset($this->terminate)
                && $this->workerPoolManager->checkForResults('merge')
            ) {
                $result = $this->workerPoolManager->getResult('merge');
                $mergedComponents += $result['mergedComponents'];
                if (!$compare) {
                    foreach ($result['deleted'] as $id) {
                        ++$deleted;
                        ++$count;
                        $this->bufferedDelete($id);
                    }
                }
                foreach ($result['records'] as $record) {
                    ++$count;
                    if (!$compare) {
                        $this->bufferedUpdate($record, $count, $noCommit);
                    } else {
                        $this->compareWithSolrRecord($record, $compare);
                    }
                }
            }
            usleep(1000);
        }

        if (!$compare) {
            $this->flushUpdateBuffer();
        }
        $this->log->log(
            'processMerged',
            "Total $count merged records (of which $deleted deleted) with "
            . "$mergedComponents merged parts $verb"
        );
        pcntl_signal(SIGINT, SIG_DFL);
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
            $this->log->log(
                'processDedupRecord',
                "Dedup record with id $dedupId missing",
                Logger::ERROR
            );
            return $result;
        }
        if ($dedupRecord['deleted']) {
            $result['deleted'][] = $dedupRecord['_id'];
            return $result;
        }

        $children = [];
        $merged = [];
        $records = $this->db->findRecords(
            ['_id' => ['$in' => (array)$dedupRecord['ids']]]
        );
        foreach ($records as $record) {
            if (in_array($record['source_id'], $this->nonIndexedSources)) {
                continue;
            }
            if ($record['deleted']
                || ($sourceId && $delete && $record['source_id'] == $sourceId)
            ) {
                $result['deleted'][] = $record['_id'];
                continue;
            }
            $data = $this->createSolrArray($record, $mergedComponents, $dedupRecord);
            if ($data === false) {
                continue;
            }
            $result['mergedComponents'] += $mergedComponents;
            $children[] = ['mongo' => $record, 'solr' => $data];
        }
        $merged = $this->mergeRecords($children);
        $this->copyMergedDataToChildren($merged, $children);

        if (count($children) == 0) {
            $this->log->log(
                'processMerged',
                "Found no records with dedup id: $dedupId, ids: "
                . implode(',', (array)$dedupRecord['ids']),
                Logger::INFO
            );
            $result['deleted'][] = $dedupRecord['_id'];
        } elseif (count($children) == 1) {
            // A dedup key exists for a single record. This should only happen
            // when a data source is being deleted...
            $child = $children[0];
            if (!$delete) {
                $this->log->log(
                    'processMerged',
                    'Found a single record with a dedup id: '
                    . $child['solr']['id'],
                    Logger::WARNING
                );
            }
            if ($this->verbose) {
                echo 'Original deduplicated but single record '
                    . $child['solr']['id'] . ":\n";
                print_r($child['solr']);
            }

            $result['records'][] = $child['solr'];
            $result['deleted'][] = $dedupRecord['_id'];
        } else {
            foreach ($children as $child) {
                $child['solr']['merged_child_boolean'] = true;

                if ($this->verbose) {
                    echo 'Original deduplicated record '
                        . $child['solr']['id'] . ":\n";
                    $this->prettyPrint($child['solr']);
                }

                $result['records'][] = $child['solr'];
            }

            // Remove duplicate fields from the merged record
            foreach ($merged as $fieldkey => $value) {
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
                            MetadataUtils::array_iunique($merged[$fieldkey])
                        );
                    }
                }
            }
            if (isset($merged['allfields'])) {
                $merged['allfields'] = array_values(
                    MetadataUtils::array_iunique($merged['allfields'])
                );
            } else {
                $this->log->log(
                    'processMerged',
                    "allfields missing in merged record for dedup key $dedupId",
                    Logger::WARNING
                );
            }

            $mergedId = (string)$dedupId;
            if (empty($merged)) {
                $result['deleted'][] = $mergedId;
            } else {
                $merged['id'] = $mergedId;
                $merged['record_format'] = $merged['recordtype'] = 'merged';
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
     * @param string $record Record
     *
     * @return array
     */
    public function processSingleRecord($record)
    {
        $result = [
            'deleted' => [],
            'records' => [],
            'mergedComponents' => 0
        ];

        if ($record['deleted']) {
            $result['deleted'][] = (string)$record['_id'];
        } else {
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
        $this->solrRequest('{ "optimize": {} }', 4 * 60 * 60);
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
        $this->log->log('countValues', "Creating record list");
        $params = ['deleted' => false];
        if ($sourceId) {
            $params['source_id'] = $sourceId;
        }
        $records = $this->db->findRecords($params);
        $this->log->log('countValues', "Counting values");
        $values = [];
        $count = 0;
        foreach ($records as $record) {
            $source = $record['source_id'];
            if (!isset($this->settings[$source])) {
                // Try to reload data source settings as they might have been updated
                // during a long run
                $this->initDatasources();
                if (!isset($this->settings[$source])) {
                    $this->log->log(
                        'countValues',
                        "No settings found for data source '$source'",
                        Logger::FATAL
                    );
                    throw new \Exception(
                        'countValues', "No settings found for data source '$source'"
                    );
                }
            }
            $settings = $this->settings[$source];
            $mergedComponents = 0;
            if ($mapped) {
                $data = $this->createSolrArray($record, $mergedComponents);
            } else {
                $metadataRecord = $this->recordFactory->createRecord(
                    $record['format'],
                    MetadataUtils::getRecordData($record, true),
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
                        ->transformToSolrArray($metadataRecord->toXML(), $params);
                } else {
                    $data = $metadataRecord->toSolrArray();
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
                $this->log->log('countValues', "$count records processed");
                if ($this->verbose) {
                    echo "Current list:\n";
                    arsort($values, SORT_NUMERIC);
                    foreach ($values as $key => $value) {
                        echo "$key: $value\n";
                    }
                    echo "\n";
                }
            }
        }
        arsort($values, SORT_NUMERIC);
        echo "Result list:\n";
        foreach ($values as $key => $value) {
            echo "$key: $value\n";
        }
    }

    /**
     * Initialize or reload data source settings
     *
     * @param array $dataSourceSettings Optional data source settings to use instead
     *                                  of reading them from the ini file
     *
     * @return void
     */
    protected function initDatasources($dataSourceSettings = null)
    {
        if (null === $dataSourceSettings) {
            $filename = "{$this->basePath}/conf/datasources.ini";
            $dataSourceSettings = parse_ini_file($filename, true);
            if (false === $dataSourceSettings) {
                $error = error_get_last();
                $message = $error['message'] ?? 'unknown error occurred';
                throw new \Exception(
                    "Could not load data source settings from file '$filename':"
                    . $message
                );
            }
        }
        $this->settings = [];
        foreach ($dataSourceSettings as $source => $settings) {
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
                = isset($settings['indexMergedParts'])
                    ? $settings['indexMergedParts'] : true;
            $this->settings[$source]['solrTransformationXSLT']
                = isset($settings['solrTransformation'])
                    && $settings['solrTransformation']
                    ? new \RecordManager\Base\Utils\XslTransformation(
                        $this->basePath . '/transformations',
                        $settings['solrTransformation']
                    ) : null;
            if (!isset($this->settings[$source]['dedup'])) {
                $this->settings[$source]['dedup'] = false;
            }

            $this->settings[$source]['extraFields'] = [];
            if (isset($settings['extrafields'])) {
                foreach ($settings['extrafields'] as $extraField) {
                    list($field, $value) = explode(':', $extraField, 2);
                    $this->settings[$source]['extraFields'][] = [$field => $value];
                }
            }

            if (isset($settings['index']) && !$settings['index']) {
                $this->nonIndexedSources[] = $source;
            }
        }

        // Create field mapper
        $this->fieldMapper = new $this->fieldMapperClass(
            $this->basePath,
            array_merge(
                isset($this->config['DefaultMappings'])
                ? $this->config['DefaultMappings'] : [],
                isset($this->config['Default Mappings'])
                ? $this->config['Default Mappings'] : []
            ),
            $dataSourceSettings
        );
    }

    /**
     * Create Solr array for the given record
     *
     * @param array   $record           Mongo record
     * @param integer $mergedComponents Number of component parts merged to the
     *                                  record
     * @param array   $dedupRecord      Mongo dedup record
     *
     * @return array
     * @throws Exception
     */
    protected function createSolrArray($record, &$mergedComponents,
        $dedupRecord = null
    ) {
        $mergedComponents = 0;

        $metadataRecord = $this->recordFactory->createRecord(
            $record['format'],
            MetadataUtils::getRecordData($record, true),
            $record['oai_id'],
            $record['source_id']
        );

        $source = $record['source_id'];
        if (!isset($this->settings[$source])) {
            // Try to reload data source settings as they might have been updated
            // during a long run
            $this->initDatasources();
            if (!isset($this->settings[$source])) {
                $this->log->log(
                    'createSolrArray',
                    "No settings found for data source '$source', record "
                    . "{$record['_id']}: " . $this->prettyPrint($record, true),
                    Logger::FATAL
                );
                throw new \Exception("No settings found for data source '$source'");
            }
        }
        $settings = $this->settings[$source];
        $hiddenComponent = MetadataUtils::isHiddenComponentPart(
            $settings, $record, $metadataRecord
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
                    $this->log->log(
                        'createSolrArray',
                        "linking_id missing for record '{$record['_id']}'",
                        Logger::ERROR
                    );
                    $warnings[] = 'linking_id missing';
                }
            } else {
                $params = [
                    'host_record_id' => $record['linking_id'],
                    'deleted' => false
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
                    $components = $this->db->findRecords($params);
                }
            }
        }

        if ($hasComponentParts && isset($components)) {
            $changeDate = null;
            $mergedComponents += $metadataRecord->mergeComponentParts(
                $components, $changeDate
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
            $data = $metadataRecord->toSolrArray();
            $this->enrich($source, $settings, $metadataRecord, $data);
        }

        $data['id'] = $this->createSolrId($record['_id']);
        if (null !== $dedupRecord && $this->dedupIdField) {
            $data[$this->dedupIdField] = (string)$dedupRecord['_id'];
        }

        // Record links between host records and component parts
        if ($metadataRecord->getIsComponentPart()) {
            $hostRecords = [];
            if ($this->db && isset($record['host_record_id'])) {
                $hostRecords = $this->db->findRecords(
                    [
                        'source_id' => $record['source_id'],
                        'linking_id' => ['$in' => (array)$record['host_record_id']]
                    ]
                );
                if (!$hostRecords) {
                    $this->log->log(
                        'createSolrArray',
                        "Any of host records ["
                        . implode(', ', (array)$record['host_record_id'])
                        . "] not found for record '" . $record['_id'] . "'",
                        Logger::WARNING
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
                    $hostMetadataRecord = $this->recordFactory->createRecord(
                        $hostRecord['format'],
                        MetadataUtils::getRecordData($hostRecord, true),
                        $hostRecord['oai_id'],
                        $hostRecord['source_id']
                    );
                    $hostTitle = $hostMetadataRecord->getTitle();
                    if ($this->hierarchyParentTitleField) {
                        $data[$this->hierarchyParentTitleField][] = $hostTitle;
                    }
                    if ($this->containerTitleField
                        && empty($data[$this->containerTitleField])
                    ) {
                        $data[$this->containerTitleField] = $hostTitle;
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
                        $record['source_id'] . '.' . $data[$field]
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
        $data = $this->fieldMapper->mapValues($source, $data);

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
                for ($i = 0; $i < count($values); $i++) {
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
            $data['allfields'] = MetadataUtils::array_iunique($all);
        }

        $data['first_indexed']
            = MetadataUtils::formatTimestamp(
                $record['created']->toDateTime()->getTimestamp()
            );
        $data['last_indexed'] = MetadataUtils::formatTimestamp(
            $record['date']->toDateTime()->getTimestamp()
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
                    MetadataUtils::normalizeKey(
                        $format, $this->unicodeNormalizationForm
                    )
                );
            }
        }

        if ($hiddenComponent) {
            $data['hidden_component_boolean'] = true;
        }

        // Work identification keys
        if ($workIds = $metadataRecord->getWorkIdentificationData()) {
            $keys = [];
            foreach ($workIds['titles'] as $titleData) {
                $title = MetadataUtils::normalizeKey(
                    $titleData['value'],
                    $this->unicodeNormalizationForm
                );
                if ('uniform' === $titleData['type']) {
                    $keys[] = "UT $title";
                } else {
                    foreach ($workIds['authors'] as $authorData) {
                        $author = MetadataUtils::normalizeKey(
                            $authorData['value'],
                            $this->unicodeNormalizationForm
                        );
                        $keys[] = "AT $author $title";
                    }
                }
            }
            foreach ($workIds['titlesAltScript'] as $titleData) {
                $title = MetadataUtils::normalizeKey(
                    $titleData['value'],
                    $this->unicodeNormalizationForm
                );
                if ('uniform' === $titleData['type']) {
                    $keys[] = "UT $title";
                } else {
                    foreach ($workIds['authorsAltScript'] as $authorData) {
                        $author = MetadataUtils::normalizeKey(
                            $authorData['value'],
                            $this->unicodeNormalizationForm
                        );
                        $keys[] = "AT $author $title";
                    }
                }
            }
            if ($keys) {
                $data['work_keys_str_mv'] = $keys;
            }
        }

        foreach ($data as $key => &$values) {
            if (is_array($values)) {
                foreach ($values as $key => &$value) {
                    $value = MetadataUtils::normalizeUnicode(
                        $value, $this->unicodeNormalizationForm
                    );
                    if (empty($value) || $value === 0 || $value === 0.0
                        || $value === '0'
                    ) {
                        unset($values[$key]);
                    }
                }
                $values = array_values(array_unique($values));
            } elseif ($key != 'fullrecord') {
                $values = MetadataUtils::normalizeUnicode(
                    $values, $this->unicodeNormalizationForm
                );
            }
            if (empty($values) || $values === 0 || $values === 0.0 || $values === '0'
            ) {
                unset($data[$key]);
            }
        }

        if (!empty($this->warningsField)) {
            $warnings = array_merge(
                $warnings, $metadataRecord->getProcessingWarnings()
            );
            if ($warnings) {
                $data[$this->warningsField] = $warnings;
            }
        }

        return $data;
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
        $useInstitution = isset($settings['institutionInBuilding'])
            ? $settings['institutionInBuilding'] : 'institution';
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
            $institutionCode = isset($settings['institution'])
                ? $settings['institution'] : '';
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
     * @param array $records Array of records to merge including the Mongo record and
     *                       Solr array
     *
     * @return array Merged Solr array
     */
    protected function mergeRecords($records)
    {
        // Analyze the records to find the best record to be used as the base
        foreach ($records as &$record) {
            $fieldCount = 0;
            $capsRatio = 0;
            $titleLen = isset($record['solr']['title'])
                ? strlen($record['solr']['title']) : 0;
            foreach ($record['solr'] as $key => $field) {
                if (!isset($this->scoredFields[$key])) {
                    continue;
                }
                foreach ((array)$field as $content) {
                    ++$fieldCount;

                    $contentLower = mb_strtolower($content, 'UTF-8');
                    $similar = similar_text($content, $contentLower);
                    // Use normal strlen since similar_text doesn't support MB
                    $length = strlen($content);
                    $capsRatio += 1 - $similar / $length;
                }
            }
            $baseScore = $fieldCount + $titleLen;
            $capsRatio /= $fieldCount;
            $record['score'] = 0 == $capsRatio ? $fieldCount
                : $baseScore / $capsRatio;
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
     * Copy configured fields from merged record to children
     *
     * @param array $merged  Merged record
     * @param array $records Array of child records
     *
     * @return void
     */
    protected function copyMergedDataToChildren($merged, &$records)
    {
        foreach ($this->copyFromMergedRecord as $copyField) {
            if (empty($merged[$copyField])) {
                continue;
            }
            foreach ($records as &$child) {
                $child['solr'][$copyField] = array_unique(
                    array_merge(
                        (array)($child['solr'][$copyField] ?? []),
                        (array)$merged[$copyField]
                    )
                );
            }
        }
    }

    /**
     * Compare given record with the already one in Solr
     *
     * @param array  $record  Record
     * @param string $logfile Log file where results are written
     *
     * @throws Exception
     * @return void
     */
    protected function compareWithSolrRecord($record, $logfile)
    {
        $ignoreFields = [
            'allfields', 'allfields_unstemmed', 'fulltext', 'fulltext_unstemmed',
            'spelling', 'spellingShingle', 'authorStr', 'author_facet',
            'publisherStr', 'publishDateSort', 'topic_browse', 'hierarchy_browse',
            'first_indexed', 'last_indexed', '_version_',
            'fullrecord', 'title_full_unstemmed', 'title_fullStr',
            'author_additionalStr'
        ];

        if (isset($this->config['Solr']['ignore_in_comparison'])) {
            $ignoreFields = array_merge(
                $ignoreFields,
                explode(',', $this->config['Solr']['ignore_in_comparison'])
            );
        }

        if (!isset($this->config['Solr']['search_url'])) {
            throw new \Exception('search_url not set in ini file Solr section');
        }

        $this->request = $this->initSolrRequest();
        $this->request->setMethod(\HTTP_Request2::METHOD_GET);
        $url = $this->config['Solr']['search_url'];
        $url .= '?q=id:"' . urlencode($record['id']) . '"&wt=json';
        $this->request->setUrl($url);

        $response = $this->request->send();
        if ($response->getStatus() != 200) {
            $this->log->log(
                'compareWithSolrRecord',
                "Could not fetch record (url $url), status code "
                . $response->getStatus()
            );
            return;
        }

        $solrResponse = json_decode($response->getBody(), true);
        $solrRecord = isset($solrResponse['response']['docs'][0])
            ? $solrResponse['response']['docs'][0]
            : [];

        $differences = '';
        $allFields = array_unique(
            array_merge(array_keys($record), array_keys($solrRecord))
        );
        $allFields = array_diff($allFields, $ignoreFields);
        foreach ($allFields as $field) {
            if (!isset($solrRecord[$field])
                || !isset($record[$field])
                || $record[$field] != $solrRecord[$field]
            ) {
                $valueDiffs = '';

                $values = isset($record[$field])
                    ? is_array($record[$field])
                        ? $record[$field]
                        : [$record[$field]]
                    : [];

                $solrValues = isset($solrRecord[$field])
                    ? is_array($solrRecord[$field])
                        ? $solrRecord[$field]
                        : [$solrRecord[$field]]
                    : [];

                foreach ($solrValues as $solrValue) {
                    if (!in_array($solrValue, $values)) {
                        $valueDiffs .= "--- $solrValue" . PHP_EOL;
                    }
                }
                foreach ($values as $value) {
                    if (!in_array($value, $solrValues)) {
                        $valueDiffs .= "+++ $value " . PHP_EOL;
                    }
                }

                if ($valueDiffs) {
                    $differences .= "$field:" . PHP_EOL . $valueDiffs;
                }
            }
        }
        if ($differences) {
            $msg = "Record {$record['id']} would be changed: " . PHP_EOL
                . $differences . PHP_EOL;
            if ($logfile == '-') {
                echo $msg;
            } else {
                file_put_contents($logfile, $msg, FILE_APPEND);
            }
        }
    }

    /**
     * Initialize a Solr request object
     *
     * @param int $timeout Timeout in seconds (optional)
     *
     * @return \HTTP_Request2
     */
    protected function initSolrRequest($timeout = null)
    {
        $request = new \HTTP_Request2(
            $this->config['Solr']['update_url'],
            \HTTP_Request2::METHOD_POST,
            $this->httpParams
        );
        if ($timeout !== null) {
            $request->setConfig('timeout', $timeout);
        }
        $request->setHeader('Connection', 'Keep-Alive');
        $request->setHeader('User-Agent', 'RecordManager');
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
            $this->request = $this->initSolrRequest($timeout);
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
                    $this->log->log(
                        'solrRequest',
                        'Solr server request failed (' . $e->getMessage()
                        . "), retrying in {$this->updateRetryWait} seconds...",
                        Logger::WARNING
                    );
                    sleep($this->updateRetryWait);
                    continue;
                }
                throw $e;
            }
            if ($try < $maxTries) {
                $code = null === $response ? 999 : $response->getStatus();
                if ($code >= 300) {
                    $this->log->log(
                        'solrRequest',
                        "Solr server request failed ($code), retrying in "
                        . "{$this->updateRetryWait} seconds...",
                        Logger::WARNING
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
                    $this->log->log(
                        'waitForClusterStateOk',
                        "Cluster state check failed after {$this->maxUpdateTries}"
                        . ' attempts',
                        Logger::ERROR
                    );
                    return false;
                }
            }
            $this->log->log(
                'waitForClusterStateOk',
                'Retrying cluster state check in'
                . " {$this->clusterStateCheckInterval} seconds...",
                Logger::WARNING
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
        $request = $this->initSolrRequest();
        $url = $this->config['Solr']['admin_url'] . '/zookeeper'
            . '?wt=json&detail=true&path=%2Fclusterstate.json&view=graph';
        $request->setUrl($url);
        try {
            $response = $request->send();
        } catch (\Exception $e) {
            $this->log->log(
                'checkClusterState',
                "Solr admin request '$url' failed (" . $e->getMessage() . ')',
                Logger::ERROR
            );
            $this->clusterState = 'error';
            return 'error';
        }

        $code = null === $response ? 999 : $response->getStatus();
        if (200 !== $code) {
            $this->log->log(
                'checkClusterState',
                "Solr admin request '$url' failed ($code)",
                Logger::ERROR
            );
            $this->clusterState = 'error';
            return 'error';
        }
        $state = json_decode($response->getBody(), true);
        if (null === $state) {
            $this->log->log(
                'checkClusterState',
                'Unable to decode zookeeper status from response: '
                . (null !== $response ? $response->getBody() : ''),
                Logger::ERROR
            );
            $this->clusterState = 'error';
            return 'error';
        }
        $data = json_decode($state['znode']['data'], true);
        if (null === $data) {
            $this->log->log(
                'checkClusterState',
                'Unable to decode node data from ' . $state['znode']['data'],
                Logger::ERROR
            );
            $this->clusterState = 'error';
            return 'error';
        }
        foreach ($data as $collectionName => $collection) {
            foreach ($collection['shards'] as $shardName => $shard) {
                if (!in_array($shard['state'], $this->normalShardStatuses)) {
                    $this->log->log(
                        'checkClusterState',
                        "Collection $collectionName shard $shardName:"
                        . " Not in usable state: {$shard['state']}",
                        Logger::WARNING
                    );
                    $this->clusterState = 'degraded';
                    return 'degraded';
                }
                foreach ($shard['replicas'] as $replica) {
                    if ('active' !== $replica['state']) {
                        $this->log->log(
                            'checkClusterState',
                            "Collection $collectionName shard $shardName: Core"
                            . " {$replica['core']} at {$replica['node_name']}"
                            . " not in active state: {$replica['state']}",
                            Logger::WARNING
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
            $this->log->log(
                'bufferedUpdate',
                'Could not convert to JSON: ' . var_export($data, true),
                Logger::FATAL
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
            $this->log->log(
                'bufferedUpdate', 'Waiting for any pending requests to complete...'
            );
            $this->workerPoolManager->waitUntilDone('solr');
            $this->log->log('bufferedUpdate', 'Intermediate commit...');
            $this->solrRequest('{ "commit": {} }', 3600);
            $this->log->log('bufferedUpdate', 'Intermediate commit complete');
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
        $this->bufferedDeletions[] = '"delete":{"id":"' . $id . '"}';
        if (count($this->bufferedDeletions) >= 1000) {
            $request = "{" . implode(',', $this->bufferedDeletions) . "}";
            $this->workerPoolManager->addRequest(
                'solr',
                $request
            );
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
     *
     * @return void
     */
    protected function enrich($source, $settings, $record, &$data)
    {
        $enrichments = array_unique(
            array_merge(
                (array)($this->config['Solr']['enrichment'] ?? []),
                (array)($settings['enrichments'] ?? [])
            )
        );
        foreach ($enrichments as $enrichment) {
            if (!isset($this->enrichments[$enrichment])) {
                $className = $enrichment;
                if (strpos($className, '\\') === false) {
                    $className = "\RecordManager\Base\Enrichment\\$className";
                }
                $this->enrichments[$enrichment] = new $className(
                    $this->db, $this->log, $this->config
                );
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
     * Reconnect to the Mongo database after forking to avoid trouble with multiple
     * processes sharing the connection.
     *
     * Public visibility so that the worker init can call this
     *
     * @return void
     */
    public function reconnectDatabase()
    {
        $this->db->reconnectDatabase();
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
        if (isset($parts[1])
            && !empty($this->settings[$parts[0]]['indexUnprefixedIds'])
        ) {
            return $parts[1];
        }
        return $recordId;
    }

    /**
     * Parse source parameter to Mongo selectors
     *
     * @param string $sourceIds A single source id or a comma-separated list of
     *                          sources or exclusion filters
     *
     * @return array of arrays $or and $nor filters
     */
    protected function createSourceFilter($sourceIds)
    {
        if (!$sourceIds) {
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
                    $regex = new \MongoDB\BSON\Regex($matches[1]);
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
}

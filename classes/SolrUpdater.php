<?php
/**
 * SolrUpdater Class
 *
 * PHP version 5
 *
 * Copyright (C) The National Library of Finland 2012-2017.
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
    declare(ticks = 10);
}
require_once 'BaseRecord.php';
require_once 'MetadataUtils.php';
require_once 'PerformanceCounter.php';
require_once 'WorkerPoolManager.php';

use MongoDB\BSON\UTCDateTime;

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
    protected $db;
    protected $log;
    protected $settings;
    protected $buildingHierarchy;
    protected $verbose;
    protected $counts;
    protected $journalFormats;
    protected $eJournalFormats;
    protected $allJournalFormats;
    protected $terminate;
    protected $dumpPrefix = '';

    protected $commitInterval;
    protected $maxUpdateRecords;
    protected $maxUpdateSize;
    protected $maxUpdateTries;
    protected $updateRetryWait;
    protected $backgroundUpdates;
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
     * @var HTTP_Request2
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
     * Constructor
     *
     * @param MongoDB $db       Database connection
     * @param string  $basePath RecordManager main directory
     * @param object  $log      Logger
     * @param boolean $verbose  Whether to output verbose messages
     *
     * @throws Exception
     */
    public function __construct($db, $basePath, $log, $verbose)
    {
        global $configArray;

        $this->db = $db;
        $this->basePath = $basePath;
        $this->log = $log;
        $this->verbose = $verbose;
        $this->counts = isset($configArray['Mongo']['counts'])
            && $configArray['Mongo']['counts'];

        $this->journalFormats = isset($configArray['Solr']['journal_formats'])
            ? $configArray['Solr']['journal_formats']
            : ['Journal', 'Serial', 'Newspaper'];

        $this->eJournalFormats = isset($configArray['Solr']['ejournal_formats'])
            ? $configArray['Solr']['journal_formats']
            : ['eJournal'];

        $this->allJournalFormats
            = array_merge($this->journalFormats, $this->eJournalFormats);

        if (isset($configArray['Solr']['hierarchical_facets'])) {
            $this->hierarchicalFacets = $configArray['Solr']['hierarchical_facets'];
        }
        // Special case: building hierarchy
        $this->buildingHierarchy = in_array('building', $this->hierarchicalFacets);

        if (isset($configArray['Solr']['merged_fields'])) {
            $this->mergedFields
                = explode(',', $configArray['Solr']['merged_fields']);
        }
        $this->mergedFields = array_flip($this->mergedFields);

        if (isset($configArray['Solr']['single_fields'])) {
            $this->singleFields
                = explode(',', $configArray['Solr']['single_fields']);
        }
        $this->singleFields = array_flip($this->singleFields);

        if (isset($configArray['Solr']['warnings_field'])) {
            $this->warningsField = $configArray['Solr']['warnings_field'];
        }

        $this->commitInterval = isset($configArray['Solr']['max_commit_interval'])
            ? $configArray['Solr']['max_commit_interval'] : 50000;
        $this->maxUpdateRecords = isset($configArray['Solr']['max_update_records'])
            ? $configArray['Solr']['max_update_records'] : 5000;
        $this->maxUpdateSize = isset($configArray['Solr']['max_update_size'])
            ? $configArray['Solr']['max_update_size'] : 1024;
        $this->maxUpdateSize *= 1024;
        $this->maxUpdateTries = isset($configArray['Solr']['max_update_tries'])
            ? $configArray['Solr']['max_update_tries'] : 15;
        $this->updateRetryWait = isset($configArray['Solr']['update_retry_wait'])
            ? $configArray['Solr']['update_retry_wait'] : 60;
        $this->backgroundUpdates = isset($configArray['Solr']['background_update'])
            ? $configArray['Solr']['background_update'] : 0;
        $this->recordWorkers = isset($configArray['Solr']['record_workers'])
            ? $configArray['Solr']['record_workers'] : 0;
        $this->solrUpdateWorkers = isset($configArray['Solr']['solr_update_workers'])
            ? $configArray['Solr']['solr_update_workers'] : 0;
        $this->threadedMergedRecordUpdate
            = isset($configArray['Solr']['threaded_merged_record_update'])
                ? $configArray['Solr']['threaded_merged_record_update'] : false;
        $this->clusterStateCheckInterval
            = isset($configArray['Solr']['cluster_state_check_interval'])
                ? $configArray['Solr']['cluster_state_check_interval'] : 0;
        if (empty($configArray['Solr']['admin_url'])) {
            $this->clusterStateCheckInterval = 0;
            $this->log->log(
                'SolrUpdater',
                'admin_url not defined, cluster state check disabled',
                Logger::WARNING
            );
        }

        if (isset($configArray['HTTP'])) {
            $this->httpParams += $configArray['HTTP'];
        }

        // Load settings and mapping files
        $this->loadDatasources();
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
     * @param string|null $fromDate   Starting date for updates (if empty
     *                                string, last update date stored in the database
     *                                is used and if null, all records are processed)
     * @param string      $sourceId   Comma-separated list of source IDs to update,
     *                                or empty or * for all sources
     * @param string      $singleId   Process only the record with the given ID
     * @param bool        $noCommit   If true, changes are not explicitly committed
     * @param bool        $delete     If true, records in the given $sourceId are all
     *                                deleted
     * @param string      $compare    If set, just compare the records with the ones
     *                                already in the Solr index and write any
     *                                differences in a file given in this parameter
     * @param string      $dumpPrefix If specified, the Solr records are dumped into
     *                                files and not sent to Solr.
     *
     * @return void
     */
    public function updateRecords($fromDate = null, $sourceId = '', $singleId = '',
        $noCommit = false, $delete = false, $compare = false, $dumpPrefix = ''
    ) {
        if ($compare && $compare != '-') {
            file_put_contents($compare, '');
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
                    "Using {$this->solrUpdateWorkers} Solr update workers"
                );
            }

            $needCommit = false;

            if (isset($fromDate) && $fromDate) {
                $mongoFromDate = new UTCDateTime(strtotime($fromDate) * 1000);
            }

            if (!isset($fromDate)) {
                $state = $this->db->state->findOne(['_id' => 'Last Index Update']);
                if ($state) {
                    $mongoFromDate = $state['value'];
                } else {
                    unset($mongoFromDate);
                }
            }
            $from = isset($mongoFromDate)
                ? $mongoFromDate->toDatetime()->format('Y-m-d H:i:s')
                : 'the beginning';
            // Take the last indexing date now and store it when done
            $lastIndexingDate = new UTCDateTime(time() * 1000);

            // Only process merged records if any of the selected sources has
            // deduplication enabled
            $processDedupRecords = true;
            if ($sourceId) {
                $processDedupRecords = false;
                $sources = explode(',', $sourceId);
                foreach ($sources as $source) {
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
                        throw new Exception(
                            "Could not fork merged record background update child"
                        );
                    }
                }

                if (!$childPid) {
                    if (null !== $childPid) {
                        $this->reconnectMongoDb();
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
                    } catch (Exception $e) {
                        if (null === $childPid) {
                            throw $e;
                        }
                        $this->log->log(
                            'updateRecords',
                            'Exception from merged record processing: '
                            . $e->getMessage()
                        );
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
                [$this, 'reconnectMongoDB']
            );

            // Reconnect MongoDB to be sure it's used just by us
            $this->reconnectMongoDB();

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
                if ($sourceId) {
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
                $params['dedup_id'] = ['$exists' => false];
                $params['update_needed'] = false;
            }
            $records = $this->db->record->find($params, ['noCursorTimeout' => true]);
            $total = $this->counts ? $this->db->record->count($params) : 'the';
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
                            $this->compareWithSolrRecord($child['solr'], $compare);
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
                                "Merged record update process failed, "
                                . "aborting",
                                Logger::ERROR
                            );
                            throw new Exception(
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
                            $this->compareWithSolrRecord($child['solr'], $compare);
                        }
                    }
                }
                usleep(10);
            }

            $this->flushUpdateBuffer();

            if (isset($lastIndexingDate) && !$compare) {
                $state
                    = ['_id' => "Last Index Update", 'value' => $lastIndexingDate];
                $this->db->state->replaceOne(
                    ['_id' => $state['_id']],
                    $state,
                    ['upsert' => true]
                );
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
                            $needCommit = 1 === $exitCode;
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
                    'updateRecords', 'Waiting for all requests to complete...'
                );
                $this->workerPoolManager->waitUntilDone('solr');
                $this->log->log('updateRecords', 'Final commit...');
                $this->solrRequest('{ "commit": {} }', 3600);
                $this->log->log('updateRecords', 'Commit complete');
            }
        } catch (Exception $e) {
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
        global $configArray;

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
            if ($sourceId) {
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
            if (!$delete) {
                $params['update_needed'] = false;
            }
            $params['dedup_id'] = ['$exists' => true];
        }

        $collectionName = 'mr_record_' . md5(json_encode($params));
        if (isset($fromDate)) {
            $collectionName .= '_' . date('Ymd', strtotime($fromDate));
        } else {
            $collectionName .= '_0';
        }
        $record = $this->db->record->findOne([], ['sort' => ['updated' => -1]]);
        if (empty($record)) {
            $this->log->log('processMerged', 'No records found');
            return;
        }
        $lastRecordTime = $record['updated']->toDateTime()->getTimestamp();
        $collectionName .= "_$lastRecordTime";

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

        // Check if we already have a suitable collection and drop too old
        // collections
        $collectionExists = false;
        foreach ($this->db->listCollections() as $collection) {
            $collection = $collection->getName();
            if ($collection == $collectionName) {
                $collectionExists = true;
            } else {
                $nameParts = explode('_', $collection);
                $collTime = isset($nameParts[4]) ? $nameParts[4] : null;
                if (strncmp($collection, 'mr_record_', 10) == 0
                    && is_numeric($collTime)
                    && $collTime != $lastRecordTime
                    && $collTime < time() - 60 * 60 * 24 * 7
                ) {
                    $this->log->log(
                        'processMerged',
                        "Cleanup: dropping old merged record collection $collection"
                    );
                    try {
                        $this->db->selectCollection($collection)->drop();
                    } catch (Exception $e) {
                        $this->log->log(
                            'processMerged',
                            "Failed to drop collection $collection: "
                            . $e->getMessage(),
                            Logger::WARNING
                        );
                    }
                }
            }
        }

        $from = isset($mongoFromDate)
            ? $mongoFromDate->toDateTime()->format('Y-m-d H:i:s')
            : 'the beginning';

        if (!$collectionExists) {
            $this->log->log(
                'processMerged',
                "Creating merged record list $collectionName (from $from, stage 1/2)"
            );

            $prevId = null;
            $tmpCollectionName = $collectionName . '_tmp_' . getmypid();
            $collection = $this->db->selectCollection($tmpCollectionName);
            $count = 0;
            $totalMergeCount = 0;
            $records = $this->db->record->find($params, ['noCursorTimeout' => true]);
            $writeConcern = new MongoDB\Driver\WriteConcern(0);
            foreach ($records as $record) {
                if (isset($this->terminate)) {
                    $this->log->log(
                        'processMerged',
                        'Termination upon request (merged record list)'
                    );
                    $collection->drop();
                    exit(1);
                }
                $id = $record['dedup_id'];
                if (isset($record['update_needed']) && $record['update_needed']) {
                    $this->log->log(
                        'processMerged',
                        "Record {$record['_id']} needs deduplication and would not"
                        . " be processed in a normal update",
                        Logger::WARNING
                    );
                }

                if (!isset($prevId) || $prevId != $id) {
                    $collection->replaceOne(
                        ['_id' => $id],
                        ['_id' => $id],
                        [
                            'upsert' => true,
                            'writeConcern' => $writeConcern
                        ]
                    );
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
                "Creating merged record list $collectionName"
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

            $records = $this->db->dedup->find(
                $dedupParams, ['noCursorTimeout' => true]
            );
            $count = 0;
            $writeConcern = new MongoDB\Driver\WriteConcern(0);
            foreach ($records as $record) {
                if (isset($this->terminate)) {
                    $this->log->log('processMerged', 'Termination upon request');
                    $collection->drop();
                    exit(1);
                }
                $id = $record['_id'];
                if (!isset($prevId) || $prevId != $id) {
                    $collection->replaceOne(
                        ['_id' => $id],
                        ['_id' => $id],
                        [
                            'upsert' => true,
                            'writeConcern' => $writeConcern
                        ]
                    );

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
                // renameCollection requires admin priviledge
                $mongo = new \MongoDB\Client($configArray['Mongo']['url']);
                $dbName = $configArray['Mongo']['database'];
                $res = $mongo->admin->command(
                    [
                        'renameCollection' => $dbName . '.' . $tmpCollectionName,
                        'to' => $dbName . '.' . $collectionName
                    ]
                );
                $resArray = $res->toArray();
                if (!$resArray[0]['ok']) {
                    throw new Exception(
                        'Renaming collection failed: ' . print_r($resArray, true)
                    );
                }
            }
        } else {
            $this->log->log(
                'processMerged',
                "Using existing merged record list $collectionName"
            );
        }

        $this->workerPoolManager->createWorkerPool(
            'solr',
            $this->solrUpdateWorkers,
            $this->solrUpdateWorkers,
            [$this, 'solrRequest']
        );

        $keys = $this->db->{$collectionName}->find([], ['noCursorTimeout' => true]);
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
            [$this, 'reconnectMongoDb']
        );
        foreach ($keys as $key) {
            if (isset($this->terminate)) {
                throw new Exception('Execution termination requested');
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
        $dedupRecord = $this->db->dedup->findOne(
            ['_id' => new \MongoDB\BSON\ObjectID($dedupId)]
        );
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
        $records = $this->db->record->find(
            ['_id' => ['$in' => (array)$dedupRecord['ids']]],
            ['noCursorTimeout' => true]
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
            $data = $this->createSolrArray($record, $mergedComponents);
            if ($data === false) {
                continue;
            }
            $result['mergedComponents'] += $mergedComponents;
            $merged = $this->mergeRecords($merged, $data);
            $children[] = ['mongo' => $record, 'solr' => $data];
        }

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
                $merged['recordtype'] = 'merged';
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

        if (isset($record['update_needed']) && $record['update_needed']) {
            $this->log->log(
                'processSingleRecord',
                "Record {$record['_id']} needs deduplication and would not"
                . " be processed in a normal update",
                Logger::WARNING
            );
        }

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
        $records = $this->db->record->find($params, ['noCursorTimeout' => true]);
        $this->log->log('countValues', "Counting values");
        $values = [];
        $count = 0;
        foreach ($records as $record) {
            $source = $record['source_id'];
            if (!isset($this->settings[$source])) {
                // Try to reload data source settings as they might have been updated
                // during a long run
                $this->loadDatasources();
                if (!isset($this->settings[$source])) {
                    $this->log->log(
                        'countValues',
                        "No settings found for data source '$source'",
                        Logger::FATAL
                    );
                    throw new Exception(
                        'countValues', "No settings found for data source '$source'"
                    );
                }
            }
            $settings = $this->settings[$source];
            $mergedComponents = 0;
            if ($mapped) {
                $data = $this->createSolrArray($record, $mergedComponents);
            } else {
                $metadataRecord = RecordFactory::createRecord(
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
                    $prependTitleWithSubtitle
                        = isset($settings['prepend_title_with_subtitle'])
                            ? $settings['prepend_title_with_subtitle'] : true;
                    $data = $metadataRecord->toSolrArray($prependTitleWithSubtitle);
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
        foreach ($values as $key => $value) {
            echo "$key: $value\n";
        }
    }

    /**
     * Map source format to Solr format
     *
     * @param string $source Source ID
     * @param string $format Format
     *
     * @return string Mapped format string
     */
    public function mapFormat($source, $format)
    {
        $settings = $this->settings[$source];

        if (isset($settings['mappingFiles']['format'])) {
            $mappingFile = $settings['mappingFiles']['format'];
            $map = $mappingFile[0]['map'];
            if (!empty($format)) {
                $format = $this->mapValue($format, $mappingFile);
                return is_array($format) ? $format[0] : $format;
            } elseif (isset($map['##empty'])) {
                return $map['##empty'];
            } elseif (isset($map['##emptyarray'])) {
                return $map['##emptyarray'];
            }
        }
        return $format;
    }

    /**
     * Load data source settings
     *
     * @return void
     */
    protected function loadDatasources()
    {
        global $configArray;

        $dataSourceSettings
            = parse_ini_file("{$this->basePath}/conf/datasources.ini", true);
        $this->settings = [];
        foreach ($dataSourceSettings as $source => $settings) {
            if (!isset($settings['institution'])) {
                throw new Exception("Error: institution not set for $source\n");
            }
            if (!isset($settings['format'])) {
                throw new Exception("Error: format not set for $source\n");
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
                    ? new XslTransformation(
                        $this->basePath . '/transformations',
                        $settings['solrTransformation']
                    ) : null;
            if (!isset($this->settings[$source]['dedup'])) {
                $this->settings[$source]['dedup'] = false;
            }
            $this->settings[$source]['mappingFiles'] = [];

            // Use default mappings as the basis
            $allMappings = isset($configArray['DefaultMappings'])
                ? $configArray['DefaultMappings'] : [];

            // Apply data source specific overrides
            foreach ($settings as $key => $value) {
                if (substr($key, -8, 8) == '_mapping') {
                    $field = substr($key, 0, -8);
                    if (empty($value)) {
                        unset($allMappings[$field]);
                    } else {
                        $allMappings[$field] = $value;
                    }
                }
            }

            foreach ($allMappings as $field => $values) {
                foreach ((array)$values as $value) {
                    $parts = explode(',', $value, 2);
                    $filename = $parts[0];
                    $type = isset($parts[1]) ? $parts[1] : 'normal';
                    $this->settings[$source]['mappingFiles'][$field][] = [
                        'type' => $type,
                        'map' => $this->readMappingFile(
                            $this->basePath . '/mappings/' . $filename
                        )
                    ];
                }
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
    }

    /**
     * Create Solr array for the given record
     *
     * @param array   $record           Mongo record
     * @param integer $mergedComponents Number of component parts merged to the
     * record
     *
     * @return string[]
     * @throws Exception
     */
    protected function createSolrArray($record, &$mergedComponents)
    {
        global $configArray;

        $mergedComponents = 0;

        $metadataRecord = RecordFactory::createRecord(
            $record['format'],
            MetadataUtils::getRecordData($record, true),
            $record['oai_id'],
            $record['source_id']
        );

        $source = $record['source_id'];
        if (!isset($this->settings[$source])) {
            // Try to reload data source settings as they might have been updated
            // during a long run
            $this->loadDatasources();
            if (!isset($this->settings[$source])) {
                $this->log->log(
                    'createSolrArray',
                    "No settings found for data source '$source', record "
                    . "{$record['_id']}: " . $this->prettyPrint($record, true),
                    Logger::FATAL
                );
                throw new Exception("No settings found for data source '$source'");
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
                $this->log->log(
                    'createSolrArray',
                    "linking_id missing for record '{$record['_id']}'",
                    Logger::ERROR
                );
                $warnings[] = 'linking_id missing';
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
                $component = $this->db->record->findOne($params);
                $hasComponentParts = !empty($component);
                if ($hasComponentParts) {
                    $components = $this->db->record->find($params);
                }

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
                if (!$merge) {
                    unset($components);
                }
            }
        }

        if ($hasComponentParts && isset($components)) {
            $mergedComponents += $metadataRecord->mergeComponentParts($components);
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
            $prependTitleWithSubtitle
                = isset($settings['prepend_title_with_subtitle'])
                    ? $settings['prepend_title_with_subtitle'] : true;
            $data = $metadataRecord->toSolrArray($prependTitleWithSubtitle);
            $this->enrich($source, $settings, $metadataRecord, $data);
        }

        $data['id'] = $record['_id'];

        // Record links between host records and component parts
        if ($metadataRecord->getIsComponentPart()) {
            $hostRecord = null;
            if (isset($record['host_record_id']) && $this->db) {
                $hostRecord = $this->db->record->findOne(
                    [
                        'source_id' => $record['source_id'],
                        'linking_id' => $record['host_record_id']
                    ]
                );
            }
            if (!$hostRecord) {
                if (isset($record['host_record_id'])) {
                    $this->log->log(
                        'createSolrArray',
                        "Host record '" . $record['host_record_id']
                        . "' not found for record '" . $record['_id'] . "'",
                        Logger::WARNING
                    );
                }
                $warnings[] = 'host record missing';
                $data['container_title'] = $metadataRecord->getContainerTitle();
            } else {
                $data['hierarchy_parent_id'] = $hostRecord['_id'];
                $hostMetadataRecord = RecordFactory::createRecord(
                    $hostRecord['format'],
                    MetadataUtils::getRecordData($hostRecord, true),
                    $hostRecord['oai_id'],
                    $hostRecord['source_id']
                );
                $data['container_title'] = $data['hierarchy_parent_title']
                    = $hostMetadataRecord->getTitle();
            }
            $data['container_volume'] = $metadataRecord->getVolume();
            $data['container_issue'] = $metadataRecord->getIssue();
            $data['container_start_page'] = $metadataRecord->getStartPage();
            $data['container_reference'] = $metadataRecord->getContainerReference();
        } else {
            // Add prefixes to hierarchy linking fields
            foreach (['hierarchy_top_id', 'hierarchy_parent_id', 'is_hierarchy_id']
                as $field
            ) {
                if (isset($data[$field]) && $data[$field]) {
                    $data[$field] = $record['source_id'] . '.' . $data[$field];
                }
            }
        }
        if ($hasComponentParts) {
            $data['is_hierarchy_id'] = $record['_id'];
            $data['is_hierarchy_title'] = $metadataRecord->getTitle();
        }

        if (!isset($data['institution'])) {
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
        foreach ($settings['mappingFiles'] as $field => $mappingFile) {
            if (isset($data[$field]) && !empty($data[$field])) {
                if (is_array($data[$field])) {
                    $newValues = [];
                    foreach ($data[$field] as $value) {
                        $replacement = $this->mapValue($value, $mappingFile);
                        if (is_array($replacement)) {
                            $newValues = array_merge($newValues, $replacement);
                        } else {
                            $newValues[] = $replacement;
                        }
                    }
                    if (null !== $newValues) {
                        $data[$field] = array_values(array_unique($newValues));
                    }
                } else {
                    $data[$field] = $this->mapValue($data[$field], $mappingFile);
                }
            } elseif (isset($mappingFile[0]['map']['##empty'])) {
                $data[$field] = $mappingFile[0]['map']['##empty'];
            } elseif (isset($mappingFile[0]['map']['##emptyarray'])) {
                $data[$field] = [$mappingFile[0]['map']['##emptyarray']];
            }
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
                $values = explode('/', $datavalue);
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
                    $key, ['fullrecord', 'thumbnail', 'id', 'recordtype', 'ctrlnum']
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
        $data['recordtype'] = $record['format'];
        if (!isset($data['fullrecord'])) {
            $data['fullrecord'] = $metadataRecord->toXML();
        }
        if (!is_array($data['format'])) {
            $data['format'] = [$data['format']];
        }

        if (isset($configArray['Solr']['format_in_allfields'])
            && $configArray['Solr']['format_in_allfields']
        ) {
            foreach ($data['format'] as $format) {
                // Replace numbers since they may be be considered word boundaries
                $data['allfields'][] = str_replace(
                    ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'],
                    ['ax', 'bx', 'cx', 'dx', 'ex', 'fx', 'gx', 'hx', 'ix', 'jx'],
                    MetadataUtils::normalize($format)
                );
            }
        }

        if ($hiddenComponent) {
            $data['hidden_component_boolean'] = true;
        }

        foreach ($data as $key => &$values) {
            if (is_array($values)) {
                foreach ($values as $key => &$value) {
                    $value = MetadataUtils::normalizeUnicode($value);
                    if (empty($value) || $value === 0 || $value === 0.0
                        || $value === '0'
                    ) {
                        unset($values[$key]);
                    }
                }
                $values = array_values(array_unique($values));
            } elseif ($key != 'fullrecord') {
                $values = MetadataUtils::normalizeUnicode($values);
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
     * Map a value using a mapping file
     *
     * @param mixed $value       Value to map
     * @param array $mappingFile Mapping file
     * @param int   $index       Mapping index for sub-entry mappings
     *
     * @return mixed
     */
    protected function mapValue($value, $mappingFile, $index = 0)
    {
        if (is_array($value)) {
            // Map array parts (predefined hierarchy) separately
            $newValue = [];
            foreach ($value as $i => &$v) {
                $v = $this->mapValue($v, $mappingFile, $i);
                if ('' === $v) {
                    // If we get an empty string from any level, stop here
                    break;
                }
                $newValue[] = $v;
            }
            return implode('/', $newValue);
        }
        $map = isset($mappingFile[$index]['map']) ? $mappingFile[$index]['map']
            : $mappingFile[0]['map'];
        $type = isset($mappingFile[$index]['type'])
            ? $mappingFile[$index]['type'] : $mappingFile[0]['type'];
        if ('regexp' == $type) {
            foreach ($map as $pattern => $replacement) {
                $pattern = addcslashes($pattern, '/');
                $newValue = preg_replace(
                    "/$pattern/u", $replacement, $value, -1, $count
                );
                if ($count > 0) {
                    return $newValue;
                }
            }
            return $value;
        }
        $replacement = $value;
        if (isset($map[$value])) {
            $replacement = $map[$value];
        } elseif (isset($map['##default'])) {
            $replacement = $map['##default'];
        }
        return $replacement;
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
            $institutionCode = $settings['institution'] . '/' . $source;
            break;
        default:
            $institutionCode = $settings['institution'];
            break;
        }
        if ($institutionCode) {
            if (isset($data['building']) && $data['building']) {
                if (is_array($data['building'])) {
                    foreach ($data['building'] as &$building) {
                        // Allow also empty values that might result from
                        // mapping tables
                        if ($building !== '') {
                            $building = "$institutionCode/$building";
                        }
                    }
                } else {
                    $data['building']
                        = $institutionCode . '/' . $data['building'];
                }
            } else {
                $data['building'] = [$institutionCode];
            }
        }
    }

    /**
     * Merge two Solr records
     *
     * @param string[] $merged Merged (base) record
     * @param string[] $add    Record to merge into $merged
     *
     * @return string[] Resulting merged record
     */
    protected function mergeRecords($merged, $add)
    {
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
                $merged[$key] = array_values(array_merge($merged[$key], $values));
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

        return $merged;
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
        global $configArray;

        $ignoreFields = [
            'allfields', 'allfields_unstemmed', 'fulltext', 'fulltext_unstemmed',
            'spelling', 'spellingShingle', 'authorStr', 'author_facet',
            'publisherStr', 'publishDateSort', 'topic_browse', 'hierarchy_browse',
            'first_indexed', 'last_indexed', '_version_',
            'fullrecord', 'title_full_unstemmed', 'title_fullStr',
            'author_additionalStr'
        ];

        if (isset($configArray['Solr']['ignore_in_comparison'])) {
            $ignoreFields = array_merge(
                $ignoreFields,
                explode(',', $configArray['Solr']['ignore_in_comparison'])
            );
        }

        if (!isset($configArray['Solr']['search_url'])) {
            throw new Exception('search_url not set in ini file Solr section');
        }

        $this->request = $this->initSolrRequest();
        $this->request->setMethod(HTTP_Request2::METHOD_GET);
        $url = $configArray['Solr']['search_url'];
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
     * @return HTTP_Request2
     */
    protected function initSolrRequest($timeout = null)
    {
        global $configArray;

        $request = new HTTP_Request2(
            $configArray['Solr']['update_url'],
            HTTP_Request2::METHOD_POST,
            $this->httpParams
        );
        if ($timeout !== null) {
            $request->setConfig('timeout', $timeout);
        }
        $request->setHeader('Connection', 'Keep-Alive');
        $request->setHeader('User-Agent', 'RecordManager');
        if (isset($configArray['Solr']['username'])
            && isset($configArray['Solr']['password'])
        ) {
            $request->setAuth(
                $configArray['Solr']['username'],
                $configArray['Solr']['password'],
                HTTP_Request2::AUTH_BASIC
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
        global $configArray;

        if (null === $this->request) {
            $this->request = $this->initSolrRequest($timeout);
        }

        if (!$this->waitForClusterStateOk()) {
            throw new Exception('Failed to check that the cluster state is ok');
        }

        $this->request->setHeader('Content-Type', 'application/json');
        $this->request->setBody($body);

        $response = null;
        $maxTries = $this->maxUpdateTries;
        for ($try = 1; $try <= $maxTries; $try++) {
            try {
                if (!$this->waitForClusterStateOk()) {
                    throw new Exception(
                        'Failed to check that the cluster state is ok'
                    );
                }
                $response = $this->request->send();
            } catch (Exception $e) {
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
            throw new Exception(
                "Solr server request failed ($code). URL:\n"
                . $configArray['Solr']['update_url']
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
        global $configArray;

        $lastCheck = time() - $this->lastClusterStateCheck;
        if ($lastCheck >= $this->clusterStateCheckInterval) {
            $this->lastClusterStateCheck = time();
            $request = $this->initSolrRequest();
            $url = $configArray['Solr']['admin_url'] . '/zookeeper'
                . '?wt=json&detail=true&path=%2Fclusterstate.json&view=graph';
            $request->setUrl($url);
            try {
                $response = $request->send();
            } catch (Exception $e) {
                $this->log->log(
                    'checkClusterState',
                    "Solr admin request '$url' failed (" . $e->getMessage() . ')',
                    Logger::ERROR
                );
                return 'error';
            }

            $code = null === $response ? 999 : $response->getStatus();
            if (200 !== $code) {
                $this->log->log(
                    'checkClusterState',
                    "Solr admin request '$url' failed ($code)",
                    Logger::ERROR
                );
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
                return 'error';
            }
            $data = json_decode($state['znode']['data'], true);
            if (null === $data) {
                $this->log->log(
                    'checkClusterState',
                    'Unable to decode node data from ' . $state['znode']['data'],
                    Logger::ERROR
                );
                return 'error';
            }
            foreach ($data as $collectionName => $collection) {
                foreach ($collection['shards'] as $shardName => $shard) {
                    if ('active' !== $shard['state']) {
                        $this->log->log(
                            'checkClusterState',
                            "Collection $collectionName shard $shardName:"
                            . " Not in active state: {$shard['state']}",
                            Logger::WARNING
                        );
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
                            return 'degraded';
                        }
                    }
                }
            }
        }
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
            throw new Exception('Could not convert record to JSON');
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
                'bufferedUpdate', 'Waiting for all requests to complete...'
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
     * Read a mapping file (two strings separated by ' = ' per line)
     *
     * @param string $filename Mapping file name
     *
     * @throws Exception
     * @return string[] Mappings
     */
    protected function readMappingFile($filename)
    {
        $mappings = [];
        $handle = fopen($filename, 'r');
        if (!$handle) {
            throw new Exception("Could not open mapping file '$filename'");
        }
        $lineno = 0;
        while (($line = fgets($handle))) {
            ++$lineno;
            $line = rtrim($line);
            if (!$line || $line[0] == ';') {
                continue;
            }
            $values = explode(' = ', $line, 2);
            if (!isset($values[1])) {
                if (strstr($line, ' =') === false) {
                    fclose($handle);
                    throw new Exception(
                        "Unable to parse mapping file '$filename' line "
                        . "(no ' = ' found): ($lineno) $line"
                    );
                }
                $values = explode(' =', $line, 2);
                $mappings[$values[0]] = '';
            } else {
                $key = trim($values[0]);
                if (substr($key, -2) == '[]') {
                    $mappings[substr($key, 0, -2)][] = $values[1];
                } else {
                    $mappings[$key] = $values[1];
                }
            }
        }
        fclose($handle);
        return $mappings;
    }

    /**
     * Enrich record according to the data source settings
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
        if (isset($settings['enrichments'])) {
            foreach ($settings['enrichments'] as $enrichment) {
                if (!isset($this->enrichments[$enrichment])) {
                    include_once "$enrichment.php";
                    $this->enrichments[$enrichment] = new $enrichment(
                        $this->db, $this->log
                    );
                }
                $this->enrichments[$enrichment]->enrich($source, $record, $data);
            }
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
        throw new Exception('Could not find a free dump file slot');
    }

    /**
     * Pretty-print a record
     *
     * @param array $data   Record data to print
     * @param bool  $return If true, the pretty-printed record is returned instead
     * of being echoed to screen.
     *
     * @return string
     */
    protected function prettyPrint($data, $return = false)
    {
        if (defined('JSON_PRETTY_PRINT') && defined('JSON_UNESCAPED_UNICODE')) {
            $res = json_encode($data, JSON_PRETTY_PRINT + JSON_UNESCAPED_UNICODE)
                . "\n";
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
     * Reconnect to the Mongo database after forking
     *
     * Public visibility so that the worker init can call this
     *
     * @return void
     */
    public function reconnectMongoDb()
    {
        // Make sure not to reuse the existing MongoDB connection by
        // opening a new connection with our pid as an additional
        // but unused parameter in the url.
        global $configArray;
        $connectTimeout = isset($configArray['Mongo']['connect_timeout'])
            ? $configArray['Mongo']['connect_timeout'] : 300000;
        $socketTimeout = isset($configArray['Mongo']['socket_timeout'])
            ? $configArray['Mongo']['socket_timeout'] : 300000;
        $url = $configArray['Mongo']['url'];
        $url .= strpos($url, '?') >= 0 ? '&' : '?';
        $url .= '_xpid=' . getmypid();
        $mongo = new \MongoDB\Client(
            $url,
            [
                'connectTimeoutMS' => $connectTimeout,
                'socketTimeoutMS' => $socketTimeout
            ]
        );
        $this->db = $mongo->{$configArray['Mongo']['database']};
    }
}

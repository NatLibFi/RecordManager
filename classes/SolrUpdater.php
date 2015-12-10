<?php
/**
 * SolrUpdater Class
 *
 * PHP version 5
 *
 * Copyright (C) The National Library of Finland 2012-2015.
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
declare(ticks = 100);

require_once 'BaseRecord.php';
require_once 'MetadataUtils.php';
require_once 'PerformanceCounter.php';

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
    protected $articleFormats;
    protected $eArticleFormats;
    protected $allArticleFormats;
    protected $httpPids = [];
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
     * Mongo cursor timeout
     * @var int
     */
    protected $cursorTimeout = 300000;

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
    protected $request;

    protected $mergedFields = ['institution', 'collection', 'building',
        'language', 'physical', 'publisher', 'publishDate', 'contents', 'url',
        'ctrlnum', 'author=author2', 'author2', 'author_additional', 'title_alt',
        'title_old', 'title_new', 'dateSpan', 'series', 'series2', 'topic', 'genre',
        'geographic', 'era', 'long_lat', 'isbn', 'issn'];

    protected $singleFields = ['title', 'title_short', 'title_full',
        'title_sort', 'author-letter', 'format', 'publishDateSort',
        'callnumber', 'callnumber-a', 'callnumber-first-code', 'illustrated',
        'first_indexed', 'last-indexed'];

    protected $enrichments = [];

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
    public function __construct($db, $basePath, $log, $verbose, $cursorTimeout)
    {
        global $configArray;

        $this->db = $db;
        $this->cursorTimeout = $cursorTimeout;
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

        $this->articleFormats = isset($configArray['Solr']['article_formats'])
            ? $configArray['Solr']['article_formats']
            : ['Article'];

        $this->eArticleFormats = isset($configArray['Solr']['earticle_formats'])
            ? $configArray['Solr']['earticle_formats']
            : ['eArticle'];

        $this->allArticleFormats
            = array_merge($this->articleFormats, $this->eArticleFormats);

        // Special case: building hierarchy
        $this->buildingHierarchy = isset($configArray['Solr']['hierarchical_facets'])
            && in_array('building', $configArray['Solr']['hierarchical_facets']);

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
        $this->threadedMergedRecordUpdate
            = isset($configArray['Solr']['threaded_merged_record_update'])
                ? $configArray['Solr']['threaded_merged_record_update'] : false;

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
        $this->terminate = true;
        echo "Termination requested\n";
    }

    /**
     * Process merged (deduplicated) records
     *
     * @param MongoDate $mongoFromDate Start date
     * @param string    $sourceId      Comma-separated list of source IDs to update,
     *                                 or empty or * for all sources
     * @param string    $singleId      Export only a record with the given ID
     * @param bool      $noCommit      If true, changes are not explicitly committed
     * @param bool      $delete        If true, records in the given $sourceId are
     *                                 all deleted
     * @param string    $compare       If set, just compare the records with the
     *                                 ones already in the Solr index and write any
     *                                 differences in a file given in this parameter
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
        }
        $record = $this->db->record->find()->timeout($this->cursorTimeout)
            ->sort(['updated' => -1])->getNext();
        $lastRecordTime = $record['updated']->sec;
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
            $collection = explode('.', $collection, 2);
            if ($collection[0] != $configArray['Mongo']['database']) {
                continue;
            }
            $collection = end($collection);
            if ($collection == $collectionName) {
                $collectionExists = true;
            } else {
                $nameParts = explode('_', $collection);
                $collTime = end($nameParts);
                if (strncmp($collection, 'mr_record_', 10) == 0
                    && is_numeric($collTime)
                    && $collTime != $lastRecordTime
                    && $collTime < time() - 60 * 60 * 24 * 7
                ) {
                    $this->log->log(
                        'processMerged',
                        "Cleanup: dropping old m/r collection $collection"
                    );
                    $this->db->selectCollection($collection)->drop();
                }
            }
        }

        $from = isset($mongoFromDate)
            ? date('Y-m-d H:i:s', $mongoFromDate->sec)
            : 'the beginning';

        if (!$collectionExists) {
            $this->log->log(
                'processMerged',
                "Creating merged record list $collectionName (from $from, stage 1/2)"
            );

            $records = $this->db->record->find($params, ['dedup_id' => 1])
                ->timeout($this->cursorTimeout);
            $prevId = null;
            $collection = $this->db->selectCollection($collectionName . '_tmp');
            $collection->drop();
            $count = 0;
            $totalMergeCount = 0;
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
                    $collection->insert(['_id' => $id], ['w' => 0]);
                    ++$totalMergeCount;
                    if (++$count % 10000 == 0) {
                        $this->log->log('processMerged', "$count id's processed");
                    }
                }
                $prevId = $id;
            }
            $this->log->log('processMerged', "$count id's processed");

            // Add dedup records by date (those that were not added by record date)
            if (!isset($mongoFromDate)) {
                $this->log->log(
                    'processMerged',
                    'No starting date, bypassing stage 2/2'
                );
            } else {
                $this->log->log(
                    'processMerged',
                    "Creating merged record list $collectionName"
                    . " (from $from, stage 2/2)"
                );
                $dedupParams = [];
                if ($singleId) {
                    $dedupParams['ids'] = $singleId;
                } else {
                    $dedupParams['changed'] = ['$gte' => $mongoFromDate];
                }

                $records = $this->db->dedup->find($dedupParams, ['_id' => 1])
                    ->timeout($this->cursorTimeout);
                $count = 0;
                foreach ($records as $record) {
                    if (isset($this->terminate)) {
                        $this->log->log('processMerged', 'Termination upon request');
                        $collection->drop();
                        exit(1);
                    }
                    $id = $record['_id'];
                    if (!isset($prevId) || $prevId != $id) {
                        $collection->insert(['_id' => $id], ['w' => 0]);
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
            }
            if ($totalMergeCount > 0) {
                $mongo = new MongoClient($configArray['Mongo']['url']);
                $dbName = $configArray['Mongo']['database'];
                $res = $mongo->admin->command(
                    [
                        'renameCollection' => $dbName . '.' . $collectionName
                            . '_tmp',
                        'to' => $dbName . '.' . $collectionName
                    ]
                );
                if (!$res['ok']) {
                    throw new Exception(
                        'Renaming collection failed: ' . print_r($res, true)
                    );
                }
            }
        } else {
            $this->log->log(
                'processMerged',
                "Using existing merged record list $collectionName"
            );
        }
        pcntl_signal(SIGINT, SIG_DFL);

        $keys = $this->db->{$collectionName}->find()->timeout($this->cursorTimeout);
        $keys->immortal(true);
        $count = 0;
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
        foreach ($keys as $key) {
            if (empty($key['_id'])) {
                continue;
            }

            $dedupRecord = $this->db->dedup->find(['_id' => $key['_id']])->limit(-1)
                ->timeout($this->cursorTimeout)->getNext();
            if (empty($dedupRecord)) {
                $this->log->log(
                    'processMerged',
                    "Dedup record with id {$key['_id']} missing",
                    Logger::ERROR
                );
                continue;
            }
            if ($dedupRecord['deleted']) {
                if (!$compare) {
                    $this->bufferedDelete($dedupRecord['_id']);
                }
                ++$count;
                ++$deleted;
                continue;
            }

            $children = [];
            $merged = [];
            $records = $this->db->record->find(
                ['_id' => ['$in' => $dedupRecord['ids']]]
            )->timeout($this->cursorTimeout);
            foreach ($records as $record) {
                if ($record['deleted']
                    || ($sourceId && $delete && $record['source_id'] == $sourceId)
                ) {
                    if (!$compare) {
                        $this->bufferedDelete($record['_id']);
                    }
                    ++$count;
                    ++$deleted;
                    continue;
                }
                $data = $this->createSolrArray($record, $mergedComponents);
                if ($data === false) {
                    continue;
                }
                $merged = $this->mergeRecords($merged, $data);
                $children[] = ['mongo' => $record, 'solr' => $data];
            }

            if (count($children) == 0) {
                $this->log->log(
                    'processMerged',
                    "Found no records with dedup id: {$key['_id']}",
                    Logger::INFO
                );
                if (!$compare) {
                    $this->bufferedDelete($dedupRecord['_id']);
                }
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

                ++$count;

                if (!$compare) {
                    $res = $this->bufferedUpdate($child['solr'], $count, $noCommit);
                } else {
                    $res = $count % 1000 == 0;
                    $this->compareWithSolrRecord($child['solr'], $compare);
                }
                if ($res) {
                    $pc->add($count);
                    $avg = $pc->getSpeed();
                    $this->log->log(
                        'processMerged',
                        "$count merged records (of which $deleted deleted) with "
                        . "$mergedComponents merged parts $verb, $avg records/sec"
                    );
                }
            } else {
                foreach ($children as $child) {
                    $child['solr']['merged_child_boolean'] = true;

                    if ($this->verbose) {
                        echo 'Original deduplicated record '
                            . $child['solr']['id'] . ":\n";
                        $this->prettyPrint($child['solr']);
                    }

                    ++$count;
                    if (!$compare) {
                        $res = $this->bufferedUpdate(
                            $child['solr'], $count, $noCommit
                        );
                    } else {
                        $res = $count % 1000 == 0;
                        $this->compareWithSolrRecord($child['solr'], $compare);
                    }
                    if ($res) {
                        $pc->add($count);
                        $avg = $pc->getSpeed();
                        $this->log->log(
                            'processMerged',
                            "$count merged records (of which $deleted deleted) with "
                            . "$mergedComponents merged parts $verb, $avg "
                            . 'records/sec'
                        );
                    }
                }

                // Remove duplicate fields from the merged record
                foreach ($merged as $fieldkey => $value) {
                    if ($fieldkey == 'author=author2') {
                        $fieldkey = 'author2';
                    }
                    if (substr($fieldkey, -3, 3) == '_mv'
                        || isset($this->mergedFields[$fieldkey])
                    ) {
                        $merged[$fieldkey] = array_values(
                            MetadataUtils::array_iunique($merged[$fieldkey])
                        );
                    }
                }
                if (isset($merged['allfields'])) {
                    $merged['allfields'] = array_values(
                        MetadataUtils::array_iunique($merged['allfields'])
                    );
                } else {
                    $this->log->log(
                        'processMerged',
                        'allfields missing in merged record for dedup key '
                        . $key['_id'],
                        Logger::WARNING
                    );
                }

                $mergedId = (string)$key['_id'];
                if (empty($merged)) {
                    if (!$compare) {
                        $this->bufferedDelete($mergedId);
                    }
                    ++$deleted;
                    continue;
                }
                $merged['id'] = $mergedId;
                $merged['recordtype'] = 'merged';
                $merged['merged_boolean'] = true;

                if ($this->verbose) {
                    echo "Merged record {$merged['id']}:\n";
                    $this->prettyPrint($merged);
                }

                ++$count;
                if (!$compare) {
                    $res = $this->bufferedUpdate($merged, $count, $noCommit);
                } else {
                    $res = $count % 1000 == 0;
                    $this->compareWithSolrRecord($merged, $compare);
                }
                if ($res) {
                    $pc->add($count);
                    $avg = $pc->getSpeed();
                    $this->log->log(
                        'processMerged',
                        "$count merged records (of which $deleted deleted) with "
                        . "$mergedComponents merged parts $verb, $avg records/sec"
                    );
                }
            }
        }
        if (!$compare) {
            $this->flushUpdateBuffer();
        }
        $this->log->log(
            'processMerged',
            "Total $count merged records (of which $deleted deleted) with "
            . "$mergedComponents merged parts $verb"
        );
        return $count > 0;
    }

    /**
     * Update Solr index (merged records and individual records)
     *
     * @param string|null $fromDate   Starting date for updates (if empty
     *                                string, last update date stored in the database
     *                                is used and if null, all records are processed)
     * @param string      $sourceId   Comma-separated list of source IDs to update,
     *                                or empty or * for all sources
     * @param string      $singleId   Export only a record with the given ID
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
            if ($this->backgroundUpdates && !$compare) {
                $this->log->log(
                    'updateRecords',
                    "Using {$this->backgroundUpdates} thread(s) for updates"
                );
            }

            $needCommit = false;

            if (isset($fromDate) && $fromDate) {
                $mongoFromDate = new MongoDate(strtotime($fromDate));
            }

            if (!isset($fromDate)) {
                $state = $this->db->state->find(['_id' => 'Last Index Update'])
                    ->limit(-1)->timeout($this->cursorTimeout)->getNext();
                if (isset($state)) {
                    $mongoFromDate = $state['value'];
                } else {
                    unset($mongoFromDate);
                }
            }
            $from = isset($mongoFromDate)
                ? date('Y-m-d H:i:s', $mongoFromDate->sec) : 'the beginning';
            // Take the last indexing date now and store it when done
            $lastIndexingDate = new MongoDate();

            if (!$delete && $this->threadedMergedRecordUpdate && !$compare) {
                $childPid = pcntl_fork();
                if ($childPid == -1) {
                    throw new Exception(
                        "Could not fork merged record background update child"
                    );
                }
            }

            if (!$childPid) {
                $needCommit = $this->processMerged(
                    isset($mongoFromDate) ? $mongoFromDate : null,
                    $sourceId,
                    $singleId,
                    $noCommit,
                    $delete,
                    $compare
                );

                if ($childPid !== null) {
                    exit($needCommit ? 1 : 0);
                }
            }

            if ($delete) {
                return;
            }

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
            $records = $this->db->record->find($params)
                ->timeout($this->cursorTimeout);
            $records->immortal(true);

            $total = $this->counts ? $records->count() : 'the';
            $count = 0;
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
                            sleep(10);
                        }
                    }
                    $this->log->log(
                        'updateRecords',
                        'Termination upon request (individual record handler)'
                    );
                    exit(1);
                }
                if (isset($record['update_needed']) && $record['update_needed']) {
                    $this->log->log(
                        'updateRecords',
                        "Record {$record['_id']} needs deduplication and would not"
                        . " be processed in a normal update",
                        Logger::WARNING
                    );
                }

                if ($record['deleted']) {
                    if (!$compare) {
                        $this->bufferedDelete((string)$record['_id']);
                    }
                    ++$count;
                    ++$deleted;
                } else {
                    $data = $this->createSolrArray($record, $mergedComponents);
                    if ($data === false) {
                        continue;
                    }

                    if ($this->verbose) {
                        echo "Metadata for record {$record['_id']}: \n";
                        $this->prettyPrint($data);
                    }

                    ++$count;

                    if (!$compare) {
                        $res = $this->bufferedUpdate(
                            $data, $count, $childPid || $noCommit
                        );
                    } else {
                        $res = $count % 1000 == 0;
                        $this->compareWithSolrRecord($data, $compare);
                    }
                    if ($res) {
                        $pc->add($count);
                        $avg = $pc->getSpeed();
                        $this->log->log(
                            'updateRecords',
                            "$count individual records (of which $deleted deleted) "
                            . "with $mergedComponents merged parts $verb, "
                            . "$avg records/sec"
                        );

                        // Check child status
                        if ($childPid) {
                            $pid = pcntl_waitpid($childPid, $status, WNOHANG);
                            if ($pid > 0) {
                                $childPid = null;
                                $exitCode = pcntl_wexitstatus($status);
                                if ($exitCode == 1) {
                                    $needCommit = true;
                                } elseif ($exitCode) {
                                    $this->log->log(
                                        'updateRecords',
                                        "Merged record update thread failed, "
                                        . "aborting",
                                        Logger::ERROR
                                    );
                                    throw new Exception(
                                        'Merged record update thread failed'
                                    );
                                }
                            }
                        }
                    }
                }
            }
            $this->flushUpdateBuffer();

            if (isset($lastIndexingDate) && !$compare) {
                $state
                    = ['_id' => "Last Index Update", 'value' => $lastIndexingDate];
                $this->db->state->save($state);
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
                    }
                    sleep(10);
                }
            }

            if (!$noCommit && $needCommit && !$compare && !$this->dumpPrefix) {
                $this->waitForHttpChildren();
                $this->log->log('updateRecords', "Final commit...");
                $this->solrRequest('{ "commit": {} }', 3600);
                $this->waitForHttpChildren();
                $this->log->log('updateRecords', "Commit complete");
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
                    if ($pid > 0) {
                        break;
                    }
                    sleep(10);
                }
            }

            if ($this->threadedMergedRecordUpdate && !$childPid) {
                exit(2);
            }
        }
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
        $this->waitForHttpChildren();
    }

    /**
     * Optimize the Solr index
     *
     * @return void
     */
    public function optimizeIndex()
    {
        $this->solrRequest('{ "optimize": {} }', 4 * 60 * 60);
        $this->waitForHttpChildren();
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
        $records = $this->db->record->find($params)->timeout($this->cursorTimeout);
        $records->immortal(true);
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

        if (isset($settings['mappingFiles'][$source]['format'])) {
            $map = $settings['mappingFiles'][$source]['format'];
            if (!empty($format)) {
                if (isset($map[$format])) {
                    return $map[$format];
                }
                if (isset($map['##default'])) {
                    return $map['##default'];
                }
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

            foreach ($settings as $key => $value) {
                if (substr($key, -8, 8) == '_mapping') {
                    $field = substr($key, 0, -8);
                    $this->settings[$source]['mappingFiles'][$field]
                        = $this->readMappingFile(
                            $this->basePath . '/mappings/' . $value
                        );
                }
            }

            $this->settings[$source]['extraFields'] = [];
            if (isset($settings['extrafields'])) {
                foreach ($settings['extrafields'] as $extraField) {
                    list($field, $value) = explode(':', $extraField, 2);
                    $this->settings[$source]['extraFields'][] = [$field => $value];
                }
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
        $hiddenComponent = false;
        if (isset($record['host_record_id'])) {
            if ($settings['componentParts'] == 'merge_all') {
                $hiddenComponent = true;
            } elseif ($settings['componentParts'] == 'merge_non_articles'
                || $settings['componentParts'] == 'merge_non_earticles'
            ) {
                $format = $metadataRecord->getFormat();
                if (!in_array($format, $this->allArticleFormats)) {
                    $hiddenComponent = true;
                } elseif (in_array($format, $this->articleFormats)) {
                    $hiddenComponent = true;
                }
            }
        }

        if ($hiddenComponent && !$settings['indexMergedParts']) {
            return false;
        }

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
            } else {
                $components = $this->db->record->find(
                    [
                        'source_id' => $record['source_id'],
                        'host_record_id' => $record['linking_id'],
                        'deleted' => false
                    ]
                )->timeout($this->cursorTimeout);
                $hasComponentParts = $components->hasNext();
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

        if (isset($components)) {
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
                $hostRecord = $this->db->record->find(
                    [
                        'source_id' => $record['source_id'],
                        'linking_id' => $record['host_record_id']
                    ]
                )->limit(-1)->timeout($this->cursorTimeout)->getNext();
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

        // Map field values according to any mapping files
        foreach ($settings['mappingFiles'] as $field => $map) {
            if (isset($data[$field]) && !empty($data[$field])) {
                if (is_array($data[$field])) {
                    foreach ($data[$field] as &$value) {
                        if (isset($map[$value])) {
                            $value = $map[$value];
                        } elseif (isset($map['##default'])) {
                            $value = $map['##default'];
                        }
                    }
                    $data[$field] = array_values(array_unique($data[$field]));
                } else {
                    if (isset($map[$data[$field]])) {
                        $data[$field] = $map[$data[$field]];
                    } elseif (isset($map['##default'])) {
                        $data[$field] = $map['##default'];
                    }
                }
            } elseif (isset($map['##empty'])) {
                $data[$field] = $map['##empty'];
            } elseif (isset($map['##emptyarray'])) {
                $data[$field] = [$map['##emptyarray']];
            }
        }

        // Special case: Special values for building (institution/location).
        // Used by default if building is set as a hierarchical facet.
        if ($this->buildingHierarchy || isset($settings['institutionInBuilding'])) {
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

        // Hierarchical facets
        if (isset($configArray['Solr']['hierarchical_facets'])) {
            foreach ($configArray['Solr']['hierarchical_facets'] as $facet) {
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
        }

        if (!isset($data['allfields'])) {
            $all = [];
            foreach ($data as $key => $field) {
                if (in_array(
                    $key, ['fullrecord', 'thumbnail', 'id', 'recordtype', 'ctrlnum']
                )) {
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
            = MetadataUtils::formatTimestamp($record['created']->sec);
        $data['last_indexed'] = MetadataUtils::formatTimestamp($record['date']->sec);
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

        return $data;
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
            'spelling', 'spellingShingle', 'authorStr', 'author2Str', 'publisherStr',
            'publishDateSort', 'topic_browse', 'hierarchy_browse',
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

        $this->initSolrRequest();
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
     * Initialize the Solr request object
     *
     * @param int $timeout Timeout in seconds (optional)
     *
     * @return void
     */
    protected function initSolrRequest($timeout = null)
    {
        global $configArray;

        if (!isset($this->request)) {
            $this->request = new HTTP_Request2(
                $configArray['Solr']['update_url'],
                HTTP_Request2::METHOD_POST,
                ['ssl_verify_peer' => false]
            );
            if ($timeout !== null) {
                $this->request->setConfig('timeout', $timeout);
            }
            $this->request->setHeader('Connection', 'Keep-Alive');
            $this->request->setHeader('User-Agent', 'RecordManager');
            if (isset($configArray['Solr']['username'])
                && isset($configArray['Solr']['password'])
            ) {
                $this->request->setAuth(
                    $configArray['Solr']['username'],
                    $configArray['Solr']['password'],
                    HTTP_Request2::AUTH_BASIC
                );
            }
        }
    }

    /**
     * Make a JSON request to the Solr server
     *
     * @param string       $body    The JSON request
     * @param integer|null $timeout If specified, the HTTP call timeout in seconds
     *
     * @return void
     */
    protected function solrRequest($body, $timeout = null)
    {
        global $configArray;

        $this->initSolrRequest($timeout);
        if ($this->backgroundUpdates) {
            if ($this->backgroundUpdates <= count($this->httpPids)) {
                $this->waitForAHttpChild();
            }
            $pid = pcntl_fork();
            if ($pid == -1) {
                throw new Exception("Could not fork background update child");
            } elseif ($pid) {
                $this->httpPids[] = $pid;
                return;
            }
        }
        $this->request->setHeader('Content-Type', 'application/json');
        $this->request->setBody($body);

        $response = null;
        $maxTries = $this->maxUpdateTries;
        for ($try = 1; $try <= $maxTries; $try++) {
            try {
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
                if ($this->backgroundUpdates) {
                    $this->log->log(
                        'solrRequest',
                        'Solr server request failed (' . $e->getMessage()
                        . "). URL:\n" . $configArray['Solr']['update_url']
                        . "\nRequest:\n$body",
                        Logger::FATAL
                    );
                    // Kill parent and self
                    posix_kill(posix_getppid(), SIGQUIT);
                    posix_kill(getmypid(), SIGKILL);
                } else {
                    throw $e;
                }
            }
            if ($try < $maxTries) {
                $code = is_null($response) ? 999 : $response->getStatus();
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
        $code = is_null($response) ? 999 : $response->getStatus();
        if ($code >= 300) {
            if ($this->backgroundUpdates) {
                $this->log->log(
                    'solrRequest',
                    "Solr server request failed ($code). URL:\n"
                    . $configArray['Solr']['update_url']
                    . "\nRequest:\n$body\n\nResponse:\n"
                    . $response->getBody(),
                    Logger::FATAL
                );
                // Kill parent and self
                posix_kill(posix_getppid(), SIGQUIT);
                posix_kill(getmypid(), SIGKILL);
            } else {
                throw new Exception(
                    "Solr server request failed ($code). URL:\n"
                    . $configArray['Solr']['update_url']
                    . "\nRequest:\n$body\n\nResponse:\n" . $response->getBody()
                );
            }
        }
        if ($this->backgroundUpdates) {
            // Don't let PHP cleanup e.g. the Mongo connection
            posix_kill(getmypid(), SIGKILL);
        }
    }

    /**
     * Wait for all http requests to complete
     *
     * @throws Exception
     * @return void
     */
    protected function waitForHttpChildren()
    {
        foreach ($this->httpPids as $httpPid) {
            pcntl_waitpid($httpPid, $status);
            if (pcntl_wexitstatus($status) != 0) {
                throw new Exception("Aborting due to failed HTTP request");
            }
        }
        $this->httpPids = [];
    }

    /**
     * Wait for a single http request to complete
     *
     * @throws Exception
     * @return void
     */
    protected function waitForAHttpChild()
    {
        $startTime = microtime(true);
        while (1) {
            foreach ($this->httpPids as $httpPid) {
                $pid = pcntl_waitpid($httpPid, $status, WNOHANG);
                if ($pid > 0) {
                    if (pcntl_wexitstatus($status) != 0) {
                        throw new Exception("Aborting due to failed HTTP request");
                    }
                    $this->httpPids = array_diff($this->httpPids, [$pid]);
                    if ($this->verbose) {
                        $waitTime = microtime(true) - $startTime;
                        if ($waitTime > 1) {
                            echo "Waited " . round(microtime(true) - $startTime, 4) .
                                " seconds for an HTTP request to complete\n";
                        }
                    }
                    return;
                }
            }
            sleep(10);
        }
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
                $this->solrRequest($request);
            }
            $this->buffer = '';
            $this->bufferLen = 0;
            $this->buffered = 0;
            $result = true;
        }
        if (!$noCommit && !$this->dumpPrefix && $count % $this->commitInterval == 0
        ) {
            $this->waitForHttpChildren();
            $this->log->log('bufferedUpdate', "Intermediate commit...");
            $this->solrRequest('{ "commit": {} }', 3600);
            $this->waitForHttpChildren();
            $this->log->log('bufferedUpdate', "Intermediate commit complete");
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
            $this->solrRequest("{" . implode(',', $this->bufferedDeletions) . "}");
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
        $this->waitForHttpChildren();
    }

    /**
     * Read a mapping file (two strings separated by ' = ' per line)
     *
     * @param string $filename Mapping file name
     *
     * @throws Exception
     * @return string[string] Mappings
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
                $mappings[$values[0]] = $values[1];
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
}

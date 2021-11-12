<?php
/**
 * Abstract database access class
 *
 * PHP version 7
 *
 * Copyright (c) The National Library of Finland 2020.
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
namespace RecordManager\Base\Database;

/**
 * Abstract database access class
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
abstract class AbstractDatabase implements DatabaseInterface
{
    /**
     * Default page size when iterating a large set of results
     *
     * @var int
     */
    protected $defaultPageSize = 1000;

    /**
     * Dedup collection name
     *
     * @var string
     */
    protected $dedupCollection = 'dedup';

    /**
     * Record collection name
     *
     * @var string
     */
    protected $recordCollection = 'record';

    /**
     * State collection name
     *
     * @var string
     */
    protected $stateCollection = 'state';

    /**
     * URI cache collection name
     *
     * @var string
     */
    protected $uriCacheCollection = 'uriCache';

    /**
     * Ontology enrichment collection name
     *
     * @var string
     */
    protected $ontologyEnrichmentCollection = 'ontologyEnrichment';

    /**
     * Log message collection name
     *
     * @var string
     */
    protected $logMessageCollection = 'logMessage';

    /**
     * Active tracking collections
     *
     * Collection names in array keys
     *
     * @var array
     */
    protected $trackingCollections = [];

    /**
     * Constructor.
     *
     * @param array $config Database settings
     *
     * @throws \Exception
     */
    public function __construct(array $config)
    {
        if (!empty($config['dedup_collection'])) {
            $this->dedupCollection = $config['dedup_collection'];
        }
        if (!empty($config['record_collection'])) {
            $this->recordCollection = $config['record_collection'];
        }
        if (!empty($config['state_collection'])) {
            $this->stateCollection = $config['state_collection'];
        }
        if (!empty($config['uri_cache_collection'])) {
            $this->uriCacheCollection = $config['uri_cache_collection'];
        }
        if (!empty($config['ontology_enrichment_collection'])) {
            $this->ontologyEnrichmentCollection
                = $config['ontology_enrichment_collection'];
        }
        if (!empty($config['log_message_collection'])) {
            $this->logMessageCollection = $config['log_message_collection'];
        }

        register_shutdown_function([$this, 'dropCurrentTrackingCollections']);
    }

    /**
     * Clean up any tracking collections created during current execution
     *
     * @return void
     */
    public function dropCurrentTrackingCollections(): void
    {
        $collections = $this->trackingCollections;
        foreach (array_keys($collections) as $collectionName) {
            $this->dropTrackingCollection($collectionName);
        }
    }

    /**
     * Get a timestamp in a format the database uses
     *
     * @param int $time Optional unix time (default = current time)
     *
     * @return mixed
     */
    abstract public function getTimestamp($time = null);

    /**
     * Convert a database timestamp to unix time
     *
     * @param mixed $timestamp Database timestamp
     *
     * @return int
     */
    abstract public function getUnixTime($timestamp): int;

    /**
     * Get a record
     *
     * @param string $id Record ID
     *
     * @return array|null
     */
    abstract public function getRecord($id);

    /**
     * Find a single record
     *
     * @param array $filter  Search filter
     * @param array $options Options such as sorting
     *
     * @return array|null
     */
    abstract public function findRecord($filter, $options = []);

    /**
     * Find records
     *
     * @param array $filter  Search filter
     * @param array $options Options such as sorting
     *
     * @return \Traversable
     */
    abstract public function findRecords($filter, $options = []);

    /**
     * Iterate through records
     *
     * Calls callback for each record until exhausted or callback returns false.
     *
     * @param array    $filter   Search filter
     * @param array    $options  Options such as sorting
     * @param Callable $callback Callback to call for each record
     * @param array    $params   Optional parameters to pass to the callback
     *
     * @return void
     */
    public function iterateRecords(
        array $filter,
        array $options,
        callable $callback,
        array $params = []
    ): void {
        $this->iterate(
            [$this, 'findRecords'],
            $filter,
            $options,
            $callback,
            $params
        );
    }

    /**
     * Count records
     *
     * @param array $filter  Search filter
     * @param array $options Options such as sorting
     *
     * @return int
     */
    abstract public function countRecords($filter, $options = []);

    /**
     * Save a record
     *
     * @param array $record Record
     *
     * @return array Saved record (with a new _id if it didn't have one)
     */
    abstract public function saveRecord($record);

    /**
     * Update a record
     *
     * @param string $id     Record ID
     * @param array  $fields Modified fields
     * @param array  $remove Removed fields
     *
     * @return void
     */
    abstract public function updateRecord($id, $fields, $remove = []);

    /**
     * Update multiple records
     *
     * @param array $filter Record ID
     * @param array $fields Modified fields
     * @param array $remove Removed fields
     *
     * @return void
     */
    abstract public function updateRecords($filter, $fields, $remove = []);

    /**
     * Delete a record
     *
     * @param string $id Record ID
     *
     * @return void
     */
    abstract public function deleteRecord($id);

    /**
     * Get a state record
     *
     * @param string $id Record ID
     *
     * @return array|null
     */
    abstract public function getState($id);

    /**
     * Save a state record
     *
     * @param array $record State record
     *
     * @return array Saved record (with a new _id if it didn't have one)
     */
    abstract public function saveState($record);

    /**
     * Delete a state record
     *
     * @param string $id Record ID
     *
     * @return void
     */
    abstract public function deleteState($id);

    /**
     * Get a dedup record
     *
     * @param mixed $id Record ID
     *
     * @return array|null
     */
    abstract public function getDedup($id);

    /**
     * Find a single dedup record
     *
     * @param array $filter  Search filter
     * @param array $options Options such as sorting
     *
     * @return array|null
     */
    abstract public function findDedup($filter, $options = []);

    /**
     * Find dedup records
     *
     * @param array $filter  Search filter
     * @param array $options Options such as sorting
     *
     * @return \Traversable
     */
    abstract public function findDedups($filter, $options = []);

    /**
     * Iterate through dedup records
     *
     * Calls callback for each record until exhausted or callback returns false.
     *
     * @param array    $filter   Search filter
     * @param array    $options  Options such as sorting
     * @param Callable $callback Callback to call for each record
     * @param array    $params   Optional parameters to pass to the callback
     *
     * @return void
     */
    public function iterateDedups(
        array $filter,
        array $options,
        callable $callback,
        array $params = []
    ): void {
        $this->iterate(
            [$this, 'findDedups'],
            $filter,
            $options,
            $callback,
            $params
        );
    }

    /**
     * Count dedup records
     *
     * @param array $filter  Search filter
     * @param array $options Options such as sorting
     *
     * @return int
     */
    abstract public function countDedups($filter, $options = []);

    /**
     * Save a dedup record
     *
     * @param array $record Dedup record
     *
     * @return array Saved record (with a new _id if it didn't have one)
     */
    abstract public function saveDedup($record);

    /**
     * Delete a dedup record
     *
     * @param string $id Record ID
     *
     * @return void
     */
    abstract public function deleteDedup($id);

    /**
     * Remove old tracking collections
     *
     * @param int $minAge Minimum age in days. Default is 7 days.
     *
     * @return array Array of two arrays with collections removed and those whose
     * removal failed
     */
    abstract public function cleanupTrackingCollections(int $minAge = 7);

    /**
     * Create a new temporary tracking collection
     *
     * @return string
     */
    abstract public function getNewTrackingCollection();

    /**
     * Remove a temporary tracking collection
     *
     * @param string $collectionName The temporary collection name
     *
     * @return bool
     */
    abstract public function dropTrackingCollection($collectionName);

    /**
     * Add a record ID to a tracking collection
     *
     * @param string $collectionName The queue collection name
     * @param string $id             ID to add
     *
     * @return bool True if added, false if id already exists
     */
    abstract public function addIdToTrackingCollection($collectionName, $id);

    /**
     * Save a log message
     *
     * @param string $context   Context
     * @param string $msg       Message
     * @param int    $level     Message level (see constants in Logger)
     * @param int    $pid       Process ID
     * @param int    $timestamp Unix time stamp
     *
     * @return void
     */
    abstract public function saveLogMessage(
        string $context,
        string $msg,
        int $level,
        int $pid,
        int $timestamp
    ): void;

    /**
     * Find log messages
     *
     * @param array $filter  Search filter
     * @param array $options Options such as sorting
     *
     * @return \Traversable
     */
    abstract public function findLogMessages(array $filter, array $options = []);

    /**
     * Delete a log message
     *
     * @param mixed $id Message ID
     *
     * @return void
     */
    abstract public function deleteLogMessage($id): void;

    /**
     * Find a single URI cache record
     *
     * @param array $filter  Search filter
     * @param array $options Options such as sorting
     *
     * @return array|null
     */
    abstract public function findUriCache($filter, $options = []);

    /**
     * Save a URI cache record
     *
     * @param array $record URI cache record
     *
     * @return array Saved record (with a new _id if it didn't have one)
     */
    abstract public function saveUriCache($record);

    /**
     * Find a single ontology enrichment record
     *
     * @param array $filter  Search filter
     * @param array $options Options such as sorting
     *
     * @return array|null
     */
    abstract public function findOntologyEnrichment($filter, $options = []);

    /**
     * Get default page size for results
     *
     * @return int
     */
    public function getDefaultPageSize()
    {
        return $this->defaultPageSize;
    }

    /**
     * Iterate through records
     *
     * Calls callback for each record until exhausted or callback returns false.
     *
     * @param Callable $findMethod Method used to find records to iterate
     * @param array    $filter     Search filter
     * @param array    $options    Options such as sorting
     * @param Callable $callback   Callback to call for each record
     * @param array    $params     Optional parameters to pass to the callback
     *
     * @return void
     */
    protected function iterate(
        callable $findMethod,
        array $filter,
        array $options,
        callable $callback,
        array $params = []
    ): void {
        $limit = $this->getDefaultPageSize();
        $lastId = null;
        do {
            $currentFilter = $filter;
            if (null !== $lastId) {
                $currentFilter['_id'] = [
                    '$gt' => $lastId
                ];
            }
            $records = $findMethod(
                $currentFilter,
                array_merge(
                    $options,
                    [
                        'skip' => 0,
                        'limit' => $limit,
                        'sort' => ['_id' => 1]
                    ]
                )
            );
            $lastId = null;
            foreach ($records as $record) {
                if (!isset($record['_id'])) {
                    throw new
                        \Exception('Cannot iterate records without _id column');
                }
                $lastId = $record['_id'];
                if ($callback($record, $params) === false) {
                    return;
                }
            }
        } while ($lastId && !isset($filter['_id']));
    }
}

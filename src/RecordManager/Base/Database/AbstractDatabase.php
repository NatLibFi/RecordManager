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
 * @link     https://github.com/KDK-Alli/RecordManager
 */
namespace RecordManager\Base\Database;

/**
 * Abstract database access class
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
abstract class AbstractDatabase implements DatabaseInterface
{
    /**
     * Database connection string
     *
     * @var string
     */
    protected $dsn;

    /**
     * Database name
     *
     * @var string
     */
    protected $databaseName;

    /**
     * Database settings
     *
     * @var array
     */
    protected $settings;

    /**
     * Whether to report actual counts. When false, all count methods return 'the'
     * instead.
     *
     * @var bool
     */
    protected $counts = false;

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
     * Constructor.
     *
     * @param string $dsn      Database connection string
     * @param string $database Datatabase name
     * @param array  $settings Optional database settings
     *
     * @throws Exception
     */
    public function __construct($dsn, $database, $settings)
    {
        if (!empty($settings['dedup_collection'])) {
            $this->dedupCollection = $settings['dedup_collection'];
        }
        if (!empty($settings['record_collection'])) {
            $this->recordCollection = $settings['record_collection'];
        }
        if (!empty($settings['state_collection'])) {
            $this->stateCollection = $settings['state_collection'];
        }
        if (!empty($settings['uri_cache_collection'])) {
            $this->uriCacheCollection = $settings['uri_cache_collection'];
        }
        if (!empty($settings['ontology_enrichment_collection'])) {
            $this->ontologyEnrichmentCollection
                = $settings['ontology_enrichment_collection'];
        }
        $this->counts = !empty($settings['counts']);

        $this->dsn = $dsn;
        $this->databaseName = $database;
        $this->settings = $settings;

        $this->reconnectDatabase();
    }

    /**
     * Open a database connection with the stored parameters
     *
     * @return void
     */
    abstract public function reconnectDatabase();

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
     * @param string|ObjectID $id Record ID
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
     * Remove old queue collections
     *
     * @param int $lastRecordTime Newest record timestamp
     *
     * @return array Array of two arrays with collections removed and those whose
     * removal failed
     */
    abstract public function cleanupQueueCollections($lastRecordTime);

    /**
     * Check for an existing queue collection with the given parameters
     *
     * @param string $hash           Hash of parameters used to identify the
     *                               collection
     * @param int    $fromDate       Timestamp of processing start date
     * @param int    $lastRecordTime Newest record timestamp
     *
     * @return string
     */
    abstract public function getExistingQueueCollection($hash, $fromDate,
        $lastRecordTime
    );

    /**
     * Create a new temporary queue collection for the given parameters
     *
     * @param string $hash           Hash of parameters used to identify the
     *                               collection
     * @param string $fromDate       Timestamp of processing start date
     * @param int    $lastRecordTime Newest record timestamp
     *
     * @return string
     */
    abstract public function getNewQueueCollection($hash, $fromDate, $lastRecordTime
    );

    /**
     * Rename a temporary dedup collection to its final name and return the name
     *
     * @param string $collectionName The temporary collection name
     *
     * @return string
     */
    abstract public function finalizeQueueCollection($collectionName);

    /**
     * Remove a temp dedup collection
     *
     * @param string $collectionName The temporary collection name
     *
     * @return bool
     */
    abstract public function dropQueueCollection($collectionName);

    /**
     * Add a record ID to a queue collection
     *
     * @param string $collectionName The queue collection name
     * @param string $id             ID to add
     *
     * @return void
     */
    abstract public function addIdToQueue($collectionName, $id);

    /**
     * Get IDs in queue
     *
     * @param string $collectionName The queue collection name
     *
     * @return \Traversable
     */
    abstract public function getQueuedIds($collectionName);

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
        return 1000;
    }
}

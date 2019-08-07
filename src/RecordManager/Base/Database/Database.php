<?php
/**
 * Database access class
 *
 * PHP version 5
 *
 * Copyright (c) The National Library of Finland 2017-2019.
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
 * Database access class
 *
 * This class encapsulates access to the underlying MongoDB database.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class Database
{
    /**
     * Mongo database URL
     *
     * @var string
     */
    protected $mongoUrl;

    /**
     * Mongo database name
     *
     * @var string
     */
    protected $mongoDatabaseName;

    /**
     * Mongo settings
     *
     * @var array
     */
    protected $mongoSettings;

    /**
     * Mongo Client
     *
     * @var \MongoDB\Client
     */
    protected $mongoClient;

    /**
     * Mongo database
     *
     * @var MongoDB
     */
    protected $db;

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
     * @param string $url      Database connection URL
     * @param string $database Datatabase name
     * @param array  $settings Optional database settings
     *
     * @throws Exception
     */
    public function __construct($url, $database, $settings)
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

        $this->mongoUrl = $url;
        $this->mongoDatabaseName = $database;
        $this->mongoSettings = $settings;
        $this->counts = !empty($settings['counts']);

        $this->reconnectDatabase();
    }

    /**
     * Open a database connection with the stored parameters
     *
     * @return void
     */
    public function reconnectDatabase()
    {
        $connectTimeout = isset($this->mongoSettings['connect_timeout'])
            ? $this->mongoSettings['connect_timeout'] : 300000;
        $socketTimeout = isset($this->mongoSettings['socket_timeout'])
            ? $this->mongoSettings['socket_timeout'] : 300000;
        $url = $this->mongoUrl;
        $this->mongoClient = new \MongoDB\Client(
            $url,
            [
                'connectTimeoutMS' => (int)$connectTimeout,
                'socketTimeoutMS' => (int)$socketTimeout,
                '_xpid' => getmypid()
            ]
        );
        $this->db = $this->mongoClient->{$this->mongoDatabaseName};
    }

    /**
     * Get a timestamp
     *
     * @param int $time Optional unix time (default = current time)
     *
     * @return \MongoDB\BSON\UTCDateTime
     */
    public function getTimestamp($time = null)
    {
        return new \MongoDB\BSON\UTCDateTime(
            ($time === null ? time() : $time) * 1000
        );
    }

    /**
     * Get a record
     *
     * @param string $id Record ID
     *
     * @return array|null
     */
    public function getRecord($id)
    {
        return $this->getMongoRecord($this->recordCollection, $id);
    }

    /**
     * Find a single record
     *
     * @param array $filter  Search filter
     * @param array $options Options such as sorting
     *
     * @return array|null
     */
    public function findRecord($filter, $options = [])
    {
        return $this->findMongoRecord($this->recordCollection, $filter, $options);
    }

    /**
     * Find records
     *
     * @param array $filter  Search filter
     * @param array $options Options such as sorting
     *
     * @return MongoDB\Driver\Cursor
     */
    public function findRecords($filter, $options = [])
    {
        return $this->findMongoRecords($this->recordCollection, $filter, $options);
    }

    /**
     * Count records
     *
     * @param array $filter  Search filter
     * @param array $options Options such as sorting
     *
     * @return int
     */
    public function countRecords($filter, $options = [])
    {
        return $this->countMongoRecords($this->recordCollection, $filter, $options);
    }

    /**
     * Save a record
     *
     * @param array $record Record
     *
     * @return array Saved record (with a new _id if it didn't have one)
     */
    public function saveRecord($record)
    {
        return $this->saveMongoRecord($this->recordCollection, $record);
    }

    /**
     * Update a record
     *
     * @param string $id     Record ID
     * @param array  $fields Modified fields
     * @param array  $remove Removed fields
     *
     * @return void
     */
    public function updateRecord($id, $fields, $remove = [])
    {
        $this->updateMongoRecord($this->recordCollection, $id, $fields, $remove);
    }

    /**
     * Update multiple records
     *
     * @param array $filter Record ID
     * @param array $fields Modified fields
     * @param array $remove Removed fields
     *
     * @return void
     */
    public function updateRecords($filter, $fields, $remove = [])
    {
        $this->updateMongoRecords(
            $this->recordCollection, $filter, $fields, $remove
        );
    }

    /**
     * Delete a record
     *
     * @param string $id Record ID
     *
     * @return void
     */
    public function deleteRecord($id)
    {
        $this->deleteMongoRecord($this->recordCollection, $id);
    }

    /**
     * Get a state record
     *
     * @param string $id Record ID
     *
     * @return array|null
     */
    public function getState($id)
    {
        return $this->getMongoRecord($this->stateCollection, $id);
    }

    /**
     * Save a state record
     *
     * @param array $record State record
     *
     * @return array Saved record (with a new _id if it didn't have one)
     */
    public function saveState($record)
    {
        return $this->saveMongoRecord($this->stateCollection, $record);
    }

    /**
     * Delete a state record
     *
     * @param string $id Record ID
     *
     * @return void
     */
    public function deleteState($id)
    {
        $this->deleteMongoRecord($this->stateCollection, $id);
    }

    /**
     * Get a dedup record
     *
     * @param string|ObjectID $id Record ID
     *
     * @return array|null
     */
    public function getDedup($id)
    {
        if (is_string($id)) {
            $id = new \MongoDB\BSON\ObjectID($id);
        }
        return $this->getMongoRecord($this->dedupCollection, $id);
    }

    /**
     * Find a single dedup record
     *
     * @param array $filter  Search filter
     * @param array $options Options such as sorting
     *
     * @return array|null
     */
    public function findDedup($filter, $options = [])
    {
        return $this->findMongoRecord($this->dedupCollection, $filter, $options);
    }

    /**
     * Find dedup records
     *
     * @param array $filter  Search filter
     * @param array $options Options such as sorting
     *
     * @return MongoDB\Driver\Cursor
     */
    public function findDedups($filter, $options = [])
    {
        return $this->findMongoRecords($this->dedupCollection, $filter, $options);
    }

    /**
     * Count dedup records
     *
     * @param array $filter  Search filter
     * @param array $options Options such as sorting
     *
     * @return int
     */
    public function countDedups($filter, $options = [])
    {
        return $this->countMongoRecords($this->dedupCollection, $filter, $options);
    }

    /**
     * Save a dedup record
     *
     * @param array $record Dedup record
     *
     * @return array Saved record (with a new _id if it didn't have one)
     */
    public function saveDedup($record)
    {
        return $this->saveMongoRecord($this->dedupCollection, $record);
    }

    /**
     * Delete a dedup record
     *
     * @param string $id Record ID
     *
     * @return void
     */
    public function deleteDedup($id)
    {
        $this->deleteMongoRecord($this->dedupCollection, $id);
    }

    /**
     * Remove old queue collections
     *
     * @param int $lastRecordTime Newest record timestamp
     *
     * @return array Array of two arrays with collections removed and those whose
     * removal failed
     */
    public function cleanupQueueCollections($lastRecordTime)
    {
        $removed = [];
        $failed = [];
        foreach ($this->db->listCollections() as $collection) {
            $collection = $collection->getName();
            if (strncmp($collection, 'mr_record_', 10) != 0) {
                continue;
            }
            $nameParts = explode('_', $collection);
            $collTime = isset($nameParts[4]) ? $nameParts[4] : null;
            if (is_numeric($collTime)
                && $collTime != $lastRecordTime
                && $collTime < time() - 60 * 60 * 24 * 7
            ) {
                try {
                    $this->db->selectCollection($collection)->drop();
                    $removed[] = $collection;
                } catch (\Exception $e) {
                    $failed[] = $collection;
                }
            }
        }
        return compact('removed', 'failed');
    }

    /**
     * Check for an existing queue collection with the given parameters
     *
     * @param string $hash           Hash of parameters used to identify the
     *                               collection
     * @param string $fromDate       Timestamp of processing start date
     * @param int    $lastRecordTime Newest record timestamp
     *
     * @return string
     */
    public function getExistingQueueCollection($hash, $fromDate, $lastRecordTime)
    {
        $collectionName = "mr_record_{$hash}_{$fromDate}_{$lastRecordTime}";
        foreach ($this->db->listCollections() as $collection) {
            $collection = $collection->getName();
            if ($collection == $collectionName) {
                return $collectionName;
            }
        }
        return '';
    }

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
    public function getNewQueueCollection($hash, $fromDate, $lastRecordTime)
    {
        $collectionName = "tmp_mr_record_{$hash}_{$fromDate}_{$lastRecordTime}";
        return $collectionName;
    }

    /**
     * Rename a temporary dedup collection to its final name and return the name
     *
     * @param string $collectionName The temporary collection name
     *
     * @return string
     */
    public function finalizeQueueCollection($collectionName)
    {
        if (strncmp($collectionName, 'tmp_', 4) !== 0) {
            throw new \Exception(
                "Invalid temp queue collection name: '$collectionName'"
            );
        }
        $newName = substr($collectionName, 4);

        // renameCollection requires admin priviledge
        $res = $this->mongoClient->admin->command(
            [
                'renameCollection' => $this->mongoDatabaseName . '.'
                    . $collectionName,
                'to' => $this->mongoDatabaseName . '.' . $newName
            ]
        );
        $resArray = $res->toArray();
        if (!$resArray[0]['ok']) {
            throw new \Exception(
                'Renaming collection failed: ' . print_r($resArray, true)
            );
        }
        return $newName;
    }

    /**
     * Remove a temp dedup collection
     *
     * @param string $collectionName The temporary collection name
     *
     * @return bool
     */
    public function dropQueueCollection($collectionName)
    {
        if (strncmp($collectionName, 'tmp_', 4) !== 0) {
            throw new \Exception(
                "Invalid temp queue collection name: '$collectionName'"
            );
        }
        $collection = $this->mongoClient->{$collectionName};
        $res = $collection->drop();
        return (bool)$res['ok'];
    }

    /**
     * Add a record ID to a queue collection
     *
     * @param string $collectionName The queue collection name
     * @param string $id             ID to add
     *
     * @return void
     */
    public function addIdToQueue($collectionName, $id)
    {
        $this->saveMongoRecord($collectionName, ['_id' => $id], 0);
    }

    /**
     * Get IDs in queue
     *
     * @param string $collectionName The queue collection name
     *
     * @return MongoDB\Driver\Cursor
     */
    public function getQueuedIds($collectionName)
    {
        return $this->findMongoRecords($collectionName, [], []);
    }

    /**
     * Find a single URI cache record
     *
     * @param array $filter  Search filter
     * @param array $options Options such as sorting
     *
     * @return array|null
     */
    public function findUriCache($filter, $options = [])
    {
        return $this->findMongoRecord($this->uriCacheCollection, $filter, $options);
    }

    /**
     * Save a URI cache record
     *
     * @param array $record URI cache record
     *
     * @return array Saved record (with a new _id if it didn't have one)
     */
    public function saveUriCache($record)
    {
        return $this->saveMongoRecord($this->uriCacheCollection, $record);
    }

    /**
     * Find a single ontology enrichment record
     *
     * @param array $filter  Search filter
     * @param array $options Options such as sorting
     *
     * @return array|null
     */
    public function findOntologyEnrichment($filter, $options = [])
    {
        return $this->findMongoRecord(
            $this->ontologyEnrichmentCollection, $filter, $options
        );
    }

    /**
     * Get a record
     *
     * @param string $collection Collection
     * @param string $id         Record ID
     *
     * @return array|null
     */
    protected function getMongoRecord($collection, $id)
    {
        return $this->db->{$collection}->findOne(['_id' => $id]);
    }

    /**
     * Find a single record
     *
     * @param string $collection Collection
     * @param array  $filter     Search filter
     * @param array  $options    Options such as sorting
     *
     * @return array|null
     */
    protected function findMongoRecord($collection, $filter, $options)
    {
        return $this->db->{$collection}->findOne($filter, $options);
    }

    /**
     * Find records
     *
     * @param string $collection Collection
     * @param array  $filter     Search filter
     * @param array  $options    Options such as sorting
     *
     * @return MongoDB\Driver\Cursor
     */
    protected function findMongoRecords($collection, $filter, $options)
    {
        if (!isset($options['noCursorTimeout'])) {
            $options['noCursorTimeout'] = true;
        }
        return $this->db->{$collection}->find($filter, $options);
    }

    /**
     * Count records
     *
     * @param string $collection Collection
     * @param array  $filter     Search filter
     * @param array  $options    Options such as sorting
     *
     * @return int
     */
    protected function countMongoRecords($collection, $filter, $options)
    {
        return $this->counts
            ? $this->db->{$collection}->count($filter, $options)
            : 'the';
    }

    /**
     * Save a record
     *
     * @param string $collection   Collection
     * @param array  $record       Record
     * @param int    $writeConcern Optional write concern for the operation
     *
     * @return array Saved record (with a new _id if it didn't have one)
     */
    protected function saveMongoRecord($collection, $record, $writeConcern = null)
    {
        $params = [];
        if (null !== $writeConcern) {
            $params['writeConcern']
                = new \MongoDB\Driver\WriteConcern($writeConcern);
        }
        if (!isset($record['_id'])) {
            $res = $this->db->{$collection}->insertOne($record, $params);
            $record['_id'] = $res->getInsertedId();
        } else {
            $params['upsert'] = true;
            $this->db->{$collection}->replaceOne(
                ['_id' => $record['_id']],
                $record,
                $params
            );
        }
        return $record;
    }

    /**
     * Update a record
     *
     * @param string $collection Collection
     * @param string $id         Record ID
     * @param array  $fields     Modified fields
     * @param array  $remove     Removed fields
     *
     * @return void
     */
    protected function updateMongoRecord($collection, $id, $fields, $remove = [])
    {
        $params = [];
        if ($fields) {
            $params['$set'] = $fields;
        }
        if ($remove) {
            $params['$unset'] = $remove;
        }
        $this->db->{$collection}->updateOne(['_id' => $id], $params);
    }

    /**
     * Update multiple records
     *
     * @param string $collection Collection
     * @param array  $filter     Record ID
     * @param array  $fields     Modified fields
     * @param array  $remove     Removed fields
     *
     * @return void
     */
    protected function updateMongoRecords($collection, $filter, $fields,
        $remove = []
    ) {
        $params = [];
        if ($fields) {
            $params['$set'] = $fields;
        }
        if ($remove) {
            $params['$unset'] = $remove;
        }
        $this->db->{$collection}->updateMany($filter, $params);
    }

    /**
     * Delete a record
     *
     * @param string $collection Collection
     * @param string $id         Record ID
     *
     * @return void
     */
    protected function deleteMongoRecord($collection, $id)
    {
        $this->db->{$collection}->deleteOne(['_id' => $id]);
    }
}

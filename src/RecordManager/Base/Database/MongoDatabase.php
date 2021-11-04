<?php
/**
 * MongoDB access class
 *
 * PHP version 7
 *
 * Copyright (c) The National Library of Finland 2017-2021.
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
 * MongoDB access class
 *
 * This class encapsulates access to the underlying MongoDB database.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class MongoDatabase extends AbstractDatabase
{
    /**
     * Database url
     *
     * @var string
     */
    protected $url;

    /**
     * Mongo Client
     *
     * @var \MongoDB\Client
     */
    protected $mongoClient;

    /**
     * Mongo database
     *
     * @var \MongoDB\Database
     */
    protected $db;

    /**
     * Database name
     *
     * @var string
     */
    protected $databaseName;

    /**
     * Connection timeout
     *
     * @var int
     */
    protected $connectTimeout;

    /**
     * Socket read/write timeout
     *
     * @var int
     */
    protected $socketTimeout;

    /**
     * Process id that connected the database
     *
     * @var int
     */
    protected $pid = null;

    /**
     * Whether to report actual counts. When false, all count methods return 'the'
     * instead.
     *
     * @var bool
     */
    protected $counts = false;

    /**
     * Whether to use a MongoDB session.
     *
     * @var bool
     */
    protected $useSession;

    /**
     * Session ID
     *
     * @var \MongoDB\Model\BSONDocument
     */
    protected $sessionId = null;

    /**
     * Session refresh interval in seconds
     *
     * @var int
     */
    protected $sessionRefreshInterval = 60;

    /**
     * Last session refresh time
     *
     * @var int
     */
    protected $lastSessionRefresh = 0;

    /**
     * Constructor.
     *
     * @param array $config Database settings
     *
     * @throws \Exception
     */
    public function __construct(array $config)
    {
        parent::__construct($config);

        $this->url = $config['url'] ?? '';
        $this->databaseName = $config['database'] ?? '';
        $this->counts = !empty($config['counts']);
        $this->connectTimeout = $config['connect_timeout'] ?? 300000;
        $this->socketTimeout = $config['socket_timeout'] ?? 300000;
        $this->useSession = $config['session'] ?? true;
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
     * Convert a database timestamp to unix time
     *
     * @param mixed $timestamp Database timestamp
     *
     * @return int
     */
    public function getUnixTime($timestamp): int
    {
        return $timestamp->toDateTime()->getTimestamp();
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
     * @return \Traversable
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
            $this->recordCollection,
            $filter,
            $fields,
            $remove
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
     * @param mixed $id Record ID
     *
     * @return array|null
     */
    public function getDedup($id)
    {
        if (is_string($id)) {
            try {
                $id = new \MongoDB\BSON\ObjectId($id);
            } catch (\MongoDB\Driver\Exception\InvalidArgumentException $e) {
                // Invalid id, return null:
                return null;
            }
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
     * @return \MongoDB\Driver\Cursor
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
     * Remove old tracking collections
     *
     * @param int $minAge Minimum age in days. Default is 7 days.
     *
     * @return array Array of two arrays with collections removed and those whose
     * removal failed
     */
    public function cleanupTrackingCollections(int $minAge = 7)
    {
        $removed = [];
        $failed = [];
        foreach ($this->getDb()->listCollections() as $collection) {
            $collection = $collection->getName();
            if (strncmp($collection, 'tracking_', 9) !== 0) {
                continue;
            }
            $nameParts = explode('_', $collection);
            $collTime = $nameParts[2] ?? null;
            if (is_numeric($collTime)
                && $collTime < time() - $minAge * 60 * 60 * 24
            ) {
                try {
                    $this->getDb()->dropCollection($collection);
                    $removed[] = $collection;
                } catch (\Exception $e) {
                    $failed[] = $collection;
                }
            }
        }
        return compact('removed', 'failed');
    }

    /**
     * Create a new temporary tracking collection
     *
     * @return string
     */
    public function getNewTrackingCollection()
    {
        return 'tracking_' . getmypid() . '_' . time();
    }

    /**
     * Remove a temporary tracking collection
     *
     * @param string $collectionName The temporary collection name
     *
     * @return bool
     */
    public function dropTrackingCollection($collectionName)
    {
        if (strncmp($collectionName, 'tracking_', 4) !== 0) {
            throw new \Exception(
                "Invalid tracking collection name: '$collectionName'"
            );
        }
        $res = (array)$this->getDb()->dropCollection($collectionName);
        return (bool)$res['ok'];
    }

    /**
     * Add a record ID to a tracking collection
     *
     * @param string $collectionName The queue collection name
     * @param string $id             ID to add
     *
     * @return bool true if added, false if id already exists
     */
    public function addIdToTrackingCollection($collectionName, $id)
    {
        $params = [
            'writeConcern' => new \MongoDB\Driver\WriteConcern(1),
            'upsert' => true
        ];
        $res = $this->getDb()->{$collectionName}->replaceOne(
            ['_id' => $id],
            ['_id' => $id],
            $params
        );

        return $res->getMatchedCount() === 0;
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
            $this->ontologyEnrichmentCollection,
            $filter,
            $options
        );
    }

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
    public function saveLogMessage(
        string $context,
        string $msg,
        int $level,
        int $pid,
        int $timestamp
    ): void {
        $record = [
            'timestamp' => $this->getTimestamp($timestamp),
            'context' => $context,
            'message' => $msg,
            'level' => $level,
            'pid' => $pid,
        ];
        $this->saveMongoRecord($this->logMessageCollection, $record);
    }

    /**
     * Find log messages
     *
     * @param array $filter  Search filter
     * @param array $options Options such as sorting
     *
     * @return \Traversable
     */
    public function findLogMessages(array $filter, array $options = [])
    {
        return $this->findMongoRecords(
            $this->logMessageCollection,
            $filter,
            $options
        );
    }

    /**
     * Delete a log message
     *
     * @param mixed $id Message ID
     *
     * @return void
     */
    public function deleteLogMessage($id): void
    {
        $this->deleteMongoRecord($this->logMessageCollection, $id);
    }

    /**
     * Get a database connection
     *
     * @return \MongoDB\Database
     */
    public function getDb()
    {
        if (null === $this->db) {
            $this->mongoClient = new \MongoDB\Client(
                $this->url,
                [
                    'connectTimeoutMS' => (int)$this->connectTimeout,
                    'socketTimeoutMS' => (int)$this->socketTimeout,
                ]
            );
            $this->db = $this->mongoClient->{$this->databaseName};
            $this->pid = getmypid();
            if ($this->useSession) {
                $result = $this->db->command(['startSession' => 1]);
                $result->rewind();
                $session = $result->current();
                if (empty($session['id'])) {
                    throw new \Exception(
                        'Could not start MongoDB session: '
                        . var_export($session, true)
                    );
                }
                $this->sessionId = $session['id'];
                $this->lastSessionRefresh = time();
            }
        } elseif ($this->pid !== getmypid()) {
            throw new \Exception(
                'PID ' . getmypid() . ': database already connected by PID '
                . getmypid()
            );
        } else {
            $this->refreshSession();
        }
        return $this->db;
    }

    /**
     * Refresh the session whenever necessary
     *
     * @return void
     */
    protected function refreshSession()
    {
        if ($this->useSession && $this->sessionId
            && time() - $this->lastSessionRefresh >= $this->sessionRefreshInterval
        ) {
            // Do not call getDb to avoid circular reference
            $result = $this->db->command(
                [
                    'refreshSessions' => [
                        [
                            'id' => $this->sessionId['id']
                        ]
                    ]
                ]
            );
            $result->rewind();
            $result = $result->current();
            if (!$result['ok']) {
                throw new \Exception(
                    'Could not refresh MongoDB session: '
                    . var_export($result, true)
                );
            }
            $this->lastSessionRefresh = time();
        }
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
        $result = $this->getDb()->{$collection}->findOne(['_id' => $id]);
        return null === $result ? null : iterator_to_array($result);
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
        $result = $this->getDb()->{$collection}->findOne($filter, $options);
        return null === $result ? null : iterator_to_array($result);
    }

    /**
     * Find records
     *
     * @param string $collection Collection
     * @param array  $filter     Search filter
     * @param array  $options    Options such as sorting
     *
     * @return \MongoDB\Driver\Cursor
     */
    protected function findMongoRecords($collection, $filter, $options)
    {
        if (!isset($options['noCursorTimeout'])) {
            $options['noCursorTimeout'] = true;
        }
        if ($filter) {
            array_walk_recursive(
                $filter,
                function (&$value) {
                    if ($value instanceof Regex) {
                        $value = new \MongoDB\BSON\Regex((string)$value);
                    }
                }
            );
        }
        return $this->getDb()->{$collection}->find($filter, $options);
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
            ? $this->getDb()->{$collection}->count($filter, $options)
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
            $res = $this->getDb()->{$collection}->insertOne($record, $params);
            $record['_id'] = $res->getInsertedId();
        } else {
            $params['upsert'] = true;
            $this->getDb()->{$collection}->replaceOne(
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
        $this->getDb()->{$collection}->updateOne(['_id' => $id], $params);
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
    protected function updateMongoRecords(
        $collection,
        $filter,
        $fields,
        $remove = []
    ) {
        $params = [];
        if ($fields) {
            $params['$set'] = $fields;
        }
        if ($remove) {
            $params['$unset'] = $remove;
        }
        $this->getDb()->{$collection}->updateMany($filter, $params);
    }

    /**
     * Delete a record
     *
     * @param string $collection Collection
     * @param mixed  $id         Record ID
     *
     * @return void
     */
    protected function deleteMongoRecord($collection, $id)
    {
        $this->getDb()->{$collection}->deleteOne(['_id' => $id]);
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
        if (!isset($options['noCursorTimeout'])) {
            $options['noCursorTimeout'] = true;
        }
        $records = $findMethod($filter, $options);
        foreach ($records as $record) {
            if ($callback(iterator_to_array($record), $params) === false) {
                return;
            }
            $this->refreshSession();
        }
    }
}

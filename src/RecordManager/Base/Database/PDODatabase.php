<?php

/**
 * PDO access class
 *
 * PHP version 8
 *
 * Copyright (c) The National Library of Finland 2020-2021.
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

use function count;
use function in_array;
use function intval;
use function is_array;
use function is_bool;

/**
 * PDO access class
 *
 * This class encapsulates access to an underlying MySQL, MariaDB or other compatible
 * database. Currently at least the
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class PDODatabase extends AbstractDatabase
{
    /**
     * Database connection string
     *
     * @var string
     */
    protected $dsn;

    /**
     * Username
     *
     * @var string
     */
    protected $username;

    /**
     * Password
     *
     * @var string
     */
    protected $password;

    /**
     * Database
     *
     * @var ?\PDO
     */
    protected $db = null;

    /**
     * Process id that connected the database
     *
     * @var int
     */
    protected $pid = null;

    /**
     * Main fields in each table. Automatically filled.
     *
     * @var array
     */
    protected $mainFields = [];

    /**
     * Last fetched record attributes per collection
     *
     * @var array
     */
    protected $lastRecordAttrs = [];

    /**
     * Id's of records for last fetched attributes per collection
     *
     * @var array
     */
    protected $lastRecordAttrsId = [];

    /**
     * Whether to use index hints (MySQL/MariaDB)
     *
     * @var bool
     */
    protected $useIndexHints = false;

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

        $this->dsn = $config['connection'] ?? '';
        $this->username = $config['username'] ?? '';
        $this->password = $config['password'] ?? '';
        $this->useIndexHints = (bool)($config['use_index_hints'] ?? true);
    }

    /**
     * Get a timestamp
     *
     * @param int $time Optional unix time (default = current time)
     *
     * @return mixed
     */
    public function getTimestamp($time = null)
    {
        return date('Y-m-d H:i:s', $time === null ? time() : $time);
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
        return strtotime($timestamp);
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
        return $this->getPDORecord($this->recordCollection, $id);
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
        return $this->findPDORecord($this->recordCollection, $filter, $options);
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
        return $this->findPDORecords($this->recordCollection, $filter, $options);
    }

    /**
     * Count records
     *
     * @param array $filter  Search filter
     * @param array $options Options such as sorting
     *
     * @return int|string
     */
    public function countRecords($filter, $options = [])
    {
        return $this->countPDORecords($this->recordCollection, $filter, $options);
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
        return $this->savePDORecord($this->recordCollection, $record);
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
        $this->updatePDORecord($this->recordCollection, $id, $fields, $remove);
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
        $this->updatePDORecords(
            [$this, 'findRecords'],
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
        $this->deletePDORecord($this->recordCollection, $id);
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
        return $this->getPDORecord($this->stateCollection, $id);
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
        return $this->savePDORecord($this->stateCollection, $record);
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
        $this->deletePDORecord($this->stateCollection, $id);
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
        return $this->getPDORecord($this->dedupCollection, $id);
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
        return $this->findPDORecord($this->dedupCollection, $filter, $options);
    }

    /**
     * Find dedup records
     *
     * @param array $filter  Search filter
     * @param array $options Options such as sorting
     *
     * @return \Traversable
     */
    public function findDedups($filter, $options = [])
    {
        return $this->findPDORecords($this->dedupCollection, $filter, $options);
    }

    /**
     * Count dedup records
     *
     * @param array $filter  Search filter
     * @param array $options Options such as sorting
     *
     * @return int|string
     */
    public function countDedups($filter, $options = [])
    {
        return $this->countPDORecords($this->dedupCollection, $filter, $options);
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
        return $this->savePDORecord($this->dedupCollection, $record);
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
        $this->deletePDORecord($this->dedupCollection, $id);
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

        $res = $this->dbQuery("show tables like 'tracking_%'");
        while ($collection = $res->fetchColumn()) {
            $nameParts = explode('_', (string)$collection);
            $collTime = $nameParts[2] ?? null;
            if (
                is_numeric($collTime)
                && $collTime != time() - $minAge * 60 * 60 * 24
            ) {
                try {
                    $this->dbQuery("drop table $collection");
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
        $collectionName = 'tracking_' . getmypid() . '_' . time();
        $this->dbQuery(
            "create table {$collectionName} ("
            . '_id VARCHAR(255) PRIMARY KEY'
            . ')'
        );
        $this->trackingCollections[$collectionName] = true;
        return $collectionName;
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
        if (!str_starts_with($collectionName, 'tracking_')) {
            throw new \Exception(
                "Invalid tracking collection name: '$collectionName'"
            );
        }
        try {
            $this->dbQuery("drop table $collectionName");
        } catch (\Exception $e) {
            return false;
        }
        if (isset($this->trackingCollections[$collectionName])) {
            unset($this->trackingCollections[$collectionName]);
        }
        return true;
    }

    /**
     * Add a record ID to a tracking collection
     *
     * @param string $collectionName The queue collection name
     * @param string $id             ID to add
     *
     * @return bool True if added, false if id already exists
     */
    public function addIdToTrackingCollection($collectionName, $id)
    {
        $res = $this->dbQuery(
            "insert ignore into $collectionName (_id) values (?)",
            [$id]
        );
        return $res->rowCount() > 0;
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
        return $this->findPDORecord($this->uriCacheCollection, $filter, $options);
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
        try {
            return $this->savePDORecord($this->uriCacheCollection, $record);
        } catch (\PDOException $e) {
            // Since this can be done by multiple workers simultaneously, we might
            // encounter duplicate inserts at the same time, so ignore duplicate key
            // errors.
            if (($e->errorInfo[1] ?? 0) == 1062) {
                return $record;
            }
            throw $e;
        }
    }

    /**
     * Find a single linked data enrichment record
     *
     * @param array $filter  Search filter
     * @param array $options Options such as sorting
     *
     * @return array|null
     */
    public function findLinkedDataEnrichment($filter, $options = [])
    {
        return $this->findPDORecord(
            $this->linkedDataEnrichmentCollection,
            $filter,
            $options
        );
    }

    /**
     * Save a linked data enrichment record
     *
     * @param array $record Linked data enrichment record
     *
     * @return array Saved record (with a new _id if it didn't have one)
     */
    public function saveLinkedDataEnrichment($record)
    {
        $record['timestamp'] = $this->getTimestamp();
        try {
            return $this->savePDORecord(
                $this->linkedDataEnrichmentCollection,
                $record
            );
        } catch (\PDOException $e) {
            // Since this can be done by multiple workers simultaneously, we might
            // encounter duplicate inserts at the same time, so ignore duplicate key
            // errors.
            if (($e->errorInfo[1] ?? 0) == 1062) {
                return $record;
            }
            throw $e;
        }
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
        $this->savePDORecord($this->logMessageCollection, $record);
    }

    /**
     * Find log messages
     *
     * @param array $filter  Search filter
     * @param array $options Options such as sorting
     *
     * @return \Traversable
     */
    public function findLogMessages($filter, $options = [])
    {
        return $this->findPDORecords($this->logMessageCollection, $filter, $options);
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
        $this->deletePDORecord($this->logMessageCollection, $id);
    }

    /**
     * Reset the database connection if it's open
     *
     * @return void
     */
    public function resetConnection(): void
    {
        $this->db = null;
    }

    /**
     * Get all attributes for a record
     *
     * @param string $collection Collection
     * @param string $id         Record ID
     *
     * @return array
     */
    public function getRecordAttrs(string $collection, string $id): array
    {
        if (($this->lastRecordAttrsId[$collection] ?? '') === $id) {
            return $this->lastRecordAttrs[$collection];
        }
        $res = $this->dbQuery(
            "select attr, value from {$collection}_attrs where parent_id=?",
            [$id]
        );
        $attrs = [];
        while ($row = $res->fetch()) {
            $attrs[$row['attr']][] = $row['value'];
        }

        $this->lastRecordAttrsId[$collection] = $id;
        $this->lastRecordAttrs[$collection] = $attrs;
        return $attrs;
    }

    /**
     * Get database handle
     *
     * @return \PDO
     */
    public function getDb(): \PDO
    {
        if (null === $this->db) {
            $this->db = new \PDO($this->dsn, $this->username, $this->password);
            $this->db
                ->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
            $this->pid = getmypid();
        } elseif ($this->pid !== getmypid()) {
            throw new \Exception(
                'PID ' . getmypid() . ': database already connected by PID '
                . $this->pid
            );
        }
        return $this->db;
    }

    /**
     * Get a record
     *
     * @param string $collection Collection
     * @param string $id         Record ID
     *
     * @return array|null
     */
    protected function getPDORecord(string $collection, string $id)
    {
        $res = $this->dbQuery("select * from $collection where _id=?", [$id]);
        $record = $res->fetch();
        if ($record && in_array($collection, ['record', 'dedup'])) {
            $record += $this->getRecordAttrs($collection, $record['_id']);
        }
        return $record ?: null;
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
    protected function findPDORecord(
        string $collection,
        array $filter,
        array $options
    ) {
        [$where, $params] = $this->filterToSQL($collection, $filter);
        [$fields, $sqlOptions] = $this->optionsToSQL($options);
        $sql = "select $fields from $collection";
        if ($where) {
            $sql .= " where $where";
        }
        if ($sqlOptions) {
            $sql .= " $sqlOptions";
        }
        $result = $this->dbQuery($sql, $params)->fetch();
        if ($result) {
            if ('*' === $fields && in_array($collection, ['record', 'dedup'])) {
                $result += $this->getRecordAttrs($collection, $result['_id']);
            }
        }
        return $result ?: null;
    }

    /**
     * Find records
     *
     * @param string $collection Collection
     * @param array  $filter     Search filter
     * @param array  $options    Options such as sorting
     *
     * @return \Traversable
     */
    protected function findPDORecords(
        string $collection,
        array $filter,
        array $options
    ): \Traversable {
        [$where, $params] = $this->filterToSQL($collection, $filter);
        [$fields, $sqlOptions] = $this->optionsToSQL($options);
        $sql = "select $fields from $collection";
        if ($hints = $this->getIndexHints($collection, $filter, $options)) {
            $sql .= " $hints";
        }
        if ($where) {
            $sql .= " where $where";
        }
        if ($sqlOptions) {
            $sql .= " $sqlOptions";
        }
        $res = $this->dbQuery($sql, $params);
        if ('*' === $fields && in_array($collection, ['record', 'dedup'])) {
            return new PDOResultIterator($res, $this, $collection);
        }
        return $res;
    }

    /**
     * Count records
     *
     * @param string $collection Collection
     * @param array  $filter     Search filter
     * @param array  $options    Options such as sorting
     *
     * @return int|string
     */
    protected function countPDORecords(
        string $collection,
        array $filter,
        array $options
    ) {
        [$where, $params] = $this->filterToSQL($collection, $filter);
        [, $sqlOptions] = $this->optionsToSQL($options);
        $sql = "select count(*) from $collection";
        if ($where) {
            $sql .= " where $where";
        }
        if ($sqlOptions) {
            $sql .= " $sqlOptions";
        }
        return intval($this->dbQuery($sql, $params)->fetchColumn() ?: 0);
    }

    /**
     * Save a record
     *
     * @param string $collection Collection
     * @param array  $record     Record
     * @param array  $oldRecord  Old record (to avoid re-reading it from database)
     *
     * @return array Saved record (with a new _id if it didn't have one)
     */
    protected function savePDORecord($collection, $record, $oldRecord = null)
    {
        $db = $this->getDb();
        $db->beginTransaction();
        try {
            $mainFields = $this->getMainFields($collection);
            $attrFields = [];

            if (null === $oldRecord) {
                $oldRecord = !empty($record['_id'])
                    ? $this->getPDORecord($collection, $record['_id']) : null;
            }
            if ($oldRecord) {
                $updateFields = [];
                $updateParams = [];
                foreach (array_keys($oldRecord + $record) as $key) {
                    $value = $record[$key] ?? null;
                    if (in_array($key, $mainFields)) {
                        $oldValue = $oldRecord[$key] ?? null;
                        if ('_id' !== $key && $oldValue !== $value) {
                            $updateFields[] = $key;
                            $updateParams[] = $value;
                        }
                    } else {
                        $attrFields[$key] = $value ?: [];
                    }
                }
                if ($updateFields) {
                    $sql = "UPDATE $collection SET ";
                    $sql .= implode(
                        ', ',
                        array_map(
                            function ($s) {
                                return "$s=?";
                            },
                            $updateFields
                        )
                    );
                    $sql .= ' WHERE _id=?';

                    $updateParams[] = $record['_id'];
                    $this->dbQuery($sql, $updateParams);
                }
            } else {
                $insertFields = [];
                $insertParams = [];
                foreach ($record as $key => $value) {
                    if (in_array($key, $mainFields)) {
                        $insertFields[] = $key;
                        $insertParams[] = $value;
                    } else {
                        $attrFields[$key] = $value;
                    }
                }
                $sql = "INSERT INTO $collection (" . implode(',', $insertFields)
                    . ') VALUES ('
                    . rtrim(str_repeat('?,', count($insertFields)), ',')
                    . ') ';

                $this->dbQuery($sql, $insertParams);
                if (!isset($record['_id'])) {
                    $record['_id'] = $db->lastInsertId($collection);
                }
            }

            if (in_array($collection, ['record', 'dedup'])) {
                // Go through existing attrs and new attrs and process them
                $deleteAttrs = [];
                $insertAttrs = [];
                $existingAttrs = $oldRecord
                    ? array_diff_key($oldRecord, array_flip($mainFields)) : [];

                foreach ($existingAttrs as $key => $values) {
                    foreach ($values as $value) {
                        if (!in_array($value, $attrFields[$key] ?? [])) {
                            $deleteAttrs[$key][] = $value;
                        }
                    }
                }
                foreach ($attrFields as $key => $values) {
                    foreach ($values as $value) {
                        if (!in_array($value, $existingAttrs[$key] ?? [])) {
                            $insertAttrs[$key][] = $value;
                        }
                    }
                }
                foreach ($deleteAttrs as $key => $values) {
                    foreach ($values as $value) {
                        $this->dbQuery(
                            "DELETE FROM {$collection}_attrs WHERE parent_id=?"
                            . ' AND attr=? AND value=?',
                            [
                                $record['_id'],
                                $key,
                                $value,
                            ]
                        );
                    }
                }
                foreach ($insertAttrs as $key => $values) {
                    foreach ($values as $value) {
                        $this->dbQuery(
                            "INSERT INTO {$collection}_attrs"
                            . ' (parent_id, attr, value) VALUES (?, ?, ?)',
                            [
                                $record['_id'],
                                $key,
                                $value,
                            ]
                        );
                    }
                }
            }
            $db->commit();
        } catch (\Exception $e) {
            // Try to roll back, but make sure not to mask any issue that could have
            // been caused during commit:
            try {
                $db->rollback();
            } catch (\Exception $e2) {
                // Do nothing
            }
            throw $e;
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
    protected function updatePDORecord($collection, $id, $fields, $remove = [])
    {
        $oldRecord = $record = $this->getPDORecord($collection, $id);
        $record = array_replace($record, $fields);
        foreach ($remove as $key) {
            if (isset($record[$key])) {
                unset($record[$key]);
            }
        }
        $this->savePDORecord($collection, $record, $oldRecord);
    }

    /**
     * Update multiple records
     *
     * @param Callable $findMethod Method used to find records to update
     * @param string   $collection Collection
     * @param array    $filter     Record ID
     * @param array    $fields     Modified fields
     * @param array    $remove     Removed fields
     *
     * @return void
     */
    protected function updatePDORecords(
        callable $findMethod,
        $collection,
        $filter,
        $fields,
        $remove = []
    ) {
        $this->iterate(
            $findMethod,
            $filter,
            [],
            function ($oldRecord) use ($collection, $fields, $remove) {
                $record = array_replace($oldRecord, $fields);
                foreach (array_keys($remove) as $key) {
                    if (isset($record[$key])) {
                        unset($record[$key]);
                    }
                }
                $this->savePDORecord($collection, $record, $oldRecord);
            }
        );
    }

    /**
     * Delete a record
     *
     * @param string $collection Collection
     * @param string $id         Record ID
     *
     * @return void
     */
    protected function deletePDORecord($collection, $id)
    {
        $this->dbQuery("DELETE FROM $collection WHERE _id=?", [$id]);
    }

    /**
     * Prepare and execute an SQL query
     *
     * @param string $sql    SQL statement
     * @param array  $params Any parameters
     *
     * @return \PDOStatement
     */
    protected function dbQuery(string $sql, array $params = [])
    {
        $stmt = $this->getDb()->prepare($sql);
        if (false === $stmt) {
            $errorInfo = $this->getDb()->errorInfo();
            $error = implode('; ', $errorInfo);
            throw new \Exception(
                "Prepare failed for '$sql': $error"
            );
        }
        foreach ($params as &$param) {
            if (is_bool($param)) {
                $param = $param ? 1 : 0;
            }
        }
        unset($param);

        if ($stmt->execute($params)) {
            return $stmt;
        }

        throw new \Exception(
            "Query '$sql' failed: " . print_r($stmt->errorInfo(), true)
        );
    }

    /**
     * Convert filters to an SQL query
     *
     * This is by no means complete, but does enough for our purposes.
     *
     * @param string $collection Collection
     * @param array  $filter     Filter
     * @param string $operator   Boolean operator for combining fields
     *
     * @return array
     */
    protected function filterToSQL(
        string $collection,
        array $filter,
        $operator = 'AND'
    ): array {
        $where = [];
        $params = [];
        $mainFields = $this->getMainFields($collection);
        foreach ($filter as $field => $value) {
            if ('$or' === $field) {
                $subQueries = [];
                $subQueryParams = [];
                foreach ($value as $subFilter) {
                    [$subPartQuery, $subPartParams]
                        = $this->filterToSQL($collection, $subFilter, 'AND');
                    $subQueries[] = $subPartQuery;
                    $subQueryParams = array_merge($subQueryParams, $subPartParams);
                }
                $where[] = '(' . implode(' OR ', $subQueries) . ')';
                $params = array_merge($params, $subQueryParams);
                continue;
            }
            if (is_array($value)) {
                $keys = array_keys($value);
                $supportedKeys = [
                    '$or', '$nor', '$in', '$ne', '$exists', '$gt', '$gte', '$lt',
                    '$lte',
                ];
                if (array_diff($keys, $supportedKeys)) {
                    throw new \Exception(
                        'Operator not supported: ' . print_r($value, true)
                    );
                }
                if (isset($value['$or'])) {
                    $whereParts = [];
                    [$wherePart, $partParams]
                        = $this->filterToSQL($collection, $value['$or'], 'OR');
                    $where[] = "($wherePart)";
                    $params = array_merge($params, $partParams);
                }
                if (isset($value['$nor'])) {
                    $whereParts = [];
                    [$wherePart, $partParams]
                        = $this->filterToSQL($collection, $value['$nor'], 'OR');
                    $where[] = "NOT ($wherePart)";
                    $params = array_merge($params, $partParams);
                }
                if (isset($value['$in'])) {
                    $whereParts = [];
                    $values = (array)$value['$in'];
                    $valueCount = count($values);
                    // Special handling for null
                    $nullKey = array_search(null, $values, true);
                    if (false !== $nullKey) {
                        unset($values[$nullKey]);
                        --$valueCount;
                        $whereParts[] = $this->mapFieldToQuery(
                            $collection,
                            $field,
                            ' IS NULL'
                        );
                        if ($valueCount) {
                            $whereParts[] = 'OR';
                        }
                    }
                    if ($valueCount > 1) {
                        $match = ' IN (' . rtrim(str_repeat('?,', $valueCount), ',')
                            . ')';
                        $whereParts[]
                            = $this->mapFieldToQuery($collection, $field, $match);
                        $params = array_merge($params, $values);
                    } elseif ($values) {
                        $whereParts[]
                            = $this->mapFieldToQuery($collection, $field, '=?');
                        $params[] = reset($values);
                    }
                    if (count($whereParts) > 1) {
                        $where[] = '(' . implode(' ', $whereParts) . ')';
                    } else {
                        $where[] = reset($whereParts);
                    }
                }
                if (isset($value['$ne'])) {
                    $values = (array)$value['$ne'];
                    $valueCount = count($values);
                    if ($valueCount > 1) {
                        $match = ' NOT IN ('
                            . rtrim(str_repeat('?,', $valueCount), ',')
                            . ')';
                        $where[]
                            = $this->mapFieldToQuery($collection, $field, $match);
                        $params = array_merge($params, $values);
                    } else {
                        $where[]
                            = $this->mapFieldToQuery($collection, $field, '<>?');
                        $params[] = $values[0];
                    }
                }
                if (isset($value['$exists'])) {
                    if (in_array($field, $mainFields)) {
                        $match = $value['$exists'] ? ' IS NOT NULL' : ' IS NULL';
                        $where[]
                            = $this->mapFieldToQuery($collection, $field, $match);
                    } else {
                        $sub = $this
                            ->mapFieldToQuery($collection, $field, ' IS NOT NULL');
                        $where[] = ($value['$exists'] ? '' : 'NOT ') . $sub;
                    }
                }
                if (isset($value['$gt'])) {
                    $where[] = $this->mapFieldToQuery($collection, $field, '>?');
                    $params[] = $value['$gt'];
                }
                if (isset($value['$gte'])) {
                    $where[] = $this->mapFieldToQuery($collection, $field, '>=?');
                    $params[] = $value['$gte'];
                }
                if (isset($value['$lt'])) {
                    $where[] = $this->mapFieldToQuery($collection, $field, '<?');
                    $params[] = $value['$lt'];
                }
                if (isset($value['$lte'])) {
                    $where[] = $this->mapFieldToQuery($collection, $field, '<=?');
                    $params[] = $value['$lte'];
                }
            } else {
                if ($value instanceof \RecordManager\Base\Database\Regex) {
                    $params[] = (string)$value;
                    $where[]
                        = $this->mapFieldToQuery($collection, $field, ' REGEXP ');
                } else {
                    $where[] = $this->mapFieldToQuery($collection, $field, '=?');
                    $params[] = $value;
                }
            }
        }
        return [implode(" $operator ", $where), $params];
    }

    /**
     * Map a query field to a column in the main table or _attrs table
     *
     * @param string $collection Collection name
     * @param string $field      Field name
     * @param string $operator   Matching operator
     *
     * @return string
     */
    protected function mapFieldToQuery(
        string $collection,
        string $field,
        string $operator
    ): string {
        $mainFields = $this->getMainFields($collection);
        if (in_array($field, $mainFields)) {
            return "$field$operator";
        }
        $result = "_id IN (SELECT parent_id FROM {$collection}_attrs ca WHERE"
            . " ca.attr='$field' AND ca.value$operator)";

        if (' IS NULL' === $operator) {
            $result = "($result OR _id NOT IN (SELECT parent_id FROM"
                . " {$collection}_attrs ca WHERE ca.attr='$field'))";
        }

        return $result;
    }

    /**
     * Convert options to SQL
     *
     * @param array $options Options
     *
     * @return array Fields and query parameters
     */
    protected function optionsToSQL(array $options): array
    {
        if (
            array_diff(array_keys($options), ['skip', 'limit', 'sort', 'projection'])
        ) {
            throw new \Exception('Unsupported options: ' . print_r($options, true));
        }
        $fields = !empty($options['projection'])
            ? array_keys($options['projection']) : ['*'];
        $sqlOptions = [];
        if (isset($options['sort'])) {
            $sort = [];
            foreach ($options['sort'] as $field => $dir) {
                $sort[] = $field . (-1 === $dir ? ' desc' : ' asc');
            }
            $sqlOptions[] = 'ORDER BY ' . implode(', ', $sort);
        }
        $limit = '';
        if (isset($options['skip'])) {
            $limit = $options['skip'] . ',';
        }
        if (isset($options['limit'])) {
            $limit .= $options['limit'];
        }
        if ($limit) {
            $sqlOptions[] = "LIMIT $limit";
        }

        return [implode(',', $fields), implode(' ', $sqlOptions)];
    }

    /**
     * Get main fields for a collection
     *
     * @param string $collection Collection
     *
     * @return array
     */
    protected function getMainFields(string $collection): array
    {
        if (!isset($this->mainFields[$collection])) {
            $res = $this->dbQuery("SHOW COLUMNS FROM $collection");
            while ($row = $res->fetch()) {
                $this->mainFields[$collection][] = $row['Field'];
            }
        }
        return $this->mainFields[$collection];
    }

    /**
     * Check if the database is MySQL or MariaDB
     *
     * @return bool
     */
    protected function isMySQLCompatible(): bool
    {
        return str_starts_with($this->dsn, 'mysql')
            || str_starts_with($this->dsn, 'mariadb');
    }

    /**
     * Get index hints
     *
     * @param string $collection Collection
     * @param array  $filter     Search filter
     * @param array  $options    Options such as sorting
     *
     * @return string
     */
    protected function getIndexHints(string $collection, array $filter, array $options): string
    {
        if (!$this->useIndexHints || !$this->isMySQLCompatible()) {
            return '';
        }
        if ('record' === $collection && isset($options['sort']['_id']) && isset($filter['source_id'])) {
            return 'USE INDEX (source_update_needed)';
        }
        return '';
    }
}

<?php
/**
 * PDO access class
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
     * Database
     *
     * @var \PDO
     */
    protected $db = null;

    /**
     * Process id that connected the database
     *
     * @var int
     */
    protected $pid = null;

    /**
     * Whether the database supports MySQL syntax
     *
     * @var bool
     */
    protected $mysql;

    /**
     * Main fields in each table. Automatically filled.
     *
     * @var array
     */
    protected $mainFields = [];

    /**
     * Last fetched record attributes
     *
     * @var array
     */
    protected $lastRecordAttrs = [];

    /**
     * Id of record for last fetched attributes
     *
     * @var array
     */
    protected $lastRecordAttrId = [];

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
        parent::__construct($url, $database, $settings);

        $this->mysql = strncmp($this->dsn, 'mysql', 5) === 0;
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
     * @return int
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
     * @param string|ObjectID $id Record ID
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
     * @return int
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

        $res = $this->dbQuery("show tables like 'mr_record_%'");
        while ($collection = $res->fetchColumn()) {
            $nameParts = explode('_', $collection);
            $collTime = $nameParts[4] ?? null;
            if (is_numeric($collTime)
                && $collTime != $lastRecordTime
                && $collTime < time() - 60 * 60 * 24 * 7
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
     * Check for an existing queue collection with the given parameters
     *
     * @param string $hash           Hash of parameters used to identify the
     *                               collection
     * @param int    $fromDate       Timestamp of processing start date
     * @param int    $lastRecordTime Newest record timestamp
     *
     * @return string
     */
    public function getExistingQueueCollection($hash, $fromDate, $lastRecordTime)
    {
        $collectionName = "mr_record_{$hash}_{$fromDate}_{$lastRecordTime}";
        $res = $this->dbQuery("show tables like ?", [$collectionName]);
        if ($res->fetch()) {
            return $collectionName;
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
        $this->dbQuery(
            "create table if not exists {$collectionName} ("
            . '_id VARCHAR(255) PRIMARY KEY'
            . ')'
        );
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

        $this->dbQuery("rename table $collectionName to $newName");
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
        try {
            $this->dbQuery('drop table ?', [$collectionName]);
        } catch (\Exception $e) {
            return false;
        }
        return true;
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
        $this->dbQuery("insert ignore into $collectionName (_id) values (?)", [$id]);
    }

    /**
     * Get IDs in queue
     *
     * @param string $collectionName The queue collection name
     * @param array  $options        Options such as skip and limit
     *
     * @return \Traversable
     */
    public function getQueuedIds($collectionName, $options)
    {
        return $this->findPDORecords($collectionName, [], $options);
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
        return $this->savePDORecord($this->uriCacheCollection, $record);
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
        return $this->findPDORecord(
            $this->ontologyEnrichmentCollection, $filter, $options
        );
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
    protected function findPDORecord(string $collection, array $filter,
        array $options
    ) {
        list($where, $params) = $this->filterToSQL($collection, $filter);
        list($fields, $sqlOptions) = $this->optionsToSQL($options);
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
        return $result;
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
    protected function findPDORecords(string $collection, array $filter,
        array $options
    ): \Traversable {
        list($where, $params) = $this->filterToSQL($collection, $filter);
        list($fields, $sqlOptions) = $this->optionsToSQL($options);
        $sql = "select $fields from $collection";
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
    protected function countPDORecords(string $collection, array $filter,
        array $options
    ) {
        if (!$this->counts) {
            return 'the';
        }

        list($where, $params) = $this->filterToSQL($collection, $filter);
        list(, $sqlOptions) = $this->optionsToSQL($options);
        $sql = "select count(*) from $collection where $where $sqlOptions";
        return $this->dbQuery($sql, $params)->fetchColumn();
    }

    /**
     * Save a record
     *
     * @param string $collection Collection
     * @param array  $record     Record
     *
     * @return array Saved record (with a new _id if it didn't have one)
     */
    protected function savePDORecord($collection, $record)
    {
        $attrFields = [];
        $mainFields = $this->getMainFields($collection);
        $insertFields = [];
        $updateFields = [];
        $updateParams = [];
        foreach ($record as $key => $value) {
            if (in_array($key, $mainFields)) {
                $insertFields[] = $key;
                $insertParams[] = $value;
                if ('_id' !== $key) {
                    $updateFields[] = $key;
                    $updateParams[] = $value;
                }
            } else {
                $attrFields[$key] = $value;
            }
        }

        $existingAttrs = !empty($record['_id'])
            && in_array($collection, ['record', 'dedup'])
            ? $this->getRecordAttrs($collection, $record['_id']) : [];
        $db = $this->getDb();
        $db->beginTransaction();
        try {
            $params = $insertParams;
            $updateSQL = '';
            if (!empty($record['_id'])) {
                if ($this->mysql) {
                    $updateSQL = 'on duplicate key update ';
                } else {
                    $updateSQL = 'on conflict(_id) set ';
                }
                $updateSQL .= implode(
                    ', ',
                    array_map(
                        function ($s) {
                            return "$s=?";
                        },
                        $updateFields
                    )
                );

                $params = array_merge($params, $updateParams);
            }
            $sql = "insert into $collection (" . implode(',', $insertFields)
                . ') VALUES (' . rtrim(str_repeat('?,', count($insertFields)), ',')
                . ') ' . $updateSQL;

            $this->dbQuery($sql, $params);
            if (!isset($record['_id'])) {
                $record['_id'] = $db->lastInsertId($collection);
            }
            if (in_array($collection, ['record', 'dedup'])) {
                // Go through existing attrs and new attrs and process them
                $deleteAttrs = [];
                $insertAttrs = [];
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
                    foreach ((array)$values as $value) {
                        $this->dbQuery(
                            "delete from {$collection}_attrs where parent_id=?"
                            . ' and attr=? and value=?',
                            [
                                $record['_id'],
                                $key,
                                $value
                            ]
                        );
                    }
                }
                foreach ($insertAttrs as $key => $values) {
                    foreach ((array)$values as $value) {
                        $this->dbQuery(
                            "insert into {$collection}_attrs"
                            . ' (parent_id, attr, value) values (?, ?, ?)',
                            [
                                $record['_id'],
                                $key,
                                $value
                            ]
                        );
                    }
                }
            }
            $db->commit();
        } catch (\Exception $e) {
            $db->rollback();
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
        $record = $this->getPDORecord($collection, $id);
        $record = array_replace($record, $fields);
        foreach ($remove as $key) {
            if (isset($record[$key])) {
                unset($record[$key]);
            }
        }
        $this->savePDORecord($collection, $record);
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
    protected function updatePDORecords($collection, $filter, $fields,
        $remove = []
    ) {
        foreach ($this->findPDORecords($collection, $filter, []) as $record) {
            $record = array_replace($record, $fields);
            foreach ($remove as $key) {
                if (isset($record[$key])) {
                    unset($record[$key]);
                }
            }
            $this->savePDORecord($collection, $record);
        }
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
        $this->dbQuery("delete from $collection where _id=?", [$id]);
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
            throw new \Exception(
                "Prepare failed for '$sql': " . $this->getDb()->error
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
    protected function filterToSQL(string $collection, array $filter,
        $operator = 'and'
    ): array {
        $where = [];
        $params = [];
        foreach ($filter as $field => $value) {
            if (is_array($value)) {
                $keys = array_keys($value);
                $supportedKeys = [
                    '$or', '$nor', '$in', '$ne', '$exists', '$gt', '$gte', '$lt',
                    '$lte'
                ];
                if (array_diff($keys, $supportedKeys)) {
                    throw new \Exception(
                        'Operator not supported: ' . print_r($value, true)
                    );
                }
                if (isset($value['$or'])) {
                    $whereParts = [];
                    list($wherePart, $partParams)
                        = $this->filterToSQL($collection, $value['$or'], 'or');
                    $where[] = "($wherePart)";
                    $params = array_merge($params, $partParams);
                }
                if (isset($value['$nor'])) {
                    $whereParts = [];
                    list($wherePart, $partParams)
                        = $this->filterToSQL($collection, $value['$nor'], 'or');
                    $where[] = "not ($wherePart)";
                    $params = array_merge($params, $partParams);
                }
                if (isset($value['$in'])) {
                    $whereParts = [];
                    $values = (array)$value['$in'];
                    $valueCount = count($values);
                    // Special handling for null
                    $nullKey = array_search(null, $values);
                    if (false !== $nullKey) {
                        unset($values[$nullKey]);
                        --$valueCount;
                        $whereParts[] = $this->mapFieldToQuery(
                            $collection, $field, ' is null OR'
                        );
                    }
                    if ($valueCount > 1) {
                        $match = ' in (' . rtrim(str_repeat('?,', $valueCount), ',')
                            . ')';
                        $whereParts[]
                            = $this->mapFieldToQuery($collection, $field, $match);
                        $params = array_merge($params, $values);
                    } else {
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
                        $match = ' not in ('
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
                    $match = $value['$exists'] ? ' is not null' : ' is null';
                    $where[] = $this->mapFieldToQuery($collection, $field, $match);
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
                if ($value instanceof Regex) {
                    $params[] = (string)$value;
                    $where[]
                        = $this->mapFieldToQuery($collection, $field, ' regexp ');
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
    protected function mapFieldToQuery(string $collection, string $field,
        string $operator
    ): string {
        $mainFields = $this->getMainFields($collection);
        if (in_array($field, $mainFields)) {
            return "$field$operator";
        }
        $result = "exists (select * from {$collection}_attrs ca where"
            . " ca.parent_id={$collection}._id and ca.attr='$field'"
            . " and ca.value$operator)";

        if (' is null' === $operator) {
            $result = "($result OR not exists (select * from {$collection}_attrs"
                . " ca where ca.parent_id={$collection}._id and ca.attr='$field'))";
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
        if (array_diff(array_keys($options), ['skip', 'limit', 'sort', 'projection'])
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
            $res = $this->dbQuery("show columns from $collection");
            while ($row = $res->fetch()) {
                $this->mainFields[$collection][] = $row['Field'];
            }
        }
        return $this->mainFields[$collection];
    }

    /**
     * Get database handle
     *
     * @return \PDO
     */
    public function getDb(): \PDO
    {
        if (null === $this->db) {
            $this->db = new \PDO(
                $this->dsn . ';_xpid=' . getmypid(),
                $this->settings['username'] ?? '',
                $this->settings['password'] ?? ''
            );
            $this->db
                ->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
            $this->pid = getmypid();
        } elseif ($this->pid !== getmypid()) {
            throw new \Exception(
                'PID ' . getmypid() . ': database already connected by PID '
                . getmypid()
            );
        }
        return $this->db;
    }
}

/**
 * Result iterator class that adds any attributes to each returned record
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class PDOResultIterator extends \IteratorIterator
{
    /**
     * Database
     *
     * @var \PDODatabase
     */
    protected $db;

    /**
     * Collection
     *
     * @var string
     */
    protected $collection;

    /**
     * Constructor
     *
     * @param \Traversable $iterator   Iterator
     * @param PDODatabase  $db         Database
     * @param string       $collection Collection
     */
    public function __construct(\Traversable $iterator, PDODatabase $db,
        string $collection
    ) {
        parent::__construct($iterator);

        $this->db = $db;
        $this->collection = $collection;
    }

    /**
     * Get the current value
     *
     * @return mixed
     */
    public function current()
    {
        $result = parent::current();
        if ($result) {
            $result += $this->db->getRecordAttrs($this->collection, $result['_id']);
        }
        return $result;
    }
}

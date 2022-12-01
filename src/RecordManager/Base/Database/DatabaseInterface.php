<?php
/**
 * Database interface class
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
 * Database interface class
 *
 * This class provides all the methods a database class would publish.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
interface DatabaseInterface
{
    /**
     * Constructor.
     *
     * @param array $config Database settings
     *
     * @throws \Exception
     */
    public function __construct(array $config);

    /**
     * Get a timestamp in the format the underlying database supports
     *
     * @param int $time Optional unix time (default = current time)
     *
     * @return mixed
     */
    public function getTimestamp($time = null);

    /**
     * Convert a database timestamp to unix time
     *
     * @param mixed $timestamp Database timestamp
     *
     * @return int
     */
    public function getUnixTime($timestamp): int;

    /**
     * Get a record
     *
     * @param string $id Record ID
     *
     * @return array|null
     */
    public function getRecord($id);

    /**
     * Find a single record
     *
     * @param array $filter  Search filter
     * @param array $options Options such as sorting
     *
     * @return array|null
     */
    public function findRecord($filter, $options = []);

    /**
     * Find records
     *
     * @param array $filter  Search filter
     * @param array $options Options such as sorting
     *
     * @return \Traversable
     */
    public function findRecords($filter, $options = []);

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
    ): void;

    /**
     * Count records
     *
     * @param array $filter  Search filter
     * @param array $options Options such as sorting
     *
     * @return int|string
     */
    public function countRecords($filter, $options = []);

    /**
     * Save a record
     *
     * @param array $record Record
     *
     * @return array Saved record (with a new _id if it didn't have one)
     */
    public function saveRecord($record);

    /**
     * Update a record
     *
     * @param string $id     Record ID
     * @param array  $fields Modified fields
     * @param array  $remove Removed fields
     *
     * @return void
     */
    public function updateRecord($id, $fields, $remove = []);

    /**
     * Update multiple records
     *
     * @param array $filter Record ID
     * @param array $fields Modified fields
     * @param array $remove Removed fields
     *
     * @return void
     */
    public function updateRecords($filter, $fields, $remove = []);

    /**
     * Delete a record
     *
     * @param string $id Record ID
     *
     * @return void
     */
    public function deleteRecord($id);

    /**
     * Get a state record
     *
     * @param string $id Record ID
     *
     * @return array|null
     */
    public function getState($id);

    /**
     * Save a state record
     *
     * @param array $record State record
     *
     * @return array Saved record (with a new _id if it didn't have one)
     */
    public function saveState($record);

    /**
     * Delete a state record
     *
     * @param string $id Record ID
     *
     * @return void
     */
    public function deleteState($id);

    /**
     * Get a dedup record
     *
     * @param mixed $id Record ID
     *
     * @return array|null
     */
    public function getDedup($id);

    /**
     * Find a single dedup record
     *
     * @param array $filter  Search filter
     * @param array $options Options such as sorting
     *
     * @return array|null
     */
    public function findDedup($filter, $options = []);

    /**
     * Find dedup records
     *
     * @param array $filter  Search filter
     * @param array $options Options such as sorting
     *
     * @return \Traversable
     */
    public function findDedups($filter, $options = []);

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
    ): void;

    /**
     * Count dedup records
     *
     * @param array $filter  Search filter
     * @param array $options Options such as sorting
     *
     * @return int|string
     */
    public function countDedups($filter, $options = []);

    /**
     * Save a dedup record
     *
     * @param array $record Dedup record
     *
     * @return array Saved record (with a new _id if it didn't have one)
     */
    public function saveDedup($record);

    /**
     * Delete a dedup record
     *
     * @param string $id Record ID
     *
     * @return void
     */
    public function deleteDedup($id);

    /**
     * Remove old tracking collections
     *
     * @param int $minAge Minimum age in days. Default is 7 days.
     *
     * @return array Array of two arrays with collections removed and those whose
     * removal failed
     */
    public function cleanupTrackingCollections(int $minAge = 7);

    /**
     * Create a new temporary tracking collection
     *
     * @return string
     */
    public function getNewTrackingCollection();

    /**
     * Remove a temporary tracking collection
     *
     * @param string $collectionName The temporary collection name
     *
     * @return bool
     */
    public function dropTrackingCollection($collectionName);

    /**
     * Add a record ID to a tracking collection
     *
     * @param string $collectionName The queue collection name
     * @param string $id             ID to add
     *
     * @return bool True if added, false if id already exists
     */
    public function addIdToTrackingCollection($collectionName, $id);

    /**
     * Find a single URI cache record
     *
     * @param array $filter  Search filter
     * @param array $options Options such as sorting
     *
     * @return array|null
     */
    public function findUriCache($filter, $options = []);

    /**
     * Save a URI cache record
     *
     * @param array $record URI cache record
     *
     * @return array Saved record (with a new _id if it didn't have one)
     */
    public function saveUriCache($record);

    /**
     * Find a single linked data enrichment record
     *
     * @param array $filter  Search filter
     * @param array $options Options such as sorting
     *
     * @return array|null
     */
    public function findLinkedDataEnrichment($filter, $options = []);

    /**
     * Save a linked data enrichment record
     *
     * @param array $record Linked data enrichment record
     *
     * @return array Saved record (with a new _id if it didn't have one)
     */
    public function saveLinkedDataEnrichment($record);

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
    ): void;

    /**
     * Find log messages
     *
     * @param array $filter  Search filter
     * @param array $options Options such as sorting
     *
     * @return \Traversable
     */
    public function findLogMessages(array $filter, array $options = []);

    /**
     * Delete a log message
     *
     * @param mixed $id Message ID
     *
     * @return void
     */
    public function deleteLogMessage($id): void;

    /**
     * Reset the database connection if it's open
     *
     * @return void
     */
    public function resetConnection(): void;
}

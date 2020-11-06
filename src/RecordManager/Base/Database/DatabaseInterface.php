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
 * @link     https://github.com/KDK-Alli/RecordManager
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
 * @link     https://github.com/KDK-Alli/RecordManager
 */
interface DatabaseInterface
{
    /**
     * Constructor.
     *
     * @param string $url      Database connection URL
     * @param string $database Datatabase name
     * @param array  $settings Optional database settings
     *
     * @throws Exception
     */
    public function __construct($url, $database, $settings);

    /**
     * Open a database connection with the stored parameters
     *
     * @return void
     */
    public function reconnectDatabase();

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
     * @param string|ObjectID $id Record ID
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
     * Count dedup records
     *
     * @param array $filter  Search filter
     * @param array $options Options such as sorting
     *
     * @return int
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
     * Remove old queue collections
     *
     * @param int $lastRecordTime Newest record timestamp
     *
     * @return array Array of two arrays with collections removed and those whose
     * removal failed
     */
    public function cleanupQueueCollections($lastRecordTime);

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
    public function getExistingQueueCollection($hash, $fromDate, $lastRecordTime);

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
    public function getNewQueueCollection($hash, $fromDate, $lastRecordTime);

    /**
     * Rename a temporary dedup collection to its final name and return the name
     *
     * @param string $collectionName The temporary collection name
     *
     * @return string
     */
    public function finalizeQueueCollection($collectionName);

    /**
     * Remove a temp dedup collection
     *
     * @param string $collectionName The temporary collection name
     *
     * @return bool
     */
    public function dropQueueCollection($collectionName);

    /**
     * Add a record ID to a queue collection
     *
     * @param string $collectionName The queue collection name
     * @param string $id             ID to add
     *
     * @return void
     */
    public function addIdToQueue($collectionName, $id);

    /**
     * Get IDs in queue
     *
     * @param string $collectionName The queue collection name
     *
     * @return \Traversable
     */
    public function getQueuedIds($collectionName);

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
     * Find a single ontology enrichment record
     *
     * @param array $filter  Search filter
     * @param array $options Options such as sorting
     *
     * @return array|null
     */
    public function findOntologyEnrichment($filter, $options = []);
}

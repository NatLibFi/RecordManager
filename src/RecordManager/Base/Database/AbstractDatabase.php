<?php

/**
 * Abstract database access class
 *
 * PHP version 8
 *
 * Copyright (c) The National Library of Finland 2020-2022.
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
     * Linked data enrichment collection name
     *
     * @var string
     */
    protected $linkedDataEnrichmentCollection = 'ldEnrichment';

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
                    '$gt' => $lastId,
                ];
            }
            $records = $findMethod(
                $currentFilter,
                array_merge(
                    $options,
                    [
                        'skip' => 0,
                        'limit' => $limit,
                        'sort' => ['_id' => 1],
                    ]
                )
            );
            $lastId = null;
            foreach ($records as $record) {
                if (!isset($record['_id'])) {
                    throw new \Exception('Cannot iterate records without _id column');
                }
                $lastId = $record['_id'];
                if ($callback($record, $params) === false) {
                    return;
                }
            }
        } while ($lastId && !isset($filter['_id']));
    }
}

<?php
/**
 * SolrUpdater Class
 *
 * PHP version 5
 *
 * Copyright (C) Ere Maijala, The National Library of Finland 2012
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
 */

require_once 'BaseRecord.php';
require_once 'MetadataUtils.php';

/**
 * SolrUpdater Class
 *
 * This is a class for updating the Solr index.
 *
 */
class SolrUpdater
{
    protected $_db;
    protected $_log;
    protected $_settings;
    protected $_buildingHierarchy;
    protected $_verbose;

    public function __construct($db, $basePath, $dataSourceSettings, $log, $verbose)
    {
        global $configArray;
        
        $this->_db = $db;
        $this->_basePath = $basePath;
        $this->_log = $log;
        $this->_verbose = $verbose;
        $this->_counts = isset($configArray['Mongo']['counts']) && $configArray['Mongo']['counts'];
        
        // Special case: building hierarchy
        $this->_buildingHierarchy = isset($configArray['Solr']['hierarchical_facets'])
            && in_array('building', $configArray['Solr']['hierarchical_facets']);

        // Load settings and mapping files
        $this->_settings = array();
        foreach ($dataSourceSettings as $source => $settings) {
            if (!isset($settings['institution'])) {
                throw new Exception("Error: institution not set for $source\n");
            }
            if (!isset($settings['format'])) {
                throw new Exception("Error: format not set for $source\n");
            }
            $this->_settings[$source] = $settings;
            $this->_settings[$source]['idPrefix'] = isset($settings['idPrefix']) && $settings['idPrefix'] ? $settings['idPrefix'] : $source;
            $this->_settings[$source]['componentParts'] = isset($settings['componentParts']) && $settings['componentParts'] ? $settings['componentParts'] : 'as_is';
            $this->_settings[$source]['indexMergedParts'] = isset($settings['indexMergedParts']) ? $settings['indexMergedParts'] : true;
            $this->_settings[$source]['solrTransformationXSLT'] = isset($settings['solrTransformation']) && $settings['solrTransformation'] ? new XslTransformation($this->_basePath . '/transformations', $settings['solrTransformation']) : null;
            $this->_settings[$source]['mappingFiles'] = array();
            
            foreach ($settings as $key => $value) {
                if (substr($key, -8, 8) == '_mapping') {
                    $field = substr($key, 0, -8);
                    $this->_settings[$source]['mappingFiles'][$field] = parse_ini_file($this->_basePath . '/mappings/' . $value);
                }
            }
        }

        $this->_commitInterval = isset($configArray['Solr']['max_commit_interval'])
            ? $configArray['Solr']['max_commit_interval'] : 50000;
        $this->_maxUpdateRecords = isset($configArray['Solr']['max_update_records'])
            ? $configArray['Solr']['max_update_records'] : 5000;
        $this->_maxUpdateSize = isset($configArray['Solr']['max_update_size'])
            ? $configArray['Solr']['max_update_size'] : 1024;
        $this->_maxUpdateSize *= 1024;
    }

    /**
     * Update Solr index (individual records)
     * 
     * @param string|null	$fromDate   Starting date for updates (if empty 
     *                                string, last update date stored in the database
     *                                is used and if null, all records are processed)
     * @param string		$sourceId     Source ID to update, or empty or * for all 
     *                                sources (ignored if record merging is enabled)
     * @param string 		$singleId     Export only a record with the given ID
     * @param bool  		$noCommit     If true, changes are not explicitly committed
     */
    public function updateIndividualRecords($fromDate = null, $sourceId = '', $singleId = '', $noCommit = false)
    {
        if (isset($fromDate) && $fromDate) {
            $mongoFromDate = new MongoDate(strtotime($fromDate));
        }

        foreach ($this->_settings as $source => $settings) {
            try {
                if ($sourceId && $sourceId != '*' && $source != $sourceId) {
                    continue;
                }
                if (empty($source) || empty($settings)) {
                    continue;
                }

                if (!isset($fromDate)) {
                    $state = $this->_db->state->findOne(array('_id' => "Last Index Update $source"));
                    if (isset($state)) {
                        $mongoFromDate = $state['value'];
                    } else {
                        unset($mongoFromDate);
                    }
                }
                $from = isset($mongoFromDate) ? date('Y-m-d H:i:s', $mongoFromDate->sec) : 'the beginning';
                $this->_log->log('updateIndividualRecords', "Creating record list (from $from), source $source)...");
                // Take the last indexing date now and store it when done
                $lastIndexingDate = new MongoDate();
                $params = array();
                if ($singleId) {
                    $params['_id'] = $singleId;
                    $params['source_id'] = $source;
                    $lastIndexingDate = null;
                } else {
                    $params['source_id'] = $source;
                    if (isset($mongoFromDate)) {
                        $params['updated'] = array('$gte' => $mongoFromDate);
                    }
                    $params['update_needed'] = false;
                }
                $records = $this->_db->record->find($params);
                $records->immortal(true);

                $total = $this->_counts ? $records->count() : 'the';
                $count = 0;
                $mergedComponents = 0;
                $deleted = 0;
                if ($noCommit) {
                    $this->_log->log('updateIndividualRecords', "Indexing $total records (with no forced commits) from $source...");
                } else {
                    $this->_log->log('updateIndividualRecords', "Indexing $total records (max commit interval {$this->_commitInterval} records) from $source...");
                }
                $starttime = microtime(true);
                $this->_initBufferedUpdate();
                foreach ($records as $record) {
                    if ($record['deleted']) {
                        $this->_solrRequest(json_encode(array('delete' => array('id' => (string)$record['key']))));
                        ++$deleted;
                    } else {
                        $data = $this->_createSolrArray($record, $mergedComponents);
                        if ($data === false) {
                            continue;
                        }

                        if ($this->_verbose) {
                            echo "Metadata for record {$record['_id']}: \n";
                            print_r($data);
                            echo "JSON for record {$record['_id']}: \n" . json_encode($data) . "\n";
                        }

                        ++$count;                       
                        $res = $this->_bufferedUpdate($data, $count, $noCommit);
                        if ($res) {
                            $this->_log->log('updateIndividualRecords', "$count records (of which $deleted deleted) with $mergedComponents merged parts indexed from $source, $res records/sec");
                        }
                    }
                }
                $this->_flushUpdateBuffer();

                if (isset($lastIndexingDate)) {
                    $state = array('_id' => "Last Index Update $source", 'value' => $lastIndexingDate);
                    $this->_db->state->save($state);
                }

                $this->_log->log('updateIndividualRecords', "Completed with $count records (of which $deleted deleted) with $mergedComponents merged parts indexed from $source");
            } catch (Exception $e) {
                $this->_log->log('updateIndividualRecords', 'Exception: ' . $e->getMessage(), Logger::FATAL);
            }
        }
        if (!$noCommit) {
            $this->_log->log('updateIndividualRecords', "Final commit...");
            $this->_solrRequest('{ "commit": {} }');
            $this->_log->log('updateIndividualRecords', "Commit complete");
        }
    }
    
    /**
     * Update Solr index (merged records)
     * 
     * @param string|null	$fromDate   Starting date for updates (if empty 
     *                                string, last update date stored in the database
     *                                is used and if null, all records are processed)
     * @param string 		$singleId     Export only a record with the given ID
     * @param bool  		$noCommit     If true, changes are not explicitly committed
     */
    public function updateMergedRecords($fromDate = null, $sourceId = '', $singleId = '', $noCommit = false)
    {
        try {
            if (isset($fromDate) && $fromDate) {
                $mongoFromDate = new MongoDate(strtotime($fromDate));
            }
    
            if (!isset($fromDate)) {
                $state = $this->_db->state->findOne(array('_id' => 'Last Index Update'));
                if (isset($state)) {
                    $mongoFromDate = $state['value'];
                } else {
                    unset($mongoFromDate);
                }
            }
            $from = isset($mongoFromDate) ? date('Y-m-d H:i:s', $mongoFromDate->sec) : 'the beginning';
            $this->_log->log('updateMergedRecords', "Creating merged record list (from $from)...");
            // Take the last indexing date now and store it when done
            $lastIndexingDate = new MongoDate();
            $this->_initBufferedUpdate();
    
            // Process deduped records
            $params = array();
            if ($singleId) {
                $params['_id'] = $singleId;
                $lastIndexingDate = null;
            } else {
                if (isset($mongoFromDate)) {
                    $params['updated'] = array('$gte' => $mongoFromDate);
                }
                $params['update_needed'] = false;
            }
            $keys = $this->_db->command(
                array(
                    'distinct' => 'record',
                    'key' => 'dedup_key',
                    'query' => $params
                )
            );
    
            $total = $this->_counts ? count($keys['values']) : 'the';
            $count = 0;
            $mergedComponents = 0;
            $deleted = 0;
            $this->_initBufferedUpdate();
            if ($noCommit) {
                $this->_log->log('updateMergedRecords', "Indexing $total merged records (with no forced commits)...");
            } else {
                $this->_log->log('updateMergedRecords', "Indexing $total merged records (max commit interval {$this->_commitInterval} records)...");
            }
            $starttime = microtime(true);
            foreach ($keys['values'] as $key) {
                if (empty($key)) {
                    continue;
                }
                $records = $this->_db->record->find(array('dedup_key' => $key));
                $merged = array();
                foreach ($records as $record) {
                    if ($record['deleted']) {
                        $this->_solrRequest(json_encode(array('delete' => array('id' => $record['_id']))));
                        continue;
                    }
                    $data = $this->_createSolrArray($record, $mergedComponents);
                    if ($data === false) {
                        continue;
                    }
                    $merged = $this->_mergeRecords($merged, $data);
                    $data['merged_child_boolean'] = true;
                    ++$count;
                    $res = $this->_bufferedUpdate($data, $count, $noCommit);
                    if ($res) {
                        $this->_log->log('updateMergedRecords', "$count merged records (of which $deleted deleted) with $mergedComponents merged parts indexed, $res records/sec");
                    }
                }
                if (empty($merged)) {
                    $this->_solrRequest(json_encode(array('delete' => array('id' => (string)$key))));
                    ++$deleted;
                    continue;
                }
                $merged['id'] = (string)$key;
                $merged['merged_boolean'] = true;
                ++$count;
                $res = $this->_bufferedUpdate($merged, $count, $noCommit);
                if ($res) {
                    $this->_log->log('updateMergedRecords', "$count merged records (of which $deleted deleted) with $mergedComponents merged parts indexed, $res records/sec");
                }
            }
            $this->_flushUpdateBuffer();
            $this->_log->log('updateMergedRecords', "Total $count merged records (of which $deleted deleted) with $mergedComponents merged parts indexed");
    
            $this->_log->log('updateMergedRecords', "Creating individual record list (from $from)...");
            $params = array('dedup_key' => array('$exists' => false));
            if ($singleId) {
                $params['_id'] = $singleId;
                $lastIndexingDate = null;
            } else {
                if (isset($mongoFromDate)) {
                    $params['updated'] = array('$gte' => $mongoFromDate);
                }
                $params['update_needed'] = false;
            }
            $records = $this->_db->record->find($params);
            $records->immortal(true);
    
            $total = $this->_counts ? $records->count() : 'the';
            $count = 0;
            $mergedComponents = 0;
            $deleted = 0;
            if ($noCommit) {
                $this->_log->log('updateMergedRecords', "Indexing $total individual records (with no forced commits)...");
            } else {
                $this->_log->log('updateMergedRecords', "Indexing $total individual records (max commit interval {$this->_commitInterval} records)...");
            }
            $starttime = microtime(true);
            $this->_initBufferedUpdate();
            foreach ($records as $record) {
                if ($record['deleted']) {
                    $this->_solrRequest(json_encode(array('delete' => array('id' => (string)$record['key']))));
                    ++$deleted;
                } else {
                    $data = $this->_createSolrArray($record, $mergedComponents);
                    if ($data === false) {
                        continue;
                    }
                    
                    if ($this->_verbose) {
                        echo "Metadata for record {$record['_id']}: \n";
                        print_r($data);
                        echo "JSON for record {$record['_id']}: \n" . json_encode($data) . "\n";
                    }
    
                    ++$count;                       
                    $res = $this->_bufferedUpdate($data, $count, $noCommit);
                    if ($res) {
                        $this->_log->log('updateMergedRecords', "$count individual records (of which $deleted deleted) with $mergedComponents merged parts indexed, $res records/sec");
                    }
                }
            }
            $this->_flushUpdateBuffer();
    
            if (isset($lastIndexingDate)) {
                $state = array('_id' => "Last Index Update", 'value' => $lastIndexingDate);
                $this->_db->state->save($state);
            }
    
            $this->_log->log('updateMergedRecords', "Total $count individual records (of which $deleted deleted) with $mergedComponents merged parts indexed");
            
            if (!$noCommit) {
                $this->_log->log('updateMergedRecords', "Final commit...");
                $this->_solrRequest('{ "commit": {} }');
                $this->_log->log('updateMergedRecords', "Commit complete");
            }
        } catch (Exception $e) {
            $this->_log->log('updateMergedRecords', 'Exception: ' . $e->getMessage(), Logger::FATAL);
        }
    }
    
    /**
     * Delete all records belonging to the given source from the index
     * 
     * @param string $sourceId
     */
    public function deleteDataSource($sourceId)
    {
        $this->_solrRequest('{ "delete": { "query": "id:' . $sourceId . '.*" } }');
        $this->_solrRequest('{ "commit": {} }');
    }

    /**
     * Optimize the Solr index
     */
    public function optimizeIndex()
    {
        $this->_solrRequest('{ "optimize": {} }');
    }
    
    /**
     * Create Solr array for the given record
     * @param MongoRecord $record
     */
    protected function _createSolrArray($record, &$mergedComponents)
    {
        $metadataRecord = RecordFactory::createRecord($record['format'], MetadataUtils::getRecordData($record, true), $record['oai_id']);
        
        $source = $record['source_id'];
        $settings = $this->_settings[$source];
        $hiddenComponent = false;
        if ($record['host_record_id']) {
            if ($settings['componentParts'] == 'merge_all') {
                $hiddenComponent = true;
            } elseif ($settings['componentParts'] == 'merge_non_articles' || $settings['componentParts'] == 'merge_non_earticles') {
                $format = $metadataRecord->getFormat();
                if ($format != 'eJournalArticle' && $format != 'JournalArticle') {
                    $hiddenComponent = true;
                } elseif ($format == 'JournalArticle' && $settings['componentParts'] == 'merge_non_earticles') {
                    $hiddenComponent = true;
                }
            }
        }
        
        if ($hiddenComponent && !$settings['indexMergedParts']) {
            return false;
        }
        
        $hasComponentParts = false;
        $components = null;
        if (!$record['host_record_id'] && $settings['componentParts'] != 'as_is') {
            // Fetch all component parts for merging
            $components = $this->_db->record->find(array('host_record_id' => $record['_id'], 'deleted' => false));
            $hasComponentParts = $components->hasNext();
            $format = $metadataRecord->getFormat();
            $merge = false;
            if ($settings['componentParts'] == 'merge_all') {
                $merge = true;
            } elseif ($format != 'eJournal' && $format != 'Journal' && $format != 'Serial') {
                $merge = true;
            } elseif (($format == 'Journal' || $format == 'Serial') && $settings['componentParts'] == 'merge_non_earticles') {
                $merge = true;
            }
            if (!$merge) {
                unset($components);
            }
        }
        
        $metadataRecord->setIDPrefix($settings['idPrefix'] . '.');
        if (isset($components)) {
            $mergedComponents += $metadataRecord->mergeComponentParts($components);
        }
        if (isset($settings['solrTransformationXSLT'])) {
            $params = array(
                'source_id' => $source,
                'institution' => $settings['institution'],
                'format' => $settings['format'],
                'id_prefix' => $settings['idPrefix']
            );
            $data = $settings['solrTransformationXSLT']->transformToSolrArray($metadataRecord->toXML(), $params);
        } else {
            $data = $metadataRecord->toSolrArray();
        }
        
        $data['id'] = $record['_id'];
        
        // Record links between host records and component parts
        if ($metadataRecord->getIsComponentPart()) {
            $hostRecord = null;
            if ($record['host_record_id']) {
                $hostRecord = $this->_db->record->findOne(array('_id' => $record['host_record_id']));
                $data['hierarchy_parent_id'] = $record['host_record_id'];
            }
            if (!$hostRecord) {
                $this->_log->log('_createSolrArray', 'Host record ' . $record['host_record_id'] . ' not found for record ' . $record['_id'], Logger::WARNING);
                $data['container_title'] = $metadataRecord->getContainerTitle();
            } else {
                $hostMetadataRecord = RecordFactory::createRecord($hostRecord['format'], MetadataUtils::getRecordData($hostRecord, true), $hostRecord['oai_id']);
                $data['container_title'] = $data['hierarchy_parent_title'] = $hostMetadataRecord->getTitle();
            }
            $data['container_volume'] = $metadataRecord->getVolume();
            $data['container_issue'] = $metadataRecord->getIssue();
            $data['container_start_page'] = $metadataRecord->getStartPage();
            $data['container_reference'] = $metadataRecord->getContainerReference();
        }
        if ($hasComponentParts) {
            $data['is_hierarchy_id'] = $record['_id'];
            $data['is_hierarchy_title'] = $metadataRecord->getTitle();
        }
        
        if (!isset($data['institution'])) {
            $data['institution'] = $settings['institution'];
        }
        if (!isset($data['collection'])) {
            $data['collection'] = $record['source_id'];
        }
        
        // Map field values according to any mapping files
        foreach ($settings['mappingFiles'] as $field => $map) {
            if (isset($data[$field])) {
                if (is_array($data[$field])) {
                    foreach ($data[$field] as &$value) {
                        if (isset($map[$value])) {
                            $value = $map[$value];
                        }
                    }
                    $data[$field] = array_unique($data[$field]);
                } else {
                    if (isset($map[$data[$field]])) {
                        $data[$field] = $map[$data[$field]];
                    }
                }
            }
        }
        
        // Special case: Hierarchical facet support for building (institution/location)
        if ($this->_buildingHierarchy) {
            if (isset($data['building']) && $data['building']) {
                $building = array('0/' . $settings['institution']);
                foreach ($data['building'] as $datavalue) {
                    $values = explode('/', $datavalue);
                    $hierarchyString = $settings['institution'];
                    for ($i = 0; $i < count($values); $i++) {
                        $hierarchyString .= '/' . $values[$i];
                        $building[] = ($i + 1) . "/$hierarchyString";
                    }
                }
                $data['building'] = $building;
            } else {
                $data['building'] = array(
                    '0/' . $settings['institution']
                );
            }
        }
        
        // Other hierarchical facets
        if (isset($configArray['Solr']['hierarchical_facets'])) {
            foreach ($configArray['Solr']['hierarchical_facets'] as $facet) {
                if ($facet == 'building' || !isset($data[$facet])) {
                    continue;
                }
                $array = array();
                if (!is_array($data[$facet])) {
                    $data[$facet] = array($data[$facet]);
                }
                foreach ($data[$facet] as $datavalue) {
                    $values = explode('/', $datavalue);
                    $hierarchyString = '';
                    for ($i = 0; $i < count($values); $i++) {
                        $hierarchyString .= '/' . $values[$i];
                        $array[] = ($i) . $hierarchyString;
                    }
                }
                $data[$facet] = $array;
            }
        }
        
        if (!isset($data['allfields'])) {
            $all = array();
            foreach ($data as $key => $field) {
                if (in_array($key, array('fullrecord', 'thumbnail', 'id', 'recordtype'))) {
                    continue;
                }
                if (is_array($field)) {
                    $all[] = implode(' ', $field);
                } else {
                    $all[] = $field;
                }
            }
            $data['allfields'] = MetadataUtils::array_iunique($all);
        }
        
        $data['dedup_key'] = isset($record['dedup_key']) && $record['dedup_key'] ? (string)$record['dedup_key'] : $record['_id'];
        $data['first_indexed'] = MetadataUtils::formatTimestamp($record['created']->sec);
        $data['last_indexed'] = MetadataUtils::formatTimestamp($record['updated']->sec);
        $data['recordtype'] = $record['format'];
        if (!isset($data['fullrecord'])) {
            $data['fullrecord'] = $metadataRecord->toXML();
        }
        if (!is_array($data['format'])) {
            $data['format'] = array($data['format']);
        }
        
        if ($hiddenComponent) {
            $data['hidden_component_boolean'] = true;
        }
        
        foreach ($data as $key => $value) {
            // Checking only for empty() won't work as 0 is empty too
            if (empty($value) && $value !== 0 && $value !== 0.0 && $value !== '0') {
                unset($data[$key]);
            }
        }

        return $data;
    }
    
    protected function _mergeRecords($merged, $add)
    {
        $mergedFields = array('institution', 'collection', 'building', 'language', 
            'physical', 'publisher', 'publishDate', 'contents', 'url', 'ctrlnum',
            'author2', 'author_additional', 'title_alt', 'title_old', 'title_new', 
            'dateSpan', 'series', 'series2', 'topic', 'genre', 'geographic', 
            'era', 'long_lat', 'hierachy_top_id', 'hierarchy_top_title',
            'hierarchy_parent_id', 'hierarchy_parent_title', 'hierarchy_sequence',
            'is_hierarchy_id', 'is_hierarchy_title');
        
        $checkedFields = array('title_auth', 'title', 'title_short', 'title_full', 'title_sort', 'author');
        
        if (empty($merged)) {
            $merged = $add;
            unset($merged['id']);
            $merged['local_ids'] = array($add['id']);
            unset($merged['fullrecord']);
        } else {
            $merged['local_ids'][] = $add['id'];
        } 
        foreach ($add as $key => $value) {
            if (substr($key, -3, 3) == '_mv' || in_array($key, $mergedFields)) {
                if (!isset($merged[$key])) {
                    $merged[$key] = $value;
                }
                if (!is_array($merged[$key])) {
                    $merged[$key] = array($merged[$key]);
                }
                if (!is_array($value)) {
                    $value = array($value);
                }
                $merged[$key] = array_values(MetadataUtils::array_iunique(array_merge($merged[$key], $value)));
            } elseif (in_array($key, $checkedFields)) {
                if (!isset($merged[$key])) {
                    $merged[$key] = $value;
                }
            } elseif ($key == 'allfields') {
                if (!isset($merged['allfields'])) {
                    $merged['allfields'] = array();
                }
                $merged['allfields'] = array_values(
                    MetadataUtils::array_iunique(
                        array_merge(
                            $merged['allfields'], 
                            $add['allfields']
                        )
                    )
                );
            }
        }
        
        return $merged;
    }

    /**
     * Make a JSON request to the Solr server
     * 
     * @param string $body	The JSON request
     */
    protected function _solrRequest($body)
    {
        global $configArray;

        $request = new HTTP_Request2($configArray['Solr']['update_url'], HTTP_Request2::METHOD_POST, 
            array('ssl_verify_peer' => false));
        $request->setHeader('User-Agent', 'RecordManager');
        if (isset($configArray['Solr']['username']) && isset($configArray['Solr']['password'])) {
            $request->setAuth($configArray['Solr']['username'], $configArray['Solr']['password'], HTTP_Request2::AUTH_BASIC);
        }
        $request->setHeader('Content-Type', 'application/json');
        $request->setBody($body);
        $response = $request->send();
        $code = $response->getStatus();
        if ($code >= 300) {
            throw new Exception("Solr server request failed ($code). Request:\n$body\n\nResponse:\n" . $response->getBody());
        }
    }

    /**
     * Initialize the record update buffer
     */
    protected function _initBufferedUpdate()
    {
        $this->_buffer = '';
        $this->_bufferLen = 0;
        $this->_buffered = 0;
        $this->_bufferStartTime = microtime(true);
    }

    /**
     * Update Solr index in a batch
     * 
     * @param array $data      Record metadata
     * @param int   $count     Number of records processed so far
     * @param bool  $noCommit  Whether to not do any explicit commits
     * 
     * @return false|int       False when buffering, records/sec when the
     *                         batch has been sent to Solr 
     */
    protected function _bufferedUpdate($data, $count, $noCommit)
    {
        $result = false;
        
        if (isset($data['allfields']) && is_array($data['allfields'])) {
            $data['allfields'] = implode(' ', $data['allfields']);
        }
        $jsonData = json_encode($data);
        if ($this->_buffered > 0) {
            $this->_buffer .= ",\n";
        }
        $this->_buffer .= $jsonData;
        $this->_bufferLen += strlen($jsonData);
        if (++$this->_buffered >= $this->_maxUpdateRecords || $this->_bufferLen > $this->_maxUpdateSize) {
            $this->_solrRequest("[\n{$this->_buffer}\n]");
            $result = round($this->_buffered / (microtime(true) - $this->_bufferStartTime));
            $this->_buffer = '';
            $this->_bufferLen = 0;
            $this->_buffered = 0;
            $this->_bufferStartTime = microtime(true);
        }
        if (!$noCommit && $count % $this->_commitInterval == 0) {
            $this->_log->log('bufferedUpdate', "Intermediate commit...");
            $this->_solrRequest('{ "commit": {} }');
        }
        return $result;
    }
    
    protected function _flushUpdateBuffer()
    {
        if ($this->_buffered > 0) {
            $this->_solrRequest("[\n{$this->_buffer}\n]");
        }
    }
}

<?php
/**
 * Record Manager
 *
 * PHP version 5
 *
 * Copyright (C) Ere Maijala 2011-2012.
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
 * @link     http://pear.php.net/package/DB_DataObject/ PEAR Documentation
 */

require_once 'PEAR.php';
require_once 'Logger.php';
require_once 'RecordFactory.php';
require_once 'FileSplitter.php';
require_once 'HarvestOaiPmh.php';
require_once 'XslTransformation.php';
require_once 'MetadataUtils.php';

/**
 * RecordManager Class
 *
 * This is the main class for RecordManager.
 *
 */
class RecordManager
{
    public $verbose = false;
    public $quiet = false;
    public $harvestFromDate = null;
    public $harvestUntilDate = null;

    protected $_basePath = '';
    protected $_log = null;
    protected $_db = null;
    protected $_dataSourceSettings = null;

    protected $_format = '';
    protected $_idPrefix = '';
    protected $_sourceId = '';
    protected $_institution = '';
    protected $_recordXPath = '';
    protected $_componentParts = '';
    protected $_dedup = false;
    protected $_normalizationXSLT = null;
    protected $_solrTransformationXSLT = null;
    protected $_recordSplitter = null; 
    protected $_pretransformation = '';
    protected $_indexMergedParts = true;
    
    public function __construct($console = false)
    {
        global $configArray;

        date_default_timezone_set($configArray['Site']['timezone']);

        $this->_log = new Logger();
        if ($console) {
            $this->_log->logToConsole = true;
        }

        $basePath = substr(__FILE__, 0, strrpos(__FILE__, DIRECTORY_SEPARATOR));
        $basePath = substr($basePath, 0, strrpos($basePath, DIRECTORY_SEPARATOR));
        $this->_dataSourceSettings = parse_ini_file("$basePath/conf/datasources.ini", true);
        $this->_basePath = $basePath;

        $mongo = new Mongo($configArray['Mongo']['url']);
        $this->_db = $mongo->selectDB($configArray['Mongo']['database']);
        MongoCursor::$timeout = isset($configArray['Mongo']['cursor_timeout']) ? $configArray['Mongo']['cursor_timeout'] : 300000;
    }

    public function loadFromFile($source, $file)
    {
        $this->_log->log('loadFromFile', "Loading records from '$file' into '$source'");
        $this->_loadSourceSettings($source);
        if (!$this->_recordXPath) {
            $this->_log->log('loadFromFile', 'recordXPath not defined', Logger::FATAL);
            die("recordXPath not defined\n");
        }
        $data = file_get_contents($file);
        if ($data === false) {
            throw new Exception("Could not read file '$file'");
        }
        
        if ($this->_pretransformation) {
            if ($this->verbose) {
                echo "Executing pretransformation...\n";
            }
            $data = $this->_pretransform($data);
        }
        
        if ($this->verbose) {
            echo "Creating FileSplitter...\n";
        }
        $splitter = new FileSplitter($data, $this->_recordXPath);
        $count = 0;
        
        if ($this->verbose) {
            echo "Storing records...\n";
        }
        while (!$splitter->getEOF())
        {
            $data = $splitter->getNextRecord();
            if ($this->verbose) {
                echo "Storing a record...\n";
            }
            $count += $this->storeRecord('', false, $data);
            if ($this->verbose) {
                echo "Stored records: $count...\n";
            }
        }
        
        $this->_log->log('loadFromFile', "$count records loaded");
        return $count;
    }

    public function exportRecords($file, $deletedFile, $fromDate, $skipRecords = 0, $sourceId = '', $singleId = '')
    {
        if ($file == '-') {
            $file = 'php://stdout';
        }

        if (file_exists($file)) {
            unlink($file);
        }
        if (file_exists($deletedFile)) {
            unlink($deletedFile);
        }
        file_put_contents($file, "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n\n<collection>\n", FILE_APPEND);

        foreach ($this->_dataSourceSettings as $source => $settings) {
            try {
                if ($sourceId && $sourceId != '*' && $source != $sourceId) {
                    continue;
                }
                if (empty($source) || empty($settings)) {
                    continue;
                }

                $this->_loadSourceSettings($source);

                $this->_log->log('exportRecords', "Creating record list (from " . ($fromDate ? $fromDate : 'the beginning') . ", source $source)...");

                $params = array();
                if ($singleId) {
                    $params['_id'] = $singleId;
                    $params['source_id'] = $source;
                } else {
                    $params['source_id'] = $source;
                    if ($fromDate) {
                        $params['updated'] = array('$gte' => new MongoDate(strtotime($fromDate)));
                    }
                    $params['update_needed'] = false;
                }
                $records = $this->_db->record->find($params);
                $total = $records->count();
                $count = 0;
                $deduped = 0;
                $deleted = 0;
                $this->_log->log('exportRecords', "Exporting $total records from $source...");
                if ($skipRecords) {
                	$this->_log->log('exportRecords', "(1 per each $skipRecords records)");
                }
                foreach ($records as $record) {
                    if ($record['deleted']) {
                        file_put_contents($deletedFile, "{$record['_id']}\n", FILE_APPEND);
                        ++$deleted;
                    } else {
                        ++$count;
                        if ($skipRecords > 0 && $count % $skipRecords != 0) {
                            continue;
                        }
                        $metadataRecord = RecordFactory::createRecord($record['format'], $this->_getRecordData($record, true), $record['oai_id']);
                        if (isset($record['dedup_key']) && $record['dedup_key']) {
                            ++$deduped;
                        }
                        $metadataRecord->setIDPrefix($this->_idPrefix . '.');
                        $metadataRecord->addDedupKeyToMetadata((isset($record['dedup_key']) && $record['dedup_key']) ? $record['dedup_key'] : $record['_id']);
                        file_put_contents($file, $metadataRecord->toXML() . "\n", FILE_APPEND);
                    }
                    if ($count % 1000 == 0) {
                        $this->_log->log('exportRecords', "$deleted deleted, $count normal (of which $deduped deduped) records exported from $source");
                    }
                }
                $this->_log->log('exportRecords', "Completed with $deleted deleted, $count normal (of which $deduped deduped) records exported from $source");
            } catch (Exception $e) {
                $this->_log->log('exportRecords', 'Exception: ' . $e->getMessage(), Logger::FATAL);
            }
        }
        file_put_contents($file, "</collection>\n", FILE_APPEND);
    }

    public function updateSolrIndex($fromDate = null, $sourceId = '', $singleId = '')
    {
        global $configArray;
        $commitInterval = isset($configArray['Solr']['max_commit_interval']) ? $configArray['Solr']['max_commit_interval'] : 50000;
        	
        foreach ($this->_dataSourceSettings as $source => $settings) {
            try {
                if ($sourceId && $sourceId != '*' && $source != $sourceId) {
                    continue;
                }
                if (empty($source) || empty($settings)) {
                    continue;
                }

                $this->_loadSourceSettings($source);
                	
                if (!isset($fromDate)) {
                    $state = $this->_db->state->findOne(array('_id' => "Last Index Update $source"));
                    if (isset($state)) {
                        $fromDate = date('Y-m-d H:i:s', $state['value']->sec);
                    }
                }
                $this->_log->log('updateSolrIndex', "Creating record list (from " . ($fromDate ? $fromDate : 'the beginning') . ", source $source)...");
                // Take the last indexing date now and store it when done
                $lastIndexingDate = new MongoDate();
                $params = array();
                if ($singleId) {
                    $params['_id'] = $singleId;
                    $params['source_id'] = $source;
                    $lastIndexingDate = null;
                } else {
                    $params['source_id'] = $source;
                    if ($fromDate) {
                        $params['updated'] = array('$gte' => new MongoDate(strtotime($fromDate)));
                    }
                    $params['update_needed'] = false;
                }
                $records = $this->_db->record->find($params);
                $records->immortal(true);

                $total = $records->count();
                $count = 0;
                $deduped = 0;
                $mergedComponents = 0;
                $deleted = 0;
                $buffer = '';
                $bufferLen = 0;
                $buffered = 0;
                $delList = array();;
                $this->_log->log('updateSolrIndex', "Indexing $total records (max commit interval $commitInterval records) from $source...");
                foreach ($records as $record) {
                    if ($record['deleted']) {
                        $this->_solrRequest(json_encode(array('delete' => array('id' => $record['_id']))));
                        ++$deleted;
                    } else {
                        $metadataRecord = RecordFactory::createRecord($record['format'], $this->_getRecordData($record, true), $record['oai_id']);

                        $hiddenComponent = false;
                        if ($record['host_record_id']) {
                            if ($this->_componentParts == 'merge_all') {
                                $hiddenComponent = true;
                            } elseif ($this->_componentParts == 'merge_non_articles' || $this->_componentParts == 'merge_non_earticles') {
                                $format = $metadataRecord->getFormat();
                                if ($format != 'eJournalArticle' && $format != 'JournalArticle') {
                                    $hiddenComponent = true;
                                } elseif ($format == 'JournalArticle' && $this->_componentParts == 'merge_non_earticles') {
                                    $hiddenComponent = true;
                                }
                            }
                        }

                        if ($hiddenComponent && !$this->_indexMergedParts) {
                            continue;
                        }
                        
                        $components = null;
                        if (!$record['host_record_id'] && $this->_componentParts != 'as_is') {
                            $format = $metadataRecord->getFormat();
                            $merge = false;
                            if ($this->_componentParts == 'merge_all') {
                                $merge = true;
                            } elseif ($format != 'eJournal' && $format != 'Journal' && $format != 'Serial') {
                                $merge = true;
                            } elseif (($format == 'Journal' || $format == 'Serial') && $this->_componentParts == 'merge_non_earticles') {
                                $merge = true;
                            }
                            if ($merge) {
                                // Fetch all component parts for merging
                                $components = $this->_db->record->find(array('host_record_id' => $record['_id'], 'deleted' => false));
                            }
                        }

                        
                        $metadataRecord->setIDPrefix($this->_idPrefix . '.');
                        if (isset($components)) {
                            $mergedComponents += $metadataRecord->mergeComponentParts($components);
                        }
                        if (isset($this->_solrTransformationXSLT)) {
                            $data = $this->_solrTransformationXSLT->transformToSolrArray($metadataRecord->toXML());
                        } else {
                            $data = $metadataRecord->toSolrArray();
                        }

                        $data['id'] = $record['_id'];
                        $data['host_id'] = $record['host_record_id'];
                        $data['institution'] = $this->_institution;
                        $data['collection'] = $record['source_id'];
                        if (isset($data['building']) && $data['building']) {
                            foreach ($data['building'] as $key => $value) {
                                $data['building'][$key] = $record['source_id'] . ".$value";
                            }
                        }
                        $data['dedup_key'] = isset($record['dedup_key']) && $record['dedup_key'] ? $record['dedup_key'] : $record['_id'];
                        $data['first_indexed'] = $this->_formatTimestamp($record['created']->sec);
                        $data['last_indexed'] = $this->_formatTimestamp($record['updated']->sec);
                        $data['recordtype'] = $record['format'];
                        if (!isset($data['fullrecord'])) {
                            $data['fullrecord'] = $metadataRecord->toXML();
                        }
                        if ($hiddenComponent) {
                            $data['hidden_component_boolean'] = true;
                        }

                        foreach ($data as $key => $value) {
                            if (is_null($value)) {
                                unset($data[$key]);
                            }
                        }
                        if ($buffered > 0) {
                            $buffer .= ",\n";
                        }
                        $jsonData = json_encode($data);
                        if ($this->verbose) {
                            echo "Metadata for record {$record['_id']}: \n";
                            print_r($data);
                            echo "JSON for record {$record['_id']}: \n$jsonData\n";
                        }
                        $buffer .= $jsonData;
                        $bufferLen += strlen($jsonData);
                        if (++$buffered >= 1000 || $bufferLen > 10000) {
                            $this->_solrRequest("[\n$buffer\n]");
                            $buffer = '';
                            $bufferLen = 0;
                            $buffered = 0;
                        }
                    }
                    if (++$count % 1000 == 0) {
                        $this->_log->log('updateSolrIndex', "$count records (of which $deleted deleted) with $mergedComponents merged parts indexed from $source");
                    }
                    if ($count % $commitInterval == 0) {
                        $this->_solrRequest('{ "commit": {} }');
                    }
                }
                if ($buffered > 0) {
                    $this->_solrRequest("[\n$buffer\n]");
                }
                if (!empty($delList)) {
                    $this->_solrRequest(json_encode(array('delete' => $delList)));
                }
                $this->_log->log('updateSolrIndex', "Committing final changes...");
                $this->_solrRequest('{ "commit": {} }');

                if (isset($lastIndexingDate)) {
                    $state = array('_id' => "Last Index Update $source", 'value' => $lastIndexingDate);
                    $this->_db->state->save($state);
                }

                $this->_log->log('updateSolrIndex', "Completed with $count records (of which $deleted deleted) with $mergedComponents merged parts indexed from $source");
            } catch (Exception $e) {
                $this->_log->log('updateSolrIndex', 'Exception: ' . $e->getMessage(), Logger::FATAL);
            }
        }
    }

    public function renormalize($source, $singleId)
    {
        if (!$source) {
            die('Source must be specified');
        }
        $this->_loadSourceSettings($source);
        $this->_log->log('renormalize', "Creating record list for '$source'...");

        $params = array('deleted' => false);
        if ($singleId) {
            $params['_id'] = $singleId;
            $params['source_id'] = $source;
        } else {
            $params['source_id'] = $source;
        }
        $records = $this->_db->record->find($params);
        $total = $records->count();
        $count = 0;

        $this->_log->log('renormalize', "Processing $total records...");
        $starttime = microtime(true);
        foreach ($records as $record) {
            $originalData = $this->_getRecordData($record, false);
            $normalizedData = $originalData;
            if (isset($this->_normalizationXSLT)) {
                $normalizedData = $this->_normalizationXSLT->transform($originalData, array('oai_id' => $record['oai_id']));
            }
            
            $metadataRecord = RecordFactory::createRecord($record['format'], $normalizedData, $record['oai_id']);
            $this->_updateDedupCandidateKeys($record, $metadataRecord);
            
            if ($normalizedData == $originalData) {
                $record['normalized_data'] = '';
            } else {
                $record['normalized_data'] = new MongoBinData($normalizedData);
            }
            $record['dedup_key'] = '';
            $record['update_needed'] = $this->_dedup ? true : false;
            $record['updated'] = new MongoDate();
            try {
                $this->_db->record->save($record);
            } catch (Exception $e) {
                die("Save failed: " . $e->getMessage() . "\n");
            }
            ++$count;
            if ($count % 1000 == 0) {
                $avg = round(1000 / (microtime(true) - $starttime));
                $this->_log->log('renormalize', "$count records processed, $avg records/sec");
                $starttime = microtime(true);
            }
        }
        $this->_log->log('renormalize', "Completed with $count records processed");
    }

    public function deduplicate($sourceId, $allRecords = false, $singleId = '')
    {
        foreach ($this->_dataSourceSettings as $source => $settings) {
            try {
                if ($sourceId && $sourceId != '*' && $source != $sourceId) {
                    continue;
                }
                if (empty($source) || empty($settings)) {
                    continue;
                }

                $this->_loadSourceSettings($source);
                $this->_log->log('deduplicate', "Creating record list for '$source'...");

                $params = array('deleted' => false);
                if ($singleId) {
                    $params['_id'] = $singleId;
                    $params['source_id'] = $source;
                } else {
                    $params['source_id'] = $source;
                    if (!$allRecords) {
                        $params['update_needed'] = true;
                    }
                }
                $records = $this->_db->record->find($params);
                $total = $records->count();
                $count = 0;
                $deduped = 0;
                $starttime = microtime(true);
                $this->_tooManyCandidatesKeys = array();
                $this->_log->log('deduplicate', "Processing $total records for '$source'...");
                foreach ($records as $record) {
                    $startRecordTime = microtime(true);
                    if ($this->_dedupRecord($record)) {
                        if ($this->verbose) {
                            echo '+';
                        }
                        ++$deduped;
                    } else {
                        if ($this->verbose)
                        echo '.';
                    }
                    if (microtime(true) - $startRecordTime > 0.7) {
                        if ($this->verbose) {
                            echo "\n";
                        }
                        $this->_log->log('deduplicate', "Candidate search for " . $record['_id'] . " took " . (microtime(true) - $startRecordTime));
                    }
                    ++$count;
                    if ($count % 1000 == 0) {
                        $avg = round(1000 / (microtime(true) - $starttime));
                        if ($this->verbose) {
                            echo "\n";
                        }
                        $this->_log->log('deduplicate', "$count records processed for '$source', $deduped deduplicated, $avg records/sec");
                        $starttime = microtime(true);
                    }
                }
                $this->_log->log('deduplicate', "Completed with $count records processed for '$source'");
            } catch (Exception $e) {
                $this->_log->log('deduplicate', 'Exception: ' . $e->getMessage(), Logger::FATAL);
            }
        }
    }

    public function harvest($repository = '', $harvestFromDate = null, $harvestUntilDate = null, $startResumptionToken = '')
    {
        global $configArray;

        if (empty($this->_dataSourceSettings)) {
            $this->_log->log('harvest', "Please add data source settings to datasources.ini", Logger::FATAL);
            die("Please add data source settings to datasources.ini\n");
        }

        // Loop through all the sources and perform harvests
        foreach ($this->_dataSourceSettings as $source => $settings) {
            try {
                if ($repository && $repository != '*' && $source != $repository) {
                    continue;
                }
                if (empty($source) || empty($settings) || !isset($settings['url'])) {
                    continue;
                }
                $this->_log->log('harvest', "Harvesting from {$source}...");

                $this->_loadSourceSettings($source);

                if ($this->verbose) {
                    $settings['verbose'] = true;
                }

                $harvest = new HarvestOAIPMH($this->_log, $this->_db, $source, $this->_basePath, $settings, $startResumptionToken);
                if (isset($harvestFromDate))
                $harvest->setStartDate($harvestFromDate);
                if (isset($harvestUntilDate))
                $harvest->setEndDate($harvestUntilDate);
                $harvest->launch(array($this, 'storeRecord'));
                $this->_log->log('harvest', "Harvesting from {$source} completed");
            } catch (Exception $e) {
                $this->_log->log('harvest', 'Exception: ' . $e->getMessage(), Logger::FATAL);
            }
        }
    }

    public function dumpRecord($recordID)
    {
        if (!$recordID) {
            die("dump: record id must be specified\n");
        }
        $records = $this->_db->record->find(array('_id' => $recordID));
        foreach ($records as $record) {
            $record['original_data'] = $this->_getRecordData($record, false);
            $record['normalized_data'] = $this->_getRecordData($record, true);
            if ($record['original_data'] == $record['normalized_data']) {
                $record['normalized_data'] = '';
            }
            print_r($record);
        }
    }
    
    public function storeRecord($oaiID, $deleted, $recordData)
    {
        if ($deleted) {
            // A single OAI-PMH record may have been split to multiple records
            $records = $this->_db->record->find(array('oai_id' => $oaiID));
            foreach ($records as $record) {
                $record['deleted'] = true;
                $this->_db->record->save($record);
            }
            return;
        }

        $dataArray = Array();
        if ($this->_recordSplitter) {
            if ($this->verbose) {
                echo "Splitting records...\n";
            }
            if (is_string($this->_recordSplitter)) {
                require_once $this->_recordSplitter;
                $className = substr($this->_recordSplitter, 0, -4);
                $splitter = new $className($recordData);
                while (!$splitter->getEOF()) {
                    $dataArray[] = $splitter->getNextRecord();
                }
            } else {
                $doc = new DOMDocument();
                $doc->loadXML($recordData);
                if ($this->verbose) {
                    echo "XML Doc Created...\n";
                }
                $transformedDoc = $this->_recordSplitter->transformToDoc($doc);
                if ($this->verbose) {
                    echo "XML Transformation Done...\n";
                }
                $records = simplexml_import_dom($transformedDoc);
                if ($this->verbose) {
                    echo "Creating record array...\n";
                }
                foreach ($records as $record) {
                    $dataArray[] = $record->saveXML();
                }
            }
        } else {
            $dataArray = array($recordData);
        }

        if ($this->verbose) {
            echo "Storing array of " . count($dataArray) . " records...\n";
        }
                
        $count = 0;
        foreach ($dataArray as $data) {
            if (isset($this->_normalizationXSLT)) {
                $metadataRecord = RecordFactory::createRecord($this->_format, $this->_normalizationXSLT->transform($data, array('oai_id' => $oaiID)), $oaiID);
                $normalizedData = $metadataRecord->serialize();
                $originalData = RecordFactory::createRecord($this->_format, $data, $oaiID)->serialize();
            }
            else {
                $metadataRecord = RecordFactory::createRecord($this->_format, $data, $oaiID);
                $originalData = $metadataRecord->serialize();
                $normalizedData = $originalData;
            }
    
            $hostID = $metadataRecord->getHostRecordID();
            if ($hostID) {
                $hostID = $this->_idPrefix . '.' . $hostID;
            }
            $id = $metadataRecord->getID();
            if (!$id) {
                throw new Exception("Empty ID returned for record $oaiID");
            }
            $id = $this->_idPrefix . '.' . $id;
            $dbRecord = $this->_db->record->findOne(array('_id' => $id));
            if ($dbRecord) {
                if (!isset($dbRecord['created'])) {
                    $dbRecord['created'] = $dbRecord['updated'] = new MongoDate();
                } else {
                    $dbRecord['updated'] = new MongoDate();
                }
            } else {
                $dbRecord = array();
                $dbRecord['_id'] = $id;
                $dbRecord['created'] = $dbRecord['updated'] = new MongoDate();
            }
            if ($normalizedData) {
                if ($data == $normalizedData) {
                    $normalizedData = '';
                };
            }
            $originalData = gzdeflate($originalData);
            if ($normalizedData) {
                $normalizedData = gzdeflate($normalizedData);
            }
            $dbRecord['source_id'] = $this->_sourceId;
            $dbRecord['oai_id'] = $oaiID;
            $dbRecord['deleted'] = false;
            $dbRecord['host_record_id'] = $hostID;
            $dbRecord['format'] = $this->_format;
            $dbRecord['original_data'] = new MongoBinData($originalData);
            $dbRecord['normalized_data'] = $normalizedData ? new MongoBinData($normalizedData) : '';
            // TODO: don't update created
            $dbRecord['update_needed'] = $this->_dedup ? true : false;
            $this->_updateDedupCandidateKeys($dbRecord, $metadataRecord);
            $this->_db->record->save($dbRecord);
            ++$count;
        }
        return $count;
    }

    protected function _loadRecords($data)
    {
        $this->_log->log('loadRecords', "loading records");
        $this->_log->log('loadRecords', "$count records loaded");
        return $count;
    }

    protected function _updateDedupCandidateKeys(&$record, $metadataRecord)
    {
        $record['title_keys'] = array(MetadataUtils::createTitleKey($metadataRecord->getTitle(true)));
        $record['isbn_keys'] = array_unique($metadataRecord->getISBNs());
    }

    protected function _dedupRecord($record)
    {
        $origRecord = RecordFactory::createRecord($record['format'], $this->_getRecordData($record, true), $record['oai_id']);
        $key = MetadataUtils::createTitleKey($origRecord->getTitle(true));
        $keyArray = array($key);
        $ISBNArray = array_unique($origRecord->getISBNs());

        $matchRecord = null;
        foreach (array('ISBN' => $ISBNArray, 'key' => $keyArray) as $type => $array) {
            //			$minWords = $type == 'ISBN' ? 1 : min(array(count($array), 2));
            //			for ($words = min(array(count($array), 10)); $words >= $minWords  ; $words--) {
            //				$keyPart = implode(' ', array_slice($array, 0, $words));
            //				if (strlen($keyPart) < RecordManager::MINIMUM_DEDUP_CANDIDATE_KEY_LENGTH)
            //					break;

            foreach ($array as $keyPart) {
                if (!$keyPart || isset($this->_tooManyCandidatesKeys[$keyPart])) {
                    continue;
                }
                	
                $startTime = microtime(true);

                if ($this->verbose) {
                    echo "Search: '$keyPart'\n";
                }
                if ($type == 'ISBN') {
                    $candidates = $this->_db->record->find(array('isbn_keys' => $keyPart, 'source_id' => array('$ne' => $this->_sourceId)));
                }
                else {
                    $candidates = $this->_db->record->find(array('title_keys' => $keyPart, 'source_id' => array('$ne' => $this->_sourceId)));
                }
                //echo "Search done\n";
                $processed = 0;
                if ($candidates->hasNext()) {
                    // We have candidates
                    if ($this->verbose) {
                        echo "Found candidates\n";
                    }
                    //echo "Found " . $candidates->count() . " candidates for '$keyPart'\n";

                    // Go through the candidates, try to match
                    $matchRecord = null;
                    foreach ($candidates as $candidate) {
                        if ($candidate['source_id'] == $this->_sourceId)
                        continue;
                        // Verify the candidate has not been deduped with this source yet
                        if (isset($candidate['dedup_key']) && $candidate['dedup_key']) {
                            if ($this->_db->record->find(array('dedup_key' => $candidate['dedup_key'], 'source_id' => $this->_sourceId))->hasNext()) {
                                if ($this->verbose) {
                                    echo "Candidate {$candidate['_id']} already deduplicated\n";
                                }
                                continue;
                            }
                        }

                        if (++$processed > 1000) {
                            // Too many candidates, give up..
                            $this->_log->log('dedupRecord', "Too many candidates for record " . $record['_id'] . " with key '$keyPart'", Logger::DEBUG);
                            $this->_tooManyCandidatesKeys[$keyPart] = 1;
                            if (count($this->_tooManyCandidatesKeys) > 20000) {
                                array_shift($this->_tooManyCandidatesKeys);
                            }
                            break;
                        }

                        $cRecord = RecordFactory::createRecord($candidate['format'], $this->_getRecordData($candidate, true), $record['oai_id']);
                        if ($this->verbose) {
                            echo "Candidate:\n" . ($candidate['normalized_data'] ? $candidate['normalized_data'] : $candidate['original_data']) . "\n";
                        }
                        	
                        // Check for common ISBN
                        $origISBNs = $origRecord->getISBNs();
                        $cISBNs = $cRecord->getISBNs();
                        $isect = array_intersect($origISBNs, $cISBNs);
                        if (!empty($isect)) {
                            // Shared ISBN -> match
                            if ($this->verbose) {
                                echo "++ISBN match:\n";
                                print_r($origISBNs);
                                print_r($cISBNs);
                                echo $origRecord->getFullTitle() . "\n";
                                echo $cRecord->getFullTitle() . "\n";
                            }
                            	
                            $matchRecord = $candidate;
                            break 2;
                        }

                        if ($origRecord->getFormat() != $cRecord->getFormat()) {
                            if ($this->verbose) {
                                echo "--Format mismatch: " . $origRecord->getFormat() . ' != ' . $cRecord->getFormat() . "\n";
                            }
                            continue;
                        }
                        $origYear = $origRecord->getPublicationYear();
                        $cYear = $cRecord->getPublicationYear();
                        if ($origYear && $cYear && $origYear != $cYear) {
                            if ($this->verbose) {
                                echo "--Year mismatch: $origYear != $cYear\n";
                            }
                            continue;
                        }
                        $pages = $origRecord->getPageCount();
                        $cPages = $cRecord->getPageCount();
                        if ($pages && $cPages && abs($pages-$cPages) > 10) {
                            if ($this->verbose) {
                                echo "--Pages mismatch ($pages != $cPages)\n";
                            }
                            continue;
                        }

                        if ($origRecord->getSeriesISSN() != $cRecord->getSeriesISSN()) {
                            continue;
                        }
                        if ($origRecord->getSeriesNumbering() != $cRecord->getSeriesNumbering()) {
                            continue;
                        }

                        $origTitle = MetadataUtils::normalize($origRecord->getTitle(true));
                        $cTitle = MetadataUtils::normalize($cRecord->getTitle(true));
                        if (!$origTitle || !$cTitle) {
                            // No title match without title...
                            if ($this->verbose) {
                                echo "No title - no further matching\n";
                            }
                            continue;
                        }
                        $lev = levenshtein(substr($origTitle, 0, 255), substr($cTitle, 0, 255));
                        $lev = $lev / strlen($origTitle) * 100;
                        if ($this->verbose) {
                            echo "Title lev: $lev\n";
                        }

                        if ($lev < 10) {
                            $origAuthor = MetadataUtils::normalize($origRecord->getMainAuthor());
                            $cAuthor = MetadataUtils::normalize($cRecord->getMainAuthor());
                            $authorLev = 0;
                            if ($origAuthor && $cAuthor) {
                                if (!MetadataUtils::authorMatch($origAuthor, $cAuthor)) {
                                    $authorLev = levenshtein(substr($origAuthor, 0, 255), substr($cAuthor, 0, 255));
                                    $authorLev = $authorLev / mb_strlen($origAuthor) * 100;
                                    if ($authorLev > 20) {
                                        if ($this->verbose) {
                                            echo "\nAuthor lev discard (lev: $lev, authorLev: $authorLev):\n";
                                            echo $origRecord->getFullTitle() . "\n";
                                            echo "   $origAuthor - $origTitle\n";
                                            echo $cRecord->getFullTitle() . "\n";
                                            echo "   $cAuthor - $cTitle\n";
                                        }
                                        continue;
                                    }
                                }
                            }
                            	
                            if ($this->verbose) {
                                echo "\nTitle match (lev: $lev, authorLev: $authorLev):\n";
                                echo $origRecord->getFullTitle() . "\n";
                                echo "   $origAuthor - $origTitle.\n";
                                echo $cRecord->getFullTitle() . "\n";
                                echo "   $cAuthor - $cTitle.\n";
                                //echo $record->getNormalizedData() . "\n--\n";
                                //echo $dbRecord->getNormalizedData() . "\n\n";
                            }
                            // We have a match!
                            $matchRecord = $candidate;
                            break 2;
                        }
                    }
                }
            }
        }

        if ($matchRecord) {
            $this->_markDuplicates($record, $matchRecord);
            return true;
        } else {
            $record['dedup_key'] = null;
            $record['updated'] = new MongoDate();
            $record['update_needed'] = false;
            $this->_db->record->save($record);
        } 
        return false;
    }

    protected function _markDuplicates($rec1, $rec2)
    {
        if (isset($rec1['dedup_key']) && $rec1['dedup_key'] != '') {
            $rec2['dedup_key'] = $rec1['dedup_key'];
        }
        elseif (isset($rec2['dedup_key']) && $rec2['dedup_key'] != '') {
            $rec1['dedup_key'] = $rec2['dedup_key'];
        }
        else
        {
            $key = 'dedup' . uniqid();
            $rec1['dedup_key'] = $key;
            $rec2['dedup_key'] = $key;
        }
        $rec1['updated'] = new MongoDate();
        $rec1['update_needed'] = false;            
        $this->_db->record->save($rec1);
        $rec2['updated'] = new MongoDate();
        $rec2['update_needed'] = false;
        $this->_db->record->save($rec2);
    }

    protected function _pretransform($data)
    {
        if (!isset($this->_pre_xslt))
        {
            $style = new DOMDocument();
            $style->load($this->_basePath . '/transformations/' . $this->_pretransformation);
            $this->_pre_xslt = new XSLTProcessor();
            $this->_pre_xslt->importStylesheet($style);
            $this->_pre_xslt->setParameter('', 'source_id', $this->_sourceId);
            $this->_pre_xslt->setParameter('', 'institution', $this->_institution);
            $this->_pre_xslt->setParameter('', 'format', $this->_format);
            $this->_pre_xslt->setParameter('', 'id_prefix', $this->_idPrefix);
        }
        $doc = new DOMDocument();
        $doc->loadXML($data);
        return $this->_pre_xslt->transformToXml($doc);
    }

    protected function _formatTimestamp($timestamp)
    {
        $date = new DateTime('', new DateTimeZone('UTC'));
        $date->setTimeStamp($timestamp);
        return $date->format('Y-m-d') . 'T' . $date->format('H:i:s') . 'Z';
    }

    protected function _solrRequest($body)
    {
        global $configArray;

        $request = new HTTP_Request();
        $request->addHeader('User-Agent', 'RecordManager');
        $request->setMethod(HTTP_REQUEST_METHOD_POST);
        $request->setURL($configArray['Solr']['update_url']);
        if (isset($configArray['Solr']['username']) && isset($configArray['Solr']['password'])) {
            $request->setBasicAuth($configArray['Solr']['username'], $configArray['Solr']['password']);
        }
        $request->addHeader('Content-Type', 'application/json');
        $request->setBody($body);
        $result = $request->sendRequest();
        if (PEAR::isError($result)) {
            die('Solr server request failed: ' . $result->getMessage());
        }
        $code = $request->getResponseCode();
        if ($code >= 300) {
            echo "Request: \n";
            echo $body;
            echo "\n-----\n";
            $this->_log->log('_solrRequest', "Solr server request failed: $code: " . $request->getResponseBody(), Logger::FATAL);
            die("Solr server request failed: $code: " . $request->getResponseBody() . "\n");
        }
    }

    protected function _getRecordData(&$record, $normalized)
    {
        if ($normalized) {
            $data = $record['normalized_data'] ? $record['normalized_data'] : $record['original_data'];
        } else {
            $data = $record['original_data'];
        }
        return is_string($data) ? $data : gzinflate($data->bin);
    }
    
    protected function _loadSourceSettings($source)
    {
        if (!isset($this->_dataSourceSettings[$source])) {
            $this->_log->log('loadSourceSettings', "Settings not found for data source $source", Logger::FATAL);
            throw new Exception("Error: settings not found for $source\n");
        }
        $settings = $this->_dataSourceSettings[$source];
        if (!isset($settings['institution'])) {
            $this->_log->log('loadSourceSettings', "institution not set for $source", Logger::FATAL);
            throw new Exception("Error: institution not set for $source\n");
        }
        if (!isset($settings['format'])) {
            $this->_log->log('loadSourceSettings', "format not set for $source", Logger::FATAL);
            throw new Exception("Error: format not set for $source\n");
        }
        $this->_format = $settings['format'];
        $this->_sourceId = $source;
        $this->_idPrefix = isset($settings['idPrefix']) && $settings['idPrefix'] ? $settings['idPrefix'] : $source;
        $this->_institution = $settings['institution'];
        $this->_recordXPath = isset($settings['recordXPath']) ? $settings['recordXPath'] : '';
        $this->_dedup = isset($settings['dedup']) ? $settings['dedup'] : false;
        $this->_componentParts = isset($settings['componentParts']) && $settings['componentParts'] ? $settings['componentParts'] : 'as_is';
        $this->_pretransformation = isset($settings['preTransformation']) ? $settings['preTransformation'] : '';
        $this->_indexMergedParts = isset($settings['indexMergedParts']) ? $settings['indexMergedParts'] : true;
        
        $params = array('source_id' => $this->_sourceId, 'institution' => $this->_institution, 'format' => $this->_format, 'id_prefix' => $this->_idPrefix);
        $this->_normalizationXSLT = isset($settings['normalization']) && $settings['normalization'] ? new XslTransformation($this->_basePath . '/transformations', $settings['normalization'], $params) : null;
        $this->_solrTransformationXSLT = isset($settings['solrTransformation']) && $settings['solrTransformation'] ? new XslTransformation($this->_basePath . '/transformations', $settings['solrTransformation'], $params) : null;
        
        if (isset($settings['recordSplitter'])) {
            if (substr($settings['recordSplitter'], -4) == '.php') {
                $this->_recordSplitter = $settings['recordSplitter']; 
            } else {
                $style = new DOMDocument();
                $xslFile = $this->_basePath . '/transformations/' . $settings['recordSplitter'];
                if ($style->load($xslFile) === false) {
                    throw new Exception("Could not load $xslFile");
                }
                $this->_recordSplitter = new XSLTProcessor();
                $this->_recordSplitter->importStylesheet($style);
            }
        }
    }
}


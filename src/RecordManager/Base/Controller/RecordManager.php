<?php
/**
 * Record Manager
 *
 * PHP version 5
 *
 * Copyright (C) The National Library of Finland 2011-2017.
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
namespace RecordManager\Base\Controller;

use RecordManager\Base\Database\Database;
use RecordManager\Base\Record\Factory as RecordFactory;
use RecordManager\Base\Solr\SolrUpdater;
use RecordManager\Base\Utils\Logger;
use RecordManager\Base\Utils\MetadataUtils;
use RecordManager\Base\Utils\PerformanceCounter;
use RecordManager\Base\Utils\XslTransformation;

require_once 'PEAR.php';
require_once 'HTTP/Request2.php';

/**
 * RecordManager Class
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class RecordManager extends AbstractBase
{
    use StoreRecordTrait;

    /**
     * Dedup Handler
     *
     * @var DedupHandler
     */
    protected $dedupHandler = null;

    /**
     * Harvested MetaLib Records
     *
     * @var array
     */
    protected $metaLibRecords = [];

    // TODO: refactor data source setting handling
    protected $harvestType = '';
    protected $format = '';
    protected $idPrefix = '';
    protected $sourceId = '';
    protected $institution = '';
    protected $recordXPath = '';
    protected $oaiIDXPath = '';
    protected $componentParts = '';
    protected $dedup = false;
    protected $normalizationXSLT = null;
    protected $solrTransformationXSLT = null;
    protected $recordSplitter = null;
    protected $keepMissingHierarchyMembers = false;
    protected $pretransformation = '';
    protected $indexMergedParts = true;
    protected $nonInheritedFields = [];
    protected $prependParentTitleWithUnitId = null;
    protected $previousId = '[none]';

    /**
     * Constructor
     *
     * @param boolean $console Specify whether RecordManager is executed on the
     * console so that log output is also output to the console.
     * @param boolean $verbose Whether verbose output is enabled
     */
    public function __construct($console = false, $verbose = false)
    {
        parent::__construct($console, $verbose);

        $dedupClass = isset($configArray['Site']['dedup_handler'])
            ? $configArray['Site']['dedup_handler']
            : '\RecordManager\Base\Deduplication\DedupHandler';
        $this->dedupHandler = new $dedupClass(
            $this->db, $this->logger, $this->verbose, $this->basePath, $this->config,
            $this->dataSourceSettings
        );
    }

    /**
     * Catch the SIGINT signal and signal the main thread to terminate
     *
     * @param int $signal Signal ID
     *
     * @return void
     */
    public function sigIntHandler($signal)
    {
        $this->terminate = true;
        echo "Termination requested\n";
    }

    /**
     * Harvest records from a data source
     *
     * @param string      $repository           Source ID to harvest
     * @param string      $harvestFromDate      Override start date (otherwise
     * harvesting is done from the previous harvest date)
     * @param string      $harvestUntilDate     Override end date (otherwise
     * current date is used)
     * @param string      $startResumptionToken Override OAI-PMH resumptionToken to
     * resume interrupted harvesting process (note
     *                                     that tokens may have a limited lifetime)
     * @param string      $exclude              Source ID's to exclude whe using '*'
     * for repository
     * @param bool|string $reharvest            Whether to consider this a full
     * reharvest where sets may have changed
     *                                          (deletes records not received during
     * this harvesting)
     *
     * @return void
     * @throws Exception
     */
    public function harvest(
        $repository = '',
        $harvestFromDate = null,
        $harvestUntilDate = null,
        $startResumptionToken = '',
        $exclude = null,
        $reharvest = false
    ) {
        $this->initSourceSettings();
        if (empty($this->dataSourceSettings)) {
            $this->logger->log(
                'harvest',
                'Please add data source settings to datasources.ini',
                Logger::FATAL
            );
            throw new \Exception("Data source settings missing in datasources.ini");
        }

        if ($reharvest && !is_string($reharvest) && $startResumptionToken) {
            $this->logger->log(
                'harvest',
                'Reharvest start date must be specified when used with the'
                . ' resumption token override option',
                Logger::FATAL
            );
            throw new \Exception(
                'Reharvest start date must be specified when used with the'
                . ' resumption token override option'
            );
        }

        $excludedSources = isset($exclude) ? explode(',', $exclude) : [];

        // Loop through all the sources and perform harvests
        foreach ($this->dataSourceSettings as $source => $settings) {
            try {
                if ($repository && $repository != '*' && $source != $repository) {
                    continue;
                }
                if ((!$repository || $repository == '*')
                    && in_array($source, $excludedSources)
                ) {
                    continue;
                }
                if (empty($source) || empty($settings) || !isset($settings['url'])) {
                    continue;
                }
                $this->logger->log(
                    'harvest',
                    "Harvesting from '{$source}'"
                    . ($reharvest ? ' (full reharvest)' : '')
                );

                $this->loadSourceSettings($source);

                if ($this->verbose) {
                    $settings['verbose'] = true;
                }

                if ($this->harvestType == 'metalib') {
                    // MetaLib doesn't handle deleted records, so we'll just fetch
                    // everything and compare with what we have
                    $this->logger->log('harvest', "Fetching records from MetaLib");
                    $harvest = new \RecordManager\Base\Harvest\MetaLib(
                        $this->logger, $this->db, $source, $this->basePath, $settings
                    );
                    $harvestedRecords = $harvest->harvest();
                    $this->processFullRecordSet($source, $harvestedRecords);
                } elseif ($this->harvestType == 'metalib_export') {
                    // MetaLib doesn't handle deleted records, so we'll just fetch
                    // everything and delete whatever we didn't get
                    $harvest = new \RecordManager\Base\Harvest\MetaLibExport(
                        $this->logger, $this->db, $source, $this->basePath, $settings
                    );
                    if (isset($harvestFromDate)) {
                        $harvest->setStartDate($harvestFromDate);
                    }
                    if (isset($harvestUntilDate)) {
                        $harvest->setEndDate($harvestUntilDate);
                    }
                    $this->metaLibRecords = [];
                    $harvest->harvest([$this, 'storeMetaLibRecord']);
                    if ($this->metaLibRecords) {
                        $this->processFullRecordSet($source, $this->metaLibRecords);
                    }
                } elseif ($this->harvestType == 'sfx') {
                    $harvest = new \RecordManager\Base\Harvest\Sfx(
                        $this->logger, $this->db, $source, $this->basePath, $settings
                    );
                    if (isset($harvestFromDate)) {
                        $harvest->setStartDate($harvestFromDate);
                    }
                    if (isset($harvestUntilDate)) {
                        $harvest->setEndDate($harvestUntilDate);
                    }
                    $harvest->harvest([$this, 'storeRecord']);
                } else {
                    $dateThreshold = null;
                    if ($reharvest) {
                        if (is_string($reharvest)) {
                            $dateThreshold = $this->db->getTimetamp(
                                strtotime($reharvest)
                            );
                        } else {
                            $dateThreshold = $this->db->getTimestamp();
                        }
                        $this->logger->log(
                            'harvest',
                            'Reharvest date threshold: '
                            . $dateThreshold->toDatetime()->format('Y-m-d H:i:s')
                        );
                    }

                    if ($this->harvestType == 'sierra') {
                        $harvest = new \RecordManager\Base\Harvest\SierraApi(
                            $this->logger,
                            $this->db,
                            $source,
                            $this->basePath,
                            $settings,
                            $startResumptionToken ? $startResumptionToken : 0
                        );
                    } else {
                        $harvest = new \RecordManager\Base\Harvest\OAIPMH(
                            $this->logger,
                            $this->db,
                            $source,
                            $this->basePath,
                            $settings,
                            $startResumptionToken
                        );
                    }
                    if (isset($harvestFromDate)) {
                        $harvest->setStartDate(
                            $harvestFromDate == '-' ? null : $harvestFromDate
                        );
                    }
                    if (isset($harvestUntilDate)) {
                        $harvest->setEndDate($harvestUntilDate);
                    }

                    $harvest->harvest([$this, 'storeRecord']);

                    if ($reharvest) {
                        if ($harvest->getHarvestedRecordCount() == 0) {
                            $this->logger->log(
                                'harvest',
                                "No records received from '$source' during"
                                . ' reharvesting -- assuming an error and skipping'
                                . ' marking records deleted',
                                Logger::FATAL
                            );
                        } else {
                            $this->logger->log(
                                'harvest',
                                'Marking deleted all records not received during'
                                . ' the harvesting'
                            );
                            $records = $this->db->findRecords(
                                [
                                    'source_id' => $this->sourceId,
                                    'deleted' => false,
                                    'updated' => ['$lt' => $dateThreshold]
                                ]
                            );
                            $count = 0;
                            foreach ($records as $record) {
                                $this->storeRecord(
                                    $this->sourceId, $record['oai_id'], true, ''
                                );
                                if (++$count % 1000 == 0) {
                                    $this->logger->log(
                                        'harvest', "Deleted $count records"
                                    );
                                }
                            }
                            $this->logger->log('harvest', "Deleted $count records");
                        }
                    }

                    if (!$reharvest && isset($settings['deletions'])
                        && strncmp(
                            $settings['deletions'], 'ListIdentifiers', 15
                        ) == 0
                    ) {
                        // The repository doesn't support reporting deletions, so
                        // list all identifiers and mark deleted records that were
                        // not found

                        if (!is_callable([$harvest, 'listIdentifiers'])) {
                            throw new \Exception(
                                get_class($harvest)
                                . ' does not support listing identifiers'
                            );
                        }

                        $processDeletions = true;
                        $interval = null;
                        $deletions = explode(':', $settings['deletions']);
                        if (isset($deletions[1])) {
                            $state = $this->db->getState(
                                "Last Deletion Processing Time $source"
                            );
                            if (null !== $state) {
                                $interval
                                    = round((time() - $state['value']) / 3600 / 24);
                                if ($interval < $deletions[1]) {
                                    $this->logger->log(
                                        'harvest',
                                        "Not processing deletions, $interval days "
                                        . "since last time"
                                    );
                                    $processDeletions = false;
                                }
                            }
                        }

                        if ($processDeletions) {
                            $this->logger->log(
                                'harvest',
                                'Processing deletions' . (isset($interval)
                                    ? " ($interval days since last time)" : '')
                            );

                            $this->logger->log('harvest', 'Unmarking records');
                            $this->db->updateRecords(
                                ['source_id' => $this->sourceId, 'deleted' => false],
                                [],
                                ['mark' => 1]
                            );

                            $this->logger->log('harvest', "Fetching identifiers");
                            $harvest->listIdentifiers([$this, 'markRecord']);

                            $this->logger->log('harvest', "Marking deleted records");

                            $records = $this->db->findRecords(
                                [
                                    'source_id' => $this->sourceId,
                                    'deleted' => false,
                                    'mark' => ['$exists' => false]
                                ]
                            );
                            $count = 0;
                            foreach ($records as $record) {
                                $this->storeRecord(
                                    $this->sourceId, $record['oai_id'], true, ''
                                );
                                if (++$count % 1000 == 0) {
                                    $this->logger->log(
                                        'harvest', "Deleted $count records"
                                    );
                                }
                            }
                            $this->logger->log('harvest', "Deleted $count records");

                            $state = [
                                '_id' => "Last Deletion Processing Time $source",
                                'value' => time()
                            ];
                            $this->db->saveState($state);
                        }
                    }
                }
                $this->logger->log(
                    'harvest', "Harvesting from '{$source}' completed"
                );
            } catch (\Exception $e) {
                $this->logger->log(
                    'harvest', 'Exception: ' . $e->getMessage(), Logger::FATAL
                );
            }
        }
    }

    /**
     * Dump a single record to console
     *
     * @param string $recordID ID of the record to be dumped
     *
     * @return void
     * @throws Exception
     */
    public function dumpRecord($recordID)
    {
        if (!$recordID) {
            throw new \Exception('dump: record id must be specified');
        }
        $records = $this->db->findRecords(['_id' => $recordID]);
        foreach ($records as $record) {
            $record['original_data'] = MetadataUtils::getRecordData($record, false);
            $record['normalized_data'] = MetadataUtils::getRecordData($record, true);
            if ($record['original_data'] == $record['normalized_data']) {
                $record['normalized_data'] = '';
            }
            print_r($record);
        }
    }

    /**
     * Mark deleted records of a single data source
     *
     * @param string $sourceId Source ID
     *
     * @return void
     */
    public function markDeleted($sourceId)
    {
        $this->logger->log('markDeleted', "Creating record list for '$sourceId'");

        $params = ['deleted' => false, 'source_id' => $sourceId];
        $records = $this->db->findRecords($params);
        $total = $this->db->countRecords($params);
        $count = 0;

        $this->logger->log(
            'markDeleted', "Marking deleted $total records from '$sourceId'"
        );
        $pc = new PerformanceCounter();
        foreach ($records as $record) {
            if (isset($record['dedup_id'])) {
                $this->dedupHandler->removeFromDedupRecord(
                    $record['dedup_id'], $record['_id']
                );
                unset($record['dedup_id']);
            }
            $record['deleted'] = true;
            $record['updated'] = new \MongoDB\BSON\UTCDateTime(time() * 1000);
            $this->db->saveRecord($record);

            ++$count;
            if ($count % 1000 == 0) {
                $pc->add($count);
                $avg = $pc->getSpeed();
                $this->logger->log(
                    'markDeleted',
                    "$count records marked deleted from '$sourceId', "
                    . "$avg records/sec"
                );
            }
        }
        $this->logger->log(
            'markDeleted',
            "Completed with $count records marked deleted from '$sourceId'"
        );

        $this->logger->log(
            'markDeleted',
            "Deleting last harvest date from data source '$sourceId'"
        );
        $this->db->deleteState("Last Harvest Date $sourceId");
        $this->logger->log('markDeleted', "Marking of $sourceId completed");
    }

    /**
     * Delete records of a single data source from the Mongo database
     *
     * @param string  $sourceId Source ID
     * @param boolean $force    Force deletion even if dedup is enable for the source
     *
     * @return void
     */
    public function deleteRecords($sourceId, $force = false)
    {
        if (isset($this->dataSourceSettings[$sourceId])) {
            $settings = $this->dataSourceSettings[$sourceId];
            if (isset($settings['dedup']) && $settings['dedup']) {
                if ($force) {
                    $this->logger->log(
                        'deleteRecords',
                        "Deduplication enabled for '$sourceId' but deletion forced "
                        . " - may lead to orphaned dedup records",
                        Logger::WARNING
                    );
                } else {
                    $this->logger->log(
                        'deleteRecords',
                        "Deduplication enabled for '$sourceId', aborting "
                        . "(use markdeleted instead)",
                        Logger::ERROR
                    );
                    return;
                }
            }
        }

        $params = [];
        $params['source_id'] = $sourceId;
        $this->logger->log('deleteRecords', "Creating record list for '$sourceId'");

        $params = ['source_id' => $sourceId];
        $records = $this->db->findRecords($params);
        $total = $this->db->countRecords($params);
        $count = 0;

        $this->logger->log(
            'deleteRecords', "Deleting $total records from '$sourceId'"
        );
        $pc = new PerformanceCounter();
        foreach ($records as $record) {
            if (isset($record['dedup_id'])) {
                $this->dedupHandler->removeFromDedupRecord(
                    $record['dedup_id'], $record['_id']
                );
            }
            $this->db->deleteRecord($record['_id']);

            ++$count;
            if ($count % 1000 == 0) {
                $pc->add($count);
                $avg = $pc->getSpeed();
                $this->logger->log(
                    'deleteRecords',
                    "$count records deleted from '$sourceId', $avg records/sec"
                );
            }
        }
        $this->logger->log(
            'deleteRecords', "Completed with $count records deleted from '$sourceId'"
        );

        $this->logger->log(
            'deleteRecords',
            "Deleting last harvest date from data source '$sourceId'"
        );
        $this->db->deleteState("Last Harvest Date $sourceId");
        $this->logger->log('deleteRecords', "Deletion of $sourceId completed");
    }

    /**
     * Delete records of a single data source from the Solr index
     *
     * @param string $sourceId Source ID
     *
     * @return void
     */
    public function deleteSolrRecords($sourceId)
    {
        global $configArray;

        $updater = new SolrUpdater(
            $this->db, $this->basePath, $this->logger, $this->verbose
        );
        if (isset($configArray['Solr']['merge_records'])
            && $configArray['Solr']['merge_records']
        ) {
            $this->logger->log(
                'deleteSolrRecords',
                "Deleting data source '$sourceId' from merged records via Solr "
                . "update for merged records"
            );
            $updater->updateRecords('', $sourceId, '', false, true);
        }
        $this->logger->log(
            'deleteSolrRecords',
            "Deleting data source '$sourceId' directly from Solr"
        );
        $updater->deleteDataSource($sourceId);
        $this->logger->log(
            'deleteSolrRecords', "Deletion of '$sourceId' from Solr completed"
        );
    }

    /**
     * Optimize the Solr index
     *
     * @return void
     */
    public function optimizeSolr()
    {
        $updater = new SolrUpdater(
            $this->db, $this->basePath, $this->logger, $this->verbose
        );

        $this->logger->log('optimizeSolr', 'Optimizing Solr index');
        $updater->optimizeIndex();
        $this->logger->log('optimizeSolr', 'Solr optimization completed');
    }

    /**
     * Save a record temporarily to an array. Used by MetaLib harvesting.
     *
     * @param string $oaiID      ID of the record as received from OAI-PMH
     * @param bool   $deleted    Whether the record is to be deleted
     * @param string $recordData Record metadata
     *
     * @throws Exception
     * @return integer Number of records processed (can be > 1 for split records)
     */
    public function storeMetaLibRecord($oaiID, $deleted, $recordData)
    {
        $this->metaLibRecords[] = $recordData;
        return 1;
    }

    /**
     * Count distinct values in the specified field (that would be added to the
     * Solr index)
     *
     * @param string $sourceId Source ID
     * @param string $field    Field name
     * @param bool   $mapped   Whether to count values after any mapping files are
     *                         are processed
     *
     * @return void
     */
    public function countValues($sourceId, $field, $mapped)
    {
        if (!$field) {
            echo "Field must be specified\n";
            exit;
        }
        $updater = new SolrUpdater(
            $this->db, $this->basePath, $this->logger, $this->verbose
        );
        $updater->countValues($sourceId, $field, $mapped);
    }

    /**
     * Mark a record "seen". Used by OAI-PMH harvesting when deletions are not
     * supported.
     *
     * @param string $oaiID   ID of the record as received from OAI-PMH
     * @param bool   $deleted Whether the record is to be deleted
     *
     * @throws Exception
     * @return void
     */
    public function markRecord($oaiID, $deleted)
    {
        if ($deleted) {
            // Don't mark deleted records...
            return;
        }
        $this->db->updateRecords(
            ['source_id' => $this->sourceId, 'oai_id' => $oaiID],
            ['mark' => true]
        );
    }

    /**
     * Verify consistency of dedup records links with actual records
     *
     * @return void
     */
    public function checkDedupRecords()
    {
        $this->logger->log('checkDedupRecords', "Checking dedup record consistency");

        $dedupRecords = $this->db->findDedups([]);
        $count = 0;
        $fixed = 0;
        $pc = new PerformanceCounter();
        foreach ($dedupRecords as $dedupRecord) {
            $results = $this->dedupHandler->checkDedupRecord($dedupRecord);
            if ($results) {
                $fixed += count($results);
                foreach ($results as $result) {
                    $this->logger->log('checkDedupRecords', $result);
                }
            }
            ++$count;
            if ($count % 1000 == 0) {
                $pc->add($count);
                $avg = $pc->getSpeed();
                $this->logger->log(
                    'checkDedupRecords',
                    "$count records checked with $fixed links fixed, "
                    . "$avg records/sec"
                );
            }
        }
        $this->logger->log(
            'checkDedupRecords',
            "Completed with $count records checked with $fixed links fixed"
        );
    }

    /**
     * Search for $regexp in data sources
     *
     * @param string $regexp Regular expression
     *
     * @return void
     */
    public function searchDataSources($regexp)
    {
        if (substr($regexp, 0, 1) !== '/') {
            $regexp = "/$regexp/";
        }
        $matches = '';
        foreach ($this->dataSourceSettings as $source => $settings) {
            foreach ($settings as $setting => $value) {
                foreach (is_array($value) ? $value : [$value] as $single) {
                    if (!is_string($single)) {
                        continue;
                    }
                    if (preg_match($regexp, "$setting=$single")) {
                        if ($matches) {
                            $matches .= ',';
                        }
                        $matches .= $source;
                        break 2;
                    }
                }
            }
        }
        echo "$matches\n";
    }

    /**
     * Creates a preview of the given metadata and returns it
     *
     * @param string $metadata The metadata to process
     * @param string $format   Metadata format
     * @param string $source   Source identifier
     *
     * @return array Solr record fields
     */
    public function previewRecord($metadata, $format, $source)
    {
        $preview = new \RecordManager\Base\Solr\PreviewCreator(
            $this->db, $this->basePath, $this->logger, $this->verbose
        );
        return $preview->preview($metadata, $format, $source);
    }

    /**
     * Execute a pretransformation on data before it is split into records and
     * loaded. Used when loading from a file.
     *
     * @param string $data The original data
     *
     * @return string Transformed data
     */
    protected function pretransform($data)
    {
        if (!isset($this->preXSLT)) {
            $style = new \DOMDocument();
            $style->load(
                $this->basePath . '/transformations/' . $this->pretransformation
            );
            $this->preXSLT = new \XSLTProcessor();
            $this->preXSLT->importStylesheet($style);
            $this->preXSLT->setParameter('', 'source_id', $this->sourceId);
            $this->preXSLT->setParameter('', 'institution', $this->institution);
            $this->preXSLT->setParameter('', 'format', $this->format);
            $this->preXSLT->setParameter('', 'id_prefix', $this->idPrefix);
        }
        $doc = new \DOMDocument();
        $doc->loadXML($data, LIBXML_PARSEHUGE);
        return $this->preXSLT->transformToXml($doc);
    }

    /**
     * Load the data source settings and setup some functions
     *
     * @param string $source Source ID
     *
     * @throws Exception
     * @return void
     */
    protected function loadSourceSettings($source)
    {
        if (!isset($this->dataSourceSettings[$source])) {
            $this->logger->log(
                'loadSourceSettings',
                "Settings not found for data source $source",
                Logger::FATAL
            );
            throw new \Exception("Error: settings not found for $source\n");
        }
        $settings = $this->dataSourceSettings[$source];
        if (!isset($settings['institution'])) {
            $this->logger->log(
                'loadSourceSettings',
                "institution not set for $source",
                Logger::FATAL
            );
            throw new \Exception("Error: institution not set for $source\n");
        }
        if (!isset($settings['format'])) {
            $this->logger->log(
                'loadSourceSettings', "format not set for $source", Logger::FATAL
            );
            throw new \Exception("Error: format not set for $source\n");
        }
        $this->format = $settings['format'];
        $this->sourceId = $source;
        $this->idPrefix = isset($settings['idPrefix']) && $settings['idPrefix']
            ? $settings['idPrefix'] : $source;
        $this->institution = $settings['institution'];
        $this->recordXPath
            = isset($settings['recordXPath']) ? $settings['recordXPath'] : '';
        $this->oaiIDXPath
            = isset($settings['oaiIDXPath']) ? $settings['oaiIDXPath'] : '';
        $this->dedup = isset($settings['dedup']) ? $settings['dedup'] : false;
        $this->componentParts = isset($settings['componentParts'])
            && $settings['componentParts'] ? $settings['componentParts'] : 'as_is';
        $this->pretransformation = isset($settings['preTransformation'])
            ? $settings['preTransformation'] : '';
        $this->indexMergedParts = isset($settings['indexMergedParts'])
            ? $settings['indexMergedParts'] : true;
        $this->harvestType = isset($settings['type']) ? $settings['type'] : '';
        $this->nonInheritedFields = isset($settings['non_inherited_fields'])
            ? $settings['non_inherited_fields'] : [];
        $this->prependParentTitleWithUnitId
            = isset($settings['prepend_parent_title_with_unitid'])
            ? $settings['prepend_parent_title_with_unitid'] : true;

        $params = [
            'source_id' => $this->sourceId,
            'institution' => $this->institution,
            'format' => $this->format,
            'id_prefix' => $this->idPrefix
        ];
        $this->normalizationXSLT = isset($settings['normalization'])
            && $settings['normalization']
            ? new XslTransformation(
                $this->basePath . '/transformations',
                $settings['normalization'],
                $params
            ) : null;
        $this->solrTransformationXSLT = isset($settings['solrTransformation'])
            && $settings['solrTransformation']
            ? new XslTransformation(
                $this->basePath . '/transformations',
                $settings['solrTransformation'],
                $params
            ) : null;

        if (isset($settings['recordSplitter'])) {
            if (substr($settings['recordSplitter'], -4) == '.php') {
                $this->recordSplitter = $settings['recordSplitter'];
            } else {
                $style = new \DOMDocument();
                $xslFile = $this->basePath . '/transformations/'
                    . $settings['recordSplitter'];
                if ($style->load($xslFile) === false) {
                    throw new \Exception("Could not load $xslFile");
                }
                $this->recordSplitter = new \XSLTProcessor();
                $this->recordSplitter->importStylesheet($style);
            }
        } else {
            $this->recordSplitter = null;
        }

        $this->keepMissingHierarchyMembers
            = isset($settings['keepMissingHierarchyMembers'])
            ? $settings['keepMissingHierarchyMembers']
            : false;
    }

    /**
     * Process a complete record set harvested e.g. from MetaLib
     *
     * @param string   $source           Source ID
     * @param string[] $harvestedRecords Array of records
     *
     * @return void
     */
    protected function processFullRecordSet(
        $source,
        $harvestedRecords
    ) {
        $this->logger->log(
            'processFullRecordSet', "[$source] Processing complete record set"
        );
        // Create keyed array
        $records = [];
        foreach ($harvestedRecords as $record) {
            $marc = RecordFactory::createRecord('marc', $record, '', $source);
            $id = $marc->getID();
            $records["$source.$id"] = $record;
        }

        $this->logger->log(
            'processFullRecordSet',
            "[$source] Merging results with the records in database"
        );
        $deleted = 0;
        $unchanged = 0;
        $changed = 0;
        $added = 0;
        $dbRecords = $this->db->findRecords(
            ['deleted' => false, 'source_id' => $source]
        );
        foreach ($dbRecords as $dbRecord) {
            $id = $dbRecord['_id'];
            if (!isset($records[$id])) {
                // Record not in harvested records, mark deleted
                $this->storeRecord($source, $id, true, '');
                unset($records[$id]);
                ++$deleted;
                continue;
            }
            // Check if the record has changed
            $marc = RecordFactory::createRecord('marc', $records[$id], '', $source);
            if ($marc->serialize() != MetadataUtils::getRecordData($dbRecord, false)
            ) {
                // Record changed, update...
                $this->storeRecord($source, $id, false, $records[$id]);
                ++$changed;
            } else {
                ++$unchanged;
            }
            unset($records[$id]);
        }
        $this->logger->log('processFullRecordSet', "[$source] Adding new records");
        foreach ($records as $id => $record) {
            $this->storeRecord($source, $id, false, $record);
            ++$added;
        }
        $this->logger->log(
            'processFullRecordSet',
            "[$source] $added new, $changed changed, $unchanged unchanged and "
            . "$deleted deleted records processed"
        );
    }
}

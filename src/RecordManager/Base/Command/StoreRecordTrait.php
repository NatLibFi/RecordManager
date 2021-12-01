<?php
/**
 * Record storage trait
 *
 * Prerequisites:
 * - MetadataUtils as $this->metadataUtils.
 * - Logger as $this->logger
 *
 * PHP version 7
 *
 * Copyright (C) The National Library of Finland 2011-2021.
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
namespace RecordManager\Base\Command;

/**
 * Record storage trait
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
trait StoreRecordTrait
{
    /**
     * ID of any previously stored record
     *
     * @var string
     */
    protected $previousStoredId = '[none]';

    /**
     * Deduplication handler
     *
     * @var \RecordManager\Base\Deduplication\DedupHandlerInterface
     */
    protected $dedupHandler;

    /**
     * Whether to set mark ("seen" flag) true for stored records
     *
     * @var bool
     */
    protected $markRecords = false;

    /**
     * Save a record into the database. Used by e.g. file import and OAI-PMH
     * harvesting.
     *
     * @param string $sourceId   Source ID
     * @param string $oaiID      ID of the record as received from OAI-PMH
     * @param bool   $deleted    Whether the record is to be deleted
     * @param string $recordData Record metadata
     *
     * @throws \Exception
     * @return integer Number of records processed (can be > 1 for split records)
     */
    public function storeRecord($sourceId, $oaiID, $deleted, $recordData)
    {
        if (!isset($this->dedupHandler)) {
            throw new \Exception('Dedup handler missing');
        }

        if ($deleted && !empty($oaiID)) {
            return $this->deleteByOaiId($sourceId, $oaiID);
        }

        $dataArray = [];
        $settings = $this->dataSourceConfig[$sourceId];
        if ($settings['recordSplitter']) {
            $this->logger->writelnDebug('Splitting records');
            if (!($settings['recordSplitter'] instanceof \XSLTProcessor)) {
                $splitterParams = !empty($settings['recordSplitterParams'])
                    ? $settings['recordSplitterParams']
                    : [];
                // Support legacy params
                if (!empty($settings['prependParentTitleWithUnitId'])) {
                    $splitterParams['prependParentTitleWithUnitId'] = true;
                }
                if (!empty($settings['nonInheritedFields'])) {
                    $splitterParams['nonInheritedFields']
                        = $settings['nonInheritedFields'];
                }
                $splitter = $settings['recordSplitter'];
                $splitter->init($splitterParams);
                $splitter->setData($recordData);
                while (!$splitter->getEOF()) {
                    $splitRecord = $splitter->getNextRecord();
                    $dataArray[] = $splitRecord['metadata'];
                }
            } else {
                $doc = new \DOMDocument();
                $doc->loadXML($recordData);
                $this->logger->writelnDebug('XML Doc Created');
                $transformedDoc = $settings['recordSplitter']->transformToDoc($doc);
                $this->logger->writelnDebug('XML Transformation Done');
                $records = simplexml_import_dom($transformedDoc);
                $this->logger->writelnDebug('Creating record array');
                foreach ($records as $record) {
                    $dataArray[] = $record->saveXML();
                }
            }
        } else {
            $dataArray = [$recordData];
        }

        $this->logger->writelnDebug(
            'Storing array of ' . count($dataArray) . ' records'
        );

        // Store start time so that we can mark deleted any child records not
        // present anymore
        $startTime = $this->db->getTimestamp();

        $count = 0;
        $mainID = '';
        foreach ($dataArray as $data) {
            if (null !== $settings['normalizationXSLT']) {
                $metadataRecord = $this->createRecord(
                    $settings['format'],
                    $settings['normalizationXSLT']
                        ->transform($data, ['oai_id' => $oaiID]),
                    $oaiID,
                    $sourceId
                );
                $metadataRecord->normalize();
                $normalizedData = $metadataRecord->serialize();
                $originalData = $this->createRecord(
                    $settings['format'],
                    $data,
                    $oaiID,
                    $sourceId
                )->serialize();
            } else {
                $metadataRecord = $this->createRecord(
                    $settings['format'],
                    $data,
                    $oaiID,
                    $sourceId
                );
                $originalData = $metadataRecord->serialize();
                $metadataRecord->normalize();
                $normalizedData = $metadataRecord->serialize();
            }

            $id = $metadataRecord->getID();
            if (!$id) {
                if (!$oaiID) {
                    throw new \Exception(
                        'Empty ID returned for record, and no OAI ID '
                        . "(previous record ID: $this->previousStoredId)"
                    );
                }
                $id = $oaiID;
            }

            // If the record is suppressed, mark it deleted
            if (!$deleted && $metadataRecord->getSuppressed()) {
                $deleted = true;
            }

            $this->previousStoredId = $id;
            $id = $settings['idPrefix'] . '.' . $id;
            $hostIDs = $metadataRecord->getHostRecordIDs();
            $dbRecord = $this->db->getRecord($id);
            if ($dbRecord) {
                $dbRecord['updated'] = $this->db->getTimestamp();
                $this->logger->writelnDebug("Updating record $id");
            } else {
                $dbRecord = [];
                $dbRecord['source_id'] = $sourceId;
                $dbRecord['_id'] = $id;
                $dbRecord['created'] = $dbRecord['updated']
                    = $this->db->getTimestamp();
                $this->logger->writelnDebug("Adding record $id");
            }
            $dbRecord['date'] = $dbRecord['updated'];
            if ($this->markRecords) {
                $dbRecord['mark'] = true;
            }
            if ($normalizedData) {
                if ($originalData == $normalizedData) {
                    $normalizedData = '';
                }
            }
            $dbRecord['oai_id'] = $oaiID;
            $dbRecord['deleted'] = $deleted;
            $dbRecord['linking_id'] = $metadataRecord->getLinkingIDs();
            if ($mainID) {
                $dbRecord['main_id'] = $mainID;
            }
            if ($hostIDs) {
                $dbRecord['host_record_id'] = $hostIDs;
            } elseif (isset($dbRecord['host_record_id'])) {
                unset($dbRecord['host_record_id']);
            }
            $dbRecord['format'] = $settings['format'];
            $dbRecord['original_data'] = $originalData;
            $dbRecord['normalized_data'] = $normalizedData;
            $hostSourceIds = !empty($settings['__hostRecordSourceId'])
                ? $settings['__hostRecordSourceId'] : [$sourceId];
            if ($settings['dedup']) {
                if ($dbRecord['deleted']) {
                    if (isset($dbRecord['dedup_id'])) {
                        $this->dedupHandler->removeFromDedupRecord(
                            $dbRecord['dedup_id'],
                            $dbRecord['_id']
                        );
                        unset($dbRecord['dedup_id']);
                    }
                    $dbRecord['update_needed'] = false;
                } else {
                    // If this is a host record, mark it to be deduplicated.
                    // If this is a component part, mark its host record to be
                    // deduplicated.
                    if (!$hostIDs) {
                        $dbRecord['update_needed']
                            = $this->dedupHandler->updateDedupCandidateKeys(
                                $dbRecord,
                                $metadataRecord
                            );
                    } else {
                        $this->db->updateRecords(
                            [
                                'source_id' => ['$in' => $hostSourceIds],
                                'linking_id' => ['$in' => (array)$hostIDs]
                            ],
                            ['update_needed' => true]
                        );
                        $dbRecord['update_needed'] = false;
                    }
                }
            } else {
                if (isset($dbRecord['title_keys'])) {
                    unset($dbRecord['title_keys']);
                }
                if (isset($dbRecord['isbn_keys'])) {
                    unset($dbRecord['isbn_keys']);
                }
                if (isset($dbRecord['id_keys'])) {
                    unset($dbRecord['id_keys']);
                }
                $dbRecord['update_needed'] = false;

                // Mark host records updated too
                if ($hostIDs) {
                    $this->db->updateRecords(
                        [
                            'source_id' => ['$in' => $hostSourceIds],
                            'linking_id' => ['$in' => (array)$hostIDs]
                        ],
                        ['updated' => $this->db->getTimestamp()]
                    );
                }
            }
            $this->db->saveRecord($dbRecord);
            ++$count;
            if (!$mainID) {
                $mainID = $id;
            }
        }

        if ($count > 1 && $mainID && !$settings['keepMissingHierarchyMembers']) {
            // We processed a hierarchical record. Mark deleted any children that
            // were not updated.
            $this->db->updateRecords(
                [
                    'source_id' => $sourceId,
                    'main_id' => $mainID,
                    'updated' => ['$lt' => $startTime],
                    'deleted' => false,
                ],
                [
                    'deleted' => true,
                    'updated' => $this->db->getTimestamp(),
                    'update_needed' => false
                ]
            );
        }

        return $count;
    }

    /**
     * Mark a record deleted
     *
     * @param array $record          Record
     * @param bool  $deferHostUpdate Whether to defer updating host records'
     *                               timestamps
     *
     * @return void
     */
    public function markRecordDeleted($record, $deferHostUpdate = false)
    {
        $dedupId = $record['dedup_id'] ?? null;
        if (isset($record['dedup_id'])) {
            unset($record['dedup_id']);
        }
        $record['deleted'] = true;
        $record['updated'] = $this->db->getTimestamp();
        $record['update_needed'] = false;
        $this->db->saveRecord($record);

        // Save dedup record now that record's dedup_id is cleared
        if (null !== $dedupId) {
            $this->dedupHandler->removeFromDedupRecord(
                $dedupId,
                $record['_id']
            );
        }

        // Mark host records updated too
        $sourceId = $record['source_id'];
        $settings = $this->dataSourceConfig[$sourceId];
        $metadataRecord = $this->createRecord(
            $record['format'],
            $this->metadataUtils->getRecordData($record, true),
            $record['oai_id'],
            $sourceId
        );
        $hostIDs = $metadataRecord->getHostRecordIDs();
        if ($hostIDs) {
            $hostSourceIds = !empty($settings['__hostRecordSourceId'])
                ? $settings['__hostRecordSourceId'] : [$sourceId];
            $this->db->updateRecords(
                [
                    'source_id' => ['$in' => $hostSourceIds],
                    'linking_id' => ['$in' => (array)$hostIDs],
                    'deleted' => false
                ],
                $deferHostUpdate
                    ? ['update_needed' => true]
                    : ['updated' => $this->db->getTimestamp()]
            );
        }
    }

    /**
     * Delete records with an OAI identifier
     *
     * @param string $sourceId Source ID
     * @param string $oaiID    ID of the record as received from OAI-PMH
     *
     * @return int Count of records deleted
     */
    protected function deleteByOaiId($sourceId, $oaiID)
    {
        // A single OAI-PMH record may have been split to multiple records. Find
        // all occurrences.
        $count = 0;
        $this->db->iterateRecords(
            ['source_id' => $sourceId, 'oai_id' => $oaiID],
            [],
            function ($record) use (&$count, $oaiID) {
                $this->logger->writelnDebug(
                    "Delete by oai_id $oaiID: {$record['_id']}"
                );
                $this->markRecordDeleted($record);
                ++$count;
            }
        );
        return $count;
    }
}

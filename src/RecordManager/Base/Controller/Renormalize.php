<?php
/**
 * Record Renormalization
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

use RecordManager\Base\Utils\MetadataUtils;
use RecordManager\Base\Utils\PerformanceCounter;

/**
 * Record Renormalization
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class Renormalize extends AbstractBase
{
    /**
     * Renormalize records in a data source
     *
     * @param string $sourceId Source ID to renormalize
     * @param string $singleId Renormalize only a single record with the given ID
     *
     * @return void
     */
    public function launch($sourceId, $singleId)
    {
        $this->initSourceSettings();
        $dedupHandler = $this->getDedupHandler();
        foreach ($this->dataSourceSettings as $source => $settings) {
            if ($sourceId && $sourceId != '*' && $source != $sourceId) {
                continue;
            }
            if (empty($source) || empty($settings)) {
                continue;
            }
            $this->logger->log('renormalize', "Creating record list for '$source'");

            $params = ['deleted' => false];
            if ($singleId) {
                $params['_id'] = $singleId;
                $params['source_id'] = $source;
            } else {
                $params['source_id'] = $source;
            }
            $records = $this->db->findRecords($params);
            $total = $this->db->countRecords($params);
            $count = 0;

            $this->logger->log(
                'renormalize', "Processing $total records from '$source'"
            );
            $pc = new PerformanceCounter();
            foreach ($records as $record) {
                $originalData = MetadataUtils::getRecordData($record, false);
                $normalizedData = $originalData;
                if (null !== $settings['normalizationXSLT']) {
                    $origMetadataRecord = $this->recordFactory->createRecord(
                        $record['format'],
                        $originalData,
                        $record['oai_id'],
                        $record['source_id']
                    );
                    $normalizedData = $settings['normalizationXSLT']->transform(
                        $origMetadataRecord->toXML(), ['oai_id' => $record['oai_id']]
                    );
                }

                $metadataRecord = $this->recordFactory->createRecord(
                    $record['format'],
                    $normalizedData,
                    $record['oai_id'],
                    $record['source_id']
                );
                $metadataRecord->normalize();

                if ($metadataRecord->getSuppressed()) {
                    $record['deleted'] = true;
                }

                $hostIDs = $metadataRecord->getHostRecordIDs();
                $normalizedData = $metadataRecord->serialize();
                if ($settings['dedup'] && !$hostIDs && !$record['deleted']) {
                    $record['update_needed'] = $dedupHandler
                        ->updateDedupCandidateKeys($record, $metadataRecord);
                } else {
                    if (isset($record['title_keys'])) {
                        unset($record['title_keys']);
                    }
                    if (isset($record['isbn_keys'])) {
                        unset($record['isbn_keys']);
                    }
                    if (isset($record['id_keys'])) {
                        unset($record['id_keys']);
                    }
                    if (isset($record['dedup_id'])) {
                        unset($record['dedup_id']);
                    }
                    $record['update_needed'] = false;
                }

                $record['original_data'] = $originalData;
                if ($normalizedData == $originalData) {
                    $record['normalized_data'] = '';
                } else {
                    $record['normalized_data'] = $normalizedData;
                }
                $record['linking_id'] = $metadataRecord->getLinkingID();
                if ($hostIDs) {
                    $record['host_record_id'] = $hostIDs;
                } elseif (isset($record['host_record_id'])) {
                    unset($record['host_record_id']);
                }
                $record['updated'] = $this->db->getTimestamp();
                $this->db->saveRecord($record);

                if ($this->verbose) {
                    echo "Metadata for record {$record['_id']}: \n";
                    $record['normalized_data']
                        = MetadataUtils::getRecordData($record, true);
                    $record['original_data']
                        = MetadataUtils::getRecordData($record, false);
                    if ($record['normalized_data'] === $record['original_data']) {
                        $record['normalized_data'] = '';
                    }
                    print_r($record);
                }

                ++$count;
                if ($count % 1000 == 0) {
                    $pc->add($count);
                    $avg = $pc->getSpeed();
                    $this->logger->log(
                        'renormalize',
                        "$count records processed from '$source', $avg records/sec"
                    );
                }
            }
            $this->logger->log(
                'renormalize',
                "Completed with $count records processed from '$source'"
            );
        }
    }
}

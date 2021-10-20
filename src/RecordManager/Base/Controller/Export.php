<?php
/**
 * Export
 *
 * PHP version 7
 *
 * Copyright (C) The National Library of Finland 2011-2020.
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
namespace RecordManager\Base\Controller;

use RecordManager\Base\Utils\MetadataUtils;

/**
 * Export
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class Export extends AbstractBase
{
    /**
     * Export records from the database to a file
     *
     * @param string $file            File name where to write exported records
     * @param string $deletedFile     File name where to write IDs of deleted records
     * @param string $fromDate        Starting date of last update (e.g. 2011-12-24)
     * @param string $untilDate       Ending date of last update (e.g. 2011-12-24)
     * @param string $fromCreateDate  Starting date of creation (e.g. 2011-12-24)
     * @param string $untilCreateDate Ending date of creation (e.g. 2011-12-24)
     * @param int    $skipRecords     Export only one per each $skipRecords records
     *                                for a sample set
     * @param string $sourceId        Source ID to export, or empty or * for all
     * @param string $singleId        Export only a record with the given ID
     * @param string $xpath           Optional XPath expression to limit the export
     *                                with
     * @param bool   $sortDedup       Whether to sort the records by dedup id
     * @param string $addDedupId      When to add dedup id to each record
     *                                ('deduped' = when the record has duplicates,
     *                                'always' = even if the record doesn't have
     *                                duplicates, otherwise never)
     *
     * @return void
     */
    public function launch(
        $file,
        $deletedFile,
        $fromDate,
        $untilDate,
        $fromCreateDate,
        $untilCreateDate,
        $skipRecords = 0,
        $sourceId = '',
        $singleId = '',
        $xpath = '',
        $sortDedup = false,
        $addDedupId = ''
    ) {
        if ($file == '-') {
            $file = 'php://stdout';
        }

        if (file_exists($file)) {
            unlink($file);
        }
        if ($deletedFile && file_exists($deletedFile)) {
            unlink($deletedFile);
        }
        file_put_contents(
            $file,
            "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n\n<collection>\n",
            FILE_APPEND
        );

        try {
            $this->logger->logInfo('exportRecords', 'Creating record list');

            $params = [];
            if ($singleId) {
                $params['_id'] = $singleId;
            } else {
                if ($fromDate && $untilDate) {
                    $params['$and'] = [
                        [
                            'updated' => [
                                '$gte'
                                    => $this->db->getTimestamp(strtotime($fromDate))
                            ]
                        ],
                        [
                            'updated' => [
                                '$lte'
                                    => $this->db->getTimestamp(strtotime($untilDate))
                            ]
                        ]
                    ];
                } elseif ($fromDate) {
                    $params['updated']
                        = ['$gte' => $this->db->getTimestamp(strtotime($fromDate))];
                } elseif ($untilDate) {
                    $params['updated']
                        = ['$lte' => $this->db->getTimestamp(strtotime($untilDate))];
                }
                if ($fromCreateDate && $untilCreateDate) {
                    $params['$and'] = [
                        [
                            'created' => [
                                '$gte' => $this->db->getTimestamp(
                                    strtotime($fromCreateDate)
                                )
                            ]
                        ],
                        [
                            'created' => [
                                '$lte' => $this->db->getTimestamp(
                                    strtotime($untilCreateDate)
                                )
                            ]
                        ]
                    ];
                } elseif ($fromCreateDate) {
                    $params['created'] = [
                        '$gte' => $this->db->getTimestamp(strtotime($fromCreateDate))
                    ];
                } elseif ($untilDate) {
                    $params['created'] = [
                        '$lte'
                            => $this->db->getTimestamp(strtotime($untilCreateDate))
                    ];
                }
                if ($sourceId && $sourceId !== '*') {
                    $sources = explode(',', $sourceId);
                    if (count($sources) == 1) {
                        $params['source_id'] = $sourceId;
                    } else {
                        $sourceParams = [];
                        foreach ($sources as $source) {
                            $sourceParams[] = ['source_id' => $source];
                        }
                        $params['$or'] = $sourceParams;
                    }
                }
            }
            $options = [];
            if ($sortDedup) {
                $options['sort'] = ['dedup_id' => 1];
            }

            $total = $this->db->countRecords($params, $options);
            $count = 0;
            $deduped = 0;
            $deleted = 0;
            $this->logger->logInfo('exportRecords', "Exporting $total records");
            if ($skipRecords) {
                $this->logger->logInfo(
                    'exportRecords',
                    "(1 per each $skipRecords records)"
                );
            }
            $this->db->iterateRecords(
                $params,
                $options,
                function ($record) use (
                    &$count,
                    &$deduped,
                    &$deleted,
                    $skipRecords,
                    $xpath,
                    $file,
                    $deletedFile,
                    $addDedupId
                ) {
                    $metadataRecord = $this->createRecord(
                        $record['format'],
                        MetadataUtils::getRecordData($record, true),
                        $record['oai_id'],
                        $record['source_id']
                    );
                    if ($xpath) {
                        $xml = $metadataRecord->toXML();
                        $dom = MetadataUtils::loadXML($xml);
                        if (!$dom) {
                            throw new \Exception(
                                "Failed to parse record '${$record['_id']}'"
                            );
                        }
                        $xpathResult = $dom->xpath($xpath);
                        if ($xpathResult === false) {
                            throw new \Exception(
                                "Failed to evaluate XPath expression '$xpath'"
                            );
                        }
                        if (!$xpathResult) {
                            return true;
                        }
                    }
                    ++$count;
                    if ($record['deleted']) {
                        if ($deletedFile) {
                            file_put_contents(
                                $deletedFile,
                                "{$record['_id']}\n",
                                FILE_APPEND
                            );
                        }
                        ++$deleted;
                    } else {
                        if ($skipRecords > 0 && $count % $skipRecords != 0) {
                            return true;
                        }
                        if (isset($record['dedup_id'])) {
                            ++$deduped;
                        }
                        if ($addDedupId == 'always') {
                            $metadataRecord->addDedupKeyToMetadata(
                                $record['dedup_id']
                                ?? $record['_id']
                            );
                        } elseif ($addDedupId == 'deduped') {
                            $metadataRecord->addDedupKeyToMetadata(
                                $record['dedup_id']
                                ?? ''
                            );
                        }
                        $xml = $metadataRecord->toXML();
                        $xml = preg_replace('/^<\?xml.*?\?>[\n\r]*/', '', $xml);
                        file_put_contents($file, $xml . "\n", FILE_APPEND);
                    }
                    if ($count % 1000 == 0) {
                        $this->logger->logInfo(
                            'exportRecords',
                            "$count records (of which $deduped deduped, $deleted "
                            . "deleted) exported"
                        );
                    }
                }
            );
            $this->logger->logInfo(
                'exportRecords',
                "Completed with $count records (of which $deduped deduped, $deleted "
                . "deleted) exported"
            );
        } catch (\Exception $e) {
            $this->logger->logFatal(
                'exportRecords',
                'Exception: ' . $e->getMessage()
            );
        }
        file_put_contents($file, "</collection>\n", FILE_APPEND);
    }
}

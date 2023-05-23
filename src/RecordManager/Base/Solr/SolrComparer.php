<?php

/**
 * SolrComparer Class
 *
 * PHP version 8
 *
 * Copyright (C) The National Library of Finland 2021.
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

namespace RecordManager\Base\Solr;

use RecordManager\Base\Utils\PerformanceCounter;

/**
 * SolrComparer Class
 *
 * Class for comparing records with the Solr index.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class SolrComparer extends SolrUpdater
{
    /**
     * Fields to compare
     *
     * @var array
     */
    protected $compareFields;

    /**
     * Whether to skip missing records
     *
     * @var bool
     */
    protected $skipMissing;

    /**
     * Compare records with the Solr index
     *
     * @param string      $logFile     Log file to use for any record differences
     * @param string|null $fromDate    Starting date for updates (if empty
     *                                 string, all records are processed)
     * @param string      $sourceId    Comma-separated list of source IDs to
     *                                 update, or empty or * for all sources
     * @param string      $singleId    Process only the record with the given ID
     * @param string      $fields      Compare only the given fields
     * @param bool        $skipMissing Whether to skip records missing from index
     *
     * @return void
     */
    public function compareRecords(
        ?string $logFile,
        ?string $fromDate,
        ?string $sourceId,
        ?string $singleId,
        ?string $fields,
        bool $skipMissing
    ): void {
        $this->skipMissing = $skipMissing;
        if ($logFile) {
            file_put_contents($logFile, '');
        }

        $this->compareFields = $fields ? explode(',', $fields) : [];

        try {
            $fromTimestamp = $this->getStartTimestamp($fromDate, '');
            $from = null !== $fromTimestamp
                ? date('Y-m-d H:i:s\Z', $fromTimestamp) : 'the beginning';

            $this->log
                ->logInfo('compareRecords', "Creating record list (from $from)");
            $params = [];
            if ($singleId) {
                $params['_id'] = $singleId;
            } else {
                if (null !== $fromTimestamp) {
                    $params['updated']
                        = ['$gte' => $this->db->getTimestamp($fromTimestamp)];
                }
                [$sourceOr, $sourceNor] = $this->createSourceFilter($sourceId);
                if ($sourceOr) {
                    $params['$or'] = $sourceOr;
                }
                if ($sourceNor) {
                    $params['$nor'] = $sourceNor;
                }
            }

            $trackingName = $this->db->getNewTrackingCollection();
            $this->log->logInfo(
                'updateRecords',
                "Tracking deduplicated records with collection $trackingName"
            );

            $total = $this->db->countRecords($params);
            $count = 0;
            $lastDisplayedCount = 0;
            $mergedComponents = 0;
            $deleted = 0;
            $prevId = '';
            $this->log->logInfo(
                'compareRecords',
                "Comparing $total individual records"
            );
            $pc = new PerformanceCounter();

            $handler = function ($record) use (
                $pc,
                &$mergedComponents,
                $logFile,
                &$count,
                &$deleted,
                &$lastDisplayedCount,
                $trackingName,
                &$prevId
            ) {
                if (in_array($record['source_id'], $this->nonIndexedSources)) {
                    return true;
                }

                if (isset($record['dedup_id'])) {
                    $id = $record['dedup_id'];
                    if (
                        $prevId !== $id
                        && $this->db->addIdToTrackingCollection($trackingName, $id)
                    ) {
                        $result = $this->processDedupRecord(
                            $id,
                            $record['source_id'],
                            false
                        );

                        foreach ($result['records'] as $record) {
                            ++$count;
                            $this->compareWithSolrRecord($record, $logFile);
                        }
                        $mergedComponents += $result['mergedComponents'];
                        $deleted += count($result['deleted']);
                    }
                } else {
                    $result = $this->processSingleRecord($record);
                    $mergedComponents += $result['mergedComponents'];
                    $deleted += count($result['deleted']);
                    foreach ($result['records'] as $record) {
                        ++$count;
                        $this->compareWithSolrRecord($record, $logFile);
                    }
                }
                if ($count + $deleted >= $lastDisplayedCount + 1000) {
                    $lastDisplayedCount = $count + $deleted;
                    $pc->add($count);
                    $avg = $pc->getSpeed();
                    $this->log->logInfo(
                        'compareRecords',
                        "$count normal, $deleted deleted and"
                            . " $mergedComponents included child records"
                            . " compared, $avg records/sec"
                    );
                }
            };

            $this->db->iterateRecords(
                $params,
                [],
                $handler
            );

            $this->db->dropTrackingCollection($trackingName);

            $this->log->logInfo(
                'compareRecords',
                "Total $count normal, $deleted deleted and"
                    . " $mergedComponents included child records compared"
            );
        } catch (\Exception $e) {
            $this->log->logFatal(
                'compareRecords',
                'Exception: ' . (string)$e
            );
        }
    }

    /**
     * Compare given record with the already one in Solr
     *
     * @param array  $record  Record
     * @param string $logFile Log file where results are written
     *
     * @throws \Exception
     * @return void
     */
    protected function compareWithSolrRecord($record, $logFile)
    {
        $ignoreFields = [
            'allfields', 'allfields_unstemmed', 'fulltext', 'fulltext_unstemmed',
            'spelling', 'spellingShingle', 'authorStr', 'author_facet',
            'publisherStr', 'publishDateSort', 'topic_browse', 'hierarchy_browse',
            'first_indexed', 'last_indexed', '_version_',
            'fullrecord', 'title_full_unstemmed', 'title_fullStr',
            'author_additionalStr',
        ];

        if (isset($this->config['Solr']['ignore_in_comparison'])) {
            $ignoreFields = [
                ...$ignoreFields,
                ...explode(',', $this->config['Solr']['ignore_in_comparison']),
            ];
        }

        if (!($url = $this->config['Solr']['search_url'])) {
            throw new \Exception('search_url not set in ini file Solr section');
        }

        $this->request = $this->initSolrRequest(\HTTP_Request2::METHOD_GET);
        $url .= '?q=id:"' . urlencode(addcslashes($record['id'], '"')) . '"&wt=json';
        $this->request->setUrl($url);

        $response = $this->request->send();
        if ($response->getStatus() != 200) {
            $this->log->logInfo(
                'compareWithSolrRecord',
                "Could not fetch record (url $url), status code "
                    . $response->getStatus()
            );
            return;
        }

        $solrResponse = json_decode($response->getBody(), true);
        $solrRecord = $solrResponse['response']['docs'][0]
            ?? [];

        if ($this->skipMissing && [] === $solrRecord) {
            return;
        }

        $differences = '';
        $allFields = array_unique(
            [...array_keys($record), ...array_keys($solrRecord)]
        );
        $allFields = $this->compareFields
            ? array_intersect($allFields, $this->compareFields)
            : array_diff($allFields, $ignoreFields);
        foreach ($allFields as $field) {
            if (
                !isset($solrRecord[$field])
                || !isset($record[$field])
                || $record[$field] != $solrRecord[$field]
            ) {
                $valueDiffs = '';

                $values = (array)($record[$field] ?? []);
                $solrValues = (array)($solrRecord[$field] ?? []);

                foreach ($solrValues as $solrValue) {
                    if (!in_array($solrValue, $values)) {
                        $valueDiffs .= "--- {$solrValue}⏎" . PHP_EOL;
                    }
                }
                foreach ($values as $value) {
                    if (!in_array($value, $solrValues)) {
                        $valueDiffs .= "+++ {$value}⏎" . PHP_EOL;
                    }
                }

                if ($valueDiffs) {
                    $differences .= "$field:" . PHP_EOL . $valueDiffs;
                }
            }
        }
        if ($differences) {
            $msg = "Record {$record['id']} would be changed: " . PHP_EOL
                . $differences . PHP_EOL;
            if (!$logFile) {
                $this->log->writeConsole($msg);
            } else {
                file_put_contents($logFile, $msg, FILE_APPEND);
            }
        }
    }
}

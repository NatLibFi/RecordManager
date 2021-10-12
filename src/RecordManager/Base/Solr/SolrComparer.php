<?php
/**
 * SolrComparer Class
 *
 * PHP version 7
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
     * Compare records with the Solr index
     *
     * @param string      $logFile  Log file to use for any record differences
     * @param string|null $fromDate Starting date for updates (if empty
     *                              string, all records are processed)
     * @param string      $sourceId Comma-separated list of source IDs to
     *                              update, or empty or * for all sources
     * @param string      $singleId Process only the record with the given ID
     *
     * @return void
     */
    public function compareRecords($logFile, $fromDate, $sourceId, $singleId)
    {
        // Install a signal handler so that we can exit cleanly if interrupted
        unset($this->terminate);
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, [$this, 'sigIntHandler']);
            pcntl_signal(SIGTERM, [$this, 'sigIntHandler']);
            $this->log
                ->logInfo('compareRecords', 'Interrupt handler set');
        } else {
            $this->log->logInfo(
                'compareRecords',
                'Could not set an interrupt handler -- pcntl not available'
            );
        }

        if ($logFile) {
            file_put_contents($logFile, '');
        }

        $fromTimestamp = null;
        try {
            // Only process merged records if any of the selected sources has
            // deduplication enabled
            $processDedupRecords = true;
            if ($sourceId) {
                $sources = explode(',', $sourceId);
                foreach ($sources as $source) {
                    if (strncmp($source, '-', 1) === 0
                        || '' === trim($source)
                    ) {
                        continue;
                    }
                    $processDedupRecords = false;
                    if (isset($this->settings[$source]['dedup'])
                        && $this->settings[$source]['dedup']
                    ) {
                        $processDedupRecords = true;
                        break;
                    }
                }
            }

            if ($processDedupRecords) {
                $count = 0;
                $lastDisplayedCount = 0;
                $mergedComponents = 0;
                $deleted = 0;
                $pc = new PerformanceCounter();
                $this->iterateMergedRecords(
                    $fromDate,
                    $sourceId,
                    $singleId,
                    '',
                    false,
                    function (string $dedupId) use ($sourceId,
                        &$mergedComponents, $logFile, &$deleted, &$count,
                        &$lastDisplayedCount, $pc
                    ) {
                        $result = $this->processDedupRecord(
                            $dedupId,
                            $sourceId,
                            false
                        );

                        foreach ($result['records'] as $record) {
                            ++$count;
                            $this->compareWithSolrRecord($record, $logFile);
                        }
                        $mergedComponents += $result['mergedComponents'];
                        $deleted += count($result['deleted']);
                        if ($count + $deleted >= $lastDisplayedCount + 1000) {
                            $lastDisplayedCount = $count + $deleted;
                            $pc->add($count);
                            $avg = $pc->getSpeed();
                            $this->log->logInfo(
                                'compareRecords',
                                "$count merged, $deleted deleted and"
                                    . " $mergedComponents included child records"
                                    . " compared, $avg records/sec"
                            );
                        }
                    }
                );
            }

            if (isset($this->terminate)) {
                $this->log->logInfo('compareRecords', 'Termination upon request');
                exit(1);
            }

            $fromTimestamp = $this->getStartTimestamp($fromDate, '');
            $from = null !== $fromTimestamp
                ? date('Y-m-d H:i:s\Z', $fromTimestamp) : 'the beginning';

            $this->log->logInfo(
                'compareRecords', "Creating individual record list (from $from)"
            );
            $params = [];
            if ($singleId) {
                $params['_id'] = $singleId;
                $params['dedup_id'] = ['$exists' => false];
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
                $params['dedup_id'] = ['$exists' => false];
            }
            $total = $this->db->countRecords($params);
            $count = 0;
            $lastDisplayedCount = 0;
            $mergedComponents = 0;
            $deleted = 0;
            $this->log->logInfo(
                'compareRecords',
                "Comparing $total individual records"
            );
            $pc = new PerformanceCounter();
            $this->db->iterateRecords(
                $params,
                [],
                function ($record) use ($pc, &$mergedComponents, $logFile,
                    &$count, &$deleted, &$lastDisplayedCount
                ) {
                    if (isset($this->terminate)) {
                        return false;
                    }
                    if (in_array($record['source_id'], $this->nonIndexedSources)) {
                        return true;
                    }

                    $result = $this->processSingleRecord($record);
                    $mergedComponents += $result['mergedComponents'];
                    $deleted += count($result['deleted']);
                    foreach ($result['records'] as $record) {
                        ++$count;
                        $this->compareWithSolrRecord($record, $logFile);
                    }
                    if ($count + $deleted >= $lastDisplayedCount + 1000) {
                        $lastDisplayedCount = $count + $deleted;
                        $pc->add($count);
                        $avg = $pc->getSpeed();
                        $this->log->logInfo(
                            'compareRecords',
                            "$count individual, $deleted deleted and"
                                . " $mergedComponents included child records"
                                . " compared, $avg records/sec"
                        );
                    }
                }
            );

            if (isset($this->terminate)) {
                $this->log->logInfo('compareRecords', 'Termination upon request');
                exit(1);
            }

            $this->log->logInfo(
                'compareRecords',
                "Total $count individual, $deleted deleted and"
                    . " $mergedComponents included child records compared"
            );
        } catch (\Exception $e) {
            $this->log->logFatal(
                'compareRecords',
                'Exception: ' . $e->getMessage() . ' at ' . $e->getFile() . ':'
                    . $e->getLine()
            );
        }
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, SIG_DFL);
            pcntl_signal(SIGTERM, SIG_DFL);
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
            'author_additionalStr'
        ];

        if (isset($this->config['Solr']['ignore_in_comparison'])) {
            $ignoreFields = array_merge(
                $ignoreFields,
                explode(',', $this->config['Solr']['ignore_in_comparison'])
            );
        }

        if (!isset($this->config['Solr']['search_url'])) {
            throw new \Exception('search_url not set in ini file Solr section');
        }

        $this->request = $this->initSolrRequest(\HTTP_Request2::METHOD_GET);
        $url = $this->config['Solr']['search_url'];
        $url .= '?q=id:"' . urlencode($record['id']) . '"&wt=json';
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

        $differences = '';
        $allFields = array_unique(
            array_merge(array_keys($record), array_keys($solrRecord))
        );
        $allFields = array_diff($allFields, $ignoreFields);
        foreach ($allFields as $field) {
            if (!isset($solrRecord[$field])
                || !isset($record[$field])
                || $record[$field] != $solrRecord[$field]
            ) {
                $valueDiffs = '';

                $values = (array)($record[$field] ?? []);
                $solrValues = (array)($solrRecord[$field] ?? []);

                foreach ($solrValues as $solrValue) {
                    if (!in_array($solrValue, $values)) {
                        $valueDiffs .= "--- $solrValue" . PHP_EOL;
                    }
                }
                foreach ($values as $value) {
                    if (!in_array($value, $solrValues)) {
                        $valueDiffs .= "+++ $value " . PHP_EOL;
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
                echo $msg;
            } else {
                file_put_contents($logFile, $msg, FILE_APPEND);
            }
        }
    }
}

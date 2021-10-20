<?php
/**
 * Check Dedup Records
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

use RecordManager\Base\Utils\PerformanceCounter;

/**
 * Check Dedup Records
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class CheckDedup extends AbstractBase
{
    /**
     * Verify consistency of dedup records links with actual records
     *
     * @param string $singleId Optional ID of a single record to check
     *
     * @return void
     */
    public function launch($singleId = '')
    {
        $this->logger
            ->logInfo('checkDedupRecords', 'Checking dedup record consistency');

        $params = [];
        if ($singleId) {
            $record = $this->db->getRecord($singleId);
            if (!$record) {
                $this->logger->logInfo(
                    'checkDedupRecords',
                    'No record found with the given ID'
                );
                return;
            }
            if (empty($record['dedup_id'])) {
                $this->logger->logInfo(
                    'checkDedupRecords',
                    "Record $singleId not deduplicated"
                );
                return;
            }
            $params['_id'] = $record['dedup_id'];
        }

        $this->logger->logInfo('checkDedupRecords', 'Checking dedup records');
        $dedupHandler = $this->dedupHandler;
        $count = 0;
        $fixed = 0;
        $pc = new PerformanceCounter();
        $this->db->iterateDedups(
            $params,
            ['projection' => ['_id' => 1]],
            function (array $dedupRecordId) use (
                $dedupHandler,
                &$count,
                &$fixed,
                $pc
            ) {
                // Avoid stale data by reading the record just before processing
                $dedupRecord = $this->db->getDedup($dedupRecordId['_id']);
                if (null === $dedupRecord) {
                    return true;
                }
                $results = $dedupHandler->checkDedupRecord($dedupRecord);
                if ($results) {
                    $fixed += count($results);
                    foreach ($results as $result) {
                        $this->logger->logInfo('checkDedupRecords', $result);
                    }
                }
                ++$count;
                if ($count % 1000 == 0) {
                    $pc->add($count);
                    $avg = $pc->getSpeed();
                    $this->logger->logInfo(
                        'checkDedupRecords',
                        "$count records checked with $fixed links fixed, "
                        . "$avg records/sec"
                    );
                }
            }
        );
        $this->logger->logInfo(
            'checkDedupRecords',
            "Completed dedup check with $count records checked, $fixed links fixed"
        );

        $this->logger->logInfo('checkDedupRecords', 'Checking record links');

        $params = [];
        if ($singleId) {
            $params['_id'] = $singleId;
        } else {
            $params['dedup_id'] = ['$exists' => true];
        }
        $count = 0;
        $fixed = 0;
        $pc = new PerformanceCounter();
        $this->db->iterateRecords(
            $params,
            ['projection' => ['_id' => 1]],
            function (array $recordId) use ($dedupHandler, &$count, &$fixed, $pc) {
                $record = $this->db->getRecord($recordId['_id']);
                $result = $dedupHandler->checkRecordLinks($record);
                if ($result) {
                    ++$fixed;
                    $this->logger->logInfo('checkDedupRecords', $result);
                }
                ++$count;
                if ($count % 1000 == 0) {
                    $pc->add($count);
                    $avg = $pc->getSpeed();
                    $this->logger->logInfo(
                        'checkDedupRecords',
                        "$count links checked with $fixed links fixed, "
                        . "$avg records/sec"
                    );
                }
            }
        );
        $this->logger->logInfo(
            'checkDedupRecords',
            "Completed link check with $count records checked, $fixed links fixed"
        );
    }
}

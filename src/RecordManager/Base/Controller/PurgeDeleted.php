<?php
/**
 * Purge deleted records
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
 * Purge deleted records
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class PurgeDeleted extends AbstractBase
{
    /**
     * Purge deleted records from the database
     *
     * @param int    $daysToKeep Days to keep
     * @param string $sourceId   Optional source ID
     *
     * @return void
     */
    public function launch($daysToKeep = 0, $sourceId = '')
    {
        // Process normal records
        $dateStr = '';
        $params = ['deleted' => true];
        if ($daysToKeep) {
            $date = strtotime("-$daysToKeep day");
            $dateStr = ' until ' . date('Y-m-d', $date);
            $params['updated'] = ['$lt' => $this->db->getTimestamp($date)];
        }
        if ($sourceId) {
            $params['source_id'] = $sourceId;
        }
        $this->logger->logInfo(
            'purgeDeletedRecords',
            "Creating record list$dateStr" . ($sourceId ? " for '$sourceId'" : '')
        );
        $total = $this->db->countRecords($params);
        $count = 0;

        $this->logger->logInfo('purgeDeletedRecords', "Purging $total records");
        $pc = new PerformanceCounter();

        do {
            // Fetch a set of records at a time since the remaining set will be
            // changing during this process.
            $records = $this->db->findRecords($params, ['limit' => 1000]);
            $more = false;
            foreach ($records as $record) {
                $more = true;
                $this->db->deleteRecord($record['_id']);
                ++$count;
                if ($count % 1000 == 0) {
                    $pc->add($count);
                    $avg = $pc->getSpeed();
                    $this->logger->logInfo(
                        'purgeDeletedRecords',
                        "$count records purged, $avg records/sec"
                    );
                }
            }
        } while ($more);

        $this->logger->logInfo(
            'purgeDeletedRecords',
            "Total $count records purged"
        );

        if ($sourceId) {
            $this->logger->logInfo(
                'purgeDeletedRecords',
                'Source specified -- skipping dedup records'
            );
            return;
        }

        // Process dedup records
        $params = ['deleted' => true];
        if ($daysToKeep) {
            $params['changed'] = ['$lt' => $this->db->getTimestamp($date)];
        }
        $this->logger->logInfo(
            'purgeDeletedRecords',
            "Creating dedup record list$dateStr"
        );
        $total = $this->db->countDedups($params);
        $count = 0;
        $this->logger
            ->logInfo('purgeDeletedRecords', "Purging $total dedup records");
        $pc = new PerformanceCounter();
        $this->db->iterateDedups(
            $params,
            [],
            function ($record) use (&$count, $pc) {
                $this->db->deleteDedup($record['_id']);
                ++$count;
                if ($count % 1000 == 0) {
                    $pc->add($count);
                    $avg = $pc->getSpeed();
                    $this->logger->logInfo(
                        'purgeDeletedRecords',
                        "$count dedup records purged, $avg records/sec"
                    );
                }
            }
        );
        $this->logger->logInfo(
            'purgeDeletedRecords',
            "Total $count dedup records purged"
        );
    }
}

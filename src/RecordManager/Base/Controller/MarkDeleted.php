<?php
/**
 * Mark Records Deleted
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
 * Mark Records Deleted
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class MarkDeleted extends AbstractBase
{
    /**
     * Mark deleted records of a single data source
     *
     * @param string $sourceId Source ID
     * @param string $singleId Mark deleted only a single record with the given ID
     *
     * @return void
     */
    public function launch($sourceId, $singleId)
    {
        if (empty($sourceId)) {
            $this->logger->logFatal('markDeleted', 'No source id provided');
            return;
        }

        $this->logger
            ->logInfo('markDeleted', "Creating record list for '$sourceId'");

        $params = ['deleted' => false, 'source_id' => $sourceId];
        if ($singleId) {
            $params['_id'] = $singleId;
        }
        $total = $this->db->countRecords($params);
        $count = 0;

        $this->logger->logInfo(
            'markDeleted', "Marking deleted $total records from '$sourceId'"
        );
        $pc = new PerformanceCounter();

        do {
            // Fetch a set of records at a time since the remaining set will be
            // changing during this process.
            $records = $this->db->findRecords($params, ['limit' => 1000]);
            $more = false;
            foreach ($records as $record) {
                $more = true;
                if (isset($record['dedup_id'])) {
                    $this->dedupHandler->removeFromDedupRecord(
                        $record['dedup_id'], $record['_id']
                    );
                    unset($record['dedup_id']);
                }
                $record['deleted'] = true;
                $record['updated'] = $this->db->getTimestamp();
                $this->db->saveRecord($record);

                ++$count;
                if ($count % 1000 == 0) {
                    $pc->add($count);
                    $avg = $pc->getSpeed();
                    $this->logger->logInfo(
                        'markDeleted',
                        "$count records marked deleted from '$sourceId', "
                        . "$avg records/sec"
                    );
                }
            }
        } while ($more);

        $this->logger->logInfo(
            'markDeleted',
            "Completed with $count records marked deleted from '$sourceId'"
        );

        if (!$singleId) {
            $this->logger->logInfo(
                'markDeleted',
                "Deleting last harvest date from data source '$sourceId'"
            );
            $this->db->deleteState("Last Harvest Date $sourceId");
        }

        $this->logger->logInfo('markDeleted', "Marking of $sourceId completed");
    }
}

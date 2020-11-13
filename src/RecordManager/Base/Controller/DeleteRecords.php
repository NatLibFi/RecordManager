<?php
/**
 * Delete Records
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
 * @link     https://github.com/KDK-Alli/RecordManager
 */
namespace RecordManager\Base\Controller;

use RecordManager\Base\Utils\PerformanceCounter;

/**
 * Delete Records
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class DeleteRecords extends AbstractBase
{
    /**
     * Delete records of a single data source from the database
     *
     * @param string  $sourceId Source ID
     * @param boolean $force    Force deletion even if dedup is enable for the source
     *
     * @return void
     */
    public function launch($sourceId, $force = false)
    {
        if (isset($this->dataSourceSettings[$sourceId])) {
            $settings = $this->dataSourceSettings[$sourceId];
            if (isset($settings['dedup']) && $settings['dedup']) {
                if ($force) {
                    $this->logger->logWarning(
                        'deleteRecords',
                        "Deduplication enabled for '$sourceId' but deletion forced "
                        . " - may lead to orphaned dedup records"
                    );
                } else {
                    $this->logger->logError(
                        'deleteRecords',
                        "Deduplication enabled for '$sourceId', aborting "
                        . "(use markdeleted instead)"
                    );
                    return;
                }
            }
        }

        $params = [];
        $params['source_id'] = $sourceId;
        $this->logger
            ->logInfo('deleteRecords', "Creating record list for '$sourceId'");

        $params = ['source_id' => $sourceId];
        $total = $this->db->countRecords($params);
        $count = 0;
        $this->logger->logInfo(
            'deleteRecords', "Deleting $total records from '$sourceId'"
        );
        $pc = new PerformanceCounter();
        $this->db->iterateRecords(
            $params,
            [],
            function ($record) use (&$count, $pc, $sourceId) {
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
                    $this->logger->logInfo(
                        'deleteRecords',
                        "$count records deleted from '$sourceId', $avg records/sec"
                    );
                }
            }
        );
        $this->logger->logInfo(
            'deleteRecords', "Completed with $count records deleted from '$sourceId'"
        );

        $this->logger->logInfo(
            'deleteRecords',
            "Deleting last harvest date from data source '$sourceId'"
        );
        $this->db->deleteState("Last Harvest Date $sourceId");
        $this->logger->logInfo('deleteRecords', "Deletion of $sourceId completed");
    }
}

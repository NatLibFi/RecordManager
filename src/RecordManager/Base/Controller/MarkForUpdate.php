<?php
/**
 * Mark Records For Solr Update
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
 * Mark Records For Solr Update
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class MarkForUpdate extends AbstractBase
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
            $this->logger->logFatal('markForUpdate', 'No source id provided');
            return;
        }

        $this->logger
            ->logInfo('markForUpdate', "Creating record list for '$sourceId'");

        $params = [
            'deleted' => false, 'update_needed' => false, 'source_id' => $sourceId
        ];
        if ($singleId) {
            $params['_id'] = $singleId;
        }
        $records = $this->db->findRecords($params);
        $total = $this->db->countRecords($params);
        $count = 0;

        $this->logger->logInfo(
            'markForUpdate', "Marking $total records for update from '$sourceId'"
        );
        $pc = new PerformanceCounter();
        foreach ($records as $record) {
            $this->db->updateRecord(
                $record['_id'],
                [
                    'updated' => $this->db->getTimestamp()
                ]
            );

            ++$count;
            if ($count % 1000 == 0) {
                $pc->add($count);
                $avg = $pc->getSpeed();
                $this->logger->logInfo(
                    'markForUpdate',
                    "$count records marked for update from '$sourceId', "
                    . "$avg records/sec"
                );
            }
        }
        $this->logger->logInfo(
            'markForUpdate',
            "Completed with $count records marked for update from '$sourceId'"
        );
    }
}

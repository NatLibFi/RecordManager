<?php
/**
 * Record unsuppression
 *
 * PHP version 7
 *
 * Copyright (C) The National Library of Finland 2020.
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
 * Record unsuppression
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class Unsuppress extends AbstractBase
{
    /**
     * Suppress records in a data source
     *
     * @param string $sourceId Source ID to process
     * @param string $singleId Process only a single record with the given ID
     *
     * @return void
     */
    public function launch($sourceId, $singleId)
    {
        $this->initSourceSettings();
        foreach ($this->dataSourceSettings as $source => $settings) {
            if ($sourceId && $sourceId != '*' && $source != $sourceId) {
                continue;
            }
            if (empty($source) || empty($settings)) {
                continue;
            }
            $this->logger
                ->logInfo('unsuppress', "Creating record list for '$source'");

            $params = [
                'deleted' => false,
                'suppressed' => true,
            ];
            if ($singleId) {
                $params['_id'] = $singleId;
                $params['source_id'] = $source;
            } else {
                $params['source_id'] = $source;
            }
            $records = $this->db->findRecords($params);
            $total = $this->db->countRecords($params);
            $count = 0;

            $this->logger->logInfo(
                'unsuppress', "Processing $total records from '$source'"
            );
            $pc = new PerformanceCounter();
            foreach ($records as $record) {
                $record['suppressed'] = false;
                if ($settings['dedup']) {
                    $record['update_needed'] = true;
                }
                $record['updated'] = $this->db->getTimestamp();
                $this->db->saveRecord($record);

                ++$count;
                if ($count % 1000 == 0) {
                    $pc->add($count);
                    $avg = $pc->getSpeed();
                    $this->logger->logInfo(
                        'unsuppress',
                        "$count records processed from '$source', $avg records/sec"
                    );
                }
            }
            $this->logger->logInfo(
                'unsuppress',
                "Completed with $count records processed from '$source'"
            );
        }
    }
}

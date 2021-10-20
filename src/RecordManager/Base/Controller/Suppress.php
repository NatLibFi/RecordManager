<?php
/**
 * Record suppression
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
 * @link     https://github.com/NatLibFi/RecordManager
 */
namespace RecordManager\Base\Controller;

use RecordManager\Base\Utils\PerformanceCounter;

/**
 * Record suppression
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class Suppress extends AbstractBase
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
                ->logInfo('suppress', "Creating record list for '$source'");

            $params = [
                'deleted' => false,
                'suppressed' => ['$in' => [null, false]],
            ];
            if ($singleId) {
                $params['_id'] = $singleId;
                $params['source_id'] = $source;
            } else {
                $params['source_id'] = $source;
            }
            $dedupHandler = $this->dedupHandler;
            $total = $this->db->countRecords($params);
            $count = 0;

            $this->logger->logInfo(
                'suppress',
                "Processing $total records from '$source'"
            );
            $pc = new PerformanceCounter();
            $this->db->iterateRecords(
                $params,
                [],
                function ($record) use (
                    $settings,
                    $dedupHandler,
                    &$count,
                    $pc,
                    $source
                ) {
                    $record['suppressed'] = true;
                    if ($settings['dedup'] && isset($record['dedup_id'])) {
                        $dedupHandler->removeFromDedupRecord(
                            $record['dedup_id'],
                            $record['_id']
                        );
                        unset($record['dedup_id']);
                    }
                    $record['update_needed'] = false;
                    $record['updated'] = $this->db->getTimestamp();
                    $this->db->saveRecord($record);

                    ++$count;
                    if ($count % 1000 == 0) {
                        $pc->add($count);
                        $avg = $pc->getSpeed();
                        $this->logger->logInfo(
                            'suppress',
                            "$count records processed from '$source'"
                                . ", $avg records/sec"
                        );
                    }
                }
            );
            $this->logger->logInfo(
                'suppress',
                "Completed with $count records processed from '$source'"
            );
        }
    }
}

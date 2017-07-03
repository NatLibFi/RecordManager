<?php
/**
 * Check Dedup Records
 *
 * PHP version 5
 *
 * Copyright (C) The National Library of Finland 2011-2017.
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
 * Check Dedup Records
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class CheckDedup extends AbstractBase
{
    /**
     * Verify consistency of dedup records links with actual records
     *
     * @return void
     */
    public function launch()
    {
        $this->logger->log('checkDedupRecords', 'Checking dedup record consistency');

        $dedupHandler = $this->getDedupHandler();

        $dedupRecords = $this->db->findDedups([]);
        $count = 0;
        $fixed = 0;
        $pc = new PerformanceCounter();
        foreach ($dedupRecords as $dedupRecord) {
            $results = $dedupHandler->checkDedupRecord($dedupRecord);
            if ($results) {
                $fixed += count($results);
                foreach ($results as $result) {
                    $this->logger->log('checkDedupRecords', $result);
                }
            }
            ++$count;
            if ($count % 1000 == 0) {
                $pc->add($count);
                $avg = $pc->getSpeed();
                $this->logger->log(
                    'checkDedupRecords',
                    "$count records checked with $fixed links fixed, "
                    . "$avg records/sec"
                );
            }
        }
        $this->logger->log(
            'checkDedupRecords',
            "Completed with $count records checked with $fixed links fixed"
        );
    }
}

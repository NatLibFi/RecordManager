<?php
/**
 * Solr Update
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

use RecordManager\Base\Solr\SolrUpdater;

/**
 * Solr Update
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class SolrUpdate extends AbstractBase
{
    /**
     * Send updates to the Solr index
     *
     * @param string|null $fromDate      Starting date for updates (if empty
     *                                   string, last update date stored in the
     *                                   database is used and if null, all records
     *                                   are processed)
     * @param string      $sourceId      Source ID to process, or empty or * for all
     *                                   sources (ignored if record merging is
     *                                   enabled)
     * @param string      $singleId      Process only a record with the given ID
     * @param bool        $noCommit      If true, changes are not explicitly
     *                                   committed
     * @param bool        $datePerServer Track last Solr update date per server url
     *
     * @return void
     */
    public function launch($fromDate = null, $sourceId = '', $singleId = '',
        $noCommit = false, $datePerServer = false
    ) {
        $updater = new SolrUpdater(
            $this->db, $this->basePath, $this->logger, $this->verbose, $this->config,
            $this->dataSourceSettings, $this->recordFactory
        );
        $updater->updateRecords(
            $fromDate, $sourceId, $singleId, $noCommit, false, false, '',
            $datePerServer
        );
    }
}

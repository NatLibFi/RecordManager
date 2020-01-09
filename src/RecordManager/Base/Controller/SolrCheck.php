<?php
/**
 * Solr Index Check
 *
 * PHP version 5
 *
 * Copyright (C) The National Library of Finland 2019.
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
 * Solr Index Check
 *
 * Checks the Solr index for orphaned records
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class SolrCheck extends AbstractBase
{
    /**
     * Check the Solr index
     *
     * @return void
     */
    public function launch()
    {
        $updater = new SolrUpdater(
            $this->db, $this->basePath, $this->logger, $this->verbose, $this->config,
            $this->dataSourceSettings, $this->recordFactory
        );

        $this->logger->log('SolrCheck', 'Checking Solr index');
        $updater->checkIndexedRecords();
        $this->logger->log('SolrCheck', 'Solr check completed');
    }
}

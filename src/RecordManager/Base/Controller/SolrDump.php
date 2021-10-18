<?php
/**
 * Solr Updater
 *
 * PHP version 7
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
 * @link     https://github.com/NatLibFi/RecordManager
 */
namespace RecordManager\Base\Controller;

/**
 * Solr Updater
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class SolrDump extends AbstractBase
{
    use ControllerWithSolrUpdaterTrait;

    /**
     * Dump Solr updates to files instead of sending them to Solr
     *
     * @param string      $dumpPrefix Prefix for the dump files.
     * @param string|null $fromDate   Starting date for processing (if empty
     *                                string, last update date stored in the database
     *                                is used and if null, all records are processed)
     * @param string      $sourceId   Source ID to process, or empty or * for all
     *                                sources (ignored if record merging is enabled)
     * @param string      $singleId   Process only a record with the given ID
     * @param bool        $mapValues  Whether to use mappings for the values
     *
     * @return void
     */
    public function launch($dumpPrefix, $fromDate = null, $sourceId = '',
        $singleId = '', $mapValues = true
    ) {
        if (!$mapValues) {
            $this->config['Solr']['field_mapper']
                = '\RecordManager\Base\Utils\NoOpFieldMapper';
        }
        $this->solrUpdater->updateRecords(
            $fromDate, $sourceId, $singleId, false, false, $dumpPrefix, false
        );
    }
}

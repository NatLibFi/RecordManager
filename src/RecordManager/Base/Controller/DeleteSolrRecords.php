<?php
/**
 * Delete Records from Solr
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

/**
 * Delete Records from Solr
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class DeleteSolrRecords extends AbstractBase
{
    use ControllerWithSolrUpdaterTrait;

    /**
     * Delete records of a single data source from the Solr index
     *
     * @param string $sourceId Source ID
     *
     * @return void
     */
    public function launch($sourceId)
    {
        if (!empty($this->config['Solr']['merge_records'])) {
            $this->logger->logInfo(
                'deleteSolrRecords',
                "Deleting data source '$sourceId' from merged records via Solr "
                    . 'update for merged records'
            );
            $this->solrUpdater->updateRecords('', $sourceId, '', false, true);
        }
        $this->logger->logInfo(
            'deleteSolrRecords',
            "Deleting data source '$sourceId' directly from Solr"
        );
        $this->solrUpdater->deleteDataSource($sourceId);
        $this->logger->logInfo(
            'deleteSolrRecords', "Deletion of '$sourceId' from Solr completed"
        );
    }
}

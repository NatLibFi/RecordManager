<?php
/**
 * Solr Optimization
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

use RecordManager\Base\Solr\SolrUpdater;

/**
 * Solr Optimization
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class SolrOptimize extends AbstractBase
{
    /**
     * Optimize the Solr index
     *
     * @return void
     */
    public function launch()
    {
        $updater = new SolrUpdater(
            $this->db, $this->basePath, $this->logger, $this->verbose, $this->config,
            $this->dataSourceSettings, $this->recordFactory
        );

        $this->logger->logInfo('optimizeSolr', 'Optimizing Solr index');
        $updater->optimizeIndex();
        $this->logger->logInfo('optimizeSolr', 'Solr optimization completed');
    }
}

<?php
/**
 * Solr Update
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

use RecordManager\Base\Database\DatabaseInterface;
use RecordManager\Base\Deduplication\DedupHandlerInterface;
use RecordManager\Base\Record\PluginManager as RecordPluginManager;
use RecordManager\Base\Solr\SolrUpdater;
use RecordManager\Base\Utils\Logger;

/**
 * Solr Update
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class SolrUpdate extends AbstractBase
{
    /**
     * Constructor
     *
     * @param array                 $config              Main configuration
     * @param array                 $datasourceConfig    Datasource configuration
     * @param Logger                $logger              Logger
     * @param DatabaseInterface     $database            Database
     * @param RecordPluginManager   $recordPluginManager Record plugin manager
     * @param DedupHandlerInterface $dedupHandler        Deduplication handler
     * @param SolrUpdater           $solrUpdater         Solr updater
     */
    public function __construct(
        array $config,
        array $datasourceConfig,
        Logger $logger,
        DatabaseInterface $database,
        RecordPluginManager $recordPluginManager,
        DedupHandlerInterface $dedupHandler,
        SolrUpdater $solrUpdater
    ) {
        parent::__construct(
            $config,
            $datasourceConfig,
            $logger,
            $database,
            $recordPluginManager,
            $dedupHandler
        );
        $this->solrUpdater = $solrUpdater;
    }

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
        $this->solrUpdater->updateRecords(
            $fromDate, $sourceId, $singleId, $noCommit, false, '', $datePerServer
        );
    }
}

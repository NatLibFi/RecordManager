<?php

/**
 * RecordManager controller base class
 *
 * PHP version 8
 *
 * Copyright (C) The National Library of Finland 2011-2021.
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
use RecordManager\Base\Splitter\PluginManager as SplitterPluginManager;
use RecordManager\Base\Utils\Logger;
use RecordManager\Base\Utils\MetadataUtils;

/**
 * RecordManager controller base class
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
abstract class AbstractBase
{
    use \RecordManager\Base\Record\CreateRecordTrait;

    /**
     * Main configuration
     *
     * @var array
     */
    protected $config;

    /**
     * Verbose mode
     *
     * @var bool
     */
    protected $verbose = false;

    /**
     * Logger
     *
     * @var Logger
     */
    protected $logger;

    /**
     * Database
     *
     * @var DatabaseInterface
     */
    protected $db;

    /**
     * Data source settings
     *
     * @var array
     */
    protected $dataSourceConfig;

    /**
     * Record plugin manager
     *
     * @var RecordPluginManager
     */
    protected $recordPluginManager;

    /**
     * Record splitter plugin manager
     *
     * @var SplitterPluginManager
     */
    protected $splitterPluginManager;

    /**
     * Deduplication handler
     *
     * @var DedupHandlerInterface
     */
    protected $dedupHandler;

    /**
     * Metadata utilities
     *
     * @var MetadataUtils
     */
    protected $metadataUtils;

    /**
     * Constructor
     *
     * @param array                 $config              Main configuration
     * @param array                 $datasourceConfig    Datasource configuration
     * @param Logger                $logger              Logger
     * @param DatabaseInterface     $database            Database
     * @param RecordPluginManager   $recordPluginManager Record plugin manager
     * @param SplitterPluginManager $splitterManager     Record splitter plugin
     *                                                   manager
     * @param DedupHandlerInterface $dedupHandler        Deduplication handler
     * @param MetadataUtils         $metadataUtils       Metadata utilities
     */
    public function __construct(
        array $config,
        array $datasourceConfig,
        Logger $logger,
        DatabaseInterface $database,
        RecordPluginManager $recordPluginManager,
        SplitterPluginManager $splitterManager,
        DedupHandlerInterface $dedupHandler,
        MetadataUtils $metadataUtils
    ) {
        if (isset($config['Site']['timezone'])) {
            date_default_timezone_set($config['Site']['timezone']);
        }

        $this->config = $config;
        $this->logger = $logger;
        $this->db = $database;
        $this->logger->setDatabase($this->db);
        $this->dataSourceConfig = $datasourceConfig;
        $this->recordPluginManager = $recordPluginManager;
        $this->splitterPluginManager = $splitterManager;
        $this->dedupHandler = $dedupHandler;
        $this->metadataUtils = $metadataUtils;
    }
}

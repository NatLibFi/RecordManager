<?php
/**
 * Record Manager controller base class
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

use RecordManager\Base\Database\Database;
use RecordManager\Base\Record\Factory as RecordFactory;
use RecordManager\Base\Utils\Logger;
use RecordManager\Base\Utils\MetadataUtils;
use RecordManager\Base\Utils\XslTransformation;

if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
} else {
    declare(ticks = 1);
}

/**
 * Record Manager controller base class
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
abstract class AbstractBase
{
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
     * Base path of Record Manager
     *
     * @var string
     */
    protected $basePath;

    /**
     * Logger
     *
     * @var Logger
     */
    protected $logger;

    /**
     * Database
     *
     * @var Database
     */
    protected $db;

    /**
     * Data source settings
     *
     * @var array
     */
    protected $dataSourceSettings;

    /**
     * Record factory
     *
     * @var RecordFactory
     */
    protected $recordFactory;

    /**
     * Constructor
     *
     * @param string $basePath Base directory
     * @param array  $config   Main configuration
     * @param bool   $console  Specify whether RecordManager is executed on the
     *                         console so that log output is also output to the
     *                         console
     * @param bool   $verbose  Whether verbose output is enabled
     */
    public function __construct($basePath, $config, $console = false,
        $verbose = false
    ) {
        $this->config = $config;

        date_default_timezone_set($config['Site']['timezone']);

        $this->logger = new Logger($config);
        if ($console) {
            $this->logger->logToConsole = true;
        }
        $this->verbose = $verbose;

        $this->basePath = $basePath;
        $this->dataSourceSettings = $config['dataSourceSettings']
            = $this->readDataSourceSettings("$basePath/conf/datasources.ini");

        try {
            $this->db = new Database(
                $config['Mongo']['url'],
                $config['Mongo']['database'],
                $config['Mongo']
            );
        } catch (\Exception $e) {
            $this->logger->log(
                'startup',
                'Failed to connect to MongoDB: ' . $e->getMessage(),
                Logger::FATAL
            );
            throw $e;
        }

        MetadataUtils::setLogger($this->logger);
        MetadataUtils::setConfig($config, $this->basePath);

        $this->recordFactory = new RecordFactory(
            $this->logger, $config, $this->dataSourceSettings
        );
    }

    /**
     * Initialize the data source settings and XSL transformations
     *
     * @throws Exception
     * @return void
     */
    protected function initSourceSettings()
    {
        foreach ($this->dataSourceSettings as $source => &$settings) {
            if (!isset($settings['institution'])) {
                $this->logger->log(
                    'initSourceSettings',
                    "institution not set for $source",
                    Logger::FATAL
                );
                throw new \Exception("Error: institution not set for $source\n");
            }
            if (!isset($settings['format'])) {
                $this->logger->log(
                    'initSourceSettings', "format not set for $source", Logger::FATAL
                );
                throw new \Exception("Error: format not set for $source\n");
            }
            if (empty($settings['idPrefix'])) {
                $settings['idPrefix'] = $source;
            }
            if (!isset($settings['recordXPath'])) {
                $settings['recordXPath'] = '//record';
            }
            if (!isset($settings['oaiIDXPath'])) {
                $settings['oaiIDXPath'] = '';
            }
            if (!isset($settings['dedup'])) {
                $settings['dedup'] = false;
            }
            if (empty($settings['componentParts'])) {
                $settings['componentParts'] = 'as_is';
            }
            if (!isset($settings['preTransformation'])) {
                $settings['preTransformation'] = '';
            }
            if (!isset($settings['indexMergedParts'])) {
                $settings['indexMergedParts'] = true;
            }
            if (!isset($settings['type'])) {
                $settings['type'] = '';
            }
            if (!isset($settings['non_inherited_fields'])) {
                $settings['non_inherited_fields'] = [];
            }
            if (!isset($settings['keepMissingHierarchyMembers'])) {
                $settings['keepMissingHierarchyMembers'] = false;
            }

            $params = [
                'source_id' => $source,
                'institution' => $settings['institution'],
                'format' => $settings['format'],
                'id_prefix' => $settings['idPrefix']
            ];
            $settings['normalizationXSLT'] = !empty($settings['normalization'])
                ? new XslTransformation(
                    $this->basePath . '/transformations',
                    $settings['normalization'],
                    $params
                ) : null;
            $settings['solrTransformationXSLT']
                = !empty($settings['solrTransformation'])
                ? new XslTransformation(
                    $this->basePath . '/transformations',
                    $settings['solrTransformation'],
                    $params
                ) : null;

            if (!empty($settings['recordSplitterClass'])) {
                if (!class_exists($settings['recordSplitterClass'])) {
                    throw new \Exception(
                        "Record splitter class '"
                        . $settings['recordSplitterClass']
                        . "' not found for source $source"
                    );
                }
                $settings['recordSplitter'] = $settings['recordSplitterClass'];
            } elseif (!empty($settings['recordSplitter'])) {
                $style = new \DOMDocument();
                $xslFile = $this->basePath . '/transformations/'
                    . $settings['recordSplitter'];
                if ($style->load($xslFile) === false) {
                    throw new \Exception(
                        "Could not load $xslFile for source $source"
                    );
                }
                $settings['recordSplitter'] = new \XSLTProcessor();
                $settings['recordSplitter']->importStylesheet($style);
            } else {
                $settings['recordSplitter'] = null;
            }
        }
    }

    /**
     * Create a dedup handler
     *
     * @return DedupHandler
     */
    protected function getDedupHandler()
    {
        $dedupClass = isset($this->config['Site']['dedup_handler'])
            ? $this->config['Site']['dedup_handler']
            : '\RecordManager\Base\Deduplication\DedupHandler';
        $dedupHandler = new $dedupClass(
            $this->db, $this->logger, $this->verbose, $this->basePath, $this->config,
            $this->dataSourceSettings, $this->recordFactory
        );
        return $dedupHandler;
    }

    /**
     * Read and initalize the data source settings
     *
     * @param string $filename Ini file
     *
     * @return array
     */
    protected function readDataSourceSettings($filename)
    {
        $settings = parse_ini_file($filename, true);
        if (false === $settings) {
            $error = error_get_last();
            $message = $error['message'] ?? 'unknown error occurred';
            throw new \Exception(
                "Could not load data source settings from file '$filename': $message"
            );
        }

        // Check for linked data sources and store information to the linked sources
        // too
        foreach ($settings as $sourceId => $sourceSettings) {
            if (!empty($sourceSettings['componentPartSourceId'])) {
                foreach ($sourceSettings['componentPartSourceId'] as $linked) {
                    if (!isset($settings[$linked]['__hostRecordSourceId'])) {
                        $settings[$linked]['__hostRecordSourceId'] = [$linked];
                    }
                    $settings[$linked]['__hostRecordSourceId'][] = $sourceId;
                }
            }
        }

        return $settings;
    }
}

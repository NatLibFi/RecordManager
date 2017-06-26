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
use RecordManager\Base\Utils\Logger;
use RecordManager\Base\Utils\MetadataUtils;

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
     * Constructor
     *
     * @param boolean $console Specify whether RecordManager is executed on the
     * console so that log output is also output to the console.
     * @param boolean $verbose Whether verbose output is enabled
     */
    public function __construct($console = false, $verbose = false)
    {
        global $configArray;
        global $logger;

        date_default_timezone_set($configArray['Site']['timezone']);

        $this->logger = new Logger();
        if ($console) {
            $this->logger->logToConsole = true;
        }
        $this->verbose = $verbose;

        // Store logger in a global for legacy access in other classes
        $logger = $this->logger;

        $basePath = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR
            . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..';
        $basePath = realpath($basePath);
        $this->dataSourceSettings = $configArray['dataSourceSettings']
            = parse_ini_file("$basePath/conf/datasources.ini", true);
        $this->basePath = $basePath;

        try {
            $this->db = new Database(
                $configArray['Mongo']['url'],
                $configArray['Mongo']['database'],
                $configArray['Mongo']
            );
        } catch (\Exception $e) {
            $this->log->log(
                'startup',
                'Failed to connect to MongoDB: ' . $e->getMessage(),
                Logger::FATAL
            );
            throw $e;
        }

        if (isset($configArray['Site']['full_title_prefixes'])) {
            MetadataUtils::$fullTitlePrefixes = array_map(
                ['\RecordManager\Base\Utils\MetadataUtils', 'normalize'],
                file(
                    "$basePath/conf/{$configArray['Site']['full_title_prefixes']}",
                    FILE_IGNORE_NEW_LINES
                )
            );
        }

        // Read the abbreviations file
        MetadataUtils::$abbreviations = isset($configArray['Site']['abbreviations'])
            ? $this->readListFile($configArray['Site']['abbreviations']) : [];

        // Read the artices file
        MetadataUtils::$articles = isset($configArray['Site']['articles'])
            ? $this->readListFile($configArray['Site']['articles']) : [];
    }

    /**
     * Run the workload
     *
     * @return void
     */
    abstract public function launch();

    /**
     * Read a list file into an array
     *
     * @param string $filename List file name
     *
     * @return array
     */
    protected function readListFile($filename)
    {
        global $basePath;

        $filename = "$basePath/conf/$filename";
        $lines = file($filename, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            $this->logger->log(
                'readListFile', "Could not open list file '$filename'", Logger::ERROR
            );
            return [];
        }
        array_walk(
            $lines,
            function (&$value) {
                $value = trim($value, "'");
            }
        );

        return $lines;
    }
}

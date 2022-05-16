<?php
/**
 * Harvesting Base Class
 *
 * PHP version 7
 *
 * Copyright (c) The National Library of Finland 2011-2021.
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
namespace RecordManager\Base\Harvest;

use RecordManager\Base\Database\DatabaseInterface as Database;
use RecordManager\Base\Http\ClientManager as HttpClientManager;
use RecordManager\Base\Utils\Logger;
use RecordManager\Base\Utils\MetadataUtils;

/**
 * Harvesting Base Class
 *
 * This class provides a basic structure for harvesting classes.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
abstract class AbstractBase
{
    /**
     * Database
     *
     * @var Database
     */
    protected $db;

    /**
     * Logger
     *
     * @var Logger
     */
    protected $log;

    /**
     * HTTP client manager
     *
     * @var HttpClientManager
     */
    protected $httpClientManager;

    /**
     * Metadata utilities
     *
     * @var MetadataUtils
     */
    protected $metadataUtils;

    /**
     * Main configuration
     *
     * @var array
     */
    protected $config;

    /**
     * Data source configuration
     *
     * @var array
     */
    protected $dataSourceConfig;

    /**
     * Base URL of repository
     *
     * @var string
     */
    protected $baseURL = null;

    /**
     * Source ID
     *
     * @var string
     */
    protected $source = null;

    /**
     * Harvest start date (null for all records)
     *
     * @var ?string
     */
    protected $startDate = null;

    /**
     * Harvest end date (null for all records)
     *
     * @var string
     */
    protected $endDate = null;

    /**
     * Whether to display debug output
     *
     * @var bool
     */
    protected $verbose = false;

    /**
     * Whether running a reharvest
     *
     * This allows e.g. bypassing deleted records during a reharvest run.
     *
     * @var bool
     */
    protected $reharvest = false;

    /**
     * Changed record count
     *
     * @var int
     */
    protected $changedRecords = 0;

    /**
     * Deleted record count
     *
     * @var int
     */
    protected $deletedRecords = 0;

    /**
     * Unchanged record count
     *
     * @var int
     */
    protected $unchangedRecords = 0;

    /**
     * Transformations applied to the responses before processing
     *
     * @var \XSLTProcessor[]
     */
    protected $preXslt = [];

    /**
     * Whether to always re-parse transformation results e.g. to include any new
     * nodes added by outputting non-encoded text.
     *
     * @var bool
     */
    protected $reParseTransformed = false;

    /**
     * Record handling callback
     *
     * @see StoreRecordTrait::storeRecord
     *
     * @var callable
     */
    protected $callback = null;

    /**
     * Most recent record date encountered during harvesting
     *
     * @var string
     */
    protected $trackedEndDate = '';

    /**
     * Number of times to attempt a request before bailing out
     *
     * @var int
     */
    protected $maxTries = 5;

    /**
     * Seconds to wait between request attempts
     *
     * @var int
     */
    protected $retryWait = 30;

    /**
     * Constructor.
     *
     * @param array             $config           Main configuration
     * @param array             $dataSourceConfig Data source configuration
     * @param Database          $db               Database
     * @param Logger            $logger           The Logger object used for logging
     *                                            messages
     * @param HttpClientManager $httpManager      HTTP client manager
     * @param MetadataUtils     $metadataUtils    Metadata utilities
     *
     * @throws \Exception
     */
    public function __construct(
        array $config,
        array $dataSourceConfig,
        Database $db,
        Logger $logger,
        HttpClientManager $httpManager,
        MetadataUtils $metadataUtils
    ) {
        $this->config = $config;
        $this->dataSourceConfig = $dataSourceConfig;
        $this->db = $db;
        $this->log = $logger;
        $this->httpClientManager = $httpManager;
        $this->metadataUtils = $metadataUtils;
    }

    /**
     * Initialize harvesting
     *
     * @param string $source    Source ID
     * @param bool   $verbose   Verbose mode toggle
     * @param bool   $reharvest Whether running a reharvest
     *
     * @return void
     */
    public function init(string $source, bool $verbose, bool $reharvest): void
    {
        $this->verbose = $verbose;
        $this->reharvest = $reharvest;

        // Check if we have a start date
        $this->source = $source;
        $this->loadLastHarvestedDate();

        $settings = $this->dataSourceConfig[$source] ?? [];

        // Set up base URL:
        if (empty($settings['url'])) {
            throw new \Exception('Missing base URL');
        }
        $this->baseURL = $settings['url'];

        if (!empty($settings['preTransformation'])) {
            foreach ((array)$settings['preTransformation'] as $transformation) {
                $style = new \DOMDocument();
                $style->load(RECMAN_BASE_PATH . "/transformations/$transformation");
                $xslt = new \XSLTProcessor();
                $xslt->importStylesheet($style);
                $xslt->setParameter('', 'source_id', $this->source);
                $this->preXslt[] = $xslt;
            }
        } else {
            $this->preXslt = [];
        }
        $this->reParseTransformed = !empty($settings['reParseTransformed']);

        $this->maxTries = $this->config['Harvesting']['max_tries'] ?? 5;
        $this->retryWait = $this->config['Harvesting']['retry_wait'] ?? 30;
    }

    /**
     * Harvest all available documents.
     *
     * @param callable $callback Function to be called to store a harvested record
     *
     * @return void
     */
    abstract public function harvest($callback);

    /**
     * Return the number of changed records
     *
     * @return int
     */
    public function getChangedRecordCount()
    {
        return $this->changedRecords;
    }

    /**
     * Return the number of deleted records
     *
     * @return int
     */
    public function getDeletedRecordCount()
    {
        return $this->deletedRecords;
    }

    /**
     * Return the number of unchanged records
     *
     * @return int
     */
    public function getUnchangedRecordCount()
    {
        return $this->unchangedRecords;
    }

    /**
     * Return total number of harvested records
     *
     * @return int
     */
    public function getHarvestedRecordCount()
    {
        return $this->changedRecords + $this->deletedRecords
            + $this->unchangedRecords;
    }

    /**
     * Set a start date for the harvest (only harvest records AFTER this date).
     *
     * @param string $date Start date (YYYY-MM-DD format).
     *
     * @return void
     */
    public function setStartDate($date)
    {
        $this->startDate = $date;
    }

    /**
     * Set an end date for the harvest (only harvest records BEFORE this date).
     *
     * @param string $date End date (YYYY-MM-DD format).
     *
     * @return void
     */
    public function setEndDate($date)
    {
        $this->endDate = $date;
    }

    /**
     * Initialize settings for harvesting.
     *
     * @param callable $callback Function to be called to store a harvested record
     *
     * @return void
     */
    protected function initHarvest($callback)
    {
        $this->callback = $callback;
        $this->changedRecords = 0;
        $this->unchangedRecords = 0;
        $this->deletedRecords = 0;
    }

    /**
     * Retrieve the date from the database and use it as our start
     * date if it is available.
     *
     * @return void
     */
    protected function loadLastHarvestedDate()
    {
        $state = $this->db->getState("Last Harvest Date {$this->source}");
        if (null !== $state) {
            $this->setStartDate($state['value']);
        }
    }

    /**
     * Save the tracked date as the last harvested date.
     *
     * @param string $date Date to save.
     *
     * @return void
     */
    protected function saveLastHarvestedDate($date)
    {
        $state = ['_id' => "Last Harvest Date {$this->source}", 'value' => $date];
        // Reset database connection since it could have timed out during the
        // process:
        $this->db->resetConnection();
        $this->db->saveState($state);
    }

    /**
     * Check if the record is modified.
     * This implementation works for MARC records.
     *
     * @param \SimpleXMLElement $record Record
     *
     * @return bool
     */
    protected function isModified($record)
    {
        $status = substr($record->leader, 5, 1);
        return $status != 'd';
    }

    /**
     * Extract record ID.
     * This implementation works for MARC records.
     *
     * @param \SimpleXMLElement $record Record
     *
     * @return string|false ID if found, false if record is missing ID
     * @throws \Exception
     */
    protected function extractID($record)
    {
        foreach ($record->controlfield as $field) {
            if ($field->attributes()->tag == '001') {
                return (string)$field;
            }
        }
        return false;
    }

    /**
     * Create an OAI style ID
     *
     * @param string $sourceId Source ID
     * @param string $id       Record ID
     *
     * @return string OAI ID
     */
    protected function createOaiId($sourceId, $id)
    {
        return get_class($this) . ":$sourceId:$id";
    }

    /**
     * Do transformation
     *
     * Always returns a string to make sure any elements added as unescaped strings
     * are properly parsed.
     *
     * @param string $xml       XML to transform
     * @param bool   $returnDoc Whether to return DOM document instead of string
     *
     * @return string|\DOMDocument Transformed XML
     * @throws \Exception
     */
    protected function transform($xml, $returnDoc = false)
    {
        $doc = $this->transformToDoc($xml);
        return $returnDoc ? $doc : $doc->saveXML();
    }

    /**
     * Do transformation to DOM Document
     *
     * Always returns a string to make sure any elements added as unescaped strings
     * are properly parsed.
     *
     * @param string $xml XML to transform
     *
     * @return \DOMDocument Transformed XML
     * @throws \Exception
     */
    protected function transformToDoc(string $xml): \DOMDocument
    {
        $doc = new \DOMDocument();
        $result = $this->metadataUtils->loadXML($xml, $doc, 0, $errors);
        if ($result === false || $errors) {
            $this->warningMsg('Invalid XML received, trying encoding fix...');
            $xml = iconv('UTF-8', 'UTF-8//IGNORE', $xml);
            // Replace any control characters not allowed in XML 1.0:
            $xml = preg_replace('/[\x01-\x08,\x0B,\x0C,\x0E-\x1F]/', ' ', $xml);
            $result = $this->metadataUtils->loadXML($xml, $doc, 0, $errors);
        }
        if ($result === false || $errors) {
            $this->fatalMsg("Could not parse XML: $errors");
            throw new \Exception('Failed to parse XML');
        }

        if ($this->reParseTransformed) {
            foreach ($this->preXslt as $xslt) {
                $xml = $xslt->transformToXml($doc);
                $doc = new \DOMDocument();
                $result = $this->metadataUtils->loadXML($xml, $doc, 0, $errors);
            }
            if ($result === false || $errors) {
                $this->fatalMsg("Could not parse XML: $errors");
                throw new \Exception('Failed to parse XML');
            }
        } else {
            foreach ($this->preXslt as $xslt) {
                $doc = $xslt->transformToDoc($doc);
            }
        }

        return $doc;
    }

    /**
     * Report the results of harvesting
     *
     * @return void
     */
    protected function reportResults()
    {
        $this->infoMsg(
            'Harvested ' . $this->changedRecords . ' updated, '
            . $this->unchangedRecords . ' unchanged and '
            . $this->deletedRecords . ' deleted records'
        );
    }

    /**
     * Format log message
     *
     * @param string $msg Log message
     *
     * @return string
     */
    protected function formatLogMessage($msg)
    {
        return "[{$this->source}] $msg";
    }

    /**
     * Get class name for logging
     *
     * @return string
     */
    protected function getLogClass()
    {
        $classParts = explode('\\', get_class($this));
        $class = end($classParts);

        return $class;
    }

    /**
     * Log a message and display on console in verbose mode.
     *
     * @param string $msg Message
     *
     * @return void
     */
    protected function infoMsg($msg)
    {
        $msg = $this->formatLogMessage($msg);
        $this->log->logInfo($this->getLogClass(), $msg);
    }

    /**
     * Log an error and display on console in verbose mode.
     *
     * @param string $msg Message
     *
     * @return void
     */
    protected function errorMsg($msg)
    {
        $msg = $this->formatLogMessage($msg);
        $this->log->logError($this->getLogClass(), $msg);
    }

    /**
     * Log a warning and display on console in verbose mode.
     *
     * @param string $msg Message
     *
     * @return void
     */
    protected function warningMsg($msg)
    {
        $msg = $this->formatLogMessage($msg);
        $this->log->logWarning($this->getLogClass(), $msg);
    }

    /**
     * Log a fatal error and display on console in verbose mode.
     *
     * @param string $msg Message
     *
     * @return void
     */
    protected function fatalMsg($msg)
    {
        $msg = $this->formatLogMessage($msg);
        $this->log->logFatal($this->getLogClass(), $msg);
    }

    /**
     * Get file name for a temporary file
     *
     * @param string $prefix File name prefix
     * @param string $suffix File name suffix
     *
     * @return string
     * @throws \Exception
     */
    protected function getTempFileName($prefix, $suffix)
    {
        $tmpDir = !empty($this->config['Site']['temp_dir'])
            ? $this->config['Site']['temp_dir'] : sys_get_temp_dir();

        $attempt = 1;
        do {
            $tmpName = $tmpDir . DIRECTORY_SEPARATOR . $prefix . getmypid()
                . mt_rand() . $suffix;
            $fp = @fopen($tmpName, 'x');
        } while (!$fp && ++$attempt < 100);
        if (!$fp) {
            throw new \Exception("Could not create temp file $tmpName");
        }
        fclose($fp);
        return $tmpName;
    }
}

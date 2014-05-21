<?php
/**
 * HTTP-based File Harvesting Class
 *
 * PHP version 5
 *
 * Copyright (c) The National Library of Finland 2011-2014.
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

require_once 'HTTP/Request2.php';

/**
 * HarvestHTTPFiles Class
 *
 * This class harvests files via HTTP using settings from datasources.ini.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class HarvestHTTPFiles
{
    protected $log;                   // Logger
    protected $db;                    // Mongo database
    protected $baseURL;               // URL to harvest from
    protected $filePrefix = '';       // File name prefix
    protected $fileSuffix = '';       // File name prefix
    protected $source;                // Source ID
    protected $startDate = null;      // Harvest start date (null for all records)
    protected $endDate = null;        // Harvest end date (null for all records)
    protected $verbose = false;       // Whether to display debug output
    protected $preXSLT = false;       // Pre-transformation XSLT
    protected $changedRecords = 0;    // Harvested changed record count
    protected $deletedRecords = 0;    // Harvested deleted record count
    protected $unchangedRecords = 0;  // Harvested unchanged record count
    protected $recordElem = 'record'; // Element to look for in retrieved XML

    // As we harvest records, we want to track the most recent date encountered
    // so we can set a start point for the next harvest.
    protected $trackedEndDate = '';

    /**
     * Constructor.
     *
     * @param object $logger   The Logger object used for logging messages.
     * @param object $db       Mongo database handle.
     * @param string $source   The data source to be harvested.
     * @param string $basePath RecordManager main directory location
     * @param array  $settings Settings from datasources.ini.
     *
     * @access public
     */
    public function __construct($logger, $db, $source, $basePath, $settings)
    {
        $this->log = $logger;
        $this->db = $db;

        // Check if we have a start date
        $this->source = $source;
        $this->loadLastHarvestedDate();

        // Set up base URL:
        if (empty($settings['url'])) {
            throw new Exception("Missing base URL for {$source}");
        }
        $this->baseURL = $settings['url'];
        if (isset($settings['filePrefix'])) {
            $this->filePrefix = $settings['filePrefix'];
        }
        if (isset($settings['fileSuffix'])) {
            $this->fileSuffix = $settings['fileSuffix'];
        }
        if (isset($settings['verbose'])) {
            $this->verbose = $settings['verbose'];
        }

        if (isset($settings['preTransformation'])) {
            $style = new DOMDocument();
            $style->load($basePath . '/transformations/' . $settings['preTransformation']);
            $this->preXSLT = new XSLTProcessor();
            $this->preXSLT->importStylesheet($style);
            $this->preXSLT->setParameter('', 'source_id', $this->source);
        }
    }

    public function getChangedRecordCount()
    {
        return $this->changedRecords;
    }

    public function getDeletedRecordCount()
    {
        return $this->deletedRecords;
    }

    public function getUnchangedRecordCount()
    {
        return $this->unchangedRecords;
    }

    /**
     * Set a start date for the harvest (only harvest records AFTER this date).
     *
     * @param string $date Start date (YYYY-MM-DD format).
     *
     * @return void
     * @access public
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
     * @access public
     */
    public function setEndDate($date)
    {
        $this->endDate = $date;
    }

    /**
     * Harvest all available documents.
     *
     * @param functionref $callback Function to be called to store a harvested record
     *
     * @return void
     * @access public
     */
    public function harvest($callback)
    {
        $this->callback = $callback;

        if (isset($this->startDate)) {
            $this->message('Incremental harvest from timestamp ' . $this->startDate);
        } else {
            $this->message('Initial harvest for all records');
        }
        $fileList = $this->retrieveFileList();
        $this->message('Files to harvest: ' . count($fileList));
        foreach ($fileList as $file) {
            $data = $this->retrieveFile($file);

            $this->message('Processing the records...', true);

            if ($this->preXSLT) {
                $data = $this->preTransform($data);
            }

            $xml = new XMLReader();
            $saveUseErrors = libxml_use_internal_errors(true);
            libxml_clear_errors();
            $result = $xml->XML($data);
            if ($result === false || libxml_get_last_error() !== false) {
                // Assuming it's a character encoding issue, this might help...
                $this->message('Invalid XML received, trying encoding fix...', false, Logger::WARNING);
                $data = iconv('UTF-8', 'UTF-8//IGNORE', $data);
                libxml_clear_errors();
                $result = $xml->XML($data);
            }
            if ($result === false || libxml_get_last_error() !== false) {
                libxml_use_internal_errors($saveUseErrors);
                $errors = '';
                foreach (libxml_get_errors() as $error) {
                    if ($errors) {
                        $errors .= '; ';
                    }
                    $errors .= 'Error ' . $error->code . ' at ' . $error->line . ':' . $error->column . ': ' . $error->message;
                }
                $this->message("Could not parse XML response: $errors\n", false, Logger::FATAL);
                throw new Exception("Failed to parse XML response");
            }
            libxml_use_internal_errors($saveUseErrors);

            $this->changedRecords = 0;
            $this->unchangedRecords = 0;
            $this->deletedRecords = 0;

            $this->processRecords($xml);

            $this->message('Harvested ' . $this->changedRecords . ' updated, ' . $this->unchangedRecords . ' unchanged and ' . $this->deletedRecords . ' deleted records');
        }
        if ($this->trackedEndDate > 0) {
            $this->saveLastHarvestedDate();
        }
    }

    /**
     * Retrieve the date from the database and use it as our start
     * date if it is available.
     *
     * @return void
     * @access protected
     */
    protected function loadLastHarvestedDate()
    {
        $state = $this->db->state->findOne(array('_id' => "Last Harvest Date {$this->source}"));
        if (isset($state)) {
            $this->setStartDate($state['value']);
        }
    }

    /**
     * Save the tracked date as the last harvested date.
     *
     * @return void
     * @access protected
     */
    protected function saveLastHarvestedDate()
    {
        $state = array('_id' => "Last Harvest Date {$this->source}", 'value' => $this->trackedEndDate);
        $this->db->state->save($state);
    }

    /**
     * Retrieve list of files to be harvested, filter by date
     *
     * @throws Exception
     * @return string[]
     */
    protected function retrieveFileList()
    {
        $request = new HTTP_Request2(
            $this->baseURL,
            HTTP_Request2::METHOD_GET,
            array('ssl_verify_peer' => false)
        );
        $request->setHeader('User-Agent', 'RecordManager');

        $url = $request->getURL();
        $urlStr = $url->getURL();
        $this->message("Sending request: $urlStr", true);

        // Perform request and throw an exception on error:
        for ($try = 1; $try <= 5; $try++) {
            try {
                $response = $request->send();
            } catch (Exception $e) {
                if ($try < 5) {
                    $this->message("Request '$urlStr' failed (" . $e->getMessage() . "), retrying in 30 seconds...", false, Logger::WARNING);
                    sleep(30);
                    continue;
                }
                throw $e;
            }
            if ($try < 5) {
                $code = $response->getStatus();
                if ($code >= 300) {
                    $this->message("Request '$urlStr' failed ($code), retrying in 30 seconds...", false, Logger::WARNING);
                    sleep(30);
                    continue;
                }
            }
            break;
        }
        $code = $response->getStatus();
        if ($code >= 300) {
            $this->message("Request '$urlStr' failed: $code", false, Logger::FATAL);
            throw new Exception("Request failed: $code");
        }

        $responseStr = $response->getBody();

        $matches = array();
        preg_match_all("/href=\"({$this->filePrefix}.*?{$this->fileSuffix})\"/", $responseStr, $matches, PREG_SET_ORDER);
        $files = array();
        foreach ($matches as $match) {
            $filename = $match[1];
            $date = $this->getFileDate($filename, $responseStr);
            if ($date === false) {
                $this->message("Invalid filename date in '$filename'", false, Logger::WARNING);
                continue;
            }
            if ($date > $this->startDate && (!$this->endDate || $date <= $this->endDate)) {
                $files[] = $filename;
                if (!$this->trackedEndDate || $this->trackedEndDate < $date) {
                    $this->trackedEndDate = $date;
                }
            }
        }
        return $files;
    }

    /**
     * Fetch a file to be harvested
     *
     * @param string $filename File to retrieve
     *
     * @return string xml
     * @throws Exception
     */
    protected function retrieveFile($filename)
    {
        $request = new HTTP_Request2(
            $this->baseURL . $filename,
            HTTP_Request2::METHOD_GET,
            array('ssl_verify_peer' => false)
        );
        $request->setHeader('User-Agent', 'RecordManager');

        $url = $request->getURL();
        $urlStr = $url->getURL();
        $this->message("Sending request: $urlStr", true);

        // Perform request and throw an exception on error:
        for ($try = 1; $try <= 5; $try++) {
            try {
                $response = $request->send();
            } catch (Exception $e) {
                if ($try < 5) {
                    $this->message("Request '$urlStr' failed (" . $e->getMessage() . "), retrying in 30 seconds...", false, Logger::WARNING);
                    sleep(30);
                    continue;
                }
                throw $e;
            }
            if ($try < 5) {
                $code = $response->getStatus();
                if ($code >= 300) {
                    $this->message("Request '$urlStr' failed ($code), retrying in 30 seconds...", false, Logger::WARNING);
                    sleep(30);
                    continue;
                }
            }
            break;
        }
        $code = $response->getStatus();
        if ($code >= 300) {
            $this->message("Request '$urlStr' failed: $code", false, Logger::FATAL);
            throw new Exception("Request failed: $code");
        }

        return $response->getBody();
    }

    /**
     * Process the records xml
     *
     * @param XMLReader &$xml XML File of records
     *
     * @return void
     */
    protected function processRecords(&$xml)
    {
        while ($xml->read() && $xml->name !== $this->recordElem);
        $count = 0;
        $doc = new DOMDocument;
        while ($xml->name == $this->recordElem) {
            $this->processRecord(simplexml_import_dom($doc->importNode($xml->expand(), true)));
            if (++$count % 1000 == 0) {
                $this->message("$count records processed", true);
            }
            $xml->next($this->recordElem);
        }
    }

    /**
     * Save a harvested record.
     *
     * @param SimpleXMLElement $record Record
     *
     * @return void
     * @access protected
     */
    protected function processRecord($record)
    {
        $id = $this->extractID($record);
        $oaiId = $this->createOaiId($this->source, $id);
        if ($this->isDeleted($record)) {
            call_user_func($this->callback, $oaiId, true, null);
            $this->deletedRecords++;
        } elseif ($this->isModified($record)) {
            $this->normalizeRecord($record, $id);
            $this->changedRecords += call_user_func($this->callback, $oaiId, false, $record->asXML());
        } else {
            // This assumes the provider may return records that are not changed or
            // deleted.
            $this->unchangedRecords++;
        }
    }

    /**
     * Check if the record is deleted.
     * This implementation works for MARC records.
     *
     * @param SimpleXMLElement $record Record
     *
     * @return bool
     */
    protected function isDeleted($record)
    {
        $status = substr($record->leader, 5, 1);
        return $status == 'd';
    }

    /**
     * Check if the record is modified.
     * This implementation works for MARC records.
     *
     * @param SimpleXMLElement $record Record
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
     * @param SimpleXMLElement $record Record
     *
     * @return string ID
     * @throws Exception
     */
    protected function extractID($record)
    {
        $nodes = $record->xpath("controlfield[@tag='001']");
        if (empty($nodes)) {
            throw new Exception("[{$this->source}] No ID found in harvested record");
        }
        return trim((string)$nodes[0]);
    }

    /**
     * Extract file date from the file name or directory list response data
     *
     * @param string $filename    File name
     * @param string $responseStr Full HTTP directory listing response
     *
     * @return string|false Date in ISO8601 format or false if date could not be
     * determined
     */
    protected function getFileDate($filename, $responseStr)
    {
        if (!preg_match('/(\d{4})(\d\d)(\d\d)(\d\d)(\d\d)(\d\d)/', $filename, $dateparts)) {
            return false;
        }
        $date = $dateparts[1] . '-' . $dateparts[2] . '-' . $dateparts[3] . 'T' .
                $dateparts[4] . ':' . $dateparts[5] . ':' . $dateparts[6];
        return $date;
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
        return get_class() . ":$sourceId:$id";
    }

    /**
     * Normalize a record
     *
     * @param SimpleXMLElement &$record Record
     * @param string           $id      Record ID
     *
     * @return void
     */
    protected function normalizeRecord(&$record, $id)
    {
    }

    /**
     * Do pre-transformation
     *
     * @param string $xml XML to transform
     *
     * @return string Transformed XML
     */
    protected function preTransform($xml)
    {
        $doc = new DOMDocument();
        $saveUseErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $result = $doc->loadXML($xml, LIBXML_PARSEHUGE);
        if ($result === false || libxml_get_last_error() !== false) {
            $this->message('Invalid XML received, trying encoding fix...', false, Logger::WARNING);
            $xml = iconv('UTF-8', 'UTF-8//IGNORE', $xml);
            libxml_clear_errors();
            $result = $doc->loadXML($xml, LIBXML_PARSEHUGE);
        }
        if ($result === false || libxml_get_last_error() !== false) {
            libxml_use_internal_errors($saveUseErrors);
            $errors = '';
            foreach (libxml_get_errors() as $error) {
                if ($errors) {
                    $errors .= '; ';
                }
                $errors .= 'Error ' . $error->code . ' at ' . $error->line . ':' . $error->column . ': ' . $error->message;
            }
            $this->message("Could not parse XML: $errors\n", false, Logger::FATAL);
            throw new Exception("Failed to parse XML");
        }
        libxml_use_internal_errors($saveUseErrors);

        return $this->preXSLT->transformToXml($doc);
    }

    /**
     * Log a message and display on console in verbose mode.
     *
     * @param string $msg     Message
     * @param bool   $verbose Flag telling whether this is considered verbose output
     * @param level  $level   Logging level
     *
     * @return void
     * @access protected
     */
    protected function message($msg, $verbose = false, $level = Logger::INFO)
    {
        $msg = "[{$this->source}] $msg";
        if ($this->verbose) {
            echo "$msg\n";
        }
        $this->log->log(get_class($this), $msg, $level);
    }
}


<?php
/**
 * SFX Export File Harvesting Class
 *
 * Based on harvest-oai.php in VuFind
 *
 * PHP version 5
 *
 * Copyright (c) Demian Katz 2010.
 * Copyright (c) Ere Maijala 2011-2012.
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
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */

require_once 'HTTP/Request2.php';

/**
 * HarvestSfx Class
 *
 * This class harvests SFX export files via HTTP using settings from datasources.ini.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class HarvestSfx
{
    protected $log;                   // Logger
    protected $db;                    // Mongo database
    protected $baseURL;               // URL to harvest from
    protected $filePrefix = '';       // File name prefix
    protected $source;                // Source ID
    protected $startDate = null;      // Harvest start date (null for all records)
    protected $endDate = null;      // Harvest end date (null for all records)
    protected $verbose = false;       // Whether to display debug output
    protected $normalRecords = 0;     // Harvested normal record count
    protected $deletedRecords = 0;    // Harvested deleted record count
    protected $childPid = null;       // Child process id for record processing
    
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
        if (isset($settings['verbose'])) {
            $this->verbose = $settings['verbose'];
        }
        
        $style = new DOMDocument();
        if ($style->load($basePath . '/transformations/strip_namespaces.xsl') === false) {
            throw new Exception('Could not load ' . $basePath . '/transformations/strip_namespaces.xsl');
        }
        $this->transformation = new XSLTProcessor();
        $this->transformation->importStylesheet($style);
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
    public function launch($callback)
    {
        $this->normalRecords = 0;
        $this->deletedRecords = 0;
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
            $xml = $this->parseResponse($data);
            $this->processRecords($xml->record);
            $this->message('Harvested ' . $this->normalRecords . ' normal records and ' . $this->deletedRecords . ' deleted records');
        }
        if (isset($this->childPid)) {
            pcntl_waitpid($this->childPid, $status);
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
        preg_match_all("/href=\"({$this->filePrefix}.*?)\"/", $responseStr, $matches, PREG_SET_ORDER);
        $files = array();
        foreach ($matches as $match) {
            $filename = $match[1];
            if (!preg_match('/(\d{4})(\d\d)(\d\d)(\d\d)(\d\d)(\d\d)/', $filename, $dateparts)) {
                echo "Invalid filename date\n";
                continue;
            }
            $date = $dateparts[1] . '-' . $dateparts[2] . '-' . $dateparts[3] . 'T' . 
                    $dateparts[4] . ':' . $dateparts[5] . ':' . $dateparts[6];
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
     * Process a response into a SimpleXML object. Throw exception if an error is
     * detected.
     *
     * @param string $xml Export XML.
     *
     * @return object     SimpleXML-formatted response.
     * @access protected
     */
    protected function parseResponse($xml)
    {
        // Parse the XML:
        $saveUseErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();
        
        $xml = str_replace('<collection xmlns="http://www.loc.gov/MARC21/slim">', '<collection>', $xml);
        $result = simplexml_load_string($xml);
        if ($result === false || libxml_get_last_error() !== false) {
            // Assuming it's a character encoding issue, this might help...
            $this->message('Invalid XML received, trying encoding fix...', false, Logger::WARNING);
            $xml = iconv('UTF-8', 'UTF-8//IGNORE', $xml);
            libxml_clear_errors();
            $result = $this->loadXML($xml);
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
            $this->message("Could not parse XML response: $errors\nXML:\n$xml", false, Logger::FATAL);
            throw new Exception("Failed to parse XML response");
        }
        libxml_use_internal_errors($saveUseErrors);

        // If we got this far, we have a valid response:
        return $result;
    }

    /**
     * Save harvested records.
     *
     * @param object $records SimpleXML records.
     *
     * @return void
     * @access protected
     */
    protected function processRecords($records)
    {
        $this->message('Processing ' . count($records) . ' records...', true);

        $count = 0;
        foreach ($records as $record) {
            $id = $this->extractID($record);
            if ($this->isDeleted($record)) {
                call_user_func($this->callback, $id, true, null);
                $this->deletedRecords++;
            } else {
                $record->addChild('controlfield', $id)->addAttribute('tag', '001');
                $this->normalRecords += call_user_func($this->callback, "sfx:{$this->source}:$id", false, $record->asXML());
            }
            if (++$count % 1000 == 0) {
                $this->message("$count records processed", true);
            }
        }
        $this->message('All records processed', true);
    }

    /**
     * Check if the record is deleted
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
     * Extract record ID
     * 
     * @param SimpleXMLElement $record Record
     * 
     * @return string ID 
     * @throws Exception
     */
    protected function extractID($record)
    {
        $nodes = $record->xpath("datafield[@tag='090']/subfield[@code='a']");
        if (empty($nodes)) {
            throw new Exception('No ID found in harvested record');
        }
        return trim((string)$nodes[0]);
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
        if ($this->verbose) {
            echo "$msg\n";
        }
        $this->log->log('harvestSfx', $msg, $level);
    }
}


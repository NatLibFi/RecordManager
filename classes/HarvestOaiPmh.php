<?php
/**
 * OAI-PMH Harvesting Class
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
 */

require_once 'HTTP/Request2.php';

/**
 * HarvestOaiPmh Class
 *
 * This class harvests records via OAI-PMH using settings from datasources.ini.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 */
class HarvestOaiPmh
{
    protected $_log;                   // Logger
    protected $_db;                    // Mongo database
    protected $_baseURL;               // URL to harvest from
    protected $_set = null;            // Set to harvest (null for all records)
    protected $_metadata = 'oai_dc';   // Metadata type to harvest
    protected $_idPrefix = '';         // OAI prefix to strip from ID values
    protected $_idSearch = array();    // Regular expression searches
    protected $_idReplace = array();   // Replacements for regular expression matches
    protected $_source;                // Source ID
    protected $_startDate = null;      // Harvest start date (null for all records)
    protected $_endDate = null; 		 // Harvest end date (null for all records)
    protected $_granularity = 'auto';  // Date granularity
    protected $_injectId = false;      // Tag to use for injecting IDs into XML
    protected $_injectSetSpec = false; // Tag to use for injecting setSpecs
    protected $_injectSetName = false; // Tag to use for injecting set names
    protected $_injectDate = false;    // Tag to use for injecting datestamp
    protected $_setNames = array();    // Associative array of setSpec => setName
    protected $_harvestedIdLog = false;// Filename for logging harvested IDs.
    protected $_verbose = false;       // Whether to display debug output
    protected $_normalRecords = 0;     // Harvested normal record count
    protected $_deletedRecords = 0;    // Harvested deleted record count
    protected $_debugLog = '';         // File where to dump OAI requests and responses for debugging
    protected $_childPid = null;       // Child process id for record processing
    protected $_resumptionToken = '';  // Override the first harvest request
    protected $_transformation = null; // Transformation applied to the OAI-PMH responses before processing
    
    // As we harvest records, we want to track the most recent date encountered
    // so we can set a start point for the next harvest.
    protected $_trackedEndDate = 0;

    /**
     * Constructor.
     *
     * @param object $logger   The Logger object used for logging messages.
     * @param object $db       Mongo database handle.
     * @param string $source   The data source to be harvested.
     * @param string $basePath RecordManager main directory location 
     * @param array  $settings Settings from datasources.ini.
     * @param string $startResumptionToken Optional override for the initial
     *                         harvest command (to resume interrupted harvesting)
     *
     * @access public
     */
    public function __construct($logger, $db, $source, $basePath, $settings, $startResumptionToken = '')
    {
        $this->_log = $logger;
        $this->_db = $db;
         
        // Don't time out during harvest
        set_time_limit(0);

        // Check if we have a start date
        $this->_source = $source;
        $this->_loadLastHarvestedDate();

       	$this->_resumptionToken = $startResumptionToken;

        // Set up base URL:
        if (empty($settings['url'])) {
            throw new Exception("Missing base URL for {$source}");
        }
        $this->_baseURL = $settings['url'];
        if (isset($settings['set'])) {
            $this->_set = $settings['set'];
        }
        if (isset($settings['metadataPrefix'])) {
            $this->_metadata = $settings['metadataPrefix'];
        }
        if (isset($settings['idPrefix'])) {
            $this->_idPrefix = $settings['idPrefix'];
        }
        if (isset($settings['idSearch'])) {
            $this->_idSearch = $settings['idSearch'];
        }
        if (isset($settings['idReplace'])) {
            $this->_idReplace = $settings['idReplace'];
        }
        if (isset($settings['harvestedIdLog'])) {
            $this->_harvestedIdLog = $settings['harvestedIdLog'];
        }
        if (isset($settings['injectId'])) {
            $this->_injectId = $settings['injectId'];
        }
        if (isset($settings['injectSetSpec'])) {
            $this->_injectSetSpec = $settings['injectSetSpec'];
        }
        if (isset($settings['injectSetName'])) {
            $this->_injectSetName = $settings['injectSetName'];
            $this->_loadSetNames();
        }
        if (isset($settings['injectDate'])) {
            $this->_injectDate = $settings['injectDate'];
        }
        if (isset($settings['dateGranularity'])) {
            $this->_granularity = $settings['dateGranularity'];
        }
        if (isset($settings['verbose'])) {
            $this->_verbose = $settings['verbose'];
        }
        if ($this->_granularity == 'auto') {
            $this->_loadGranularity();
        }
        if (isset($settings['debuglog'])) {
            $this->_debugLog = $settings['debuglog'];
        }
        if (isset($settings['oaipmhTransformation'])) {
            $style = new DOMDocument();
            if ($style->load("$basePath/transformations/". $settings['oaipmhTransformation']) === false) {
                throw new Exception("Could not load $basePath/transformations/" . $settings['oaipmhTransformation']);
            }
            $this->_transformation = new XSLTProcessor();
            $this->_transformation->importStylesheet($style);
        }
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
        $this->_startDate = $date;
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
        $this->_endDate = $date;
    }

    /**
     * Harvest all available documents.
     *
     * @param  function reference $callback  Function to be called to store a harvested record
     * @return void
     * @access public
     */
    public function harvest($callback)
    {
        $this->_normalRecords = 0;
        $this->_deletedRecords = 0;
        $this->_callback = $callback;

        if ($this->_resumptionToken) {
            $this->_message('Incremental harvest from given resumptionToken');
            $token = $this->_getRecordsByToken($this->_resumptionToken);
        } else {
            // Start harvesting at the requested date:
            if (!empty($this->_startDate)) {
                $this->_message('Incremental harvest from timestamp ' . $this->_startDate);
            } else {
                $this->_message('Initial harvest for all records');
            }
            $token = $this->_getRecordsByDate();
        }

        // Keep harvesting as long as a resumption token is provided:
        while ($token !== false) {
            $this->_harvestProgressReport();
            $token = $this->_getRecordsByToken($token);
        }
        $this->_harvestProgressReport();
        if (isset($this->_childPid)) {
            pcntl_waitpid($this->_childPid, $status);
        }
    }

    /**
     * List identifiers of all available documents.
     *
     * @param  function reference $callback  Function to be called to process an identifier
     * @return void
     * @access public
     */
    public function listIdentifiers($callback)
    {
        $this->_normalRecords = 0;
        $this->_deletedRecords = 0;
        $this->_callback = $callback;
    
        if ($this->_resumptionToken) {
            $this->_message('Incremental listing from given resumptionToken');
            $token = $this->_getIdentifiersByToken($this->_resumptionToken);
        } else {
            $this->_message('Listing all identifiers');
            $token = $this->_getIdentifiers();
        }
    
        // Keep harvesting as long as a resumption token is provided:
        while ($token !== false) {
            $this->_listIdentifiersProgressReport();
            $token = $this->_getIdentifiersByToken($token);
        }
        $this->_listIdentifiersProgressReport();
        if (isset($this->_childPid)) {
            pcntl_waitpid($this->_childPid, $status);
        }
    }
    
    
    protected function _harvestProgressReport()
    {
        $this->_message(
            'Harvested ' . $this->_normalRecords
            . ' normal records and ' . $this->_deletedRecords . ' deleted records'
        );
    }

    public function _listIdentifiersProgressReport()
    {
        $this->_message(
            'Listed ' . $this->_normalRecords
            . ' normal records and ' . $this->_deletedRecords . ' deleted records'
        );
    }

    /**
     * Retrieve the date from the database and use it as our start
     * date if it is available.
     *
     * @return void
     * @access protected
     */
    protected function _loadLastHarvestedDate()
    {
        $state = $this->_db->state->findOne(array('_id' => "Last Harvest Date {$this->_source}"));
        if (isset($state)) {
            $this->setStartDate($state['value']);
        }
    }

    /**
     * Normalize a date to a Unix timestamp.
     *
     * @param string $date Date (ISO-8601 or YYYY-MM-DD HH:MM:SS)
     *
     * @return integer     Unix timestamp (or false if $date invalid)
     * @access protected
     */
    protected function normalizeDate($date)
    {
        // Remove timezone markers -- we don't want PHP to outsmart us by adjusting
        // the time zone!
        $date = str_replace(array('T', 'Z'), array(' ', ''), $date);

        // Translate to a timestamp:
        return strtotime($date);
    }

    /**
     * Save the last harvested date.
     *
     * @param string $date Date to save.
     *
     * @return void
     * @access protected
     */
    protected function _saveLastHarvestedDate($date)
    {
        $state = array('_id' => "Last Harvest Date {$this->_source}", 'value' => $date);
        $this->_db->state->save($state);
    }

    /**
     * Make an OAI-PMH request.  Throw an exception if there is an error; return a SimpleXML object
     * on success.
     *
     * @param string $verb   OAI-PMH verb to execute.
     * @param array  $params GET parameters for ListRecords method.
     *
     * @return object        SimpleXML-formatted response.
     * @access protected
     */
    protected function _sendRequest($verb, $params = array())
    {
        // Set up the request:
        $request = new HTTP_Request2(
            $this->_baseURL, 
            HTTP_Request2::METHOD_GET, 
            array('ssl_verify_peer' => false)
        );       
        $request->setHeader('User-Agent', 'RecordManager');

        // Load request parameters:
        $url = $request->getURL();
        $params['verb'] = $verb;
        $url->setQueryVariables($params);
        
        $urlStr = $url->getURL();
        $this->_message("Sending request: $urlStr", true);
        if ($this->_debugLog) {
            file_put_contents($this->_debugLog, "Request:\n$urlStr\n", FILE_APPEND);
        }

        // Perform request and throw an exception on error:
        for ($try = 1; $try <= 5; $try++) {
            try {
                $response = $request->send();
            } catch (Exception $e) {
                if ($try < 5) {
                    $this->_message(
                        "Request '$urlStr' failed (" . $e->getMessage() . "), retrying in 30 seconds...", 
                        false, 
                        Logger::WARNING
                    );
                    sleep(30);
                    continue;
                }
                throw $e;
            }
            if ($try < 5) {
                $code = $response->getStatus();
                if ($code >= 300) {
                    $this->_message(
                        "Request '$urlStr' failed ($code), retrying in 30 seconds...",
                        false,
                        Logger::WARNING
                    );
                    sleep(30);
                    continue;
                }
            }
            break;
        }
        $code = $response->getStatus();
        if ($code >= 300) {
            $this->_message("Request '$urlStr' failed: $code", false, Logger::FATAL);
            throw new Exception("Request failed: $code");
        }

        // If we got this far, there was no error -- send back response.
        $responseStr = $response->getBody();
        if ($this->_debugLog) {
            file_put_contents($this->_debugLog, "Response:\n$responseStr\n\n", FILE_APPEND);
        }
        return $this->_processResponse($responseStr);
    }

    /**
     *
     * Load XML into simplexml
     * @param string $xml
     * 
     * @return SimpleXMLElement
     * @access protected
     */
    protected function _loadXML($xml)
    {
        if ($this->_transformation) {
            $doc = new DOMDocument();
            $doc->loadXML($xml);
            return simplexml_import_dom($this->_transformation->transformToDoc($doc));
        }
        return simplexml_load_string($xml);
    }

    /**
     * Process an OAI-PMH response into a SimpleXML object. Throw exception if an error is
     * detected.
     *
     * @param string $xml OAI-PMH response XML.
     *
     * @return object     SimpleXML-formatted response.
     * @access protected
     */
    protected function _processResponse($xml)
    {
        // Parse the XML:
        $saveUseErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $result = $this->_loadXML($xml);
        if ($result === false || libxml_get_last_error() !== false) {
            // Assuming it's a character encoding issue, this might help...
            $this->_message('Invalid XML received, trying encoding fix...', false, Logger::WARNING);
            $xml = iconv('UTF-8', 'UTF-8//IGNORE', $xml);
            libxml_clear_errors();
            $result = $this->_loadXML($xml);
        }
        if ($result === false || libxml_get_last_error() !== false) {
            libxml_use_internal_errors($saveUseErrors);
            $errors = '';
            foreach (libxml_get_errors() as $error) {
                if ($errors) {
                    $errors .= '; ';
                }
                $errors .= 'Error ' . $error->code . ' at '
                    . $error->line . ':' . $error->column . ': ' . $error->message;
            }
            $this->_message("Could not parse XML response: $errors\nXML:\n$xml", false, Logger::FATAL);
            throw new Exception("Failed to parse XML response");
        }
        libxml_use_internal_errors($saveUseErrors);

        // Detect errors and throw an exception if one is found:
        if ($result->error) {
            $attribs = $result->error->attributes();
            if ($attribs['code'] != 'noRecordsMatch') {
                $this->_message(
                    "OAI-PMH server returned error {$attribs['code']} ({$result->error})", 
                    false,
                    Logger::FATAL
                );
                throw new Exception(
                    "OAI-PMH error -- code: {$attribs['code']}, " .
                    "value: {$result->error}\n"
                );
            }
        }

        // If we got this far, we have a valid response:
        return $result;
    }

    /**
     * Load date granularity from the server.
     *
     * @return void
     * @access protected
     */
    protected function _loadGranularity()
    {
        $this->_message('Autodetecting date granularity...');
        $response = $this->_sendRequest('Identify');
        // @codingStandardsIgnoreStart
        $this->_granularity = (string)$response->Identify->granularity;
        // @codingStandardsIgnoreEnd
        $this->_message("Date granularity: {$this->_granularity}");
    }

    /**
     * Load set list from the server.
     *
     * @return void
     * @access protected
     */
    protected function _loadSetNames()
    {
        $this->_message('Loading set list... ');

        // On the first pass through the following loop, we want to get the
        // first page of sets without using a resumption token:
        $params = array();

        // Grab set information until we have it all (at which point we will
        // break out of this otherwise-infinite loop):
        while (true) {
            // Process current page of results:
            $response = $this->_sendRequest('ListSets', $params);
            if (isset($response->ListSets->set)) {
                foreach ($response->ListSets->set as $current) {
                    $spec = (string)$current->setSpec;
                    $name = (string)$current->setName;
                    if (!empty($spec)) {
                        $this->_setNames[$spec] = $name;
                    }
                }
            }

            // Is there a resumption token?  If so, continue looping; if not,
            // we're done!
            if (isset($response->ListSets->resumptionToken)
                && !empty($response->ListSets->resumptionToken)
            ) {
                $params['resumptionToken']
                = (string)$response->ListSets->resumptionToken;
            } else {
                $this->_message('Found ' . count($this->_setNames) . ' sets');
                return;
            }
        }
    }

    /**
     * Extract the ID from a record object (support method for _processRecords()).
     *
     * @param object $header SimpleXML record header.
     *
     * @return string        The ID value.
     * @access protected
     */
    protected function _extractID($header)
    {
        // Normalize to string:
        $id = (string)$header->identifier;

        // Strip prefix if found:
        if (substr($id, 0, strlen($this->_idPrefix)) == $this->_idPrefix) {
            $id = substr($id, strlen($this->_idPrefix));
        }

        // Apply regular expression matching:
        if (!empty($this->_idSearch)) {
            $id = preg_replace($this->_idSearch, $this->_idReplace, $id);
        }

        // Return final value:
        return $id;
    }

    /**
     * Save harvested records and track the end date.
     *
     * @param object $records SimpleXML records.
     *
     * @return void
     * @access protected
     */
    protected function _processRecords($records)
    {
        $this->_message('Processing ' . count($records) . ' records...', true);

        // Array for tracking successfully harvested IDs:
        $harvestedIds = array();

        // Loop through the records:
        foreach ($records as $record) {
            // Bypass the record if the record is missing its header:
            if (empty($record->header)) {
                $this->_message("Record header missing", false, Logger::ERROR);
                continue;
            }

            // Get the ID of the current record:
            $id = $this->_extractID($record->header);

            // Save the current record, either as a deleted or as a regular record:
            $attribs = $record->header->attributes();
            if (strtolower($attribs['status']) == 'deleted') {
                call_user_func($this->_callback, $id, true, null);
                $this->_deletedRecords++;
            } else {
                $recordNode = $record->metadata->children();
                if (empty($recordNode)) {
                    $this->_message("No metadata found for record $id", false, Logger::ERROR);
                    continue;
                }
                $harvestedIds[] = $id;
                $this->_normalRecords += call_user_func($this->_callback, $id, false, trim($recordNode[0]->asXML()));
            }

            // If the current record's date is newer than the previous end date,
            // remember it for future reference:
            $date = $this->normalizeDate($record->header->datestamp);
            if ($date && $date > $this->_trackedEndDate) {
                $this->_trackedEndDate = $date;
            }
        }
        // Do we have IDs to log and a log filename?  If so, log them:
        if (!empty($this->_harvestedIdLog) && !empty($harvestedIds)) {
            $file = fopen($this->_basePath . $this->_harvestedIdLog, 'a');
            if ($file === false) {
                $this->_message("Could not open {$this->_harvestedIdLog}", false, Logger::FATAL);
                throw new Exception("Problem opening {$this->_harvestedIdLog}");
            }
            fputs($file, implode("\n", $harvestedIds));
            fclose($file);
        }
    }

    /**
     * Harvest records using OAI-PMH.
     *
     * @param array $params GET parameters for ListRecords method.
     *
     * @return mixed        Resumption token if provided, false if finished
     * @access protected
     */
    protected function _getRecords($params)
    {
        // Make the OAI-PMH request:
        $response = $this->_sendRequest('ListRecords', $params);

        // Save the records from the response:
        if ($response->ListRecords->record) {
            $this->_processRecords($response->ListRecords->record);
        }

        // If we have a resumption token, keep going; otherwise, we're done -- save
        // the end date.
        if (isset($response->ListRecords->resumptionToken)
            && !empty($response->ListRecords->resumptionToken)
        ) {
            return $response->ListRecords->resumptionToken;
        } else if ($this->_trackedEndDate > 0) {
            $dateFormat = ($this->_granularity == 'YYYY-MM-DD') ?
                'Y-m-d' : 'Y-m-d\TH:i:s\Z';
            $this->_saveLastHarvestedDate(date($dateFormat, $this->_trackedEndDate));
        }
        return false;
    }

    /**
     * Harvest records via OAI-PMH using date and set.
     *
     * @return mixed        Resumption token if provided, false if finished
     * @access protected
     */
    protected function _getRecordsByDate($date = null, $set = null)
    {
        $params = array('metadataPrefix' => $this->_metadata);
        if (!empty($this->_startDate)) {
            $params['from'] = $this->_startDate;
        }
        if (!empty($this->_endDate)) {
            $params['until'] = $this->_endDate;
        }
        if (!empty($this->_set)) {
            $params['set'] = $this->_set;
        }
        return $this->_getRecords($params);
    }

    /**
     * Harvest records via OAI-PMH using resumption token.
     *
     * @param string $token Resumption token.
     *
     * @return mixed        Resumption token if provided, false if finished
     * @access protected
     */
    protected function _getRecordsByToken($token)
    {
        return $this->_getRecords(array('resumptionToken' => (string)$token));
    }

    /**
     * Get identifiers using OAI-PMH.
     *
     * @param array $params GET parameters for ListIdentifiers method.
     *
     * @return mixed        Resumption token if provided, false if finished
     * @access protected
     */
    protected function _getIdentifiers($params = array())
    {
        // Make the OAI-PMH request:
        if (empty($params)) {
            $params = array('metadataPrefix' => $this->_metadata);
        }
        $response = $this->_sendRequest('ListIdentifiers', $params);

        // Process headers
        if ($response->ListIdentifiers) {
            $this->_processIdentifiers($response->ListIdentifiers->header);
        }

        // If we have a resumption token, keep going; otherwise, we're done -- save
        // the end date.
        if (isset($response->ListIdentifiers->resumptionToken)
            && !empty($response->ListIdentifiers->resumptionToken)
        ) {
            return $response->ListIdentifiers->resumptionToken;
        } 
        return false;
    }

    /**
     * Get identifiers via OAI-PMH using resumption token.
     *
     * @param string $token Resumption token.
     *
     * @return mixed        Resumption token if provided, false if finished
     * @access protected
     */
    protected function _getIdentifiersByToken($token)
    {
        return $this->_getIdentifiers(array('resumptionToken' => (string)$token));
    }

    /**
     * Process fetched identifiers.
     *
     * @param object $records SimpleXML records.
     *
     * @return void
     * @access protected
     */
    protected function _processIdentifiers($headers)
    {
        $this->_message('Processing ' . count($headers) . ' identifiers...', true);
    
        // Loop through the records:
        foreach ($headers as $header) {
            // Get the ID of the current record:
            $id = $this->_extractID($header);
    
            // Process the current header, either as a deleted or as a regular record:
            $attribs = $header->attributes();
            if (strtolower($attribs['status']) == 'deleted') {
                call_user_func($this->_callback, $id, true);
                $this->_deletedRecords++;
            } else {
                call_user_func($this->_callback, $id, false);
                $this->_normalRecords++;
            }
        }
    }

    /**
     * Log a message and display on console in verbose mode.
     *
     * @param string $msg Message.
     * @param bool   $verbose Flag telling whether this is considered verbose output
     *
     * @return void
     * @access protected
     */
    protected function _message($msg, $verbose = false, $level = Logger::INFO)
    {
        if ($this->_verbose) {
            echo "$msg\n";
        }
        $this->_log->log('harvestOaiPmh', $msg, $level);
    }
}


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

require 'HTTP/Request.php';

/**
 * HarvestOaiPmh Class
 *
 * This class harvests records via OAI-PMH using settings from oai.ini.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 */
class HarvestOaiPmh
{
    private $_log;
    private $_db;
    private $_baseURL;               // URL to harvest from
    private $_set = null;            // Target set to harvest (null for all records)
    private $_metadata = 'oai_dc';   // Metadata type to harvest
    private $_idPrefix = '';         // OAI prefix to strip from ID values
    private $_idSearch = array();    // Regular expression searches
    private $_idReplace = array();   // Replacements for regular expression matches
    private $_target;                // Target ID
    private $_lastHarvestFile;       // File for tracking last harvest date
    private $_startDate = null;      // Harvest start date (null for all records)
    private $_endDate = null; 		 // Harvest end date (null for all records)
    private $_granularity = 'auto';  // Date granularity
    private $_injectId = false;      // Tag to use for injecting IDs into XML
    private $_injectSetSpec = false; // Tag to use for injecting setSpecs
    private $_injectSetName = false; // Tag to use for injecting set names
    private $_injectDate = false;    // Tag to use for injecting datestamp
    private $_setNames = array();    // Associative array of setSpec => setName
    private $_harvestedIdLog = false;// Filename for logging harvested IDs.
    private $_verbose = false;       // Should we display debug output?
    private $_normalRecords = 0;     // Harvested normal record count
    private $_deletedRecords = 0;    // Harvested deleted record count
    private $_debugLog = '';         // File where to dump OAI requests and responses for debugging
    private $_childPid = null;       // Child process id for record processing
    private $_resumptionToken = '';  // Override the first harvest request
    private $_transformation = null; // Transformation applied to the OAI-PMH responses before processing
    
    // As we harvest records, we want to track the most recent date encountered
    // so we can set a start point for the next harvest.
    private $_trackedEndDate = 0;

    /**
     * Constructor.
     *
     * @param string $target   Target directory for harvest.
     * @param array  $settings OAI-PMH settings from oai.ini.
     *
     * @access public
     */
    public function __construct($logger, $db, $target, $basePath, $settings, $startResumptionToken = '')
    {
        $this->_log = $logger;
        $this->_db = $db;
         
        // Don't time out during harvest
        set_time_limit(0);

        // Check if we have a start date
        $this->_target = $target;
        $this->_loadLastHarvestedDate();

       	$this->_resumptionToken = $startResumptionToken;

        // Set up base URL:
        if (empty($settings['url'])) {
            die("Missing base URL for {$target}.\n");
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
            if ($style->load($basePath . '/transformations/' . $settings['oaipmhTransformation']) === false) {
                throw new Exception('Could not load ' . $basePath . '/transformations/' . $settings['oaipmhTransformation']);
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
     * @return void
     * @access public
     */
    public function launch($callback)
    {
        $this->_normalRecords = 0;
        $this->_deletedRecords = 0;
        $this->_callback = $callback;

        if ($this->_resumptionToken) {
            $token = $this->_getRecordsByToken($this->_resumptionToken);
        } else {
            // Start harvesting at the requested date:
            $token = $this->_getRecordsByDate();
        }

        // Keep harvesting as long as a resumption token is provided:
        while ($token !== false) {
            $this->progressReport();
            $token = $this->_getRecordsByToken($token);
        }
        $this->progressReport();
        if (isset($this->_childPid)) {
            pcntl_waitpid($this->_childPid, $status);
        }
    }

    public function progressReport()
    {
        $this->_message('Harvested ' . $this->_normalRecords . ' normal records and ' . $this->_deletedRecords . ' deleted records');
    }

    /**
     * Retrieve the date from the database and use it as our start
     * date if it is available.
     *
     * @return void
     * @access private
     */
    private function _loadLastHarvestedDate()
    {
        $state = $this->_db->state->findOne(array('_id' => "Last Harvest Date {$this->_target}"));
        if (isset($state)) {
            $this->setStartDate($state['value']);
            $this->_message('Incremental harvest from timestamp ' . $state['value']);
        }
        else {
            $this->_message('No last harvested date stored', false, Logger::WARNING);
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
     * @access private
     */
    private function _saveLastHarvestedDate($date)
    {
        $state = array('_id' => "Last Harvest Date {$this->_target}", 'value' => $date);
        $this->_db->state->save($state);
    }

    /**
     * Make an OAI-PMH request.  Die if there is an error; return a SimpleXML object
     * on success.
     *
     * @param string $verb   OAI-PMH verb to execute.
     * @param array  $params GET parameters for ListRecords method.
     *
     * @return object        SimpleXML-formatted response.
     * @access private
     */
    private function _sendRequest($verb, $params = array())
    {
        // Set up the request:
        $request = new HTTP_Request();
        $request->addHeader('User-Agent', 'RecordManager');
        $request->setMethod(HTTP_REQUEST_METHOD_GET);
        $request->setURL($this->_baseURL);

        // Load request parameters:
        $request->addQueryString('verb', $verb);
        foreach ($params as $key => $value) {
            $request->addQueryString($key, $value);
        }

        $url = $request->getURL();
        $this->_message("Sending request: $url", true);
        if ($this->_debugLog) {
            file_put_contents($this->_debugLog, "Request:\n$url\n", FILE_APPEND);
        }

        // Perform request and die on error:
        for ($try = 1; $try <= 5; $try++) {
            $result = $request->sendRequest();
            if ($try < 5) {
                if (PEAR::isError($result)) {
                    $this->_message("Request '$url' failed (" . $result->getMessage() . "), retrying in 30 seconds...", false, Logger::WARNING);
                    sleep(30);
                    continue;
                }
                $code = $request->getResponseCode() ;
                if ($code >= 300) {
                    $this->_message("Request '$url' failed ($code), retrying in 30 seconds...", false, Logger::WARNING);
                    sleep(30);
                    continue;
                }
            }
            break;
        }
        if (PEAR::isError($result)) {
            $this->_message("Request '$url' failed (" . $result->getMessage() . ")", false, Logger::FATAL);
            die($result->getMessage() . "\n");
        }
        $code = $request->getResponseCode();
        if ($code >= 300) {
            $this->_message("Request '$url' failed: $code", false, Logger::FATAL);
            die("Request failed: $code\n");
        }

        // If we got this far, there was no error -- send back response.
        $response = $request->getResponseBody();
        if ($this->_debugLog) {
            file_put_contents($this->_debugLog, "Response:\n$response\n\n", FILE_APPEND);
        }
        return $this->_processResponse($response);
    }

    /**
     *
     * Load XML into simplexml
     * @param string $xml
     * @return SimpleXMLElement
     */
    private function _loadXML($xml)
    {
        if ($this->_transformation) {
            $doc = new DOMDocument();
            $doc->loadXML($xml);
            return simplexml_import_dom($this->_transformation->transformToDoc($doc));
        }
        return simplexml_load_string($xml);
    }

    /**
     * Process an OAI-PMH response into a SimpleXML object.  Die if an error is
     * detected.
     *
     * @param string $xml OAI-PMH response XML.
     *
     * @return object     SimpleXML-formatted response.
     * @access private
     */
    private function _processResponse($xml)
    {
        // Parse the XML:
        $saveUseErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $result = $this->_loadXML($xml);
        if ($result === false || libxml_get_last_error() !== false) {
            // Assuming it's a character encoding issue, this might help...
            $this->_message("Invalid XML received, trying encoding fix...", false, Logger::WARNING);
            $xml = iconv("UTF-8","UTF-8//IGNORE", $xml);
            libxml_clear_errors();
            $result = $this->_loadXML($xml);
        }
        if ($result === false || libxml_get_last_error() !== false) {
            $errors = '';
            foreach (libxml_get_errors() as $error) {
                if ($errors) {
                    $errors .= '; ';
                }
                $errors .= 'Error ' . $error->code . ' at ' . $error->line . ':' . $error->column . ': ' . $error->message;
            }
            $this->_message("Could not parse XML response: $errors\nXML:\n$xml", false, Logger::FATAL);
            die("Problem loading XML\n");
        }
        libxml_use_internal_errors($saveUseErrors);

        // Detect errors and die if one is found:
        if ($result->error) {
            $attribs = $result->error->attributes();
            $this->_message("OAI-PMH server returned error {$attribs['code']} ({$result->error})", false, Logger::FATAL);
            die(
                "OAI-PMH error -- code: {$attribs['code']}, " .
                "value: {$result->error}\n"
            );
        }

        // If we got this far, we have a valid response:
        return $result;
    }

    /**
     * Load date granularity from the server.
     *
     * @return void
     * @access private
     */
    private function _loadGranularity()
    {
        $this->_message('Autodetecting date granularity...');
        $response = $this->_sendRequest('Identify');
        $this->_granularity = (string)$response->Identify->granularity;
        $this->_message("Date granularity: {$this->_granularity}");
    }

    /**
     * Load set list from the server.
     *
     * @return void
     * @access private
     */
    private function _loadSetNames()
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
     * @param object $record SimpleXML record.
     *
     * @return string        The ID value.
     * @access private
     */
    private function _extractID($record)
    {
        // Normalize to string:
        $id = (string)$record->header->identifier;

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
     * Save harvested records to disk and track the end date.
     *
     * @param object $records SimpleXML records.
     *
     * @return void
     * @access private
     */
    private function _processRecords($records)
    {
        $this->_message('Processing ' . count($records) . ' records...', true);

        // Array for tracking successfully harvested IDs:
        $harvestedIds = array();

        // Loop through the records:
        foreach ($records as $record) {
            // Die if the record is missing its header:
            if (empty($record->header)) {
                $this->_message("Record header missing", false, Logger::FATAL);
                die("Unexpected missing record header.\n");
            }

            // Get the ID of the current record:
            $id = $this->_extractID($record);

            // Save the current record, either as a deleted or as a regular file:
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
                call_user_func($this->_callback, $id, false, trim($recordNode[0]->asXML()));
                $harvestedIds[] = $id;
                $this->_normalRecords++;
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
                die ("Problem opening {$this->_harvestedIdLog}.\n");
            }
            fputs($file, implode("\n", $harvestedIds));
            fclose($file);
        }
        $this->_message('Records processed', true);
    }

    /**
     * Harvest records using OAI-PMH.
     *
     * @param array $params GET parameters for ListRecords method.
     *
     * @return mixed        Resumption token if provided, false if finished
     * @access private
     */
    private function _getRecords($params)
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
     * @access private
     */
    private function _getRecordsByDate($date = null, $set = null)
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
     * @access private
     */
    private function _getRecordsByToken($token)
    {
        return $this->_getRecords(array('resumptionToken' => (string)$token));
    }

    /**
     * Display a timestamped message on the console and log it.
     *
     * @param string $msg Message.
     * @param bool   $verbose Flag telling whether this is considered verbose output
     *
     * @return void
     * @access private
     */
    private function _message($msg, $verbose = false, $level = Logger::INFO)
    {
        if ($this->_verbose) {
            echo $msg;
        }
        $this->_log->log('harvestOaiPmh', $msg, $level);
    }
}


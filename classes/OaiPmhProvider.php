<?php
/**
 * OAI-PMH Provider Class
 *
 * PHP version 5
 *
 * Copyright (C) The National Library of Finland 2012.
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

require_once 'RecordFactory.php';
require_once 'Logger.php';
require_once 'XslTransformation.php';

/**
 * OAI-PMH Provider Class
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class OaiPmhProvider
{
    const DT_EMPTY = 0;
    const DT_INVALID = 1;
    const DT_SHORT = 2;
    const DT_LONG = 3;
    
    protected $verb = '';
    protected $log = null;
    protected $db = null;
    protected $transformations = array();
    protected $dataSourceSettings = array();
    protected $formats = array();
    protected $sets = array();
    
    /**
     * Constructor
     *
     * @access public
     */
    public function __construct()
    {
        global $configArray;
        global $basePath;
        $this->dataSourceSettings = parse_ini_file("$basePath/conf/datasources.ini", true);
        $this->formats = parse_ini_file("$basePath/conf/{$configArray['OAI-PMH']['format_definitions']}", true);
        $this->sets = parse_ini_file("$basePath/conf/{$configArray['OAI-PMH']['set_definitions']}", true);
        
        date_default_timezone_set($configArray['Site']['timezone']);
        
        $this->log = new Logger();
        
        $mongo = new Mongo($configArray['Mongo']['url']);
        $this->db = $mongo->selectDB($configArray['Mongo']['database']);
        MongoCursor::$timeout = isset($configArray['Mongo']['cursor_timeout']) ? $configArray['Mongo']['cursor_timeout'] : 300000;
        
    }

    /**
     * Process the request
     * 
     * @return void
     */
    public function launch()
    {
        $this->verb = $this->getParam('verb');
        $this->printPrefix();
        if (!$this->checkParameters()) {
            $this->printSuffix();
            die();
        }
        switch ($this->verb) {
        case 'GetRecord':
            $this->getRecord();
            break;
        case 'Identify':
            $this->identify();
            break;
        case 'ListIdentifiers':
        case 'ListRecords':
            $this->listRecords($this->verb);
            break;
        case 'ListMetadataFormats':
            $this->listMetadataFormats();
            break;
        case 'ListSets':
            $this->listSets();
            break;
        default:
            $this->error('badVerb', 'Illegal OAI Verb');
            break;
        }
        $this->printSuffix();
    }
    
    /**
     * GetRecord handler
     * 
     * @return void
     */
    protected function getRecord()
    {
        $id = $this->getParam('identifier');
        $prefix = $this->getParam('metadataPrefix');
        
        $record = $this->db->record->findOne(array('oai_id' => $id));
        if (!$record) {
            $this->error('idDoesNotExist', 'The value of the identifier argument is unknown or illegal in this repository.');
            $this->printSuffix();
            die();
        }
        $xml = $this->createRecord($record, $prefix, true);
        print <<<EOF
  <GetRecord>
$xml
  </GetRecord>

EOF;
    }
    
    /**
     * Identify handler
     * 
     * @return void
     */
    protected function identify()
    {
        global $configArray;
        $name = $this->escape($configArray['OAI-PMH']['repository_name']);
        $base = $this->escape($configArray['OAI-PMH']['base_url']);
        $admin = $this->escape($configArray['OAI-PMH']['admin_email']);
        $earliestDate = $this->toOaiDate($this->getEarliestDateStamp());
        
        print <<<EOF
<Identify>
  <repositoryName>$name</repositoryName>
  <baseURL>$base</baseURL>
  <protocolVersion>2.0</protocolVersion>
  <adminEmail>$admin</adminEmail>
  <earliestDatestamp>$earliestDate</earliestDatestamp>
  <deletedRecord>persistent</deletedRecord>
  <granularity>YYYY-MM-DDThh:mm:ssZ</granularity>
<!--  <compression>deflate</compression> -->
</Identify>       

EOF;
    }
    
    /**
     * ListRecords/ListIdentifiers handler
     * 
     * @param boolean $verb 'ListRecords' or 'ListIdentifiers'
     * 
     * @return void
     */
    protected function listRecords($verb)
    {
        global $configArray;
        
        $includeMetadata = $verb == 'ListRecords';
        
        $resumptionToken = $this->getParam('resumptionToken');
        if ($resumptionToken) {
            $params = explode('|', $resumptionToken);
            if (count($params) != 5) { 
                $this->error('badResumptionToken', '');
                $this->printSuffix();
                die();
            }
            $set = $params[0];
            $metadataPrefix = $params[1];
            $from = $params[2];
            $until = $params[3];
            $position = $params[4];
        } else {
            $set = $this->getParam('set');
            $metadataPrefix = $this->getParam('metadataPrefix');
            $from = $this->getParam('from');
            $until = $this->getParam('until');
            $position = 0;
        }
        
        $queryParams = array();
        if ($set) {
            if (!isset($this->sets[$set])) {
                $this->error('noRecordsMatch', 'Requested set does not exist');
                $this->printSuffix();
                die();
            }
            foreach ($this->sets[$set] as $key => $value) {
                if ($key == 'name') {
                    continue;
                } 
                $queryParams[$key] = $value;
            }
        }
        if ($from && $until) {
            $queryParams['updated'] = array('$gte' => new MongoDate($this->fromOaiDate($from, '00:00:00'), 0), 
              '$lte' => new MongoDate($this->fromOaiDate($until, '23:59:59'), 999999));
        } elseif ($from) {
            $queryParams['updated'] = array('$gte' => new MongoDate($this->fromOaiDate($from, '00:00:00'), 0));
        } elseif ($until) {
            $queryParams['updated'] = array('$lte' => new MongoDate($this->fromOaiDate($until, '23:59:59'), 999999));
        }
                        
        $records = $this->db->record->find($queryParams)->sort(array('updated' => 1));
        if ($position) {
            $records = $records->skip($position);
        }
        
        if (!$records->hasNext()) {
            $this->error('noRecordsMatch', '');
            $this->printSuffix();
            die();
        }
        
        print <<<EOF
          <$verb>
        
EOF;
        
        $maxRecords = $configArray['OAI-PMH']['result_limit'];
        $count = 0;
        foreach ($records as $record) {
            $xml = $this->createRecord($record, $metadataPrefix, $includeMetadata);
            if ($xml === false) {
                break;
            }
            print $xml;
            if (++$count >= $maxRecords) {
                if ($records->hasNext()) {
                    // More records available, create resumptionToken
                    $token = $this->escape(implode('|', array($set, $metadataPrefix, $from, $until, $count + $position)));
                    print <<<EOF
    <resumptionToken cursor="$position">$token</resumptionToken>

EOF;
                }
                break;
            }
        }
        print <<<EOF
  </$verb>

EOF;
    }
    
    /**
     * ListMetadataFormats handler
     * 
     * @return void
     */
    protected function listMetadataFormats()
    {
        global $configArray;
        
        $formats = array();

        $id = $this->getParam('identifier');
        $source = '';
        if ($id) {
            $record = $this->db->record->findOne(array('oai_id' => $id));
            if (!$record) {
                $this->error('idDoesNotExist', 'The value of the identifier argument is unknown or illegal in this repository.');
                $this->printSuffix();
                die();
            }
            $source = $record['source_id'];
        }
        
        // List available formats
        foreach ($this->dataSourceSettings as $key => $datasource) {
            if ($source && $key != $source) {
                continue;
            }
            if (!isset($datasource['format'])) {
                continue;
            }
            $formats[$datasource['format']] = 1;
            foreach ($datasource as $key => $value) {
                if (preg_match('/transformation_to_(.+)/', $key, $matches)) {
                    $formats[$matches[1]] = 1;
                }
            }
        }
        
        print <<<EOF
  <ListMetadataFormats>

EOF;
        
        // Map to OAI-PMH formats
        $xml = '';
        foreach ($formats as $key => $dummy) {
            foreach ($this->formats as $id => $settings) {
                if ($settings['format'] == $key) {
                    $prefix = $this->escape($id);
                    $schema = $settings['schema'];  
                    $namespace = $settings['namespace'];  
                
                    print <<<EOF
    <metadataFormat>
      <metadataPrefix>$prefix</metadataPrefix>
      <schema>$schema</schema>
      <metadataNamespace>$namespace</metadataNamespace>
    </metadataFormat>

EOF;
                    break;
                }
            }
        }
        print <<<EOF
  </ListMetadataFormats>

EOF;
    }
    
    /**
     * ListSets handler
     * 
     * @return void
     */
    protected function listSets()
    {
        print <<<EOF
  <ListSets>

EOF;

        foreach ($this->sets as $id => $set) {
            $id = $this->escape($id);
            $name = $this->escape($set['name']);
     
            print <<<EOF
    <set>
      <setSpec>$id</setSpec>
      <setName>$name</setName>
    </set>          
EOF;
        }
        
        print <<<EOF
  </ListSets>
EOF;
    }
    
    /**
     * Output and log an error
     * 
     * @param string $code    Error code
     * @param string $message Error message
     * 
     * @return void
     */
    protected function error($code, $message)
    {
        $code = $this->escape($code);
        $message = $this->escape($message);
        print "  <error code=\"$code\">$message</error>\n";
        $this->log("$code - $message", Logger::ERROR);
    }
    
    /**
     * Output OAI-PMH response body opening
     * 
     * @return void
     */
    protected function printPrefix()
    {
        global $configArray;
        header('Content-Type: text/xml');
        header("Cache-Control: no-cache, must-revalidate");
        $date = $this->toOaiDate();
        $base = $this->escape($configArray['OAI-PMH']['base_url']);
        $arguments = '';
        foreach ($this->getRequestParameters() as $param) {
            $keyValue = explode('=', $param, 2);
            if (isset($keyValue[1])) { 
                $arguments .= ' ' . $keyValue[0] . '="' . $this->escape($keyValue[1]) . '"';
            }
        }
            
        print <<<EOF
<?xml version="1.0" encoding="UTF-8"?>
<OAI-PMH xmlns="http://www.openarchives.org/OAI/2.0/" 
         xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:schemaLocation="http://www.openarchives.org/OAI/2.0/
         http://www.openarchives.org/OAI/2.0/OAI-PMH.xsd">
  <responseDate>$date</responseDate>
  <request$arguments>$base</request>

EOF;
    }
    
    /**
     * Output OAI-PMH response body closing
     * 
     * @return void
     */
    protected function printSuffix()
    {
        print "</OAI-PMH>\n";
    }

    /**
     * Convert OAI-PMH timestamp to PHP time
     * 
     * @param string $datestr              OAI-PMH timestamp
     * @param string $timePartForShortDate Time part to use for a date without time
     * 
     * @return int A timestamp
     */
    protected function fromOaiDate($datestr, $timePartForShortDate)
    {
        if ($this->getOaiDateType($datestr) === OaiPmhProvider::DT_SHORT) {
            $datestr .= ' ' . $timePartForShortDate . '+0000';
        } else {
            $datestr = substr($datestr, 0, strlen($datestr) - 1) . '+0000';
        }
        return strtotime($datestr);
    }
    
    /**
     * Convert time to OAI-PMH timestamp
     * 
     * @param int|null $date Time to convert or null for current time
     * 
     * @return string OAI-PMH timestamp
     */
    protected function toOaiDate($date = null) 
    {
        if (!isset($date)) {
            $date = time();
        }
        return gmdate('Y-m-d\TH:i:s\Z', $date);
    }
    
    /**
     * Return HTTP request parameter
     * 
     * @param string $param Parameter name
     * 
     * @return string Parameter value or empty string
     */
    protected function getParam($param)
    {
        return isset($_REQUEST[$param]) ? $_REQUEST[$param] : ''; 
    }
    
    /**
     * Return the earliest time in the database
     * 
     * @return int Time
     */
    protected function getEarliestDateStamp()
    {
        $record = $this->db->record->find()->sort(array('updated' => 1))->limit(1)->getNext();
        return $record['updated']->sec;
    }
    
    /**
     * Get the sets the record belongs to
     * 
     * @param object $record Mongo record
     * 
     * @return string[]
     */
    protected function getRecordSets($record)
    {
        $sets = array();
        foreach ($this->sets as $id => $set) {
            $match = true;
            foreach ($set as $key => $value) {
                if ($key == 'name') {
                    continue;
                }
                if (!isset($record[$key]) || $record[$key] != $value) {
                    $match = false;
                    break;
                }
            } 
            if ($match) {
                $sets[] = $id;
            }
        }
        return $sets;
    }
    
    /**
     * Get all request parameters
     * 
     * @return string[]
     */
    protected function getRequestParameters()
    {
        if ($_SERVER['REQUEST_METHOD'] == 'POST') {
            $params = file_get_contents("php://input");
        } else {
            $params = $_SERVER['QUERY_STRING'];
        }
        return explode('&', $params);
    }
    
    /**
     * Verify that the request parameters are valid
     * 
     * @return boolean
     */
    protected function checkParameters()
    {
        $paramArray = $this->getRequestParameters();
        $checkArray = array();
        foreach ($paramArray as $param) {
            $keyValue = explode('=', $param, 2);
            if (isset($checkArray[$keyValue[0]])) {
                $this->error('badArgument', 'Duplicate arguments not allowed');
                return false;
            }
            $checkArray[$keyValue[0]] = 1;
        }
        // Check for missing or unknown parameters
        $paramCount = count($paramArray) - 1;
        switch ($this->verb) {
        case 'GetRecord':
            if ($paramCount != 2 || !$this->getParam('identifier') || !$this->getParam('metadataPrefix')) {
                $this->error('badArgument', 'Missing or extraneous arguments');
                return false;
            }
            break;
        case 'Identify':
            if ($paramCount != 0) {
                $this->error('badArgument', 'Extraneous arguments');
                return false;
            }
            break;
        case 'ListIdentifiers':
        case 'ListRecords':
            if ($this->getParam('resumptionToken')) {
                if ($paramCount != 1) {
                    $this->error('badArgument', 'Extraneous arguments with resumptionToken');
                    return false;
                }
            } else {
                if (!$this->getParam('metadataPrefix')) {
                    $this->error('badArgument', 'Missing argument "metadataPrefix"');
                    return false;
                }
                foreach ($_GET as $key => $value) {
                    if (!in_array($key, array('verb', 'from', 'until', 'set', 'metadataPrefix'))) {
                        $this->error('badArgument', 'Illegal argument');
                        return false;
                    }    
                }
            }
            break;
        case 'ListMetadataFormats':
            if ($paramCount > 1 || ($paramCount == 1 && !$this->getParam('identifier'))) {
                $this->error('badArgument', 'Invalid arguments');
                return false;
            }
            break;
        case 'ListSets':
            if ($paramCount > 1 || ($paramCount == 1 && !$this->getParam('resumptionToken'))) {
                $this->error('badArgument', 'Invalid arguments');
                return false;
            } elseif ($this->getParam('resumptionToken')) {
                $this->error('badResumptionToken', '');
                return false;
            }
            break;
        default:
            $this->error('badVerb', 'Invalid verb');
            $this->printSuffix();
            die();
        }
        
        // Check dates
        $fromType = $this->getOaiDateType($this->getParam('from'));
        $untilType = $this->getOaiDateType($this->getParam('until'));
        
        if ($fromType == OaiPmhProvider::DT_INVALID || $untilType == OaiPmhProvider::DT_INVALID) {
            $this->error('badArgument', 'Invalid date format');
            return false;
        }
        if ($fromType != OaiPmhProvider::DT_EMPTY && $untilType != OaiPmhProvider::DT_EMPTY && $fromType != $untilType) {
            $this->error('badArgument', 'Incompatible date formats');
            return false;
        }
        
        return true;
    }

    /**
     * Get the type of the given timestamp
     * 
     * @param string $date OAI-PMH timestamp
     * 
     * @return enum Date type
     */
    protected function getOaiDateType($date)
    {
        if (!$date) {
            return OaiPmhProvider::DT_EMPTY;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return OaiPmhProvider::DT_SHORT;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $date)) {
            return OaiPmhProvider::DT_LONG;
        }
        return OaiPmhProvider::DT_INVALID;
    }
    
    /**
     * Write a message to log
     * 
     * @param string $message Message
     * @param enum   $level   Log level for the message
     * 
     * @return void
     */
    protected function log($message, $level = Logger::INFO)
    {
        $message = '[' . $_SERVER['REMOTE_ADDR'] . '] ' . $message . ' (request: ' . $_SERVER['QUERY_STRING'] . ')';
        $this->log->log('OaiPmhProvider', $message, $level);
    }
    
    /**
     * Create record XML
     * 
     * @param object  $record          Mongo record
     * @param string  $format          Metadata format
     * @param boolean $includeMetadata Whether to include record data (or only header)
     * 
     * @return boolean|string
     */
    protected function createRecord($record, $format, $includeMetadata)
    {
        global $configArray;
        global $basePath;
        
        $sourceFormat = $record['format'];
        if (isset($this->formats[$format])) {
            $format = $this->formats[$format]['format'];
        }
        $metadata = '';
        if ($includeMetadata) {
            $mongodata = $record['normalized_data'] ? $record['normalized_data'] : $record['original_data'];
            $metadataRecord = RecordFactory::createRecord($record['format'], gzinflate($mongodata->bin), $record['oai_id'], $record['source_id']);
            $metadata = $metadataRecord->toXML();
            $key = "transformation_to_{$format}";
            $source = $record['source_id'];
            $datasource = $this->dataSourceSettings[$source];
            if ($sourceFormat != $format || isset($datasource[$key])) {
                if (!isset($datasource[$key])) {
                    $this->error('cannotDisseminateFormat', '', false);
                    return false;
                }
                $transformationKey = "{$key}_$source";
                if (!isset($this->transformations[$transformationKey])) {
                    $this->transformations[$transformationKey] = new XslTransformation($basePath . '/transformations', $datasource[$key]);
                }
                $params = array('source_id' => $source, 'institution' => $datasource['institution'], 'format' => $record['format']);
                $metadata = $this->transformations[$transformationKey]->transform($metadata, $params);
            }
            if (strncmp($metadata, '<?xml', 5) == 0) {
                $end = strpos($metadata, '>');
                $metadata = substr($metadata, $end + 1);
            }
            $metadata = <<<EOF
      <metadata>
        $metadata
      </metadata>

EOF;
        }
        
        $setSpecs = '';
        foreach ($this->getRecordSets($record) as $id) {
            $id = $this->escape($id);
            $setSpecs .= <<<EOF
        <setSpec>$id</setSpec>

EOF;
        }
        
        $id = $this->escape($record['oai_id']);
        $date = $this->toOaiDate($record['updated']->sec);
        $source = $this->escape($record['source_id']);
        $status = $record['deleted'] ? ' status="deleted"' : '';
        return <<<EOF
    <record>
      <header$status>
        <identifier>$id</identifier>
        <datestamp>$date</datestamp>
$setSpecs      </header>
$metadata    </record>

EOF;
    }
    
    /**
     * Escape special characters for XML
     * 
     * @param string $str String to escape
     * 
     * @return string Escaped string
     */
    protected function escape($str)
    {
        $str = str_replace('&', '&amp;', $str);
        $str = str_replace('"', '&quot;', $str);
        $str = str_replace('<', '&lt;', $str);
        $str = str_replace('>', '&gt;', $str);
        return $str;
    }
}


<?php
/**
* OAI-PMH Provider Class
*
* PHP version 5
*
* Copyright (C) Ere Maijala 2012.
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
* @link
*/

require_once 'RecordFactory.php';
require_once 'Logger.php';
require_once 'XslTransformation.php';

/**
 * OAI-PMH Provider Class
 *
 */
class OaiPmhProvider
{
    protected $_verb = '';
    protected $_log = null;
    protected $_db = null;
    protected $_transformations = array();
    protected $_dataSourceSettings = array();
    protected $_formats = array();
    protected $_sets = array();
    
    /**
     * Constructor
     *
     * @access public
     */
    public function __construct()
    {
        global $configArray;
        global $basePath;
        $this->_dataSourceSettings = parse_ini_file("$basePath/conf/datasources.ini", true);
        $this->_formats = parse_ini_file("$basePath/conf/{$configArray['OAI-PMH']['format_definitions']}", true);
        $this->_sets = parse_ini_file("$basePath/conf/{$configArray['OAI-PMH']['set_definitions']}", true);
        
        date_default_timezone_set($configArray['Site']['timezone']);
        
        $this->_log = new Logger();
        
        $mongo = new Mongo($configArray['Mongo']['url']);
        $this->_db = $mongo->selectDB($configArray['Mongo']['database']);
        MongoCursor::$timeout = isset($configArray['Mongo']['cursor_timeout']) ? $configArray['Mongo']['cursor_timeout'] : 300000;
        
    }

    public function launch()
    {
        $this->_verb = $this->_getParam('verb');
        $this->_printPrefix();
        if (!$this->_checkParameters()) {
            $this->_printSuffix();
            die();
        }
        switch ($this->_verb) {
            case 'GetRecord':
                $this->_getRecord();
                break;
            case 'Identify':
                $this->_identify();
                break;
            case 'ListIdentifiers':
                $this->_listRecords(false);
                break;
            case 'ListRecords':
                $this->_listRecords(true);
                break;
            case 'ListMetadataFormats':
                $this->_listMetadataFormats();
                break;
            case 'ListSets':
                $this->_listSets();
                break;
            default:
                $this->_error('badVerb', 'Illegal OAI Verb');
                break;
            
        }
        $this->_printSuffix();
    }
    
    protected function _getRecord()
    {
        $id = $this->_getParam('identifier');
        $prefix = $this->_getParam('metadataPrefix');
        
        $record = $this->_db->record->findOne(array('oai_id' => $id));
        if (!$record) {
            $this->_error('idDoesNotExist', 'The value of the identifier argument is unknown or illegal in this repository.');
            die();
        }
        $xml = $this->_createRecord($record, $prefix, true);
        print <<<EOF
  <GetRecord>
$xml
  </GetRecord>

EOF;
    }
    
    protected function _identify()
    {
        global $configArray;
        $name = htmlentities($configArray['OAI-PMH']['repository_name']);
        $base = htmlentities($configArray['OAI-PMH']['base_url']);
        $admin = htmlentities($configArray['OAI-PMH']['admin_email']);
        $earliestDate = $this->_toOaiDate($this->_getEarliestDateStamp());
        
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
    
    protected function _listRecords($includeMetadata)
    {
        global $configArray;
        $resumptionToken = $this->_getParam('resumptionToken');
        if ($resumptionToken) {
            $params = explode('|', $resumptionToken);
            $set = $params[0];
            $metadataPrefix = $params[1];
            $from = $params[2];
            $until = $params[3];
            $position = $params[4];
        } else {
            $set = $this->_getParam('set');
            $metadataPrefix = $this->_getParam('metadataPrefix');
            $from = $this->_getParam('from');
            $until = $this->_getParam('until');
            $position = 0;
        }
        
        $queryParams = array();
        if ($set) {
            if (!isset($this->_sets[$set])) {
                $this->_error('noRecordsMatch', 'Requested set does not exist');
                $this->_printSuffix();
                die();
            }
            foreach ($this->_sets[$set] as $key => $value) {
                if ($key == 'name') {
                    continue;
                } 
                $queryParams[$key] = $value;
            }
        }
        if ($from && $until) {
            $queryParams['updated'] = array('$gte' => new MongoDate($this->_fromOaiDate($from, '00:00:00')), 
              '$lte' => new MongoDate($this->_fromOaiDate($until, '23:59:59')));
        } elseif ($from) {
            $queryParams['updated'] = array('$gte' => new MongoDate($this->_fromOaiDate($from, '00:00:00')));
        } elseif ($until) {
            $queryParams['updated'] = array('$lte' => new MongoDate($this->_fromOaiDate($until, '23:59:59')));
        }
                        
        $records = $this->_db->record->find($queryParams)->sort(array('updated' => 1));
        if ($position) {
            $records = $records->skip($position);
        }
        
        if (!$records->hasNext()) {
            $this->_error('noRecordsMatch', '');
            $this->_printSuffix();
            die();
        }
        
        print <<<EOF
          <ListRecords>
        
EOF;
        
        $maxRecords = $configArray['OAI-PMH']['result_limit'];
        $count = 0;
        foreach ($records as $record) {
          $xml = $this->_createRecord($record, $metadataPrefix, $includeMetadata);
          if ($xml === false) {
              break;
          }
          print $xml;
          if (++$count >= $maxRecords) {
              if ($records->hasNext()) {
                  // More records available, create resumptionToken
                  $cursor = $count + $position;
                  $token = htmlentities(implode('|', array($set, $metadataPrefix, $from, $until, $cursor)));
                  print <<<EOF
    <resumptionToken cursor="$cursor">$token</resumptionToken>

EOF;
              }
              break;
          }
        }
        print <<<EOF
  </ListRecords>

EOF;
    }
    
    protected function _listMetadataFormats()
    {
        global $configArray;
        
        $formats = array();

        $id = $this->_getParam('identifier');
        $source = '';
        if ($id) {
            $record = $this->_db->record->findOne(array('oai_id' => $id));
            if (!$record) {
                $this->_error('idDoesNotExist', 'The value of the identifier argument is unknown or illegal in this repository.');
                die();
            }
            $source = $record['source_id'];
        }
        
        // List available formats
        foreach ($this->_dataSourceSettings as $key => $datasource) {
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
            foreach ($this->_formats as $id => $settings) {
                if ($settings['format'] == $key) {
                    $prefix = htmlentities($id);
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
    
    protected function _listSets()
    {
        print <<<EOF
  <ListSets>

EOF;

        foreach ($this->_sets as $id => $set) {
            $id = htmlentities($id);
            $name = htmlentities($set['name']);
     
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
    
    protected function _error($code, $message)
    {
        $code = htmlentities($code);
        $message = htmlentities($message);
        print "  <error code=\"$code\">$message</error>\n";
        $this->_log("$code - $message", Logger::ERROR);
    }
    
    protected function _printPrefix()
    {
        global $configArray;
        header('Content-Type: text/xml');
        header("Cache-Control: no-cache, must-revalidate");
        $date = $this->_toOaiDate();
        $base = htmlentities($configArray['OAI-PMH']['base_url']);
        $arguments = '';
        foreach (explode('&', $_SERVER['QUERY_STRING']) as $param) {
            $keyValue = explode('=', $param, 2);
            $arguments .= ' ' . $keyValue[0] . '="' . htmlentities($keyValue[1]) . '"';
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
    
    protected function _printSuffix()
    {
        print "</OAI-PMH>\n";
    }

    protected function _fromOaiDate($datestr, $timePartForShortDate)
    {
        if (strstr($datestr, 'T') === false) {
            $datestr .= ' ' + $timePartForShortDate + '+0000';
        } else {
            $datestr = substr($datestr, 0, strlen($datestr) - 1) . '+0000';
        }
        return strtotime($datestr);
    }
    
    protected function _toOaiDate($date = null) 
    {
        if (!isset($date)) {
            $date = time();
        }
        return gmdate('Y-m-d\TH:i:s\Z', $date);
    }
    
    protected function _getParam($param)
    {
        return isset($_GET[$param]) ? $_GET[$param] : ''; 
    }
    
    protected function _getEarliestDateStamp()
    {
        $record = $this->_db->record->find()->sort(array('updated' => 1))->limit(1)->getNext();
        return $record['updated']->sec;
    }
    
    protected function _getRecordSets($record)
    {
        $sets = array();
        foreach ($this->_sets as $id => $set) {
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
    
    protected function _checkParameters()
    {
        $paramArray = explode('&', $_SERVER['QUERY_STRING']);
        $checkArray = array();
        foreach ($paramArray as $param) {
            $keyValue = explode('=', $param, 2);
            if (isset($checkArray[$keyValue[0]])) {
                $this->_error('badArgument', 'Duplicate arguments not allowed');
                return false;
            }
            $checkArray[$keyValue[0]] = 1;
        }
        
        $paramCount = count($_GET) - 1;
        switch ($this->_verb) {
            case 'GetRecord':
                if ($paramCount != 2 || !$this->_getParam('identifier') || !$this->_getParam('metadataPrefix')) {
                    $this->_error('badArgument', 'Missing or extraneous arguments');
                    return false;
                }
                break;
            case 'Identify':
                if ($paramCount != 0) {
                    $this->_error('badArgument', 'Extraneous arguments');
                    return false;
                }
                break;
            case 'ListIdentifiers':
            case 'ListRecords':
                if ($this->_getParam('resumptionToken')) {
                    if ($paramCount != 1) {
                        $this->_error('badArgument', 'Extraneous arguments with resumptionToken');
                        return false;
                    }
                } else {
                    if (!$this->_getParam('metadataPrefix')) {
                        $this->_error('badArgument', 'Missing argument "metadataPrefix"');
                        return false;
                    }
                    foreach ($_GET as $key => $value) {
                        if (!in_array($key, array('verb', 'from', 'until', 'set', 'metadataPrefix'))) {
                            $this->_error('badArgument', 'Illegal argument');
                            return false;
                        }    
                    }
                }
                break;
            case 'ListMetadataFormats':
                if ($paramCount > 1 || ($paramCount == 1 && !$this->_getParam('identifier'))) {
                    $this->_error('badArgument', 'Invalid arguments');
                    return false;
                }
                break;
            case 'ListSets':
                if ($paramCount > 1 || ($paramCount == 1 && !$this->_getParam('resumptionToken'))) {
                    $this->_error('badArgument', 'Invalid arguments');
                    return false;
                } elseif ($this->_getParam('resumptionToken')) {
                    $this->_error('badResumptionToken', '');
                }
                break;
            default:
                $this->_error('badVerb', 'Invalid verb');
                $this->_printSuffix();
                die();
        }
        return true;
    }
    
    protected function _log($message, $level = Logger::INFO)
    {
        $message = '[' . $_SERVER['REMOTE_ADDR'] . '] ' . $message . ' (request: ' . $_SERVER['QUERY_STRING'] . ')';
        $this->_log->log('OaiPmhProvider', $message, $level);
    }
    
    protected function _createRecord($record, $format, $includeMetadata)
    {
        global $configArray;
        global $basePath;
        
        $sourceFormat = $record['format'];
        if (isset($configArray['OAI-PMH Format Mappings'][$format])) {
            $format = $configArray['OAI-PMH Format Mappings'][$format];
        }
        $metadata = '';
        if ($includeMetadata) {
            $mongodata = $record['normalized_data'] ? $record['normalized_data'] : $record['original_data'];
            $metadataRecord = RecordFactory::createRecord($record['format'], gzinflate($mongodata->bin), $record['oai_id']);
            $metadata = $metadataRecord->toXML();
            if ($sourceFormat != $format) {
                $source = $record['source_id'];
                $datasource = $this->_dataSourceSettings[$source];
                $key = "transformation_to_{$format}";
                if (!isset($datasource[$key])) {
                    $this->_error('cannotDisseminateFormat', '', false);
                    return false;
                }
                $transformationKey = "{$key}_$source";
                if (!isset($this->_transformations[$transformationKey])) {
                    $this->_transformations[$transformationKey] = new XslTransformation($basePath . '/transformations', $datasource[$key]);
                }
                $params = array('source_id' => $source, 'institution' => $datasource['institution'], 'format' => $record['format']);
                $metadata = $this->_transformations[$transformationKey]->transform($metadata, $params);
            }
            $prolog = '<?xml version="1.0"?>';
            if (strncmp($metadata, $prolog, 21) == 0) {
                $metadata = substr($metadata, 22);
            }
            $metadata = <<<EOF
      <metadata>
        $metadata
      </metadata>

EOF;
        }
        
        $setSpecs = '';
        foreach ($this->_getRecordSets($record) as $id) {
            $id = htmlentities($id);
            $setSpecs .= <<<EOF
        <setSpec>$id</setSpec>

EOF;
        }
        
        $id = htmlentities($record['oai_id']);
        $date = $this->_oaiDate($record['updated']->sec);
        $source = htmlentities($record['source_id']);
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
}


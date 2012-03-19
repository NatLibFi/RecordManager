<?php
/**
 * MetaLib KnowledgeBase Harvester
 *
 * PHP version 5
 *
 * Copyright (C) Ere Maijala, The National Library of Finland 20112
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
 */

require_once 'FileSplitter.php';

/**
 * HarvestMetaLib
 *
 * This class harvests IRD records from MetaLib via X-Server using settings from datasources.ini. 
 *
 */
class HarvestMetaLib
{
    protected $_log;                   // Logger
    protected $_db;                    // Mongo database
    protected $_basePath;              // RecordManager base directory
    protected $_baseURL;               // URL to harvest from
    protected $_username = '';         // MetaLib X-Server user name
    protected $_password = '';         // MetaLib X-Server password
    protected $_verbose = false;       // Whether to display debug output
    protected $_query = '';            // Query used to find the IRD's (e.g. WIN=INSTITUTE, see 
                                     // locate_command in http://www.exlibrisgroup.org/display/MetaLibOI/source_locate)
        
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
        $this->_log = $logger;
        $this->_db = $db;
        $this->_basePath = $basePath;
         
        // Don't time out during harvest
        set_time_limit(0);
    
        // Set up base URL:
        if (empty($settings['url'])) {
            die("Missing base URL for {$target}.\n");
        }
        $this->_baseURL = $settings['url'];
        if (isset($settings['verbose'])) {
            $this->_verbose = $settings['verbose'];
        }
        $this->_username = $settings['xUser'];
        $this->_password = $settings['xPassword'];
        $this->_query = $settings['query'];
    }
    
    /**
     * Harvest all available documents.
     *
     * @return array  Array of MARCXML records
     * @access public
     */
    public function launch()
    {
        $xml = $this->_callXServer(array(
            'op' => 'login_request',
            'user_name' => $this->_username,
            'user_password' => $this->_password
        ));
        $doc = simplexml_load_string($xml);
        if (isset($doc->login_response->local_error)) {
            $this->_message("X-Server login failed: \n" . $xml, false, Logger::FATAL);
            throw new Exception("X-Server login failed");
        }
        if (!isset($doc->login_response->auth)) {
            $this->_message("Could not find auth information in X-Server login response: \n" . $xml, false, Logger::FATAL);
            throw new Exception("X-Server login response missing auth information");
        }
        if ((string)$doc->login_response->auth != 'Y') {
            $this->_message("X-Server login failed for '{$this->_username}'", false, Logger::FATAL);
            throw new Exception("X-Server login failed");
        }
        $session = (string)$doc->login_response->session_id;
        
        $xml = $this->_callXServer(array(
            'op' => 'source_locate_request',
            'session_id' => $session, 
        	'locate_command' => $this->_query,
            'source_full_info_flag' => 'Y'
        ));
        
        $doc = simplexml_load_string($xml);
        if (isset($doc->source_locate_response->local_error)) {
            $this->_message("X-Server source locate request failed: \n" . $xml, false, Logger::FATAL);
            throw new Exception("X-Server source locate request failed");
        }
    
        $style = new DOMDocument();
        if ($style->load($this->_basePath . '/transformations/strip_namespaces.xsl') === false) {
            throw new Exception('Could not load ' . $this->_basePath . '/transformations/strip_namespaces.xsl');
        }
        $transformation = new XSLTProcessor();
        $transformation->importStylesheet($style);
        $doc = new DOMDocument();
        $doc->loadXML($xml);
        $splitter = new FileSplitter($transformation->transformToDoc($doc), '//source_locate_response/source_full_info/record');
        
        $records = array();
        while (!$splitter->getEOF()) {
            $records[] = $splitter->getNextRecord();
        }
        return $records;
    }
    
    /**
     * Call MetaLib X-Server
     *
     * @param  array  $params  URL Parameters 
     * @return string XML
     * @access public
     */
    protected function _callXServer($params)
    {
        $request = new HTTP_Request();
        $request->addHeader('User-Agent', 'RecordManager');
        $request->setMethod(HTTP_REQUEST_METHOD_GET);
        $request->setURL($this->_baseURL);

        // Load request parameters:
        foreach ($params as $key => $value) {
            $request->addQueryString($key, $value);
        }

        $url = $request->getURL();
        $cleanUrl = preg_replace('/user_password=([^&]+)/', 'user_password=***', $url);
        $this->_message("Sending request: $cleanUrl", true);

        $result = $request->sendRequest();
        if (PEAR::isError($result)) {
            $this->_message("Request '$url' failed (" . $result->getMessage() . ")", false, Logger::FATAL);
            throw new Exception($result->getMessage());
        }
        $code = $request->getResponseCode();
        if ($code >= 300) {
            $this->_message("Request '$url' failed: $code", false, Logger::FATAL);
            throw new Exception("Request failed: $code");
        }
        $this->_message("Request successful", true);
        
        return $request->getResponseBody();
    }

    /**
     * Log a message
     *
     * @param string $msg Message.
     * @param bool   $verbose Flag telling whether this is considered verbose output
     *
     * @return void
     * @access private
     */
    private function _message($msg, $verbose = false, $level = Logger::INFO)
    {
        $this->_log->log('harvestMetaLib', $msg, $level);
    }
}

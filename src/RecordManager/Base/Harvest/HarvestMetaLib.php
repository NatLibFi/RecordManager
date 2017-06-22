<?php
/**
 * MetaLib KnowledgeBase Harvester
 *
 * PHP version 5
 *
 * Copyright (C) The National Library of Finland 20112
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
namespace RecordManager\Base\Harvest;

use RecordManager\Base\Database\Database;
use RecordManager\Base\Splitter\FileSplitter;
use RecordManager\Base\Utils\Logger;

require_once 'HTTP/Request2.php';

/**
 * HarvestMetaLib
 *
 * This class harvests IRD records from MetaLib via X-Server using settings from
 * datasources.ini.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class HarvestMetaLib
{
    /**
     * Logger
     *
     * @var Logger
     */
    protected $log;

    /**
     * Database
     *
     * @var Database
     */
    protected $db;

    /**
     * RecordManager Base Directory
     *
     * @var string
     */
    protected $basePath;

    /**
     * Base URL of MetaLib
     *
     * @var string
     */
    protected $baseURL;

    /**
     * MetaLib X-Server User Name
     *
     * @var string
     */
    protected $username = '';

    /**
     * MetaLib X-Server Password
     *
     * @var string
     */
    protected $password = '';

    /**
     * Whether to Display Debug Output
     *
     * @var bool
     */
    protected $verbose = false;

    /**
     * HTTP_Request2 configuration params
     *
     * @array
     */
    protected $httpParams = [];

    /**
     * Query used to Query used to find the IRDs (e.g. WIN=INSTITUTE, see locate_
     * command in http://www.exlibrisgroup.org/display/MetaLibOI/source_locate)
     *
     * @link http://www.exlibrisgroup.org/display/MetaLibOI/source_locate
     * @var  string
     */
    protected $query = '';

    /**
    * Constructor.
    *
    * @param Logger   $logger   The Logger object used for logging messages
    * @param Database $db       Database
    * @param string   $source   The data source to be harvested
    * @param string   $basePath RecordManager main directory location
    * @param array    $settings Settings from datasources.ini
    *
    * @throws Exception
    */
    public function __construct(Logger $logger, Database $db, $source, $basePath,
        $settings
    ) {
        global $configArray;

        $this->log = $logger;
        $this->db = $db;
        $this->basePath = $basePath;

        // Don't time out during harvest
        set_time_limit(0);

        // Set up base URL:
        if (empty($settings['url'])) {
            throw new \Exception("Missing base URL for {$source}");
        }
        $this->baseURL = $settings['url'];
        if (isset($settings['verbose'])) {
            $this->verbose = $settings['verbose'];
        }
        $this->username = $settings['xUser'];
        $this->password = $settings['xPassword'];
        $this->query = $settings['query'];

        if (isset($configArray['HTTP'])) {
            $this->httpParams += $configArray['HTTP'];
        }
    }

    /**
     * Harvest all available documents.
     *
     * @return string[] Array of MARCXML records
     * @throws Exception
     */
    public function harvest()
    {
        $xml = $this->callXServer(
            [
                'op' => 'login_request',
                'user_name' => $this->username,
                'user_password' => $this->password
            ]
        );
        $doc = simplexml_load_string($xml);
        if (isset($doc->login_response->local_error)) {
            $this->_message(
                "X-Server login failed: \n" . $xml, false, Logger::FATAL
            );
            throw new \Exception("X-Server login failed");
        }
        if (!isset($doc->login_response->auth)) {
            $this->_message(
                "Could not find auth information in X-Server login response: \n"
                . $xml,
                false,
                Logger::FATAL
            );
            throw new \Exception("X-Server login response missing auth information");
        }
        if ((string)$doc->login_response->auth != 'Y') {
            $this->_message(
                "X-Server login failed for '{$this->username}'", false, Logger::FATAL
            );
            throw new \Exception("X-Server login failed");
        }
        $session = (string)$doc->login_response->session_id;

        $xml = $this->callXServer(
            [
                'op' => 'source_locate_request',
                'session_id' => $session,
                'locate_command' => $this->query,
                'source_full_info_flag' => 'Y'
            ]
        );

        $style = new \DOMDocument();
        if ($style->load(
            $this->basePath . '/transformations/strip_namespaces.xsl'
        ) === false
        ) {
            throw new \Exception(
                'Could not load ' . $this->basePath
                . '/transformations/strip_namespaces.xsl'
            );
        }
        $doc = new \DOMDocument();
        if (!$doc->loadXML($xml)) {
            $this->_message(
                "Failed to parse X-Server source locate response: \n" . $xml,
                false,
                Logger::FATAL
            );
            throw new \Exception("Failed to parse X-Server source locate response");
        }
        $responseNode = $doc->getElementsByTagName('source_locate_response');
        if ($responseNode->length > 0) {
            $responseNode = $responseNode->item(0)
                ->getElementsByTagName('local_error');
            if ($responseNode->length > 0) {
                $this->_message(
                    "X-Server source locate request failed: \n" . $xml,
                    false,
                    Logger::FATAL
                );
                throw new \Exception("X-Server source locate request failed");
            }
        }
        $transformation = new \XSLTProcessor();
        $transformation->importStylesheet($style);
        $splitter = new FileSplitter(
            $transformation->transformToDoc($doc),
            '//source_locate_response/source_full_info/record',
            ''
        );

        $records = [];
        while (!$splitter->getEOF()) {
            $oaiID = '';
            $records[] = $splitter->getNextRecord($oaiID);
        }
        return $records;
    }

    /**
     * Call MetaLib X-Server
     *
     * @param array $params URL Parameters
     *
     * @return string XML
     * @throws Exception
     */
    protected function callXServer($params)
    {
        $request = new \HTTP_Request2(
            $this->baseURL,
            \HTTP_Request2::METHOD_GET,
            $this->httpParams
        );
        $request->setHeader('User-Agent', 'RecordManager');

        $url = $request->getURL();
        $url->setQueryVariables($params);

        $cleanUrl = preg_replace(
            '/user_password=([^&]+)/', 'user_password=***',
            $url->getURL()
        );
        $this->_message("Sending request: $cleanUrl", true);

        $response = $request->send();
        $code = $response->getStatus();
        if ($code >= 300) {
            $this->_message("Request '$url' failed: $code", false, Logger::FATAL);
            throw new \Exception("Request failed: $code");
        }
        $this->_message("Request successful", true);

        return $response->getBody();
    }

    /**
     * Log a message
     *
     * @param string $msg     Message
     * @param bool   $verbose Flag telling whether this is considered verbose output
     * @param int    $level   Logging level
     *
     * @return void
     */
    private function _message($msg, $verbose = false, $level = Logger::INFO)
    {
        $this->log->log('harvestMetaLib', $msg, $level);
    }
}

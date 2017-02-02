<?php
/**
 * Enrichment Class
 *
 * PHP version 5
 *
 * Copyright (C) The National Library of Finland 2014-2016.
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
 * Enrichment Class
 *
 * This is a base class for enrichment of records.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class Enrichment
{
    /**
     * Mongo DB connection
     *
     * @var MongoDB
     */
    protected $db;

    /**
     * Logger
     *
     * @var Logger
     */
    protected $log;

    /**
     * Maximum age of cached data in seconds
     *
     * @var number
     */
    protected $maxCacheAge;

    /**
     * HTTP Request
     *
     * @var HTTP_Request2
     */
    protected $request = null;

    /**
     * Maximum number of HTTP request attempts
     *
     * @var int
     */
    protected $maxTries;

    /**
     * Delay between HTTP request attempts (seconds)
     *
     * @var int
     */
    protected $retryWait;

    /**
     * HTTP_Request2 configuration params
     *
     * @array
     */
    protected $httpParams = [
        'follow_redirects' => true
    ];

    /**
     * Constructor
     *
     * @param MongoDB $db  Database connection (for cache)
     * @param Logger  $log Logger
     */
    public function __construct($db, $log)
    {
        global $configArray;

        $this->db = $db;
        $this->log = $log;
        $this->maxCacheAge = isset($configArray['Enrichment']['cache_expiration'])
            ? $configArray['Enrichment']['cache_expiration'] * 60
            : 86400;
        $this->maxTries = isset($configArray['Enrichment']['max_tries'])
            ? $configArray['Enrichment']['max_tries']
            : 90;
        $this->retryWait = isset($configArray['Enrichment']['retry_wait'])
            ? $configArray['Enrichment']['retry_wait']
            : 5;

        if (isset($configArray['HTTP'])) {
            $this->httpParams += $configArray['HTTP'];
        }
    }

    /**
     * Enrich the record and return any additions in solrArray
     *
     * @param string $sourceId  Source ID
     * @param object $record    Metadata Record
     * @param array  $solrArray Metadata to be sent to Solr
     *
     * @return void
     */
    public function enrich($sourceId, $record, &$solrArray)
    {
        // Implemented in child classes
    }

    /**
     * A helper function that retrieves external metadata and caches it
     *
     * @param string   $url          URL to fetch
     * @param string   $id           ID of the entity to fetch
     * @param string[] $headers      Optional headers to add to the request
     * @param array    $ignoreErrors Error codes to ignore
     *
     * @return string Metadata (typically XML)
     * @throws Exception
     */
    protected function getExternalData($url, $id, $headers = [], $ignoreErrors = [])
    {
        $cached = $this->db->uriCache->findOne(
            [
                '_id' => $id,
                'timestamp' => [
                    '$gt' => new \MongoDB\BSON\UTCDateTime(
                        (time() - $this->maxCacheAge) * 1000
                    )
                 ]
            ]
        );
        if ($cached) {
            return $cached['data'];
        }

        if (is_null($this->request)) {
            $this->request = new HTTP_Request2(
                $url,
                HTTP_Request2::METHOD_GET,
                $this->httpParams
            );
            $this->request->setHeader('Connection', 'Keep-Alive');
            $this->request->setHeader('User-Agent', 'RecordManager');
        } else {
            $this->request->setUrl($url);
        }
        if ($headers) {
            $this->request->setHeader($headers);
        }

        $retryWait = $this->retryWait;
        $response = null;
        for ($try = 1; $try <= $this->maxTries; $try++) {
            try {
                $response = $this->request->send();
            } catch (Exception $e) {
                if ($try < $this->maxTries) {
                    if ($retryWait < 30) {
                        // Progressively longer delay
                        $retryWait *= 2;
                    }
                    $this->log->log(
                        'getExternalData',
                        "HTTP request for '$url' failed (" . $e->getMessage()
                        . "), retrying in {$retryWait} seconds...",
                        Logger::WARNING
                    );
                    sleep($retryWait);
                    continue;
                }
                throw $e;
            }
            if ($try < $this->maxTries) {
                $code = $response->getStatus();
                if ($code >= 300 && $code != 404 && !in_array($code, $ignoreErrors)
                ) {
                    $this->log->log(
                        'getExternalData',
                        "HTTP request for '$url' failed ($code), retrying "
                        . "in {$this->retryWait} seconds...",
                        Logger::WARNING
                    );
                    sleep($this->retryWait);
                    continue;
                }
            }
            break;
        }

        $code = is_null($response) ? 999 : $response->getStatus();
        if ($code >= 300 && $code != 404 && !in_array($code, $ignoreErrors)) {
            throw new Exception("Enrichment failed to fetch '$url': $code");
        }

        $data = $code < 300 ? $response->getBody() : '';

        $this->db->uriCache->replaceOne(
            [
                '_id' => $id,
                'timestamp' => new \MongoDB\BSON\UTCDateTime(time() * 1000),
                'data' => $data
            ],
            [
                'upsert' => true
            ]
        );

        return $data;
    }
}

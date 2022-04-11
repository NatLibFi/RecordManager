<?php
/**
 * Trait for handling full text
 *
 * Prerequisites:
 * - HTTP\ClientManager as $this->httpClientManager
 * - Database as $this->db
 *
 * PHP version 7
 *
 * Copyright (C) The National Library of Finland 2020-2022.
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
namespace RecordManager\Base\Record;

/**
 * Trait for handling full text
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
trait FullTextTrait
{
    /**
     * HTTP Request class
     *
     * @var ?\HTTP_Request2
     */
    protected $urlRequest = null;

    /**
     * Number of requests handled per host
     *
     * @var array
     */
    protected static $requestsHandled = [];

    /**
     * Time all successful requests have taken per host
     *
     * @var array
     */
    protected static $requestsDuration = [];

    /**
     * Sum of sizes of all successful responses per host
     *
     * @var array
     */
    protected static $requestsSize = [];

    /**
     * Get full text fields for a given document
     *
     * @param \SimpleXMLElement $doc Document
     *
     * @return array
     */
    protected function getFullTextfields($doc)
    {
        $data = [];

        $fulltext = [];
        $xpaths = $this->getDriverParam('fullTextXpaths', []);
        foreach ((array)$xpaths as $xpath) {
            foreach ($doc->xpath($xpath) as $field) {
                $fulltext[] = (string)$field;
            }
        }

        $xpaths = $this->getDriverParam('fullTextUrlXPaths', []);
        foreach ((array)$xpaths as $xpath) {
            foreach ($doc->xpath($xpath) as $field) {
                $url = (string)$field;
                if (preg_match('/^https?:\/\//', $url)) {
                    try {
                        $fulltext[] = $this->getUrl($url);
                    } catch (\Exception $e) {
                        $id = $this->source . '.' . $this->getID();
                        $this->logger->logWarning(
                            get_class($this),
                            "Full text enrichment failed for record '$id': "
                            . $e->getMessage()
                        );
                    }
                }
            }
        }

        if ($fulltext) {
            $ft = implode(' ', $fulltext);
            // Try to handle hyphenated text properly. This is not perfect since
            // something like 'Etelä-Suomi' will become EteläSuomi
            $ft = preg_replace('/([^\s]+)-\s*[\n\r]+\s*/m', '\1', $ft);
            $data['fulltext'] = $ft;
        }

        return $data;
    }

    /**
     * A helper function that retrieves content from a url
     *
     * @param string $url URL to fetch
     *
     * @return string
     * @throws \Exception
     */
    protected function getUrl($url)
    {
        $maxCacheAge
            = ($this->config['FullTextEnrichment']['cache_expiration'] ?? 43200)
            * 60;
        $maxTries = $this->config['FullTextEnrichment']['max_tries'] ?? 90;
        $retryWait = $this->config['FullTextEnrichment']['retry_wait'] ?? 5;
        $httpOptions = [
            'follow_redirects' => true
        ];

        if ($maxCacheAge && null !== $this->db) {
            $cached = $this->db->findUriCache(
                [
                    '_id' => $url,
                    'timestamp' => [
                        '$gt' => $this->db->getTimestamp(time() - $maxCacheAge)
                    ]
                ]
            );
            if (null !== $cached) {
                return $cached['data'];
            }
        }

        $host = parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT);
        if ($port) {
            $host .= ":$port";
        }

        $response = null;
        $body = '';
        for ($try = 1; $try <= $maxTries; $try++) {
            if (!isset($this->request)) {
                $this->urlRequest = $this->httpClientManager->createClient(
                    $url,
                    \HTTP_Request2::METHOD_GET,
                    $httpOptions
                );
                $this->urlRequest->setHeader('Connection', 'Keep-Alive');
            } else {
                $this->urlRequest->setUrl($url);
            }

            $duration = 0;
            try {
                $startTime = microtime(true);
                $response = $this->urlRequest->send();
                $duration = microtime(true) - $startTime;
            } catch (\Exception $e) {
                if ($try < $maxTries) {
                    if ($retryWait < 30) {
                        // Progressively longer delay
                        $retryWait *= 2;
                    }
                    $this->logger->logWarning(
                        'getUrl',
                        "HTTP request for '$url' failed (" . $e->getMessage()
                            . "), retrying in {$retryWait} seconds (retry $try)..."
                    );
                    $this->urlRequest = null;
                    sleep($retryWait);
                    continue;
                }
                throw $e;
            }
            if ($try < $maxTries) {
                $code = $response->getStatus();
                if ($code >= 300 && $code != 404) {
                    $this->logger->logWarning(
                        'getUrl',
                        "HTTP request for '$url' failed ($code), retrying "
                            . "in {$retryWait} seconds (retry $try)..."
                    );
                    $this->urlRequest = null;
                    sleep($retryWait);
                    continue;
                }
            }
            if ($try > 1) {
                $this->logger->logWarning(
                    'getUrl',
                    "HTTP request for '$url' completed on attempt $try"
                );
            }
            $body = $response->getBody();
            if (isset(static::$requestsHandled[$host])) {
                static::$requestsHandled[$host]++;
                static::$requestsDuration[$host] += $duration;
                static::$requestsSize[$host] += strlen($body);
            } else {
                static::$requestsHandled[$host] = 1;
                static::$requestsDuration[$host] = $duration;
                static::$requestsSize[$host] = strlen($body);
            }
            if (static::$requestsHandled[$host] % 1000 === 0) {
                $average = floor(
                    static::$requestsDuration[$host]
                    / static::$requestsHandled[$host]
                    * 1000
                );
                $this->logger->logInfo(
                    'getUrl',
                    static::$requestsHandled[$host] . ' HTTP requests completed'
                        . " for $host, average time for a request $average ms"
                        . ', ' . round(static::$requestsSize[$host] / 1024 / 1024, 2)
                        . ' MB received'
                );
            }
            break;
        }

        $code = null === $response ? 999 : $response->getStatus();
        if ($code >= 300 && $code != 404) {
            throw new \Exception("Failed to fetch full text url '$url': $code");
        }

        // Make sure the text is valid UTF-8:
        $body = iconv('UTF-8', 'UTF-8//IGNORE', $body);
        // Replace also any control characters not allowed in XML 1.0:
        $body = preg_replace('/[\x01-\x08,\x0B,\x0C,\x0E-\x1F]/', ' ', $body);

        if ($maxCacheAge && null !== $this->db) {
            $this->db->saveUriCache(
                [
                    '_id' => $url,
                    'timestamp' => $this->db->getTimestamp(),
                    'url' => $url,
                    'headers' => [],
                    'data' => $body
                ]
            );
        }

        return $body;
    }
}

<?php

/**
 * Enrichment Class
 *
 * PHP version 8
 *
 * Copyright (C) The National Library of Finland 2014-2022.
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

namespace RecordManager\Base\Enrichment;

use GuzzleHttp\Client;
use RecordManager\Base\Database\DatabaseInterface as Database;
use RecordManager\Base\Exception\HttpRequestException;
use RecordManager\Base\Http\HttpService;
use RecordManager\Base\Record\PluginManager as RecordPluginManager;
use RecordManager\Base\Utils\Logger;
use RecordManager\Base\Utils\MetadataUtils;

use function in_array;

/**
 * Enrichment Class
 *
 * This is a base class for enrichment of records.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
abstract class AbstractEnrichment
{
    /**
     * Database
     *
     * @var Database
     */
    protected $db;

    /**
     * Logger
     *
     * @var Logger
     */
    protected $logger;

    /**
     * Main configuration
     *
     * @var array
     */
    protected $config;

    /**
     * Maximum age of cached data in seconds
     *
     * @var int
     */
    protected $maxCacheAge;

    /**
     * HTTP client
     *
     * @var ?Client
     */
    protected ?Client $httpClient = null;

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
     * HTTP options
     *
     * @var array
     */
    protected $httpOptions = [
        'follow_redirects' => true,
        'headers' => [
            'Connection' => 'Keep-Alive',
        ],
    ];

    /**
     * Number of requests handled per host
     *
     * @var array
     */
    protected $requestsHandled = [];

    /**
     * Time all successful requests have taken per host
     *
     * @var array
     */
    protected $requestsDuration = [];

    /**
     * Record plugin manager
     *
     * @var RecordPluginManager
     */
    protected $recordPluginManager;

    /**
     * HTTP service
     *
     * @var HttpService
     */
    protected $httpService;

    /**
     * Metadata utilities
     *
     * @var MetadataUtils
     */
    protected $metadataUtils;

    /**
     * Constructor
     *
     * @param array               $config              Main configuration
     * @param Database            $db                  Database connection (for
     *                                                 cache)
     * @param Logger              $logger              Logger
     * @param RecordPluginManager $recordPluginManager Record plugin manager
     * @param HttpService         $httpService         HTTP service
     * @param MetadataUtils       $metadataUtils       Metadata utilities
     */
    public function __construct(
        array $config,
        Database $db,
        Logger $logger,
        RecordPluginManager $recordPluginManager,
        HttpService $httpService,
        MetadataUtils $metadataUtils
    ) {
        $this->config = $config;
        $this->db = $db;
        $this->logger = $logger;
        $this->recordPluginManager = $recordPluginManager;
        $this->httpService = $httpService;
        $this->metadataUtils = $metadataUtils;

        $this->init();
    }

    /**
     * Initialize settings
     *
     * @return void
     */
    public function init()
    {
        // Default to 7 days:
        $this->maxCacheAge
            = ($this->config['Enrichment']['cache_expiration'] ?? 10080) * 60;
        $this->maxTries = $this->config['Enrichment']['max_tries'] ?? 90;
        $this->retryWait = $this->config['Enrichment']['retry_wait'] ?? 5;
    }

    /**
     * A helper function that retrieves external metadata and caches it
     *
     * @param string $url          URL to fetch
     * @param string $id           ID of the entity to fetch
     * @param array  $headers      Optional headers to add to the request
     * @param array  $ignoreErrors Error codes to ignore
     * @param bool   $useCache     Whether to use cache for the request
     *
     * @return string Metadata (typically XML)
     * @throws \Exception
     */
    protected function getExternalData(
        $url,
        $id,
        $headers = [],
        $ignoreErrors = [],
        bool $useCache = true
    ) {
        if ($useCache) {
            $cached = $this->db->findUriCache(
                [
                    '_id' => $id,
                    'timestamp' => [
                        '$gt' => $this->db->getTimestamp(time() - $this->maxCacheAge),
                    ],
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
        $retryWait = $this->retryWait;
        $response = null;
        for ($try = 1; $try <= $this->maxTries; $try++) {
            if (null === $this->httpClient) {
                $this->httpClient = $this->httpService->createClient(
                    $url,
                    $this->httpOptions
                );
            }

            $duration = 0;
            try {
                $startTime = microtime(true);
                $response = $this->httpClient->get($url);
                $duration = microtime(true) - $startTime;
            } catch (\Exception $e) {
                if ($try < $this->maxTries) {
                    if ($retryWait < 30) {
                        // Progressively longer delay
                        $retryWait *= 2;
                    }
                    $this->logger->logWarning(
                        'getExternalData',
                        "HTTP request for '$url' failed (" . $e->getMessage()
                            . "), retrying in {$retryWait} seconds (retry $try)..."
                    );
                    $this->httpClient = null;
                    sleep($retryWait);
                    continue;
                }
                throw HttpRequestException::fromException($e);
            }
            if ($try < $this->maxTries) {
                $code = $response->getStatusCode();
                if (
                    $code >= 300 && $code != 404 && !in_array($code, $ignoreErrors)
                ) {
                    $this->logger->logWarning(
                        'getExternalData',
                        "HTTP request for '$url' failed ($code), retrying "
                            . "in {$this->retryWait} seconds (retry $try)..."
                    );
                    $this->httpClient = null;
                    sleep($this->retryWait);
                    continue;
                }
            }
            if ($try > 1) {
                $this->logger->logWarning(
                    'getExternalData',
                    "HTTP request for '$url' completed on attempt $try"
                );
            }
            if (isset($this->requestsHandled[$host])) {
                $this->requestsHandled[$host]++;
                $this->requestsDuration[$host] += $duration;
            } else {
                $this->requestsHandled[$host] = 1;
                $this->requestsDuration[$host] = $duration;
            }
            if ($this->requestsHandled[$host] % 1000 === 0) {
                $average = floor(
                    $this->requestsDuration[$host] / $this->requestsHandled[$host]
                    * 1000
                );
                $this->logger->logInfo(
                    'getExternalData',
                    "{$this->requestsHandled[$host]} HTTP requests completed"
                        . " for $host, average time for a request $average ms"
                );
            }
            break;
        }

        $code = null === $response ? 999 : $response->getStatusCode();
        if ($code >= 300 && $code != 404 && !in_array($code, $ignoreErrors)) {
            throw new HttpRequestException(
                "Enrichment failed to fetch '$url': $code",
                $code
            );
        }

        $data = $code < 300 ? (string)$response->getBody() : '';

        if ($useCache) {
            $this->db->saveUriCache(
                [
                    '_id' => $id,
                    'timestamp' => $this->db->getTimestamp(),
                    'url' => $url,
                    'headers' => $headers,
                    'data' => $data,
                ]
            );
        }

        return $data;
    }
}

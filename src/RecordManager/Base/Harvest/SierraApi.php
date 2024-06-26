<?php

/**
 * Sierra API Harvesting Class
 *
 * PHP version 8
 *
 * Copyright (c) The National Library of Finland 2016-2024.
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

namespace RecordManager\Base\Harvest;

use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\MessageInterface;
use RecordManager\Base\Exception\HttpRequestException;

use function call_user_func;
use function in_array;
use function intval;

/**
 * SierraApi Class
 *
 * This class harvests records via the III Sierra REST API using settings from
 * datasources.ini.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class SierraApi extends AbstractBase
{
    /**
     * Client key for the Sierra API
     *
     * @var string
     */
    protected $apiKey;

    /**
     * Client secret for the Sierra API
     *
     * @var string
     */
    protected $apiSecret;

    /**
     * Sierra API version to use (default is 5, lowest supported is 3)
     *
     * @var string
     */
    protected $apiVersion;

    /**
     * Access token to the API
     *
     * @var string
     */
    protected $accessToken = null;

    /**
     * Harvesting start position
     *
     * @var int
     */
    protected $startPosition = 0;

    /**
     * Whether to request suppressed records
     *
     * @var null|boolean
     */
    protected $suppressedRecords = null;

    /**
     * Number of records to request in one query. A too high number may result in
     * error from the API or the request taking indefinitely long.
     *
     * @var int
     */
    protected $batchSize = 100;

    /**
     * Bib codes (BCODE3) suppressed from being harvested. Records having one of
     * these codes will be processed as deleted. Usually not required if
     * suppressedRecords = false.
     *
     * @var array
     */
    protected $suppressedBibCode3 = [];

    /**
     * Whether to keep existing 852 fields in the MARC records before adding new ones
     * for the item locations.
     *
     * @var bool
     */
    protected $keepExisting852Fields = false;

    /**
     * HTTP client options
     *
     * @var array
     */
    protected $httpOptions = [
        // Set a timeout since Sierra may sometimes just hang without ever returning.
        'timeout' => 600,
    ];

    /**
     * Fields to request from Sierra
     *
     * @var string
     */
    protected $harvestFields = 'default,locations,fixedFields,varFields,catalogDate';

    /**
     * Initialize harvesting
     *
     * @param string $source    Source ID
     * @param bool   $verbose   Verbose mode toggle
     * @param bool   $reharvest Whether running a reharvest
     *
     * @return void
     */
    public function init(string $source, bool $verbose, bool $reharvest): void
    {
        parent::init($source, $verbose, $reharvest);

        $settings = $this->dataSourceConfig[$source] ?? [];
        if (
            empty($settings['sierraApiKey']) || empty($settings['sierraApiSecret'])
        ) {
            throw new \Exception(
                'sierraApiKey or sierraApiSecret missing from settings'
            );
        }
        $this->apiKey = $settings['sierraApiKey'];
        $this->apiSecret = $settings['sierraApiSecret'];
        $this->suppressedRecords = $settings['suppressedRecords'] ?? null;
        $this->batchSize = $settings['batchSize'] ?? 100;
        $this->suppressedBibCode3 = explode(
            ',',
            $settings['suppressedBibCode3'] ?? ''
        );
        $this->apiVersion = 'v' . ($settings['sierraApiVersion'] ?? '6');
        $this->keepExisting852Fields = $settings['keepExisting852Fields'] ?? false;
    }

    /**
     * Override the start position.
     *
     * @param string $pos New start position
     *
     * @return void
     */
    public function setInitialPosition($pos)
    {
        $this->startPosition = intval($pos);
    }

    /**
     * Harvest all available documents.
     *
     * @param callable $callback Function to be called to store a harvested record
     *
     * @return void
     */
    public function harvest($callback)
    {
        $this->initHarvest($callback);

        $harvestStartTime = $this->getHarvestStartTime();
        $apiParams = [
            'limit' => $this->batchSize,
            'offset' => $this->startPosition,
            'fields' => $this->harvestFields,
        ];
        if (null !== $this->suppressedRecords) {
            $apiParams['suppressed'] = $this->suppressedRecords ? 'true' : 'false';
        }
        if ($this->reharvest) {
            $apiParams['deleted'] = 'false';
        }

        if (!empty($this->startDate) || !empty($this->endDate)) {
            $startDate = !empty($this->startDate) ? $this->startDate : '';
            $endDate = !empty($this->endDate) ? $this->endDate : '';
            $apiParams['updatedDate'] = "[$startDate,$endDate]";
            $this->infoMsg("Incremental harvest: $startDate-$endDate");
        } else {
            $this->infoMsg('Initial harvest for all records');
        }

        // Keep harvesting as long as a records are received:
        do {
            $response = $this->sendRequest([$this->apiVersion, 'bibs'], $apiParams);
            $count = $this->processResponse((string)$response->getBody());
            $this->reportResults();
            $apiParams['offset'] += $apiParams['limit'];
        } while ($count > 0);

        // Sierra doesn't support time portion for deleted records, and apparently
        // their updatedDate doesn't necessarily change when the record is deleted.
        // If we have a date range, harvest deleted records for the whole dates
        // separately.
        if (!empty($this->startDate) || !empty($this->endDate)) {
            $startDate = !empty($this->startDate) ? substr($this->startDate, 0, 10)
                : '';
            $endDate = !empty($this->endDate) ? substr($this->endDate, 0, 10) : '';
            unset($apiParams['updatedDate']);
            $apiParams['deletedDate'] = "[$startDate,$endDate]";
            $apiParams['offset'] = 0;
            $this->infoMsg("Incremental harvest of deletions: $startDate-$endDate");
            $this->initHarvest($callback);

            // Keep harvesting as long as a records are received:
            do {
                $response
                    = $this->sendRequest([$this->apiVersion, 'bibs'], $apiParams);
                $count = $this->processResponse((string)$response->getBody());
                $this->reportResults();
                $apiParams['offset'] += $apiParams['limit'];
            } while ($count > 0);
        }

        if (empty($this->endDate)) {
            $this->saveLastHarvestedDate(
                gmdate('Y-m-d\TH:i:s\Z', $harvestStartTime)
            );
        }
    }

    /**
     * Harvest a single record.
     *
     * @param callable $callback Function to be called to store a harvested record
     * @param string   $id       Record ID
     *
     * @return void
     */
    public function harvestSingle(callable $callback, string $id): void
    {
        $this->initHarvest($callback);

        $apiParams = [
            'limit' => $this->batchSize,
            'offset' => $this->startPosition,
            'fields' => 'id,deleted,locations,fixedFields,varFields',
            'id' => $id,
        ];
        if (null !== $this->suppressedRecords) {
            $apiParams['suppressed'] = $this->suppressedRecords ? 'true' : 'false';
        }

        $response = $this->sendRequest([$this->apiVersion, 'bibs'], $apiParams);
        $this->processResponse((string)$response->getBody());
        $this->reportResults();
    }

    /**
     * Get server date as a unix timestamp
     *
     * @return int
     */
    protected function getHarvestStartTime()
    {
        $response = $this->sendRequest([$this->apiVersion, 'info', 'token'], []);
        if ($date = $response->getHeader('Date')) {
            $date = reset($date);
            $dateTime = \DateTime::createFromFormat('D\, d M Y H:i:s O+', $date);
            if (false === $dateTime) {
                throw new \Exception("Could not parse server date header: $date");
            }
            $result = $dateTime->getTimestamp();
            $this->infoMsg(
                'Current server date: ' . gmdate('Y-m-d\TH:i:s\Z', $result)
            );
            return $result;
        }
        $result = time();
        $this->warningMsg(
            'Could not find server date, using local date: '
            . gmdate('Y-m-d\TH:i:s\Z', $result)
        );
        return $result;
    }

    /**
     * Make a request and return the response as a string
     *
     * @param array $path   Sierra API path
     * @param array $params GET parameters for the method
     *
     * @return MessageInterface
     * @throws \Exception
     * @throws GuzzleException
     */
    protected function sendRequest($path, $params): MessageInterface
    {
        // Set up the request:
        $apiUrl = $this->baseURL;

        foreach ($path as $value) {
            $apiUrl .= '/' . urlencode($value);
        }

        $client = $this->httpService->createClient($apiUrl, $this->httpOptions);
        $headers = [
            'Accept' => 'application/json',
        ];
        $url = $this->httpService->appendQueryParams($apiUrl, $params);

        if (null === $this->accessToken) {
            $this->renewAccessToken();
        }
        $headers['Authorization'] = "Bearer {$this->accessToken}";

        // Perform request and throw an exception on error:
        $maxTries = $this->maxTries;
        for ($try = 1; $try <= $maxTries; $try++) {
            $this->infoMsg("Sending request: $url");
            try {
                $response = $client->get($url, compact('headers'));
                $code = $response->getStatusCode();
                if ($code == 404) {
                    return $response;
                }
                if ($code == 401) {
                    $this->infoMsg('Renewing access token');
                    $this->renewAccessToken();
                    $headers['Authorization'] = "Bearer {$this->accessToken}";
                    ++$maxTries;
                    sleep(1);
                    continue;
                }
                if ($code >= 300) {
                    if ($try < $this->maxTries) {
                        $this->warningMsg(
                            "Request '$url' failed ($code: "
                            . (string)$response->getBody() . '), retrying in '
                            . "{$this->retryWait} seconds..."
                        );
                        sleep($this->retryWait);
                        continue;
                    }
                    $this->fatalMsg("Request '$url' failed: $code");
                    throw new HttpRequestException("Request failed: $code", $code);
                }

                return $response;
            } catch (\Exception $e) {
                if ($try < $this->maxTries) {
                    $this->warningMsg(
                        "Request '$url' failed (" . $e->getMessage()
                        . "), retrying in {$this->retryWait} seconds..."
                    );
                    sleep($this->retryWait);
                    continue;
                }
                throw HttpRequestException::fromException($e);
            }
        }
        throw new \Exception('Request failed');
    }

    /**
     * Process the API response.
     * Throw exception if an error is detected.
     *
     * @param string $response Sierra response JSON
     *
     * @return int Count of records processed
     * @throws \Exception
     */
    protected function processResponse($response)
    {
        $this->infoMsg('Processing received records');
        if (empty($response)) {
            return 0;
        }
        $json = json_decode($response, true);
        if (isset($json['ErrorCodes'])) {
            $this->errorMsg(
                'Sierra API returned error: '
                . $json['ErrorCodes']['code'] . ' ' . $json['ErrorCodes']['name']
                . ': ' . $json['ErrorCodes']['description']
            );
            throw new \Exception(
                '{$this->source}: Server returned error: '
                . $json['ErrorCodes']['code'] . ' ' . $json['ErrorCodes']['name']
                . ': ' . $json['ErrorCodes']['description']
            );
        }

        if (!isset($json['entries'])) {
            return 0;
        }

        $count = 0;
        foreach ($json['entries'] as $record) {
            ++$count;
            $id = $record['id'];
            $oaiId = $this->createOaiId($this->source, $id);
            $deleted = $this->isDeleted($record);
            if ($deleted) {
                call_user_func($this->callback, $this->source, $oaiId, true, null, $this->getExtraMetadata($record));
                $this->deletedRecords++;
            } else {
                $this->changedRecords += call_user_func(
                    $this->callback,
                    $this->source,
                    $oaiId,
                    false,
                    $this->convertRecordToMarcArray($record),
                    $this->getExtraMetadata($record)
                );
            }
        }
        return $count;
    }

    /**
     * Renew the access token. Throw an exception if there is an error.
     *
     * @return void
     * @throws \Exception
     * @throws GuzzleException
     */
    protected function renewAccessToken()
    {
        // Set up the request:
        $apiUrl = $this->baseURL . '/' . $this->apiVersion . '/token';
        $client = $this->httpService->createClient($apiUrl);
        $headers = [
            'Accept' => 'application/json',
            'Authorization' => 'Basic ' . base64_encode("{$this->apiKey}:{$this->apiSecret}"),
        ];

        // Perform request and throw an exception on error:
        for ($try = 1; $try <= $this->maxTries; $try++) {
            $this->infoMsg("Sending request: $apiUrl");
            try {
                $response = $client->post($apiUrl, ['headers' => $headers, 'body' => 'grant_type=client_credentials']);
                $code = $response->getStatusCode();
                if ($code >= 300) {
                    if ($try < $this->maxTries) {
                        $this->warningMsg(
                            "Request '$apiUrl' failed ($code: "
                            . (string)$response->getBody() . '), retrying in'
                            . " {$this->retryWait} seconds..."
                        );
                        sleep($this->retryWait);
                        continue;
                    }
                    $this->fatalMsg(
                        "Request '$apiUrl' failed ($code: " . (string)$response->getBody()
                        . ')'
                    );
                    throw new HttpRequestException(
                        "Access token request failed: $code",
                        $code
                    );
                }

                $json = json_decode((string)$response->getBody(), true);
                if (empty($json['access_token'])) {
                    throw new \Exception(
                        'No access token in response: ' . (string)$response->getBody()
                    );
                }
                $this->accessToken = $json['access_token'];
                break;
            } catch (\Exception $e) {
                if ($try < $this->maxTries) {
                    $this->warningMsg(
                        "Request '$apiUrl' failed (" . $e->getMessage()
                        . "), retrying in {$this->retryWait} seconds..."
                    );
                    sleep($this->retryWait);
                    continue;
                }
                throw HttpRequestException::fromException($e);
            }
        }
    }

    /**
     * Create an OAI style ID
     *
     * @param string $sourceId Source ID
     * @param string $id       Record ID
     *
     * @return string OAI ID
     */
    protected function createOaiId($sourceId, $id)
    {
        return "sierra:$sourceId:$id";
    }

    /**
     * Convert Sierra record to MARC-in-JSON -style array format
     *
     * @param array $record Sierra BIB record varFields
     *
     * @return array
     */
    protected function convertRecordToMarcArray($record)
    {
        $id = $record['id'];
        $marc = [];
        $marc['fields'][] = ['001' => $id];
        foreach ($record['varFields'] as $varField) {
            if ($varField['fieldTag'] == '_') {
                $marc['leader'] = $varField['content'];
                continue;
            }
            if (
                !isset($varField['marcTag'])
                || (!$this->keepExisting852Fields && $varField['marcTag'] == '852')
            ) {
                continue;
            }
            // Make sure the tag has three characters
            $marcTag = substr('000' . trim($varField['marcTag']), -3);
            if (isset($varField['subfields'])) {
                if ($marcTag >= 10) {
                    $subfields = [];
                    foreach ($varField['subfields'] as $subfield) {
                        $subfields[] = [
                            $subfield['tag'] => $subfield['content'],
                        ];
                    }
                    $marc['fields'][] = [
                        (string)$marcTag => [
                            'ind1' => $varField['ind1'],
                            'ind2' => $varField['ind2'],
                            'subfields' => $subfields,
                        ],
                    ];
                }
            } else {
                $marc['fields'][] = [(string)$marcTag => $varField['content']];
            }
        }

        if (!empty($record['locations'])) {
            foreach ($record['locations'] as $location) {
                $marc['fields'][] = [
                    '852' => [
                        'ind1' => ' ',
                        'ind2' => ' ',
                        'subfields' => [
                            ['b' => $location['code']],
                        ],
                    ],
                ];
            }
        }

        if (isset($record['fixedFields']['30']['value'])) {
            $marc['fields'][] = [
                '977' => [
                    'ind1' => ' ',
                    'ind2' => ' ',
                    'subfields' => [
                        ['a' => trim($record['fixedFields']['30']['value'])],
                    ],
                ],
            ];
        }

        if (empty($marc['leader'])) {
            $this->warningMsg("No leader found for record $id in {$this->source}");
            $marc['leader'] = '00000nam  2200000   4500';
        }

        uasort(
            $marc['fields'],
            function ($a, $b) {
                return key($a) <=> key($b);
            }
        );

        return $marc;
    }

    /**
     * Get any extra metadata from the Sierra record
     *
     * @param array $record Sierra BIB record
     *
     * @return array
     */
    protected function getExtraMetadata(array $record): array
    {
        $catalogDate = $record['catalogDate'] ?? null;
        return $catalogDate ? compact('catalogDate') : [];
    }

    /**
     * Check if the record is deleted.
     *
     * @param array $record Sierra Bib Record
     *
     * @return bool
     */
    protected function isDeleted($record)
    {
        if ($record['deleted']) {
            return true;
        }
        if (isset($record['fixedFields']['31'])) {
            $suppressed = in_array(
                $record['fixedFields']['31']['value'],
                $this->suppressedBibCode3
            );
            if ($suppressed) {
                return true;
            }
        }
        return false;
    }

    /**
     * Report the results of harvesting
     *
     * @return void
     */
    protected function reportResults()
    {
        $this->infoMsg(
            'Harvested ' . $this->changedRecords . ' normal and '
            . $this->deletedRecords . ' deleted records'
        );
    }
}

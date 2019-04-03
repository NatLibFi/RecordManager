<?php
/**
 * Sierra API Harvesting Class
 *
 * PHP version 5
 *
 * Copyright (c) The National Library of Finland 2016-2017.
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
use RecordManager\Base\Utils\Logger;

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
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class SierraApi extends Base
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
     * Constructor.
     *
     * @param Database $db       Database
     * @param Logger   $logger   The Logger object used for logging messages
     * @param string   $source   The data source to be harvested
     * @param string   $basePath RecordManager main directory location
     * @param array    $config   Main configuration
     * @param array    $settings Settings from datasources.ini
     *
     * @throws Exception
     */
    public function __construct(Database $db, Logger $logger, $source, $basePath,
        $config, $settings
    ) {
        parent::__construct($db, $logger, $source, $basePath, $config, $settings);

        if (empty($settings['sierraApiKey'])
            || empty($settings['sierraApiSecret'])
        ) {
            throw new \Exception(
                "sierraApiKey or sierraApiSecret missing from settings of '$source'"
            );
        }
        $this->apiKey = $settings['sierraApiKey'];
        $this->apiSecret = $settings['sierraApiSecret'];
        if (isset($settings['suppressedRecords'])) {
            $this->suppressedRecords = $settings['suppressedRecords'];
        }
        if (isset($settings['batchSize'])) {
            $this->batchSize = $settings['batchSize'];
        }
        if (isset($settings['suppressedBibCode3'])) {
            $this->suppressedBibCode3 = explode(
                ',', $settings['suppressedBibCode3']
            );
        }

        // Set a timeout since Sierra may sometimes just hang without ever returning.
        $this->httpParams['timeout'] = 600;
    }

    /**
     * Override the start position.
     *
     * @param int $pos New start position
     *
     * @return void
     */
    public function setStartPos($pos)
    {
        $this->startPosition = $pos;
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
            'fields' => 'id,deleted,locations,fixedFields,varFields'
        ];
        if (null !== $this->suppressedRecords) {
            $apiParams['suppressed'] = $this->suppressedRecords ? 'true' : 'false';
        }

        if (!empty($this->startDate) || !empty($this->endDate)) {
            $startDate = !empty($this->startDate) ? $this->startDate : '';
            $endDate = !empty($this->endDate) ? $this->endDate : '';
            $apiParams['updatedDate'] = "[$startDate,$endDate]";
            $this->message("Incremental harvest: $startDate-$endDate");
        } else {
            $this->message('Initial harvest for all records');
        }

        // Keep harvesting as long as a records are received:
        do {
            $response = $this->sendRequest(['v3', 'bibs'], $apiParams);
            $count = $this->processResponse($response->getBody());
            $this->reportResults();
            $apiParams['offset'] += $apiParams['limit'];
        } while ($count > 0);

        if (empty($this->endDate)) {
            $this->saveLastHarvestedDate(
                gmdate('Y-m-d\TH:i:s\Z', $harvestStartTime)
            );
        }
    }

    /**
     * Get server date as a unix timestamp
     *
     * @return int
     */
    protected function getHarvestStartTime()
    {
        $response = $this->sendRequest(['v3', 'info', 'token'], []);
        if ($date = $response->getHeader('Date')) {
            $dateTime = \DateTime::createFromFormat('D\, d M Y H:i:s O+', $date);
            if (false === $dateTime) {
                throw new \Exception("Could not parse server date header: $date");
            }
            $result = $dateTime->getTimestamp();
            $this->message(
                'Current server date: ' . gmdate('Y-m-d\TH:i:s\Z', $result)
            );
            return $result;
        }
        $result = time();
        $this->message(
            'Could not find server date, using local date: '
            . gmdate('Y-m-d\TH:i:s\Z', $result),
            false,
            Logger::WARNING
        );
        return $result;
    }

    /**
     * Make a request and return the response as a string
     *
     * @param array $path   Sierra API path
     * @param array $params GET parameters for the method
     *
     * @return \HTTP_Request2_Response
     * @throws \Exception
     * @throws \HTTP_Request2_LogicException
     */
    protected function sendRequest($path, $params)
    {
        // Set up the request:
        $apiUrl = $this->baseURL;

        foreach ($path as $value) {
            $apiUrl .= '/' . urlencode($value);
        }

        $request = new \HTTP_Request2(
            $apiUrl,
            \HTTP_Request2::METHOD_GET,
            $this->httpParams
        );
        $request->setHeader('User-Agent', 'RecordManager');
        $request->setHeader('Accept', 'application/json');

        // Load request parameters:
        $url = $request->getURL();
        $url->setQueryVariables($params);
        $urlStr = $url->getURL();

        if (null === $this->accessToken) {
            $this->renewAccessToken();
        }
        $request->setHeader(
            'Authorization', "Bearer {$this->accessToken}"
        );

        // Perform request and throw an exception on error:
        $maxTries = $this->maxTries;
        for ($try = 1; $try <= $maxTries; $try++) {
            $this->message("Sending request: $urlStr", true);
            try {
                $response = $request->send();
                $code = $response->getStatus();
                if ($code == 404) {
                    return $response;
                }
                if ($code == 401) {
                    $this->message('Renewing access token');
                    $this->renewAccessToken();
                    $request->setHeader(
                        'Authorization', "Bearer {$this->accessToken}"
                    );
                    ++$maxTries;
                    sleep(1);
                    continue;
                }
                if ($code >= 300) {
                    if ($try < $this->maxTries) {
                        $this->message(
                            "Request '$urlStr' failed ($code: "
                            . $response->getBody() . '), retrying in '
                            . "{$this->retryWait} seconds...",
                            false,
                            Logger::WARNING
                        );
                        sleep($this->retryWait);
                        continue;
                    }
                    $this->message(
                        "Request '$urlStr' failed: $code", false, Logger::FATAL
                    );
                    throw new \Exception("{$this->source}: Request failed: $code");
                }

                return $response;
            } catch (\Exception $e) {
                if ($try < $this->maxTries) {
                    $this->message(
                        "Request '$urlStr' failed (" . $e->getMessage()
                        . "), retrying in {$this->retryWait} seconds...",
                        false,
                        Logger::WARNING
                    );
                    sleep($this->retryWait);
                    continue;
                }
                throw new \Exception("{$this->source}: " . $e->getMessage());
            }
        }
        throw new \Exception("{$this->source}: Request failed");
    }

    /**
     * Process the API response.
     * Throw exception if an error is detected.
     *
     * @param string $response Sierra response JSON
     *
     * @return int Count of records processed
     * @throws Exception
     */
    protected function processResponse($response)
    {
        $this->message('Processing received records', true);
        if (empty($response)) {
            return 0;
        }
        $json = json_decode($response, true);
        if (isset($json['ErrorCodes'])) {
            $this->message(
                'Sierra API returned error: '
                . $json['ErrorCodes']['code'] . ' ' . $json['ErrorCodes']['name']
                . ': ' . $json['ErrorCodes']['description'],
                false,
                Logger::ERROR
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
                call_user_func($this->callback, $this->source, $oaiId, true, null);
                $this->deletedRecords++;
            } else {
                $this->changedRecords += call_user_func(
                    $this->callback,
                    $this->source,
                    $oaiId,
                    false,
                    $this->convertRecordToMarcArray($record)
                );
            }
        }
        return $count;
    }

    /**
     * Renew the access token. Throw an exception if there is an error.
     *
     * @return void
     * @throws Exception
     * @throws \HTTP_Request2_LogicException
     */
    protected function renewAccessToken()
    {
        // Set up the request:
        $apiUrl = $this->baseURL . '/v3/token';
        $request = new \HTTP_Request2(
            $apiUrl,
            \HTTP_Request2::METHOD_POST,
            $this->httpParams
        );
        $request->setHeader('User-Agent', 'RecordManager');
        $request->setHeader('Accept', 'application/json');
        $request->setHeader(
            'Authorization',
            'Basic ' . base64_encode("{$this->apiKey}:{$this->apiSecret}")
        );
        $request->setBody('grant_type=client_credentials');

        // Perform request and throw an exception on error:
        for ($try = 1; $try <= $this->maxTries; $try++) {
            $this->message("Sending request: $apiUrl", true);
            try {
                $response = $request->send();
                $code = $response->getStatus();
                if ($code >= 300) {
                    if ($try < $this->maxTries) {
                        $this->message(
                            "Request '$apiUrl' failed ($code: "
                            . $response->getBody() . '), retrying in'
                            . " {$this->retryWait} seconds...",
                            false,
                            Logger::WARNING
                        );
                        sleep($this->retryWait);
                        continue;
                    }
                    $this->message(
                        "Request '$apiUrl' failed ($code: " . $response->getBody()
                        . ')', false, Logger::FATAL
                    );
                    throw new \Exception("{$this->source}: Request failed: $code");
                }

                $json = json_decode($response->getBody(), true);
                if (empty($json['access_token'])) {
                    throw new \Exception(
                        'No access token in response: ' . $response->getBody()
                    );
                }
                $this->accessToken = $json['access_token'];
                break;
            } catch (\Exception $e) {
                if ($try < $this->maxTries) {
                    $this->message(
                        "Request '$apiUrl' failed (" . $e->getMessage()
                        . "), retrying in {$this->retryWait} seconds...",
                        false,
                        Logger::WARNING
                    );
                    sleep($this->retryWait);
                    continue;
                }
                throw new \Exception("{$this->source}: " . $e->getMessage());
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
     * Convert Sierra record to our internal MARC array format
     *
     * @param array $record Sierra BIB record varFields
     *
     * @return array
     */
    protected function convertRecordToMarcArray($record)
    {
        $id = $record['id'];
        $marc = [];
        foreach ($record['varFields'] as $varField) {
            if ($varField['fieldTag'] == '_') {
                $marc['000'] = $varField['content'];
                continue;
            }
            if (!isset($varField['marcTag']) || $varField['marcTag'] == '852') {
                continue;
            }
            // Make sure the tag has three characters
            $marcTag = substr('000' . trim($varField['marcTag']), -3);
            if (isset($varField['subfields'])) {
                if ($marcTag >= 10) {
                    $subfields = [];
                    foreach ($varField['subfields'] as $subfield) {
                        $subfields[] = [
                            $subfield['tag'] => $subfield['content']
                        ];
                    }
                    $marc[$marcTag][] = [
                        'i1' => $varField['ind1'],
                        'i2' => $varField['ind2'],
                        's' => $subfields
                    ];
                }
            } else {
                $marc[$marcTag][] = $varField['content'];
            }
        }

        if (isset($record['fixedFields']['30']['value'])) {
            $marc['977'][] = [
                'i1' => ' ',
                'i2' => ' ',
                's' => [
                    ['a' => trim($record['fixedFields']['30']['value'])]
                ]
            ];
        }

        if (!empty($record['locations'])) {
            foreach ($record['locations'] as $location) {
                $marc['852'][] = [
                    'i1' => ' ',
                    'i2' => ' ',
                    's' => [
                        ['b' => $location['code']]
                    ]
                ];
            }
        }

        $marc['001'] = [$id];

        if (empty($marc['000'])) {
            $this->log->log(
                'convertVarFieldsToMarcArray',
                "No leader found for record $id in {$this->source}",
                Logger::WARNING
            );
            $marc['000'] = '00000nam  2200000   4500';
        }

        ksort($marc);

        return ['v' => 3, 'f' => $marc];
    }

    /**
     * Check if the record is deleted.
     * This implementation works for MARC records.
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
                $record['fixedFields']['31']['value'], $this->suppressedBibCode3
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
        $this->message(
            'Harvested ' . $this->changedRecords . ' normal and '
            . $this->deletedRecords . ' deleted records'
        );
    }
}

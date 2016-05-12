<?php
/**
 * OAI-PMH Harvesting Class
 *
 * PHP version 5
 *
 * Copyright (c) The National Library of Finland 2016.
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
require_once 'BaseHarvest.php';

/**
 * HarvestOaiPmh Class
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
class HarvestSierraApi extends BaseHarvest
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
     * Constructor.
     *
     * @param object $logger   The Logger object used for logging messages.
     * @param object $db       Mongo database handle.
     * @param string $source   The data source to be harvested.
     * @param string $basePath RecordManager main directory location.
     * @param array  $settings Settings from datasources.ini.
     * @param int    $startPos Optional harvesting start position.
     *
     * @throws Exception
     */
    public function __construct($logger, $db, $source, $basePath, $settings,
        $startPos = 0
    ) {
        parent::__construct($logger, $db, $source, $basePath, $settings);

        if (empty($settings['sierraApiKey'])
            || empty($settings['sierraApiSecret'])
        ) {
            throw new \Exception(
                "sierraApiKey or sierraApiSecret missing from settings of '$source'"
            );
        }
        $this->apiKey = $settings['sierraApiKey'];
        $this->apiSecret = $settings['sierraApiSecret'];
        $this->startPosition = $startPos;
        if (isset($settings['suppressedRecords'])) {
            $this->suppressedRecords = $settings['suppressedRecords'];
        }
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

        $harvestStartTime = time();
        $apiParams = [
            'limit' => 500,
            'offset' => $this->startPosition,
            'fields' => 'deleted,marc,locations',
        ];
        if (null !== $this->suppressedRecords) {
            $apiParams['suppressed'] = $this->suppressedRecords ? 'true' : 'false';
        }

        if (!empty($this->startDate) || !empty($this->endDate)) {
            $startDate = !empty($this->startDate) ? $this->startDate : '';
            $endDate = !empty($this->endDate) ? $this->endDate : '';
            $apiParams['updatedDate'] = "[$startDate,$endDate]";
        }

        // Keep harvesting as long as a records are received:
        do {
            $response = $this->sendRequest('bibs/', $apiParams);
            $count = $this->processResponse($response);
            $this->reportResults();
            $apiParams['offset'] += $apiParams['limit'];
        } while ($count > 0);
        if (!empty($this->endDate)) {
            $this->saveLastHarvestedDate(date($dateFormat, $harvestStartTime));
        }
    }

    /**
     * Make a request and return the response as a string
     *
     * @param string $path   Sierra API path
     * @param array  $params GET parameters for the method
     *
     * @return string
     * @throws Exception
     * @throws HTTP_Request2_LogicException
     */
    protected function sendRequest($path, $params)
    {
        // Set up the request:
        $apiUrl = $this->baseURL;
        if (substr($apiUrl, -1) != '/') {
            $apiUrl .= '/';
        }
        $apiUrl .= $path;
        $request = new HTTP_Request2(
            $apiUrl,
            HTTP_Request2::METHOD_GET,
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
                    return '';
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
                    throw new Exception("{$this->source}: Request failed: $code");
                }

                return $response->getBody();
            } catch (Exception $e) {
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
                throw new Exception("{$this->source}: " . $e->getMessage());
            }
        }
        throw new Exception("{$this->source}: Request failed");
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
            throw new Exception('Server returned error: '
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
            $deleted = $record['deleted'];
            if ($deleted) {
                call_user_func($this->callback, $oaiId, true, null);
                $this->deletedRecords++;
            } else {
                $marc = $this->convertRecordToMarcArray($record);
                $this->changedRecords += call_user_func(
                    $this->callback,
                    $oaiId,
                    false,
                    $marc
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
     * @throws HTTP_Request2_LogicException
     */
    protected function renewAccessToken()
    {
        // Set up the request:
        $apiUrl = $this->baseURL;
        if (substr($apiUrl, -1) != '/') {
            $apiUrl .= '/';
        }
        $apiUrl .= 'token';
        $request = new HTTP_Request2(
            $apiUrl,
            HTTP_Request2::METHOD_POST,
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
                    throw new Exception("{$this->source}: Request failed: $code");
                }

                $json = json_decode($response->getBody(), true);
                $this->accessToken = $json['access_token'];
                break;
            } catch (Exception $e) {
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
                throw new Exception("{$this->source}: " . $e->getMessage());
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
     * Convert Sierra MARC JSON to our internal MARC array format
     *
     * @param array $record Sierra record
     *
     * @return array
     */
    protected function convertRecordToMarcArray($record)
    {
        $json = $record['marc'];
        $marc = [];
        $marc['000'] = $json['leader'];
        foreach ($json['fields'] as $field) {
            if (isset($field['data'])) {
                $data = $field['data'];
                $newField = [
                    'i1' => $data['ind1'],
                    'i2' => $data['ind2'],
                    's' => []
                ];
                foreach ($data['subfields'] as $subfield) {
                    $newField['s'][] = [
                        $subfield['code'] => $subfield['data']
                    ];
                }
                $marc[$field['tag']][] = $newField;
            } elseif ($field['tag'] !== '000') {
                $marc[$field['tag']][] = $field['value'];
            }
        }
        $marc['001'] = [$record['id']];

        unset($marc['852']);
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

        return ['v' => 3, 'f' => $marc];
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

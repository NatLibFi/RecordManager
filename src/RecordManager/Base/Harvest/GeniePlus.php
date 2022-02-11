<?php
/**
 * GeniePlus API Harvesting Class
 *
 * PHP version 7
 *
 * Copyright (c) Villanova University 2022.
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
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
namespace RecordManager\Base\Harvest;

use RecordManager\Base\Utils\LineBasedMarcFormatter;

/**
 * GeniePlus Class
 *
 * This class harvests records via the GeniePlus REST API using settings from
 * datasources.ini.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class GeniePlus extends AbstractBase
{
    /**
     * Database name for the API
     *
     * @var string
     */
    protected $database;

    /**
     * Template containing MARC records
     *
     * @var string
     */
    protected $template;

    /**
     * OAuth ID for the API
     *
     * @var string
     */
    protected $oauthId;

    /**
     * Username for the API
     *
     * @var string
     */
    protected $username;

    /**
     * Password for the API
     *
     * @var string
     */
    protected $password;

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
     * Number of records to request in one query. A too high number may result in
     * error from the API or the request taking indefinitely long.
     *
     * @var int
     */
    protected $batchSize = 100;

    /**
     * Database field name for unique record ID.
     *
     * @var string
     */
    protected $idField;

    /**
     * Database field name for MARC record.
     *
     * @var string
     */
    protected $marcField;

    /**
     * Database field name for item location.
     *
     * @var string
     */
    protected $locationField;

    /**
     * Database field name for item sublocation.
     *
     * @var string
     */
    protected $sublocationField;

    /**
     * Database field name for item call number.
     *
     * @var string
     */
    protected $callnumberField;

    /**
     * Database field name for item barcode.
     *
     * @var string
     */
    protected $barcodeField;

    /**
     * MARC field to output unique ID into.
     *
     * @var int
     */
    protected $uniqueIdOutputField;

    /**
     * MARC subfield to output unique ID into.
     *
     * @var int
     */
    protected $uniqueIdOutputSubfield;

    /**
     * Item limit per location group.
     *
     * @var int
     */
    protected $itemLimitPerLocationGroup = -1;

    /**
     * HTTP client options
     *
     * @var array
     */
    protected $httpOptions = [
        'timeout' => 600
    ];

    /**
     * Helper for converting line-based MARC to XML.
     *
     * @var LineBasedMarcFormatter
     */
    protected $lineBasedFormatter;

    /**
     * Constructor
     */
    public function __construct()
    {
        parent::__construct(...func_get_args());
        $this->lineBasedFormatter = new LineBasedMarcFormatter(
            [
                [
                    'subfieldRegExp' => '/‡([a-z0-9])/',
                    'endOfLineMarker' => '^',
                    'ind1Offset' => 3,
                    'ind2Offset' => 4,
                    'contentOffset' => 4,
                    'firstSubfieldOffset' => 5,
                ],
            ]
        );
    }

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
        if (empty($settings['geniePlusDatabase'])
            || empty($settings['geniePlusOauthId'])
            || empty($settings['geniePlusUsername'])
            || empty($settings['geniePlusPassword'])
        ) {
            throw new \Exception(
                'Required GeniePlus setting missing from settings'
            );
        }
        $this->database = $settings['geniePlusDatabase'];
        $this->template = $settings['geniePlusTemplate'] ?? 'Catalog';
        $this->oauthId = $settings['geniePlusOauthId'];
        $this->username = $settings['geniePlusUsername'];
        $this->password = $settings['geniePlusPassword'];
        $this->batchSize = $settings['batchSize'] ?? 100;
        $this->idField = $settings['geniePlusIdField'] ?? 'UniqRecNum';
        $this->marcField = $settings['geniePlusMarcField'] ?? 'MarcRecord';
        $this->locationField = $settings['geniePlusLocationField']
            ?? 'Inventory.Location.CodeDesc';
        $this->sublocationField = $settings['geniePlusSublocationField']
            ?? 'Inventory.SubLoc.CodeDesc';
        $this->callnumberField = $settings['geniePlusCallnumberField']
            ?? 'Inventory.CallNumLC';
        $this->barcodeField = $settings['geniePlusBarcodeField']
            ?? 'Inventory.Barcode';
        $this->uniqueIdOutputField
            = $settings['geniePlusUniqueIdOutputField'] ?? 999;
        $this->uniqueIdOutputSubfield
            = $settings['geniePlusUniqueIdOutputSubfield'] ?? 'c';
        $this->itemLimitPerLocationGroup
            = $settings['geniePlusItemLimitPerLocationGroup'] ?? -1;
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
     * Reformat a date for use in API queries
     *
     * @param string $date Date in YYYY-MM-DD format
     *
     * @return string      Date in MM/DD/YYYY format.
     */
    protected function reformatDate($date)
    {
        return date('n/j/Y', strtotime($date));
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
        $fields = [
            $this->idField,
            $this->marcField,
            $this->locationField,
            $this->sublocationField,
            $this->callnumberField,
            $this->barcodeField,
        ];
        $apiParams = [
            'page-size' => $this->batchSize,
            'page' => floor($this->startPosition / $this->batchSize),
            'fields' => implode(',', $fields),
            'command' => "DtTmModifd > '1/1/1980 1:00:00 PM' sortby DtTmModifd"
        ];

        if (!empty($this->startDate) || !empty($this->endDate)) {
            $clauses = [];
            if (!empty($this->startDate)) {
                $startDate = $this->reformatDate($this->startDate);
                $clauses[] = "DtTmModifd >= '$startDate'";
            }
            if (!empty($this->endDate)) {
                $endDate = $this->reformatDate($this->endDate);
                $clauses[] = "DtTmModifd <= '$endDate'";
            }
            $apiParams['command'] = implode(' AND ', $clauses)
                . ' sortby DtTmModifd';
            $this->infoMsg($apiParams['command']);
            $this->infoMsg(
                "Incremental harvest: {$this->startDate}-{$this->endDate}"
            );
        } else {
            $this->infoMsg('Initial harvest for all records');
        }

        // Keep harvesting as long as a records are received:
        do {
            $response = $this->sendRequest(
                [
                    '_rest',
                    'databases',
                    $this->database,
                    'templates',
                    $this->template,
                    'search-result',
                ],
                $apiParams
            );
            $count = $this->processResponse($response->getBody());
            $this->reportResults();
            $apiParams['page']++;
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
        $result = time();
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

        $request = $this->httpClientManager->createClient(
            $apiUrl,
            \HTTP_Request2::METHOD_GET,
            $this->httpOptions
        );
        $request->setHeader('Accept', 'application/json');

        // Load request parameters:
        $url = $request->getURL();
        $url->setQueryVariables($params);
        $urlStr = $url->getURL();

        if (null === $this->accessToken) {
            $this->renewAccessToken();
        }
        $request->setHeader(
            'Authorization',
            "Bearer {$this->accessToken}"
        );

        // Perform request and throw an exception on error:
        $maxTries = $this->maxTries;
        for ($try = 1; $try <= $maxTries; $try++) {
            $this->infoMsg("Sending request: $urlStr");
            try {
                $response = $request->send();
                $code = $response->getStatus();
                if ($code == 404) {
                    return $response;
                }
                if ($code == 401) {
                    $this->infoMsg('Renewing access token');
                    $this->renewAccessToken();
                    $request->setHeader(
                        'Authorization',
                        "Bearer {$this->accessToken}"
                    );
                    ++$maxTries;
                    sleep(1);
                    continue;
                }
                if ($code >= 300) {
                    if ($try < $this->maxTries) {
                        $this->warningMsg(
                            "Request '$urlStr' failed ($code: "
                            . $response->getBody() . '), retrying in '
                            . "{$this->retryWait} seconds..."
                        );
                        sleep($this->retryWait);
                        continue;
                    }
                    $this->fatalMsg("Request '$urlStr' failed: $code");
                    throw new \Exception("Request failed: $code");
                }

                return $response;
            } catch (\Exception $e) {
                if ($try < $this->maxTries) {
                    $this->warningMsg(
                        "Request '$urlStr' failed (" . $e->getMessage()
                        . "), retrying in {$this->retryWait} seconds..."
                    );
                    sleep($this->retryWait);
                    continue;
                }
                throw $e;
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
        if (!isset($json['total'])) {
            throw new \Exception("Total missing from response; unexpected format!");
        }
        if (!isset($json['records'])) {
            return 0;
        }

        $count = 0;
        foreach ($json['records'] as $record) {
            ++$count;
            if (!isset($record[$this->idField][0]['display'])) {
                throw new \Exception("Missing ID field: {$this->idField}");
            }
            $id = $record[$this->idField][0]['display'];
            $oaiId = $this->createOaiId($this->source, $id);
            $this->changedRecords += call_user_func(
                $this->callback,
                $this->source,
                $oaiId,
                false,
                $this->processMarcRecord($record)
            );
        }
        return $count;
    }

    /**
     * Renew the access token. Throw an exception if there is an error.
     *
     * @return void
     * @throws \Exception
     * @throws \HTTP_Request2_LogicException
     */
    protected function renewAccessToken()
    {
        // Set up the request:
        $apiUrl = $this->baseURL . '/_oauth/token';
        $request = $this->httpClientManager->createClient(
            $apiUrl,
            \HTTP_Request2::METHOD_POST
        );
        $request->setHeader('Accept', 'application/json');
        $params = [
            'client_id' => $this->oauthId,
            'grant_type' => 'password',
            'database' => $this->database,
            'username' => $this->username,
            'password' => $this->password,
        ];
        $request->setBody(http_build_query($params));

        // Perform request and throw an exception on error:
        for ($try = 1; $try <= $this->maxTries; $try++) {
            $this->infoMsg("Sending request: $apiUrl");
            try {
                $response = $request->send();
                $code = $response->getStatus();
                if ($code >= 300) {
                    if ($try < $this->maxTries) {
                        $this->warningMsg(
                            "Request '$apiUrl' failed ($code: "
                            . $response->getBody() . '), retrying in'
                            . " {$this->retryWait} seconds..."
                        );
                        sleep($this->retryWait);
                        continue;
                    }
                    $this->fatalMsg(
                        "Request '$apiUrl' failed ($code: " . $response->getBody()
                        . ')'
                    );
                    throw new \Exception("Access token request failed: $code");
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
                    $this->warningMsg(
                        "Request '$apiUrl' failed (" . $e->getMessage()
                        . "), retrying in {$this->retryWait} seconds..."
                    );
                    sleep($this->retryWait);
                    continue;
                }
                throw $e;
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
        return "genieplus:$sourceId:$id";
    }

    /**
     * Extract display values from an API response field.
     *
     * @param array $field Array of values from API
     *
     * @return array
     */
    protected function extractDisplayValues($field): array
    {
        $callback = function ($value) {
            return $value['display'];
        };
        return array_map($callback, $field);
    }

    /**
     * Given independent arrays of locations, sublocations, call numbers and
     * barcodes, create an array of arrays of holdings data, grouped by the
     * location-sublocation-callnumber key.
     *
     * @param string[] $locations    Location data
     * @param string[] $sublocations Sublocation data
     * @param string[] $callNos      Call number data
     * @param string[] $barcodes     Barcode data
     *
     * @return array
     */
    protected function getGroupedHoldingsData(
        array $locations,
        array $sublocations,
        array $callNos,
        array $barcodes
    ): array {
        // Figure out how many iterations are needed to group everything:
        $total = max(
            [
                count($locations),
                count($sublocations),
                count($callNos),
                count($barcodes),
            ]
        );
        // Create a collection of location-sublocation-callnumber groups:
        $groups = [];
        for ($i = 0; $i < $total; $i++) {
            $location = $locations[$i] ?? '';
            $sublocation = $sublocations[$i] ?? '';
            $callNo = $callNos[$i] ?? '';
            $barcode = $barcodes[$i] ?? '';
            $groupKey = implode('-', [$location, $sublocation, $callNo]);
            // If everything is empty, we should skip this one:
            if (empty($barcode) && $groupKey === '--') {
                continue;
            }
            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [];
            }
            $groups[$groupKey][] = [
                'a' => $location,
                'b' => $sublocation,
                'h' => $callNo,
                'p' => $barcode,
            ];
        }
        return $groups;
    }

    /**
     * Extract holdings data from an API response. Return an array of arrays
     * representing 852 fields (indexed by subfield code).
     *
     * @param array $record Record from API response
     *
     * @return array
     */
    protected function getHoldings(array $record): array
    {
        // Special case: short circuit if disabled:
        if ($this->itemLimitPerLocationGroup === 0) {
            return [];
        }

        // Extract all the details from the record and group the data:
        $groups = $this->getGroupedHoldingsData(
            $this->extractDisplayValues($record[$this->locationField] ?? []),
            $this->extractDisplayValues($record[$this->sublocationField] ?? []),
            $this->extractDisplayValues($record[$this->callnumberField] ?? []),
            $this->extractDisplayValues($record[$this->barcodeField] ?? [])
        );

        // Now create a result set by applying the per-group limit to the groups:
        $result = [];
        foreach ($groups as $group) {
            // Negative number means "keep everything"
            if ($this->itemLimitPerLocationGroup < 0) {
                $result = array_merge($result, $group);
            } else {
                $result = array_merge(
                    $result,
                    array_slice($group, 0, $this->itemLimitPerLocationGroup)
                );
            }
        }
        return $result;
    }

    /**
     * Extract/format MARC record found in API response
     *
     * @param array $record GeniePlus record from API response
     *
     * @return array
     */
    protected function processMarcRecord($record)
    {
        if (!isset($record[$this->marcField][0]['display'])) {
            throw new \Exception("Missing MARC field: {$this->marcField}");
        }
        // Extract MARC and ID from API response
        $marc = $record[$this->marcField][0]['display'];
        $id = $record[$this->idField][0]['display'];

        // Convert to XML (and strip any illegal characters that slipped through)
        $rawXml = $this->lineBasedFormatter->convertLineBasedMarcToXml($marc);
        $replacementCount = $this->lineBasedFormatter->getIllegalXmlCharacterCount();
        if ($replacementCount > 0) {
            $this->warningMsg("Replaced $replacementCount bad chars in record $id");
        }
        $xml = simplexml_load_string($rawXml);
        if (!$xml) {
            throw new \Exception("Problem processing MARC record $id");
        }

        // Inject unique GeniePlus record ID
        $field = $xml->addChild('datafield');
        $field->addAttribute('tag', $this->uniqueIdOutputField);
        $field->addAttribute('ind1', ' ');
        $field->addAttribute('ind2', ' ');
        $sub = $field->addChild('subfield', htmlspecialchars($id, ENT_NOQUOTES));
        $sub->addAttribute('code', $this->uniqueIdOutputSubfield);

        // Inject holdings data
        foreach ($this->getHoldings($record) as $holding) {
            $field = $xml->addChild('datafield');
            $field->addAttribute('tag', '852');
            $field->addAttribute('ind1', ' ');
            $field->addAttribute('ind2', ' ');
            foreach ($holding as $code => $value) {
                $sub = $field
                    ->addChild('subfield', htmlspecialchars($value, ENT_NOQUOTES));
                $sub->addAttribute('code', $code);
            }
        }

        return $xml->asXML();
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

<?php

/**
 * OAI-PMH Harvesting Class
 *
 * Based on harvest-oai.php in VuFind
 *
 * PHP version 8
 *
 * Copyright (c) Demian Katz 2010.
 * Copyright (c) The National Library of Finland 2011-2021.
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
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */

namespace RecordManager\Base\Harvest;

use RecordManager\Base\Exception\HttpRequestException;

/**
 * OaiPmh Class
 *
 * This class harvests records via OAI-PMH using settings from datasources.ini.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class OaiPmh extends AbstractBase
{
    /**
     * Set to harvest (null for all records)
     *
     * @var string
     */
    protected $set = null;

    /**
     * Metadata type to harvest
     *
     * @var string
     */
    protected $metadataPrefix = 'oai_dc';

    /**
     * OAI prefix to strip from ID values
     *
     * @var string
     */
    protected $idPrefix = '';

    /**
     * Regular expression searches for ID substitutions
     *
     * @var array
     */
    protected $idSearch = [];

    /**
     * Replacements for regular expression matches for ID substitutions
     *
     * @var array
     */
    protected $idReplace = [];

    /**
     * Date granularity
     *
     * @var string
     */
    protected $granularity = 'auto';

    /**
     * File where to dump OAI requests and responses for debugging
     *
     * @var string
     */
    protected $debugLog = '';

    /**
     * Resumption token to use to override the first harvest request
     *
     * @var string
     */
    protected $resumptionToken = '';

    /**
     * Date received from server via Identify command. Used to set the last
     * harvest date.
     *
     * @var int
     */
    protected $serverDate = null;

    /**
     * Whether to ignore noRecordsMatch error when harvesting
     * (broken sources may report an error even with a valid resumptionToken)
     *
     * @var bool
     */
    protected $ignoreNoRecordsMatch = false;

    /**
     * Safety limit for abort if the same resumption token with no new results is
     * received with consecutive calls
     *
     * @var int
     */
    protected $sameResumptionTokenLimit = 100;

    /**
     * Last received resumption token
     *
     * @var string
     */
    protected $lastResumptionToken = '';

    /**
     * Counter for same resumption token received with consecutive calls
     *
     * @var int
     */
    protected $sameResumptionTokenCount = 0;

    /**
     * Current response being processed
     *
     * @var ?\DOMDocument
     */
    protected $xml = null;

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
        $this->set = $settings['set'] ?? null;
        $this->metadataPrefix = $settings['metadataPrefix'] ?? 'oai_dc';
        $this->idPrefix = $settings['idPrefix'] ?? '';
        $this->idSearch = $settings['idSearch'] ?? [];
        $this->idReplace = $settings['idReplace'] ?? [];
        $this->granularity = $settings['dateGranularity'] ?? 'auto';
        $this->debugLog = $settings['debuglog'] ?? '';
        $this->preXslt = [];
        foreach ((array)($settings['oaipmhTransformation'] ?? []) as $transformation) {
            $style = new \DOMDocument();
            $xsltPath = RECMAN_BASE_PATH . "/transformations/$transformation";
            $loadResult = $style->load($xsltPath);
            if (false === $loadResult) {
                throw new \Exception("Could not load $xsltPath");
            }
            $xslt = new \XSLTProcessor();
            $xslt->importStylesheet($style);
            $xslt->setParameter('', 'source_id', $source);
            $this->preXslt[] = $xslt;
        }
        $this->ignoreNoRecordsMatch = $settings['ignoreNoRecordsMatch'] ?? false;
        $this->sameResumptionTokenLimit
            = $settings['sameResumptionTokenLimit'] ?? 100;

        $this->xml = null;

        $this->identifyServer();
    }

    /**
     * Override the initial position
     *
     * @param string $pos New start position
     *
     * @return void
     */
    public function setInitialPosition($pos)
    {
        $this->resumptionToken = $pos;
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

        if ($this->resumptionToken) {
            $this->infoMsg('Incremental harvest from given resumptionToken');
            $token = $this->getRecordsByToken($this->resumptionToken);
        } else {
            // Start harvesting at the requested date:
            if (!empty($this->startDate)) {
                $this->infoMsg(
                    'Incremental harvest from timestamp ' . $this->startDate
                );
            } else {
                $this->infoMsg('Initial harvest for all records');
            }
            $token = $this->getRecordsByDate();
        }

        // Keep harvesting as long as a resumption token is provided:
        $this->resetSafeguard();
        while ($token !== false) {
            $this->reportResults();
            $token = $this->getRecordsByToken($token);
            $this->safeguard($token);
        }
        $this->reportResults();
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

        // Make the OAI-PMH request:
        $params = [
            'metadataPrefix' => $this->metadataPrefix,
            'identifier' => $id,
        ];
        $this->xml = $this->sendRequest('GetRecord', $params);

        // Save the records from the response:
        $getRecord = $this->getSingleNode($this->xml, 'GetRecord');
        if ($getRecord !== false) {
            $records = $this->getImmediateChildrenByTagName($getRecord, 'record');
            if ($records) {
                $this->processRecords($records);
            }
        }
    }

    /**
     * List identifiers of all available documents.
     *
     * @param callable $callback Function to be called to process an identifier
     *
     * @return void
     */
    public function listIdentifiers($callback)
    {
        $this->initHarvest($callback);

        if ($this->resumptionToken) {
            $this->infoMsg('Incremental listing from given resumptionToken');
            $token = $this->getIdentifiersByToken($this->resumptionToken);
        } else {
            $this->infoMsg('Listing all identifiers');
            $token = $this->getIdentifiers();
        }

        // Keep harvesting as long as a resumption token is provided:
        $this->resetSafeguard();
        while ($token !== false) {
            $this->reportListIdentifiersResults();
            $token = $this->getIdentifiersByToken($token);
            $this->safeguard($token);
        }
        $this->reportListIdentifiersResults();
    }

    /**
     * Reset safeguard
     *
     * @return void
     */
    protected function resetSafeguard()
    {
        $this->lastResumptionToken = '';
        $this->sameResumptionTokenCount = 0;
    }

    /**
     * Safeguard against broken repositories that don't return records and
     * return the same resumption token over and over again
     *
     * @param string $resumptionToken Latest resumption token
     *
     * @return void
     */
    protected function safeguard($resumptionToken)
    {
        if ($this->lastResumptionToken === $resumptionToken) {
            if (++$this->sameResumptionTokenCount >= $this->sameResumptionTokenLimit) {
                throw new \Exception(
                    "Same resumptionToken received"
                    . " {$this->sameResumptionTokenCount} times, aborting"
                );
            }
        } else {
            $this->sameResumptionTokenCount = 0;
            $this->lastResumptionToken = $resumptionToken;
        }
    }

    /**
     * Report the results of harvesting
     *
     * @return void
     */
    protected function reportResults()
    {
        $this->infoMsg(
            'Harvested ' . $this->changedRecords . ' normal records and '
            . $this->deletedRecords . ' deleted records from ' . $this->source
        );
    }

    /**
     * Display listing progress
     *
     * @return void
     */
    protected function reportListIdentifiersResults()
    {
        $this->infoMsg(
            'Listed ' . $this->changedRecords . ' normal records and '
            . $this->deletedRecords . ' deleted records from ' . $this->source
        );
    }

    /**
     * Normalize a date to a Unix timestamp.
     *
     * @param string $date Date (ISO-8601 or YYYY-MM-DD HH:MM:SS)
     *
     * @return integer     Unix timestamp (or false if $date invalid)
     */
    protected function normalizeDate($date)
    {
        // Remove timezone markers -- we don't want PHP to outsmart us by adjusting
        // the time zone!
        $date = str_replace(['T', 'Z'], [' ', ''], $date);

        // Translate to a timestamp:
        return strtotime($date);
    }

    /**
     * Make an OAI-PMH request.  Throw an exception if there is an error;
     * return a \DOMDocument object on success.
     *
     * @param string $verb   OAI-PMH verb to execute.
     * @param array  $params GET parameters for ListRecords method.
     *
     * @return \DOMDocument Response as DOM
     * @throws \Exception
     * @throws \HTTP_Request2_LogicException
     */
    protected function sendRequest($verb, $params = [])
    {
        // Set up the request:
        $request = $this->httpClientManager->createClient(
            $this->baseURL,
            \HTTP_Request2::METHOD_GET
        );

        // Load request parameters:
        $url = $request->getURL();
        $params['verb'] = $verb;
        $url->setQueryVariables(array_merge($url->getQueryVariables(), $params));

        $urlStr = $url->getURL();
        if ($this->debugLog) {
            file_put_contents(
                $this->debugLog,
                date('Y-m-d H:i:s') . ' [' . getmypid() . "] Request:\n$urlStr\n",
                FILE_APPEND
            );
        }

        // Perform request and throw an exception on error:
        for ($try = 1; $try <= $this->maxTries; $try++) {
            $this->infoMsg("Sending request: $urlStr");
            try {
                $response = $request->send();
                $code = $response->getStatus();
                if ($code >= 300) {
                    if ($try < $this->maxTries) {
                        $this->warningMsg(
                            "Request '$urlStr' failed ($code), retrying in "
                            . "{$this->retryWait} seconds..."
                        );
                        sleep($this->retryWait);
                        continue;
                    }
                    $this->fatalMsg("Request '$urlStr' failed: $code");
                    throw new HttpRequestException("Request failed: $code", $code);
                }

                $responseStr = $response->getBody();
                if ($this->debugLog) {
                    file_put_contents(
                        $this->debugLog,
                        date('Y-m-d H:i:s') . ' [' . getmypid() . "] Response:\n" .
                        "$responseStr\n\n",
                        FILE_APPEND
                    );
                }
                if ('' === $responseStr) {
                    throw new \Exception('Empty response from server');
                }
                return $this->processResponse(
                    $responseStr,
                    isset($params['resumptionToken'])
                );
            } catch (\Exception $e) {
                if ($try < $this->maxTries) {
                    $this->warningMsg(
                        "Request '$urlStr' failed (" . $e->getMessage()
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
     * Process an OAI-PMH response into a \DOMDocument object.
     * Throw exception if an error is detected.
     *
     * @param string $xml        OAI-PMH response XML
     * @param bool   $resumption Whether this is a request made with a
     *                           resumptionToken
     *
     * @return \DOMDocument Response as DOM
     * @throws \Exception
     */
    protected function processResponse($xml, $resumption)
    {
        try {
            $result = $this->transformToDoc($xml);
        } catch (\Exception $e) {
            $tempfile = $this->getTempFileName('oai-pmh-error-', '.xml');
            file_put_contents($tempfile, $xml);
            $this->errorMsg("Invalid XML stored in $tempfile");
            throw new \Exception(
                "Failed to parse XML response: " . $e->getMessage()
            );
        }

        // Detect errors and throw an exception if one is found:
        $error = $this->getSingleNode($result, 'error');
        if ($error) {
            $code = $error->getAttribute('code');
            if (($resumption && !$this->ignoreNoRecordsMatch) || $code != 'noRecordsMatch') {
                $value = $result->saveXML($error);
                $this->errorMsg("OAI-PMH server returned error $code ($value)");
                throw new \Exception(
                    "OAI-PMH error -- code: $code, value: $value"
                );
            }
        }

        // If we got this far, we have a valid response:
        return $result;
    }

    /**
     * Extract the ID from a record object (support method for processRecords()).
     *
     * @param \DOMNode $record XML record header
     *
     * @return string The ID value
     */
    protected function extractIDFromDom($record)
    {
        // Normalize to string:
        $id = $this->getSingleNode($record, 'identifier')->nodeValue;

        // Strip prefix if found:
        if (substr($id, 0, strlen($this->idPrefix)) == $this->idPrefix) {
            $id = substr($id, strlen($this->idPrefix));
        }

        // Apply regular expression matching:
        if (!empty($this->idSearch)) {
            $id = preg_replace($this->idSearch, $this->idReplace, $id);
        }

        // Return final value:
        return $id;
    }

    /**
     * Process the records.
     *
     * @param array $records DOM records
     *
     * @return void
     */
    protected function processRecords($records)
    {
        $count = count($records);
        $this->infoMsg("Processing $count records");

        // Loop through the records:
        foreach ($records as $record) {
            $header = $this->getSingleNode($record, 'header');

            // Bypass the record if the record is missing its header:
            if ($header === false) {
                $this->errorMsg(
                    'Record header missing: ' . PHP_EOL
                    . $this->xml->saveXML($record)
                );
                continue;
            }

            // Get the ID of the current record:
            $id = $this->extractIDFromDom($header);

            // Save the current record, either as a deleted or as a regular record:
            $status = strtolower($header->getAttribute('status'));
            if ($status === 'deleted') {
                call_user_func($this->callback, $this->source, $id, true, null);
                $this->deletedRecords++;
            } else {
                $recordMetadata = $this->getSingleNode($record, 'metadata');
                if ($recordMetadata === false) {
                    $this->errorMsg("No metadata tag found for record $id");
                    continue;
                }
                $recordNode = $this->getSingleNode($recordMetadata, '*');
                if ($recordNode === false) {
                    $this->errorMsg("No metadata fields found for record $id");
                    continue;
                }
                // Add namespaces to the record element
                $xpath = new \DOMXPath($this->xml);
                foreach ($xpath->query('namespace::*', $recordNode) as $node) {
                    // Bypass default xml namespace
                    if ($node->nodeValue == 'http://www.w3.org/XML/1998/namespace') {
                        continue;
                    }
                    // Check whether the attribute already exists
                    if ($recordNode->hasAttribute($node->nodeName)) {
                        continue;
                    }
                    $attr = $this->xml->createAttribute($node->nodeName);
                    $attr->value = $node->nodeValue;
                    $recordNode->appendChild($attr);
                }
                $this->changedRecords += call_user_func(
                    $this->callback,
                    $this->source,
                    $id,
                    false,
                    trim($this->xml->saveXML($recordNode))
                );
            }
        }
    }

    /**
     * Harvest records using OAI-PMH.
     *
     * @param array $params GET parameters for ListRecords method.
     *
     * @return mixed        Resumption token if provided, false if finished
     */
    protected function getRecords($params)
    {
        // Make the OAI-PMH request:
        $this->xml = $this->sendRequest('ListRecords', $params);

        // Save the records from the response:
        $listRecords = $this->getSingleNode($this->xml, 'ListRecords');
        if ($listRecords !== false) {
            $records = $this->getImmediateChildrenByTagName($listRecords, 'record');
            if ($records) {
                $this->processRecords($records);
            }

            // If we have a resumption token, keep going; otherwise, we're
            // done -- save the end date.
            $token = $this->getSingleNode($listRecords, 'resumptionToken');
            if ($token !== false && $token->nodeValue) {
                return $token->nodeValue;
            }
        }
        $dateFormat = $this->granularity == 'YYYY-MM-DD'
            ? 'Y-m-d' : 'Y-m-d\TH:i:s\Z';
        $this->saveLastHarvestedDate(date($dateFormat, $this->serverDate));
        return false;
    }

    /**
     * Harvest records via OAI-PMH using date and set.
     *
     * @return mixed Resumption token if provided, false if finished
     */
    protected function getRecordsByDate()
    {
        $params = ['metadataPrefix' => $this->metadataPrefix];
        if (!empty($this->startDate)) {
            $params['from'] = $this->startDate;
        }
        if (!empty($this->endDate)) {
            $params['until'] = $this->endDate;
        }
        if (!empty($this->set)) {
            $params['set'] = $this->set;
        }
        return $this->getRecords($params);
    }

    /**
     * Harvest records via OAI-PMH using resumption token.
     *
     * @param string $token Resumption token.
     *
     * @return mixed        Resumption token if provided, false if finished
     */
    protected function getRecordsByToken($token)
    {
        return $this->getRecords(['resumptionToken' => (string)$token]);
    }

    /**
     * Get identifiers using OAI-PMH.
     *
     * @param array $params GET parameters for ListIdentifiers method.
     *
     * @return mixed        Resumption token if provided, false if finished
     */
    protected function getIdentifiers($params = [])
    {
        // Make the OAI-PMH request:
        if (empty($params)) {
            $params = ['metadataPrefix' => $this->metadataPrefix];
            if (!empty($this->set)) {
                $params['set'] = $this->set;
            }
        }

        $this->xml = $this->sendRequest('ListIdentifiers', $params);

        // Process headers
        $listIdentifiers = $this->getSingleNode($this->xml, 'ListIdentifiers');
        if ($listIdentifiers !== false) {
            $headers = $this->getImmediateChildrenByTagName(
                $listIdentifiers,
                'header'
            );
            $this->processIdentifiers($headers);
            $token = $this->getSingleNode($listIdentifiers, 'resumptionToken');
            if ($token !== false && $token->nodeValue) {
                return $token->nodeValue;
            }
        }

        return false;
    }

    /**
     * Get identifiers via OAI-PMH using resumption token.
     *
     * @param string $token Resumption token.
     *
     * @return mixed        Resumption token if provided, false if finished
     */
    protected function getIdentifiersByToken($token)
    {
        return $this->getIdentifiers(['resumptionToken' => (string)$token]);
    }

    /**
     * Process fetched identifiers.
     *
     * @param array $headers DOM headers
     *
     * @return void
     */
    protected function processIdentifiers($headers)
    {
        $this->infoMsg('Processing ' . count($headers) . ' identifiers');

        // Loop through the records:
        foreach ($headers as $header) {
            // Get the ID of the current record:
            $id = $this->extractIDFromDom($header);

            // Process the current header, either as a deleted or as a regular record
            if (strcasecmp($header->getAttribute('status'), 'deleted') == 0) {
                call_user_func($this->callback, $this->source, $id, true);
                $this->deletedRecords++;
            } else {
                call_user_func($this->callback, $this->source, $id, false);
                $this->changedRecords++;
            }
        }
    }

    /**
     * Get the first XML child node with the given name
     *
     * @param \DOMDocument|\DOMElement $xml      The XML Node
     * @param string                   $nodeName Node to get
     *
     * @return \DOMElement|false  Result node or false if not found
     */
    protected function getSingleNode($xml, $nodeName)
    {
        $nodes = $xml->getElementsByTagName($nodeName);
        if ($nodes->length == 0) {
            return false;
        }
        return $nodes->item(0) ?? false;
    }

    /**
     * Traverse all children and collect those nodes that
     * have the tagname specified in $tagName. Non-recursive
     *
     * @param \DOMElement $element DOM Element
     * @param string      $tagName Tag to get
     *
     * @return array
     */
    protected function getImmediateChildrenByTagName($element, $tagName)
    {
        $result = [];
        foreach ($element->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->tagName == $tagName) {
                $result[] = $child;
            }
        }
        return $result;
    }

    /**
     * Identify the server and setup some setting based on the response
     *
     * @return void
     */
    protected function identifyServer()
    {
        $this->infoMsg('Identifying server');
        $response = $this->sendRequest('Identify');
        if ($this->granularity == 'auto') {
            $identify = $this->getSingleNode($response, 'Identify');
            if ($identify === false) {
                throw new \Exception(
                    'Could not find Identify node in the Identify response'
                );
            }
            $granularity = $this->getSingleNode(
                $identify,
                'granularity'
            );
            if ($granularity === false) {
                throw new \Exception(
                    'Could not find date granularity in the Identify response'
                );
            }
            $this->granularity = trim(
                $granularity->nodeValue
            );
            $this->infoMsg("Detected date granularity: {$this->granularity}");
        }
        $this->serverDate = $this->normalizeDate(
            $this->getSingleNode($response, 'responseDate')->nodeValue
        );
        $this->infoMsg(
            'Current server date: ' . date('Y-m-d\TH:i:s\Z', $this->serverDate)
        );
    }
}

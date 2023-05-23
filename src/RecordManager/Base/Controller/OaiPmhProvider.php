<?php

/**
 * OAI-PMH Provider
 *
 * PHP version 8
 *
 * Copyright (C) The National Library of Finland 2012-2021.
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

namespace RecordManager\Base\Controller;

use RecordManager\Base\Database\DatabaseInterface;
use RecordManager\Base\Deduplication\DedupHandlerInterface;
use RecordManager\Base\Record\PluginManager as RecordPluginManager;
use RecordManager\Base\Splitter\PluginManager as SplitterPluginManager;
use RecordManager\Base\Utils\Logger;
use RecordManager\Base\Utils\MetadataUtils;
use RecordManager\Base\Utils\XslTransformation;

/**
 * OAI-PMH Provider
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class OaiPmhProvider extends AbstractBase
{
    public const DT_EMPTY = 0;
    public const DT_INVALID = 1;
    public const DT_SHORT = 2;
    public const DT_LONG = 3;

    /**
     * Requested command
     *
     * @var string
     */
    protected $verb = '';

    /**
     * Transformations
     *
     * @var array
     */
    protected $transformations = [];

    /**
     * Formats
     *
     * @var array
     */
    protected $formats = [];

    /**
     * Sets
     *
     * @var array
     */
    protected $sets = [];

    /**
     * ID Prefix used to create an OAI ID for records that don't have one
     *
     * @var string
     */
    protected $idPrefix;

    /**
     * Constructor
     *
     * @param array                 $config              Main configuration
     * @param array                 $datasourceConfig    Datasource configuration
     * @param Logger                $logger              Logger
     * @param DatabaseInterface     $database            Database
     * @param RecordPluginManager   $recordPluginManager Record plugin manager
     * @param SplitterPluginManager $splitterManager     Record splitter plugin
     *                                                   manager
     * @param DedupHandlerInterface $dedupHandler        Deduplication handler
     * @param MetadataUtils         $metadataUtils       Metadata utilities
     * @param array                 $formatConfig        OAI-PMH format configuration
     * @param array                 $setConfig           OAI-PMH set configuration
     */
    public function __construct(
        array $config,
        array $datasourceConfig,
        Logger $logger,
        DatabaseInterface $database,
        RecordPluginManager $recordPluginManager,
        SplitterPluginManager $splitterManager,
        DedupHandlerInterface $dedupHandler,
        MetadataUtils $metadataUtils,
        array $formatConfig,
        array $setConfig
    ) {
        parent::__construct(
            $config,
            $datasourceConfig,
            $logger,
            $database,
            $recordPluginManager,
            $splitterManager,
            $dedupHandler,
            $metadataUtils
        );

        $this->formats = $formatConfig;
        $this->sets = $setConfig;
        $this->idPrefix = $this->config['OAI-PMH']['id_prefix'] ?? '';
    }

    /**
     * Process the request
     *
     * @return void
     */
    public function launch()
    {
        $this->verb = $this->getParam('verb');
        $this->printPrefix();
        if (!$this->checkParameters()) {
            $this->printSuffix();
            return;
        }
        switch ($this->verb) {
            case 'GetRecord':
                $this->getRecord();
                break;
            case 'Identify':
                $this->identify();
                break;
            case 'ListIdentifiers':
            case 'ListRecords':
                $this->listRecords($this->verb);
                break;
            case 'ListMetadataFormats':
                $this->listMetadataFormats();
                break;
            case 'ListSets':
                $this->listSets();
                break;
            default:
                $this->error('badVerb', 'Illegal OAI Verb');
                break;
        }
        $this->printSuffix();
    }

    /**
     * GetRecord handler
     *
     * @return void
     */
    protected function getRecord()
    {
        $id = $this->getParam('identifier');
        $prefix = $this->getParam('metadataPrefix');

        $record = $this->db->findRecord(['oai_id' => $id]);
        if (
            !$record
            && str_starts_with($id, $this->idPrefix)
        ) {
            $id = substr($id, strlen($this->idPrefix));
            $record = $this->db->getRecord($id);
        }
        if (!$record) {
            $this->error(
                'idDoesNotExist',
                'The value of the identifier argument is unknown or illegal in'
                . ' this repository.'
            );
            return;
        }
        $xml = $this->createRecordXML($record, $prefix, true);
        echo <<<EOT
              <GetRecord>
            $xml
              </GetRecord>

            EOT;
    }

    /**
     * Identify handler
     *
     * @return void
     */
    protected function identify()
    {
        $name = $this->escape($this->config['OAI-PMH']['repository_name']);
        $base = $this->escape($this->config['OAI-PMH']['base_url']);
        $admin = $this->escape($this->config['OAI-PMH']['admin_email']);
        $earliestDate = $this->toOaiDate($this->getEarliestDateStamp());

        echo <<<EOT
            <Identify>
              <repositoryName>$name</repositoryName>
              <baseURL>$base</baseURL>
              <protocolVersion>2.0</protocolVersion>
              <adminEmail>$admin</adminEmail>
              <earliestDatestamp>$earliestDate</earliestDatestamp>
              <deletedRecord>persistent</deletedRecord>
              <granularity>YYYY-MM-DDThh:mm:ssZ</granularity>
            </Identify>

            EOT;
    }

    /**
     * ListRecords/ListIdentifiers handler
     *
     * @param string $verb Verb; 'ListRecords' or 'ListIdentifiers'
     *
     * @return void
     */
    protected function listRecords($verb)
    {
        $includeMetadata = $verb == 'ListRecords';

        $resumptionToken = $this->getParam('resumptionToken');
        if ($resumptionToken) {
            $params = explode('|', $resumptionToken);
            if (count($params) != 5) {
                $this->error('badResumptionToken', '');
                return;
            }
            $set = $params[0];
            $metadataPrefix = $params[1];
            $from = $params[2];
            $until = $params[3];
            $position = $params[4];
        } else {
            $set = $this->getParam('set');
            $metadataPrefix = $this->getParam('metadataPrefix');
            $from = $this->getParam('from');
            $until = $this->getParam('until');
            $position = 0;
        }

        $queryParams = [];
        if ($set) {
            if (!isset($this->sets[$set])) {
                $this->error('noRecordsMatch', 'Requested set does not exist');
                return;
            }
            foreach ($this->sets[$set] as $key => $value) {
                if ($key === 'name') {
                    continue;
                }
                $queryParams[$key] = $value;
            }
        }
        if ($from && $until) {
            $queryParams['updated'] = [
                '$gte' => $this->db->getTimestamp(
                    $this->fromOaiDate($from, '00:00:00')
                ),
                '$lte' => $this->db->getTimestamp(
                    $this->fromOaiDate($until, '23:59:59')
                ),
            ];
        } elseif ($from) {
            $queryParams['updated'] = [
                '$gte' => $this->db->getTimestamp(
                    $this->fromOaiDate($from, '00:00:00')
                ),
            ];
        } elseif ($until) {
            $queryParams['updated'] = [
                '$lte' => $this->db->getTimestamp(
                    $this->fromOaiDate($until, '23:59:59')
                ),
            ];
        }

        $options = ['sort' => ['updated' => 1]];
        if ($position) {
            $options['skip'] = (int)$position;
        }
        $maxRecords = $this->config['OAI-PMH']['result_limit'];
        $count = 0;
        $listElementSent = false;
        $sendListElement = function () use (&$listElementSent, $verb) {
            if (!$listElementSent) {
                echo <<<EOT
                      <$verb>

                    EOT;
                $listElementSent = true;
            }
        };

        $tokenBase = implode('|', [$set, $metadataPrefix, $from, $until]);
        $this->db->iterateRecords(
            $queryParams,
            $options,
            function ($record) use (
                &$count,
                $maxRecords,
                $tokenBase,
                $position,
                $metadataPrefix,
                $includeMetadata,
                $sendListElement,
                $listElementSent
            ) {
                ++$count;
                if ($count > $maxRecords) {
                    // More records available, create resumptionToken
                    $token = $this->escape(
                        implode('|', [$tokenBase, $count + $position - 1])
                    );
                    $sendListElement();
                    echo <<<EOT
                            <resumptionToken cursor="$position">$token</resumptionToken>

                        EOT;
                    return false;
                }
                $xml = $this->createRecordXML(
                    $record,
                    $metadataPrefix,
                    $includeMetadata,
                    !$listElementSent
                );
                if ($xml === false && !$listElementSent) {
                    return false;
                }
                $sendListElement();
                echo $xml;
            }
        );

        if ($count == 0) {
            $this->error('noRecordsMatch', '');
            return;
        }

        if ($listElementSent) {
            echo <<<EOT
                  </$verb>

                EOT;
        }
    }

    /**
     * ListMetadataFormats handler
     *
     * @return void
     */
    protected function listMetadataFormats()
    {
        $formats = [];

        $id = $this->getParam('identifier');
        $source = '';
        if ($id) {
            $record = $this->db->findRecord(['oai_id' => $id]);
            if (!$record) {
                $this->error(
                    'idDoesNotExist',
                    'The value of the identifier argument is unknown or illegal in'
                    . ' this repository.'
                );
                return;
            }
            $source = $record['source_id'];
        }

        // List available formats
        foreach ($this->dataSourceConfig as $sourceId => $datasource) {
            if ($source && $sourceId != $source) {
                continue;
            }
            if (!($format = $datasource['format'] ?? null)) {
                continue;
            }
            $formats[$format] = 1;
            foreach (array_keys($datasource) as $key) {
                if (preg_match('/transformation_to_(.+)/', $key, $matches)) {
                    $formats[$matches[1]] = 1;
                }
            }
        }

        echo <<<EOT
              <ListMetadataFormats>

            EOT;

        // Map to OAI-PMH formats
        foreach (array_keys($formats) as $key) {
            foreach ($this->formats as $id => $settings) {
                if ($settings['format'] == $key) {
                    $prefix = $this->escape($id);
                    $schema = $settings['schema'];
                    $namespace = $settings['namespace'];

                    echo <<<EOT
                            <metadataFormat>
                              <metadataPrefix>$prefix</metadataPrefix>
                              <schema>$schema</schema>
                              <metadataNamespace>$namespace</metadataNamespace>
                            </metadataFormat>

                        EOT;
                    break;
                }
            }
        }
        echo <<<EOT
              </ListMetadataFormats>

            EOT;
    }

    /**
     * ListSets handler
     *
     * @return void
     */
    protected function listSets()
    {
        echo <<<EOT
              <ListSets>

            EOT;

        foreach ($this->sets as $id => $set) {
            $id = $this->escape($id);
            $name = $this->escape($set['name']);

            echo <<<EOT
                    <set>
                      <setSpec>$id</setSpec>
                      <setName>$name</setName>
                    </set>
                EOT;
        }

        echo <<<EOT
              </ListSets>
            EOT;
    }

    /**
     * Output and log an error
     *
     * @param string $code    Error code
     * @param string $message Error message
     *
     * @return void
     */
    protected function error($code, $message)
    {
        $code = $this->escape($code);
        $message = $this->escape($message);
        echo "  <error code=\"$code\">$message</error>\n";
        $this->logError("$code - $message");
    }

    /**
     * Output OAI-PMH response body opening
     *
     * @return void
     */
    protected function printPrefix()
    {
        header('Content-Type: text/xml');
        header("Cache-Control: no-cache, must-revalidate");
        $date = $this->toOaiDate();
        $base = $this->escape($this->config['OAI-PMH']['base_url']);
        $arguments = '';
        foreach ($this->getRequestParameters() as $param) {
            $keyValue = explode('=', $param, 2);
            if (null !== ($value = $keyValue[1] ?? null)) {
                $arguments .= ' ' . $keyValue[0] . '="' . $this->escape($value) . '"';
            }
        }

        echo <<<EOT
            <?xml version="1.0" encoding="UTF-8"?>
            <OAI-PMH xmlns="http://www.openarchives.org/OAI/2.0/"
                     xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
                     xsi:schemaLocation="http://www.openarchives.org/OAI/2.0/
                     http://www.openarchives.org/OAI/2.0/OAI-PMH.xsd">
              <responseDate>$date</responseDate>
              <request$arguments>$base</request>

            EOT;
    }

    /**
     * Output OAI-PMH response body closing
     *
     * @return void
     */
    protected function printSuffix()
    {
        echo "</OAI-PMH>\n";
    }

    /**
     * Convert OAI-PMH timestamp to PHP time
     *
     * @param string $datestr              OAI-PMH timestamp
     * @param string $timePartForShortDate Time part to use for a date without time
     *
     * @return int A timestamp
     */
    protected function fromOaiDate($datestr, $timePartForShortDate)
    {
        if ($this->getOaiDateType($datestr) === OaiPmhProvider::DT_SHORT) {
            $datestr .= ' ' . $timePartForShortDate . '+0000';
        } else {
            $datestr = substr($datestr, 0, strlen($datestr) - 1) . '+0000';
        }
        return strtotime($datestr);
    }

    /**
     * Convert time to OAI-PMH timestamp
     *
     * @param int|null $date Time to convert or null for current time
     *
     * @return string OAI-PMH timestamp
     */
    protected function toOaiDate($date = null)
    {
        if (null === $date) {
            $date = time();
        }
        return gmdate('Y-m-d\TH:i:s\Z', $date);
    }

    /**
     * Return HTTP request parameter
     *
     * @param string $param Parameter name
     *
     * @return string Parameter value or empty string
     */
    protected function getParam($param)
    {
        return (string)($_REQUEST[$param] ?? '');
    }

    /**
     * Return the earliest time in the database
     *
     * @return int Time
     */
    protected function getEarliestDateStamp()
    {
        $record = $this->db->findRecord([], ['sort' => ['updated' => 1]]);
        return $this->db->getUnixTime($record['updated']);
    }

    /**
     * Get the sets the record belongs to
     *
     * @param array $record Database record
     *
     * @return array
     */
    protected function getRecordSets($record)
    {
        $sets = [];
        foreach ($this->sets as $id => $set) {
            $match = true;
            foreach ($set as $key => $value) {
                if ($key == 'name') {
                    continue;
                }
                if (!isset($record[$key]) || $record[$key] != $value) {
                    $match = false;
                    break;
                }
            }
            if ($match) {
                $sets[] = $id;
            }
        }
        return $sets;
    }

    /**
     * Get all request parameters
     *
     * @return array
     */
    protected function getRequestParameters()
    {
        if ($_SERVER['REQUEST_METHOD'] == 'POST') {
            $params = file_get_contents("php://input");
        } else {
            $params = $_SERVER['QUERY_STRING'];
        }
        return explode('&', $params);
    }

    /**
     * Verify that the request parameters are valid
     *
     * @return boolean
     */
    protected function checkParameters()
    {
        $paramArray = $this->getRequestParameters();
        $checkArray = [];
        foreach ($paramArray as $param) {
            [$key] = explode('=', $param, 2);
            if (isset($checkArray[$key])) {
                $this->error('badArgument', 'Duplicate arguments not allowed');
                return false;
            }
            $checkArray[$key] = 1;
        }
        // Check for missing or unknown parameters
        $paramCount = count($paramArray) - 1;
        switch ($this->verb) {
            case 'GetRecord':
                if (
                    $paramCount !== 2 || !$this->getParam('identifier')
                    || !$this->getParam('metadataPrefix')
                ) {
                    $this->error('badArgument', 'Missing or extraneous arguments');
                    return false;
                }
                break;
            case 'Identify':
                if ($paramCount !== 0) {
                    $this->error('badArgument', 'Extraneous arguments');
                    return false;
                }
                break;
            case 'ListIdentifiers':
            case 'ListRecords':
                if ($this->getParam('resumptionToken')) {
                    if ($paramCount !== 1) {
                        $this->error(
                            'badArgument',
                            'Extraneous arguments with resumptionToken'
                        );
                        return false;
                    }
                } else {
                    if (!($format = $this->getParam('metadataPrefix'))) {
                        $this->error('badArgument', 'Missing argument "metadataPrefix"');
                        return false;
                    }
                    foreach (array_keys($_GET) as $key) {
                        $validVerb = in_array(
                            $key,
                            ['verb', 'from', 'until', 'set', 'metadataPrefix']
                        );
                        if (!$validVerb) {
                            $this->error('badArgument', 'Illegal argument');
                            return false;
                        }
                    }
                    // Check that the requested format is available at least from one
                    // source
                    $format = $this->formats[$format]['format'] ?? '';
                    $formatValid = false;
                    foreach ($this->dataSourceConfig as $sourceSettings) {
                        if (
                            ($sourceSettings['format'] ?? '') === $format
                            || !empty($sourceSettings["transformation_to_$format"])
                        ) {
                            $formatValid = true;
                            break;
                        }
                    }
                    if (!$formatValid) {
                        $this->error('cannotDisseminateFormat', '');
                        return false;
                    }
                }
                break;
            case 'ListMetadataFormats':
                if (
                    $paramCount > 1
                    || ($paramCount === 1 && !$this->getParam('identifier'))
                ) {
                    $this->error('badArgument', 'Invalid arguments');
                    return false;
                }
                break;
            case 'ListSets':
                if (
                    $paramCount > 1
                    || ($paramCount === 1 && !$this->getParam('resumptionToken'))
                ) {
                    $this->error('badArgument', 'Invalid arguments');
                    return false;
                } elseif ($this->getParam('resumptionToken')) {
                    $this->error('badResumptionToken', '');
                    return false;
                }
                break;
            default:
                $this->error('badVerb', 'Invalid verb');
                return false;
        }

        // Check dates
        $fromType = $this->getOaiDateType($this->getParam('from'));
        $untilType = $this->getOaiDateType($this->getParam('until'));

        if (
            $fromType == OaiPmhProvider::DT_INVALID
            || $untilType == OaiPmhProvider::DT_INVALID
        ) {
            $this->error('badArgument', 'Invalid date format');
            return false;
        }
        if (
            $fromType != OaiPmhProvider::DT_EMPTY
            && $untilType != OaiPmhProvider::DT_EMPTY && $fromType != $untilType
        ) {
            $this->error('badArgument', 'Incompatible date formats');
            return false;
        }

        return true;
    }

    /**
     * Get the type of the given timestamp
     *
     * @param string $date OAI-PMH timestamp
     *
     * @return int Date type
     */
    protected function getOaiDateType($date)
    {
        if (!$date) {
            return OaiPmhProvider::DT_EMPTY;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return OaiPmhProvider::DT_SHORT;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $date)) {
            return OaiPmhProvider::DT_LONG;
        }
        return OaiPmhProvider::DT_INVALID;
    }

    /**
     * Write an error message to log
     *
     * @param string $message Message
     *
     * @return void
     */
    protected function logError($message)
    {
        $message = '[' . $_SERVER['REMOTE_ADDR'] . '] ' . $message . ' (request: '
            . $_SERVER['QUERY_STRING'] . ')';
        $this->logger->logError('OaiPmhProvider', $message);
    }

    /**
     * Create record XML
     *
     * @param array   $record          Database record
     * @param string  $format          Metadata format
     * @param boolean $includeMetadata Whether to include record data
     *                                 (or only header). Metadata is never returned
     *                                 for deleted records.
     * @param boolean $outputErrors    Whether to output errors
     *
     * @return boolean|string
     */
    protected function createRecordXML(
        $record,
        $format,
        $includeMetadata,
        $outputErrors = true
    ) {
        $sourceFormat = $record['format'];
        if (isset($this->formats[$format])) {
            $format = $this->formats[$format]['format'];
        }
        $metadata = '';
        $source = $record['source_id'];
        $datasource = $this->dataSourceConfig[$source] ?? [];
        $oaiId = empty($datasource['ignoreOaiIdInProvider'])
            ? $record['oai_id'] : '';
        if ($includeMetadata && !$record['deleted']) {
            $metadataRecord = $this->createRecord(
                $record['format'],
                $this->metadataUtils->getRecordData($record, true),
                $oaiId,
                $record['source_id']
            );
            $metadata = $metadataRecord->toXML();
            $key = "transformation_to_{$format}";
            if ($sourceFormat != $format || isset($datasource[$key])) {
                if (!isset($datasource[$key])) {
                    if ($outputErrors) {
                        $this->error('cannotDisseminateFormat', '');
                    }
                    return false;
                }
                $transformationKey = "{$key}_$source";
                if (!isset($this->transformations[$transformationKey])) {
                    $this->transformations[$transformationKey]
                        = new XslTransformation(
                            RECMAN_BASE_PATH . '/transformations',
                            $datasource[$key]
                        );
                }
                $params = [
                    'source_id' => $source,
                    'institution' => $datasource['institution'],
                    'format' => $record['format'],
                ];
                $metadata = $this->transformations[$transformationKey]
                    ->transform($metadata, $params);
            }
            if (str_starts_with($metadata, '<?xml')) {
                $end = strpos($metadata, '>');
                $metadata = substr($metadata, $end + 1);
            }
            $metadata = <<<EOT
                      <metadata>
                        $metadata
                      </metadata>
                EOT;
        }

        $setSpecs = '';
        foreach ($this->getRecordSets($record) as $id) {
            $id = $this->escape($id);
            $setSpecs .= <<<EOT
                        <setSpec>$id</setSpec>

                EOT;
        }

        $id = $this->escape(
            !empty($oaiId)
            ? $oaiId
            : $this->idPrefix . $record['_id']
        );
        $date = $this->toOaiDate($this->db->getUnixTime($record['updated']));
        $status = $record['deleted'] ? ' status="deleted"' : '';

        $header = <<<EOT
                  <header$status>
                    <identifier>$id</identifier>
                    <datestamp>$date</datestamp>
            $setSpecs      </header>
            EOT;

        if ($includeMetadata) {
            return <<<EOT
                    <record>
                $header
                $metadata
                    </record>

                EOT;
        }
        return "$header\n";
    }

    /**
     * Escape special characters for XML
     *
     * @param string $str String to escape
     *
     * @return string Escaped string
     */
    protected function escape($str)
    {
        $str = str_replace('&', '&amp;', $str);
        $str = str_replace('"', '&quot;', $str);
        $str = str_replace('<', '&lt;', $str);
        $str = str_replace('>', '&gt;', $str);
        return $str;
    }
}

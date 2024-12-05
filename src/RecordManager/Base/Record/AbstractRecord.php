<?php

/**
 * Base class for record drivers
 *
 * PHP version 8
 *
 * Copyright (C) The National Library of Finland 2011-2022.
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

use RecordManager\Base\Database\DatabaseInterface as Database;
use RecordManager\Base\Utils\Logger;
use RecordManager\Base\Utils\MetadataUtils;

use function in_array;

/**
 * Base class for record drivers
 *
 * This is a base class for processing records.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
abstract class AbstractRecord
{
    /**
     * Logger
     *
     * @var Logger
     */
    protected $logger;

    /**
     * MetadataUtils
     *
     * @var MetadataUtils
     */
    protected $metadataUtils;

    /**
     * Main configuration
     *
     * @var array
     */
    protected $config;

    /**
     * Data source settings
     *
     * @var array
     */
    protected $dataSourceConfig;

    /**
     * Record source ID
     *
     * @var string
     */
    protected $source = '';

    /**
     * Record ID prefix
     *
     * @var string
     */
    protected $idPrefix = '';

    /**
     * Extra metadata
     *
     * @var array
     */
    protected array $extraData = [];

    /**
     * Warnings about problems in the record
     *
     * @var array<string>
     */
    protected $warnings = [];

    /**
     * A record-specific transient cache for results from methods that may get called
     * multiple times with same parameters e.g. during deduplication.
     *
     * @var array
     */
    protected $resultCache = [];

    /**
     * Constructor
     *
     * @param array         $config           Main configuration
     * @param array         $dataSourceConfig Data source settings
     * @param Logger        $logger           Logger
     * @param MetadataUtils $metadataUtils    Metadata utilities
     */
    public function __construct(
        array $config,
        array $dataSourceConfig,
        Logger $logger,
        MetadataUtils $metadataUtils
    ) {
        $this->config = $config;
        $this->dataSourceConfig = $dataSourceConfig;
        $this->logger = $logger;
        $this->metadataUtils = $metadataUtils;
    }

    /**
     * Set record data
     *
     * @param string $source    Source ID
     * @param string $oaiID     Record ID received from OAI-PMH (or empty string for
     *                          file import)
     * @param string $data      Record metadata
     * @param array  $extraData Extra metadata
     *
     * @return void
     */
    public function setData($source, $oaiID, $data, $extraData)
    {
        $this->source = $source;
        $this->idPrefix = $this->dataSourceConfig[$source]['idPrefix'] ?? $source;
        $this->resultCache = [];
        $this->extraData = $extraData;
    }

    /**
     * Return record ID (unique in the data source)
     *
     * @return string
     */
    abstract public function getID();

    /**
     * Return record linking IDs (typically same as ID) used for links
     * between records in the data source
     *
     * @return array
     */
    public function getLinkingIDs()
    {
        $id = $this->getID();
        return $id ? [$id] : [];
    }

    /**
     * Serialize the record for storing in the database
     *
     * @return string
     */
    abstract public function serialize();

    /**
     * Serialize the record into XML for export
     *
     * @return string
     */
    abstract public function toXML();

    /**
     * Normalize the record (optional)
     *
     * @return void
     */
    public function normalize()
    {
    }

    /**
     * Return whether the record is a component part
     *
     * @return boolean
     */
    public function getIsComponentPart()
    {
        return false;
    }

    /**
     * Return host record IDs for a component part
     *
     * @return array
     */
    public function getHostRecordIDs(): array
    {
        return [];
    }

    /**
     * Return fields to be indexed in Solr (an alternative to an XSL transformation)
     *
     * @param ?Database $db Database connection. Omit to avoid database lookups for related records.
     *
     * @return array<string, mixed>
     */
    public function toSolrArray(?Database $db = null)
    {
        return [];
    }

    /**
     * Merge component parts to this record
     *
     * @param \Traversable $componentParts Component parts to be merged
     * @param mixed        $changeDate     Latest database timestamp for the
     *                                     component part set
     *
     * @return int
     */
    public function mergeComponentParts($componentParts, &$changeDate)
    {
        return 0;
    }

    /**
     * Return record title
     *
     * @param bool $forFiling Whether the title is to be used in filing
     *                        (e.g. sorting, non-filing characters should be removed)
     *
     * @return string
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function getTitle($forFiling = false)
    {
        return '';
    }

    /**
     * Return format(s) from predefined values
     *
     * @return string|array
     */
    public function getFormat()
    {
        return '';
    }

    /**
     * Component parts: get the volume that contains this component part
     *
     * @return string
     */
    public function getVolume()
    {
        return '';
    }

    /**
     * Component parts: get the issue that contains this component part
     *
     * @return string
     */
    public function getIssue()
    {
        return '';
    }

    /**
     * Component parts: get the start page of this component part in the host record
     *
     * @return string
     */
    public function getStartPage()
    {
        return '';
    }

    /**
     * Component parts: get the container title
     *
     * @return string
     */
    public function getContainerTitle()
    {
        return '';
    }

    /**
     * Component parts: get the reference to the part in the container
     *
     * @return string
     */
    public function getContainerReference()
    {
        return '';
    }

    /**
     * Return main author (format: Last, First)
     *
     * @return string
     */
    public function getMainAuthor()
    {
        return '';
    }

    /**
     * Dedup: Return full title (for debugging purposes only)
     *
     * @return string
     */
    public function getFullTitleForDebugging()
    {
        return '';
    }

    /**
     * Dedup: Return unique IDs (control numbers)
     *
     * @return array
     */
    public function getUniqueIDs()
    {
        return [];
    }

    /**
     * Dedup: Return (unique) ISBNs in ISBN-13 format without dashes
     *
     * @return array
     */
    public function getISBNs()
    {
        return [];
    }

    /**
     * Dedup: Return ISSNs
     *
     * @return array
     */
    public function getISSNs()
    {
        return [];
    }

    /**
     * Dedup: Return series ISSN
     *
     * @return string
     */
    public function getSeriesISSN()
    {
        return '';
    }

    /**
     * Dedup: Return series numbering
     *
     * @return string
     */
    public function getSeriesNumbering()
    {
        return '';
    }

    /**
     * Dedup: Return publication year (four digits only)
     *
     * @return string
     */
    public function getPublicationYear()
    {
        return '';
    }

    /**
     * Dedup: Return page count (number only)
     *
     * @return string
     */
    public function getPageCount()
    {
        return '';
    }

    /**
     * Dedup: Add the dedup key to a suitable field in the metadata.
     * Used when exporting records to a file.
     *
     * @param string $dedupKey Dedup key to be added
     *
     * @return void
     */
    public function addDedupKeyToMetadata($dedupKey)
    {
    }

    /**
     * Check if record has access restrictions.
     *
     * @return string 'restricted' or more specific licence id if restricted,
     * empty string otherwise
     */
    public function getAccessRestrictions()
    {
        return $this->getDriverParam('accessRestrictions', '');
    }

    /**
     * Get any warnings about problems processing the record.
     *
     * @return array
     */
    public function getProcessingWarnings(): array
    {
        return array_values(array_unique($this->warnings));
    }

    /**
     * Check if the record is suppressed.
     *
     * @return bool
     */
    public function getSuppressed()
    {
        $filters = $this->dataSourceConfig[$this->source]['suppressOnField'] ?? [];
        if ($filters) {
            $solrFields = $this->toSolrArray();
            foreach ($filters as $field => $filter) {
                if (!isset($solrFields[$field])) {
                    continue;
                }
                foreach ((array)$solrFields[$field] as $value) {
                    if (
                        str_starts_with($value, '/')
                        && str_ends_with($value, '/')
                    ) {
                        $res = preg_match($filter, $value);
                        if (false === $res) {
                            $this->logger->logError(
                                'getSuppressed',
                                "Failed to parse filter regexp: $filter"
                            );
                        }
                    } else {
                        $res = in_array($value, explode('|', $filter));
                    }
                    if ($res) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /**
     * Get key data that can be used to identify expressions of a work
     *
     * Returns an associative array like this where each set of keys defines the
     * keys for a work (multiple sets can be returned for compound works):
     *
     * [
     *   [
     *     'titles' => [
     *       ['type' => 'title', 'value' => 'Title'],
     *       ['type' => 'uniform', 'value' => 'Uniform Title']
     *      ],
     *     'authors' => [
     *       ['type' => 'author', 'value' => 'Name 1'],
     *       ['type' => 'author', 'value' => 'Name 2']
     *     ],
     *     'titlesAltScript' => [
     *       ['type' => 'title', 'value' => 'Title in alternate script'],
     *       ['type' => 'uniform', 'value' => 'Uniform Title in alternate script']
     *     ],
     *     'authorsAltScript' => [
     *       ['type' => 'author', 'value' => 'Name 1 in alternate script'],
     *       ['type' => 'author', 'value' => 'Name 2 in alternate script']
     *     ]
     *   ],
     *   [
     *     'type' => 'analytical',
     *     'titles' => [...],
     *     'authors' => [...],
     *     'titlesAltScript' => [...]
     *     'authorsAltScript' => [...]
     *   ]
     * ]
     *
     * @return array
     */
    public function getWorkIdentificationData()
    {
        $titles = [];
        $authors = [];
        if ($title = $this->getTitle(true)) {
            $titles[] = ['type' => 'title', 'value' => $title];
        }
        if (
            ($titleNonSorting = $this->getTitle(false))
            && $title !== $titleNonSorting
        ) {
            $titles[] = ['type' => 'title', 'value' => $titleNonSorting];
        }
        if ($author = $this->getMainAuthor()) {
            $authors[] = ['type' => 'author', 'value' => $author];
        }
        $titlesAltScript = [];
        $authorsAltScript = [];
        return [compact('titles', 'authors', 'titlesAltScript', 'authorsAltScript')];
    }

    /**
     * Return datasource settings.
     *
     * @return array
     */
    public function getdataSourceConfig()
    {
        return $this->dataSourceConfig[$this->source];
    }

    /**
     * Return a parameter specified in driverParams[] of datasources.ini
     *
     * @param string $parameter Parameter name
     * @param mixed  $default   Default value to return if value is not set
     *                          defaults to true
     *
     * @return mixed Value
     */
    protected function getDriverParam($parameter, $default = true)
    {
        if (
            !isset($this->dataSourceConfig[$this->source]['driverParams'])
        ) {
            return $default;
        }
        $cacheKey = __METHOD__;
        if (!isset($this->resultCache[$cacheKey])) {
            $this->resultCache[$cacheKey] = parse_ini_string(
                implode(
                    PHP_EOL,
                    $this->dataSourceConfig[$this->source]['driverParams']
                )
            );
        }
        return $this->resultCache[$cacheKey][$parameter] ?? $default;
    }

    /**
     * Store a warning message about problems with the record
     *
     * @param string $msg Message
     *
     * @return void
     */
    protected function storeWarning($msg)
    {
        $this->warnings[] = $msg;
    }

    /**
     * Verify that a string is valid ISO8601 date
     *
     * @param string $dateString Date string
     *
     * @return string Valid date string or an empty string if invalid
     */
    protected function validateDate($dateString)
    {
        if ($this->metadataUtils->validateISO8601Date($dateString) !== false) {
            return $dateString;
        }
        return '';
    }
}

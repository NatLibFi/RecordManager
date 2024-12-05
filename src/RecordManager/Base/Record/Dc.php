<?php

/**
 * Dublin Core record class
 *
 * PHP version 8
 *
 * Copyright (C) The National Library of Finland 2011-2023.
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
use RecordManager\Base\Http\HttpService as HttpService;
use RecordManager\Base\Utils\Logger;
use RecordManager\Base\Utils\MetadataUtils;

/**
 * Dublin Core record class
 *
 * This is a class for processing Dublin Core records.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class Dc extends AbstractRecord
{
    use XmlRecordTrait {
        XmlRecordTrait::setData as XmlTraitSetData;
    }
    use FullTextTrait;

    /**
     * Document
     *
     * @var \SimpleXMLElement
     */
    protected $doc = null;

    /**
     * HTTP service for FullTextTrait
     *
     * @var HttpService
     */
    protected $httpService;

    /**
     * Database for FullTextTrait
     *
     * @var ?Database
     */
    protected $db;

    /**
     * Record namespace identifier
     *
     * @var string
     */
    protected $recordNs = 'http://www.openarchives.org/OAI/2.0/oai_dc/';

    /**
     * Constructor
     *
     * @param array         $config           Main configuration
     * @param array         $dataSourceConfig Data source settings
     * @param Logger        $logger           Logger
     * @param MetadataUtils $metadataUtils    Metadata utilities
     * @param HttpService   $httpService      HTTP service
     * @param ?Database     $db               Database
     */
    public function __construct(
        $config,
        $dataSourceConfig,
        Logger $logger,
        MetadataUtils $metadataUtils,
        HttpService $httpService,
        ?Database $db = null
    ) {
        parent::__construct($config, $dataSourceConfig, $logger, $metadataUtils);
        $this->httpService = $httpService;
        $this->db = $db;
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
        $this->XmlTraitSetData($source, $oaiID, $data, $extraData);

        if (
            empty($this->doc->recordID)
            && empty($this->doc->children($this->recordNs)->recordID)
        ) {
            $parts = explode(':', $oaiID, 3);
            $id = ('oai' === $parts[0] && !empty($parts[2])) ? $parts[2] : $oaiID;
            $this->doc->addChild('recordID', $id);
        }
    }

    /**
     * Return record ID (local)
     *
     * @return string
     */
    public function getID()
    {
        $id = (string)$this->doc->recordID[0];
        if ('' === $id) {
            $id = (string)$this->doc->children($this->recordNs)->recordID[0];
        }
        return $id;
    }

    /**
     * Return fields to be indexed in Solr
     *
     * @param ?Database $db Database connection. Omit to avoid database lookups for related records.
     *
     * @return array<string, mixed>
     */
    public function toSolrArray(?Database $db = null)
    {
        $data = $this->getFullTextFields($this->doc);

        $doc = $this->doc;
        $data['record_format'] = 'dc';
        $data['ctrlnum'] = trim((string)$doc->recordID);
        $data['fullrecord'] = $doc->asXML();

        // allfields
        $allFields = [];
        foreach ($doc->children() as $field) {
            $allFields[] = $this->metadataUtils->stripTrailingPunctuation(
                trim((string)$field)
            );
        }
        $data['allfields'] = $allFields;

        // language
        $languages = [];
        foreach (explode(' ', trim((string)$doc->language)) as $language) {
            foreach (str_split($language, 3) as $code) {
                $languages[] = $code;
            }
        }
        $data['language'] = $this->metadataUtils
            ->normalizeLanguageStrings($languages);

        $data['format'] = (string)$doc->type;
        $data['author'] = $this->metadataUtils->stripTrailingPunctuation(
            trim((string)$doc->creator)
        );
        $data['author2'] = $this->getValues('contributor');

        $data['title'] = $data['title_full'] = $this->getTitle();
        $titleParts = explode(' : ', $data['title'], 2);
        $data['title_short'] = $titleParts[0];
        if (isset($titleParts[1])) {
            $data['title_sub'] = $titleParts[1];
        }
        $data['title_sort'] = $this->getTitle(true);

        $data['publisher'] = [
            $this->metadataUtils->stripTrailingPunctuation(
                trim((string)$doc->publisher)
            ),
        ];
        $data['publishDate'] = $this->getPublicationYear();

        $data['isbn'] = $this->getISBNs();
        $data['doi_str_mv'] = $this->getDOIs();

        $data['topic'] = $data['topic_facet'] = $this->getValues('subject');

        foreach ($this->getValues('identifier') as $identifier) {
            if (preg_match('/^https?/', $identifier)) {
                $data['url'] = $identifier;
            }
        }
        foreach ($this->getValues('description') as $description) {
            if (preg_match('/^https?/', $description)) {
                $data['url'] = $description;
            } elseif (preg_match('/^\d+\.\d+$/', $description)) {
                // Classification, put somewhere?
            } else {
                $data['contents'][] = $description;
            }
        }

        return $data;
    }

    /**
     * Dedup: Return full title (for debugging purposes only)
     *
     * @return string
     */
    public function getFullTitleForDebugging()
    {
        return trim((string)$this->doc->title);
    }

    /**
     * Dedup: Return record title
     *
     * @param bool $forFiling Whether the title is to be used in filing
     *                        (e.g. sorting, non-filing characters should be removed)
     *
     * @return string
     */
    public function getTitle($forFiling = false)
    {
        $title = trim((string)$this->doc->title);
        if ($forFiling) {
            $title = $this->metadataUtils->createSortTitle($title);
        } else {
            $title
                = $this->metadataUtils->stripTrailingPunctuation($title, '', true);
        }
        return $title;
    }

    /**
     * Return main author (format: Last, First)
     *
     * @return string
     */
    public function getMainAuthor()
    {
        return trim((string)$this->doc->creator);
    }

    /**
     * Dedup: Return ISBNs in ISBN-13 format without dashes
     *
     * @return array
     */
    public function getISBNs()
    {
        $arr = [];
        foreach ($this->doc->identifier as $identifier) {
            $identifier = str_replace('-', '', trim($identifier));
            if (!preg_match('{([0-9]{9,12}[0-9xX])}', $identifier, $matches)) {
                continue;
            }
            $isbn = $this->metadataUtils->normalizeISBN($matches[1]);
            if ($isbn) {
                $arr[] = $isbn;
            }
        }
        return array_values(array_unique($arr));
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
     * Dedup: Return format from predefined values
     *
     * @return string|array
     */
    public function getFormat()
    {
        return $this->doc->type ? trim((string)$this->doc->type) : 'Other';
    }

    /**
     * Dedup: Return publication year (four digits only)
     *
     * @return string
     */
    public function getPublicationYear()
    {
        foreach ($this->doc->date as $date) {
            $date = trim((string)$date);
            if (preg_match('{^(\d{4})$}', $date)) {
                return $date;
            }
        }
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
     * Get DOIs
     *
     * @return array
     */
    protected function getDOIs(): array
    {
        $result = [];

        foreach ($this->getValues('identifier') as $identifier) {
            $found = preg_match(
                '{(urn:doi:|https?://doi.org/|https?://dx.doi.org/)([^?#]+)}',
                $identifier,
                $matches
            );
            if ($found) {
                $result[] = urldecode($matches[2]);
            }
        }
        return $result;
    }

    /**
     * Get all values for a tag
     *
     * @param string $tag XML tag to get
     *
     * @return array<int, string>
     */
    protected function getValues($tag)
    {
        $values = [];
        foreach ($this->doc->{$tag} as $value) {
            $values[] = $this->metadataUtils->stripTrailingPunctuation(
                trim((string)$value)
            );
        }
        return $values;
    }
}

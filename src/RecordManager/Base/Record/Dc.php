<?php

/**
 * Dublin Core record class
 *
 * PHP version 8
 *
 * Copyright (C) The National Library of Finland 2011-2025.
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
        $data = parent::toSolrArray($db);

        $data['ctrlnum'] = $this->getControlNumbers();
        $data['fullrecord'] = $this->getFullRecord();
        $data['allfields'] = $this->getAllFields();
        $data['language'] = $this->getLanguages();
        $data['format'] = $this->getFormat();
        $data['author'] = $this->getPrimaryAuthors();
        $data['author2'] = $this->getSecondaryAuthors();
        $data['author_sort'] = $this->getAuthorSort($data['author']);
        $data['title'] = $data['title_full'] = $this->getTitle();
        $data['title_short'] = $this->getShortTitle($data['title']);
        $data['title_sub'] = $this->getTitleSub($data['title']);
        $data['title_sort'] = $this->getTitle(true);
        $data['publisher'] = $this->getPublishers();
        $data['publishDate'] = $this->getPublicationYear();
        $data['publishDateRange'] = $this->getPublicationYears();
        $data['isbn'] = $this->getISBNs();
        $data['doi_str_mv'] = $this->getDOIs();
        $data['topic'] = $this->getTopics();
        $data['topic_facet'] = $this->getTopicFacets();
        $data['url'] = $this->getUrls();
        $data['contents'] = $this->getContents();
        $data['fulltext'] = $this->getFullTextField($this->doc);

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
            if ('' === $identifier || !preg_match('{([0-9]{9,12}[0-9xX])}', $identifier, $matches)) {
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
            if ('' !== $date && preg_match('{^(\d{4})$}', $date)) {
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
        $key = __METHOD__ . "$tag";
        if (isset($this->resultCache[$key])) {
            return $this->resultCache[$key];
        }

        $result = [];
        foreach ($this->doc->{$tag} as $value) {
            $result[] = $this->metadataUtils->stripTrailingPunctuation(
                trim((string)$value)
            );
        }
        $this->resultCache[$key] = $result;
        return $result;
    }

    /**
     * Get record format.
     *
     * @return string
     */
    protected function getRecordFormat(): string
    {
        return 'dc';
    }

    /**
     * Get control numbers.
     *
     * @return array
     */
    protected function getControlNumbers(): array
    {
        $id = trim((string)$this->doc->recordID);
        return '' !== $id ? [$id] : [];
    }

    /**
     * Get an array of all fields relevant to allfields search.
     *
     * @return array
     */
    protected function getAllFields(): array
    {
        $result = [];
        foreach ($this->doc->children() as $field) {
            $result[] = $this->metadataUtils->stripTrailingPunctuation(trim((string)$field));
        }
        return $result;
    }

    /**
     * Get all language codes.
     *
     * @return array<int, string> Language codes
     */
    protected function getLanguages(): array
    {
        $result = [];
        foreach (explode(' ', trim((string)$this->doc->language)) as $language) {
            foreach (str_split($language, 3) as $code) {
                $result[] = $code;
            }
        }
        return $this->metadataUtils->normalizeLanguageStrings($result);
    }

    /**
     * Get primary authors.
     *
     * @return array
     */
    protected function getPrimaryAuthors(): array
    {
        return $this->getValues('creator');
    }

    /**
     * Get secondary authors.
     *
     * @return array
     */
    protected function getSecondaryAuthors(): array
    {
        return $this->getValues('contributor');
    }

    /**
     * Get author sort field.
     *
     * @param array $authors Primary authors
     *
     * @return string
     */
    protected function getAuthorSort(array $authors): string
    {
        return $authors[0] ?? '';
    }

    /**
     * Get short title.
     *
     * @param string $fullTitle Full title
     *
     * @return string
     */
    protected function getShortTitle(string $fullTitle): string
    {
        $titleParts = explode(' : ', $fullTitle, 2);
        return $titleParts[0];
    }

    /**
     * Get subtitle.
     *
     * @param string $fullTitle Full title
     *
     * @return string
     */
    protected function getTitleSub(string $fullTitle): string
    {
        $titleParts = explode(' : ', $fullTitle, 2);
        return $titleParts[1] ?? '';
    }

    /**
     * Get publishers.
     *
     * @return array
     */
    protected function getPublishers(): array
    {
        return $this->getValues('publisher');
    }

    /**
     * Get topics.
     *
     * @return array
     */
    protected function getTopics(): array
    {
        return $this->getValues('subject');
    }

    /**
     * Get topic facet fields.
     *
     * @return array
     */
    protected function getTopicFacets(): array
    {
        return $this->getValues('subject');
    }

    /**
     * Get URLs.
     *
     * @return array
     */
    protected function getUrls(): array
    {
        $result = [];
        foreach ($this->getValues('identifier') as $identifier) {
            if (preg_match('/^https?/', $identifier)) {
                $result[] = $identifier;
            }
        }
        foreach ($this->getValues('description') as $description) {
            if (preg_match('/^https?/', $description)) {
                $result[] = $description;
            }
        }
        return $result;
    }

    /**
     * Get contents.
     *
     * @return array
     */
    protected function getContents(): array
    {
        $result = [];
        foreach ($this->getValues('description') as $description) {
            if (preg_match('/^https?/', $description)) {
                // URL
            } elseif (preg_match('/^\d+\.\d+$/', $description)) {
                // Classification, put somewhere?
            } else {
                $result[] = $description;
            }
        }
        return $result;
    }

    /**
     * Get full record.
     *
     * @return string
     */
    protected function getFullRecord(): string
    {
        return (string)$this->doc->asXML();
    }

    /**
     * Return publication years
     *
     * @return array
     */
    protected function getPublicationYears(): array
    {
        $result = [];
        foreach ($this->doc->date as $date) {
            $date = trim((string)$date);
            if (preg_match('{^(\d{4})$}', $date)) {
                $result[] = $date;
            }
        }
        return $result;
    }
}

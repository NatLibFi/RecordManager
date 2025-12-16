<?php

/**
 * Qdc record class
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

use function in_array;

/**
 * Qdc record class
 *
 * This is a class for processing Qualified Dublin Core records.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class Qdc extends AbstractRecord
{
    use XmlRecordTrait {
        XmlRecordTrait::setData as XmlTraitSetData;
    }
    use FullTextTrait;

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
     * Type fields which should be excluded when defining format.
     *
     * @var array
     */
    protected $excludedFormatTypes = [];

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
        return trim($id);
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
        $data['author_corporate'] = $this->getCorporateAuthors();
        $data['author_sort'] = $this->getAuthorSort($data['author']);
        $data['title'] = $this->getTitle();
        $data['title_full'] = $this->getFullTitle();
        $data['title_short'] = $this->getShortTitle($data['title']);
        $data['title_sub'] = $this->getTitleSub($data['title']);
        $data['title_sort'] = $this->getTitle(true);
        $data['title_alt'] = $this->getAltTitles();
        $data['publisher'] = $this->getPublishers();
        $data['publishDate'] = $this->getPublicationYear();
        $data['publishDateRange'] = $this->getPublicationYears();
        $data['isbn'] = $this->getISBNs();
        $data['issn'] = $this->getISSNs();
        $data['doi_str_mv'] = $this->getDOIs();
        $data['topic'] = $this->getTopics();
        $data['topic_facet'] = $this->getTopicFacets();
        $data['url'] = $this->getUrls();
        $data['contents'] = $this->getContents();
        $data['description'] = $this->getDescription();
        $data['series'] = $this->getSeries();
        $data['fulltext'] = $this->getFullTextField($this->doc);
        $this->addHierarchyFields($data);

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
        $key = __METHOD__ . ($forFiling ? '1' : '0');
        if (isset($this->resultCache[$key])) {
            return $this->resultCache[$key];
        }

        $preferred = null;
        $default = '';
        foreach ($this->doc->title as $title) {
            if ('' === $default) {
                $default = (string)$title;
            }
            if ((string)($title->attributes()->{'type'}) !== 'alternative') {
                $preferred = (string)$title;
                break;
            }
        }
        $result = $forFiling
            ? $this->metadataUtils->createSortTitle($preferred ?? $default)
            : $this->metadataUtils->stripTrailingPunctuation($preferred ?? $default);

        return $this->resultCache[$key] = $result;
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
     * Dedup: Return unique IDs (control numbers)
     *
     * @return array
     */
    public function getUniqueIDs()
    {
        $arr = [];
        $form = $this->config['Site']['unicode_normalization_form'] ?? 'NFKC';
        foreach ($this->doc->identifier as $identifier) {
            $identifier = strtolower(trim((string)$identifier));
            if (str_starts_with($identifier, 'urn:')) {
                $arr[] = '(urn)' . $this->metadataUtils
                    ->normalizeKey($identifier, $form);
            }
        }

        return array_unique($arr);
    }

    /**
     * Dedup: Return ISBNs in ISBN-13 format without dashes
     *
     * @return array
     */
    public function getISBNs()
    {
        $arr = [];
        foreach ([$this->doc->identifier, $this->doc->isFormatOf] as $field) {
            foreach ($field as $identifier) {
                $identifier = str_replace('-', '', trim($identifier));
                if ('' === $identifier || !preg_match('{^([0-9]{9,12}[0-9xX])}', $identifier, $matches)) {
                    continue;
                }
                $isbn = $this->metadataUtils->normalizeISBN($matches[1]);
                if ($isbn) {
                    $arr[] = $isbn;
                }
            }
        }

        return array_unique($arr);
    }

    /**
     * Dedup: Return ISSNs
     *
     * @return array
     */
    public function getISSNs()
    {
        $result = [];
        foreach ([$this->doc->relation, $this->doc->identifier] as $fields) {
            foreach ($fields as $current) {
                if ((string)$current->attributes()->{'type'} === 'issn') {
                    $result[] = trim((string)$current);
                }
            }
        }
        return $result;
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
        $param = $this->getDriverParam('preferredFormatTypes', '');
        $preferredTypes = $param ? explode(',', $param) : [];
        $collectedTypes = [];
        $first = '';
        foreach ($this->doc->type ?? [] as $node) {
            if ($value = trim((string)$node)) {
                $typeAttr = trim((string)($node->attributes()->type ?? '')) ?: 'no_type';
                if (!in_array($typeAttr, $this->excludedFormatTypes) && !($collectedTypes[$typeAttr] ?? '')) {
                    $collectedTypes[$typeAttr] = $value;
                    $first = $first ?: $typeAttr;
                }
            }
        }
        if ($collectedTypes) {
            foreach ($preferredTypes as $pref) {
                if ($collectedTypes[$pref] ?? '') {
                    return $collectedTypes[$pref];
                }
            }
            return $collectedTypes[$first];
        }
        return 'Unknown';
    }

    /**
     * Dedup: Return publication year (four digits only)
     *
     * @return string
     */
    public function getPublicationYear()
    {
        foreach ($this->doc->date as $date) {
            $date = trim($date);
            if (preg_match('{^(\d{4})$}', $date)) {
                return $date;
            } elseif (preg_match('{^(\d{4})(-|\/)}', $date, $matches)) {
                return $matches[1];
            }
        }
        foreach ($this->doc->issued as $date) {
            $date = trim($date);
            if (preg_match('{^(\d{4})$}', $date)) {
                return $date;
            } elseif (preg_match('{^(\d{4})(-|\/)}', $date, $matches)) {
                return $matches[1];
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
     * Get series information
     *
     * @return array
     */
    public function getSeries()
    {
        return [];
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
     * Get descriptions as an associative array
     *
     * @return array
     */
    protected function getDescriptions(): array
    {
        if (isset($this->resultCache[__METHOD__])) {
            return $this->resultCache[__METHOD__];
        }

        $all = [];
        $primary = '';
        $lang = $this->getDriverParam('defaultDisplayLanguage', 'en');
        foreach ($this->doc->description as $description) {
            $trimmed = trim((string)$description);
            if (!preg_match('/(^https?)|(^\d+\.\d+$)/', $trimmed)) {
                $all[] = $trimmed;
                if (!$primary) {
                    $descLang = (string)$description->attributes()->{'lang'};
                    if ($descLang === $lang) {
                        $primary = $trimmed;
                    }
                }
            }
        }
        if (!$primary && $all) {
            $primary = $all[0];
        }
        return $this->resultCache[__METHOD__] = compact('primary', 'all');
    }

    /**
     * Get primary authors.
     *
     * @return array
     */
    protected function getPrimaryAuthors(): array
    {
        $result = [];
        foreach ($this->getValues('creator') as $author) {
            $result[] = $this->metadataUtils->stripTrailingPunctuation($author);
        }
        return $result;
    }

    /**
     * Get secondary authors.
     *
     * @return array
     */
    protected function getSecondaryAuthors(): array
    {
        $result = [];
        foreach ($this->getValues('contributor') as $contributor) {
            $result[] = $this->metadataUtils->stripTrailingPunctuation($contributor);
        }
        return $result;
    }

    /**
     * Get corporate authors
     *
     * @return array
     */
    protected function getCorporateAuthors(): array
    {
        return [];
    }

    /**
     * Get an array of all fields relevant to allfields search
     *
     * @return array
     */
    protected function getAllFields()
    {
        $allFields = [];
        foreach ($this->doc->children() as $field) {
            $allFields[] = trim((string)$field);
        }
        return $allFields;
    }

    /**
     * Return URLs associated with object
     *
     * @return array
     */
    protected function getUrls()
    {
        $urls = [];
        foreach ($this->getValues('identifier') as $identifier) {
            if (preg_match('/^https?/', $identifier)) {
                $urls[] = $identifier;
            }
        }
        foreach ($this->getValues('description') as $description) {
            if (preg_match('/^https?/', $description)) {
                $urls[] = $description;
            }
        }
        return $urls;
    }

    /**
     * Get DOIs
     *
     * @return array
     */
    protected function getDOIs(): array
    {
        $result = [];

        foreach ($this->getValues('identifier', ['type' => 'doi']) as $identifier) {
            $found = preg_match(
                '{(urn:doi:|https?://doi.org/|https?://dx.doi.org/)([^?#]+)}',
                $identifier,
                $matches
            );
            $result[] = $found ? urldecode($matches[2]) : $identifier;
        }
        return $result;
    }

    /**
     * Get languages
     *
     * @return array
     */
    protected function getLanguages()
    {
        $languages = [];
        foreach (explode(' ', trim((string)$this->doc->language)) as $language) {
            $language = preg_replace(
                '/^http:\/\/lexvo\.org\/id\/iso639-.\/(.*)/',
                '$1',
                $language
            );
            foreach (str_split($language, 3) as $code) {
                $languages[] = $code;
            }
        }
        return $this->metadataUtils->normalizeLanguageStrings($languages);
    }

    /**
     * Get xml field values
     *
     * @param string $tag        Field name
     * @param array  $attributes Attributes filter for the field
     *
     * @return array
     */
    protected function getValues($tag, array $attributes = [])
    {
        $key = md5(__METHOD__ . "$tag-" . json_encode($attributes));
        if (isset($this->resultCache[$key])) {
            return $this->resultCache[$key];
        }

        $result = [];
        foreach ($this->doc->{$tag} as $element) {
            foreach ($attributes as $attr => $value) {
                if ((string)$element[$attr] !== $value) {
                    continue 2;
                }
            }
            $result[] = trim((string)$element);
        }

        $this->resultCache[$key] = $result;
        return $result;
    }

    /**
     * Add hierarchy fields. Must be called after title is present in the array.
     *
     * @param array $data Reference to the target array
     *
     * @return void
     */
    protected function addHierarchyFields(array &$data): void
    {
    }

    /**
     * Get record format.
     *
     * @return string
     */
    protected function getRecordFormat(): string
    {
        return 'qdc';
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
     * Get full title.
     *
     * @return string
     */
    protected function getFullTitle(): string
    {
        return $this->getTitle();
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
     * Get alternate titles
     *
     * @return array
     */
    protected function getAltTitles(): array
    {
        $result = [];
        $hasMainTitle = false;
        foreach ($this->doc->title as $title) {
            if (!$hasMainTitle && (string)($title->attributes()->{'type'}) !== 'alternative') {
                $hasMainTitle = true;
            } else {
                $result[] = trim((string)$title);
            }
        }
        return $result;
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
     * Get contents.
     *
     * @return array
     */
    protected function getContents(): array
    {
        $descriptions = $this->getDescriptions();
        return $descriptions['all'];
    }

    /**
     * Get description.
     *
     * @return string
     */
    protected function getDescription(): string
    {
        $descriptions = $this->getDescriptions();
        return $descriptions['primary'];
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
            $date = trim($date);
            if (preg_match('{^(\d{4})$}', $date)) {
                $result[] = $date;
            } elseif (preg_match('{^(\d{4})(-|\/)}', $date, $matches)) {
                $result[] = $matches[1];
            }
        }
        foreach ($this->doc->issued as $date) {
            $date = trim($date);
            if (preg_match('{^(\d{4})$}', $date)) {
                $result[] = $date;
            } elseif (preg_match('{^(\d{4})(-|\/)}', $date, $matches)) {
                $result[] = $matches[1];
            }
        }
        return $result;
    }
}

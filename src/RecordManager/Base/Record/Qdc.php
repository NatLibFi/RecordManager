<?php

/**
 * Qdc record class
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
        $data = $this->getFullTextFields($this->doc);

        $doc = $this->doc;
        $data['record_format'] = 'qdc';
        $data['ctrlnum'] = trim((string)$doc->recordID);
        $data['fullrecord'] = $doc->asXML();
        $data['allfields'] = $this->getAllFields();
        $data['language'] = $this->getLanguages();

        $data['format'] = $this->getFormat();

        $data['author'] = $this->getPrimaryAuthors();
        $data['author2'] = $this->getSecondaryAuthors();
        $data['author_corporate'] = $this->getCorporateAuthors();
        if (!empty($data['author'])) {
            $data['author_sort'] = $data['author'][0];
        }

        foreach ($doc->title as $title) {
            if (
                !isset($data['title'])
                && $title->attributes()->{'type'} !== 'alternative'
            ) {
                $data['title'] = $data['title_full'] = trim((string)$title);
                $titleParts = explode(' : ', $data['title']);
                $data['title_short'] = $titleParts[0];
                if (isset($titleParts[1])) {
                    $data['title_sub'] = $titleParts[1];
                }
            } else {
                $data['title_alt'][] = trim((string)$title);
            }
        }
        $data['title_sort'] = $this->getTitle(true);

        $data['publisher'] = [trim((string)$doc->publisher)];
        $data['publishDate'] = $this->getPublicationYear();

        $data['isbn'] = $this->getISBNs();
        $data['issn'] = $this->getISSNs();
        $data['doi_str_mv'] = $this->getDOIs();

        $data['topic'] = $data['topic_facet'] = $this->getTopics();
        $data['url'] = $this->getUrls();

        $descriptions = $this->getDescriptions();
        $data['contents'] = $descriptions['all'];
        $data['description'] = $descriptions['primary'];

        $data['series'] = $this->getSeries();
        $this->getHierarchyFields($data);

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
            $title = $this->metadataUtils->stripTrailingPunctuation($title);
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
                if (!preg_match('{^([0-9]{9,12}[0-9xX])}', $identifier, $matches)) {
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
     * Get topics.
     *
     * @return array
     */
    public function getTopics()
    {
        return $this->getValues('subject');
    }

    /**
     * Get descriptions as an associative array
     *
     * @return array
     */
    public function getDescriptions(): array
    {
        $all = [];
        $primary = '';
        $lang = $this->getDriverParam('defaultDisplayLanguage', 'en');
        foreach ($this->doc->description as $description) {
            $trimmed = trim((string)$description);
            if (!preg_match('/(^https?)|(^\d+\.\d+$)/', $trimmed)) {
                $all[] = (string)$description;
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
        return compact('primary', 'all');
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
     * Get primary authors
     *
     * @return array
     */
    protected function getPrimaryAuthors()
    {
        $result = [];
        foreach ($this->getValues('creator') as $author) {
            $result[]
                = $this->metadataUtils->stripTrailingPunctuation($author);
        }
        return $result;
    }

    /**
     * Get secondary authors
     *
     * @return array
     */
    protected function getSecondaryAuthors()
    {
        $result = [];
        foreach ($this->getValues('contributor') as $contributor) {
            $result[]
                = $this->metadataUtils->stripTrailingPunctuation($contributor);
        }
        return $result;
    }

    /**
     * Get corporate authors
     *
     * @return array
     */
    protected function getCorporateAuthors()
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
            if ($found) {
                $result[] = urldecode($matches[2]);
            } else {
                $result[] = $identifier;
            }
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
        $values = [];
        foreach ($this->doc->{$tag} as $element) {
            foreach ($attributes as $attr => $value) {
                if ((string)$element[$attr] !== $value) {
                    continue 2;
                }
            }
            $values[] = trim((string)$element);
        }
        return $values;
    }

    /**
     * Get hierarchy fields. Must be called after title is present in the array.
     *
     * @param array $data Reference to the target array
     *
     * @return void
     */
    protected function getHierarchyFields(array &$data): void
    {
    }
}

<?php
/**
 * Qdc record class
 *
 * PHP version 7
 *
 * Copyright (C) The National Library of Finland 2011-2021.
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
use RecordManager\Base\Http\ClientManager as HttpClientManager;
use RecordManager\Base\Utils\Logger;
use RecordManager\Base\Utils\MetadataUtils;

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
    use FullTextTrait;

    /**
     * Document
     *
     * @var \SimpleXMLElement
     */
    protected $doc = null;

    /**
     * HTTP client manager
     *
     * @var HttpClientManager
     */
    protected $httpClientManager;

    /**
     * Constructor
     *
     * @param array             $config           Main configuration
     * @param array             $dataSourceConfig Data source settings
     * @param Logger            $logger           Logger
     * @param MetadataUtils     $metadataUtils    Metadata utilities
     * @param HttpClientManager $httpManager      HTTP client manager
     */
    public function __construct(
        $config,
        $dataSourceConfig,
        Logger $logger,
        MetadataUtils $metadataUtils,
        HttpClientManager $httpManager
    ) {
        parent::__construct($config, $dataSourceConfig, $logger, $metadataUtils);
        $this->httpClientManager = $httpManager;
    }

    /**
     * Set record data
     *
     * @param string $source Source ID
     * @param string $oaiID  Record ID received from OAI-PMH (or empty string for
     *                       file import)
     * @param string $data   Metadata
     *
     * @return void
     */
    public function setData($source, $oaiID, $data)
    {
        parent::setData($source, $oaiID, $data);

        $this->doc = $this->parseXMLRecord($data);
        if (empty($this->doc->recordID)) {
            $p = strpos($oaiID, ':');
            $p = strpos($oaiID, ':', $p + 1);
            $this->doc->addChild('recordID', substr($oaiID, $p + 1));
        }
    }

    /**
     * Return record ID (local)
     *
     * @return string
     */
    public function getID()
    {
        return trim((string)$this->doc->recordID[0]);
    }

    /**
     * Serialize the record for storing in the database
     *
     * @return string
     */
    public function serialize()
    {
        return $this->metadataUtils->trimXMLWhitespace($this->doc->asXML());
    }

    /**
     * Serialize the record into XML for export
     *
     * @return string
     */
    public function toXML()
    {
        return $this->doc->asXML();
    }

    /**
     * Return fields to be indexed in Solr
     *
     * @param Database $db Database connection. Omit to avoid database lookups for
     *                     related records.
     *
     * @return array
     */
    public function toSolrArray(Database $db = null)
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
            if (!isset($data['title'])
                && $title->attributes()->{'type'} !== 'alternative'
            ) {
                $data['title'] = $data['title_full'] = trim((string)$title);
                $titleParts = explode(' : ', $data['title']);
                if (!empty($titleParts)) {
                    $data['title_short'] = $titleParts[0];
                    if (isset($titleParts[1])) {
                        $data['title_sub'] = $titleParts[1];
                    }
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

        $data['topic'] = $data['topic_facet'] = $this->getTopics();
        $data['url'] = $this->getUrls();

        $descriptions = $this->getDescriptions();
        $data['contents'] = $descriptions['all'];
        $data['description'] = $descriptions['primary'];

        $data['series'] = $this->getSeries();

        return $data;
    }

    /**
     * Dedup: Return full title (for debugging purposes only)
     *
     * @return string
     */
    public function getFullTitle()
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
            $title = $this->metadataUtils->stripLeadingPunctuation($title);
            $title = $this->metadataUtils->stripLeadingArticle($title);
            // Again, just in case stripping the article affected this
            $title = $this->metadataUtils->stripLeadingPunctuation($title);
            $title = mb_strtolower($title, 'UTF-8');
        }
        $title = $this->metadataUtils->stripTrailingPunctuation($title);
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
            if (strncmp('urn:', $identifier, 4) === 0) {
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
        foreach ([$this->doc->relation, $this->doc->identifier] as $field) {
            foreach ($field ?? [] as $current) {
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
     * @return string
     */
    public function getFormat()
    {
        return $this->doc->type ? trim((string)$this->doc->type) : 'Unknown';
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
                return (string)$date;
            } elseif (preg_match('{^(\d{4})-}', $date, $matches)) {
                return (string)$matches[1];
            }
        }
        foreach ($this->doc->issued as $date) {
            $date = trim($date);
            if (preg_match('{^(\d{4})$}', $date)) {
                return (string)$date;
            } elseif (preg_match('{^(\d{4})-}', $date, $matches)) {
                return (string)$matches[1];
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
                $all[] = $description;
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
     * Get xml field values
     *
     * @param string $tag Field name
     *
     * @return array
     */
    protected function getValues($tag)
    {
        $values = [];
        foreach ($this->doc->{$tag} as $value) {
            $values[] = trim((string)$value);
        }
        return $values;
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
}

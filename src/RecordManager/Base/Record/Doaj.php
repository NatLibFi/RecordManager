<?php

/**
 * DOAJ record class
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
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */

namespace RecordManager\Base\Record;

use RecordManager\Base\Database\DatabaseInterface as Database;
use RecordManager\Base\Http\HttpService as HttpService;
use RecordManager\Base\Utils\Logger;
use RecordManager\Base\Utils\MetadataUtils;

/**
 * DOAJ record class
 *
 * This is a class for processing Dublin Core records.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class Doaj extends AbstractRecord
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
     * Record Document
     *
     * @var \SimpleXMLElement
     */
    protected $recordDoc = null;

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
    protected $recordNs = 'http://doaj.org/features/oai_doaj/1.0/';

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
     * Dedup: Return full title (for debugging purposes only)
     *
     * @return string
     */
    public function getFullTitleForDebugging()
    {
        return trim((string)$this->doc->children($this->recordNs)->title);
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

        $title = trim((string)$this->doc->children($this->recordNs)->title);
        if ($forFiling) {
            $title = $this->metadataUtils->createSortTitle($title);
        } else {
            $title = $this->metadataUtils->stripTrailingPunctuation($title, '', true);
        }
        return $this->resultCache[$key] = $title;
    }

    /**
     * Return main author (format: Last, First)
     *
     * @return string
     */
    public function getMainAuthor()
    {
        return trim((string)($this->doc->children($this->recordNs)->authors->author->name ?? ''));
    }

    /**
     * Dedup: Return format from predefined values
     *
     * @return string|array
     */
    public function getFormat()
    {
        return 'Article';
    }

    /**
     * Dedup: Return publication year (four digits only)
     *
     * @return string
     */
    public function getPublicationYear()
    {
        $date = trim((string)$this->doc->children($this->recordNs)->publicationDate);
        $date = substr($date, 0, 4);
        if ('' !== $date && preg_match('{^(\d{4})$}', $date)) {
            return $date;
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
     * Get ISBNs in ISBN-13 format without dashes.
     *
     * @return array
     */
    protected function getISBNs(): array
    {
        return [];
    }

    /**
     * Do any pre-processing for the record before the conversion to Solr array.
     *
     * @param ?Database $db Database connection, if available
     *
     * @return void
     */
    protected function preProcessRecordForIndexing(?Database $db): void
    {
        $this->recordDoc = $this->doc->children($this->recordNs);
    }

    /**
     * Get record format.
     *
     * @return string
     */
    protected function getRecordFormat(): string
    {
        return 'doaj';
    }

    /**
     * Get DOIs
     *
     * @return array
     */
    protected function getDOIs(): array
    {
        return [];
    }

    /**
     * Get control numbers.
     *
     * @return array
     */
    protected function getControlNumbers(): array
    {
        return [$this->getID()];
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
     * Get an array of all fields relevant to allfields search.
     *
     * @return array
     */
    protected function getAllFields(): array
    {
        $result = [];
        foreach ($this->recordDoc as $field) {
            $result[] = $this->metadataUtils->stripTrailingPunctuation(trim((string)$field));
        }
        return $result;
    }

    /**
     * Get languages.
     *
     * @return array
     */
    protected function getLanguages(): array
    {
        $result = [];
        foreach (explode(' ', trim((string)$this->recordDoc->language)) as $language) {
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
        $getAuthor = function ($xml) {
            return (string)($xml->author->name ?? '');
        };
        return array_filter(
            array_values(
                array_map($getAuthor, iterator_to_array($this->recordDoc->authors))
            )
        );
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
     * @return string
     */
    protected function getShortTitle(): string
    {
        $titleParts = explode(' : ', $this->getFullTitle(), 2);
        return $titleParts[0];
    }

    /**
     * Get subtitle.
     *
     * @return string
     */
    protected function getTitleSub(): string
    {
        $titleParts = explode(' : ', $this->getFullTitle(), 2);
        return $titleParts[1] ?? '';
    }

    /**
     * Get publishers.
     *
     * @return array
     */
    protected function getPublishers(): array
    {
        return [
            $this->metadataUtils->stripTrailingPunctuation(trim((string)$this->recordDoc->publisher)),
        ];
    }

    /**
     * Get topics.
     *
     * @return array
     */
    protected function getTopics(): array
    {
        if (isset($this->resultCache[__METHOD__])) {
            return $this->resultCache[__METHOD__];
        }
        $getTopic = function ($xml) {
            return (string)($xml->keyword ?? '');
        };
        return $this->resultCache[__METHOD__] = array_filter(
            array_values(
                array_map($getTopic, iterator_to_array($this->recordDoc->keywords))
            )
        );
    }

    /**
     * Get topic facet fields.
     *
     * @return array
     */
    protected function getTopicFacets(): array
    {
        return $this->getTopics();
    }

    /**
     * Return URLs associated with object
     *
     * @return array
     */
    protected function getUrls()
    {
        if ($url = (string)$this->recordDoc->fullTextUrl) {
            return [$url];
        }
        return [];
    }

    /**
     * Get publication years.
     *
     * @return array
     */
    protected function getPublicationYears(): array
    {
        $date = trim((string)$this->doc->children($this->recordNs)->publicationDate);
        $date = substr($date, 0, 4);
        if (preg_match('{^(\d{4})$}', $date)) {
            return [$date];
        }
        return [];
    }

    /**
     * Get full text field for a given document
     *
     * @return string
     */
    protected function getFullTextField(): string
    {
        return $this->getFullTextFieldForDocument($this->recordDoc);
    }
}

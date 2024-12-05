<?php

/**
 * DOAJ record class
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
     * Return fields to be indexed in Solr
     *
     * @param ?Database $db Database connection. Omit to avoid database lookups for related records.
     *
     * @return array<string, mixed>
     */
    public function toSolrArray(?Database $db = null)
    {
        $data = $this->getFullTextFields($this->doc);

        $doc = $this->doc->children($this->recordNs);
        $data['record_format'] = 'doaj';
        $data['ctrlnum'] = $this->getID();
        $data['fullrecord'] = $doc->asXML();

        // allfields
        $allFields = [];
        foreach ($doc as $field) {
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

        $data['format'] = $this->getFormat();

        $getAuthor = function ($xml) {
            return (string)($xml->author->name ?? '');
        };
        $data['author'] = array_filter(
            array_values(
                array_map($getAuthor, iterator_to_array($doc->authors))
            )
        );

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

        $getTopic = function ($xml) {
            return (string)($xml->keyword ?? '');
        };
        $data['topic'] = $data['topic_facet'] = array_filter(
            array_values(
                array_map($getTopic, iterator_to_array($doc->keywords))
            )
        );

        $data['url'] = $doc->fullTextUrl;

        return $data;
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
        $title = trim((string)$this->doc->children($this->recordNs)->title);
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
        return trim((string)($this->doc->children($this->recordNs)?->authors?->author?->name ?? ''));
    }

    /**
     * Dedup: Return ISBNs in ISBN-13 format without dashes
     *
     * @return array
     */
    public function getISBNs()
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
        if (preg_match('{^(\d{4})$}', $date)) {
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
     * Get DOIs
     *
     * @return array
     */
    protected function getDOIs(): array
    {
        return [];
    }
}

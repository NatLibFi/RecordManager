<?php

/**
 * Forward record class
 *
 * PHP version 8
 *
 * Copyright (C) The National Library of Finland 2016-2019.
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
use function is_array;

/**
 * Forward record class
 *
 * This is a class for processing records in the Forward format (EN 15907).
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class Forward extends AbstractRecord
{
    use XmlRecordTrait;

    /**
     * Default primary author relator codes, may be overridden in configuration.
     *
     * @var array
     */
    protected $primaryAuthorRelators = [
        'd02', 'a00', 'a03', 'a06', 'a50', 'a99',
    ];

    /**
     * Default secondary author relator codes, may be overridden in configuration.
     *
     * @var array
     */
    protected $secondaryAuthorRelators = [
        'd01', 'e01', 'f01', 'f02',
    ];

    /**
     * Default corporate author relator codes, may be overridden in configuration.
     *
     * @var array
     */
    protected $corporateAuthorRelators = [
    ];

    /**
     * Fields to leave out from allfields
     *
     * @var array
     */
    protected $filterFromAllFields = [
        'Identifier', 'RecordSource', 'TitleRelationship', 'Activity',
        'AgentIdentifier', 'ProductionEventType', 'DescriptionType', 'Language',
    ];

    /**
     * Primary language to use
     *
     * @var string
     */
    protected $primaryLanguage = 'en';

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
        parent::__construct($config, $dataSourceConfig, $logger, $metadataUtils);

        if (isset($config['ForwardRecord']['primary_author_relators'])) {
            $this->primaryAuthorRelators = explode(
                ',',
                $config['ForwardRecord']['primary_author_relators']
            );
        }
        if (isset($config['ForwardRecord']['secondary_author_relators'])) {
            $this->secondaryAuthorRelators = explode(
                ',',
                $config['ForwardRecord']['secondary_author_relators']
            );
        }
        if (isset($config['ForwardRecord']['corporate_author_relators'])) {
            $this->corporateAuthorRelators = explode(
                ',',
                $config['ForwardRecord']['corporate_author_relators']
            );
        }
    }

    /**
     * Return record ID (local)
     *
     * @return string
     */
    public function getID()
    {
        $doc = $this->getMainElement();
        $id = (string)$doc->Identifier;
        $attributes = $doc->Identifier->attributes();
        if (!empty($attributes['IDTypeName'])) {
            $id = ((string)$attributes['IDTypeName']) . '_' . $id;
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
        $data = [];

        $doc = $this->getMainElement();
        $data['record_format'] = 'forward';
        $data['ctrlnum'] = $this->getID();
        $data['fullrecord'] = $this->toXML();
        $publishDate = (string)$doc->YearOfReference;
        $data['publishDate'] = $publishDate;
        $data['title'] = $this->getTitle();
        foreach ($doc->Title as $title) {
            $titleText = (string)$title->TitleText;
            if ($titleText != $data['title']) {
                $data['title_alt'][] = $titleText;
            }
        }
        $data['title_short'] = $data['title_full'] = $data['title'];
        $data['title_sort'] = $this->getTitle(true);

        $descriptions = $this->getDescriptions($this->primaryLanguage);
        if (empty($descriptions)) {
            $descriptions = $this->getDescriptions();
        }
        $contents = $this->getContents($this->primaryLanguage);
        if (empty($contents)) {
            $contents = $this->getContents();
        }
        $descriptions = [...$descriptions, ...$contents];
        $data['description'] = implode(' ', $descriptions);

        $data['topic'] = $data['topic_facet'] = $this->getSubjects();
        $data['url'] = $this->getUrls();
        $data['thumbnail'] = $this->getThumbnail();

        $primaryAuthors = $this->getPrimaryAuthorsSorted();
        $data['author'] = $primaryAuthors['names'];

        // Support for author_variant is currently not implemented
        $data['author_role'] = $primaryAuthors['relators'];
        if (isset($primaryAuthors['names'][0])) {
            $data['author_sort'] = $primaryAuthors['names'][0];
        }

        $secondaryAuthors = $this->getSecondaryAuthors();
        $data['author2'] = $secondaryAuthors['names'];
        // Support for author2_variant is currently not implemented
        $data['author2_role'] = $secondaryAuthors['relators'];

        $corporateAuthors = $this->getCorporateAuthors();
        $data['author_corporate'] = $corporateAuthors['names'];
        $data['author_corporate_role'] = $corporateAuthors['relators'];

        $data['geographic'] = $data['geographic_facet']
            = $this->getGeographicSubjects();

        $data['genre'] = $data['genre_facet'] = $this->getGenres();

        $data['url'] = $this->getUrls();

        $data['format'] = $this->getFormat();

        $data['publisher'] = $this->getPublishers();

        // allfields
        $data['allfields'] = $this->getAllFields();

        return $data;
    }

    /**
     * Return main author (format: Last, First)
     *
     * @return string
     */
    public function getMainAuthor()
    {
        $authors = $this->getPrimaryAuthorsSorted();
        $author = $authors['names'][0] ?? '';
        if ($author) {
            if (!str_contains($author, ',')) {
                $author = $this->metadataUtils->convertAuthorLastFirst($author);
            }
        }
        return $author;
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
        $doc = $this->getMainElement();
        $title = (string)$doc->IdentifyingTitle;

        if ($forFiling) {
            $title = $this->metadataUtils->createSortTitle($title);
        }

        return $title;
    }

    /**
     * Return format from predefined values
     *
     * @return string|array
     */
    public function getFormat()
    {
        return 'MotionPicture';
    }

    /**
     * Get the main metadata element
     *
     * @return \SimpleXMLElement
     */
    protected function getMainElement()
    {
        $nodes = (array)$this->doc->children();
        $node = reset($nodes);
        return is_array($node) ? reset($node) : $node;
    }

    /**
     * Recursive function to get fields to be indexed in allfields
     *
     * @param string $fields Fields to use (optional)
     *
     * @return array<int, string>
     */
    protected function getAllFields($fields = null)
    {
        $results = [];
        if (null === $fields) {
            $fields = $this->getMainElement();
        }
        foreach ($fields as $tag => $field) {
            if (in_array($tag, $this->filterFromAllFields)) {
                continue;
            }
            $s = trim((string)$field);
            if ($s && ($s = $this->metadataUtils->stripTrailingPunctuation($s))) {
                $results[] = $s;
            }
            $subs = $this->getAllFields($field->children());
            if ($subs) {
                $results = [...$results, ...$subs];
            }
        }
        return $results;
    }

    /**
     * Get all authors or authors by relator codes.
     *
     * @param array $relators List of allowed relators, or an empty list
     *                        to return all authors.
     *
     * @return array
     */
    protected function getAuthorsByRelator($relators = [])
    {
        $result = ['names' => [], 'ids' => [], 'relators' => []];
        foreach ($this->getMainElement()->HasAgent as $agent) {
            $relator = $this->getRelator($agent);
            if (!empty($relators) && !in_array($relator, $relators)) {
                continue;
            }
            $result['names'][] = (string)$agent->AgentName;
            $id = (string)$agent->AgentIdentifier->IDTypeName . ':'
                . (string)$agent->AgentIdentifier->IDValue;
            if ($id != ':') {
                $result['ids'][] = $id;
            }
            $result['relators'][] = $relator;
        }

        return $result;
    }

    /**
     * Get relator code for the agent
     *
     * @param \SimpleXMLElement $agent Agent
     *
     * @return string
     */
    protected function getRelator($agent)
    {
        return $this->metadataUtils->normalizeRelator((string)$agent->Activity);
    }

    /**
     * Get primary authors
     *
     * @return array
     */
    protected function getPrimaryAuthors()
    {
        return $this->getAuthorsByRelator($this->primaryAuthorRelators);
    }

    /**
     * Get secondary authors
     *
     * @return array
     */
    protected function getSecondaryAuthors()
    {
        return $this->getAuthorsByRelator($this->secondaryAuthorRelators);
    }

    /**
     * Get corporate authors
     *
     * @return array
     */
    protected function getCorporateAuthors()
    {
        return $this->getAuthorsByRelator($this->corporateAuthorRelators);
    }

    /**
     * Get sorted primary authors with names and relators.
     *
     * @return array
     */
    protected function getPrimaryAuthorsSorted()
    {
        $unsortedPrimaryAuthors = $this->getPrimaryAuthors();
        // Make sure directors are first of the primary authors
        $directors = $others = [
            'names' => [],
            'relators' => [],
        ];
        foreach ($unsortedPrimaryAuthors['relators'] as $i => $relator) {
            if ('d02' === $relator) {
                $directors['names'][] = $unsortedPrimaryAuthors['names'][$i];
                $directors['relators'][] = $unsortedPrimaryAuthors['relators'][$i];
            } else {
                $others['names'][] = $unsortedPrimaryAuthors['names'][$i];
                $others['relators'][] = $unsortedPrimaryAuthors['relators'][$i];
            }
        }
        return [
            'names' => [...$directors['names'], ...$others['names']],
            'relators' => [...$directors['relators'], ...$others['relators']],
        ];
    }

    /**
     * Get contents
     *
     * @param string $language Optionally take only description in the given language
     *
     * @return array<int, string>
     */
    protected function getContents($language = null)
    {
        $results = [];
        foreach ($this->getMainElement()->ContentDescription as $description) {
            if (null !== $language && (string)$description->Language !== $language) {
                continue;
            }
            if (
                (string)$description->DescriptionType == 'Content description'
                && !empty($description->DescriptionText)
            ) {
                $results[] = (string)$description->DescriptionText;
            }
        }
        return $results;
    }

    /**
     * Get all descriptions
     *
     * @param string $language Optionally take only description in the given language
     *
     * @return array<int, string>
     */
    protected function getDescriptions($language = null)
    {
        $results = [];
        foreach ($this->getMainElement()->ContentDescription as $description) {
            if (null !== $language && (string)$description->Language !== $language) {
                continue;
            }
            if (
                (string)$description->DescriptionType == 'Synopsis'
                && !empty($description->DescriptionText)
            ) {
                $results[] = (string)$description->DescriptionText;
            }
        }
        return $results;
    }

    /**
     * Return genres
     *
     * @return array
     */
    protected function getGenres()
    {
        return [];
    }

    /**
     * Get geographic subjects
     *
     * @return array
     */
    protected function getGeographicSubjects()
    {
        $result = [];
        foreach ($this->getMainElement()->CountryOfReference as $country) {
            if (!empty($country->Country->RegionName)) {
                $result[] = (string)$country->Country->RegionName;
            }
        }
        return $result;
    }

    /**
     * Return publishers
     *
     * @return array
     */
    protected function getPublishers()
    {
        return [];
    }

    /**
     * Get all subjects
     *
     * @return array<int, string>
     */
    protected function getSubjects()
    {
        $results = [];
        foreach ($this->getMainElement()->SubjectTerms as $subjectTerms) {
            foreach ($subjectTerms->Term as $term) {
                $results[] = (string)$term;
            }
        }
        return $results;
    }

    /**
     * Get thumbnail
     *
     * @return string
     */
    protected function getThumbnail()
    {
        return '';
    }

    /**
     * Get URLs
     *
     * @return array
     */
    protected function getUrls()
    {
        return [];
    }
}

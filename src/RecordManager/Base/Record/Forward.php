<?php
/**
 * Forward record class
 *
 * PHP version 5
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
 * @link     https://github.com/KDK-Alli/RecordManager
 */
namespace RecordManager\Base\Record;

use RecordManager\Base\Utils\Logger;
use RecordManager\Base\Utils\MetadataUtils;

/**
 * Forward record class
 *
 * This is a class for processing records in the Forward format (EN 15907).
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class Forward extends Base
{
    /**
     * The XML document
     *
     * @var SimpleXMLElement
     */
    protected $doc = null;

    /**
     * Default primary author relator codes, may be overridden in configuration.
     *
     * @var array
     */
    protected $primaryAuthorRelators = [
        'd02', 'a00', 'a03', 'a06', 'a50', 'a99'
    ];

    /**
     * Default secondary author relator codes, may be overridden in configuration.
     *
     * @var array
     */
    protected $secondaryAuthorRelators = [
        'd01', 'e01', 'f01', 'f02'
    ];

    /**
     * Default corporate author relator codes, may be overridden in configuration.
     *
     * @var array
     */
    protected $corporateAuthorRelators = [
    ];

    protected $filterFromAllFields = [
        'Identifier', 'RecordSource', 'TitleRelationship', 'Activity',
        'AgentIdentifier', 'ProductionEvent', 'DescriptionType', 'Language'
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
     * @param Logger $logger             Logger
     * @param array  $config             Main configuration
     * @param array  $dataSourceSettings Data source settings
     */
    public function __construct(Logger $logger, $config, $dataSourceSettings)
    {
        parent::__construct($logger, $config, $dataSourceSettings);

        if (isset($config['ForwardRecord']['primary_author_relators'])) {
            $this->primaryAuthorRelators = explode(
                ',', $config['ForwardRecord']['primary_author_relators']
            );
        }
        if (isset($config['ForwardRecord']['secondary_author_relators'])) {
            $this->secondaryAuthorRelators = explode(
                ',', $config['ForwardRecord']['secondary_author_relators']
            );
        }
        if (isset($config['ForwardRecord']['corporate_author_relators'])) {
            $this->corporateAuthorRelators = explode(
                ',', $config['ForwardRecord']['corporate_author_relators']
            );
        }
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
        if (!empty($attributes->IDTypeName)) {
            $id = (string)$attributes->IDTypeName . '_' . $id;
        }
        return $id;
    }

    /**
     * Serialize the record for storing in the database
     *
     * @return string
     */
    public function serialize()
    {
        return MetadataUtils::trimXMLWhitespace($this->doc->asXML());
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
     * @return array
     */
    public function toSolrArray()
    {
        $data = [];

        $doc = $this->getMainElement();
        $data['record_format'] = $data['recordtype'] = 'forward';
        $data['ctrlnum'] = $this->getID();
        $data['fullrecord'] = $this->toXML();
        $publishDate = (string)$doc->YearOfReference;
        $data['publishDate'] = $publishDate;
        $data['title'] = (string)$doc->IdentifyingTitle;
        foreach ($doc->Title as $title) {
            $titleText = (string)$title->TitleText;
            if ($titleText != $data['title']) {
                $data['title_alt'][] = $titleText;
            }
        }
        $data['title_short'] = $data['title_full'] = $data['title'];
        $data['title_sort'] = MetadataUtils::stripLeadingPunctuation(
            MetadataUtils::stripLeadingArticle($data['title'])
        );

        $descriptions = $this->getDescriptions($this->primaryLanguage);
        if (empty($descriptions)) {
            $descriptions = $this->getDescriptions();
        }
        $contents = $this->getContents($this->primaryLanguage);
        if (empty($contents)) {
            $contents = $this->getContents();
        }
        $descriptions = array_merge($descriptions, $contents);
        $data['description'] = implode(' ', $descriptions);

        $data['topic'] = $data['topic_facet'] = $this->getSubjects();
        $data['url'] = $this->getUrls();
        $data['thumbnail'] = $this->getThumbnail();

        $unsortedPrimaryAuthors = $this->getPrimaryAuthors();
        // Make sure directors are first of the primary authors
        $directors = $others = [
            'names' => [],
            'ids' => [],
            'relators' => [],
            'idRoles' => []
        ];
        foreach ($unsortedPrimaryAuthors['relators'] as $i => $relator) {
            if ('d02' === $relator) {
                $directors['names'][] = $unsortedPrimaryAuthors['names'][$i];
                $directors['ids'][] = $unsortedPrimaryAuthors['ids'][$i] ?? null;
                $directors['idRoles'][]
                    = $unsortedPrimaryAuthors['idRoles'][$i] ?? null;
                $directors['relators'][] = $unsortedPrimaryAuthors['relators'][$i];
            } else {
                $others['names'][] = $unsortedPrimaryAuthors['names'][$i];
                $others['ids'][] = $unsortedPrimaryAuthors['ids'][$i] ?? null;
                $others['idRoles'][]
                    = $unsortedPrimaryAuthors['idRoles'][$i] ?? null;
                $others['relators'][] = $unsortedPrimaryAuthors['relators'][$i];
            }
        }
        $primaryAuthors = [
            'names' => array_merge($directors['names'], $others['names']),
            'ids' => array_merge($directors['ids'], $others['ids']),
            'relators' => array_merge($directors['relators'], $others['relators']),
            'idRoles' => array_merge($directors['idRoles'], $others['idRoles'])
        ];

        $data['author'] = $primaryAuthors['names'];
        $data['author_id_str_mv']
            = $this->addNamespaceToAuthRecord($primaryAuthors['ids']);
        $data['author_id_role_str_mv']
            = $this->addNamespaceToAuthRecord($primaryAuthors['idRoles']);

        // Support for author_variant is currently not implemented
        $data['author_role'] = $primaryAuthors['relators'];
        if (isset($primaryAuthors['names'][0])) {
            $data['author_sort'] = $primaryAuthors['names'][0];
        }

        $secondaryAuthors = $this->getSecondaryAuthors();
        $data['author2'] = $secondaryAuthors['names'];
        // Support for author2_variant is currently not implemented
        $data['author2_role'] = $secondaryAuthors['relators'];

        $allAuthors = $this->getAuthorsByRelator();
        $data['author2_id_str_mv']
            = $this->addNamespaceToAuthRecord($allAuthors['ids']);
        $data['author2_id_role_str_mv']
            = $this->addNamespaceToAuthRecord($allAuthors['idRoles']);

        $corporateAuthors = $this->getCorporateAuthors();
        $data['author_corporate'] = $corporateAuthors['names'];
        $data['author_corporate_id_str_mv']
            = $this->addNamespaceToAuthRecord($corporateAuthors['ids']);
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
     * Get the main metadata element
     *
     * @return SimpleXMLElement
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
     * @return array
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
            $results[] = MetadataUtils::stripTrailingPunctuation(
                trim((string)$field)
            );
            $subs = $this->getAllFields($field->children());
            if ($subs) {
                $results = array_merge($results, $subs);
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
        $result = ['names' => [], 'ids' => [], 'relators' => [], 'idRoles' => []];
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
                $result['idRoles'][]
                    = $this->formatAuthorIdWithRole($id, $relator);
            }
            $result['relators'][] = $relator;
        }

        return $result;
    }

    /**
     * Get relator code for the agent
     *
     * @param SimpleXMLElement $agent Agent
     *
     * @return string
     */
    protected function getRelator($agent)
    {
        return MetadataUtils::normalizeRelator((string)$agent->Activity);
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
     * Get contents
     *
     * @param string $language Optionally take only description in the given language
     *
     * @return array
     */
    protected function getContents($language = null)
    {
        $results = [];
        foreach ($this->getMainElement()->ContentDescription as $description) {
            if (null !== $language && (string)$description->Language !== $language) {
                continue;
            }
            if ((string)$description->DescriptionType == 'Content description'
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
     * @return array
     */
    protected function getDescriptions($language = null)
    {
        $results = [];
        foreach ($this->getMainElement()->ContentDescription as $description) {
            if (null !== $language && (string)$description->Language !== $language) {
                continue;
            }
            if ((string)$description->DescriptionType == 'Synopsis'
                && !empty($description->DescriptionText)
            ) {
                $results[] = (string)$description->DescriptionText;
            }
        }
        return $results;
    }

    /**
     * Return format from predefined values
     *
     * @return string
     */
    public function getFormat()
    {
        return 'MotionPicture';
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
     * @return array
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

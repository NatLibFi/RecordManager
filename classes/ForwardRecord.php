<?php
/**
 * ForwardRecord Class
 *
 * PHP version 5
 *
 * Copyright (C) The National Library of Finland 2016.
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
require_once 'BaseRecord.php';
require_once 'MetadataUtils.php';

/**
 * ForwardRecord Class
 *
 * This is a class for processing records in the Forward format (EN 15907).
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class ForwardRecord extends BaseRecord
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
        'A00', 'A03', 'A06', 'A50', 'A99'
    ];

    /**
     * Default secondary author relator codes, may be overridden in configuration.
     *
     * @var array
     */
    protected $secondaryAuthorRelators = [
        'D01', 'D02', 'E01', 'F01', 'F02'
    ];

    /**
     * Default corporate author relator codes, may be overridden in configuration.
     *
     * @var array
     */
    protected $corporateAuthorRelators = [
    ];

    /**
     * Constructor
     *
     * @param string $data     Metadata
     * @param string $oaiID    Record ID received from OAI-PMH (or empty string for
     * file import)
     * @param string $source   Source ID
     * @param string $idPrefix Record ID prefix
     */
    public function __construct($data, $oaiID, $source, $idPrefix)
    {
        parent::__construct($data, $oaiID, $source, $idPrefix);

        global $configArray;
        if (isset($configArray['ForwardRecord']['primary_author_relators'])) {
            $this->primaryAuthorRelators = explode(
                ',', $configArray['ForwardRecord']['primary_author_relators']
            );
        }
        if (isset($configArray['ForwardRecord']['secondary_author_relators'])) {
            $this->secondaryAuthorRelators = explode(
                ',', $configArray['ForwardRecord']['secondary_author_relators']
            );
        }
        if (isset($configArray['ForwardRecord']['corporate_author_relators'])) {
            $this->corporateAuthorRelators = explode(
                ',', $configArray['ForwardRecord']['corporate_author_relators']
            );
        }

        $this->doc = simplexml_load_string($data);
    }

    /**
     * Return record ID (local)
     *
     * @return string
     */
    public function getID()
    {
        return (string)$this->getMainElement()->Identifier;
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
     * @return string[]
     */
    public function toSolrArray()
    {
        $data = [];

        $doc = $this->getMainElement();
        $data['ctrlnum'] = $this->getID();
        $data['fullrecord'] = $this->toXML();
        $data['title'] = (string)$doc->IdentifyingTitle;
        $originalTitle = '';
        $translatedTitles = [];
        $otherTitles = [];
        foreach ($doc->Title as $title) {
            $titleText = (string)$title->TitleText;
            if ($titleText != $data['title']) {
                $data['title_alt'][] = $titleText;
            }
        }

        if (!empty($originalTitle)) {
            if (empty($data['title'])) {
                $data['title'] = $originalTitle;
            } else {
                $data['title_alt'][] = $originalTitle;
            }
        }
        foreach (array_merge($translatedTitles, $otherTitles) as $title) {
            if ($title == $data['title']) {
                continue;
            }
            $data['title_alt'][] = $title;
        }
        $data['title_full'] = $data['title_short'] = $data['title'];
        $data['title_sort'] = MetadataUtils::stripLeadingPunctuation(
            MetadataUtils::stripLeadingArticle($data['title'])
        );

        $data['publishDate'] = (string)$doc->YearOfReference;

        foreach ($doc->ContentDescription as $description) {
            $data['description'][] = (string)$description->DescriptionText;
        }
        foreach ($doc->SubjectTerms as $term) {
            $data['topic'][] = $term;
        }

        $primaryAuthors = $this->getPrimaryAuthors();
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

        foreach ($doc->CountryOfReference as $country) {
            $data['geographic'][] = (string)$country->Country->RegionName;
        }

        $data['format'] = 'MotionPicture';

        // allfields
        $allFields = [];
        foreach ($doc->children() as $tag => $field) {
            $allFields[] = MetadataUtils::stripTrailingPunctuation(
                trim((string)$field)
            );
        }
        $data['allfields'] = $allFields;

        return $data;
    }

    protected function getMainElement()
    {
        $nodes = $this->doc->children();
        return reset($nodes);
    }

    /**
     * Normalize a relator code
     *
     * @param string $relator Relator
     *
     * @return string
     */
    protected function normalizeRelator($relator)
    {
        $relator = trim($relator);
        $relator = preg_replace('/\p{P}+/', '', $relator);
        return $relator;
    }

    /**
     * Get authors by relator codes
     *
     * @param array $relators Allowed relators
     *
     * @return array Array keyed by 'names' for author names, 'ids' for author ids
     * and 'relators' for relator codes
     */
    protected function getAuthorsByRelator($relators)
    {
        $result = ['names' => [], 'ids' => [], 'relators' => []];
        foreach ($this->getMainElement()->HasAgent as $agent) {
            $relator = $this->normalizeRelator((string)$agent->Activity);
            if (!in_array($relator, $relators)) {
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
}

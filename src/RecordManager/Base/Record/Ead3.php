<?php
/**
 * EAD 3 Record Class
 *
 * PHP version 5
 *
 * Copyright (C) The National Library of Finland 2011-2017.
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
 * @author   Jukka Lehmus <jlehmus@mappi.helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
namespace RecordManager\Base\Record;

use RecordManager\Base\Utils\MetadataUtils;

/**
 * EAD 3 Record Class
 *
 * This is a class for processing EAD 3 records.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @author   Jukka Lehmus <jlehmus@mappi.helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class Ead3 extends Ead
{
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
        if (isset($this->doc->{'add-data'})
            && isset($this->doc->{'add-data'}->attributes()->identifier)
        ) {
            return (string)$this->doc->{'add-data'}->attributes()->identifier;
        }
        if (isset($this->doc->control->recordid)) {
            $id = (string)$this->doc->control->recordid;
        } else {
            throw new \Exception('No ID found for record: ' . $this->doc->asXML());
        }
        return urlencode($id);
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

        $doc = $this->doc;
        $data['record_format'] = $data['recordtype'] = 'ead3';
        $data['ctrlnum'] = (string)$this->doc->attributes()->{'id'};
        $data['fullrecord'] = MetadataUtils::trimXMLWhitespace($doc->asXML());
        $data['allfields'] = $this->getAllFields($doc);
        $data['description'] = $this->getDescription();
        $data['author'] = $this->getAuthors();
        $data['author_sort'] = reset($data['author']);
        $data['author_corporate'] = $this->getCorporateAuthors();
        $data['author2'] = $this->getSecondaryAuthors();
        $data['geographic'] = $data['geographic_facet']
            = $this->getGeographicTopics();
        $data['topic'] = $data['topic_facet'] = $this->getTopics();
        $data['format'] = $this->getFormat();
        $data['institution'] = $this->getInstitution();
        $data['series'] = $this->getSeries();
        $data['title_sub'] = $this->getSubtitle();
        $data['title_short'] = $this->getTitle();
        $data['title'] = '';
        if ($this->getDriverParam('prependTitleWithSubtitle', true)) {
            if (!empty($data['title_sub'])
                && $data['title_sub'] != $data['title_short']
            ) {
                $data['title'] = $data['title_sub'] . ' ';
            }
        }
        $data['title'] .= $data['title_short'];
        $data['title_full'] = $data['title_sort'] = $data['title'];
        $data['title_sort'] = mb_strtolower(
            MetadataUtils::stripLeadingPunctuation($data['title_sort']), 'UTF-8'
        );

        $data['language'] = $this->getLanguages();
        $data['physical'] = $this->getPhysicalExtent();
        $data['thumbnail'] = $this->getThumbnail();

        $data = array_merge($data, $this->getHierarchyFields());

        return $data;
    }

    /**
     * Return format from predefined values
     *
     * @return string
     */
    public function getFormat()
    {
        if (isset($this->doc->did->controlaccess->genreform->part)) {
            return (string)$this->doc->did->controlaccess->genreform->part;
        }
        return (string)$this->doc->attributes()->level;
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
        $title = isset($this->doc->did->unittitle)
            ? (string)$this->doc->did->unittitle
            : '';

        if ($forFiling) {
            $title = MetadataUtils::stripLeadingPunctuation($title);
            $title = MetadataUtils::stripLeadingArticle($title);
            // Again, just in case stripping the article affected this
            $title = MetadataUtils::stripLeadingPunctuation($title);
            $title = mb_strtolower($title, 'UTF-8');
        }

        return $title;
    }

    /**
     *  Get description
     *
     * @return string
     */
    protected function getDescription()
    {
        if (!empty($this->doc->scopecontent)) {
            if (!empty($this->doc->scopecontent->p)) {
                // Join all p-elements into a flat string.
                $desc = [];
                foreach ($this->doc->scopecontent->p as $p) {
                    $desc[] = trim((string)$p);
                }
                return implode('   /   ', $desc);
            }
            return (string)$this->doc->scopecontent;
        }
        return '';
    }

    /**
     * Get authors
     *
     * @return array
     */
    protected function getAuthors()
    {
        $result = [];
        if (isset($this->doc->did->controlaccess->name)) {
            foreach ($this->doc->did->controlaccess->name as $name) {
                foreach ($name->part as $part) {
                    $result[] = trim((string)$part);
                }
            }
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
        $result = [];
        if (isset($this->doc->did->controlaccess->corpname)) {
            foreach ($this->doc->did->controlaccess->corpname as $corpname) {
                $result[] = trim((string)$corpname);
            }
        }

        if (isset($this->doc->did->origination->name)) {
            foreach ($this->doc->did->origination->name as $name) {
                foreach ($name->part as $part) {
                    $result[] = trim((string)$part);
                }
            }
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
        if (!empty($this->doc->did->origination->persname)) {
            $result[] = trim(
                (string)$this->doc->did->origination->persname
            );
        }
        return $result;
    }

    /**
     * Get geographic topics
     *
     * @return array
     */
    protected function getGeographicTopics()
    {
        $result = [];
        if (!isset($this->doc->did->controlaccess->geogname)) {
            return $result;
        }
        foreach ($this->doc->did->controlaccess->geogname as $name) {
            if (trim((string)$name) !== '-') {
                $result[] = trim((string)$name);
            }
        }
        return $result;
    }

    /**
     * Get topics
     *
     * @return array
     */
    protected function getTopics()
    {
        $result = [];
        if (!isset($this->doc->did->controlaccess->subject)) {
            return $result;
        }
        foreach ($this->doc->did->controlaccess->subject as $subject) {
            if (trim((string)$subject) !== '-') {
                $result[] = trim((string)$subject);
            }
        }
        return $reslt;
    }

    /**
     * Get institution
     *
     * @return string
     */
    protected function getInstitution()
    {
        return isset($this->doc->did->repository->corpname->part)
            ? (string)$this->doc->did->repository->corpname->part
            : '';
    }

    /**
     * Get languages
     *
     * @return array
     */
    protected function getLanguages()
    {
        $result = [];
        if (!isset($this->doc->did->langmaterial->language)) {
            return $result;
        }
        foreach ($this->doc->did->langmaterial->language as $lang) {
            if (isset($lang->attributes()->langcode)) {
                $langCode = trim((string)$lang->attributes()->langcode);
                if ($langCode != '') {
                    $result[] = $langCode;
                }
            }
        }
        return $result;
    }

    /**
     * Get physical extent
     *
     * @return array
     */
    protected function getPhysicalExtent()
    {
        $result = [];
        if (!isset($this->doc->did->physdesc->extent)) {
            return $result;
        }
        foreach ($this->doc->did->physdesc->extent as $extent) {
            if (trim((string)$extent) !== '-') {
                $result[] = (string)$extent;
            }
        }
        return $result;
    }

    /**
     * Get thumbnail
     *
     * @return string
     */
    protected function getThumbnail()
    {
        $nodes = isset($this->doc->did->daogrp)
            ? $this->doc->did->daogrp->xpath('daoloc[@role="image_thumbnail"]')
            : null;
        if ($nodes) {
            // store first thumbnail
            $node = $nodes[0];
            if (isset($node->attributes()->href)) {
                return (string)$node->attributes()->href;
            }
        }
        return '';
    }

    /**
     * Get unit id
     *
     * @return string
     */
    protected function getUnitId()
    {
        return (string)$this->doc->did->unitid;
    }

    /**
     * Get hierarchy fields
     *
     * @return array
     */
    protected function getHierarchyFields()
    {
        $data = [
            'hierarchytype' => 'Default'
        ];
        if ($this->doc->{'add-data'}->archive) {
            $archiveAttr = $this->doc->{'add-data'}->archive->attributes();
            $data['hierarchy_top_id'] = (string)$archiveAttr->{'id'};
            $data['hierarchy_top_title'] = (string)$archiveAttr->title;
            if ($archiveAttr->subtitle) {
                $data['hierarchy_top_title'] .= ' : '
                    . (string)$archiveAttr->subtitle;
            }
            $data['allfields'][] = $data['hierarchy_top_title'];
            if ($archiveAttr->sequence) {
                $data['hierarchy_sequence'] = (string)$archiveAttr->sequence;
            }
        }
        if ($this->doc->{'add-data'}->{'parent'}) {
            $data['hierarchy_parent_id']
                = (string)$this->doc->{'add-data'}->{'parent'}->attributes()->{'id'};
            $data['hierarchy_parent_title']
                = (string)$this->doc->{'add-data'}->{'parent'}->attributes()->title;
        } else {
            $data['is_hierarchy_id'] = $data['hierarchy_top_id'] = $this->getID();
            $data['is_hierarchy_title'] = $data['hierarchy_top_title']
                = isset($this->doc->did->unittitle)
                    ? (string)$this->doc->did->unittitle->attributes()->label
                    : '';
        }

        return $data;
    }

    /**
     * Get all XML fields
     *
     * @param SimpleXMLElement $xml The XML document
     *
     * @return array
     */
    protected function getAllFields($xml)
    {
        $allFields = [];
        foreach ($xml->children() as $tag => $field) {
            $s = trim((string)$field);
            if ($s) {
                $allFields[] = $s;
            }
            $s = $this->getAllFields($field);
            if ($s) {
                $allFields = array_merge($allFields, $s);
            }
        }
        return $allFields;
    }
}

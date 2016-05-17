<?php
/**
 * EadRecord Class
 *
 * PHP version 5
 *
 * Copyright (C) The National Library of Finland 2011-2016.
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

/**
 * EadRecord Class
 *
 * This is a class for processing EAD records.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class EadRecord extends BaseRecord
{
    protected $doc = null;

    /**
     * Constructor
     *
     * @param string $data     Metadata
     * @param string $oaiID    Record ID received from OAI-PMH
     * (or empty string for file import)
     * @param string $source   Source ID
     * @param string $idPrefix Record ID prefix
     */
    public function __construct($data, $oaiID, $source, $idPrefix)
    {
        parent::__construct($data, $oaiID, $source, $idPrefix);

        $this->doc = simplexml_load_string($data);
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
        if (isset($this->doc->did->unitid)) {
            $id = isset($this->doc->did->unitid->attributes()->identifier)
                ? (string)$this->doc->did->unitid->attributes()->identifier
                : (string)$this->doc->did->unitid;
        } else {
            die('No ID found for record: ' . $this->doc->asXML());
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
     * @param boolean $prependTitleWithSubtitle If true and title_sub differs from
     * title_short, title is formed by combining title_sub and title_short
     *
     * @return string[]
     */
    public function toSolrArray($prependTitleWithSubtitle)
    {
        $data = [];

        $doc = $this->doc;
        $data['ctrlnum'] = (string)$this->doc->attributes()->{'id'};
        $data['fullrecord'] = MetadataUtils::trimXMLWhitespace($doc->asXML());
        $data['allfields'] = $this->getAllFields($doc);

        if ($doc->scopecontent) {
            if ($doc->scopecontent->p) {
                // Join all p-elements into a flat string.
                $desc = [];
                foreach ($doc->scopecontent->p as $p) {
                    $desc[] = trim((string)$p);
                }
                $desc = implode('   /   ', $desc);
            } else {
                $desc = (string)$doc->scopecontent;
            }
            $data['description'] = $desc;
        }

        if ($names = $doc->xpath('controlaccess/persname')) {
            foreach ($names as $name) {
                if (trim((string)$name) !== '-') {
                    $data['author'][] = trim((string)$name);
                }
            }
        }
        if (!empty($data['author'])) {
            $data['author_sort'] = $data['author'][0];
        }

        if ($names = $doc->xpath('controlaccess/corpname')) {
            foreach ($names as $name) {
                $data['author_corporate'][] = trim((string)$name);
            }
        }

        if ($doc->did->origination->corpname) {
            $data['author_corporate'] = trim(
                (string)$doc->did->origination->corpname
            );
        }
        if ($doc->did->origination->persname) {
            $data['author2'] = trim(
                (string)$doc->did->origination->persname
            );
        }

        if ($geoNames = $doc->xpath('controlaccess/geogname')) {
            $names = [];
            foreach ($geoNames as $name) {
                if (trim((string)$name) !== '-') {
                    $names[] = trim((string)$name);
                }
            }
            $data['geographic'] = $data['geographic_facet'] = $names;
        }

        if ($subjects = $doc->xpath('controlaccess/subject')) {
            $topics = [];
            foreach ($subjects as $subject) {
                if (trim((string)$subject) !== '-') {
                    $topics[] = trim((string)$subject);
                }
            }
            $data['topic'] = $data['topic_facet'] = $topics;
        }

        $genre = $doc->xpath('controlaccess/genreform');
        $data['format'] = (string) ($genre ? $genre[0] : $doc->attributes()->level);

        if (isset($doc->did->repository)) {
            $data['institution']
                = (string) isset($doc->did->repository->corpname)
                ? $doc->did->repository->corpname
                : $doc->did->repository;
        }

        $data['title_sub'] = '';

        switch ($data['format']) {
        case 'fonds':
            break;
        case 'collection':
            break;
        case 'series':
        case 'subseries':
            $data['title_sub'] = (string)$doc->did->unitid;
            break;
        default:
            $data['title_sub'] = (string)$doc->did->unitid;
            if ($doc->{'add-data'}->parent) {
                $data['series']
                    = (string)$doc->{'add-data'}->parent->attributes()->unittitle;
            }
            break;
        }

        $data['title_short'] = (string)$doc->did->unittitle;
        $data['title'] = '';
        if ($prependTitleWithSubtitle) {
            if ($data['title_sub'] && $data['title_sub'] != $data['title_short']) {
                $data['title'] = $data['title_sub'] . ' ';
            }
        }
        $data['title'] .= $data['title_short'];
        $data['title_full'] = $data['title_sort'] = $data['title'];
        $data['title_sort'] = mb_strtolower(
            MetadataUtils::stripLeadingPunctuation($data['title_sort']), 'UTF-8'
        );

        if ($languages = $doc->did->xpath('langmaterial/language')) {
            foreach ($languages as $lang) {
                if (isset($lang->attributes()->langcode)) {
                    $langCode = trim((string)$lang->attributes()->langcode);
                    if ($langCode != '') {
                        $data['language'][] = $langCode;
                    }
                }
            }
        }

        if ($extents = $doc->did->xpath('physdesc/extent')) {
            foreach ($extents as $extent) {
                if (trim((string)$extent) !== '-') {
                    $data['physical'][] = (string)$extent;
                }
            }
        }

        $nodes = $this->doc->did->daogrp->xpath('daoloc[@role="image_thumbnail"]');
        if ($nodes) {
            // store first thumbnail
            $node = $nodes[0];
            if (isset($node->attributes()->href)) {
                $data['thumbnail'] = (string)$node->attributes()->href;
            }
        }

        $data['hierarchytype'] = 'Default';
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
            $data['allfields'][] = $data['hierarchy_parent_title']
                = (string)$this->doc->{'add-data'}->{'parent'}->attributes()->title;
        } else {
            $data['is_hierarchy_id'] = $data['hierarchy_top_id'] = $this->getID();
            $data['is_hierarchy_title'] = $data['hierarchy_top_title']
                = (string)$doc->did->unittitle;
        }

        return $data;
    }

    /**
     * Get all XML fields
     *
     * @param SimpleXMLElement $xml The XML document
     *
     * @return string[]
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


<?php
/**
 * EadRecord Class
 *
 * PHP version 5
 *
 * Copyright (C) The National Library of Finland 2011-2013
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
     * @param string $data   Metadata
     * @param string $oaiID  Record ID received from OAI-PMH (or empty string for file import)
     * @param string $source Source ID
     */
    public function __construct($data, $oaiID, $source)
    {
        parent::__construct($data, $oaiID, $source);
        
        $this->doc = simplexml_load_string($data);
    }

    /**
     * Return record ID (local)
     *
     * @return string
     * @access public
     */
    public function getID()
    {
        if (isset($this->doc->{'add-data'}->attributes()->identifier)) {
            return (string)$this->doc->{'add-data'}->attributes()->identifier;
        }
        return isset($this->doc->did->unitid->attributes()->identifier) 
            ? (string)$this->doc->did->unitid->attributes()->identifier
            : (string)$this->doc->did->unitid;
    }

    /**
     * Serialize the record for storing in the database
     *
     * @return string
     * @access public
     */
    public function serialize()
    {
        return MetadataUtils::trimXMLWhitespace($this->doc->asXML());
    }

    /**
     * Serialize the record into XML for export
     *
     * @return string
     * @access public
     */
    public function toXML()
    {
        return $this->doc->asXML();
    }

    /**
     * Return fields to be indexed in Solr
     *
     * @return string[]
     * @access public
     */
    public function toSolrArray()
    {
        $data = array();
        
        $doc = $this->doc;
        $data['ctrlnum'] = (string)$this->doc->attributes()->{'id'};
        $data['fullrecord'] = MetadataUtils::trimXMLWhitespace($doc->asXML());
        $data['allfields'] = $this->getAllFields($doc);

        if ($doc->scopecontent) {
            $data['description'] = $doc->scopecontent->p ? (string)$doc->scopecontent->p : (string)$doc->scopecontent;
        }

        $authors = array();
        if (isset($doc->controlaccess->persname)) {
            foreach ($doc->controlaccess->persname as $name) {
                if (trim((string)$name) !== '-') {
                    $authors[] = (string)$name;
                }
            }
        }
        if (isset($doc->controlaccess->corpname)) {
            foreach ($doc->controlaccess->corpname as $name) {
                $authors[] = (string)$name;
            }
        }
        if ($authors) {
            $data['author'] = array_shift($authors);
            $data['author-letter'] = $data['author'];
        }
        if ($authors) {
            $data['author2'] = $authors;
        }

        if ($doc->did->origination) {
            $data['author_additional'] = (string)$doc->did->origination->corpname;
        }

        if (isset($doc->controlaccess->geogname)) {
            foreach ($doc->controlaccess->geogname as $name) {
                $data['geographic'][] = (string)$name;
            }
        }

        if (isset($doc->controlaccess->subject)) {
            foreach ($doc->controlaccess->subject as $name) {
                $data['topic'][] = (string)$name;
            }
        }

        $data['format'] = (string)$doc->attributes()->level;
        if (isset($doc->did)) {
            $data['institution'] = (string)$doc->did->repository;
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
                $data['series'] = (string)$doc->{'add-data'}->parent->attributes()->unittitle;
            }
            break;
        }

        $data['title_short'] = (string)$doc->did->unittitle;
        $data['title'] = ($data['title_sub'] ? $data['title_sub'] . ' ' : '') . $data['title_short'];
        $data['title_full'] = $data['title_sort'] = $data['title'];
        $data['title_sort'] = mb_strtolower(MetadataUtils::stripLeadingPunctuation($data['title_sort']), 'UTF-8');
        
        $data['hierarchytype'] = 'Default';
        if ($this->doc->{'add-data'}->archive) {
            $archiveAttr = $this->doc->{'add-data'}->archive->attributes();
            $data['hierarchy_top_id'] = (string)$archiveAttr->{'id'};
            $data['hierarchy_top_title'] = (string)$archiveAttr->title;
            if ($archiveAttr->subtitle) {
                $data['hierarchy_top_title'] .= ' : ' . (string)$archiveAttr->subtitle; 
            }
            if ($archiveAttr->sequence) {
                $data['hierarchy_sequence'] = (string)$archiveAttr->sequence;
            }
        }
        if ($this->doc->{'add-data'}->{'parent'}) {
            $data['hierarchy_parent_id'] = (string)$this->doc->{'add-data'}->{'parent'}->attributes()->{'id'};
            $data['hierarchy_parent_title'] = (string)$this->doc->{'add-data'}->{'parent'}->attributes()->title;
        } else {
            $data['is_hierarchy_id'] = $data['hierarchy_top_id'] = $this->getID();
            $data['is_hierarchy_title'] = $data['hierarchy_top_title'] = (string)$doc->did->unittitle;
        }        
        
        return $data;
    }

    /**
     * Get all XML fields
     * 
     * @param SimpleXMLDocument $xml The XML document
     * 
     * @return string[]
     */
    protected function getAllFields($xml)
    {
        $allFields = array();
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


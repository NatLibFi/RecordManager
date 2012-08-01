<?php
/**
 * EadRecord Class
 *
 * PHP version 5
 *
 * Copyright (C) Ere Maijala 2011-2012
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
     * @param string $data  Record metadata
     * @param string $oaiID Record ID in OAI-PMH
     * 
     * @access public
     */
    public function __construct($data, $oaiID)
    {
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
        return isset($this->doc->did->unitid->attributes()->{'identifier'}) 
            ? (string)$this->doc->did->unitid->attributes()->{'identifier'}
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
     * Set the ID prefix into all the ID fields (ID, host ID etc.)
     *
     * @param string $prefix (e.g. "source.")
     * 
     * @return void
     * @access public
     */
    public function setIDPrefix($prefix)
    {
        if ($this->doc->did->unitid) {
            $this->doc->did->unitid->attributes()->{'identifier'} = $prefix . $this->doc->did->unitid->attributes()->{'identifier'};
        }
        if ($this->doc->{'add-data'}) {
            if ($this->doc->{'add-data'}->{'archive'}) {
                $this->doc->{'add-data'}->{'archive'}->attributes()->{'id'} = $prefix . $this->doc->{'add-data'}->{'archive'}->attributes()->{'id'};
            } 
            if ($this->doc->{'add-data'}->{'parent'}) {
                $this->doc->{'add-data'}->{'parent'}->attributes()->{'id'} = $prefix . $this->doc->{'add-data'}->{'parent'}->attributes()->{'id'};
            } 
        }
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
        $data['fullrecord'] = str_replace("\t", ' ', $doc->asXML());
          
        // allfields
        $data['allfields'] = $this->getAllFields($doc);
          
        // language
        $data['format'] = (string)$doc->attributes()->level;
        if ($doc->did->origination) {
            $data['author'] = (string)$doc->did->origination->corpname;
        }

        $data['title'] = $data['title_short'] = (string)$doc->did->unittitle;
        if (in_array($data['format'], array('series', 'item'))) {
            $data['title_sub'] = (string)$doc->did->unitid;
        } else {
            $data['title_sub'] = '';
        }
        $data['title_full'] = $data['title_sort'] = $data['title'] . ($data['title_sub'] ? ' (' . $data['title_sub'] . ')' : '');

        switch ($doc->level) {
        case 'collection':
            break;
        case 'series':
            break;
        case 'item': 
            $data['series'] = (string)$doc->{'add-data'}->parent->attributes()->unittitle;
            break;
        }
        
        $data['hierarchytype'] = 'Default';
        if ($this->doc->{'add-data'}->{'archive'}) {
            $data['hierarchy_top_id'] = (string)$this->doc->{'add-data'}->archive->attributes()->{'id'};
            $data['hierarchy_top_title'] = (string)$this->doc->{'add-data'}->archive->attributes()->title;
            if ($this->doc->{'add-data'}->archive->attributes()->subtitle) {
                $data['hierarchy_top_title'] .= ' : ' . (string)$this->doc->{'add-data'}->archive->attributes()->subtitle; 
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
                $allFields[] = "$s";
            }
        }
        return $allFields;
    }
}


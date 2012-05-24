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
 */

require_once 'BaseRecord.php';

/**
 * EadRecord Class
 *
 * This is a class for processing EAD records.
 *
 */
class EadRecord extends BaseRecord
{
    protected $_doc = null;

    /**
     * Constructor
     *
     * @param string $data Record metadata
     * @access public
     */
    public function __construct($data, $oaiID)
    {
        $this->_doc = simplexml_load_string($data);
    }

    /**
     * Return record ID (local)
     *
     * @return string
     * @access public
     */
    public function getID()
    {
        return isset($this->_doc->did->unitid->attributes()->{'identifier'}) 
            ? (string)$this->_doc->did->unitid->attributes()->{'identifier'}
            : (string)$this->_doc->did->unitid;
    }

    /**
     * Serialize the record for storing in the database
     *
     * @return string
     * @access public
     */
    public function serialize()
    {
        return $this->_doc->asXML();
    }

    /**
     * Serialize the record into XML for export
     *
     * @return string
     * @access public
     */
    public function toXML()
    {
        return $this->_doc->asXML();
    }

    /**
     * Set the ID prefix into all the ID fields (ID, host ID etc.)
     *
     * @param  string $prefix (e.g. "source.")
     * @return void
     * @access public
     */
    public function setIDPrefix($prefix)
    {
        if ($this->_doc->did->unitid) {
            $this->_doc->did->unitid->attributes()->{'identifier'} = $prefix . $this->_doc->did->unitid->attributes()->{'identifier'};
        }
        if ($this->_doc->{'add-data'}) {
            if ($this->_doc->{'add-data'}->{'archive'}) {
                $this->_doc->{'add-data'}->{'archive'}->attributes()->{'id'} = $prefix . $this->_doc->{'add-data'}->{'archive'}->attributes()->{'id'};
            } 
            if ($this->_doc->{'add-data'}->{'parent'}) {
                $this->_doc->{'add-data'}->{'parent'}->attributes()->{'id'} = $prefix . $this->_doc->{'add-data'}->{'parent'}->attributes()->{'id'};
            } 
        }
    }

    /**
     * Return fields to be indexed in Solr
     *
     * @return array
     * @access public
     */
    public function toSolrArray()
    {
        $data = array();
        
        $doc = $this->_doc;
        $data['ctrlnum'] = (string)$this->_doc->attributes()->{'id'};
        $data['fullrecord'] = str_replace("\t", ' ', $doc->asXML());
        	
        // allfields
        $data['allfields'] = $this->_getAllFields($doc);
        	
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
        if ($this->_doc->{'add-data'}->{'archive'}) {
            $data['hierarchy_top_id'] = (string)$this->_doc->{'add-data'}->archive->attributes()->{'id'};
            $data['hierarchy_top_title'] = (string)$this->_doc->{'add-data'}->archive->attributes()->title;
            if ($this->_doc->{'add-data'}->archive->attributes()->subtitle) {
                $data['hierarchy_top_title'] .= ' : ' . (string)$this->_doc->{'add-data'}->archive->attributes()->subtitle; 
            }
        }
        if ($this->_doc->{'add-data'}->{'parent'}) {
            $data['hierarchy_parent_id'] = (string)$this->_doc->{'add-data'}->{'parent'}->attributes()->{'id'};
            $data['hierarchy_parent_title'] = (string)$this->_doc->{'add-data'}->{'parent'}->attributes()->title;
        } else {
            $data['is_hierarchy_id'] = $data['hierarchy_top_id'] = $this->getID();
            $data['is_hierarchy_title'] = $data['hierarchy_top_title'] = (string)$doc->did->unittitle;
        }
        
        return $data;
    }

    protected function _getAllFields($xml)
    {
        $allFields = '';
        foreach ($xml->children() as $tag => $field) {
            $allFields .= ' ';
            $s = trim((string)$field);
            if ($s) {
                $allFields .= $s;
            }
            $s = $this->_getAllFields($field);
            if ($s) {
                $allFields .= " $s";
            }
        }
        return trim($allFields);
    }
}


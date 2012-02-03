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
 * @category VuFind
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
        return (string) $this->_doc->attributes()->{'id'};
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
        $this->_doc->attributes()->{'id'} = $prefix . $this->_doc->attributes()->{'id'};
        foreach ($this->_doc->{'add-data'}->{'children'} as $child) {
            $child->attributes()->{'id'} = $prefix . $child->attributes()->{'id'}; 
        }
        foreach ($this->_doc->{'add-data'}->{'parent'} as $parent) {
            $parent->attributes()->{'id'} = $prefix . $parent->attributes()->{'id'}; 
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
        $data['format'] = (string)$doc->c->attributes()->level;
        $data['author'] = (string)$doc->c->did->origination->corpname;

        $data['title_sort'] = $data['title'] = $data['title_short'] = (string)$doc->c->did->unittitle;
        $data['title_full'] = $data['title'];
        
        $unitdate = (string)$doc->c->did->unitdate;
        if ($unitdate && $unitdate != '-') {
            $dates = explode('-', $unitdate);
            if (isset($dates[1]) && $dates[1]) {
                if ($dates[0]) {
                    $unitdate = $dates[0] . '-01-01T00:00:00Z,' . $dates[1] . '-12-31T23:59:59Z';
                } else {
                    $unitdate = '0000-01-01T00:00:00Z,' . $dates[1] . '-12-31T23:59:59Z';
                }
            } else {
                if (strpos($unitdate, '-') > 0) {
                    $unitdate = $dates[0] . '-01-01T00:00:00Z,9999-12-31T23:59:59Z';
                } else {
                    $unitdate = $dates[0] . '-01-01T00:00:00Z';
                }
            }
            $data['unit_daterange'] = $unitdate; 
        }
        
        switch ($doc->c->level) {
            case 'collection':
                break;
            case 'series':
                break;
            case 'item': 
                $data['series'] = (string)$doc->{'add-data'}->parent->unittitle;
                break;
        }
        return $data;
    }

    // TODO: this is temporary, will be replaced by proper ID's for the hierarchy
    public function getHostRecordID()
    {
        if ($this->_doc->{'add-data'}->{'parent'}) {
            return (string)$this->_doc->{'add-data'}->{'parent'}->attributes()->{'id'}; 
        }
        return '';
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


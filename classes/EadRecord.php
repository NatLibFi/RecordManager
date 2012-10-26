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
    protected $source = '';

    /**
     * Constructor
     *
     * @param string $data   Metadata
     * @param string $oaiID  Record ID received from OAI-PMH (or empty string for file import)
     * @param string $source Source ID
     */
    public function __construct($data, $oaiID, $source)
    {
        $this->source = $source;
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
          
        if ($doc->did->origination) {
            $data['author'] = (string)$doc->did->origination->corpname;
        }

        $data['format'] = (string)$doc->attributes()->level;
        switch ($data['format']) {
        case 'fonds':
            if (isset($doc->did)) {
                $data['institution'] = (string)$doc->did->repository;
            }
            break;
        case 'collection':
            $data['institution'] = (string)$doc->{'add-data'}->archive->attributes()->repository;
            break;
        case 'series':
            $data['title_sub'] = (string)$doc->did->unitid;
            $data['institution'] = (string)$doc->{'add-data'}->archive->attributes()->repository;
            break;
        case 'item': 
            $data['title_sub'] = (string)$doc->did->unitid;
            $data['series'] = (string)$doc->{'add-data'}->parent->attributes()->unittitle;
            $data['institution'] = (string)$doc->{'add-data'}->archive->attributes()->repository;
            break;
        default:
            echo "No proper handling for level '" . $data['format'] . "', document:\n" . $doc->asXML() . "\n";
            break;
        }
        if ($doc->did->unitdate) {
            if (isset($data['title_sub']) && $data['title_sub']) {
                $data['title_sub'] .= '; ' . (string)$doc->did->unitdate;
            } else {
                $data['title_sub'] = (string)$doc->did->unitdate;
            }
        }
        $data['title'] = $data['title_short'] = (string)$doc->did->unittitle;
        $data['title_full'] = $data['title_sort'] = $data['title'] . (isset($data['title_sub']) && $data['title_sub'] ? ' (' . $data['title_sub'] . ')' : '');
        
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
                $allFields[] = "$s";
            }
        }
        return $allFields;
    }
}


<?php
/**
 * EadSplitter Class
 *
 * PHP version 5
 *
 * Copyright (C) Ere Maijala 2012
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

/**
* EadRecord Class
*
* This is a class for splitting EAD records.
*
*/
class EadSplitter
{
    protected $_doc = null;
    protected $_recordNodes;
    protected $_recordCount;
    protected $_currentPos;
    
    protected $_agency = '';
    protected $_archiveId = '';
    protected $_archiveTitle = '';
    protected $_archiveSubTitle = '';
    
    /**
    * Constructor
    *
    * @param string $data EAD XML
    * @access public
    */
    public function __construct($data)
    {
        $this->_doc = simplexml_load_string($data);
        $this->_recordNodes = $this->_doc->xpath('archdesc | archdesc/dsc//*[@level]');
        $this->_recordCount = count($this->_recordNodes);
        $this->_currentPos = 0;
        
        $this->_agency = (string)$this->_doc->eadheader->eadid->attributes()->mainagencycode;
        $this->_archiveId = (string)$this->_doc->eadheader->eadid->attributes()->identifier;
        $this->_archiveTitle = (string)$this->_doc->eadheader->filedesc->titlestmt->titleproper;
        $this->_archiveSubTitle = (string)$this->_doc->eadheader->filedesc->titlestmt->subtitle;
    }
    
    public function getEOF()
    {
        return $this->_currentPos >= $this->_recordCount;
    }
    
    public function getNextRecord()
    {
        if ($this->_currentPos < $this->_recordCount)
        {
            $original = $this->_recordNodes[$this->_currentPos++];
            $record = simplexml_load_string('<' . $original->getName() . '/>');
            foreach ($original->attributes() as $key => $value) {
                $record->addAttribute($key, $value);
            }
            foreach ($original->children() as $child) {
                $this->appendXML($record, $child);
            }
            if ($record->getName() != 'archdesc') {
                $addData = $record->addChild('add-data');
                
                $absolute = $addData->addChild('archive');
                $absolute->addAttribute('id', $this->_archiveId);
                $absolute->addAttribute('title', $this->_archiveTitle);
                if ($this->_archiveSubTitle) {
                    $absolute->addAttribute('subtitle', $this->_archiveSubTitle);
                }
                
                $parentDid = $original->xpath('parent::*/did | parent::*/parent::*/did');
                $parentDid = $parentDid[0];
                $parent = $addData->addChild('parent');
                $parent->addAttribute('id', $parentDid->unitid->attributes()->identifier);
                $parent->addAttribute('title', $parentDid->unittitle);
            }
            return $record->asXML();
        }
        return false;
    }
    
    /**
     * Recursively append a node to simplexml, filtering out c, c01, c02 etc.
     * 
     * @param SimpleXMLElement  $simplexml    Node to append to
     * @param SimpleXMLElement  $append       Node to be appended
     */
    protected function appendXML(&$simplexml, $append)
    {
        if ($append) {
            $name = $append->getName();
            if ($name == 'c' || (substr($name, 0, 1) == 'c' && is_numeric(substr($name, 1)))) {
                return;
            }
            // addChild doesn't encode & ...  
            $data = (string)$append;
            $data = str_replace('&', '&amp;', $data);
            $xml = $simplexml->addChild($name, $data);
            foreach($append->attributes() as $key => $value) {
                $xml->addAttribute($key, $value);
            }
            foreach($append->children() as $child) {
                $this->appendXML($xml, $child);
            }
        }
    }    
}

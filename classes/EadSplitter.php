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
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */

/**
 * EadRecord Class
 *
 * This is a class for splitting EAD records.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
*/
class EadSplitter
{
    protected $doc = null;
    protected $recordNodes;
    protected $recordCount;
    protected $currentPos;
    
    protected $agency = '';
    protected $archiveId = '';
    protected $archiveTitle = '';
    protected $archiveSubTitle = '';
    
    /**
    * Constructor
    *
    * @param string $data EAD XML
    * 
    * @access public
    */
    public function __construct($data)
    {
        $this->doc = simplexml_load_string($data);
        $this->recordNodes = $this->doc->xpath('archdesc | archdesc/dsc//*[@level]');
        $this->recordCount = count($this->recordNodes);
        $this->currentPos = 0;
        
        $this->agency = (string)$this->doc->eadheader->eadid->attributes()->mainagencycode;
        $this->archiveId = (string)$this->doc->eadheader->eadid->attributes()->identifier;
        $this->archiveTitle = (string)$this->doc->eadheader->filedesc->titlestmt->titleproper;
        $this->archiveSubTitle = (string)$this->doc->eadheader->filedesc->titlestmt->subtitle;
    }
    
    /**
     * Check whether EOF has been encountered
     * 
     * @return boolean
     */
    public function getEOF()
    {
        return $this->currentPos >= $this->recordCount;
    }
    
    /**
     * Get next record
     * 
     * @return string XML
     */
    public function getNextRecord()
    {
        if ($this->currentPos < $this->recordCount) {
            $original = $this->recordNodes[$this->currentPos++];
            $record = simplexml_load_string('<' . $original->getName() . '/>');
            foreach ($original->attributes() as $key => $value) {
                $record->addAttribute($key, $value);
            }
            foreach ($original->children() as $child) {
                $this->appendXML($record, $child);
            }
            if ($record->getName() != 'archdesc') {
                $record->did->unitid->attributes()->identifier = $this->archiveId . '_' . 
                    $record->did->unitid->attributes()->identifier; 
                
                $addData = $record->addChild('add-data');
                
                $absolute = $addData->addChild('archive');
                $absolute->addAttribute('id', $this->archiveId);
                $absolute->addAttribute('title', $this->archiveTitle);
                $absolute->addAttribute('sequence', str_pad($this->currentPos, 7, '0', STR_PAD_LEFT));
                if ($this->archiveSubTitle) {
                    $absolute->addAttribute('subtitle', $this->archiveSubTitle);
                }
                
                $parentDid = $original->xpath('parent::*/did | parent::*/parent::*/did');
                $parentDid = $parentDid[0];
                $parentID = (string)$parentDid->unitid->attributes()->identifier;
                if ($parentID != $this->archiveId) {
                    $parentID = $this->archiveId . '_' . $parentID;
                }
                $parent = $addData->addChild('parent');
                $parent->addAttribute('id', $parentID);
                $parent->addAttribute('title', $parentDid->unittitle);
            }
            return $record->asXML();
        }
        return false;
    }
    
    /**
     * Recursively append a node to simplexml, filtering out c, c01, c02 etc.
     * 
     * @param SimpleXMLElement &$simplexml Node to append to
     * @param SimpleXMLElement $append     Node to be appended
     * 
     * @return void
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
            foreach ($append->attributes() as $key => $value) {
                $xml->addAttribute($key, $value);
            }
            foreach ($append->children() as $child) {
                $this->appendXML($xml, $child);
            }
        }
    }    
}

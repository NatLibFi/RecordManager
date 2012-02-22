<?php
/**
 * XML File Splitter
 *
 * PHP version 5
 *
 * Copyright (C) Ere Maijala 2011-20112
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

/**
 * FileSplitter
 *
 * This class splits XML to multiple records using an xpath expression
 *
 */
class FileSplitter
{
    protected $_xmlDoc;
    protected $_recordNodes;
    protected $_recordCount;
    protected $_currentPos;

    function __construct($data, $recordXPath)
    {
        $this->_xmlDoc = new DOMDocument();
        $this->_xmlDoc->loadXML($data);
        $xpath = new DOMXpath($this->_xmlDoc);
        $this->_recordNodes = $xpath->query($recordXPath);
        $this->_recordCount = $this->_recordNodes->length;
        $this->_currentPos = 0;
    }

    public function getEOF()
    {
        return $this->_currentPos >= $this->_recordCount;
    }

    public function getNextRecord()
    {
        if ($this->_currentPos < $this->_recordCount)
        {
            if ($this->_recordNodes->item($this->_currentPos)->nodeType == XML_DOCUMENT_NODE) {
                return $this->_recordNodes->item($this->_currentPos++)->saveXML();
            }
            $new = new DomDocument;
            $new->appendChild($new->importNode($this->_recordNodes->item($this->_currentPos++), true));
            return $new->saveXML();
        }
        return false;
    }
}


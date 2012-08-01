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
 * @link     https://github.com/KDK-Alli/RecordManager
 */

/**
 * FileSplitter
 *
 * This class splits XML to multiple records using an xpath expression
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class FileSplitter
{
    protected $xmlDoc;
    protected $recordNodes;
    protected $recordCount;
    protected $currentPos;

    /**
     * Construct the splitter
     * 
     * @param mixed  $data        XML string or DOM document
     * @param string $recordXPath XPath used to find the records
     */
    function __construct($data, $recordXPath)
    {
        if (is_string($data)) {
            $this->xmlDoc = new DOMDocument();
            $this->xmlDoc->loadXML($data);
        } else {
            $this->xmlDoc = $data;
        }
        $xpath = new DOMXpath($this->xmlDoc);
        $this->recordNodes = $xpath->query($recordXPath);
        $this->recordCount = $this->recordNodes->length;
        $this->currentPos = 0;
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
     * @return string|boolean
     */
    public function getNextRecord()
    {
        if ($this->currentPos < $this->recordCount) {
            if ($this->recordNodes->item($this->currentPos)->nodeType == XML_DOCUMENT_NODE) {
                return $this->recordNodes->item($this->currentPos++)->saveXML();
            }
            $new = new DomDocument;
            $new->appendChild($new->importNode($this->recordNodes->item($this->currentPos++), true));
            return $new->saveXML();
        }
        return false;
    }
}

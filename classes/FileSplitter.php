<?php
/**
 * XML File Splitter
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
    protected $xpath;
    protected $oaiIDXpath;

    /**
     * Construct the splitter
     * 
     * @param mixed  $data        XML string or DOM document
     * @param string $recordXPath XPath used to find the records
     * @param string $oaiIDXPath  XPath used to find the records' oaiID's (relative to record)
     */
    function __construct($data, $recordXPath, $oaiIDXPath)
    {
        if (is_string($data)) {
            $this->xmlDoc = new DOMDocument();
            $this->xmlDoc->loadXML($data, LIBXML_PARSEHUGE);
        } else {
            $this->xmlDoc = $data;
        }
        $this->xpath = new DOMXpath($this->xmlDoc);
        $this->recordNodes = $this->xpath->query($recordXPath);
        $this->recordCount = $this->recordNodes->length;
        $this->currentPos = 0;
        $this->oaiIDXpath = $oaiIDXPath;
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
     * @param string &$oaiID OAI Identifier (if XPath specified in constructor)
     * 
     * @return string|boolean
     */
    public function getNextRecord(&$oaiID)
    {
        if ($this->currentPos < $this->recordCount) {
            $node = $this->recordNodes->item($this->currentPos++);
            if ($this->oaiIDXpath) {
                $xNodes = $this->xpath->query($this->oaiIDXpath, $node);
                if ($xNodes->length == 0 || !$xNodes->item(0)->nodeValue) {
                    die("No OAI ID found with XPath '{$this->oaiIDXpath}' starting at element: '{$node->nodeName}'\n");
                }
                $oaiID = $xNodes->item(0)->nodeValue;
            }
            if ($node->nodeType !== XML_DOCUMENT_NODE) {
                $childNode = $node;
                $node = new DomDocument;
                $node->appendChild($node->importNode($childNode, true));
            }
            return $node->saveXML();
        }
        return false;
    }
}

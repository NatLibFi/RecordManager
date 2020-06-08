<?php
/**
 * XML File Splitter
 *
 * PHP version 7
 *
 * Copyright (C) The National Library of Finland 2011-2019.
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
namespace RecordManager\Base\Splitter;

/**
 * File Splitter
 *
 * This class splits XML to multiple records using an xpath expression
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class File
{
    protected $xmlDoc;
    protected $recordNodes;
    protected $recordCount;
    protected $currentPos;
    protected $xpath;
    protected $recordXPath = '//record';
    protected $oaiIDXpath = '';

    /**
     * Constructor
     *
     * @param array $params Splitter configuration params
     */
    public function __construct($params)
    {
        if (!empty($params['recordXPath'])) {
            $this->recordXPath = $params['recordXPath'];
        }
        if (!empty($params['oaiIDXPath'])) {
            $this->oaiIDXPath = $params['oaiIDXPath'];
        }
    }

    /**
     * Set metadata
     *
     * @param mixed $data XML string or DOM document
     *
     * @return void
     */
    public function setData($data)
    {
        if (is_string($data)) {
            $this->xmlDoc = new \DOMDocument();
            \RecordManager\Base\Utils\MetadataUtils::loadXML($data, $this->xmlDoc);
        } else {
            $this->xmlDoc = $data;
        }
        $this->xpath = new \DOMXpath($this->xmlDoc);
        $this->recordNodes = $this->xpath->query($this->recordXPath);
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
     * @param string $oaiID OAI Identifier (if XPath specified in constructor)
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
                    die(
                        "No OAI ID found with XPath '{$this->oaiIDXpath}' " .
                        "starting at element: '{$node->nodeName}'\n"
                    );
                }
                $oaiID = $xNodes->item(0)->nodeValue;
            }
            if ($node->nodeType !== XML_DOCUMENT_NODE) {
                $childNode = $node;
                $node = new \DomDocument;
                $node->appendChild($node->importNode($childNode, true));
            }
            return $node->saveXML();
        }
        return false;
    }
}

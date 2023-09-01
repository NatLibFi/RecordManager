<?php

/**
 * Generic XML File Splitter
 *
 * PHP version 8
 *
 * Copyright (C) The National Library of Finland 2011-2021.
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
 * @link     https://github.com/NatLibFi/RecordManager
 */

namespace RecordManager\Base\Splitter;

use function is_string;

/**
 * Generic File Splitter
 *
 * This class splits XML to multiple records using an XPath expression
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class File extends AbstractBase
{
    /**
     * XML document
     *
     * @var \DOMDocument
     */
    protected $xmlDoc;

    /**
     * Record nodes
     *
     * @var \DOMNodeList|false
     */
    protected $recordNodes;

    /**
     * XPath query handler
     *
     * @var \DOMXPath
     */
    protected $xpath;

    /**
     * Record XPath
     *
     * @var string
     */
    protected $recordXPath = '//record';

    /**
     * OAI identifier XPath
     *
     * @var string
     */
    protected $oaiIDXpath = '';

    /**
     * Initializer
     *
     * @param array $params Splitter configuration
     *
     * @return void
     */
    public function init(array $params): void
    {
        if (!empty($params['recordXPath'])) {
            $this->recordXPath = $params['recordXPath'];
        }
        if (!empty($params['oaiIDXPath'])) {
            $this->oaiIDXpath = $params['oaiIDXPath'];
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
            $this->metadataUtils->loadXML($data, $this->xmlDoc);
        } else {
            $this->xmlDoc = $data;
        }
        $this->xpath = new \DOMXPath($this->xmlDoc);
        $this->recordNodes = $this->xpath->query($this->recordXPath);
        $this->recordCount = $this->recordNodes->length;
        $this->currentPos = 0;
    }

    /**
     * Get next record
     *
     * Returns false on EOF or an associative array with the following keys:
     * - string metadata       Actual metadata
     * - array  additionalData Any additional data
     *
     * @return array|bool
     */
    public function getNextRecord()
    {
        if ($this->getEOF()) {
            return false;
        }
        $result = [];
        $node = $this->recordNodes->item($this->currentPos++);
        if ($this->oaiIDXpath) {
            $xNodes = $this->xpath->query($this->oaiIDXpath, $node);
            if ($xNodes->length == 0 || !$xNodes->item(0)->nodeValue) {
                throw new \Exception(
                    "No OAI ID found with XPath '{$this->oaiIDXpath}' " .
                    "starting at element: '{$node->nodeName}'\n"
                );
            }
            $result['additionalData']['oaiId'] = $xNodes->item(0)->nodeValue;
        }
        $result['metadata'] = ($node instanceof \DOMDocument)
            ? $node->saveXML()
            : $node->ownerDocument->saveXML($node);

        return $result;
    }
}

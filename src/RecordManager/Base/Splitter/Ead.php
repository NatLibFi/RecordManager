<?php

/**
 * Ead Splitter
 *
 * PHP version 8
 *
 * Copyright (C) The National Library of Finland 2012-2022.
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

use function count;
use function in_array;
use function is_array;

/**
 * Ead Splitter
 *
 * This is a class for splitting EAD records.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class Ead extends AbstractBase
{
    /**
     * Whether to prepend unit id to parent title
     *
     * @var bool
     */
    protected $prependParentTitleWithUnitId;

    /**
     * Keyed list of fields within record did element not to be inherited to child
     * nodes
     *
     * @var array
     */
    protected $nonInheritedFields = [];

    /**
     * XML document
     *
     * @var \SimpleXMLElement
     */
    protected $doc;

    /**
     * Record nodes
     *
     * @var array
     */
    protected $recordNodes;

    /**
     * Record count
     *
     * @var int
     */
    protected $recordCount;

    /**
     * Current position
     *
     * @var int
     */
    protected $currentPos;

    /**
     * Main agency code
     *
     * @var string
     */
    protected $agency;

    /**
     * Archive identifier
     *
     * @var string
     */
    protected $archiveId;

    /**
     * Archive title
     *
     * @var string
     */
    protected $archiveTitle;

    /**
     * Archive identifier
     *
     * @var string
     */
    protected $archiveSubTitle;

    /**
     * Initializer
     *
     * @param array $params Splitter configuration
     *
     * @return void
     */
    public function init(array $params): void
    {
        $this->prependParentTitleWithUnitId
            = !empty($params['prependParentTitleWithUnitId']);

        if (!empty($params['nonInheritedFields'])) {
            if (is_array($params['nonInheritedFields'])) {
                $this->nonInheritedFields += $params['nonInheritedFields'];
            } else {
                foreach (explode(',', $params['nonInheritedFields']) as $field) {
                    $this->nonInheritedFields[$field] = true;
                }
            }
        }
    }

    /**
     * Set metadata
     *
     * @param string $data EAD XML
     *
     * @return void
     */
    public function setData($data)
    {
        $this->doc = $this->metadataUtils->loadSimpleXML($data);
        $this->recordNodes = $this->doc->xpath('archdesc | archdesc/dsc//*[@level]');
        $this->recordCount = count($this->recordNodes);
        $this->currentPos = 0;

        $this->agency
            = (string)$this->doc->eadheader->eadid->attributes()->mainagencycode;
        $this->archiveId = urlencode(
            (string)($this->doc->eadheader->eadid->attributes()->identifier
                ? $this->doc->eadheader->eadid->attributes()->identifier
                : $this->doc->eadheader->eadid)
        );
        $this->archiveTitle
            = (string)$this->doc->eadheader->filedesc->titlestmt->titleproper;
        $this->archiveSubTitle
            = (string)$this->doc->eadheader->filedesc->titlestmt->subtitle;
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
        $original = $this->recordNodes[$this->currentPos++];
        $record = simplexml_load_string('<' . $original->getName() . '/>');
        foreach ($original->attributes() as $key => $value) {
            $record->addAttribute($key, $value);
        }
        foreach ($original->children() as $child) {
            $this->appendXMLFiltered($record, $child);
        }

        $addData = $record->addChild('add-data');

        if ($record->did->unitid) {
            $unitId = urlencode(
                $record->did->unitid->attributes()->identifier
                ? (string)$record->did->unitid->attributes()->identifier
                : (string)$record->did->unitid
            );
            if ($unitId != $this->archiveId) {
                $unitId = $this->archiveId . '_' . $unitId;
            }
        } else {
            // Create ID for the unit
            $unitId = $this->archiveId . '_' . $this->currentPos;
        }
        if ($record->getName() != 'archdesc') {
            $addData->addAttribute('identifier', $unitId);
        }
        // Also store it in original record for the children
        $original->addChild('add-data')->addAttribute('identifier', $unitId);

        $absolute = $addData->addChild('archive');
        $absolute->addAttribute('id', $this->archiveId);
        $absolute->addAttribute('title', $this->archiveTitle);
        $absolute->addAttribute(
            'sequence',
            str_pad((string)$this->currentPos, 7, '0', STR_PAD_LEFT)
        );
        if ($this->archiveSubTitle) {
            $absolute->addAttribute('subtitle', $this->archiveSubTitle);
        }

        $ancestorDid = $original->xpath('ancestor::*/did');
        if ($ancestorDid) {
            // Append any ancestor did's
            foreach (array_reverse($ancestorDid) as $did) {
                $this->appendXML($record, $did, $this->nonInheritedFields);
            }
        }

        if ($record->getName() !== 'archdesc') {
            foreach ($this->doc->archdesc->bibliography ?? [] as $elem) {
                $this->appendXML($record, $elem, $this->nonInheritedFields);
            }

            foreach ($this->doc->archdesc->accessrestrict ?? [] as $elem) {
                $this->appendXML($record, $elem, $this->nonInheritedFields);
            }
        }

        $parentDid = $original->xpath('parent::*/did | parent::*/parent::*/did');
        if ($parentDid) {
            $parentDid = $parentDid[0];
            // If parent has add-data, take the generated ID from it
            $parentAddData = $original->xpath(
                'parent::*/add-data | parent::*/parent::*/add-data'
            );
            if ($parentAddData) {
                $parentID
                    = (string)$parentAddData[0]->attributes()->identifier;
            } else {
                // Generate parent ID
                $parentID = urlencode(
                    $parentDid->unitid->attributes()->identifier
                    ? (string)$parentDid->unitid->attributes()->identifier
                    : (string)$parentDid->unitid
                );
                if ($parentID != $this->archiveId) {
                    $parentID = $this->archiveId . '_' . $parentID;
                }
            }
            $parentTitle = (string)$parentDid->unittitle;

            if ($this->prependParentTitleWithUnitId) {
                if (
                    (string)$parentDid->unitid
                    && in_array(
                        (string)$record->attributes()->level,
                        ['series', 'subseries', 'item', 'file']
                    )
                ) {
                    $parentTitle = (string)$parentDid->unitid . ' '
                        . $parentTitle;
                }
            }
            $parent = $addData->addChild('parent');
            $parent->addAttribute('id', $parentID);
            $parent->addAttribute('title', $parentTitle);
        }

        return ['metadata' => $record->asXML()];
    }

    /**
     * Recursively append a node to simplexml, merge elements with same name
     *
     * @param \SimpleXMLElement $simplexml Node to append to
     * @param \SimpleXMLElement $append    Node to be appended
     * @param array             $ignore    Keyed array of node names to be ignored
     *                                     (unless value is false)
     *
     * @return void
     */
    protected function appendXML(&$simplexml, $append, $ignore = [])
    {
        if ($append !== null) {
            $name = $append->getName();
            if (isset($ignore[$name])) {
                return;
            }
            // addChild doesn't encode & ...
            $data = (string)$append;
            $data = trim(str_replace('&', '&amp;', $data));
            if ($simplexml->{$name}) {
                $xml = $simplexml->{$name};
            } else {
                $xml = $simplexml->addChild($name, $data);
                foreach ($append->attributes() as $key => $value) {
                    if (!$xml->attributes()->{$key}) {
                        $xml->addAttribute($key, (string)$value);
                    }
                }
            }
            foreach ($append->children() as $child) {
                if (!($ignore[$child->getName()] ?? false)) {
                    $this->appendXML($xml, $child);
                }
            }
        }
    }

    /**
     * Recursively append a node to simplexml, filtering out c, c01, c02 etc.
     *
     * @param \SimpleXMLElement $simplexml Node to append to
     * @param \SimpleXMLElement $append    Node to be appended
     *
     * @return void
     */
    protected function appendXMLFiltered(&$simplexml, $append)
    {
        if ($append !== null) {
            $name = $append->getName();
            if (
                $name == 'c' || (substr($name, 0, 1) == 'c'
                && is_numeric(substr($name, 1)))
            ) {
                return;
            }
            // addChild doesn't encode & ...
            $data = (string)$append;
            $data = str_replace('&', '&amp;', $data);
            $xml = $simplexml->addChild($name, $data);
            foreach ($append->attributes() as $key => $value) {
                $xml->addAttribute($key, (string)$value);
            }
            foreach ($append->children() as $child) {
                $this->appendXMLFiltered($xml, $child);
            }
        }
    }
}

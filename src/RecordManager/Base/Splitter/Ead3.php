<?php
/**
 * EAD 3 splitter
 *
 * PHP version 7
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
 * @author   Jukka Lehmus <jlehmus@mappi.helsinki.fi>
 * @author   Samuli Sillanpää <samuli.sillanpaa@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
namespace RecordManager\Base\Splitter;

/**
 * EAD 3 splitter
 *
 * This is a class for splitting EAD 3 records.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @author   Jukka Lehmus <jlehmus@mappi.helsinki.fi>
 * @author   Samuli Sillanpää <samuli.sillanpaa@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class Ead3 extends Ead
{
    /**
     * The label attribute of the identifying unitid element
     *
     * @var string
     */
    protected $unitIdLabel = null;

    /**
     * Initializer
     *
     * @param array $params Splitter configuration
     *
     * @return void
     */
    public function init(array $params): void
    {
        parent::init($params);

        $this->unitIdLabel = $params['unitIdLabel'] ?? null;
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
        $this->doc = $this->metadataUtils->loadXML($data);

        $this->recordNodes = $this->doc->xpath('archdesc | archdesc/dsc//*[@level]');
        $this->recordCount = count($this->recordNodes);
        $this->currentPos = 0;

        $this->agency
            = (string)($this->doc->control->maintenanceagency->agencycode ?? '');

        foreach ($this->doc->archdesc->did->unitid ?? [] as $i) {
            $attr = $i->attributes();
            if (!isset($attr->identifier)) {
                continue;
            }
            $id = urlencode((string)$attr->identifier);
            if (!$this->archiveId) {
                $this->archiveId = $id;
            }
            if (!$this->unitIdLabel
                || (string)$attr->label === $this->unitIdLabel
            ) {
                $this->archiveId = $id;
                break;
            }
        }
        $this->archiveTitle = $this->getArchiveTitle();
        $this->archiveTitle = $this->archiveTitle ?? $this->archiveId;

        $this->archiveSubTitle = '';
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
        $unitId = '';

        if (!empty($record->did->unitid)) {
            $firstId = '';
            foreach ($record->did->unitid as $i) {
                $attr = $i->attributes();
                if (!isset($attr->identifier)) {
                    continue;
                }
                $id = urlencode((string)$attr->identifier);
                if (!$firstId) {
                    $firstId = $id;
                }
                if (!$this->unitIdLabel
                    || (string)$attr->label === $this->unitIdLabel
                ) {
                    $unitId = $id;
                    if ($unitId != $this->archiveId) {
                        break;
                    }
                }
            }
            if (!$unitId) {
                $unitId = $firstId;
            }

            if ($unitId == '') {
                // This shouldn't happen:
                $unitId = urlencode($this->archiveId . '_' . $this->currentPos);
            } elseif ($unitId != $this->archiveId) {
                $unitId = $this->archiveId . '_' . $unitId;
            }
        } else {
            $unitId = $this->archiveId . '_' . $this->currentPos;
        }

        if ($record->getName() != 'archdesc') {
            $addData->addAttribute('identifier', $unitId);
        } else {
            $unitId = $this->archiveId;
            $addData->addAttribute('identifier', $unitId);
        }
        // Also store it in original record for the children
        $originalAddData = $original->addChild('add-data');
        $originalAddData->addAttribute('identifier', $unitId);

        $absolute = $addData->addChild('archive');
        $absolute->addAttribute('id', $this->archiveId);
        $absolute->addAttribute('title', $this->archiveTitle);
        $absolute->addAttribute(
            'sequence',
            str_pad($this->currentPos, 7, '0', STR_PAD_LEFT)
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

        foreach ($this->doc->archdesc->bibliography ?? [] as $elem) {
            $this->appendXML($record, $elem);
        }

        foreach ($this->doc->archdesc->accessrestrict ?? [] as $elem) {
            $this->appendXML($record, $elem);
        }

        $parentDid = $original->xpath('parent::*/did');
        if ($parentDid) {
            $parentDid = $parentDid[0];
            // If parent has add-data, take the generated ID from it
            $parentAddData = $original->xpath('parent::*/add-data');

            if ($parentAddData) {
                $parentID = (string)$parentAddData[0]->attributes()->identifier;
            } else {
                //generate
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

            if (!$parentTitle) {
                $parentTitle
                    = (string)$parentDid->unittitle->attributes()->label;

                if (!$parentTitle) {
                    $parentTitle = $parentID;
                }
            }

            if ($this->prependParentTitleWithUnitId) {
                if ($pid = $this->getParentUnitId($parentDid)) {
                    $parentTitle = $pid . ' ' . $parentTitle;
                }
            }

            $parentNode = $original->xpath('parent::*[@level]');

            $parent = $addData->addChild('parent');
            $parent->addAttribute('id', $parentID);
            $parent->addAttribute('title', $parentTitle);
            if ($parentNode) {
                // Add a new parent-node to parent record addData
                $level = (string)$parentNode[0]->attributes()->level;
                $parent->addAttribute('level', $level);
                if (in_array($level, ['series', 'subseries'])) {
                    $parent = $originalAddData->addChild('parent');
                    $parent->addAttribute('id', $parentID);
                    $parent->addAttribute('title', $parentTitle);
                    $parent->addAttribute('level', $level);
                }
            }

            if ($parentAddData) {
                // Copy all parent-nodes from parent record addData.
                foreach ($parentAddData[0]->parent as $p) {
                    $copy = $addData->addChild('parent');
                    $copy2 = $originalAddData->addChild('parent');
                    foreach ($p->attributes() as $key => $val) {
                        $copy->addAttribute($key, $val);
                        $copy2->addAttribute($key, $val);
                    }
                }
            }
        } else {
            if ($this->currentPos > 1) {
                $parent = $addData->addChild('parent');
                $parent->addAttribute('id', $this->archiveId);
                $parent->addAttribute('title', $this->archiveTitle);
                $parent->addAttribute('level', 'archive');
            }
        }

        return ['metadata' => $record->asXML()];
    }

    /**
     * Get archive title
     *
     * @return string
     */
    protected function getArchiveTitle(): string
    {
        return (string)$this->doc->archdesc->did->unittitle;
    }

    /**
     * Get parent unit id for prepending to parent title
     *
     * @param \SimpleXMLElement $parentDid Parent did
     *
     * @return string
     */
    protected function getParentUnitId(\SimpleXMLElement $parentDid): string
    {
        return (string)($parentDid->unitid ?? '');
    }
}

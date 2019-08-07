<?php
/**
 * EAD 3 Splitter Class
 *
 * PHP version 5
 *
 * Copyright (C) The National Library of Finland 2012-2019.
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
 * @link     https://github.com/KDK-Alli/RecordManager
 */
namespace RecordManager\Finna\Splitter;

/**
 * EAD 3 Splitter Class
 *
 * This is a class for splitting EAD 3 records.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @author   Jukka Lehmus <jlehmus@mappi.helsinki.fi>
 * @author   Samuli Sillanpää <samuli.sillanpaa@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class Ead3 extends \RecordManager\Base\Splitter\Ead
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

    protected $doc;
    protected $recordNodes;
    protected $recordCount;
    protected $currentPos;

    protected $agency = '';
    protected $archiveId = '';
    protected $archiveTitle = '';
    protected $archiveSubTitle = '';
    protected $repository = '';

    /**
     * Constructor
     *
     * @param array $params Splitter configuration params
     */
    public function __construct($params)
    {
        $this->prependParentTitleWithUnitId
            = !empty($params['prependParentTitleWithUnitId']);

        if (!empty($params['nonInheritedFields'])) {
            $fields = explode(',', $params['nonInheritedFields']);
            $this->nonInheritedFields = array_flip($fields);
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
        $this->doc = simplexml_load_string($data, null, LIBXML_PARSEHUGE);
        $this->recordNodes = $this->doc->xpath('archdesc | archdesc/dsc//*[@level]');
        $this->recordCount = count($this->recordNodes);
        $this->currentPos = 0;

        $this->agency
            = (string)$this->doc->control->maintenanceagency->agencycode;
        $this->archiveId = urlencode(
            (string)($this->doc->control->recordid
                ? $this->doc->control->recordid
                : $this->doc->control->recordid)
        );

        $this->archiveTitle
            = (string)$this->doc->control->filedesc->titlestmt->titleproper;

        if (!$this->archiveTitle) {
            $this->archiveTitle = $this->archiveId;
        }

        $this->archiveSubTitle
            = isset($this->doc->control->filedesc->titlestmt->subtitle)
                ? (string)$this->doc->control->filedesc->titlestmt->subtitle
                : '';
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
                $this->appendXMLFiltered($record, $child);
            }

            $addData = $record->addChild('add-data');
            $unitId = '';

            if ($record->did->unitid) {
                foreach ($record->did->unitid as $i) {
                    $attr = $i->attributes();
                    if ($attr->label == 'Tekninen' && isset($attr->identifier)) {
                        $unitId = urlencode((string)$attr->identifier);
                        if ($unitId != $this->archiveId) {
                            $unitId = $this->archiveId . '_' . $unitId;
                            break;
                        }
                    }
                }

                // This shouldn't happen:
                if ($unitId == '') {
                    $unitId = urlencode($this->archiveId . '_' . $this->currentPos);
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
            $original->addChild('add-data')->addAttribute('identifier', $unitId);

            $absolute = $addData->addChild('archive');
            $absolute->addAttribute('id', $this->archiveId);
            $absolute->addAttribute('title', $this->archiveTitle);
            $absolute->addAttribute(
                'sequence', str_pad($this->currentPos, 7, '0', STR_PAD_LEFT)
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

            if ($this->doc->archdesc->bibliography) {
                foreach ($this->doc->archdesc->bibliography as $elem) {
                    $this->appendXML($record, $elem);
                }
            }

            if ($this->doc->archdesc->accessrestrict) {
                foreach ($this->doc->archdesc->accessrestrict as $elem) {
                    $this->appendXML($record, $elem);
                }
            }

            $parentDid = $original->xpath('parent::*/did');

            if ($parentDid) {
                $parentDid = $parentDid[0];
                // If parent has add-data, take the generated ID from it
                $parentAddData = $original->xpath('parent::*/add-data');

                if ($parentAddData) {
                    $parentID
                        = (string)$parentAddData[0]->attributes()->identifier;
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
                    $pid = implode(
                        '+', $parentDid->xpath('unitid[@label="Analoginen"]')
                    );

                    if (preg_match('/\//', $pid)) {
                        $pid = substr(strrchr($pid, '/'), 1);
                    }

                    $parentTitle = $pid . ' ' . $parentTitle;
                }

                $parent = $addData->addChild('parent');
                $parent->addAttribute('id', $parentID);
                $parent->addAttribute('title', $parentTitle);

            } else {

                if ($this->currentPos > 1) {
                    $parent = $addData->addChild('parent');
                    $parent->addAttribute('id', $this->archiveId);
                    $parent->addAttribute('title', $this->archiveTitle);
                }
            }

            return $record->asXML();
        }

        return false;
    }
}

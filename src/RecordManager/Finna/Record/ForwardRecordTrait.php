<?php
/**
 * Forward record trait.
 *
 * PHP version 5
 *
 * Copyright (C) The National Library of Finland 2019.
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
 * @author   Samuli Sillanpää <samuli.sillanpaa@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
namespace RecordManager\Finna\Record;

use RecordManager\Base\Utils\MetadataUtils;

/**
 * Forward record trait.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Samuli Sillanpää <samuli.sillanpaa@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
trait ForwardRecordTrait
{
    /**
     * Merge component parts to this record
     *
     * @param MongoCollection $componentParts Component parts to be merged
     * @param MongoDate|null  $changeDate     Latest timestamp for the component part
     *                                        set
     *
     * @return int Count of records merged
     */
    public function mergeComponentParts($componentParts, &$changeDate)
    {
        $count = 0;
        $parts = [];
        foreach ($componentParts as $componentPart) {
            if (null === $changeDate || $changeDate < $componentPart['date']) {
                $changeDate = $componentPart['date'];
            }
            $data = MetadataUtils::getRecordData($componentPart, true);
            $xml = simplexml_load_string($data);
            foreach ($xml->children() as $child) {
                $parts[] = [
                    'xml' => $child,
                    'order' => empty($child->Title->PartDesignation->Value)
                        ? 0 : (int)$child->Title->PartDesignation->Value
                ];
            }
            ++$count;
        }
        usort(
            $parts,
            function ($a, $b) {
                return $a['order'] - $b['order'];
            }
        );
        foreach ($parts as $part) {
            $this->appendXml($this->doc, $part['xml']);
        }
        return $count;
    }

    /**
     * Recursively append XML
     *
     * @param SimpleXMLElement $simplexml Node to append to
     * @param SimpleXMLElement $append    Node to be appended
     *
     * @return void
     */
    protected function appendXml(&$simplexml, $append)
    {
        if ($append !== null) {
            $name = $append->getName();
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
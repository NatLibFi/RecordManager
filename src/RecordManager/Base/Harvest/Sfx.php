<?php

/**
 * SFX Export File Harvesting Class
 *
 * PHP version 8
 *
 * Copyright (c) The National Library of Finland 2011-2017.
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

namespace RecordManager\Base\Harvest;

/**
 * Sfx Class
 *
 * This class harvests SFX export files via HTTP using settings from datasources.ini.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class Sfx extends HTTPFiles
{
    /**
     * Fetch a file to be harvested
     *
     * @param string $filename File to retrieve
     *
     * @return string xml
     * @throws \Exception
     */
    protected function retrieveFile($filename)
    {
        $data = parent::retrieveFile($filename);
        $data = str_replace(
            '<collection xmlns="http://www.loc.gov/MARC21/slim">',
            '<collection>',
            $data
        );
        return $data;
    }

    /**
     * Extract record ID.
     * This implementation works for MARC records.
     *
     * @param \SimpleXMLElement $record Record
     *
     * @return string ID
     * @throws \Exception
     */
    protected function extractID($record)
    {
        $nodes = $record->xpath("datafield[@tag='090']/subfield[@code='a']");
        if (empty($nodes)) {
            throw new \Exception('No ID found in harvested record');
        }
        return trim((string)$nodes[0]);
    }

    /**
     * Create an OAI style ID
     *
     * @param string $sourceId Source ID
     * @param string $id       Record ID
     *
     * @return string OAI ID
     */
    protected function createOaiId($sourceId, $id)
    {
        return "sfx:$sourceId:$id";
    }

    /**
     * Normalize a record
     *
     * @param \SimpleXMLElement $record Record
     * @param string            $id     Record ID
     *
     * @return void
     */
    protected function normalizeRecord(&$record, $id)
    {
        $record->addChild('controlfield', $id)->addAttribute('tag', '001');
    }

    /**
     * Check if the record is modified.
     *
     * @param \SimpleXMLElement $record Record
     *
     * @return bool
     */
    protected function isModified($record)
    {
        $status = substr($record->leader, 5, 1);
        return $status != '-';
    }
}

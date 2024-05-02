<?php

/**
 * Trait for instantiating and populating a metadata record driver
 *
 * Prerequisites:
 * - the class must have Record\PluginManager as $this->recordPluginManager
 * - the class must have Utils\MetadataUtils as $this->metadataUtils
 *
 * PHP version 8
 *
 * Copyright (C) The National Library of Finland 2021-2024.
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

namespace RecordManager\Base\Record;

use function is_string;

/**
 * Trait for instantiating and populating a metadata record
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
trait CreateRecordTrait
{
    /**
     * Construct a metadata record driver for the specified format
     *
     * @param string       $format    Metadata format
     * @param string       $data      Metadata
     * @param string       $oaiID     Record ID received from OAI-PMH
     * @param string       $source    Record source
     * @param string|array $extraData Extra data (JSON string or array)
     *
     * @return object       The record driver for handling the record
     * @throws \Exception
     *
     * @todo Improve return type
     */
    public function createRecord($format, $data, $oaiID, $source, $extraData = [])
    {
        $record = $this->recordPluginManager->get($format);
        $record->setData($source, $oaiID, $data, is_string($extraData) ? json_decode($extraData, true) : $extraData);
        return $record;
    }

    /**
     * Construct a metadata record driver for the specified format
     *
     * @param array $record Database record
     *
     * @return object The record driver for handling the record
     * @throws \Exception
     *
     * @todo Improve return type
     */
    public function createRecordFromDbRecord(array $record): object
    {
        return $this->createRecord(
            $record['format'],
            $this->metadataUtils->getRecordData($record, true),
            $record['oai_id'],
            $record['source_id'],
            $record['extra_data'] ?? []
        );
    }
}

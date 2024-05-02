<?php

/**
 * XML record trait
 *
 * Provides XML record processing support for classes descending from AbstractRecord.
 *
 * Prerequisites:
 * - MetadataUtils as $this->metadataUtils
 *
 * PHP version 8
 *
 * Copyright (C) The National Library of Finland 2022.
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

use function assert;

/**
 * XML record trait
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
trait XmlRecordTrait
{
    /**
     * XML document
     *
     * @var \SimpleXMLElement
     */
    protected $doc = null;

    /**
     * Set record data
     *
     * @param string $source    Source ID
     * @param string $oaiID     Record ID received from OAI-PMH (or empty string for
     *                          file import)
     * @param string $data      Record metadata
     * @param array  $extraData Extra metadata
     *
     * @return void
     */
    public function setData($source, $oaiID, $data, $extraData)
    {
        parent::setData($source, $oaiID, $data, $extraData);

        $this->doc = $this->parseXMLRecord($data);
    }

    /**
     * Serialize the record for storing in the database
     *
     * @return string
     */
    public function serialize()
    {
        return $this->metadataUtils->trimXMLWhitespace($this->doc->asXML());
    }

    /**
     * Serialize the record into XML for export
     *
     * @return string
     */
    public function toXML()
    {
        if (null === $this->doc) {
            throw new \Exception('Document not set');
        }
        $xml = $this->doc->asXML();
        if (false === $xml) {
            throw new \Exception(
                "Could not serialize record '{$this->source}."
                . $this->getId() . "' to XML"
            );
        }
        return $xml;
    }

    /**
     * Parse an XML record from string to a SimpleXML object
     *
     * @param string $xml XML string
     *
     * @return \SimpleXMLElement
     * @throws \Exception
     */
    protected function parseXMLRecord($xml)
    {
        $saveUseErrors = libxml_use_internal_errors(true);
        try {
            libxml_clear_errors();
            if (empty($xml)) {
                throw new \Exception('Tried to parse empty XML string');
            }
            $doc = $this->metadataUtils->loadXML($xml);
            if (false === $doc) {
                $errors = libxml_get_errors();
                $messageParts = [];
                foreach ($errors as $error) {
                    $messageParts[] = '[' . $error->line . ':' . $error->column
                        . '] Error ' . $error->code . ': ' . $error->message;
                }
                throw new \Exception(implode("\n", $messageParts));
            }
            libxml_use_internal_errors($saveUseErrors);
            assert($doc instanceof \SimpleXMLElement);
            return $doc;
        } catch (\Exception $e) {
            libxml_use_internal_errors($saveUseErrors);
            throw $e;
        }
    }
}

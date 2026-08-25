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

use VuFindXml\XmlDoc;

/**
 * XML record trait
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
trait XmlDocRecordTrait
{
    /**
     * The XML namespace.
     *
     * @var string
     */
    protected string $nsXml = 'http://www.w3.org/XML/1998/namespace';

    /**
     * The XMLNS namespace.
     *
     * @var string
     */
    protected string $nsXmlns = 'http://www.w3.org/2000/xmlns/';

    /**
     * XML schema instance namespace.
     *
     * @var string
     */
    protected string $nsXsi = 'http://www.w3.org/2001/XMLSchema-instance';

    /**
     * XML Document
     *
     * @var ?XmlDoc
     */
    protected ?XmlDoc $xmlDoc = null;

    /**
     * Default namespace
     *
     * @var ?string
     */
    protected ?string $defaultNamespace = null;

    /**
     * Default namespace prefix
     *
     * @var ?string
     */
    protected ?string $defaultNamespacePrefix = null;

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

        $this->xmlDoc = new XmlDoc();
        if (str_starts_with($data, '{')) {
            if (null === ($data = json_decode($data, true))) {
                throw new \RuntimeException('Invalid data');
            }
            $this->xmlDoc->import($data);
        } else {
            $this->xmlDoc->parse($data);
        }
        if (null !== $this->defaultNamespace) {
            $this->xmlDoc->setDefaultNamespace($this->defaultNamespace, $this->defaultNamespacePrefix);
        }
    }

    /**
     * Serialize the record for storing in the database
     *
     * @return string
     */
    public function serialize()
    {
        return json_encode($this->xmlDoc->export());
    }

    /**
     * Serialize the record into XML for export
     *
     * @return string
     */
    public function toXML()
    {
        if (null === $this->xmlDoc) {
            throw new \Exception('Document not set');
        }
        try {
            // Ensure that the default namespace doesn't get applied to a record that's missing namespaces:
            $this->xmlDoc->setDefaultNamespace(null);
            $result = $this->xmlDoc->toXML();
            if (null !== $this->defaultNamespace) {
                $this->xmlDoc->setDefaultNamespace($this->defaultNamespace, $this->defaultNamespacePrefix);
            }
            return $result;
        } catch (\Exception $e) {
            throw new \Exception(
                "Could not serialize record '{$this->source}."
                . $this->getId() . "' to XML: " . (string)$e
            );
        }
    }

    /**
     * Get lang attribute from xml namespace with fallback to default namespace.
     *
     * @param array $node XmlDoc node
     *
     * @return ?string
     */
    protected function getLangAttr(array $node): ?string
    {
        $xml = $this->xmlDoc ?? new XmlDoc();
        return $xml->attr($node, "{{$this->nsXml}}lang") ?? $xml->attr($node, 'lang');
    }
}

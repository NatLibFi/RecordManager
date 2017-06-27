<?php
/**
 * Create Preview Record
 *
 * PHP version 5
 *
 * Copyright (C) The National Library of Finland 2011-2017.
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
namespace RecordManager\Base\Controller;

use RecordManager\Base\Record\Factory as RecordFactory;
use RecordManager\Base\Solr\PreviewCreator;

/**
 * Create Preview Record
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class CreatePreview extends AbstractBase
{
    use PreTransformationTrait;

    /**
     * Constructor
     *
     * @param array $config  Main configuration
     * @param bool  $console Specify whether RecordManager is executed on the
     * console so that log output is also output to the console.
     * @param bool  $verbose Whether verbose output is enabled
     */
    public function __construct($config, $console = false, $verbose = false)
    {
        parent::__construct($config, $console, $verbose);

        if (empty($this->dataSourceSettings['_preview'])) {
            $this->dataSourceSettings['_preview'] = [
                'institution' => '_preview',
                'componentParts' => null,
                'format' => '_preview',
                'preTransformation' => 'strip_namespaces.xsl',
                'extraFields' => [],
                'mappingFiles' => []
            ];
        }
        if (empty($this->dataSourceSettings['_marc_preview'])) {
            $this->dataSourceSettings['_marc_preview'] = [
                'institution' => '_preview',
                'componentParts' => null,
                'format' => 'marc',
                'extraFields' => [],
                'mappingFiles' => []
            ];
        }
    }

    /**
     * Create a preview of the given metadata and return it
     *
     * @param string $metadata The metadata to process
     * @param string $format   Metadata format
     * @param string $source   Source identifier
     *
     * @return array Solr record fields
     */
    public function launch($metadata, $format, $source)
    {
        if (!$source || !isset($this->dataSourceSettings[$source])) {
            $source = "_preview";
        }

        $this->initSourceSettings();

        $settings = $this->dataSourceSettings[$source];

        if (empty($format) && !empty($settings['format'])) {
            $format = $settings['format'];
        }

        if ($settings['preTransformation']) {
            $metadata = $this->pretransform($metadata);
        }

        $timestamp = $this->db->getTimestamp();
        $record = [
            'format' => $format,
            'original_data' => $metadata,
            'normalized_data' => $metadata,
            'source_id' => $source,
            'linking_id' => '',
            'oai_id' => '_preview',
            '_id' => '_preview',
            'created' => $timestamp,
            'date' => $timestamp
        ];

        // Normalize the record
        if (null !== $settings['normalizationXSLT']) {
            $record['normalized_data'] = $settings['normalizationXSLT']->transform(
                $metadata, ['oai_id' => $record['oai_id']]
            );
        }

        if (!RecordFactory::canCreate($record['format'])) {
            die("Format '$format' not supported");
        }

        $metadataRecord = RecordFactory::createRecord(
            $record['format'],
            $record['normalized_data'],
            $record['oai_id'],
            $record['source_id']
        );
        $metadataRecord->normalize();
        $record['normalized_data'] = $metadataRecord->serialize();
        $record['_id'] = $record['linking_id']
            = $source . '.' . $metadataRecord->getID();

        $preview = new \RecordManager\Base\Solr\PreviewCreator(
            $this->db, $this->basePath, $this->logger, $this->verbose, $this->config,
            $this->dataSourceSettings
        );

        return $preview->create($record);
    }
}

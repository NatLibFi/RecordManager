<?php

/**
 * Create Preview Record
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

namespace RecordManager\Base\Controller;

use RecordManager\Base\Database\DatabaseInterface;
use RecordManager\Base\Deduplication\DedupHandlerInterface;
use RecordManager\Base\Record\PluginManager as RecordPluginManager;
use RecordManager\Base\Solr\PreviewCreator;
use RecordManager\Base\Splitter\PluginManager as SplitterPluginManager;
use RecordManager\Base\Utils\LineBasedMarcFormatter;
use RecordManager\Base\Utils\Logger;
use RecordManager\Base\Utils\MetadataUtils;
use RecordManager\Base\Utils\XslTransformation;

use function in_array;

/**
 * Create Preview Record
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class CreatePreview extends AbstractBase
{
    use \RecordManager\Base\Record\PreTransformationTrait;

    /**
     * Preview creator
     *
     * @var PreviewCreator
     */
    protected $previewCreator;

    /**
     * Line-based MARC formatter
     *
     * @var LineBasedMarcFormatter
     */
    protected $lineBasedFormatter;

    /**
     * Constructor
     *
     * @param array                  $config              Main configuration
     * @param array                  $datasourceConfig    Datasource configuration
     * @param Logger                 $logger              Logger
     * @param DatabaseInterface      $database            Database
     * @param RecordPluginManager    $recordPluginManager Record plugin manager
     * @param SplitterPluginManager  $splitterManager     Record splitter plugin
     *                                                    manager
     * @param DedupHandlerInterface  $dedupHandler        Deduplication handler
     * @param MetadataUtils          $metadataUtils       Metadata utilities
     * @param PreviewCreator         $previewCreator      Preview creator
     * @param LineBasedMarcFormatter $lineBasedFormatter  Line-based MARC formatter
     */
    public function __construct(
        array $config,
        array $datasourceConfig,
        Logger $logger,
        DatabaseInterface $database,
        RecordPluginManager $recordPluginManager,
        SplitterPluginManager $splitterManager,
        DedupHandlerInterface $dedupHandler,
        MetadataUtils $metadataUtils,
        PreviewCreator $previewCreator,
        LineBasedMarcFormatter $lineBasedFormatter
    ) {
        parent::__construct(
            $config,
            $datasourceConfig,
            $logger,
            $database,
            $recordPluginManager,
            $splitterManager,
            $dedupHandler,
            $metadataUtils
        );

        $this->previewCreator = $previewCreator;
        $this->lineBasedFormatter = $lineBasedFormatter;
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
        if (!$source || !isset($this->dataSourceConfig[$source])) {
            $source = 'marc' === $format ? '_marc_preview' : '_preview';
        }

        $settings = $this->dataSourceConfig[$source];

        if (empty($format) && !empty($settings['format'])) {
            $format = $settings['format'];
        }

        // Check for line-based MARC and convert as necessary:
        if ('marc' === $format && preg_match('/^\s*(LDR|\d{3})\s/', $metadata)) {
            $metadata = $this->lineBasedFormatter
                ->convertLineBasedMarcToXml($metadata);
        }

        if (!empty($settings['preTransformation'])) {
            $metadata = $this->pretransform($metadata, $source);
        } elseif (!empty($settings['oaipmhTransformation'])) {
            $metadata = $this->oaipmhTransform(
                $metadata,
                $settings['oaipmhTransformation']
            );
        }

        if ('marc' !== $format && substr(trim($metadata), 0, 1) === '<') {
            $doc = new \DOMDocument();
            if ($this->metadataUtils->loadXML($metadata, $doc)) {
                $root = $doc->childNodes->item(0);
                if (in_array($root->nodeName, ['records', 'collection'])) {
                    // This is a collection of records, get the first one
                    $metadata = $doc->saveXML($root->childNodes->item(0));
                }
            }
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
            'date' => $timestamp,
        ];

        // Normalize the record
        if (!empty($settings['normalization'])) {
            $params = [
                'source_id' => $source,
                'institution' => $settings['institution'],
                'format' => $settings['format'],
                'id_prefix' => $settings['idPrefix'] ?? '',
            ];
            $normalizationXSLT = new XslTransformation(
                RECMAN_BASE_PATH . '/transformations',
                $settings['normalization'],
                $params
            );

            $record['normalized_data'] = $normalizationXSLT->transform(
                $metadata,
                ['oai_id' => $record['oai_id']]
            );
        }

        if (!$this->recordPluginManager->has($record['format'])) {
            throw new \Exception("Format '$format' not supported");
        }

        $metadataRecord = $this->createRecord(
            $record['format'],
            $record['normalized_data'],
            $record['oai_id'],
            $record['source_id']
        );
        $metadataRecord->normalize();
        $record['normalized_data'] = $metadataRecord->serialize();
        $record['_id'] = $record['linking_id']
            = $source . '.' . $metadataRecord->getID();

        return $this->previewCreator->create($record);
    }

    /**
     * Get a list of valid data sources
     *
     * @param string $format Optional limit to specific format
     *
     * @return array
     */
    public function getDataSources($format = '')
    {
        $result = [];
        foreach ($this->dataSourceConfig as $id => $config) {
            if ($format && $config['format'] !== $format) {
                continue;
            }
            $result[] = [
                'id' => $id,
                'format' => $config['format'] ?? '',
                'institution' => $config['institution'],
            ];
        }

        return $result;
    }

    /**
     * Perform OAI-PMH transformation for the record
     *
     * @param string       $metadata        Record metadata
     * @param string|array $transformations XSL transformations
     *
     * @return string
     */
    protected function oaipmhTransform($metadata, $transformations)
    {
        $doc = new \DOMDocument();
        if (!$this->metadataUtils->loadXML($metadata, $doc)) {
            throw new \Exception(
                'Could not parse XML record'
            );
        }
        foreach ((array)$transformations as $transformation) {
            $style = new \DOMDocument();
            $loadResult = $style->load(
                RECMAN_BASE_PATH . "/transformations/$transformation"
            );
            if (false === $loadResult) {
                throw new \Exception(
                    'Could not load configured OAI-PMH transformation'
                );
            }
            $preXslt = new \XSLTProcessor();
            $preXslt->importStylesheet($style);
            $doc = $preXslt->transformToDoc($doc);
            if (false === $doc) {
                throw new \Exception(
                    'Could not process configured OAI-PMH transformation.'
                    . ' The record may be invalid.'
                );
            }
        }
        return $doc->saveXML();
    }
}

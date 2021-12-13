<?php
/**
 * Create Preview Record
 *
 * PHP version 7
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
use RecordManager\Base\Utils\Logger;
use RecordManager\Base\Utils\MetadataUtils;
use RecordManager\Base\Utils\XslTransformation;

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
     * Constructor
     *
     * @param array                 $config              Main configuration
     * @param array                 $datasourceConfig    Datasource configuration
     * @param Logger                $logger              Logger
     * @param DatabaseInterface     $database            Database
     * @param RecordPluginManager   $recordPluginManager Record plugin manager
     * @param SplitterPluginManager $splitterManager     Record splitter plugin
     *                                                   manager
     * @param DedupHandlerInterface $dedupHandler        Deduplication handler
     * @param MetadataUtils         $metadataUtils       Metadata utilities
     * @param PreviewCreator        $previewCreator      Preview creator
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
        PreviewCreator $previewCreator
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

        if (empty($this->dataSourceConfig['_preview'])) {
            $this->dataSourceConfig['_preview'] = [
                'institution' => '_preview',
                'componentParts' => null,
                'format' => '_preview',
                'preTransformation' => 'strip_namespaces.xsl',
                'extraFields' => [],
                'mappingFiles' => []
            ];
        }
        if (empty($this->dataSourceConfig['_marc_preview'])) {
            $this->dataSourceConfig['_marc_preview'] = [
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
        if (!$source || !isset($this->dataSourceConfig[$source])) {
            $source = "_preview";
        }

        $settings = $this->dataSourceConfig[$source];

        if (empty($format) && !empty($settings['format'])) {
            $format = $settings['format'];
        }

        // Check for line-based MARC and convert as necessary:
        if ('marc' === $format && preg_match('/^\s*(LDR|\d{3})\s/', $metadata)) {
            $metadata = $this->convertLineBasedMarcToXml($metadata);
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
            'date' => $timestamp
        ];

        // Normalize the record
        if (!empty($settings['normalization'])) {
            $params = [
                'source_id' => $source,
                'institution' => $settings['institution'],
                'format' => $settings['format'],
                'id_prefix' => $settings['idPrefix']
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
                'institution' => $config['institution']
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

    /**
     * Convert a line-based MARC record ("tagged" output) to MARCXML
     *
     * Supports formats from Alma MARC record view and OCLC tagged output
     *
     * @param string $metadata Metadata
     *
     * @return string
     */
    protected function convertLineBasedMarcToXml(string $metadata)
    {
        $xml = simplexml_load_string(
            "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n\n"
            . "<collection><record></record></collection>"
        );
        $record = $xml->record[0];

        // Determine subfield format:
        $pipeCount = substr_count($metadata, '|');
        $dollarCount = substr_count($metadata, '$');
        if ($dollarCount > $pipeCount) {
            $subfieldRegExp = '/\$([a-z0-9])/';
        } else {
            $subfieldRegExp = '/\|([a-z0-9]) /';
        }

        foreach (explode("\n", $metadata) as $line) {
            $line = trim($line);
            if (!$line) {
                continue;
            }
            $tag = substr($line, 0, 3);
            $content = substr($line, 4);
            if (strncmp($content, "'", 1) === 0 && substr($content, -1) === "'") {
                $content = substr($content, 1, -1);
            }
            if ('LDR' === $tag || '000' === $tag) {
                // Make sure leader is 24 characters:
                $leader = str_pad(substr($content, 4, 24), 24);
                $record->addChild('leader', htmlspecialchars($leader));
            } elseif (intval($tag) < 10) {
                $field = $record->addChild(
                    'controlfield',
                    htmlspecialchars($content, ENT_NOQUOTES)
                );
                $field->addAttribute('tag', $tag);
            } else {
                $ind1 = substr($content, 4, 1);
                if ('_' === $ind1) {
                    $ind1 = ' ';
                }
                $ind2 = substr($content, 5, 1);
                if ('_' === $ind2) {
                    $ind2 = ' ';
                }
                $field = $record->addChild('datafield');
                $field->addAttribute('tag', $tag);
                $field->addAttribute('ind1', $ind1);
                $field->addAttribute('ind2', $ind2);

                $subs = preg_split(
                    $subfieldRegExp,
                    substr($content, 3),
                    -1,
                    PREG_SPLIT_DELIM_CAPTURE
                );
                array_shift($subs);
                while ($subs) {
                    $code = array_shift($subs);
                    $value = array_shift($subs);
                    if ('' === $value) {
                        continue;
                    }
                    $subfield = $field->addChild(
                        'subfield',
                        htmlspecialchars($value, ENT_NOQUOTES)
                    );
                    $subfield->addAttribute('code', $code);
                }
            }
        }
        return $record->asXML();
    }
}

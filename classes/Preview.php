<?php
/**
 * Preview Class
 *
 * PHP version 5
 *
 * Copyright (C) Eero Heikkinen, The National Board of Antiquities 2013
 * Copyright (C) The National Library of Finland 2012-2013
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
 * @author   Eero Heikkinen <eero.heikkinen@gmail.com>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */

require_once 'SolrUpdater.php';
require_once 'RecordFactory.php';
require_once 'Logger.php';
require_once 'XslTransformation.php';

/**
 * Preview Class
 *
 * This is a class for getting realtime previews of metadata normalization.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @author   Eero Heikkinen <eero.heikkinen@gmail.com>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class Preview extends SolrUpdater
{
    /**
     * Constructor
     * 
     * @param MongoDB $db       Database connection
     * @param string  $basePath RecordManager main directory 
     * @param object  $log      Logger
     * @param boolean $verbose  Whether to output verbose messages
     * 
     * @throws Exception
     */
    public function __construct($db, $basePath, $log, $verbose)
    {
        parent::__construct($db, $basePath, $log, $verbose);
        if (empty($this->settings['_preview'])) {
            $this->settings['_preview'] = array(
                'institution' => '_preview',
                'componentParts' => null,
                'format' => '_preview',
                'preTransformation' => 'strip_namespaces.xsl'
            );
        }
        if (empty($this->settings['_marc_preview'])) {
            $this->settings['_marc_preview'] = array(
                'institution' => '_preview',
                'componentParts' => null,
                'format' => 'marc'
            );
        }
    }

    /**
     * Creates a preview of the given metadata and returns it
     * 
     * @param string $metadata The metadata to process
     * @param string $format   Metadata format
     * @param string $source   Source identifier
     * 
     * @return array
     */
    public function preview($metadata, $format, $source) 
    {
        if (!$source) {
            $source = "_preview";
        }
        
        /* Process data source preTransformation XSL if present
           TODO: duplicates code from RecordManager, refactor? */
        $settings = $this->settings[$source];
        if (isset($settings['preTransformation']) && $settings['preTransformation']) {
            $style = new DOMDocument();
            $style->load($this->basePath . '/transformations/' . $settings['preTransformation']);
            $xslt = new XSLTProcessor();
            $xslt->importStylesheet($style);
            $xslt->setParameter('', 'source_id', $source);
            $xslt->setParameter('', 'institution', $settings['institution']);
            $xslt->setParameter('', 'format', $format);
            $xslt->setParameter('', 'id_prefix', isset($settings['idPrefix']) && $settings['idPrefix'] ? $settings['idPrefix'] : $source);

            $doc = new DOMDocument();
            $doc->loadXML($metadata);
            $metadata = $xslt->transformToXml($doc);
        }
        
        $record = array(
            'format' => $format,
            'original_data' => $metadata,
            'normalized_data' => $metadata,
            'source_id' => $source,
            'linking_id' => '',
            'oai_id' => '_preview',
            '_id' => '_preview',
            'created' => new MongoDate(),
            'date' => new MongoDate()
        );

        // Normalize the record
        $this->normalizationXSLT = isset($settings['normalization']) && $settings['normalization'] ?  : null;
        if (isset($settings['normalization'])) {
            $basePath = substr(__FILE__, 0, strrpos(__FILE__, DIRECTORY_SEPARATOR));
            $basePath = substr($basePath, 0, strrpos($basePath, DIRECTORY_SEPARATOR));
            $params = array('source_id' => $this->source, 'institution' => 'Preview', 'format' => $format, 'id_prefix' => '');
            $normalizationXSLT = new XslTransformation($basePath . '/transformations', $settings['normalization'], $params);
            $origMetadataRecord = RecordFactory::createRecord($record['format'], $metadata, $record['oai_id'], $record['source_id']);
            $record['normalized_data'] = $normalizationXSLT->transform($metadata, array('oai_id' => $record['oai_id']));
        }

        $metadataRecord = RecordFactory::createRecord($record['format'], $record['normalized_data'], $record['oai_id'], $record['source_id']);
        $metadataRecord->normalize();
        $record['normalized_data'] = $metadataRecord->serialize();
        
        return $this->createSolrArray($record, $componentParts);
    }
}

<?php
/**
 * Preview Class
 *
 * PHP version 5
 *
 * Copyright (C) Ere Maijala, The National Library of Finland 2012
 * Copyright (C) Eero Heikkinen, The National Board of Antiquities 2013
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
    protected $format = '';
    protected $source = '';
    
    /**
     * Constructor
     * 
     * @param string $basePath RecordManager main directory
     * @param string $format   Record driver format (optional)
     * @param string $source   The data source id to use (optional)
     */
    public function __construct($basePath, $format, $source) 
    {
        $this->format = $format;
        $this->source = $source; 
        $log = new Logger();
        $sources = parse_ini_file("$basePath/conf/datasources.ini", true);
        if (empty($sources['_preview'])) {
            $sources['_preview'] = array(
                'institution' => '_preview',
                'componentParts' => null,
                'format' => $this->format ? $this->format : '_preview',
                'preTransformation' => 'strip_namespaces.xsl'
            );
        }
        parent::__construct(null, $basePath, $sources, $log, false);
    }

    /**
     * Creates a preview of the given metadata and returns it
     * 
     * @param string $metadata The metadata to process
     * 
     * @return array
     */
    public function preview($metadata) 
    {
        if (!$this->source) {
            $this->source = "_preview";
        }
        
        /* Process data source preTransformation XSL if present
           TODO: duplicates code from RecordManager, refactor? */
        $settings = $this->settings[$this->source];
        if (isset($settings['preTransformation']) && $settings['preTransformation']) {
            $style = new DOMDocument();
            $style->load($this->basePath . '/transformations/' . $settings['preTransformation']);
            $xslt = new XSLTProcessor();
            $xslt->importStylesheet($style);
            $xslt->setParameter('', 'source_id', $this->source);
            $xslt->setParameter('', 'institution', $settings['institution']);
            $xslt->setParameter('', 'format', $this->format);
            $xslt->setParameter('', 'id_prefix', isset($settings['idPrefix']) && $settings['idPrefix'] ? $settings['idPrefix'] : $this->source);

            $doc = new DOMDocument();
            $doc->loadXML($metadata);
            $metadata = $xslt->transformToXml($doc);
        }
        
        $record = array(
            'format' => $this->format,
            'normalized_data' => $metadata,
            'source_id' => $this->source,
            'host_record_id' => "_preview",
            'oai_id' => "_preview",
            '_id' => "_preview",
            'created' => new MongoDate(),
            'date' => new MongoDate()
        );

        return $this->createSolrArray($record, $dummy = array());
    }
}

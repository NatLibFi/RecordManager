<?php
/**
 * NdlLidoRecord Class
 *
 * PHP version 5
 *
 * Copyright (C) Ere Maijala, The National Library of Finland 2012
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

require_once 'LidoRecord.php';
require_once 'MetadataUtils.php';

/**
 * NdlLidoRecord Class
 *
 * LidoRecord with NDL specific functionality
 * 
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class NdlLidoRecord extends LidoRecord
{
    /**
     * Return fields to be indexed in Solr (an alternative to an XSL transformation)
     *
     * @return string[]
     */
    public function toSolrArray()
    {
        $data = parent::toSolrArray();
        $doc = $this->doc;

        // REMOVE THIS ONCE TUUSULA IS FIXED
        $categoryTerm = $this->getCategoryTerm();
        if ($data['institution'] == 'Tuusula' && $categoryTerm == 'Man-Made Object') {
            $data['format'] = $this->getClassification('pääluokka');
        }
        // END OF TUUSULA FIX

        
        // REMOVE THIS ONCE KANTAPUU IS FIXED
        if ($data['institution'] == 'Kantapuu') {
            $data['institution'] = $this->getRightsHolderLegalBodyName();
            if (empty($data['institution'])) {
                unset($data['institution']);
            }
        }
        // END OF KANTAPUU FIX
        
        // REMOVE THIS ONCE TUUSULA IS FIXED
        // sometimes there are multiple subjects in one element
        // seperated with commas like "foo, bar, baz" (Tuusula)
        if (is_array($data['topic'])) {
            $topic = array();
            foreach ($data['topic'] as $subject) {
                $exploded = explode(',', $subject);
                foreach ($exploded as $explodedSubject) {
                    $topic[] = trim($explodedSubject);
                }
            }
            $data['topic'] = $data['topic_facet'] = $topic;
        }
        // END OF TUUSULA FIX
        
        if (!empty($data['material'])) {
            $materials = array();
            // sometimes there are multiple materials in one element
            // seperated with semicolons like "foo; bar; baz" (Musketti)
            // or with commas (Kantapuu)
            // TODO: have this fixed at the data source
            if (!is_array($data['material'])) {
                $data['material'] = array($data['material']);
            }
            
            foreach ($data['material'] as $material) {
                $exploded = explode(';', str_replace(',', ';', $material));
                
                foreach ($exploded as $explodedMaterial) {
                    $materials = trim($explodedMaterial);
                }
            }
            $data['material'] = $materials;
        }
        
        $daterange = explode(',', $this->getDateRange('valmistus'));
        if ($daterange) {
            $data['main_date_str'] = MetadataUtils::extractYear($daterange[0]);
        }
        
        $data['allfields'] = $this->getAllFields($data);
        
        return $data;
    }

    /**
     * Return the object description.
     *
     * @link http://www.lido-schema.org/schema/v1.0/lido-v1.0-schema-listing.html#descriptiveNoteComplexType
     * @return string
     */
    protected function getDescription()
    {
        $description = parent::getDescription();
        if (!isset($description)) {
            return $description;
        }
        
        // REMOVE THIS ONCE TUUSULA IS FIXED
                    
        // Quick and dirty way to get description when it's in the subject wrap (Tuusula)
        return $this->extractFirst("lido/descriptiveMetadata/objectRelationWrap/subjectWrap/subjectSet/displaySubject[@label='aihe']");
        
        // END OF TUUSULA FIX
    }
    
    
    /**
     * Get the default language used when building the Solr array
     * 
     * @return string
     */
    protected function getDefaultLanguage()
    {
        return 'fi';
    }
}


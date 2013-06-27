<?php
/**
 * NdlMarcRecord Class
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

require_once 'MarcRecord.php';
require_once 'MetadataUtils.php';

/**
 * NdlMarcRecord Class
 *
 * MarcRecord with NDL specific functionality
 * 
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class NdlMarcRecord extends MarcRecord
{
    /**
     * Normalize the record (optional)
     *
     * @return void
     */
    public function normalize()
    {
        if (isset($this->fields['653']) && strncmp($this->source, 'metalib', 7) == 0) {
            // Split MetaLib subjects
            $fields = array();
            foreach ($this->fields['653'] as &$field) {
                foreach ($field['s'] as $subfield) {
                    if (key($subfield) == 'a') {
                        foreach (explode('; ', current($subfield)) as $value) {
                            $fields[] = array(
                                'i1' => $field['i1'], 
                                'i2' => $field['i2'],
                                's' => array(array('a' => $value))
                            );
                        }
                    } else {
                        $fields[] = array(
                            'i1' => $field['i1'], 
                            'i2' => $field['i2'],
                            's' => array($subfield)
                        );
                    }
                }
            }
            $this->fields['653'] = $fields;
        }
        // Kyyti enumeration from 362 to title
        if ($this->source == 'kyyti' && isset($this->fields['245']) && isset($this->fields['362'])) {
            $enum = $this->getFieldSubfields('362a');
            if ($enum) {
                $this->fields['245'][0]['s'][] = array('n' => $enum);
            } 
        }
        
    }
    
    /**
     * Return record linking ID (typically same as ID) used for links
     * between records in the data source
     *
     * @return string
     */
    public function getLinkingID()
    {
        global $configArray;
        if (isset($configArray['Local']['linking_id_003']) && in_array($this->source, $configArray['Local']['linking_id_003'])) {
            $source = $this->getField('003');
            if ($source) {
                return "($source)" . $this->getID();
            }
        }
        return $this->getID();
    }

    /**
     * Return fields to be indexed in Solr (an alternative to an XSL transformation)
     *
     * @return string[]
     */
    public function toSolrArray()
    {
        $data = parent::toSolrArray();
        if (isset($data['publishDate'])) {
            $data['main_date_str'] = MetadataUtils::extractYear($data['publishDate'][0]);
        }
        
        // language override
        $data['language'] = array();
        $languages = array(substr($this->getField('008'), 35, 3));
        $languages += $this->getFieldsSubfields(array(array(MarcRecord::GET_NORMAL, '041', array('a'))), false, true, true);
        foreach ($languages as $language) {
            if (preg_match('/^\w{3}$/', $language) && $language != 'zxx' && $language != 'und') {
                $data['language'][] = $language;
            }
        }
        
        // Location coordinates
        $field = $this->getField('034');
        if ($field) {
            $westOrig = $this->getSubfield($field, 'd');
            $eastOrig = $this->getSubfield($field, 'e');
            $northOrig = $this->getSubfield($field, 'f');
            $southOrig = $this->getSubfield($field, 'g');
            $west = MetadataUtils::coordinateToDecimal($westOrig);
            $east = MetadataUtils::coordinateToDecimal($eastOrig);
            $north = MetadataUtils::coordinateToDecimal($northOrig);
            $south = MetadataUtils::coordinateToDecimal($southOrig);

            if (!is_nan($west) && !is_nan($north)) {
                if (($west < -180 || $west > 180) || ($north < -90 || $north > 90)) {
                    global $logger;
                    $logger->log('NdlMarcRecord', "Discarding invalid coordinates $west,$north decoded from w=$westOrig, e=$eastOrig, n=$northOrig, s=$southOrig, record {$this->source}." . $this->getID(), Logger::WARNING);
                } else {
                    if (!is_nan($east) && !is_nan($south)) {
                        if (($east < -180 || $east > 180) || ($south < -90 || $south > 90)) {
                            global $logger;
                            $logger->log('NdlMarcRecord', "Discarding invalid coordinates $east,$south decoded from w=$westOrig, e=$eastOrig, n=$northOrig, s=$southOrig, record {$this->source}." . $this->getID(), Logger::WARNING);
                        } else {
                            // Try to cope with weird coordinate order
                            if ($north > $south) {
                                list($north, $south) = array($south, $north);
                            }
                            if ($west > $east) {
                                list($west, $east) = array($east, $west);
                            }
                            $data['location_geo'] = "$west $north $east $south";
                        }
                    } else {
                        $data['location_geo'] = "$west $north";
                    }
                }
            }
        }
        $data['author'] = $this->getFieldSubfields('100abcde');
        
        foreach ($this->getFieldsSubfields(array(array(MarcRecord::GET_NORMAL, '080', array('a', 'b')))) as $classification) {
            $data['classification_str_mv'][] = 'udk ' . strtolower(str_replace(' ', '', $classification));
        }
        foreach ($this->getFieldsSubfields(array(array(MarcRecord::GET_NORMAL, '050', array('a', 'b')))) as $classification) {
            $data['classification_str_mv'][] = 'dlc ' . strtolower(str_replace(' ', '', $classification));
        }
        foreach ($this->getFields('084') as $field) {
            $source = $this->getSubfield($field, '2');
            $classification = $this->getSubfields($field, 'ab');
            if ($source) {
                $data['classification_str_mv'][] = "$source " . mb_strtolower(str_replace(' ', '', $classification));
            }     
        }
        if (isset($data['classification_str_mv'])) {
            foreach ($data['classification_str_mv'] as $classification) {
                $data['allfields'][] = $classification;
            }
        }
        
        // Ebrary location
        foreach ($this->getFieldsSubfields(array(array(MarcRecord::GET_NORMAL, '035', array('a')))) as $field) {
            if (strncmp($field, 'ebr', 3) == 0 && is_numeric(substr($field, 3))) {
                $data['building'][] = 'Ebrary';
            }
        }
        
        // Topics
        if (strncmp($this->source, 'metalib', 7) == 0) {
            $data['topic'] += $this->getFieldsSubfields(array(array(MarcRecord::GET_BOTH, '653', array('a'))));
            $data['topic_facet'] += $this->getFieldsSubfields(array(array(MarcRecord::GET_BOTH, '653', array('a'))));
        }
        
        // Original Study Number
        $data['ctrlnum'] = array_merge($data['ctrlnum'], $this->getFieldsSubfields(array(array(MarcRecord::GET_NORMAL, '036', array('a')))));
        
        // Source
        $data['source_str_mv'] = $this->source;

        // ISSN
        $data['issn'] = $this->getFieldsSubfields(array(array(MarcRecord::GET_NORMAL, '022', array('a'))));
        foreach ($data['issn'] as &$value) {
            $value = str_replace('-', '', $value);
        }
        $data['other_issn_str_mv'] = $this->getFieldsSubfields(
            array(
                array(MarcRecord::GET_NORMAL, '440', array('x')),
                array(MarcRecord::GET_NORMAL, '480', array('x')),
                array(MarcRecord::GET_NORMAL, '730', array('x')),
                array(MarcRecord::GET_NORMAL, '776', array('x'))
            )
        );
        foreach ($data['other_issn_str_mv'] as &$value) {
            $value = str_replace('-', '', $value);
        }
        $data['linking_issn_str_mv'] = $this->getFieldsSubfields(array(array(MarcRecord::GET_NORMAL, '022', array('l'))));
        foreach ($data['linking_issn_str_mv'] as &$value) {
            $value = str_replace('-', '', $value);
        }
        
        return $data;
    }
    
    /**
     * Dedup: Return format from predefined values
     *
     * @return string
     * @access public
     */
    public function getFormat()
    {
        // Custom predefined type in 977a
        $field977a = $this->getFieldSubfields('977a');
        if ($field977a) {
            return $field977a;
        }
        
        // Dissertations and Thesis
        if (isset($this->fields['502'])) {
            return 'Dissertation';
        }
        if (isset($this->fields['509'])) {
            return 'ProGradu';
        }
        return parent::getFormat();
    }
}

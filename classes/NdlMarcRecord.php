<?php
/**
 * NdlMarcRecord Class
 *
 * PHP version 5
 *
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
            $enum = $this->getFieldSubfields('362', array('a'));
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
        $data['publication_sdaterange'] = $this->getPublicationDateRange();
        if ($data['publication_sdaterange']) {
            $data['search_sdaterange_mv'][] = $data['publication_sdaterange'];
        }
        
        // language override
        $data['language'] = array();
        $languages = array(substr($this->getField('008'), 35, 3));
        $languages += $this->getFieldsSubfields(
            array(
                array(MarcRecord::GET_NORMAL, '041', array('a')),
                array(MarcRecord::GET_NORMAL, '979', array('h')) // 979h = component part language
            ), 
            false, true, true
        );
        foreach ($languages as $language) {
            if (preg_match('/^\w{3}$/', $language) && $language != 'zxx' && $language != 'und') {
                $data['language'][] = $language;
            }
        }
        
        // 979cd = component part authors
        foreach ($this->getFieldsSubfields(
            array(
                array(MarcRecord::GET_BOTH, '979', array('c')),
                array(MarcRecord::GET_BOTH, '979', array('d'))
            ),
            false, true, true
        ) as $field) {
            $data['author2'][] = $field;
        }
        $key = array_search($data['author'], $data['author2']);
        if ($key !== false) {
            unset($data['author2'][$key]);
        }
        $data['author2'] = array_filter(array_values($data['author2']));
        
        $data['title_alt'] = array_values(
            array_unique(
                $this->getFieldsSubfields(
                    array(
                        array(MarcRecord::GET_ALT, '245', array('a', 'b')),
                        array(MarcRecord::GET_BOTH, '130', array('a', 'd', 'f', 'g', 'k', 'l', 'n', 'p', 's', 't')),
                        array(MarcRecord::GET_BOTH, '240', array('a')),
                        array(MarcRecord::GET_BOTH, '246', array('g')),
                        array(MarcRecord::GET_BOTH, '730', array('a', 'd', 'f', 'g', 'k', 'l', 'n', 'p', 's', 't')),
                        array(MarcRecord::GET_BOTH, '740', array('a')),
                        array(MarcRecord::GET_BOTH, '979', array('b')),
                        array(MarcRecord::GET_BOTH, '979', array('e')),
                    )
                )
            )
        ); // 979b and e = component part title and uniform title
        
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
                if (!isset($data['building']) || !in_array('EbraryDynamic', $data['building'])) {
                    $data['building'][] = 'EbraryDynamic';
                }
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
        
        $fields = $this->getFields('856');
        foreach ($fields as $field) {
            $ind2 = $this->getIndicator($field, 2);
            if ($ind2 == '0' || $ind2 == '1') {
                $data['online_boolean'] = true;
                break;
            }
        }
        
        return $data;
    }
    
    /**
     * Merge component parts to this record
     *
     * @param MongoCollection $componentParts Component parts to be merged
     * 
     * @return void
     */
    public function mergeComponentParts($componentParts)
    {
        $count = 0;
        $parts = array();
        foreach ($componentParts as $componentPart) {
            $data = MetadataUtils::getRecordData($componentPart, true);
            $marc = new MARCRecord($data, '', $this->source);
            $title = $marc->getFieldSubfields('245', array('a', 'b', 'n', 'p'));
            $uniTitle = $marc->getFieldSubfields('240', array('a', 'n', 'p'));
            $additionalTitles = $marc->getFieldsSubfields(
                array(
                    array(MarcRecord::GET_NORMAL, '740', array('a'))
                )
            );
            $authors = $marc->getFieldsSubfields(
                array(
                    array(MarcRecord::GET_NORMAL, '100', array('a', 'e')),
                    array(MarcRecord::GET_NORMAL, '110', array('a', 'e'))
                )
            );
            $additionalAuthors = $marc->getFieldsSubfields(
                array(
                    array(MarcRecord::GET_NORMAL, '700', array('a', 'e')),
                    array(MarcRecord::GET_NORMAL, '710', array('a', 'e'))
                )
            );
            $duration = $marc->getFieldsSubfields(
                array(
                    array(MarcRecord::GET_NORMAL, '306', array('a'))
                )
            );
            $languages = array(substr($marc->getField('008'), 35, 3));
            $languages += $marc->getFieldsSubfields(
                array(
                    array(MarcRecord::GET_NORMAL, '041', array('a')),
                    array(MarcRecord::GET_NORMAL, '041', array('d')),
                    array(MarcRecord::GET_NORMAL, '041', array('h')),
                    array(MarcRecord::GET_NORMAL, '041', array('j'))
                ), 
                false, true, true
            );
            $id = $componentPart['_id'];

            $newField = array(
                'i1' => ' ',
                'i2' => ' ',
                's' => array(
                    array('a' => $id)
                 )
            );
            if ($title) {
                $newField['s'][] = array('b' => $title);
            }
            if ($authors) {
                $newField['s'][] = array('c' => array_shift($authors));
                foreach ($authors as $author) {
                    $newField['s'][] = array('d' => $author);
                }
            }
            foreach ($additionalAuthors as $addAuthor) {
                $newField['s'][] = array('d' => $addAuthor);
            }
            if ($uniTitle) {
                $newField['s'][] = array('e' => $uniTitle);
            }
            if ($duration) {
                $newField['s'][] = array('f' => reset($duration));
            }
            foreach ($additionalTitles as $addTitle) {
                $newField['s'][] = array('g' => $addTitle);
            }
            foreach ($languages as $language) {
                if (preg_match('/^\w{3}$/', $language) && $language != 'zxx' && $language != 'und') {
                    $newField['s'][] = array('h' => $language);
                }
            }
            
            $key = MetadataUtils::createIdSortKey($id);
            $parts[$key] = $newField;
            ++$count;
        }
        ksort($parts);
        $this->fields['979'] = array_values($parts);
        return $count;
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
        $field977a = $this->getFieldSubfields('977', array('a'));
        if ($field977a) {
            return $field977a;
        }
        
        // Dissertations and Thesis
        if (isset($this->fields['502'])) {
            return 'Dissertation';
        }
        if (isset($this->fields['509'])) {
            $field509a = $this->getFieldSubfields('509', array('a'));
            switch (strtolower($field509a)) {
            case 'kandidaatintyö':
            case 'kandidatarbete':
                return 'BachelorsThesis';
            case 'pro gradu -työ':
            case 'pro gradu':
                return 'ProGradu';
            case 'laudaturtyö':
            case 'laudaturavh':
                return 'LaudaturThesis';
            case 'lisensiaatintyö':
            case 'lic.avh.':
                return 'LicentiateThesis';
            case 'diplomityö':
            case 'diplomarbete':
                return 'MastersThesis';
            case 'erikoistyö':
            case 'vicenot.ex.':
                return 'Thesis';
            case 'lopputyö':
            case 'rättsnot.ex.':
                return 'Thesis';
            case 'amk-opinnäytetyö':
            case 'yh-examensarbete':
                return 'BachelorsThesis';
            case 'ylempi amk-opinnäytetyö':
            case 'högre yh-examensarbete':
                return 'MastersThesis';
            }
            return 'Thesis';
        }
        return parent::getFormat();
    }
    
    /**
     * Return publication year/date range
     *
     * @return string
     * @access protected
     */
    protected function getPublicationDateRange()
    {
        $field008 = $this->getField('008');
        if ($field008) {
            switch (substr($field008, 6, 1)) {
            case 'c': 
                $year = substr($field008, 7, 4);
                $startDate = "$year-01-01T00:00:00Z";
                $endDate = '9999-12-31T23:59:59Z';
                break;
            case 'd': 
            case 'i': 
            case 'k': 
            case 'm': 
            case 'q': 
                $year1 = substr($field008, 7, 4);
                $year2 = substr($field008, 11, 4);
                $startDate = "$year1-01-01T00:00:00Z";
                $endDate = "$year2-12-31T23:59:59Z";
                break;                    
            case 'e': 
                $year = substr($field008, 7, 4);
                $mon = substr($field008, 11, 2);
                $day = substr($field008, 13, 2);
                $startDate = "$year-$mon-{$day}T00:00:00Z";
                $endDate = "$year-$mon-{$day}T23:59:59Z";
                break;                    
            case 's': 
            case 't': 
            case 'u': 
                $year = substr($field008, 7, 4);
                $startDate = "$year-01-01T00:00:00Z";
                $endDate = "$year-12-31T23:59:59Z";
                break;                    
            }
        }
        if (isset($startDate) && isset($endDate) && MetadataUtils::validateISO8601Date($startDate) && MetadataUtils::validateISO8601Date($endDate)) {
            return MetadataUtils::convertDateRange(array($startDate, $endDate));
        }
        
        $field = $this->getField('260');
        if ($field) {
            $year = $this->getSubfield($field, 'c');
            $matches = array();
            if ($year && preg_match('/(\d{4})/', $year, $matches)) {
                $startDate = "{$matches[1]}-01-01T00:00:00Z";
                $endDate = "{$matches[1]}-12-31T23:59:59Z";
            }
        }
        if (isset($startDate) && isset($endDate) && MetadataUtils::validateISO8601Date($startDate) && MetadataUtils::validateISO8601Date($endDate)) {
            return MetadataUtils::convertDateRange(array($startDate, $endDate));
        }
        
        $fields = $this->getFields('264');
        foreach ($fields as $field) {
            if ($this->getIndicator($field, 2) == '1') {
                $year = $this->getSubfield($field, 'c');
                $matches = array();
                if ($year && preg_match('/(\d{4})/', $year, $matches)) {
                    $startDate = "{$matches[1]}-01-01T00:00:00Z";
                    $endDate = "{$matches[1]}-12-31T23:59:59Z";
                }
            }
        }
        if (isset($startDate) && isset($endDate) && MetadataUtils::validateISO8601Date($startDate) && MetadataUtils::validateISO8601Date($endDate)) {
            return MetadataUtils::convertDateRange(array($startDate, $endDate));
        }
        
        return '';
    }
    
    /**
     * Get an array of all fields relevant to allfields search
     * 
     * @return string[]
     */
    protected function getAllFields()
    {
        $allFields = array();
        $subfieldFilter = array(
            '650' => array('2', '6', '8'), 
            '773' => array('6', '7', '8', 'w'), 
            '856' => array('6', '8', 'q'), 
            '979' => array('a')
        );
        foreach ($this->fields as $tag => $fields) {
            if (($tag >= 100 && $tag < 841) || $tag == 856 || $tag == 979) {
                foreach ($fields as $field) {
                    $allFields[] = MetadataUtils::stripLeadingPunctuation(
                        MetadataUtils::stripTrailingPunctuation(
                            $this->getAllSubfields(
                                $field,
                                isset($subfieldFilter[$tag]) ? $subfieldFilter[$tag] : array('6', '8')
                            )
                        )
                    );
                }
            }
        }
        return array_values(array_unique($allFields));
    }
}

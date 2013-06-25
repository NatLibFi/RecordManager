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
     * Constructor
     *
     * @param string $data   Metadata
     * @param string $oaiID  Record ID received from OAI-PMH (or empty string for file import)
     * @param string $source Source ID
     */
    public function __construct($data, $oaiID, $source)
    {
        parent::__construct($data, $oaiID, $source);
        
        $this->mainEvent = 'valmistus';
        $this->usagePlaceEvent = 'käyttö';
        $this->relatedWorkRelationTypes = array('Kokoelma', 'kuuluu kokoelmaan', 'kokoelma');
    }
    
    /**
     * Return fields to be indexed in Solr (an alternative to an XSL transformation)
     *
     * @return string[]
     */
    public function toSolrArray()
    {
        $data = parent::toSolrArray();
        $doc = $this->doc;

        // Kantapuu oai provides just the consortium name as the legal body name,
        // so getting the actual institution name from the rightsholder information
        if ($data['institution'] == 'Kantapuu') {
            $data['institution'] = $this->getRightsHolderLegalBodyName();
        } else {
            $data['building'] = reset(explode('/', $data['institution']));
        }
        
        // REMOVE THIS ONCE TUUSULA IS FIXED
        // sometimes there are multiple subjects in one element
        // separated with commas like "foo, bar, baz" (Tuusula)
        $topic = array();
        if (isset($data['topic']) && is_array($data['topic'])) {
            foreach ($data['topic'] as $subject) {
                $exploded = explode(',', $subject);
                foreach ($exploded as $explodedSubject) {
                    $topic[] = trim($explodedSubject);
                }
            }
        }
        $data['topic'] = $data['topic_facet'] = $topic;
        // END OF TUUSULA FIX
        
        $data['artist_str_mv'] = $this->getActor('valmistus', 'taiteilija');
        $data['photographer_str_mv'] = $this->getActor('valmistus', 'valokuvaaja');
        $data['finder_str_mv'] = $this->getActor('löytyminen', 'löytäjä');
        $data['manufacturer_str_mv'] = $this->getActor('valmistus', 'valmistaja');
        $data['designer_str_mv'] = $this->getActor('suunnittelu', 'suunnittelija');
        $data['classification_str_mv'] = $this->getClassifications();
        $data['exhibition_str_mv'] = $this->getEventNames('näyttely');
        
        $daterange = explode(',', $this->getDateRange('valmistus'));
        if ($daterange) {
            $data['main_date_str'] = MetadataUtils::extractYear($daterange[0]);
        }
        $data['creation_daterange'] = $this->getDateRange('valmistus');
        $data['use_daterange'] = $this->getDateRange('käyttö');
        $data['finding_daterange'] = $this->getDateRange('löytyminen');
        $data['source_str_mv'] = $this->source;
        
        $data['allfields'] = $this->getAllFields($data);
        
        return $data;
    }
    
    /**
     * Get allfields contents for Solr from the Solr data array
     * 
     * @param string[] $data Solr data array
     * 
     * @return string
     */
    protected function getAllFields($data)
    {
        $allfields = parent::getAllFields(
            $data, 
            array(
                'title', 'title_alt',  'description', 'format', 'author', 'topic',
                'material', 'measurements', 'identifier', 'culture', 'classification_str_mv',
                'artist_str_mv', 'photographer_str_mv', 'finder_str_mv', 'manufacturer_str_mv', 'designer_str_mv'
            )
        );
        
        // Some fields not separately indexed but searchable
        $allfields[] = $this->getDisplayPlace('valmistus');
        $allfields[] = $this->getDisplayPlace('käyttö');
        $allfields[] = $this->getDisplayPlace('löytyminen');
        $allfields[] = $this->getEventMethod('valmistus');
        $allfields[] = $this->getRecordSourceOrganization();
        
        return $allfields;
    }
    
    /**
     * Return materials associated with the object. Materials are contained inside events, and the
     * 'valmistus' (creation) event contains all the materials of the object.
     * Either the individual materials are retrieved, or the display materials element is
     * retrieved in case of failure.
     *
     * @param string $eventType Which event to use
     *
     * @link http://www.lido-schema.org/schema/v1.0/lido-v1.0-schema-listing.html#materialsTechSetComplexType
     * @return string[]
     * @access public
     */
    protected function getEventMaterials($eventType)
    {
        $materials = parent::getEventMaterials($eventType);
        
        if (!empty($materials)) {
            return $materials;
        }
    
        // If there are no individually listed, straightforwardly indexable materials
        // we can use the displayMaterialsTech field, which is usually meant for display only.
        // However, it's possible to extract the different materials from the display field
        // Some CMS have only one field for materials so this is the only way to index their materials
        
        $xpath = 'lido/descriptiveMetadata/eventWrap/'
            . "eventSet/event[eventType/term='$eventType']/"
            . 'eventMaterialsTech/displayMaterialsTech';
    

        $material = $this->extractFirst($xpath);
        if (empty($material)) {
            return array();
        }
        
        $exploded = explode(';', str_replace(',', ';', $material));
        $materials = array();
        foreach ($exploded as $explodedMaterial) {
            $materials[] = trim($explodedMaterial);
        }
        return $materials;
    }

    /**
     * Return the object description.
     *
     * @link http://www.lido-schema.org/schema/v1.0/lido-v1.0-schema-listing.html#descriptiveNoteComplexType
     * @return string
     */
    protected function getDescription()
    {
        // Exception: Don't extract descriptions with type attribute "provenienssi"
        $descriptionWrapDescriptions = $this->extractArray("lido/descriptiveMetadata/objectIdentificationWrap/objectDescriptionWrap/objectDescriptionSet[not(@type) or (@type!='provenienssi')]/descriptiveNoteValue");
        
        if ($descriptionWrapDescriptions && $this->getTitle() == implode('; ', $descriptionWrapDescriptions)) {
            // We have the description already in the title, don't repeat
            $descriptionWrapDescriptions = array();
        }
        
        // Also read in "description of subject" which contains data suitable for this field
        $subjectDescriptions = $this->extractArray("lido/descriptiveMetadata/objectRelationWrap/subjectWrap/subjectSet/displaySubject[@label='aihe']");
        
        if (empty($descriptionWrapDescriptions)) {
            $descriptionWrapDescriptions = array();
        }
        if (empty($subjectDescriptions)) {
            $subjectDescriptions = array();
        }
        return implode(' ', array_merge($descriptionWrapDescriptions, $subjectDescriptions));
    }
    
    /**
     * Return subjects associated with object.
     *
     * @link http://www.lido-schema.org/schema/v1.0/lido-v1.0-schema-listing.html#subjectComplexType
     * @return string
     * @access public
     */
    protected function getSubjectTerms()
    {
        // Exclude 'aihe' and 'iconclass' because these don't contain (human readable) terms
        return parent::getSubjectTerms(array('aihe', 'iconclass'));
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
    

    /**
     * Attempt to parse a string (in finnish) into a normalized date range.
     * TODO: complicated normalization like this should preferably reside within its own, separate component
     * which should allow modification of the algorithm by methods other than hard-coding rules into source.
     *
     * @param string $input     Date range
     * @param string $delimiter Date delimiter
     *
     * @return string Two ISO 8601 dates separated with the supplied delimiter on success, and null on failure.
     */
    protected function parseDateRange($input, $delimiter = ',')
    {
        $input = trim(strtolower($input));
         
        switch($input) {
        case 'kivikausi':
        case 'kivikauisi':
        case 'kiviakausi':
            $this->earliestYear = '-8600';
            $this->latestYear = '-1500';
            return '-8600-01-01T00:00:00Z,-1501-12-31T23:59:59Z';
        case 'pronssikausi':
            $this->earliestYear = '-1500';
            $this->latestYear = '-500';
            return '-1500-01-01T00:00:00Z,-501-12-31T23:59:59Z';
        case 'rautakausi':
            $this->earliestYear = '-500';
            $this->latestYear = '1300';
            return '-500-01-01T00:00:00Z,1299-12-31T23:59:59Z';
        case 'keskiaika':
            $this->earliestYear = '1300';
            $this->latestYear = '1550';
            return '1300-01-01T00:00:00Z,1550-12-31T23:59:59Z';
        case 'ajoittamaton':
        case 'tuntematon':
            return null;
        }
    
        $k = array(
                'tammikuu' => '01',
                'helmikuu' => '02',
                'maaliskuu' => '03',
                'huhtikuu' => '04',
                'toukokuu' => '05',
                'kesäkuu' => '06',
                'heinäkuu' => '07',
                'elokuu' => '08',
                'syyskuu' => '09',
                'lokakuu' => '10',
                'marraskuu' => '11',
                'joulukuu' => '12'
        );
         
        if (preg_match('/(\d\d\d\d) ?- (\d\d\d\d)/', $input, $matches) > 0) {
            $startDate = $matches[1];
            $endDate = $matches[2];
        } elseif (preg_match('/(\d?\d?\d\d) ?(-|~) ?(\d?\d?\d\d) ?(-luku)?(\(?\?\)?)?/', $input, $matches) > 0) {
            // 1940-1960-luku
            // 1930 - 1970-luku
            // 30-40-luku
            $startDate = $matches[1];
            $endDate = $matches[3];
             
            if (isset($matches[4])) {
                $luku = $matches[4];
                if ($endDate % 10 == 0) {
                    $endDate+=9;
                }
            }
             
            if (isset($matches[5])) {
                $epavarma = $matches[5];
                $startDate -= 2;
                $endDate += 2;
            }
        } elseif (preg_match('/(\d?\d?\d\d) ?-luvun (loppupuoli|loppu|lopulta|loppupuolelta)/', $input, $matches) > 0) {
            $year = $matches[1];
             
            if ($year % 100 == 0) {
                // Century
                $startDate = $year + 70;
                $endDate = $year + 99;
            } elseif ($year % 10 == 0) {
                // Decade
                $startDate = $year + 7;
                $endDate = $year + 9;
            }
        } elseif (preg_match('/(\d?\d?\d\d) (tammikuu|helmikuu|maaliskuu|huhtikuu|toukokuu|kesäkuu|heinäkuu|elokuu|syyskuu|lokakuu|marraskuu|joulukuu)/', $input, $matches) > 0) {
            $year = $matches[1];
            $month = $k[$matches[2]];
            $startDate = $year . $month . '01';
            $endDate = $year . $month . '31';
            $noprocess = true;
        } elseif (preg_match('/(\d\d?).(\d\d?).(\d\d\d\d)/', $input, $matches) > 0) {
            $year = $matches[3];
            $month =  sprintf('%02d', $matches[2]);
            $day = sprintf('%02d', $matches[1]);
            $startDate = $year . '-' . $month . '-' .  $day . 'T00:00:00Z';
            $endDate = $year . '-' . $month . '-' .  $day . 'T23:59:59Z';
            $noprocess = true;
        } elseif (preg_match('/(\d?\d?\d\d) ?-luvun (alkupuolelta|alkupuoli|alku|alusta)/', $input, $matches) > 0) {
            $year = $matches[1];
             
            if ($year % 100 == 0) {
                // Century
                $startDate = $year;
                $endDate = $year + 29;
            } elseif ($year % 10 == 0) {
                // Decade
                $startDate = $year;
                $endDate = $year + 3;
            }
        } elseif (preg_match('/(\d?\d?\d\d) ?-(luvun|luku) (alkupuolelta|alkupuoli|alku|alusta)/', $input, $matches) > 0) {
            $year = $matches[1];
             
            if ($year % 100 == 0) {
                // Century
                $startDate = $year;
                $endDate = $year + 29;
            } elseif ($year % 10 == 0) {
                // Decade
                $startDate = $year;
                $endDate = $year + 3;
            }
        } elseif (preg_match('/(\d?\d?\d\d) ?-(luku|luvulta)/', $input, $matches) > 0) {
            $year = $matches[1];
            $startDate = $year;
             
            if ($year % 100 == 0) {
                $endDate = $year + 99;
            } elseif ($year % 10 == 0) {
                $endDate = $year + 9;
            } else {
                $endDate = $year;
            }
        } elseif (preg_match('/(\d?\d?\d\d) jälkeen/', $input, $matches) > 0) {
            $year = $matches[1];
             
            $startDate = $year;
            $endDate = $year + 9;
        } elseif (preg_match('/(\d?\d?\d\d) ?\?/', $input, $matches) > 0) {
            $year = $matches[1];
             
            $startDate = $year-3;
            $endDate = $year+3;
        } elseif (preg_match('/(\d?\d?\d\d)/', $input, $matches) > 0) {
            $year = $matches[1];
             
            $startDate = $year;
            $endDate = $year;
        } else {
            return null;
        }
         
        if (strlen($startDate) == 2) {
            $startDate = 1900 + $startDate;
        }
        if (strlen($endDate) == 2) {
            $endDate = 1900 + $endDate;
        }
         
        if (empty($noprocess)) {
            $startDate = $startDate . '-01-01T00:00:00Z';
            $endDate = $endDate . '-12-31T23:59:59Z';
        }
    
        // Trying to index dates into the future? I don't think so...
        $yearNow = date('Y');
        if ($startDate > $yearNow || $endDate > $yearNow) {
            return null;
        }
    
        $this->earliestYear = $startDate;
        $this->latestYear = $startDate;
    
        if (!MetadataUtils::validateISO8601Date($startDate) || !MetadataUtils::validateISO8601Date($endDate)) {
            return null;
        }
         
        return $startDate . $delimiter . $endDate;
    }
}

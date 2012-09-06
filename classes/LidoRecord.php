<?php
/**
 * LidoRecord Class
 *
 * PHP version 5
 *
 * Copyright (C) Ere Maijala 2011-2012
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

require_once 'BaseRecord.php';

/**
 * LidoRecord Class
 *
 * This is a class for processing LIDO records.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class LidoRecord extends BaseRecord
{
    protected $doc = null;
    
    protected $earliestYear;
    protected $latestYear;

    /**
     * Constructor
     *
     * @param string $data  Record metadata
     * @param string $oaiID Record ID in OAI-PMH
     * 
     * @access public
     */
    public function __construct($data, $oaiID)
    {
        $this->doc = simplexml_load_string($data);
    }
    
    /**
     * Return record ID (local)
     *
     * @return string
     * @access public
     */
    public function getID()
    {
        return $this->doc->lido->lidoRecID;
    }

    /**
     * Serialize the record for storing in the database
     *
     * @return string
     * @access public
     */
    public function serialize()
    {
        return MetadataUtils::trimXMLWhitespace($this->doc->asXML());
    }

    /**
     * Serialize the record into XML for export
     *
     * @return string
     * @access public
     */
    public function toXML()
    {
        return $this->doc->asXML();
    }

    /**
     * Set the ID prefix into all the ID fields (ID, host ID etc.)
     *
     * @param string $prefix (e.g. "source.")
     * 
     * @return void
     * @access public
     */
    public function setIDPrefix($prefix)
    {
        $this->doc->lido->lidoRecID = $prefix . $this->doc->lido->lidoRecID;
    }

    /**
     * Return fields to be indexed in Solr
     *
     * @return string[]
     * @access public
     */
    public function toSolrArray()
    {
        $data = array();
        $doc = $this->doc;
        $lang = $this->getDefaultLanguage();
       
        $data['title'] = $this->getTitle($lang);
        if ($lang != 'en') {
            $data['title_alt'] = $this->getTitle('en');
        }
        $data['description'] = $this->getDescription();
        
        $data['format'] = $this->getObjectWorkType();
        
        $data['institution'] = $this->getLegalBodyName();
        
        // Don't confuse the system if we didn't find a match and don't want to override
        if (empty($data['institution'])) {
            unset($data['institution']);
        }
        
        // TODO: this is not the only kind of actor in LIDO, is this what's wanted here?
        $data['author'] = $this->getActor('valmistus');
        
        $subjects = $this->getSubjects();
        if (empty($subjects)) {
            $subjects = array();
        }
        $classifications = $this->getClassifications();
        if (empty($classifications)) {
            $classifications = array();
        }
        
        // TODO: should the classifications reside in their own field instead of in the topic field?
        $data['topic'] = array_merge($subjects, $classifications);
        $data['topic_facet'] = $data['topic'];
        
        $data['material'] = $this->getMaterials();
        
        // This is just the display measurements! There's also the more granular form, 
        // which could be useful for some interesting things eg. sorting by size 
        $data['measurements'] = $this->getMeasurements();
        $data['identifier'] = $this->getIdentifier();
        $data['culture'] = $this->getCulture();
        $data['rights'] = $this->getRights();
        $data['unit_daterange'] = $this->getDateRange('valmistus');
        $data['era_facet'] = $this->getDisplayDate('valmistus');
        
        if (!empty($this->earliestYear) && !empty($this->latestYear)) {
            // For demo purposes only... uniform distribution
            $data['publishDate'] = rand(intval($this->earliestYear), intval($this->latestYear));
        }
        
        $data['collection'] = $this->getCollection();
        
        $urls = $this->getUrls();
        if (!empty($urls)) {
            $data['thumbnail'] = $urls[0];
        }
        
        $data['allfields'] = $this->getAllFields($data);
        
        return $data;
    }
    
    /**
     * Dedup: Return record title
     *
     * @param bool   $forFiling Whether the title is to be used in filing (e.g. sorting, non-filing characters should be removed)
     * @param string $lang      Language
     * 
     * @return string
     */
    public function getTitle($forFiling = false, $lang = null)
    {
        if ($lang != null) {
            $titles = $this->extractArray("lido/descriptiveMetadata/objectIdentificationWrap/titleWrap/titleSet/appellationValue[@lang='$lang']");
        }
        // Fallback to use any title in case none found with the specified language (the language info just might not be there)
        if (empty($titles)) {
            $titles = $this->extractArray('lido/descriptiveMetadata/objectIdentificationWrap/titleWrap/titleSet/appellationValue');
        }
        
        $num = count($titles);
        if (empty($num)) {
            return null;
        } elseif ($num == 1) {
            return $titles[0];
        } else {
            return implode(': ', $titles);
        }
    }
    
    /**
     * Return the object measurements. Only the display element is used currently 
     * until processing more granular data is needed. 
     *
     * @link http://www.lido-schema.org/schema/v1.0/lido-v1.0-schema-listing.html#objectMeasurementsSetComplexType
     * @return string
     */
    protected function getMeasurements()
    {
        return $this->extractArray('lido/descriptiveMetadata/objectIdentificationWrap/objectMeasurementsWrap/objectMeasurementsSet/displayObjectMeasurements');
    }
    
    /**
     * Return the object identifier. This is "an unambiguous numeric or alphanumeric 
     * identification number, assigned to the object by the institution of custody."
     * (usually differs from a technical database id)
     * 
     * @link http://www.lido-schema.org/schema/v1.0/lido-v1.0-schema-listing.html#repositorySetComplexType
     * @return string
     */
    protected function getIdentifier()
    {
        return $this->extractFirst('lido/descriptiveMetadata/objectIdentificationWrap/repositoryWrap/repositorySet/workID');
    }

    /**
     * Return the legal body name.
     *
     * @link http://www.lido-schema.org/schema/v1.0/lido-v1.0-schema-listing.html#legalBodyRefComplexType
     * @return string
     */
    protected function getLegalBodyName() 
    {
        return $this->extractFirst('lido/descriptiveMetadata/objectIdentificationWrap/repositoryWrap/repositorySet/repositoryName/legalBodyName/appellationValue');
    }
    
    /**
     * Return the rights holder legal body name.
     *
     * @link http://www.lido-schema.org/schema/v1.0/lido-v1.0-schema-listing.html#legalBodyRefComplexType
     * @return string
     */
    protected function getRightsHolderLegalBodyName() 
    {
        return $this->extractFirst('lido/administrativeMetadata/rightsWorkWrap/rightsWorkSet/rightsHolder/legalBodyName/appellationValue');
    }
    
    /**
     * Return the object description.
     *
     * @link http://www.lido-schema.org/schema/v1.0/lido-v1.0-schema-listing.html#descriptiveNoteComplexType
     * @return string
     */
    protected function getDescription()
    {
        $description = $this->extractFirst('lido/descriptiveMetadata/objectIdentificationWrap/objectDescriptionWrap/objectDescriptionSet/descriptiveNoteValue');
        
        if (!empty($description)) {
            return $description;
        }
        
        // REMOVE THIS ONCE TUUSULA IS FIXED
        
        // Quick and dirty way to get description when it's in the subject wrap (Tuusula)
        return $this->extractFirst("lido/descriptiveMetadata/objectRelationWrap/subjectWrap/subjectSet/displaySubject[@label='aihe']");

        // END OF TUUSULA FIX
    }
    
    /**
     * Return all the cultures associated with an object.
     *
     * @link http://www.lido-schema.org/schema/v1.0/lido-v1.0-schema-listing.html#eventComplexType
     * @return string[]
     */
    protected function getCulture()
    {
        return $this->extractArray('lido/descriptiveMetadata/eventWrap/eventSet/event/culture/term');
    }
    
    /**
     * Return the object type.
     *
     * @link http://www.lido-schema.org/schema/v1.0/lido-v1.0-schema-listing.html#objectWorkTypeWrap
     * @return string
     */
    protected function getObjectWorkType()
    {
        return $this->extractFirst('lido/descriptiveMetadata/objectClassificationWrap/objectWorkTypeWrap/objectWorkType/term');
    }
    
    /**
     * Return the classification of the specified type or the first classification if none specified.
     *
     * @param string $type Classification
     * 
     * @link http://www.lido-schema.org/schema/v1.0/lido-v1.0-schema-listing.html#objectClassificationWrap
     * @return string
     */
    protected function getClassification($type = null)
    {
        if ($type != null) {
            return $this->extractFirst("lido/descriptiveMetadata/objectClassificationWrap/classificationWrap/classification[@type='$type']/term");
        }
        return $this->extractFirst('lido/descriptiveMetadata/objectClassificationWrap/classificationWrap/classification/term');
    }
    
    /**
     * Return the classifications
     *
     * @link http://www.lido-schema.org/schema/v1.0/lido-v1.0-schema-listing.html#objectClassificationWrap
     * @return string
     */
    protected function getClassifications()
    {
        return $this->extractArray('lido/descriptiveMetadata/objectClassificationWrap/classificationWrap/classification/term');
    }
    
    /**
     * Return the term part of the category
     *
     * @link http://www.lido-schema.org/schema/v1.0/lido-v1.0-schema-listing.html#objectClassificationWrap
     * @return string
     * @access public
     */
    protected function getCategoryTerm()
    {
        return $this->extractFirst('lido/category/term');
    }
    
    /**
     * Return URLs associated with object
     *
     * @return string[]
     */
    protected function getURLs()
    {
        return $this->extractArray('lido/administrativeMetadata/resourceWrap/resourceSet/resourceRepresentation/linkResource');
    }
    
    /**
     * Return name of first actor associated with specified event
     *
     * @param string $event Which event to use (omit to scan all events)
     * 
     * @return string
     */
    protected function getActor($event = null)
    {
        $xpath = 'lido/descriptiveMetadata/eventWrap/eventSet/event';
        if (!empty($event)) {
            $xpath .= "[eventType/term='$event']";
        }
        $xpath .= '/eventActor/actorInRole/actor/nameActorSet/appellationValue';
        
        return $this->extractFirst($xpath);
    }
    
    /**
     * Return the date range associated with specified event
     *
     * @param string $event     Which event to use (omit to scan all events)
     * @param string $delimiter Delimiter between the dates
     * 
     * @return string
     */
    protected function getDateRange($event = null, $delimiter = ',')
    {
        $xpath = 'lido/descriptiveMetadata/eventWrap/eventSet/event';
        if (!empty($event)) {
            $xpath .= "[eventType/term='$event']";
        }
         
        $date = $this->extractFirst($xpath . '/eventDate/displayDate');
        if (empty($date)) {
            $date = $this->extractFirst($xpath . '/periodName/term');
        }    
        
        return $this->parseDateRange($date);
    }
    
    /**
     * Return the date range associated with specified event
     *
     * @param string $event     Which event to use (omit to scan all events)
     * @param string $delimiter Delimiter between the dates
     *
     * @return string
     */
    protected function getPeriod($event = null, $delimiter = ',')
    {
        $xpath = 'lido/descriptiveMetadata/eventWrap/eventSet/event';
        if (!empty($event)) {
            $xpath .= "[eventType/term='$event']";
        }
    
        $period = $this->extractFirst($xpath . '/periodName/term');
        if (!empty($period)) {
            return $period;
        }
        return null;
    }
    
    /**
     * Return the place associated with specified event
     *
     * @param string $event Which event to use (omit to scan all events)
     * 
     * @return string
     */
    protected function getDisplayPlace($event = null)
    {
        $xpath = 'lido/descriptiveMetadata/eventWrap/eventSet/event';
        if (!empty($event)) {
            $xpath .= "[eventType/term='$event']";
        }
    
        $displayPlace = $this->extractFirst($xpath . '/eventPlace/displayPlace');
        if (!empty($displayPlace)) {
            return $displayPlace;
        }
        return null;
    }
    
    /**
     * Return the date range associated with specified event
     *
     * @param string $event     Which event to use (omit to scan all events)
     * @param string $delimiter Delimiter between the dates
     * 
     * @return string
     */
    protected function getDisplayDate($event = null, $delimiter = ',')
    {
        $xpath = 'lido/descriptiveMetadata/eventWrap/eventSet/event';
        if (!empty($event)) {
            $xpath .= "[eventType/term='$event']";
        }
    
        $date = $this->extractFirst($xpath . '/eventDate/displayDate');
        if (!empty($date)) {
            return $date;
        }
        return null;
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
    
    /**
     * Return the collection of the object.
     *
     * @return string
     * @access public
     */
    protected function getCollection() 
    {
        return $this->extractFirst(
            'lido/descriptiveMetadata/objectRelationWrap/'
            . "relatedWorksWrap/relatedWorkSet[relatedWorkRelType/term='Kokoelma' or relatedWorkRelType/term='kuuluu kokoelmaan' or relatedWorkRelType/term='kokoelma']/"
            . 'relatedWork/displayObject'
        );
    }
    
    /**
     * Return the rights of the object.
     *
     * @link http://www.lido-schema.org/schema/v1.0/lido-v1.0-schema-listing.html#rightsComplexType
     * @return string
     */
    protected function getRights() 
    {
        return $this->extractFirst(
            'lido/administrativeMetadata/resourceWrap/'
            . 'resourceSet/rightsResource/rightsHolder/'
            . 'legalBodyName/appellationValue'
        );
    }
    
    /**
     * Return the languages used in the metadata (from 'lang' attributes used in descriptiveMetadata elements)
     *
     * @return string[]
     */
    protected function getLanguage() 
    {
        $wraps = $this->doc->xpath('lido/descriptiveMetadata');
        if (!count($wraps)) {
            return null;
        }
        
        $languages = array();
        $att = 'lang';
        foreach ($wraps as $wrap) {
            $language = (string)$wrap->attributes()->$att;
            if ($language) {
                $languages[] = $language;
            }
        }
        return $languages;    
    }
    
    /**
     * Return subjects associated with object.
     *
     * @link http://www.lido-schema.org/schema/v1.0/lido-v1.0-schema-listing.html#subjectComplexType
     * @return string
     * @access public
     */
    protected function getSubjects()
    {
        $xpath = 'lido/descriptiveMetadata/objectRelationWrap/subjectWrap/subjectSet/subject'
        // REMOVE THIS ONCE TUUSULA IS FIXED
        // In the term fields there are Iconclass identifiers, which are unfit for human consumption
        // Also the description of the object is in the subject wrap. It's kind of debated whether
        // it should be here or in the description so can't blame Muusa for that. Anyway cutting it out.
        . "[not(@type) or (@type != 'iconclass' and @type != 'aihe')]"
        // END OF TUUSULA FIX
        . '/subjectConcept/term';
    
        return $this->extractArray($xpath);
    }
    
    /**
     * Return materials associated with the object. Materials are contained inside events, and the
     * 'valmistus' (creation) event contains all the materials of the object.
     * Either the individual materials are retrieved, or the display materials element is
     * retrieved in case of failure.
     *
     * @link http://www.lido-schema.org/schema/v1.0/lido-v1.0-schema-listing.html#materialsTechSetComplexType
     * @return string[]
     * @access public
     */
    protected function getMaterials()
    {
        // First try out if the materials are individually listed
        $xpath = 'lido/descriptiveMetadata/eventWrap/'
        . "eventSet/event[eventType/term='valmistus']/"
        . 'eventMaterialsTech/materialsTech/termMaterialsTech/term';
    
        $materials = $this->extractArray($xpath);
    
        if (!empty($materials)) {
            return $materials;
        }
    
        // Next, try the displayMaterialsTech element
        $xpath = 'lido/descriptiveMetadata/eventWrap/'
        . "eventSet/event[eventType/term='valmistus']/"
        . 'eventMaterialsTech/displayMaterialsTech';
    
        return $this->extractFirst($xpath);
    }
    
    /**
     * Utility method that returns an array of strings matching given XPath selector.
     *
     * @param string $xpath XPath expression
     * 
     * @return string[]
     * @access public
     */
    protected function extractArray($xpath)
    {
        $elements = $this->doc->xpath($xpath);
        if (!$elements || !count($elements)) {
            return null;
        }
    
        $results = array();
        foreach ($elements as $element) {
            if (!empty($element)) {
                $results[] = (string)$element;
            }
        }
        return $results;
    }
    
    /**
     * Utility method that returns the first string matching given XPath selector.
     *
     * @param string $xpath XPath expression
     * 
     * @return string
     * @access public
     */
    protected function extractFirst($xpath)
    {
        $elements = $this->doc->xpath($xpath);
        if (!$elements || !count($elements) || empty($elements[0])) {
            return null;
        }
         
        return (string)$elements[0];
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
        $fields = array(
            'title', 'description', 'format', 'author', 'topic', 
            'material', 'measurements', 'identifier', 'culture'
        );
        $allfields = array();
        foreach ($fields as $key) {
            if (isset($data[$key]) && !empty($data[$key])) {
                if (is_array($data[$key])) {
                    $allfields[] = implode(' ', MetadataUtils::array_iunique($data[$key]));
                } else {
                    $allfields[] = $data[$key];
                }
            }
        }       
        return $allfields;
    }
    
    /**
     * Get the default language used when building the Solr array
     * 
     * @return string
     */
    protected function getDefaultLanguage()
    {
        return 'en';
    }
}    


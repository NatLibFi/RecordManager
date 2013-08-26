<?php
/**
 * LidoRecord Class
 *
 * PHP version 5
 *
 * Copyright (C) The National Library of Finland 2011-2013
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

    // These are types reflecting the terminology in the particular LIDO records, 
    // and can be overridden in a subclass.
    protected $mainEvent = 'creation';
    protected $usagePlaceEvent = 'usage';
    protected $relatedWorkRelationTypes = array('Collection', 'belongs to collection', 'collection');
    
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
       
        $data['title'] = $data['title_short'] = $data['title_full'] = $this->getTitle(false, $lang);
        $allTitles = $this->getTitle(false);
        foreach (explode('; ', $allTitles) as $title) {
            if ($title != $data['title']) {
                $data['title_alt'][] = $title;
            }
        }
        $data['title_sort'] = $this->getTitle(true, $lang);
        $data['description'] = $this->getDescription();
        
        $data['format'] = $this->getObjectWorkType();
        
        $data['institution'] = $this->getLegalBodyName();
        
        $data['author'] = $this->getActor($this->mainEvent);
        $data['author-letter'] = $data['author'];
        
        $data['topic'] = $data['topic_facet'] = $this->getSubjectTerms();
        $data['material'] = $this->getEventMaterials($this->mainEvent);
        
        // This is just the display measurements! There's also the more granular form, 
        // which could be useful for some interesting things eg. sorting by size 
        $data['measurements'] = $this->getMeasurements();
        
        $data['identifier'] = $this->getIdentifier();
        $data['culture'] = $this->getCulture();
        $data['rights'] = $this->getRights();

        $data['unit_daterange'] = $this->getDateRange($this->mainEvent);
        $data['era_facet'] = $this->getDisplayDate($this->mainEvent);
        $data['geographic_facet'][] = $this->getDisplayPlace($this->usagePlaceEvent);
        $data['collection'] = $this->getRelatedWorkDisplayObject($this->relatedWorkRelationTypes);
        
        $urls = $this->getUrls();
        if (count($urls))
            // thumbnail field is not multivalued so can only store first image url
            $data['thumbnail'] = $urls[0];
                
        $data['allfields'] = $this->getAllFields($data);
          
        return $data;
    }
    
    /**
     * Return record title
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
        // Fallback to use any title in case none found with the specified language (or no language specified)
        if (empty($titles)) {
            $titles = $this->extractArray('lido/descriptiveMetadata/objectIdentificationWrap/titleWrap/titleSet/appellationValue');
        }
        
        if (empty($titles)) {
            return null;
        } 
        $title = implode('; ', array_unique($titles));
        
        // Use description if title is the same as the work type
        // From LIDO specs:
        // "For objects from natural, technical, cultural history e.g. the object
        // name given here and the object type, recorded in the object / work
        // type element are often identical."
        if (strcasecmp($this->getObjectWorkType(), $title) == 0) {
            $descriptionWrapDescriptions = $this->extractArray("lido/descriptiveMetadata/objectIdentificationWrap/objectDescriptionWrap/objectDescriptionSet[not(@type) or (@type!='provenienssi')]/descriptiveNoteValue");
            if ($descriptionWrapDescriptions) {
                $title = implode('; ', $descriptionWrapDescriptions);
            }
        }
        
        if ($forFiling) {
            $title = MetadataUtils::stripLeadingPunctuation($title);
        }
        return $title;
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
        $description = $this->extractArray('lido/descriptiveMetadata/objectIdentificationWrap/objectDescriptionWrap/objectDescriptionSet/descriptiveNoteValue');
        
        if (empty($description)) {
            return null;
        }
        
        if ($this->getTitle() == implode('; ', $description)) {
            // We have the description already in the title, don't repeat
            return null;
        }
        
        return implode(' ', $description);
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
     */
    protected function getCategoryTerm()
    {
        return $this->extractFirst('lido/category/term');
    }

    /**
     * Return the organization name in the recordSource element
     *
     * @return array
     */
    protected function getRecordSourceOrganization()
    {
        return $this->extractFirst('lido/administrativeMetadata/recordWrap/recordSource/legalBodyName/appellationValue');
    }
    
    /**
     * Return all the names for the specified event type
     * 
     * @param string $eventType Event type
     *
     * @return array
     */
    protected function getEventNames($eventType) 
    {
        return $this->extractArray("lido/descriptiveMetadata/eventWrap/eventSet/event[eventType/term='$eventType']/eventName/appellationValue");
    }
    
    /**
     * Return the name(s) of events with specified type
     *
     * @param string $event     Which event to use (omit to scan all events)
     * @param string $delimiter Delimiter between the dates
     * 
     * @return string
     */
    protected function getEventName($event = null, $delimiter = ',')
    {
        $xpath = 'lido/descriptiveMetadata/eventWrap/eventSet/event';
        if (!empty($event)) {
            $xpath .= "[eventType/term='$event']";
        }
    
        $name = $this->extractFirst($xpath . '/eventName/appellationValue');
        if (!empty($name)) {
            return $name;
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
    protected function getEventMethod($event = null, $delimiter = ',')
    {
        $xpath = 'lido/descriptiveMetadata/eventWrap/eventSet/event';
        if (!empty($event)) {
            $xpath .= "[eventType/term='$event']";
        }
    
        $date = $this->extractFirst($xpath . '/eventMethod/term');
        if (!empty($date)) {
            return $date;
        }
        return null;
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
     * @param string|string[] $event Which events to use (omit to scan all events)
     * @param string|string[] $role  Which roles to use (omit to scan all roles)
     * 
     * @return string
     */
    protected function getActor($event = null, $role = null)
    {
        $xpath = 'lido/descriptiveMetadata/eventWrap/eventSet/event';
        
        if (!empty($event)) {
            if (!is_array($event)) {
                $event = array($event);
            }
            $xpath .= '[';
            foreach ($event as $i => $thisEvent) {
                if ($i) {
                    $xpath .= ' or ';
                }
                $xpath .= "eventType/term='$thisEvent'";
            }
            $xpath .= ']';
        }
        
        $xpath .= '/eventActor/actorInRole';
        
        if (!empty($role)) {
            if (!is_array($role)) {
                $role = array($role);
            }
            $xpath .= '[';
            foreach ($role as $i => $thisRole) {
                if ($i) {
                    $xpath .= ' or ';
                }
                $xpath .= "roleActor/term='$thisRole'";
            }
            $xpath .= ']';
        }
        
        $xpath .= '/actor/nameActorSet/appellationValue';
        
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
     * Return the collection of the object.
     * 
     * @param array $relatedWorkRelType Which relation types to use
     *
     * @return string
     */
    protected function getRelatedWorkDisplayObject($relatedWorkRelType) 
    {
        $filter = '';
        if (is_array($relatedWorkRelType)) {
            foreach ($relatedWorkRelType as $i => $item) {
                if ($i > 0) {
                    $filter .= ' or ';
                }
                $filter .= "relatedWorkRelType/term='$item'";
            }
        } else {
            $filter = "relatedWorkRelType/term='$relatedWorkRelType'";
        }
        
        $xpath = 'lido/descriptiveMetadata/objectRelationWrap/'
            . 'relatedWorksWrap/relatedWorkSet'
            . (empty($filter)? '': "[$filter]")
            . '/relatedWork/displayObject';
                                
        return $this->extractFirst($xpath);
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
     * @param string[] $exclude List of subject types to exclude
     *
     * @link http://www.lido-schema.org/schema/v1.0/lido-v1.0-schema-listing.html#subjectComplexType
     * @return string
     */
    protected function getSubjectTerms($exclude)
    {
        $filter = $this->buildAttributeFilter('type', $exclude);
        
        // get list of subjects without filter
        $xpath = 'lido/descriptiveMetadata/objectRelationWrap/subjectWrap/subjectSet/subject'
            . $filter
            . '/subjectConcept/term';

        return $this->extractArray($xpath);
    }
    
    /**
     * Return materials associated with a specified event type. Materials are contained inside events.
     * The individual materials are retrieved.
     *
     * @param string $eventType Which event to use
     *
     * @link http://www.lido-schema.org/schema/v1.0/lido-v1.0-schema-listing.html#materialsTechSetComplexType
     * @return string[]
     */
    protected function getEventMaterials($eventType)
    {
        $xpath = 'lido/descriptiveMetadata/eventWrap/'
            . "eventSet/event[eventType/term='$eventType']/"
            . 'eventMaterialsTech/materialsTech/termMaterialsTech/term';
    
        return $this->extractArray($xpath);
    }
    
    /**
     * Utility method that returns an array of strings matching given XPath selector.
     *
     * @param string $xpath XPath expression
     * 
     * @return string[]
     */
    protected function extractArray($xpath)
    {
        $elements = $this->doc->xpath($xpath);
        if (!$elements) {
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
     */
    protected function extractFirst($xpath)
    {
        $elements = $this->doc->xpath($xpath);
        if (!$elements || empty($elements[0])) {
            return null;
        }
         
        return (string)$elements[0];
    }
    
    /**
     * Helper function, builds XPath filter for excluding attribute values
     *
     * @param string   $attribute Attribute name
     * @param string[] $terms     Array of filter terms
     *
     * @return string
     */
    protected function buildAttributeFilter($attribute, $terms) 
    {
        if (!is_array($terms)) {
            $terms = array($terms);
        }
        if (empty($terms)) {
            return '';
        }
        $filter = '[not (@type) or (';
        foreach ($terms as $i => $term) {
            if ($i > 0) {
                $filter .= ' and ';
            }
            $filter .= "@$attribute!='$term'";
        }
        return $filter . ')]';
    }

    /**
     * Get allfields contents for Solr from the Solr data array
     * 
     * @param string[] $data   Solr data array
     * @param string[] $fields Fields to include
     * 
     * @return string
     */
    protected function getAllFields($data, $fields = array(
            'title', 'title_alt', 'description', 'format', 'author', 'topic', 
            'material', 'measurements', 'identifier', 'culture')
    ) {
        $allfields = array();
        foreach ($fields as $key) {
            if (isset($data[$key]) && !empty($data[$key])) {
                if (is_array($data[$key])) {
                    $allfields[] = implode(' ', array_unique($data[$key]));
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
    
    /**
     * Attempt to parse a string (in finnish) into a normalized date range.
     * TODO: complicated normalization like this should preferably reside within its own, separate component
     * which should allow modification of the algorithm by methods other than hard-coding rules into source.
     *
     * @param string $input Date range
     *
     * @return string Two ISO 8601 dates separated with the supplied delimiter on success, and null on failure.
     */
    protected function parseDateRange($input)
    {
        $input = trim(strtolower($input));
         
        if (preg_match('/(\d\d\d\d) ?- (\d\d\d\d)/', $input, $matches) > 0) {
            $startDate = $matches[1];
            $endDate = $matches[2];
        } elseif (preg_match('/(\d\d?).(\d\d?).(\d\d\d\d)/', $input, $matches) > 0) {
            $year = $matches[3];
            $month =  sprintf('%02d', $matches[2]);
            $day = sprintf('%02d', $matches[1]);
            $startDate = $year . '-' . $month . '-' .  $day . 'T00:00:00Z';
            $endDate = $year . '-' . $month . '-' .  $day . 'T23:59:59Z';
            $noprocess = true;
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
         
        return "$startDate,$endDate";
    }
}    

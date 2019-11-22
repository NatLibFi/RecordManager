<?php
/**
 * Lido record class
 *
 * PHP version 5
 *
 * Copyright (C) The National Library of Finland 2011-2017.
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
namespace RecordManager\Base\Record;

use RecordManager\Base\Utils\MetadataUtils;

/**
 * Lido record class
 *
 * This is a class for processing LIDO records.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class Lido extends Base
{
    protected $doc = null;

    /**
     * Main event name reflecting the terminology in the particular LIDO records.
     *
     * @var string
     */
    protected $mainEvent = 'creation';

    /**
     * Usage place event name reflecting the terminology in the particular LIDO
     * records.
     *
     * @var string
     */
    protected $usagePlaceEvent = 'usage';

    /**
     * Related work relation types reflecting the terminology in the particular LIDO
     * records.
     *
     * @var string
     */
    protected $relatedWorkRelationTypes = [
        'Collection', 'belongs to collection', 'collection'
    ];

    /**
     * Set record data
     *
     * @param string $source Source ID
     * @param string $oaiID  Record ID received from OAI-PMH (or empty string for
     *                       file import)
     * @param string $data   Metadata
     *
     * @return void
     */
    public function setData($source, $oaiID, $data)
    {
        parent::setData($source, $oaiID, $data);

        $this->doc = $this->parseXMLRecord($data);
    }

    /**
     * Return record ID (local)
     *
     * @return string
     */
    public function getID()
    {
        return (string)$this->doc->lido->lidoRecID;
    }

    /**
     * Serialize the record for storing in the database
     *
     * @return string
     */
    public function serialize()
    {
        return MetadataUtils::trimXMLWhitespace($this->doc->asXML());
    }

    /**
     * Serialize the record into XML for export
     *
     * @return string
     */
    public function toXML()
    {
        return $this->doc->asXML();
    }

    /**
     * Return fields to be indexed in Solr
     *
     * @return array
     */
    public function toSolrArray()
    {
        $data = [];

        $data['record_format'] = $data['recordtype'] = 'lido';
        $lang = $this->getDefaultLanguage();
        $title = $this->getTitle(false, $lang);
        if ($this->getDriverParam('splitTitles', false)) {
            $titlePart = MetadataUtils::splitTitle($title);
            if ($titlePart) {
                $data['description'] = $title;
                $title = $titlePart;
            }
        }
        $data['title'] = $data['title_short'] = $data['title_full'] = $title;
        $allTitles = $this->getTitle(false);
        foreach (explode('; ', $allTitles) as $title) {
            if ($title != $data['title']) {
                $data['title_alt'][] = $title;
            }
        }
        $data['title_sort'] = $this->getTitle(true, $lang);
        $description = $this->getDescription();
        if ($description) {
            if (!empty($data['description'])
                && strncmp(
                    $data['description'], $description, strlen($data['description'])
                )
            ) {
                $data['description'] .= " -- $description";
            } else {
                $data['description'] = $description;
            }
        }

        $data['format'] = $this->getObjectWorkType();

        $data['institution'] = $this->getLegalBodyName();

        $data['author'] = $this->getActors($this->mainEvent);
        if (!empty($data['author'])) {
            $data['author_sort'] = $data['author'][0];
        }

        $data['topic'] = $data['topic_facet'] = $this->getSubjectTerms();
        $data['material'] = $this->getEventMaterials($this->mainEvent);

        // This is just the display measurements! There's also the more granular
        // form, which could be useful for some interesting things eg. sorting by
        // size
        $data['measurements'] = $this->getMeasurements();

        $data['identifier'] = $this->getIdentifier();
        $data['culture'] = $this->getCulture();
        $data['rights'] = $this->getRights();

        $data['era'] = $data['era_facet']
            = $this->getEventDisplayDate($this->mainEvent);
        $data['geographic_facet'] = [];
        $eventPlace = $this->getEventDisplayPlace($this->usagePlaceEvent);
        if ($eventPlace) {
            $data['geographic_facet'][] = $eventPlace;
        }
        $data['geographic_facet'] = array_merge(
            $data['geographic_facet'], $this->getSubjectDisplayPlaces()
        );
        $data['geographic'] = $data['geographic_facet'];
        // Index the other place forms only to facets
        $data['geographic_facet'] = array_merge(
            $data['geographic_facet'], $this->getSubjectPlaces()
        );
        $data['collection']
            = $this->getRelatedWorkDisplayObject($this->relatedWorkRelationTypes);

        $urls = $this->getURLs();
        if (count($urls)) {
            // thumbnail field is not multivalued so can only store first image url
            $data['thumbnail'] = $urls[0];
        }

        $data['allfields'] = $this->getAllFields($this->doc);

        return $data;
    }

    /**
     * Return record title
     *
     * @param bool     $forFiling            Whether the title is to be used in
     *                                       filing (e.g. sorting, non-filing
     *                                       characters should be removed)
     * @param string   $lang                 Language
     * @param string[] $excludedDescriptions Description types to exclude
     *
     * @return string
     */
    public function getTitle($forFiling = false, $lang = null,
        $excludedDescriptions = ['provenance']
    ) {
        $titles = [];
        $allTitles = [];
        foreach ($this->getTitleSetNodes() as $set) {
            foreach ($set->appellationValue as $appellationValue) {
                if ($lang == null || $appellationValue['lang'] == $lang) {
                    $titles[] = (string) $appellationValue;
                }
                $allTitles[] = (string) $appellationValue;
            }
        }
        // Fallback to use any title in case none found with the specified language
        if (empty($titles)) {
            $titles = $allTitles;
        }
        if (empty($titles)) {
            return null;
        }
        $title = implode('; ', array_unique(array_filter($titles)));

        // Use description if title is the same as the work type
        // From LIDO specs:
        // "For objects from natural, technical, cultural history e.g. the object
        // name given here and the object type, recorded in the object / work
        // type element are often identical."
        $workType = $this->getObjectWorkType();
        if (is_array($workType)) {
            $workType = $workType[0];
        }
        if (strcasecmp($workType, $title) == 0) {
            $descriptionWrapDescriptions = [];
            foreach ($this->getObjectDescriptionSetNodes($excludedDescriptions)
                as $set
            ) {
                if ($set->descriptiveNoteValue) {
                    $descriptionWrapDescriptions[]
                        = (string)$set->descriptiveNoteValue;
                }
            }
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
     * Get locations for geocoding
     *
     * Returns an associative array of primary and secondary locations
     *
     * @return array
     */
    public function getLocations()
    {
        $locations = [];
        foreach ([$this->mainEvent, $this->usagePlaceEvent] as $event) {
            foreach ($this->getEventNodes($event) as $eventNode) {
                // If there is already gml in the record, don't return anything for
                // geocoding
                if (!empty($eventNode->eventPlace->gml)) {
                    return [];
                }
                $hasValue = !empty(
                    $eventNode->eventPlace->place->namePlaceSet->appellationValue
                );
                if ($hasValue) {
                    $mainPlace = (string)$eventNode->eventPlace->place->namePlaceSet
                        ->appellationValue;
                    $subLocation = $this->getSubLocation(
                        $eventNode->eventPlace->place
                    );
                    if ($mainPlace && !$subLocation) {
                        $locations = array_merge(
                            $locations,
                            explode('/', $mainPlace)
                        );
                    } else {
                        $locations[] = "$mainPlace $subLocation";
                    }
                } elseif (!empty($eventNode->eventPlace->displayPlace)) {
                    // Split multiple locations separated with a slash
                    $locations = array_merge(
                        $locations,
                        preg_split(
                            '/[\/;]/', (string)$eventNode->eventPlace->displayPlace
                        )
                    );
                }
            }
        }
        return [
            'primary' => $locations,
            'secondary' => []
        ];
    }

    /**
     * Get the last sublocation (partOfPlace) of a place
     *
     * @param simpleXMLElement $place Place element
     * @param bool             $isSub Is the current $place a sublocation
     *
     * @return string
     */
    protected function getSubLocation($place, $isSub = false)
    {
        if (!empty($place->partOfPlace)) {
            $result = $this->getSubLocation($place->partOfPlace, true);
            if (!empty($result)) {
                return $result;
            }
        }
        return $isSub && isset($place->namePlaceSet->appellationValue)
            ? (string)$place->namePlaceSet->appellationValue : '';
    }

    /**
     * Return the object measurements. Only the display element is used currently
     * until processing more granular data is needed.
     *
     * @link   http://www.lido-schema.org/schema/v1.0/lido-v1.0-schema-listing.html
     * #objectMeasurementsSetComplexType
     * @return string
     */
    protected function getMeasurements()
    {
        $nodeExists = !empty(
            $this->doc->lido->descriptiveMetadata->objectIdentificationWrap
                ->objectMeasurementsWrap->objectMeasurementsSet
        );
        if (!$nodeExists) {
            return '';
        }
        $results = [];
        foreach ($this->doc->lido->descriptiveMetadata->objectIdentificationWrap
            ->objectMeasurementsWrap->objectMeasurementsSet as $set
        ) {
            foreach ($set->displayObjectMeasurements as $measurements
            ) {
                $value = trim((string) $measurements);
                if ($value) {
                    $results[] = $value;
                }
            }
        }
        return $results;
    }

    /**
     * Return the object identifier. This is "an unambiguous numeric or alphanumeric
     * identification number, assigned to the object by the institution of custody."
     * (usually differs from a technical database id)
     *
     * @link   http://www.lido-schema.org/schema/v1.0/lido-v1.0-schema-listing.html
     * #repositorySetComplexType
     * @return string
     */
    protected function getIdentifier()
    {
        $nodeExists = !empty(
            $this->doc->lido->descriptiveMetadata->objectIdentificationWrap
                ->repositoryWrap->repositorySet
        );
        if (!$nodeExists) {
            return '';
        }
        foreach ($this->doc->lido->descriptiveMetadata->objectIdentificationWrap
            ->repositoryWrap->repositorySet as $set
        ) {
            if (!empty($set->workID)) {
                return (string) $set->workID;
            }
        }
        return '';
    }

    /**
     * Return the legal body name.
     *
     * @link   http://www.lido-schema.org/schema/v1.0/lido-v1.0-schema-listing.html
     * #legalBodyRefComplexType
     * @return string
     */
    protected function getLegalBodyName()
    {
        $empty = empty(
            $this->doc->lido->descriptiveMetadata->objectIdentificationWrap
                ->repositoryWrap->repositorySet
        );
        if (!$empty) {
            foreach ($this->doc->lido->descriptiveMetadata->objectIdentificationWrap
                ->repositoryWrap->repositorySet as $set
            ) {
                if (!empty($set->repositoryName->legalBodyName->appellationValue)) {
                    return (string)$set->repositoryName->legalBodyName
                        ->appellationValue;
                }
            }
        }

        $empty = empty(
            $this->doc->lido->administrativeMetadata->recordWrap
                ->recordSource
        );
        if ($empty) {
            return '';
        }
        foreach ($this->doc->lido->administrativeMetadata->recordWrap
            ->recordSource as $source
        ) {
            if (!empty($source->legalBodyName->appellationValue)) {
                return (string)$source->legalBodyName->appellationValue;
            }
        }

        return '';
    }

    /**
     * Return the object description.
     *
     * @link   http://www.lido-schema.org/schema/v1.0/lido-v1.0-schema-listing.html
     * #descriptiveNoteComplexType
     * @return string
     */
    protected function getDescription()
    {
        $empty = empty(
            $this->doc->lido->descriptiveMetadata->objectIdentificationWrap
                ->objectDescriptionWrap->objectDescriptionSet
        );
        if ($empty) {
            return '';
        }

        $description = [];
        foreach ($this->doc->lido->descriptiveMetadata->objectIdentificationWrap
            ->objectDescriptionWrap->objectDescriptionSet as $set
        ) {
            foreach ($set->descriptiveNoteValue as $descriptiveNoteValue) {
                $description[] = (string) $descriptiveNoteValue;
            }
        }

        if ($this->getTitle() == implode('; ', $description)) {
            // We have the description already in the title, don't repeat
            return '';
        }

        return trim(implode(' ', $description));
    }

    /**
     * Return all the cultures associated with an object.
     *
     * @link   http://www.lido-schema.org/schema/v1.0/lido-v1.0-schema-listing.html
     * #eventComplexType
     * @return array
     */
    protected function getCulture()
    {
        $results = [];
        foreach ($this->getEventNodes() as $event) {
            foreach ($event->culture as $culture) {
                if ($culture->term) {
                    $results[] = (string) $culture->term;
                }
            }
        }
        return $results;
    }

    /**
     * Return the object type.
     *
     * @link   http://www.lido-schema.org/schema/v1.0/lido-v1.0-schema-listing.html
     * #objectWorkTypeWrap
     * @return string|array
     */
    protected function getObjectWorkType()
    {
        $empty = empty(
            $this->doc->lido->descriptiveMetadata->objectClassificationWrap
                ->objectWorkTypeWrap->objectWorkType
        );
        if ($empty) {
            return '';
        }

        foreach ($this->doc->lido->descriptiveMetadata->objectClassificationWrap
            ->objectWorkTypeWrap->objectWorkType as $type
        ) {
            if (!empty($type->term)) {
                return (string) $type->term;
            }
        }
        return '';
    }

    /**
     * Return URLs associated with object
     *
     * @return array
     */
    protected function getURLs()
    {
        $results = [];
        foreach ($this->getResourceSetNodes() as $set) {
            foreach ($set->resourceRepresentation as $node) {
                if (!empty($node->linkResource)) {
                    $link = trim((string) $node->linkResource);
                    if (!empty($link)) {
                        $results[] = $link;
                    }
                }
            }
        }
        return $results;
    }

    /**
     * Return names of actors associated with specified event
     *
     * @param string|array $event Which events to use (omit to scan all events)
     * @param string|array $role  Which roles to use (omit to scan all roles)
     *
     * @return array
     */
    protected function getActors($event = null, $role = null)
    {
        $result = [];
        foreach ($this->getEventNodes($event) as $eventNode) {
            foreach ($eventNode->eventActor as $actorNode) {
                foreach ($actorNode->actorInRole as $roleNode) {
                    if (isset($roleNode->actor->nameActorSet->appellationValue)) {
                        $actorRole = MetadataUtils::normalizeRelator(
                            (string)$roleNode->roleActor->term
                        );
                        if (empty($role) || in_array($actorRole, (array)$role)) {
                            $result[] = (string)$roleNode->actor->nameActorSet
                                ->appellationValue[0];
                        }
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Return the place associated with specified event
     *
     * @param string $event Which event to use (omit to scan all events)
     *
     * @return string
     */
    protected function getEventDisplayPlace($event = null)
    {
        foreach ($this->getEventNodes($event) as $eventNode) {
            if (!empty($eventNode->eventPlace->displayPlace)) {
                return MetadataUtils::stripTrailingPunctuation(
                    (string)$eventNode->eventPlace->displayPlace, '.'
                );
            }
        }
        return '';
    }

    /**
     * Return the date range associated with specified event
     *
     * @param string $event Which event to use (omit to scan all events)
     *
     * @return string
     */
    protected function getEventDisplayDate($event = null)
    {
        foreach ($this->getEventNodes($event) as $eventNode) {
            if (!empty($eventNode->eventDate->displayDate)) {
                return (string)$eventNode->eventDate->displayDate;
            }
        }
        return '';
    }

    /**
     * Return the collection of the object.
     *
     * @param string[] $relatedWorkRelType Which relation types to use
     *
     * @return string
     */
    protected function getRelatedWorkDisplayObject($relatedWorkRelType)
    {
        foreach ($this->getRelatedWorkSetNodes($relatedWorkRelType) as $set) {
            if (!empty($set->relatedWork->displayObject)) {
                return (string) $set->relatedWork->displayObject;
            }
        }
        return '';
    }

    /**
     * Return the rights of the object.
     *
     * @link   http://www.lido-schema.org/schema/v1.0/lido-v1.0-schema-listing.html
     * #rightsComplexType
     * @return string
     */
    protected function getRights()
    {
        foreach ($this->getResourceSetNodes() as $set) {
            $empty = empty(
                $set->rightsResource->rightsHolder->legalBodyName->appellationValue
            );
            if (!$empty) {
                return (string)$set->rightsResource->rightsHolder->legalBodyName
                    ->appellationValue;
            }
        }
        return '';
    }

    /**
     * Return the languages used in the metadata (from 'lang' attributes used in
     * descriptiveMetadata elements)
     *
     * @return array
     */
    protected function getLanguage()
    {
        if (empty($this->doc->descriptiveMetadata)) {
            return [];
        }

        $results = [];
        foreach ($this->doc->descriptiveMetadata as $node) {
            if (!empty($node['lang'])) {
                $results[] = (string)$node['lang'];
            }
        }
        return $results;
    }

    /**
     * Return subjects associated with object.
     *
     * @param string[] $exclude List of subject types to exclude (defaults to
     *                          'iconclass' since it doesn't contain human readable
     *                          terms)
     *
     * @link   http://www.lido-schema.org/schema/v1.0/lido-v1.0-schema-listing.html
     * #subjectComplexType
     * @return array
     */
    protected function getSubjectTerms($exclude = ['iconclass'])
    {
        $results = [];
        foreach ($this->getSubjectNodes($exclude) as $subject) {
            foreach ($subject->subjectConcept as $concept) {
                foreach ($concept->term as $term) {
                    $str = trim((string) $term);
                    if ($str !== '') {
                        $results[] = $str;
                    }
                }
            }
        }
        return $results;
    }

    /**
     * Return the subject display places
     *
     * @return array
     */
    protected function getSubjectDisplayPlaces()
    {
        $results = [];
        foreach ($this->getSubjectNodes() as $subject) {
            foreach ($subject->subjectPlace as $place) {
                if (!empty($place->displayPlace)) {
                    $results[] = MetadataUtils::stripTrailingPunctuation(
                        (string)$place->displayPlace, '.'
                    );
                }
            }
        }
        return $results;
    }

    /**
     * Return the subject places
     *
     * @return array
     */
    protected function getSubjectPlaces()
    {
        $results = [];
        foreach ($this->getSubjectNodes() as $subject) {
            foreach ($subject->subjectPlace as $place) {
                if (!empty($place->place->namePlaceSet)) {
                    foreach ($place->place->namePlaceSet as $set) {
                        if ($set->appellationValue) {
                            $results[] = MetadataUtils::stripTrailingPunctuation(
                                (string) $set->appellationValue, '.'
                            );
                        }
                    }
                }
            }
        }
        return $results;
    }

    /**
     * Return materials associated with a specified event type. Materials are
     * contained inside events. The individual materials are retrieved.
     *
     * @param string $eventType Which event to use
     *
     * @link   http://www.lido-schema.org/schema/v1.0/lido-v1.0-schema-listing.html
     * #materialsTechSetComplexType
     * @return array
     */
    protected function getEventMaterials($eventType)
    {
        $results = [];
        $displayTerms = [];
        foreach ($this->getEventNodes($eventType) as $event) {
            foreach ($event->eventMaterialsTech as $eventMaterialsTech) {
                foreach ($eventMaterialsTech->displayMaterialsTech
                    as $displayMaterialsTech
                ) {
                    $displayTerms[] = trim((string) $displayMaterialsTech);
                }
                foreach ($eventMaterialsTech->materialsTech as $materialsTech) {
                    foreach ($materialsTech->termMaterialsTech as $termMaterialsTech
                    ) {
                        foreach ($termMaterialsTech->term as $term) {
                            $results[] = (string) $term;
                        }
                    }
                }
            }
        }
        return $results ? $results : $displayTerms;
    }

    /**
     * Get all XML fields
     *
     * @param SimpleXMLElement $xml The XML document
     *
     * @return array
     */
    protected function getAllFields($xml)
    {
        $ignoredFields = [
            'conceptID', 'eventType', 'legalBodyWeblink', 'linkResource',
            'objectMeasurementsWrap', 'recordMetadataDate', 'recordType',
            'resourceWrap', 'relatedWorksWrap', 'rightsType', 'roleActor'
        ];

        $allFields = [];
        foreach ($xml->children() as $tag => $field) {
            if (in_array($tag, $ignoredFields)) {
                continue;
            }
            $s = trim((string)$field);
            if ($s) {
                $allFields[] = $s;
            }
            $s = $this->getAllFields($field);
            if ($s) {
                $allFields = array_merge($allFields, $s);
            }
        }
        return $allFields;
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
     *
     * TODO: complicated normalizations like this should preferably reside within
     * their own, separate component which should allow modification of the
     * algorithm by methods other than hard-coding rules into source.
     *
     * @param string $input Date range
     *
     * @return string Two ISO 8601 dates separated with a comma on success, and null
     * on failure
     */
    protected function parseDateRange($input)
    {
        $input = trim(strtolower($input));

        if (preg_match('/(\d\d\d\d) ?- (\d\d\d\d)/', $input, $matches) > 0) {
            $startDate = $matches[1];
            $endDate = $matches[2];
        } elseif (preg_match('/(\d\d\d\d)-(\d\d?)-(\d\d?)/', $input, $matches) > 0) {
            $year = $matches[1];
            $month = sprintf('%02d', $matches[2]);
            $day = sprintf('%02d', $matches[3]);
            $startDate = $year . '-' . $month . '-' . $day . 'T00:00:00Z';
            $endDate = $year . '-' . $month . '-' . $day . 'T23:59:59Z';
            $noprocess = true;
        } elseif (true
            && preg_match(
                '/(\d\d?)\s*.\s*(\d\d?)\s*.\s*(\d\d\d\d)/', $input, $matches
            ) > 0
        ) {
            $year = $matches[3];
            $month = sprintf('%02d', $matches[2]);
            $day = sprintf('%02d', $matches[1]);
            $startDate = $year . '-' . $month . '-' . $day . 'T00:00:00Z';
            $endDate = $year . '-' . $month . '-' . $day . 'T23:59:59Z';
            $noprocess = true;
        } elseif (preg_match('/(\d?\d?\d\d) ?\?/', $input, $matches) > 0) {
            $year = $matches[1];

            $startDate = $year - 3;
            $endDate = $year + 3;
        } elseif (preg_match('/(\d?\d?\d\d)/', $input, $matches) > 0) {
            $year = $matches[1];

            $startDate = $year;
            $endDate = $year;
        } else {
            return null;
        }

        if (strlen($startDate) == 2) {
            $startDate = 1900 + (int)$startDate;
        }
        if (strlen($endDate) == 2) {
            $century = substr($startDate, 0, 2) . '00';
            $endDate = (int)$century + (int)$endDate;
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

        if (MetadataUtils::validateISO8601Date($startDate) === false
            || MetadataUtils::validateISO8601Date($endDate) === false
        ) {
            return null;
        }

        return "$startDate,$endDate";
    }

    /**
     * Get all events
     *
     * @param string|string[] $event Which events to use (omit to scan all events)
     *
     * @return simpleXMLElement[] Array of event nodes
     */
    protected function getEventNodes($event = null)
    {
        if (empty($this->doc->lido->descriptiveMetadata->eventWrap->eventSet)) {
            return [];
        }
        $eventList = [];
        foreach ($this->doc->lido->descriptiveMetadata->eventWrap->eventSet
            as $eventSetNode
        ) {
            foreach ($eventSetNode->event as $eventNode) {
                if (!empty($event)) {
                    $eventTypes = [];
                    if (!empty($eventNode->eventType->term)) {
                        foreach ($eventNode->eventType->term as $term) {
                            $eventTypes[] = mb_strtolower((string) $term, 'UTF-8');
                        }
                    }
                    if (true
                        && !array_intersect(
                            $eventTypes, is_array($event) ? $event : [$event]
                        )
                    ) {
                        continue;
                    }
                }
                $eventList[] = $eventNode;
            }
        }
        return $eventList;
    }

    /**
     * Get all title sets
     *
     * @param string|string[] $types Which subject types to include
     *
     * @return simpleXMLElement[] Array of subjectSet nodes
     */
    protected function getTitleSetNodes($types = [])
    {
        $empty = empty(
            $this->doc->lido->descriptiveMetadata->objectIdentificationWrap
                ->titleWrap->titleSet
        );
        if ($empty) {
            return [];
        }
        $setList = [];
        foreach ($this->doc->lido->descriptiveMetadata->objectIdentificationWrap
            ->titleWrap->titleSet as $titleSetNode
        ) {
            $setList[] = $titleSetNode;
        }
        return $setList;
    }

    /**
     * Get all subject sets
     *
     * @return simpleXMLElement[] Array of subjectSet nodes
     */
    protected function getSubjectSetNodes()
    {
        $empty = empty(
            $this->doc->lido->descriptiveMetadata->objectRelationWrap->subjectWrap
                ->subjectSet
        );
        if ($empty) {
            return [];
        }
        $setList = [];
        foreach ($this->doc->lido->descriptiveMetadata->objectRelationWrap
            ->subjectWrap->subjectSet as $subjectSetNode
        ) {
            $setList[] = $subjectSetNode;
        }
        return $setList;
    }

    /**
     * Get all subjects
     *
     * @param string|string[] $exclude Which subject types to exclude
     *
     * @return simpleXMLElement[] Array of subject nodes
     */
    protected function getSubjectNodes($exclude = [])
    {
        $subjectList = [];
        foreach ($this->getSubjectSetNodes() as $subjectSetNode) {
            foreach ($subjectSetNode->subject as $subjectNode) {
                if (empty($exclude)
                    || empty($subjectNode['type'])
                    || !in_array(
                        mb_strtolower($subjectNode['type'], 'UTF-8'), $exclude
                    )
                ) {
                    $subjectList[] = $subjectNode;
                }
            }
        }
        return $subjectList;
    }

    /**
     * Get all object description sets
     *
     * @param string|string[] $exclude Which description types to exclude
     *
     * @return simpleXMLElement[] Array of objectDescriptionSet nodes
     */
    protected function getObjectDescriptionSetNodes($exclude = [])
    {
        $empty = empty(
            $this->doc->lido->descriptiveMetadata->objectIdentificationWrap
                ->objectDescriptionWrap->objectDescriptionSet
        );
        if ($empty) {
            return [];
        }
        $setList = [];
        foreach ($this->doc->lido->descriptiveMetadata->objectIdentificationWrap
            ->objectDescriptionWrap->objectDescriptionSet as $objectSetNode
        ) {
            if (empty($exclude)
                || empty($objectSetNode['type'])
                || !in_array(
                    mb_strtolower($objectSetNode['type'], 'UTF-8'),
                    $exclude
                )
            ) {
                $setList[] = $objectSetNode;
            }
        }
        return $setList;
    }

    /**
     * Get related work sets
     *
     * @param string[] $relatedWorkRelType Which relation types to include
     *
     * @return simpleXMLElement[] Array of relatedWorkSet nodes
     */
    protected function getRelatedWorkSetNodes($relatedWorkRelType = [])
    {
        $empty = empty(
            $this->doc->lido->descriptiveMetadata->objectRelationWrap
                ->relatedWorksWrap->relatedWorkSet
        );
        if ($empty) {
            return [];
        }
        $setList = [];
        foreach ($this->doc->lido->descriptiveMetadata->objectRelationWrap
            ->relatedWorksWrap->relatedWorkSet as $relatedWorkSetNode
        ) {
            if (empty($relatedWorkRelType)
                || empty($relatedWorkSetNode->relatedWorkRelType->term)
                || in_array(
                    mb_strtolower(
                        $relatedWorkSetNode->relatedWorkRelType->term, 'UTF-8'
                    ),
                    $relatedWorkRelType
                )
            ) {
                $setList[] = $relatedWorkSetNode;
            }
        }
        return $setList;
    }

    /**
     * Get resource sets
     *
     * @return simpleXMLElement[] Array of resourceSet nodes
     */
    protected function getResourceSetNodes()
    {
        $empty = empty(
            $this->doc->lido->administrativeMetadata->resourceWrap->resourceSet
        );
        if ($empty) {
            return [];
        }
        $setList = [];
        foreach ($this->doc->lido->administrativeMetadata->resourceWrap->resourceSet
            as $resourceSetNode
        ) {
            $setList[] = $resourceSetNode;
        }
        return $setList;
    }
}

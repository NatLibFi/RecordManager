<?php
/**
 * Lido record class
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
namespace RecordManager\Base\Record;

use RecordManager\Base\Database\DatabaseInterface as Database;

/**
 * Lido record class
 *
 * This is a class for processing LIDO records.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class Lido extends AbstractRecord
{
    /**
     * The XML document
     *
     * @var \SimpleXMLElement
     */
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
        return $this->metadataUtils->trimXMLWhitespace($this->doc->asXML());
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
     * @param Database $db Database connection. Omit to avoid database lookups for
     *                     related records.
     *
     * @return array
     */
    public function toSolrArray(Database $db = null)
    {
        $data = [];

        $data['record_format'] = 'lido';
        $lang = $this->getDefaultLanguage();
        $title = $this->getTitle(false, $lang);
        if ($this->getDriverParam('splitTitles', false)) {
            $titlePart = $this->metadataUtils->splitTitle($title);
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
                    $data['description'],
                    $description,
                    strlen($data['description'])
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

        if ($dates = $this->getSubjectDisplayDates()) {
            $data['era'] = $data['era_facet'] = $dates;
        } elseif ($date = $this->getEventDisplayDate($this->mainEvent)) {
            $data['era'] = $data['era_facet'] = $date;
        }

        $data['geographic_facet'] = [];
        $eventPlace = $this->getEventDisplayPlace($this->usagePlaceEvent);
        if ($eventPlace) {
            $data['geographic_facet'][] = $eventPlace;
        }
        $data['geographic_facet'] = array_merge(
            $data['geographic_facet'],
            $this->getSubjectDisplayPlaces()
        );
        $data['geographic'] = $data['geographic_facet'];
        // Index the other place forms only to facets
        $data['geographic_facet'] = array_merge(
            $data['geographic_facet'],
            $this->getSubjectPlaces()
        );
        $data['collection']
            = $this->getRelatedWorkDisplayObject($this->relatedWorkRelationTypes);

        $urls = $this->getURLs();
        if (count($urls)) {
            // thumbnail field is not multivalued so can only store first image url
            $data['thumbnail'] = $urls[0];
        }

        $data['ctrlnum'] = $this->getRecordInfoIDs();
        $data['isbn'] = $this->getISBNs();
        $data['issn'] = $this->getISSNs();

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
    public function getTitle(
        $forFiling = false,
        $lang = null,
        $excludedDescriptions = ['provenance']
    ) {
        $titles = [];
        $allTitles = [];
        foreach ($this->getTitleSetNodes() as $set) {
            foreach ($set->appellationValue as $appellationValue) {
                if ($lang == null || $appellationValue['lang'] == $lang) {
                    $titles[] = (string)$appellationValue;
                }
                $allTitles[] = (string)$appellationValue;
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
            $title = $this->metadataUtils->stripLeadingPunctuation($title);
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
                            '/[\/;]/',
                            (string)$eventNode->eventPlace->displayPlace
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
     * Return main author (format: Last, First)
     *
     * @return string
     */
    public function getMainAuthor()
    {
        $authors = $this->getActors($this->mainEvent);
        return $authors ? $authors[0] : '';
    }

    /**
     * Get key data that can be used to identify expressions of a work
     *
     * Returns an associative array like this:
     *
     * [
     *   'titles' => [
     *     ['type' => 'title', 'value' => 'Title'],
     *     ['type' => 'uniform', 'value' => 'Uniform Title']
     *    ],
     *   'authors' => [
     *     ['type' => 'author', 'value' => 'Name 1'],
     *     ['type' => 'author', 'value' => 'Name 2']
     *   ],
     *   'titlesAltScript' => [
     *     ['type' => 'title', 'value' => 'Title in alternate script'],
     *     ['type' => 'uniform', 'value' => 'Uniform Title in alternate script']
     *   ],
     *   'authorsAltScript' => [
     *     ['type' => 'author', 'value' => 'Name 1 in alternate script'],
     *     ['type' => 'author', 'value' => 'Name 2 in alternate script']
     *   ]
     * ]
     *
     * @return array
     */
    public function getWorkIdentificationData()
    {
        $titlesByLang = [];
        foreach ($this->getTitleSetNodes() as $set) {
            foreach ($set->appellationValue as $appellationValue) {
                $title = trim((string)$appellationValue);
                if ('' !== $title) {
                    $lang = (string)($appellationValue['lang'] ?? 'NA');
                    $titlesByLang[$lang][] = $title;
                }
            }
        }

        $titles = [];
        foreach ($titlesByLang as $titleParts) {
            $title = implode(' ', $titleParts);
            $titles[] = ['type' => 'title', 'value' => $title];
            $sortTitle = $this->metadataUtils->stripLeadingPunctuation($title);
            if ($sortTitle !== $title) {
                $titles[] = ['type' => 'title', 'value' => $sortTitle];
            }
        }

        $authors = [];
        if ($author = $this->getMainAuthor()) {
            $authors[] = ['type' => 'author', 'value' => $author];
        }
        $titlesAltScript = [];
        $authorsAltScript = [];
        return compact('titles', 'authors', 'titlesAltScript', 'authorsAltScript');
    }

    /**
     * Dedup: Return ISBNs in ISBN-13 format without dashes
     *
     * @return array
     */
    public function getISBNs()
    {
        $arr = [];
        foreach ($this->getIdentifiersByType(['isbn'], []) as $identifier) {
            $identifier = str_replace('-', '', trim($identifier));
            if (!preg_match('{^([0-9]{9,12}[0-9xX])}', $identifier, $matches)) {
                continue;
            }
            $isbn = $this->metadataUtils->normalizeISBN($matches[1]);
            if ($isbn) {
                $arr[] = $isbn;
            } else {
                $this->storeWarning("Invalid ISBN '$identifier'");
            }
        }

        return array_unique($arr);
    }

    /**
     * Dedup: Return ISSNs
     *
     * @return array
     */
    public function getISSNs()
    {
        return $this->getIdentifiersByType(['issn'], []);
    }

    /**
     * Get the last sublocation (partOfPlace) of a place
     *
     * @param \SimpleXMLElement $place Place element
     * @param bool              $isSub Is the current $place a sublocation
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
                $description[] = trim((string)$descriptiveNoteValue);
            }
        }

        if ($this->getTitle() == implode('; ', $description)) {
            // We have the description already in the title, don't repeat
            return '';
        }

        return trim(implode(' ', $description));
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
                return (string)$type->term;
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
                    $link = trim((string)$node->linkResource);
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
                        $actorRole = $this->metadataUtils->normalizeRelator(
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
                $str = trim(
                    $this->metadataUtils->stripTrailingPunctuation(
                        (string)$eventNode->eventPlace->displayPlace,
                        '.'
                    )
                );
                if ('' !== $str) {
                    return $str;
                }
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
                $str = trim((string)$eventNode->eventDate->displayDate);
                if ('' !== $str) {
                    return $str;
                }
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
                return trim((string)$set->relatedWork->displayObject);
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
                    $str = trim((string)$term);
                    if ($str !== '') {
                        $results[] = $str;
                    }
                }
            }
        }
        return $results;
    }

    /**
     * Return the subject display dates
     *
     * @return array
     */
    protected function getSubjectDisplayDates()
    {
        $results = [];
        foreach ($this->getSubjectNodes() as $subject) {
            foreach ($subject->subjectDate as $date) {
                if (!empty($date->displayDate)) {
                    $str = trim(
                        $this->metadataUtils->stripTrailingPunctuation(
                            (string)$date->displayDate,
                            '.'
                        )
                    );
                    if ('' !== $str) {
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
                    $str = trim(
                        $this->metadataUtils->stripTrailingPunctuation(
                            (string)$place->displayPlace,
                            '.'
                        )
                    );
                    if ('' !== $str) {
                        $results[] = $str;
                    }
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
                            $str = trim(
                                $this->metadataUtils->stripTrailingPunctuation(
                                    (string)$set->appellationValue,
                                    '.'
                                )
                            );
                            if ('' !== $str) {
                                $results[] = $str;
                            }
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
                    $displayTerms[] = trim((string)$displayMaterialsTech);
                }
                foreach ($eventMaterialsTech->materialsTech as $materialsTech) {
                    foreach ($materialsTech->termMaterialsTech as $termMaterialsTech
                    ) {
                        foreach ($termMaterialsTech->term as $term) {
                            $results[] = (string)$term;
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
     * A recursive method for fetching all relevant fields
     *
     * @param \SimpleXMLElement $xml The XML document
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
        return $this->getDriverParam('defaultDisplayLanguage', 'en');
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
                '/(\d\d?)\s*.\s*(\d\d?)\s*.\s*(\d\d\d\d)/',
                $input,
                $matches
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

        if ($this->metadataUtils->validateISO8601Date($startDate) === false
            || $this->metadataUtils->validateISO8601Date($endDate) === false
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
     * @return \simpleXMLElement[] Array of event nodes
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
                            $eventTypes[] = mb_strtolower((string)$term, 'UTF-8');
                        }
                    }
                    if (true
                        && !array_intersect(
                            $eventTypes,
                            is_array($event) ? $event : [$event]
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
     * @return \simpleXMLElement[] Array of subjectSet nodes
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
     * @return \simpleXMLElement[] Array of subjectSet nodes
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
     * @return \simpleXMLElement[] Array of subject nodes
     */
    protected function getSubjectNodes($exclude = [])
    {
        $subjectList = [];
        foreach ($this->getSubjectSetNodes() as $subjectSetNode) {
            foreach ($subjectSetNode->subject as $subjectNode) {
                if (empty($exclude)
                    || empty($subjectNode['type'])
                    || !in_array(
                        mb_strtolower($subjectNode['type'], 'UTF-8'),
                        $exclude
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
     * @return \simpleXMLElement[] Array of objectDescriptionSet nodes
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
     * @return \simpleXMLElement[] Array of relatedWorkSet nodes
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
                    trim(
                        mb_strtolower(
                            $relatedWorkSetNode->relatedWorkRelType->term,
                            'UTF-8'
                        )
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
     * @return \simpleXMLElement[] Array of resourceSet nodes
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

    /**
     * Return identifiers from recordInfoSet.
     *
     * @return array
     */
    protected function getRecordInfoIDs()
    {
        $hasValue = isset(
            $this->doc->lido->administrativeMetadata->recordWrap->recordInfoSet
        );
        if (!$hasValue) {
            return [];
        }

        $ids = [];
        foreach ($this->doc->lido->administrativeMetadata->recordWrap->recordInfoSet
            as $set
        ) {
            if (isset($set->recordInfoID)) {
                $info = $set->recordInfoID;
                $attributes = $info->attributes();
                if (isset($attributes->type)) {
                    $type = (string)$attributes->type;
                    $ids[] = "($type)" . (string)$info;
                }
            }
        }
        return $ids;
    }

    /**
     * Return identifiers by type.
     *
     * @param array $include Types to include
     * @param array $exclude Types to exclude
     *
     * @return array
     */
    protected function getIdentifiersByType(
        array $include = [],
        array $exclude = []
    ): array {
        $result = [];
        foreach ($this->doc->lido->descriptiveMetadata as $dmd) {
            foreach ($dmd->objectIdentificationWrap->repositoryWrap->repositorySet
                ?? [] as $set
            ) {
                foreach ($set->workID as $workId) {
                    $type = trim($workId['type']);
                    if ($include && !in_array($type, $include)) {
                        continue;
                    }
                    if ($type && $exclude && !in_array($type, $include)) {
                        continue;
                    }
                    if ($identifier = trim($workId)) {
                        $result[] = $identifier;
                    }
                }
            }
        }
        return $result;
    }
}

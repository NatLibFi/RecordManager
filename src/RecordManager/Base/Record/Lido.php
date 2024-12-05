<?php

/**
 * Lido record class
 *
 * PHP version 8
 *
 * Copyright (C) The National Library of Finland 2011-2022.
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

use function count;
use function in_array;
use function is_string;
use function sprintf;
use function strlen;

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
    use XmlRecordTrait;

    /**
     * Main event names reflecting the terminology in the particular LIDO records.
     *
     * Key is event type, value is priority (smaller more important).
     *
     * @var array
     */
    protected $mainEvents = [
        'design' => 0,
        'creation' => 1,
    ];

    /**
     * Place event names reflecting the terminology in the particular LIDO records.
     *
     * Key is event type, value is priority (smaller more important).
     *
     * @var array
     */
    protected $placeEvents = [
        'usage' => 0,
    ];

    /**
     * Event names reflecting the terminology in the particular LIDO records to use
     * for retrieving secondary authors.
     *
     * Key is event type, value is priority (smaller more important).
     *
     * @var array
     */
    protected $secondaryAuthorEvents = [];

    /**
     * Related work relation types reflecting the terminology in the particular LIDO
     * records.
     *
     * @var array
     */
    protected $relatedWorkRelationTypes = [
        'Collection', 'belongs to collection', 'collection',
    ];

    /**
     * Description types to exclude from title
     *
     * @var array
     */
    protected $descriptionTypesExcludedFromTitle = ['provenance'];

    /**
     * Subject conceptID types included in topic identifiers (all lowercase).
     *
     * @var array
     */
    protected $subjectConceptIDTypes = ['uri', 'url'];

    /**
     * Repository location types to be included.
     *
     * @var array
     */
    protected $repositoryLocationTypes = [];

    /**
     * Excluded location appellationValue labels.
     *
     * @var array
     */
    protected $excludedLocationAppellationValueLabels = [];

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
     * Return fields to be indexed in Solr
     *
     * @param ?Database $db Database connection. Omit to avoid database lookups for related records.
     *
     * @return array<string, mixed>
     */
    public function toSolrArray(?Database $db = null)
    {
        $data = [];

        $data['record_format'] = 'lido';
        $title = $this->getTitle(false);
        $data['title'] = $data['title_short'] = $data['title_full'] = $title;
        $data['title_sort'] = $this->metadataUtils->createSortTitle($title);
        $data['title_alt'] = $this->getAltTitles();

        $data['description'] = $this->getDescription();

        $data['format'] = $this->getObjectWorkType();
        $data['institution'] = $this->getLegalBodyName();

        $data['author'] = $this->getAuthors();
        if (!empty($data['author'])) {
            $data['author_sort'] = $data['author'][0];
        }
        if ($this->secondaryAuthorEvents) {
            $data['author2'] = $this->getSecondaryAuthors();
        }

        $data['topic'] = $data['topic_facet'] = $this->getSubjectTerms();
        $data['material_str_mv'] = $this->getMaterials();

        $data['era'] = $data['era_facet'] = $this->getDisplayDates();

        $data['geographic'] = $data['geographic_facet'] = $this->getDisplayPlaces();
        // Index the other place forms only to facets:
        $data['geographic_facet'] = [
            ...$data['geographic_facet'],
            ...$this->getSubjectPlaces(),
        ];
        $data['collection'] = $this->getCollection();

        $urls = $this->getURLs();
        if (count($urls)) {
            // thumbnail field is not multivalued so can only store first image url
            $data['thumbnail'] = $urls[0];
        }

        $data['ctrlnum'] = $this->getRecordInfoIDs();
        $data['isbn'] = $this->getISBNs();
        $data['issn'] = $this->getISSNs();

        $this->getHierarchyFields($data);

        $data['allfields'] = $this->getAllFields($this->doc);

        // Include hierarchy titles from relatedWorksWrap:
        foreach (
            ['is_hierarchy_title', 'hierarchy_parent_title', 'hierarchy_top_title', 'title_in_hierarchy'] as $field
        ) {
            // phpcs:ignore
            /** @psalm-var list<string> */
            $titles = (array)($data[$field] ?? []);
            if ($titles) {
                $data['allfields'] = [
                    ...$data['allfields'],
                    ...$titles,
                ];
            }
        }

        return $data;
    }

    /**
     * Return record title
     *
     * @param bool $forFiling Whether the title is to be used in filing
     *                        (e.g. sorting, non-filing characters should be removed)
     *
     * @return string
     */
    public function getTitle($forFiling = false)
    {
        $titles = $this->getTitles();
        $title = $titles['preferred'];
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
        foreach ([$this->getMainEvents(), $this->getPlaceEvents()] as $event) {
            foreach ($this->getEventNodes($event) as $eventNode) {
                foreach ($eventNode->eventPlace as $placeNode) {
                    // If there is already gml in the record,
                    // don't return anything for geocoding
                    if (!empty($placeNode->gml)) {
                        return [];
                    }
                    $hasValue = !empty(
                        $placeNode->place->namePlaceSet->appellationValue
                    );
                    if ($hasValue) {
                        $mainPlace = (string)$placeNode->place->namePlaceSet
                            ->appellationValue;
                        $subLocation = $this->getSubLocation(
                            $placeNode->place
                        );
                        if ($mainPlace && !$subLocation) {
                            $locations = [
                                ...$locations,
                                ...explode('/', $mainPlace),
                            ];
                        } else {
                            $locations[] = "$mainPlace $subLocation";
                        }
                    } elseif (!empty($placeNode->displayPlace)) {
                        // Split multiple locations separated with a slash
                        $locations = [
                            ...$locations,
                            ...preg_split(
                                '/[\/;]/',
                                (string)$placeNode->displayPlace
                            ) ?: [],
                        ];
                    }
                }
            }
        }
        return [
            'primary' => $locations,
            'secondary' => [],
        ];
    }

    /**
     * Return main author (format: Last, First)
     *
     * @return string
     */
    public function getMainAuthor()
    {
        $authors = $this->getAuthors();
        return $authors ? $authors[0] : '';
    }

    /**
     * Get key data that can be used to identify expressions of a work
     *
     * Returns an associative array like this where each set of keys defines the
     * keys for a work (multiple sets can be returned for compound works):
     *
     * [
     *   [
     *     'titles' => [
     *       ['type' => 'title', 'value' => 'Title'],
     *       ['type' => 'uniform', 'value' => 'Uniform Title']
     *      ],
     *     'authors' => [
     *       ['type' => 'author', 'value' => 'Name 1'],
     *       ['type' => 'author', 'value' => 'Name 2']
     *     ],
     *     'titlesAltScript' => [
     *       ['type' => 'title', 'value' => 'Title in alternate script'],
     *       ['type' => 'uniform', 'value' => 'Uniform Title in alternate script']
     *     ],
     *     'authorsAltScript' => [
     *       ['type' => 'author', 'value' => 'Name 1 in alternate script'],
     *       ['type' => 'author', 'value' => 'Name 2 in alternate script']
     *     ]
     *   ],
     *   [
     *     'type' => 'analytical',
     *     'titles' => [...],
     *     'authors' => [...],
     *     'titlesAltScript' => [...]
     *     'authorsAltScript' => [...]
     *   ]
     * ]
     *
     * @return array
     */
    public function getWorkIdentificationData()
    {
        $titles = [];
        $titleData = $this->getTitles();
        if ($titleData['preferred']) {
            $titles[] = ['type' => 'title', 'value' => $titleData['preferred']];
        }
        foreach ($titleData['alternate'] as $title) {
            $titles[] = ['type' => 'title', 'value' => $title];
        }

        $authors = [];
        foreach ($this->getActors($this->getMainEvents(), null, false) as $author) {
            $authors[] = ['type' => 'author', 'value' => $author];
        }
        $titlesAltScript = [];
        $authorsAltScript = [];
        return [compact('titles', 'authors', 'titlesAltScript', 'authorsAltScript')];
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
     * Get all topic identifiers (for enrichment)
     *
     * @return array
     */
    public function getRawTopicIds(): array
    {
        return $this->getTopicIDs();
    }

    /**
     * Get all geographic topic identifiers (for enrichment)
     *
     * @return array
     */
    public function getRawGeographicTopicIds(): array
    {
        return [];
    }

    /**
     * Return subject identifiers associated with object.
     *
     * @param string[] $exclude List of subject types to exclude (defaults to
     *                          'iconclass' since it doesn't contain human readable
     *                          terms)
     *
     * @link   http://www.lido-schema.org/schema/v1.0/lido-v1.0-schema-listing.html
     * #subjectComplexType
     * @return array
     */
    protected function getTopicIDs($exclude = ['iconclass']): array
    {
        $result = [];
        foreach ($this->getSubjectNodes($exclude) as $subject) {
            foreach ($subject->subjectConcept as $concept) {
                foreach ($concept->conceptID as $conceptID) {
                    if ($id = trim((string)$conceptID)) {
                        $type = mb_strtolower(
                            (string)($conceptID['type'] ?? ''),
                            'UTF-8'
                        );
                        if (in_array($type, $this->subjectConceptIDTypes)) {
                            $result[] = $id;
                        }
                    }
                }
            }
        }
        return $result;
    }

    /**
     * Return record titles
     *
     * @return array Associative array with keys 'preferred' (string) and
     * 'alternate' (array)
     */
    protected function getTitles()
    {
        $key = __METHOD__ . '/'
            . implode(';', $this->descriptionTypesExcludedFromTitle);
        if (isset($this->resultCache[$key])) {
            return $this->resultCache[$key];
        }
        $mergeValues = $this->getDriverParam('mergeTitleValues', true);
        $mergeSets = $this->getDriverParam('mergeTitleSets', true);
        $formatInTitle = $this->getDriverParam('allowTitleToMatchFormat', false);
        $preferredTitles = [];
        $alternateTitles = [];
        $defaultLanguage = $this->getDefaultLanguage();
        foreach (
            $this->doc->lido->descriptiveMetadata->objectIdentificationWrap
            ->titleWrap->titleSet ?? [] as $set
        ) {
            $preferredParts = [];
            $alternateParts = [];
            foreach ($set->appellationValue as $appellationValue) {
                if (!($title = trim((string)$appellationValue))) {
                    continue;
                }
                $preference = (string)$appellationValue['pref'] ?: 'preferred';
                $titleLang = $this->getInheritedXmlAttribute(
                    $appellationValue,
                    'lang',
                    $defaultLanguage
                );
                if ('preferred' === $preference) {
                    $preferredParts[$titleLang][] = $title;
                } else {
                    $alternateParts[$titleLang][] = $title;
                }
            }
            foreach ($preferredParts as $lang => $parts) {
                // Merge repeated parts in a single titleSet if configured:
                if ($mergeValues && isset($alternateParts[$lang])) {
                    $parts = [...$parts, ...$alternateParts[$lang]];
                    unset($alternateParts[$lang]);
                }
                $preferredTitles[$lang][] = implode('; ', $parts);
            }
            foreach ($alternateParts as $lang => $parts) {
                $alternateTitles[$lang][] = implode('; ', $parts);
            }
        }

        // Merge repeated titleSets if configured:
        if ($mergeSets) {
            foreach (array_keys($preferredTitles) as $lang) {
                $preferredTitles[$lang] = [
                    implode('; ', array_unique($preferredTitles[$lang])),
                ];
            }
            foreach (array_keys($alternateTitles) as $lang) {
                $alternateTitles[$lang] = [
                    implode('; ', array_unique($alternateTitles[$lang])),
                ];
            }
        }

        if (isset($preferredTitles[$defaultLanguage])) {
            $preferred = array_shift($preferredTitles[$defaultLanguage]);
        } elseif ($preferredTitles) {
            reset($preferredTitles);
            $preferred = array_shift($preferredTitles[key($preferredTitles)]);
        } elseif (isset($alternateTitles[$defaultLanguage])) {
            $preferred = array_shift($alternateTitles[$defaultLanguage]);
        } elseif ($alternateTitles) {
            reset($alternateTitles);
            $preferred = array_shift($alternateTitles[key($alternateTitles)]);
        } else {
            $preferred = '';
        }

        foreach ($preferredTitles as $lang => $titles) {
            foreach ($titles as $title) {
                if (isset($alternateTitles[$lang])) {
                    array_unshift($alternateTitles[$lang], $title);
                } else {
                    $alternateTitles[$lang][] = $title;
                }
            }
        }
        $alternate = array_values(array_unique(array_column($alternateTitles, 0)));

        // If configured, use description if title is the same as the work type.
        // From LIDO specs:
        // "For objects from natural, technical, cultural history e.g. the object
        // name given here and the object type, recorded in the object / work
        // type element are often identical."
        $workType = $this->getObjectWorkType();
        if (!$formatInTitle && strcasecmp($workType, $preferred) == 0) {
            $descriptionWrapDescriptions = [];
            $nodes = $this->getObjectDescriptionSetNodes(
                $this->descriptionTypesExcludedFromTitle
            );
            foreach ($nodes as $set) {
                if ($value = trim((string)($set->descriptiveNoteValue ?? ''))) {
                    $descriptionWrapDescriptions[] = $value;
                }
            }
            if ($descriptionWrapDescriptions) {
                $preferred = implode('; ', $descriptionWrapDescriptions);
            }
        }

        return $this->resultCache[$key] = compact('preferred', 'alternate');
    }

    /**
     * Get an attribute for the node from the node itself or its nearest ancestor
     *
     * @param \SimpleXMLElement $node      Node
     * @param string            $attribute Attribute to get
     * @param string            $default   Default value for the attribute
     * @param int               $levels    How many levels up to traverse
     *
     * @return string
     *
     * @psalm-suppress RedundantCondition
     */
    protected function getInheritedXmlAttribute(
        \SimpleXMLElement $node,
        string $attribute,
        string $default = '',
        int $levels = 255
    ): string {
        if (null !== ($value = $node[$attribute])) {
            return (string)$value;
        }
        $domNode = dom_import_simplexml($node);
        while (($domNode->parentNode instanceof \DOMElement) && --$levels >= 0) {
            $domNode = $domNode->parentNode;
            if ($domNode->hasAttribute($attribute)) {
                $value = $domNode->getAttribute($attribute);
                break;
            }
        }
        return null === $value ? $default : $value;
    }

    /**
     * Get alternate titles
     *
     * @return array
     */
    protected function getAltTitles()
    {
        $titles = $this->getTitles();
        return $titles['alternate'];
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
        foreach (
            $this->doc->lido->descriptiveMetadata->objectIdentificationWrap
            ->repositoryWrap->repositorySet ?? [] as $set
        ) {
            if (!empty($set->repositoryName->legalBodyName->appellationValue)) {
                return (string)$set->repositoryName->legalBodyName
                    ->appellationValue;
            }
        }

        foreach ($this->doc->lido->administrativeMetadata->recordWrap->recordSource ?? [] as $source) {
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
        $description = [];
        foreach (
            $this->doc->lido->descriptiveMetadata->objectIdentificationWrap
            ->objectDescriptionWrap->objectDescriptionSet ?? [] as $set
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
     * @return string
     */
    protected function getObjectWorkType()
    {
        foreach (
            $this->doc->lido->descriptiveMetadata->objectClassificationWrap
            ->objectWorkTypeWrap->objectWorkType ?? [] as $type
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
     * @param string|array $event        Event type(s) allowed (null = all types)
     * @param string|array $role         Roles allowed (null = all roles)
     * @param bool         $includeRoles Whether to include actor roles in the
     *                                   results
     *
     * @return array<int, string>
     */
    protected function getActors($event = null, $role = null, $includeRoles = false)
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
                            $value = (string)$roleNode->actor->nameActorSet
                                ->appellationValue[0];
                            $value = trim($value);
                            if ($value) {
                                if ($includeRoles && $actorRole) {
                                    $value .= ", $actorRole";
                                }
                                $result[] = $value;
                            }
                        }
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Return places associated with specified event
     *
     * @param string|array $event Event type(s) allowed (null = all types)
     *
     * @return array<int, string>
     */
    protected function getEventDisplayPlaces($event = null)
    {
        $results = [];
        foreach ($this->getEventNodes($event) as $eventNode) {
            foreach ($eventNode->eventPlace as $placeNode) {
                if (!empty($placeNode->displayPlace)) {
                    $str = trim(
                        $this->metadataUtils->stripTrailingPunctuation(
                            (string)$placeNode->displayPlace,
                            '.'
                        )
                    );
                    if ($str) {
                        $results[] = $str;
                    }
                }
            }
        }
        return $results;
    }

    /**
     * Return the date range associated with specified event
     *
     * @param string|array $event Event type(s) allowed (null = all types)
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
        $results = [];
        foreach ($this->doc->descriptiveMetadata ?? [] as $node) {
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
     * @return array<int, string>
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
     * @return array<int, string>
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
     * @param string|array $eventType Event(s) to use
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
                foreach ($eventMaterialsTech->displayMaterialsTech as $displayMaterialsTech) {
                    $displayTerms[] = trim((string)$displayMaterialsTech);
                }
                foreach ($eventMaterialsTech->materialsTech as $materialsTech) {
                    foreach ($materialsTech->termMaterialsTech as $termMaterialsTech) {
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
     * @return array<int, string>
     */
    protected function getAllFields($xml)
    {
        $ignoredFields = [
            'conceptID', 'eventType', 'legalBodyWeblink', 'linkResource',
            'objectMeasurementsWrap', 'recordMetadataDate', 'recordType',
            'resourceWrap', 'relatedWorksWrap', 'rightsType', 'roleActor',
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
                $allFields = [...$allFields, ...$s];
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
     * @return string|null Two ISO 8601 dates separated with a comma on success, or
     * null on failure
     */
    protected function parseDateRange($input)
    {
        static $dmyRe = '/(\d\d?)\s*.\s*(\d\d?)\s*.\s*(\d\d\d\d)/';
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
        } elseif (preg_match($dmyRe, $input, $matches) > 0) {
            $year = $matches[3];
            $month = sprintf('%02d', $matches[2]);
            $day = sprintf('%02d', $matches[1]);
            $startDate = $year . '-' . $month . '-' . $day . 'T00:00:00Z';
            $endDate = $year . '-' . $month . '-' . $day . 'T23:59:59Z';
            $noprocess = true;
        } elseif (preg_match('/(\d?\d?\d\d) ?\?/', $input, $matches) > 0) {
            $year = (int)$matches[1];

            $startDate = $year - 3;
            $endDate = $year + 3;
        } elseif (preg_match('/(\d?\d?\d\d)/', $input, $matches) > 0) {
            $year = $matches[1];

            $startDate = $year;
            $endDate = $year;
        } else {
            return null;
        }

        if (strlen((string)$startDate) == 2) {
            $startDate = 1900 + (int)$startDate;
        }
        if (strlen((string)$endDate) == 2) {
            $century = substr((string)$startDate, 0, 2) . '00';
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

        if (
            $this->metadataUtils->validateISO8601Date((string)$startDate) === false
            || $this->metadataUtils->validateISO8601Date((string)$endDate) === false
        ) {
            return null;
        }

        return "$startDate,$endDate";
    }

    /**
     * Get all events
     *
     * @param string|array $events Event type(s) allowed (null = all types)
     *
     * @return \SimpleXMLElement[] Array of event nodes
     */
    protected function getEventNodes($events = null)
    {
        if (is_string($events)) {
            $events = [$events => 0];
        }
        $eventList = [];
        $index = 0;
        foreach ($this->doc->lido->descriptiveMetadata->eventWrap->eventSet ?? [] as $eventSetNode) {
            foreach ($eventSetNode->event as $eventNode) {
                if (null !== $events) {
                    $eventTypes = [];
                    if (!empty($eventNode->eventType->term)) {
                        foreach ($eventNode->eventType->term as $term) {
                            $eventTypes[] = mb_strtolower((string)$term, 'UTF-8');
                        }
                    }
                    $priority = null;
                    foreach ($eventTypes as $eventType) {
                        if (isset($events[$eventType])) {
                            $priority = $events[$eventType];
                            break;
                        }
                    }
                    if (null !== $priority) {
                        ++$index;
                        $eventList["$priority/$index"] = $eventNode;
                    }
                } else {
                    $eventList[] = $eventNode;
                }
            }
        }
        ksort($eventList);
        return array_values($eventList);
    }

    /**
     * Get all subject sets
     *
     * @return array Array of subjectSet nodes
     */
    protected function getSubjectSetNodes()
    {
        $setList = [];
        foreach (
            $this->doc->lido->descriptiveMetadata->objectRelationWrap
            ->subjectWrap->subjectSet ?? [] as $subjectSetNode
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
     * @return array Array of subjectSet nodes
     */
    protected function getSubjectNodes($exclude = [])
    {
        $subjectList = [];
        foreach ($this->getSubjectSetNodes() as $subjectSetNode) {
            foreach ($subjectSetNode->subject as $subjectNode) {
                if (
                    empty($exclude)
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
     * @return array Array of objectDescriptionSet nodes
     */
    protected function getObjectDescriptionSetNodes($exclude = [])
    {
        $setList = [];
        foreach (
            $this->doc->lido->descriptiveMetadata->objectIdentificationWrap
            ->objectDescriptionWrap->objectDescriptionSet ?? [] as $objectSetNode
        ) {
            if (
                empty($exclude)
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
     * @return array Array of relatedWorkSet nodes
     */
    protected function getRelatedWorkSetNodes($relatedWorkRelType = [])
    {
        $setList = [];
        foreach (
            $this->doc->lido->descriptiveMetadata->objectRelationWrap
            ->relatedWorksWrap->relatedWorkSet ?? [] as $relatedWorkSetNode
        ) {
            $relType = trim(
                mb_strtolower(
                    $relatedWorkSetNode->relatedWorkRelType->term ?? '',
                    'UTF-8'
                )
            );
            if (
                empty($relatedWorkRelType)
                || in_array($relType, $relatedWorkRelType)
            ) {
                $setList[] = $relatedWorkSetNode;
            }
        }
        return $setList;
    }

    /**
     * Get resource sets
     *
     * @return \SimpleXMLElement[] Array of resourceSet nodes
     */
    protected function getResourceSetNodes()
    {
        $setList = [];
        foreach ($this->doc->lido->administrativeMetadata->resourceWrap->resourceSet ?? [] as $resourceSetNode) {
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
        $ids = [];
        foreach ($this->doc->lido->administrativeMetadata->recordWrap->recordInfoSet ?? [] as $set) {
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
            foreach ($dmd->objectIdentificationWrap->repositoryWrap->repositorySet ?? [] as $set) {
                foreach ($set->workID as $workId) {
                    $type = trim($workId['type'] ?? '');
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

    /**
     * Return repository locations
     *
     * @return array<int, string>
     */
    protected function getRepositoryLocations(): array
    {
        $result = [];
        foreach (
            $this->doc->lido->descriptiveMetadata->objectIdentificationWrap->repositoryWrap->repositorySet
            ?? [] as $set
        ) {
            $type = (string)($set->attributes()->type ?? '');
            if ($this->repositoryLocationTypes && !in_array($type, $this->repositoryLocationTypes)) {
                continue;
            }
            foreach ($set->repositoryLocation->namePlaceSet ?? [] as $nameSet) {
                foreach ($nameSet->appellationValue ?? [] as $place) {
                    if (
                        $place
                        && !in_array((string)$place->attributes()->label, $this->excludedLocationAppellationValueLabels)
                    ) {
                        $result[] = trim((string)$place);
                    }
                }
            }
            foreach ($set->repositoryLocation ?? [] as $location) {
                foreach ($location->partOfPlace ?? [] as $part) {
                    while ($part->namePlaceSet) {
                        if ($partName = $part->namePlaceSet->appellationValue ?? null) {
                            if (
                                !in_array(
                                    (string)$partName->attributes()->label,
                                    $this->excludedLocationAppellationValueLabels
                                )
                            ) {
                                $result[] = trim((string)$partName);
                            }
                        }
                        $part = $part->partOfPlace;
                    }
                }
            }
        }
        return $result;
    }

    /**
     * Get main event types
     *
     * @return array
     */
    protected function getMainEvents(): array
    {
        return $this->mainEvents;
    }

    /**
     * Get secondary author event types
     *
     * @return array
     */
    protected function getSecondaryAuthorEvents(): array
    {
        return $this->secondaryAuthorEvents;
    }

    /**
     * Get place event types
     *
     * @return array
     */
    protected function getPlaceEvents(): array
    {
        return $this->placeEvents;
    }

    /**
     * Get authors
     *
     * @return array
     */
    protected function getAuthors(): array
    {
        return $this->getActors($this->getMainEvents());
    }

    /**
     * Get secondary authors
     *
     * @return array
     */
    protected function getSecondaryAuthors(): array
    {
        return $this->getActors($this->getSecondaryAuthorEvents());
    }

    /**
     * Get materials
     *
     * @return array
     */
    protected function getMaterials(): array
    {
        return $this->getEventMaterials($this->getMainEvents());
    }

    /**
     * Get Display dates
     *
     * @return array
     */
    protected function getDisplayDates(): array
    {
        $result = $this->getSubjectDisplayDates();
        if (!$result && $date = $this->getEventDisplayDate($this->getMainEvents())) {
            $result = (array)$date;
        }
        return $result;
    }

    /**
     * Get Display places
     *
     * @return array<int, string>
     */
    protected function getDisplayPlaces(): array
    {
        $result = $this->getEventDisplayPlaces($this->getPlaceEvents());
        if ($places = $this->getSubjectDisplayPlaces()) {
            $result = [...$result, ...$places];
        }
        $idPlaces = $this->getRepositoryLocations();
        $result = [...$result, ...$idPlaces];
        return $result;
    }

    /**
     * Get collection
     *
     * @return string
     */
    protected function getCollection(): string
    {
        return $this->getRelatedWorkDisplayObject($this->relatedWorkRelationTypes);
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
        foreach (
            $this->doc->lido->descriptiveMetadata->objectIdentificationWrap->repositoryWrap->repositorySet as $set
        ) {
            if (!empty($set->workID)) {
                return (string)$set->workID;
            }
        }
        return '';
    }

    /**
     * Get hierarchy fields. Must be called after title is present in the array.
     *
     * @param array $data Reference to the target array
     *
     * @return void
     */
    protected function getHierarchyFields(array &$data): void
    {
        foreach ($this->getRelatedWorkSetNodes(['is part of']) as $set) {
            if (!($relatedWork = $set->relatedWork)) {
                continue;
            }
            $relatedId = (string)($relatedWork->object->objectID ?? '');
            if (!$relatedId) {
                $this->logger
                    ->logDebug('Lido', 'Related record ID missing', true);
                continue;
            }
            $relatedTitle = (string)($relatedWork->displayObject ?? '');
            if (!$relatedTitle) {
                $this->logger
                    ->logDebug('Lido', 'Related record title missing', true);
                continue;
            }

            $type = (string)($relatedWork->object->objectType->term ?? '');
            if ('collection' === $type) {
                $data['hierarchy_top_id'] = $relatedId;
                $data['hierarchy_top_title'] = $relatedTitle;
            } elseif ('parent' === $type) {
                if ($relatedId === $this->getID()) {
                    $data['is_hierarchy_id'] = $relatedId;
                    $data['is_hierarchy_title'] = $relatedTitle;
                } else {
                    $data['hierarchy_parent_id'] = $relatedId;
                    $data['hierarchy_parent_title'] = $relatedTitle;
                }
            }
        }
        // If there is hierarchy top id but no parent id, assume this is the top
        // record:
        if (
            !empty($data['hierarchy_top_id'])
            && empty($data['hierarchy_parent_id'])
        ) {
            $data['is_hierarchy_id'] = $data['hierarchy_top_id'];
            $data['is_hierarchy_title'] = $data['hierarchy_top_title'];
        }
        if (!empty($data['hierarchy_parent_id'])) {
            // Build a sequence for sorting:
            $data['hierarchy_sequence'] = preg_replace_callback(
                '/(\d+)/',
                function ($matches) {
                    return str_pad($matches[1], 9, '0', STR_PAD_LEFT);
                },
                $this->getIdentifier()
            );
            // Add title field if needed:
            if ($this->getDriverParam('addIdToHierarchyTitle', true)) {
                $data['title_in_hierarchy']
                    = trim($this->getIdentifier() . ' ' . $data['title']);
            }
        }
    }
}

<?php

/**
 * Lido record class
 *
 * PHP version 8
 *
 * Copyright (C) The National Library of Finland 2011-2026.
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

use function in_array;
use function is_string;

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
    use XmlDocRecordTrait {
        setData as xmlRecordSetData;
    }

    /**
     * LIDO XML namespace.
     *
     * @var string
     */
    protected string $lidoNs = 'http://www.lido-schema.org';

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
     * Related work relation types for collections.
     *
     * @var array
     */
    protected $relatedWorkRelationTypes = [
        'Collection', 'belongs to collection', 'collection',
    ];

    /**
     * Related work relation types for related ISBNs.
     *
     * @var array
     */
    protected $relatedISBNRelationTypes = ['is reproduced in'];

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
     * Title types for preferred titles.
     *
     * @var array
     */
    protected $preferredTitleTypes = ['preferred'];

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
     * LIDO elements excluded from allfields.
     *
     * @var array
     */
    protected $excludeFromAllFields = [
        'conceptID', 'eventType', 'legalBodyWeblink', 'linkResource',
        'objectMeasurementsWrap', 'recordMetadataDate', 'recordType',
        'resourceWrap', 'relatedWorksWrap', 'rightsType', 'roleActor',
    ];

    /**
     * Hierarchy fields included in allfields.
     *
     * @var array
     */
    protected $hierarchyFieldsInAllFields = [
        'is_hierarchy_title', 'hierarchy_parent_title', 'hierarchy_top_title', 'title_in_hierarchy',
    ];

    /**
     * Set record data
     *
     * @param string $source    Source ID
     * @param string $oaiID     Record ID received from OAI-PMH (or empty string for
     *                          file import)
     * @param string $data      Record metadata
     * @param array  $extraData Extra metadata
     *
     * @return void
     */
    public function setData($source, $oaiID, $data, $extraData)
    {
        $this->xmlRecordSetData($source, $oaiID, $data, $extraData);
        $this->xmlDoc->setDefaultNamespace($this->lidoNs, 'lido');

        // Make sure we have a lidoWrap element as the root element as <lido> is also allowed in OAI-PMH:
        $rootName = $this->xmlDoc->name($this->xmlDoc->root(), true);
        if (in_array($rootName, ['lido', '{}lido'])) {
            $nodeArray = $this->xmlDoc->export();
            $newRoot = $nodeArray;
            $newRoot['data']['name'] = "{{$this->lidoNs}}lidoWrap";
            $newRoot['data']['sub'] = [
                $nodeArray['data'],
            ];
            // Detect any existing schemaLocation or use default:
            $schemaLocation = $newRoot['data']['attrs']["{{$this->nsXsi}}schemaLocation"]
                ?? $newRoot['data']['attrs']['schemaLocation']
                ?? 'http://www.lido-schema.org http://www.lido-schema.org/schema/v1.1/lido-v1.1.xsd';
            // Remove schemaLocation from lido element:
            unset($newRoot['data']['sub'][0]['attrs']['schemaLocation']);
            unset($newRoot['data']['sub'][0]['attrs']["{{$this->nsXsi}}schemaLocation"]);
            // Verify that the root element has correct schemaLocation:
            unset($newRoot['data']['attrs']['schemaLocation']);
            $newRoot['data']['attrs']["{{$this->nsXsi}}schemaLocation"] = $schemaLocation;
            if (!in_array($this->nsXsi, $newRoot['namespaces'])) {
                $newRoot['namespaces]']['xsi'] = $this->nsXmlns;
            }
            $newRoot['namespaces']['lido'] ??= $this->lidoNs;
            $this->xmlDoc->import($newRoot);
        }
    }

    /**
     * Return record ID (local)
     *
     * @return string
     */
    public function getID()
    {
        return $this->xmlDoc->firstValue(path: 'lido/lidoRecID') ?? '';
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
            $title = $this->metadataUtils->createSortTitle($title);
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
                foreach ($this->xmlDoc->all($eventNode, 'eventPlace') as $placeNode) {
                    // If there is already gml in the record,
                    // don't return anything for geocoding
                    if ($this->xmlDoc->first($placeNode, 'gml')) {
                        return [];
                    }
                    $appellationValue
                        = $this->xmlDoc->firstValue($placeNode, 'place/namePlaceSet/appellationValue') ?? '';
                    if ('' !== $appellationValue) {
                        $mainPlace = $appellationValue;
                        $subPlaceNode = $this->xmlDoc->first($placeNode, 'place');
                        $subLocation = $subPlaceNode ? $this->getSubLocation($subPlaceNode) : '';
                        if (!$subLocation) {
                            $locations = [
                                ...$locations,
                                ...explode('/', $mainPlace),
                            ];
                        } else {
                            $locations[] = "$mainPlace $subLocation";
                        }
                    } elseif ($displayPlace = $this->xmlDoc->firstValue($placeNode, 'displayPlace')) {
                        // Split multiple locations separated with a slash
                        $locations = [
                            ...$locations,
                            ...preg_split(
                                '/[\/;]/',
                                $displayPlace
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
        $authors = $this->getPrimaryAuthors();
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
     * Dedup: Return ISSNs
     *
     * @return array
     */
    public function getISSNs(): array
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
     * Get primary author identifiers
     *
     * @return array<int, string>
     */
    public function getPrimaryAuthorIds(): array
    {
        return [];
    }

    /**
     * Get secondary author identifiers
     *
     * @return array<int, string>
     */
    public function getSecondaryAuthorIds(): array
    {
        return [];
    }

    /**
     * Get short title.
     *
     * @return string
     */
    public function getShortTitle(): string
    {
        return $this->getTitle();
    }

    /**
     * Get full title.
     *
     * @return string
     */
    public function getFullTitle(): string
    {
        return $this->getTitle();
    }

    /**
     * Get format.
     *
     * @link   http://www.lido-schema.org/schema/v1.0/lido-v1.0-schema-listing.html
     * #objectWorkTypeWrap
     * @return string
     */
    public function getFormat()
    {
        return $this->getObjectWorkType();
    }

    /**
     * Do any post-processing for the record after the main conversion to Solr array.
     *
     * @param ?Database $db   Database connection, if available
     * @param array     $data Array of Solr fields
     *
     * @return void
     */
    protected function postProcessRecordForIndexing(?Database $db, &$data): void
    {
        // Additional titles should be added before adding hierarchy fields
        $this->addAdditionalTitles($data);
        $this->addHierarchyFields($data);
    }

    /**
     * Get ISBNs in ISBN-13 format without dashes.
     *
     * @return array
     */
    protected function getISBNs(): array
    {
        $arr = [];
        foreach ($this->getIdentifiersByType(['isbn'], []) as $identifier) {
            if ($isbn = $this->metadataUtils->normalizeISBN($this->checkISBN((string)$identifier))) {
                $arr[] = $isbn;
            } else {
                $this->storeWarning("Invalid ISBN '$identifier'");
            }
        }

        return array_unique($arr);
    }

    /**
     * Get record format.
     *
     * @return string
     */
    protected function getRecordFormat(): string
    {
        return 'lido';
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
        foreach ($this->getSubjectNodes($exclude) as $subjectNode) {
            foreach ($this->xmlDoc->all($subjectNode, 'subjectConcept/conceptID') as $conceptID) {
                if ($id = $this->xmlDoc->value($conceptID)) {
                    $type = mb_strtolower($this->xmlDoc->attr($conceptID, 'type'), 'UTF-8');
                    if (in_array($type, $this->subjectConceptIDTypes)) {
                        $result[] = $id;
                    }
                }
            }
        }
        return $result;
    }

    /**
     * Return record titles
     *
     * @param ?string $languageFilter Only include titles in specific language (for downstream usage)
     *
     * @return array Associative array with keys 'preferred' (string) and
     * 'alternate' (array)
     */
    protected function getTitles(?string $languageFilter = null)
    {
        $key = __METHOD__ . '/'
            . implode(';', $this->descriptionTypesExcludedFromTitle)
            . ($languageFilter ?? '');
        if (isset($this->resultCache[$key])) {
            return $this->resultCache[$key];
        }
        $mergeValues = $this->getDriverParam('mergeTitleValues', true);
        $mergeSets = $this->getDriverParam('mergeTitleSets', true);
        $formatInTitle = $this->getDriverParam('allowTitleToMatchFormat', false);
        $preferredTitles = [];
        $alternateTitles = [];
        // If language filter is specified, use it as the default language for further processing below the following
        // loop:
        $defaultLanguage = $languageFilter ? $languageFilter : $this->getDefaultLanguage();
        foreach ($this->xmlDoc->all(path: 'lido/descriptiveMetadata') as $descriptiveMetadata) {
            $metadataLanguage = $this->getLangAttr($descriptiveMetadata);
            foreach ($this->xmlDoc->all($descriptiveMetadata, 'objectIdentificationWrap/titleWrap/titleSet') as $set) {
                $preferredParts = [];
                $alternateParts = [];
                foreach ($this->xmlDoc->all($set, 'appellationValue') as $appellationValue) {
                    if ('' === ($title = $this->xmlDoc->value($appellationValue))) {
                        continue;
                    }
                    $titleLang = $this->metadataUtils->normalizeLanguageCode(
                        $this->getLangAttr($appellationValue) ?? $metadataLanguage ?? ''
                    );
                    if ($languageFilter && $titleLang !== $languageFilter) {
                        continue;
                    }
                    $titleLang = $titleLang ?: $defaultLanguage;
                    $preference = mb_strtolower($this->xmlDoc->attr($appellationValue, 'pref') ?? 'preferred', 'UTF-8');
                    if (in_array($preference, $this->preferredTitleTypes)) {
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
        } elseif (isset($alternateTitles[$defaultLanguage])) {
            $preferred = array_shift($alternateTitles[$defaultLanguage]);
        } elseif ($preferredTitles) {
            reset($preferredTitles);
            $preferred = array_shift($preferredTitles[key($preferredTitles)]);
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
            $nodes = $this->getObjectDescriptionSetNodes($this->descriptionTypesExcludedFromTitle);
            foreach ($nodes as $set) {
                if (!($descriptiveNoteValue = $this->xmlDoc->first($set, 'descriptiveNoteValue'))) {
                    continue;
                }
                if ('' === ($value = $this->xmlDoc->value($descriptiveNoteValue))) {
                    continue;
                }
                if (
                    $languageFilter === null
                    || $languageFilter === $this->metadataUtils->normalizeLanguageCode(
                        $this->getLangAttr($descriptiveNoteValue) ?? ''
                    )
                ) {
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
     * @param array $place Place node
     * @param bool  $isSub Is the current $place a sublocation
     *
     * @return string
     */
    protected function getSubLocation(array $place, bool $isSub = false): string
    {
        if ($partOfPlaceNode = $this->xmlDoc->first($place, 'partOfPlace')) {
            if ('' !== ($result = $this->getSubLocation($partOfPlaceNode, true))) {
                return $result;
            }
        }
        return $isSub
            ? ($this->xmlDoc->firstValue($place, 'namePlaceSet/appellationValue') ?? '')
            : '';
    }

    /**
     * Get institution.
     *
     * @return string
     */
    protected function getInstitution(): string
    {
        return $this->getLegalBodyName();
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
        $paths = [
            'lido/descriptiveMetadata/objectIdentificationWrap/repositoryWrap/repositorySet/repositoryName'
                . '/legalBodyName/appellationValue',
            'lido/administrativeMetadata/recordWrap/recordSource/legalBodyName/appellationValue',
        ];
        foreach ($paths as $path) {
            // Return first non-empty value:
            foreach ($this->xmlDoc->allValues(path: $path) as $name) {
                return $name;
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
        $path = 'lido/descriptiveMetadata/objectIdentificationWrap/objectDescriptionWrap/objectDescriptionSet'
            . '/descriptiveNoteValue';
        $description = $this->xmlDoc->allValues(path: $path);

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
        $path = 'lido/descriptiveMetadata/objectClassificationWrap/objectWorkTypeWrap/objectWorkType/term';
        // Return the first non-empty value (different from first value):
        foreach ($this->xmlDoc->allValues(path: $path) as $value) {
            return $value;
        }
        return '';
    }

    /**
     * Return URLs associated with object
     *
     * @return array
     */
    protected function getUrls()
    {
        $path = 'lido/administrativeMetadata/resourceWrap/resourceSet/resourceRepresentation/linkResource';
        return $this->xmlDoc->allValues(path: $path);
    }

    /**
     * Return names of actors associated with specified event
     *
     * @param string|array|null $event        Event type(s) allowed (null = all types)
     * @param string|array|null $rolesAllowed Roles allowed (null = all roles)
     * @param bool              $includeRoles Whether to include actor roles in the results
     *
     * @return array<int, string>
     */
    protected function getActors($event = null, $rolesAllowed = null, $includeRoles = false)
    {
        $key = md5(__METHOD__ . ($event ? implode(',', (array)$event) : 'null') . '|'
            . ($rolesAllowed ? implode(',', (array)$rolesAllowed) : 'null') . '|' . ($includeRoles ? '1' : '0'));
        if (isset($this->resultCache[$key])) {
            return $this->resultCache[$key];
        }

        $result = [];
        foreach ($this->getEventNodes($event) as $eventNode) {
            foreach ($this->xmlDoc->all($eventNode, 'eventActor/actorInRole') as $roleNode) {
                $appellationValue = $this->xmlDoc->firstValue($roleNode, 'actor/nameActorSet/appellationValue') ?? '';
                if ('' !== $appellationValue) {
                    $actorRole = $this->metadataUtils->normalizeRelator(
                        $this->xmlDoc->firstValue($roleNode, 'roleActor/term')
                    );
                    if (empty($rolesAllowed) || in_array($actorRole, (array)$rolesAllowed)) {
                        if ($includeRoles && $actorRole) {
                            $appellationValue .= ", $actorRole";
                        }
                        $result[] = $appellationValue;
                    }
                }
            }
        }

        return $this->resultCache[$key] = $result;
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
            foreach ($this->xmlDoc->allValues($eventNode, 'eventPlace/displayPlace') as $displayPlace) {
                $displayPlace = trim(
                    $this->metadataUtils->stripTrailingPunctuation($displayPlace, '.'),
                    ', \n\r\t\v\0'
                );
                if ('' !== $displayPlace) {
                    $results[] = $displayPlace;
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
            if ('' !== ($displayDate = $this->xmlDoc->firstValue($eventNode, 'eventDate/displayDate') ?? '')) {
                return $displayDate;
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
            if ('' !== ($value = $this->xmlDoc->firstValue($set, 'relatedWork/displayObject') ?? '')) {
                return $value;
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
        foreach ($this->xmlDoc->all(path: 'descriptiveMetadata') as $node) {
            if ($lang = $this->getLangAttr($node)) {
                $results[] = $lang;
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
        foreach ($this->getSubjectNodes($exclude) as $subjectNode) {
            foreach ($this->xmlDoc->allValues($subjectNode, 'subjectConcept/term') as $term) {
                if ('' !== $term) {
                    $results[] = $term;
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
        foreach ($this->getSubjectNodes() as $subjectNode) {
            foreach ($this->xmlDoc->allValues($subjectNode, 'subjectDate/displayDate') as $date) {
                $date = $this->metadataUtils->stripTrailingPunctuation($date, '.');
                if ('' !== $date) {
                    $results[] = $date;
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
        foreach ($this->getSubjectNodes() as $subjectNode) {
            foreach ($this->xmlDoc->allValues($subjectNode, 'subjectPlace/displayPlace') as $place) {
                $place = trim(
                    $this->metadataUtils->stripTrailingPunctuation($place, '.'),
                    ', \n\r\t\v\0'
                );
                if ('' !== $place) {
                    $results[] = $place;
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
        foreach ($this->getSubjectNodes() as $subjectNode) {
            foreach (
                $this->xmlDoc->allValues($subjectNode, 'subjectPlace/place/namePlaceSet/appellationValue') as $value
            ) {
                $value = trim(
                    $this->metadataUtils->stripTrailingPunctuation($value, '.')
                );
                if ('' !== $value) {
                    $results[] = $value;
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
            foreach ($this->xmlDoc->all($event, 'eventMaterialsTech') as $eventMaterialsTech) {
                $displayTerms = [
                    ...$displayTerms,
                    ...$this->xmlDoc->allValues($eventMaterialsTech, 'displayMaterialsTech'),
                ];
                $results = [
                    ...$results,
                    ...$this->xmlDoc->allValues($eventMaterialsTech, 'materialsTech/termMaterialsTech/term'),
                ];
            }
        }
        return $results ? $results : $displayTerms;
    }

    /**
     * Get all XML fields
     *
     * A recursive method for fetching all relevant fields
     *
     * @param ?array $parentNode Parent node to process, or null to process the root node
     *
     * @return array<int, string>
     */
    protected function getAllFields(?array $parentNode = null)
    {
        $allFields = [];
        foreach ($this->xmlDoc->all($parentNode) as $node) {
            if (in_array($this->xmlDoc->localName($node), $this->excludeFromAllFields)) {
                continue;
            }
            if ('' !== ($s = $this->xmlDoc->value($node))) {
                $allFields[] = $s;
            }
            $allFields = [
                ...$allFields,
                ...$this->getAllFields($node),
            ];
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
     * Get all events
     *
     * @param string|array $events Event type(s) allowed (null = all types)
     *
     * @return array Array of event nodes
     */
    protected function getEventNodes($events = null): array
    {
        if (is_string($events)) {
            $events = [$events => 0];
        }
        $eventList = [];
        $index = 0;
        $path = 'lido/descriptiveMetadata/eventWrap/eventSet/event';
        foreach ($this->xmlDoc->all(path: $path) as $eventNode) {
            if (null !== $events) {
                $eventTypes = [];
                foreach ($this->xmlDoc->allValues($eventNode, 'eventType/term') as $term) {
                    if ('' !== $term) {
                        $eventTypes[] = mb_strtolower($term, 'UTF-8');
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
        ksort($eventList);
        return array_values($eventList);
    }

    /**
     * Get all subject nodes
     *
     * @param string|string[] $exclude Which subject types to exclude
     *
     * @return array Array of subjectSet nodes
     */
    protected function getSubjectNodes($exclude = []): array
    {
        $subjectList = [];
        $path = 'lido/descriptiveMetadata/objectRelationWrap/subjectWrap/subjectSet/subject';
        foreach ($this->xmlDoc->all(path: $path) as $subjectNode) {
            $type = $this->xmlDoc->attr($subjectNode, 'type');
            if (
                empty($exclude)
                || empty($type)
                || !in_array(mb_strtolower($type, 'UTF-8'), $exclude)
            ) {
                $subjectList[] = $subjectNode;
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
        $path = 'lido/descriptiveMetadata/objectIdentificationWrap/objectDescriptionWrap/objectDescriptionSet';
        foreach ($this->xmlDoc->all(path: $path) as $objectSetNode) {
            $type = $this->xmlDoc->attr($objectSetNode, 'type') ?? '';
            if (
                !$exclude
                || '' === $type
                || !in_array(mb_strtolower($type, 'UTF-8'), $exclude)
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
    protected function getRelatedWorkSetNodes(array $relatedWorkRelType = []): array
    {
        $setList = [];
        $path = 'lido/descriptiveMetadata/objectRelationWrap/relatedWorksWrap/relatedWorkSet';
        foreach ($this->xmlDoc->all(path: $path) as $relatedWorkSetNode) {
            $relType = mb_strtolower(
                $this->xmlDoc->firstValue($relatedWorkSetNode, 'relatedWorkRelType/term'),
                'UTF-8'
            );
            if (!$relatedWorkRelType || in_array($relType, $relatedWorkRelType)) {
                $setList[] = $relatedWorkSetNode;
            }
        }
        return $setList;
    }

    /**
     * Get resource sets
     *
     * @return array Array of resourceSet nodes
     */
    protected function getResourceSetNodes(): array
    {
        return $this->xmlDoc->all(path: 'lido/administrativeMetadata/resourceWrap/resourceSet');
    }

    /**
     * Return identifiers from recordInfoSet.
     *
     * @return array
     */
    protected function getControlNumbers()
    {
        $ids = [];
        $path = 'lido/administrativeMetadata/recordWrap/recordInfoSet/recordInfoID';
        foreach ($this->xmlDoc->all(path: $path) as $recordInfoID) {
            if (null !== ($type = $this->xmlDoc->attr($recordInfoID, 'type'))) {
                $ids[] = "($type)" . $this->xmlDoc->value($recordInfoID);
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
        $path = 'lido/descriptiveMetadata/objectIdentificationWrap/repositoryWrap/repositorySet/workID';
        foreach ($this->xmlDoc->all(path: $path) as $workId) {
            $type = $this->xmlDoc->attr($workId, 'type');
            if ($include && !in_array($type, $include)) {
                continue;
            }
            if ($type && $exclude && !in_array($type, $include)) {
                continue;
            }
            if ('' !== ($identifier = $this->xmlDoc->value($workId))) {
                $result[] = $identifier;
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
        $path = 'lido/descriptiveMetadata/objectIdentificationWrap/repositoryWrap/repositorySet';
        foreach ($this->xmlDoc->all(path: $path) as $set) {
            if ($this->repositoryLocationTypes) {
                $type = mb_strtolower($this->xmlDoc->attr($set, 'type') ?? '', 'UTF-8');
                if (!in_array($type, $this->repositoryLocationTypes)) {
                    continue;
                }
            }
            foreach ($this->xmlDoc->all($set, 'repositoryLocation/namePlaceSet/appellationValue') as $place) {
                if (
                    '' !== ($value = $this->xmlDoc->value($place))
                    && !in_array($this->xmlDoc->attr($place, 'label'), $this->excludedLocationAppellationValueLabels)
                ) {
                    $result[] = $value;
                }
            }
            foreach ($this->xmlDoc->all($set, 'repositoryLocation/partOfPlace') as $part) {
                while ($namePlaceSet = $this->xmlDoc->first($part, 'namePlaceSet')) {
                    if ($appellationValue = $this->xmlDoc->first($namePlaceSet, 'appellationValue')) {
                        if (
                            !in_array(
                                $this->xmlDoc->attr($appellationValue, 'label'),
                                $this->excludedLocationAppellationValueLabels
                            )
                        ) {
                            $result[] = $this->xmlDoc->value($appellationValue);
                        }
                    }
                    $part = $this->xmlDoc->first($part, 'partOfPlace');
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
     * Get primary authors.
     *
     * @return array
     */
    protected function getPrimaryAuthors(): array
    {
        return $this->getActors($this->getMainEvents());
    }

    /**
     * Get secondary authors.
     *
     * @return array
     */
    protected function getSecondaryAuthors(): array
    {
        return $this->secondaryAuthorEvents
            ? $this->getActors($this->getSecondaryAuthorEvents())
            : [];
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
        $path = 'lido/descriptiveMetadata/objectIdentificationWrap/repositoryWrap/repositorySet/workID';
        // Return first non-empty value:
        return $this->xmlDoc->allValues(path: $path)[0] ?? '';
    }

    /**
     * Add hierarchy fields. Must be called after title is present in the array.
     *
     * @param array $data Reference to the target array
     *
     * @return void
     */
    protected function addHierarchyFields(array &$data): void
    {
        if ($this->getDriverParam('indexHierarchies', false)) {
            foreach ($this->getRelatedWorkSetNodes(['is part of']) as $set) {
                if (!($relatedWork = $this->xmlDoc->first($set, 'relatedWork'))) {
                    continue;
                }
                $relatedId = $this->xmlDoc->firstValue($relatedWork, 'object/objectID') ?? '';
                if ('' === $relatedId) {
                    $this->logger->logDebug('Lido', 'Related record ID missing', true);
                    continue;
                }
                $relatedTitle = $this->xmlDoc->firstValue($relatedWork, 'displayObject') ?? '';
                if (!$relatedTitle) {
                    $this->logger->logDebug('Lido', 'Related record title missing', true);
                    continue;
                }

                $type = $this->xmlDoc->firstValue($relatedWork, 'object/objectType/term');
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
                $this->addHierarchyTitles($data);
            }
        }
        // Include hierarchy titles from relatedWorksWrap in allfields:
        foreach ($this->hierarchyFieldsInAllFields as $field) {
            // phpcs:ignore
            /** @psalm-var list<string> */
            $titles = (array)($data[$field] ?? []);
            $data['allfields'] = [
                ...$data['allfields'],
                ...$titles,
            ];
        }
    }

    /**
     * Add hierarchy titles.
     *
     * @param array $data Reference to the target array
     *
     * @return void
     */
    protected function addHierarchyTitles(array &$data): void
    {
        // Note: title_in_hierarchy is only needed if it differs from title.
        if ($this->getDriverParam('addIdToHierarchyTitle', true)) {
            $data['title_in_hierarchy'] = [trim($this->getIdentifier() . ' ' . $data['title'])];
        }
    }

    /**
     * Add additional titles (for downstream usage).
     *
     * @param array $data Reference to the target array
     *
     * @return void
     */
    protected function addAdditionalTitles(array &$data): void
    {
    }

    /**
     * Check if identifier is a valid ISBN
     *
     * @param string $identifier Identifier to check
     *
     * @return string ISBN without dashes and namespaces, or empty string
     */
    protected function checkISBN($identifier = ''): string
    {
        $identifier = str_replace('-', '', trim($identifier));
        if ('' !== $identifier && preg_match('{^(URN:ISBN:)?([0-9]{9,12}[0-9xX])}', $identifier, $matches)) {
            return $matches[2];
        }
        return '';
    }

    /**
     * Get author sort field.
     *
     * @return string
     */
    protected function getAuthorSort(): string
    {
        $authors = $this->getPrimaryAuthors();
        return $authors[0] ?? '';
    }

    /**
     * Get topics.
     *
     * @return array
     */
    protected function getTopics(): array
    {
        return $this->getSubjectTerms();
    }

    /**
     * Get topic facet fields.
     *
     * @return array
     */
    protected function getTopicFacets(): array
    {
        return $this->getSubjectTerms();
    }

    /**
     * Get all era topics.
     *
     * @return array<int, string>
     */
    protected function getEras(): array
    {
        return $this->getDisplayDates();
    }

    /**
     * Get era facet fields.
     *
     * @return array<int, string> Topics
     */
    protected function getEraFacets(): array
    {
        return $this->getDisplayDates();
    }

    /**
     * Get all geographic topics.
     *
     * @return array<int, string>
     */
    protected function getGeographicTopics()
    {
        return $this->getDisplayPlaces();
    }

    /**
     * Get geographic facet fields.
     *
     * @return array<int, string> Topics
     */
    protected function getGeographicFacets()
    {
        // Index the other place forms only to facets:
        return [
            ...$this->getDisplayPlaces(),
            ...$this->getSubjectPlaces(),
        ];
    }

    /**
     * Get thumbnail URL.
     *
     * @return string
     */
    protected function getThumbnailUrl(): string
    {
        // thumbnail field is not multivalued, so just store take the first one:
        $urls = $this->getUrls();
        return $urls[0] ?? '';
    }
}

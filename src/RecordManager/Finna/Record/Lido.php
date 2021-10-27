<?php
/**
 * Lido record class
 *
 * PHP version 7
 *
 * Copyright (C) The National Library of Finland 2012-2020.
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
namespace RecordManager\Finna\Record;

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
class Lido extends \RecordManager\Base\Record\Lido
{
    /**
     * Main event name reflecting the terminology in the particular LIDO records.
     *
     * @var string
     */
    protected $mainEvent = 'valmistus';

    /**
     * Usage place event name reflecting the terminology in the particular LIDO
     * records.
     *
     * @var string
     */
    protected $usagePlaceEvent = 'käyttö';

    /**
     * Related work relation types reflecting the terminology in the particular LIDO
     * records.
     *
     * @var string
     */
    protected $relatedWorkRelationTypes = [
        'Kokoelma', 'kuuluu kokoelmaan', 'kokoelma'
    ];

    /**
     * Related work relation types reflecting the terminology in the particular LIDO
     * records.
     *
     * @var string
     */
    protected $relatedWorkRelationTypesExtended = [
        'Kokoelma', 'kokoelma', 'kuuluu kokoelmaan', 'Arkisto', 'arkisto',
        'Alakokoelma', 'alakokoelma', 'Erityiskokoelma', 'erityiskokoelma',
        'Hankintaerä', 'hankintaerä'
    ];

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
        $data = parent::toSolrArray($db);

        $data['allfields'][] = $this->getRecordSourceOrganization();

        $data['identifier'] = $this->getIdentifier();

        // This is just the display measurements! There's also the more granular
        // form, which could be useful for some interesting things eg. sorting by
        // size
        $data['measurements'] = $this->getMeasurements();

        $data['culture'] = $this->getCulture();
        $data['rights'] = $this->getRights();

        // Handle sources that contain multiple organisations properly
        if ($this->getDriverParam('institutionInBuilding', false)) {
            $institutionParts = explode('/', $data['institution']);
            $data['building'] = reset($institutionParts);
        }
        if ($data['collection']
            && $this->getDriverParam('collectionInBuilding', false)
        ) {
            if (isset($data['building']) && $data['building']) {
                $data['building'] .= '/' . $data['collection'];
            } else {
                $data['building'] = $data['collection'];
            }
        }

        // REMOVE THIS ONCE TUUSULA IS FIXED
        // sometimes there are multiple subjects in one element
        // separated with commas like "foo, bar, baz" (Tuusula)
        $topic = [];
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

        $data['artist_str_mv'] = $this->getActors('valmistus', 'taiteilija', false);
        $data['photographer_str_mv']
            = $this->getActors('valmistus', 'valokuvaaja', false);
        $data['finder_str_mv']
            = $this->getActors('löytyminen', 'löytäjä', false);
        $data['manufacturer_str_mv']
            = $this->getActors('valmistus', 'valmistaja', false);
        $data['designer_str_mv']
            = $this->getActors('suunnittelu', 'suunnittelija', false);

        // Keep classification_str_mv for backward-compatibility for now
        $data['classification_txt_mv'] = $data['classification_str_mv']
            = $this->getClassifications();
        $data['exhibition_str_mv'] = $this->getEventNames('näyttely');

        foreach ($this->getSubjectDateRanges() as $range) {
            if (!isset($data['main_date_str'])) {
                $data['main_date_str'] = $this->metadataUtils
                    ->extractYear($range[0]);
                $data['main_date'] = $this->validateDate($range[0]);
            }
            $data['search_daterange_mv'][]
                = $this->metadataUtils->dateRangeToStr($range);
        }

        $daterange = $this->getDateRange('valmistus');
        if ($daterange) {
            if (!isset($data['main_date_str'])) {
                $data['main_date_str'] = $this->metadataUtils
                    ->extractYear($daterange[0]);
                $data['main_date'] = $this->validateDate($daterange[0]);
            }
            $data['search_daterange_mv'][]
                = $data['creation_daterange']
                    = $this->metadataUtils->dateRangeToStr($daterange);
        } else {
            $dateSources = [
                'suunnittelu' => 'design', 'tuotanto' => 'production',
                'kuvaus' => 'photography'
            ];
            foreach ($dateSources as $dateSource => $field) {
                $daterange = $this->getDateRange($dateSource);
                if ($daterange) {
                    $data[$field . '_daterange']
                        = $this->metadataUtils->dateRangeToStr($daterange);
                    if (!isset($data['search_daterange_mv'])) {
                        $data['search_daterange_mv'][]
                            = $data[$field . '_daterange'];
                    }
                    if (!isset($data['main_date_str'])) {
                        $data['main_date_str']
                            = $this->metadataUtils->extractYear($daterange[0]);
                        $data['main_date'] = $this->validateDate($daterange[0]);
                    }
                }
            }
        }
        if ($range = $this->getDateRange('käyttö')) {
            $data['use_daterange'] = $this->metadataUtils->dateRangeToStr($range);
        }
        if ($range = $this->getDateRange('löytyminen')) {
            $data['finding_daterange'] = $this->metadataUtils
                ->dateRangeToStr($range);
        }

        $data['source_str_mv'] = $this->source;
        $data['datasource_str_mv'] = $this->source;

        if ($this->getURLs()) {
            $data['online_boolean'] = true;
            $data['online_str_mv'] = $this->source;
            // Mark everything free until we know better
            $data['free_online_boolean'] = true;
            $data['free_online_str_mv'] = $this->source;
            if ($this->hasHiResImages()) {
                $data['hires_image_boolean'] = true;
                $data['hires_image_str_mv'] = $this->source;
            }
        }

        $data['location_geo'] = $this->getEventPlaceLocations();
        $data['center_coords']
            = $this->metadataUtils->getCenterCoordinates($data['location_geo']);

        // Usage rights
        if ($rights = $this->getUsageRights()) {
            $data['usage_rights_str_mv'] = $rights;
        }

        $data['author_facet'] = $this->getActors($this->mainEvent, null, false);

        $data['format_ext_str_mv'] = $this->getObjectWorkTypes();

        $data['hierarchy_parent_title']
            = $this->getRelatedWorks($this->relatedWorkRelationTypesExtended);

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
        return parent::getTitle($forFiling, $lang, ['provenienssi']);
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
        // Subject places
        $subjectLocations = [];
        foreach ($this->getSubjectNodes() as $subject) {
            // Try first to find non-hierarchical street address and city.
            // E.g. Musketti.
            $mainPlace = '';
            $subLocation = '';
            foreach ($subject->subjectPlace as $subjectPlace) {
                foreach ($subjectPlace->place as $place) {
                    if (!isset($place->namePlaceSet->appellationValue)
                        || !isset($place->placeClassification->term)
                    ) {
                        continue;
                    }
                    $classification = strtolower($place->placeClassification->term);
                    if (strstr($classification, 'kunta') !== false
                        || strstr($classification, 'kaupunki') !== false
                        || strstr($classification, 'kylä') !== false
                    ) {
                        $mainPlace .= ' '
                            . (string)$place->namePlaceSet->appellationValue;
                    } elseif (strstr($classification, 'katuosoite') !== false
                        || strstr($classification, 'kartano') !== false
                        || strstr($classification, 'tila') !== false
                        || strstr($classification, 'talo') !== false
                        || strstr($classification, 'rakennus') !== false
                        || strstr($classification, 'alue') !== false
                    ) {
                        $subLocation .= ' ' . (string)$place->namePlaceSet
                            ->appellationValue;
                    }
                }
            }
            if ('' !== $mainPlace && '' !== $subLocation) {
                $subjectLocations = array_merge(
                    $subjectLocations,
                    $this->splitAddresses(trim($mainPlace), trim($subLocation))
                );
                continue;
            }
            // Handle a hierarchical place
            foreach ($subject->subjectPlace as $subjectPlace) {
                foreach ($subjectPlace->place as $place) {
                    if ($place->namePlaceSet->appellationValue) {
                        $mainPlace
                            = (string)$place->namePlaceSet->appellationValue;
                        $subLocation = $this->getSubLocation($place);
                        if ($mainPlace && !$subLocation) {
                            $subjectLocations[] = $mainPlace;
                        } else {
                            foreach (preg_split('/( tai |\. )/', $subLocation)
                                as $subPart
                            ) {
                                $subjectLocations[] = "$mainPlace $subPart";
                            }
                        }
                    }
                }
            }
        }

        $subjectLocations = array_map(
            function ($s) {
                return rtrim($s, ',. ');
            },
            $subjectLocations
        );

        // Event places
        $locations = [];
        foreach ([$this->mainEvent, $this->usagePlaceEvent] as $event) {
            foreach ($this->getEventNodes($event) as $eventNode) {
                // If there is already gml in the record, don't return anything for
                // geocoding
                if (!empty($eventNode->eventPlace->place->gml)) {
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
                        $locations = array_merge(
                            $locations,
                            $this->splitAddresses($mainPlace, $subLocation)
                        );
                    }
                } elseif (!empty($eventNode->eventPlace->place->partOfPlace)) {
                    // Flat part of place structure (e.g. Musketti)
                    $haveStreet = false;
                    foreach ($eventNode->eventPlace->place->partOfPlace as $part) {
                        if (isset($part->placeClassification->term)
                            && $part->placeClassification->term == 'katuosoite'
                            && !empty($part->namePlaceSet->appellationValue)
                        ) {
                            $haveStreet = true;
                            break;
                        }
                    }
                    $parts = [];
                    foreach ($eventNode->eventPlace->place->partOfPlace as $part) {
                        if ($haveStreet && isset($part->placeClassification->term)
                            && ($part->placeClassification->term == 'kaupunginosa'
                            || $part->placeClassification->term == 'rakennus')
                        ) {
                            continue;
                        }
                        if (!empty($part->namePlaceSet->appellationValue)) {
                            $parts[] = (string)$part->namePlaceSet->appellationValue;
                        }
                    }
                    $locations[] = implode(' ', $parts);
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

        $accepted = [];
        foreach ($locations as $location) {
            if (str_word_count($location) == 1) {
                foreach ($subjectLocations as $subjectLocation) {
                    if (strncmp($subjectLocation, $location, strlen($location)) == 0
                    ) {
                        continue 2;
                    }
                }
            }
            $accepted[] = $location;
        }

        return [
            'primary' => $this->processLocations($subjectLocations),
            'secondary' => $this->processLocations($accepted)
        ];
    }

    /**
     * Return main author (format: Last, First)
     *
     * @return string
     */
    public function getMainAuthor()
    {
        $authors = $this->getActors($this->mainEvent, null, false);
        return $authors ? $authors[0] : '';
    }

    /**
     * Process an array of locations
     *
     * @param array $locations Location strings
     *
     * @return array
     */
    protected function processLocations($locations)
    {
        $result = [];
        // Try to split address lists like "Helsinki, Kalevankatu 17, 19" to separate
        // entries
        foreach ($locations as $location) {
            if (preg_match('/(.+?) \d+, *\d+/', $location, $bodyMatches)
                && preg_match_all('/ (\d+)(,|$)/', $location, $matches)
            ) {
                $body = $bodyMatches[1];
                foreach ($matches[1] as $match) {
                    $result[] = "$body $match";
                }
            } else {
                $result[] = $location;
            }
        }
        // Try to add versions with additional notes like
        // "Helsinki Uudenmaankatu 31, katurakennus" removed, but avoid changing e.g.
        // "Uusimaa, Helsinki, Malmi"
        foreach ($result as $item) {
            if (str_word_count($item) > 2 && substr_count($item, ',') == 1
                && preg_match('/(.*[^\s]+\s+\d+),/', $item, $matches)
            ) {
                $result[] = $matches[1];
            }
        }

        // Remove stuff in parenthesis
        $result = array_filter($result);
        $result = array_map(
            function ($s) {
                $s = preg_replace('/\(.*/', '', $s);
                return trim($this->metadataUtils->stripTrailingPunctuation($s));
            },
            $result
        );
        $result = array_unique($result);

        return $result;
    }

    /**
     * Try to split logical sublocation parts
     *
     * @param string $mainPlace   Main location
     * @param string $subLocation Sublocation(s)
     *
     * @return array
     */
    protected function splitAddresses($mainPlace, $subLocation)
    {
        $locations = [];
        if (preg_match('/[^\s]+(\,(?!\s*\d)|\.|\s*\&)\s+[^\s]+/', $subLocation)) {
            foreach (preg_split('/(\,(?!\s*\d)|\.|\s*\&)\s+/', $subLocation) as $sub
            ) {
                $locations[] = "$mainPlace $sub";
            }
        } else {
            $locations[] = "$mainPlace $subLocation";
        }
        return $locations;
    }

    /**
     * Return usage rights if any
     *
     * @return array ['restricted'] or a more specific id if restricted,
     * empty array otherwise
     */
    protected function getUsageRights()
    {
        $hasValue = isset(
            $this->doc->lido->administrativeMetadata->resourceWrap->resourceSet
        );
        if (!$hasValue) {
            return [];
        }

        $result = [];
        foreach ($this->doc->lido->administrativeMetadata->resourceWrap->resourceSet
            as $set
        ) {
            if (isset($set->rightsResource->rightsType->conceptID)) {
                $result[] = (string)$set->rightsResource->rightsType->conceptID;
            } else {
                $result[] = 'restricted';
            }
        }
        return $result;
    }

    /**
     * Return materials associated with the object. Materials are contained inside
     * events, and the 'valmistus' (creation) event contains all the materials of the
     * object. Either the individual materials are retrieved, or the display
     * materials element is retrieved in case of failure.
     *
     * @param string $eventType Which event to use
     *
     * @link   http://www.lido-schema.org/schema/v1.0/lido-v1.0-schema-listing.html
     * #materialsTechSetComplexType
     * @return array
     */
    protected function getEventMaterials($eventType)
    {
        $materials = parent::getEventMaterials($eventType);

        if (!empty($materials)) {
            return $materials;
        }

        // If there are no individually listed, straightforwardly indexable materials
        // we can use the displayMaterialsTech field, which is usually meant for
        // display only. However, it's possible to extract the different materials
        // from the display field. Some CMS have only one field for materials so this
        // is the only way to index their materials.

        $material = '';
        foreach ($this->getEventNodes($eventType) as $node) {
            if (!empty($node->eventMaterialsTech->displayMaterialsTech)) {
                $material = (string)$node->eventMaterialsTech->displayMaterialsTech;
                break;
            }
        }
        if (empty($material)) {
            return [];
        }

        $exploded = explode(';', str_replace(',', ';', $material));
        $materials = [];
        foreach ($exploded as $explodedMaterial) {
            $materials[] = trim($explodedMaterial);
        }
        return $materials;
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
        $descriptionWrapDescriptions = [];
        foreach ($this->getObjectDescriptionSetNodes(['provenienssi'])
            as $set
        ) {
            foreach ($set->descriptiveNoteValue as $descriptiveNoteValue) {
                $descriptionWrapDescriptions[] = (string)$descriptiveNoteValue;
            }
        }
        if ($descriptionWrapDescriptions
            && $this->getTitle() == implode('; ', $descriptionWrapDescriptions)
        ) {
            // We have the description already in the title, don't repeat
            $descriptionWrapDescriptions = [];
        }

        // Also read in "description of subject" which contains data suitable for
        // this field
        $title = str_replace([',', ';'], ' ', $this->getTitle());
        if ($this->getDriverParam('splitTitles', false)) {
            $titlePart = $this->metadataUtils->splitTitle($title);
            if ($titlePart) {
                $title = $titlePart;
            }
        }
        $title = str_replace([',', ';'], ' ', $title);
        $subjectDescriptions = [];
        foreach ($this->getSubjectSetNodes() as $set) {
            $subject = $set->displaySubject;
            $label = $subject['label'];
            $checkTitle
                = trim(str_replace([',', ';'], ' ', (string)$subject)) != $title;
            if ((null === $label || 'aihe' === mb_strtolower($label, 'UTF-8'))
                && $checkTitle
            ) {
                $subjectDescriptions[] = (string)$set->displaySubject;
            }
        }

        return trim(
            implode(
                ' ',
                array_unique(
                    array_merge($subjectDescriptions, $descriptionWrapDescriptions)
                )
            )
        );
    }

    /**
     * Return subjects associated with object.
     *
     * @param string[] $exclude List of subject types to exclude (defaults to 'aihe'
     *                          and 'iconclass' since they don't contain human
     *                          readable terms)
     *
     * @link   http://www.lido-schema.org/schema/v1.0/lido-v1.0-schema-listing.html
     * #subjectComplexType
     * @return string
     */
    protected function getSubjectTerms($exclude = ['aihe', 'iconclass'])
    {
        return parent::getSubjectTerms($exclude);
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
     * Return the date range associated with specified event
     *
     * @param string $event Which event to use (omit to scan all events)
     *
     * @return null|string[] Null if parsing failed, two ISO 8601 dates otherwise
     */
    protected function getDateRange($event = null)
    {
        $startDate = '';
        $endDate = '';
        $displayDate = '';
        $periodName = '';
        foreach ($this->getEventNodes($event) as $eventNode) {
            if (!$startDate
                && !empty($eventNode->eventDate->date->earliestDate)
                && !empty($eventNode->eventDate->date->latestDate)
            ) {
                $startDate = (string)$eventNode->eventDate->date->earliestDate;
                $endDate = (string)$eventNode->eventDate->date->latestDate;
                break;
            }
            if (!$displayDate && !empty($eventNode->eventDate->displayDate)) {
                $displayDate = (string)$eventNode->eventDate->displayDate;
            }
            if (!$periodName && !empty($eventNode->periodName->term)) {
                $periodName = (string)$eventNode->periodName->term;
            }
        }

        return $this->processDateRangeValues(
            $startDate,
            $endDate,
            $displayDate,
            $periodName
        );
    }

    /**
     * Return the date ranges associated with subjects
     *
     * @return array[] Array of two ISO 8601 dates
     */
    protected function getSubjectDateRanges()
    {
        $ranges = [];
        foreach ($this->getSubjectNodes() as $node) {
            $startDate = '';
            $endDate = '';
            $displayDate = '';
            if (!empty($node->subjectDate->date->earliestDate)
                && !empty($node->subjectDate->date->latestDate)
            ) {
                $startDate = (string)$node->subjectDate->date->earliestDate;
                $endDate = (string)$node->subjectDate->date->latestDate;
            }
            if (!empty($node->subjectDate->displayDate)) {
                $displayDate = (string)$node->subjectDate->displayDate;
            }
            $range = $this->processDateRangeValues(
                $startDate,
                $endDate,
                $displayDate,
                ''
            );
            if ($range) {
                $ranges[] = $range;
            }
        }
        return $ranges;
    }

    /**
     * Process extracted date values and create best possible date range
     *
     * @param string $startDate   Start date
     * @param string $endDate     End date
     * @param string $displayDate Display date
     * @param string $periodName  Period name
     *
     * @return null|string[] Null if parsing failed, two ISO 8601 dates otherwise
     */
    protected function processDateRangeValues(
        $startDate,
        $endDate,
        $displayDate,
        $periodName
    ) {
        if ($startDate) {
            if ($endDate < $startDate) {
                $this->logger->logDebug(
                    'Lido',
                    "Invalid date range {$startDate} - {$endDate}, record "
                    . "{$this->source}." . $this->getID()
                );
                $endDate = $startDate;
                $this->storeWarning('invalid date range');
            }
            $startDate = $this->completeDate($startDate);
            $endDate = $this->completeDate($endDate, true);
            if ($startDate === null || $endDate === null) {
                return null;
            }

            return [$startDate, $endDate];
        }

        if ($displayDate) {
            return $this->parseDateRange($displayDate);
        }
        if ($periodName) {
            return $this->parseDateRange($periodName);
        }
        return null;
    }

    /**
     * Complete a partial date
     *
     * @param string $date Date string
     * @param bool   $end  Whether $date represents the end of a date range
     *
     * @return null|string
     */
    protected function completeDate($date, $end = false)
    {
        $negative = false;
        if (substr($date, 0, 1) == '-') {
            $negative = true;
            $date = substr($date, 1);
        }

        if (!$end) {
            if (strlen($date) == 1) {
                $date = '000' . $date . '-01-01T00:00:00Z';
            } elseif (strlen($date) == 2) {
                $date = '00' . $date . '-01-01T00:00:00Z';
            } elseif (strlen($date) == 3) {
                $date = '0' . $date . '-01-01T00:00:00Z';
            } elseif (strlen($date) == 4) {
                $date = $date . '-01-01T00:00:00Z';
            } elseif (strlen($date) == 7) {
                $date = $date . '-01T00:00:00Z';
            } elseif (strlen($date) == 10) {
                $date = $date . 'T00:00:00Z';
            }
        } else {
            if (strlen($date) == 1) {
                $date = '00' . $date . '-12-31T23:59:59Z';
            } elseif (strlen($date) == 2) {
                $date = '00' . $date . '-12-31T23:59:59Z';
            } elseif (strlen($date) == 3) {
                $date = '0' . $date . '-12-31T23:59:59Z';
            } elseif (strlen($date) == 4) {
                $date = $date . '-12-31T23:59:59Z';
            } elseif (strlen($date) == 7) {
                try {
                    $d = new \DateTime($date . '-01');
                } catch (\Exception $e) {
                    $this->logger->logDebug(
                        'Lido',
                        "Failed to parse date $date, record {$this->source}."
                        . $this->getID()
                    );
                    $this->storeWarning('invalid date');
                    return null;
                }
                $date = $d->format('Y-m-t') . 'T23:59:59Z';
            } elseif (strlen($date) == 10) {
                $date = $date . 'T23:59:59Z';
            }
        }
        if ($negative) {
            $date = "-$date";
        }

        return $date;
    }

    /**
     * Return the event place locations associated with specified event
     *
     * @param string $event Which event to use (omit to scan all events)
     *
     * @return array WKT
     */
    protected function getEventPlaceLocations($event = null)
    {
        $results = [];
        foreach ($this->getEventNodes($event) as $event) {
            foreach ($event->eventPlace as $eventPlace) {
                foreach ($eventPlace->place as $place) {
                    if (!empty($place->gml)) {
                        if ($wkt = $this->convertGmlToWkt($place->gml)) {
                            $results[] = $wkt;
                        }
                    }
                }
            }
        }
        return $results;
    }

    /**
     * Convert SimpleXML GML node to a WKT string
     *
     * This assumes WSG 84
     *
     * @param \SimpleXMLElement $gml GML Node
     *
     * @return string WKT
     */
    protected function convertGmlToWkt($gml)
    {
        if (!empty($gml->Polygon)) {
            if (empty($gml->Polygon->outerBoundaryIs->LinearRing->coordinates)) {
                $this->logger->logDebug(
                    'Lido',
                    "GML Polygon missing outer boundary, record "
                        . "{$this->source}." . $this->getID()
                );
                $this->storeWarning('gml polygon missing outer boundary');
                return '';
            }
            $outerBoundary
                = $this->swapCoordinates(
                    (string)$gml->Polygon->outerBoundaryIs->LinearRing->coordinates
                );
            $innerBoundary
                = !empty($gml->Polygon->innerBoundaryIs->LinearRing->coordinates)
                ? $this->swapCoordinates(
                    (string)$gml->Polygon->innerBoundaryIs->LinearRing->coordinates
                ) : '';

            return $innerBoundary
                ? "POLYGON (($outerBoundary),($innerBoundary))"
                : "POLYGON (($outerBoundary))";
        }

        if (!empty($gml->LineString)) {
            if (empty($gml->LineString->coordinates)) {
                $this->logger->logDebug(
                    'Lido',
                    "GML LineString missing coordinates, record "
                        . "{$this->source}." . $this->getID()
                );
                $this->storeWarning('gml linestring missing coordinates');
                return '';
            }
            $coordinates = $this->swapCoordinates(
                (string)$gml->LineString->coordinates
            );
            return "LINESTRING ($coordinates)";
        }

        if (!empty($gml->Point)) {
            $lat = null;
            $lon = null;
            if (!empty($gml->Point->pos)) {
                $coordinates = trim((string)$gml->Point->pos);
                if (!$coordinates) {
                    $this->logger->logDebug(
                        'Lido',
                        "Empty pos in GML point, record "
                            . "{$this->source}." . $this->getID()
                    );
                    $this->storeWarning('empty gml pos in point');
                }
                $latlon = explode(' ', (string)$coordinates, 2);
                if (isset($latlon[1])) {
                    $lat = $latlon[0];
                    $lon = $latlon[1];
                }
            } elseif (isset($gml->Point->coordinates)) {
                $coordinates = trim((string)$gml->Point->coordinates);
                if (!$coordinates) {
                    $this->logger->logDebug(
                        'Lido',
                        "Empty coordinates in GML point, record "
                            . "{$this->source}." . $this->getID()
                    );
                    $this->storeWarning('empty gml coordinates in point');
                    return '';
                }
                $latlon = explode(',', (string)$coordinates, 2);
                if (isset($latlon[1])) {
                    $lat = $latlon[0];
                    $lon = $latlon[1];
                }
            }
            if (null === $lat || null === $lon) {
                $this->logger->logDebug(
                    'Lido',
                    "GML Point does not contain pos or coordinates, record "
                        . "{$this->source}." . $this->getID()
                );
                $this->storeWarning('gml point missing data');
                return '';
            }
            $lat = trim($lat);
            $lon = trim($lon);
            if ('' === $lat || '' === $lon || $lat < -90 || $lat > 90 || $lon < -180
                || $lon > 180
            ) {
                $this->logger->logDebug(
                    'Lido',
                    "Discarding invalid coordinates '$lat,$lon', record "
                        . "{$this->source}." . $this->getID()
                );
                $this->storeWarning('invalid gml coordinates');
                return '';
            }
            return "POINT ($lon $lat)";
        }

        return '';
    }

    /**
     * Convert GML coordinates to WKT coordinates
     *
     * @param string $coordinates GML coordinates
     *
     * @return string WKT coordinates
     */
    protected function swapCoordinates($coordinates)
    {
        $result = [];
        foreach (preg_split('/(?=\/\d)\s(?=\/\d)/', $coordinates) as $coordinate) {
            [$lat, $lon] = explode(',', $coordinate, 2);
            $lat = trim($lat);
            $lon = trim($lon);
            $result[] = "$lon $lat";
        }
        return implode(',', $result);
    }

    /**
     * Attempt to parse a string (in finnish) into a normalized date range.
     *
     * TODO: complicated normalizations like this should preferably reside within
     * their own, separate component which should allow modification of the algorithm
     * by methods other than hard-coding rules into source.
     *
     * @param string $input Date range
     *
     * @return array Two ISO 8601 dates
     */
    protected function parseDateRange($input)
    {
        $input = trim(strtolower($input));

        $dateMappings = [
            'kivikausi' => ['-8600-01-01T00:00:00Z', '-1501-12-31T23:59:59Z'],
            'pronssikausi'
                => ['-1500-01-01T00:00:00Z', '-0501-12-31T23:59:59Z'],
            'rautakausi' => ['-0500-01-01T00:00:00Z','1299-12-31T23:59:59Z'],
            'keskiaika' => ['1300-01-01T00:00:00Z','1550-12-31T23:59:59Z'],
            'ajoittamaton' => null,
            'tuntematon' => null
        ];

        foreach ($dateMappings as $str => $value) {
            if (strstr($input, $str)) {
                return $value;
            }
        }

        $k = [
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
        ];

        $imprecise = false;

        [$input] = explode(',', $input, 2);

        if (true
            && preg_match(
                //@codingStandardsIgnoreLine
                '/(\d\d?)\s*.\s*(\d\d?)\s*.\s*(\d\d\d\d)\s*-\s*(\d\d?)\s*.\s*(\d\d?)\s*.\s*(\d\d\d\d)/',
                $input,
                $matches
            ) > 0
        ) {
            $startDate = sprintf(
                '%04d-%02d-%02dT00:00:00Z',
                $matches[3],
                $matches[2],
                $matches[1]
            );
            $endDate = sprintf(
                '%04d-%02d-%02dT23:59:59Z',
                $matches[6],
                $matches[5],
                $matches[4]
            );
            $noprocess = true;
        } elseif (true
            && preg_match(
                '/(\d\d\d\d)\s*-\s*(\d\d?)\s*.\s*(\d\d?)\s*.\s*(\d\d\d\d)/',
                $input,
                $matches
            ) > 0
        ) {
            $startDate = sprintf('%04d-01-01T00:00:00Z', $matches[1]);
            $endDate = sprintf(
                '%04d-%02d-%02dT23:59:59Z',
                $matches[4],
                $matches[3],
                $matches[2]
            );
            $noprocess = true;
        } elseif (true
            && preg_match(
                '/(\d\d?)\s*.\s*(\d\d?)\s*.\s*(\d\d\d\d)\s*-\s*(\d\d\d\d)/',
                $input,
                $matches
            ) > 0
        ) {
            $startDate = sprintf(
                '%04d-%02d-%02dT00:00:00Z',
                $matches[3],
                $matches[2],
                $matches[1]
            );
            $endDate = sprintf('%04d-12-31T23:59:59Z', $matches[4]);
            $noprocess = true;
        } elseif (true
            && preg_match(
                //@codingStandardsIgnoreLine
                '/(\d\d\d\d)\s*.\s*(\d\d?)\s*.\s*(\d\d?)\s*-\s*(\d\d\d\d)\s*.\s*(\d\d?)\s*.\s*(\d\d?)/',
                $input,
                $matches
            ) > 0
        ) {
            $startDate = sprintf(
                '%04d-%02d-%02dT00:00:00Z',
                $matches[1],
                $matches[2],
                $matches[3]
            );
            $endDate = sprintf(
                '%04d-%02d-%02dT23:59:59Z',
                $matches[4],
                $matches[5],
                $matches[6]
            );
            $noprocess = true;
        } elseif (true
            && preg_match(
                '/(\d\d\d\d)(\d\d?)(\d\d?)\s*-\s*(\d\d\d\d)(\d\d?)(\d\d?)/',
                $input,
                $matches
            ) > 0
        ) {
            $startDate = sprintf(
                '%04d-%02d-%02dT00:00:00Z',
                $matches[1],
                $matches[2],
                $matches[3]
            );
            $endDate = sprintf(
                '%04d-%02d-%02dT23:59:59Z',
                $matches[4],
                $matches[5],
                $matches[6]
            );
            $noprocess = true;
        } elseif (true
            && preg_match(
                '/(\d\d\d\d)(\d\d?)\s*-\s*(\d\d\d\d)(\d\d?)/',
                $input,
                $matches
            ) > 0
        ) {
            $startDate = sprintf('%04d-%02d-01T00:00:00Z', $matches[1], $matches[2]);
            $endDate = sprintf('%04d-%02d-01', $matches[3], $matches[4]);
            try {
                $d = new \DateTime($endDate);
            } catch (\Exception $e) {
                $this->logger->logDebug(
                    'Lido',
                    "Failed to parse date $endDate, record {$this->source}."
                        . $this->getID()
                );
                $this->storeWarning('invalid end date');
                return null;
            }
            $endDate = $d->format('Y-m-t') . 'T23:59:59Z';
            $noprocess = true;
        } elseif (preg_match('/(\d\d\d\d)-(\d\d?)-(\d\d?)/', $input, $matches) > 0) {
            // This one needs to be before the lazy matcher below
            $year = $matches[1];
            $month = sprintf('%02d', $matches[2]);
            $day = sprintf('%02d', $matches[3]);
            $startDate = $year . '-' . $month . '-' . $day . 'T00:00:00Z';
            $endDate = $year . '-' . $month . '-' . $day . 'T23:59:59Z';
            $noprocess = true;
        } elseif (true
            && preg_match(
                '/(\d\d\d\d)\s*-\s*(\d\d\d\d)\s*(-luvun|-l)\s+(loppupuoli|loppu)/',
                $input,
                $matches
            ) > 0
        ) {
            $startDate = $matches[1];
            $endDate = $matches[2];
            if ($endDate % 100 == 0) {
                // Century
                $endDate += 99;
            } elseif ($endDate % 10 == 0) {
                // Decade
                $endDate += 9;
            }
        } elseif (true
            && preg_match(
                '/(\d?\d?\d\d)\s*(-|~)\s*(\d?\d?\d\d)\s*(-luku|-l)?\s*(\(?\?\)?)?/',
                $input,
                $matches
            ) > 0
        ) {
            // 1940-1960-luku
            // 1940-1960-l
            // 1940-60-l
            // 1930 - 1970-luku
            // 30-40-luku
            $startDate = $matches[1];
            $endDate = $matches[3];

            if (isset($matches[4])) {
                if ($endDate % 10 == 0) {
                    $endDate += 9;
                }
            }

            $imprecise = isset($matches[5]);
        } elseif (true
            && preg_match(
                //@codingStandardsIgnoreLine
                '/(\d?\d?\d\d)\s+(tammikuu|helmikuu|maaliskuu|huhtikuu|toukokuu|kesäkuu|heinäkuu|elokuu|syyskuu|lokakuu|marraskuu|joulukuu)/',
                $input,
                $matches
            ) > 0
        ) {
            $year = $matches[1];
            $month = $k[$matches[2]];
            $startDate = $year . '-' . $month . '-01T00:00:00Z';
            $endDate = $year . '-' . $month . '-01';
            try {
                $d = new \DateTime($endDate);
                $endDate = $d->format('Y-m-t') . 'T23:59:59Z';
            } catch (\Exception $e) {
                $this->logger->logDebug(
                    'Lido',
                    "Failed to parse date $endDate, record {$this->source}."
                        . $this->getID()
                );
                $this->storeWarning('invalid end date');
                return null;
            }
            $noprocess = true;
        } elseif (preg_match('/(\d\d\d\d)(\d\d)(\d\d)/', $input, $matches) > 0) {
            $year = $matches[1];
            $month = sprintf('%02d', $matches[2]);
            $day = sprintf('%02d', $matches[3]);
            $startDate = $year . '-' . $month . '-' . $day . 'T00:00:00Z';
            $endDate = $year . '-' . $month . '-' . $day . 'T23:59:59Z';
            $noprocess = true;
        } elseif (preg_match('/(\d\d\d\d)(\d\d)/', $input, $matches) > 0) {
            $year = $matches[1];
            $month = sprintf('%02d', $matches[2]);
            $startDate = $year . '-' . $month . '-01T00:00:00Z';
            $endDate = $year . '-' . $month . '-01';
            try {
                $d = new \DateTime($endDate);
            } catch (\Exception $e) {
                $this->logger->logDebug(
                    'Lido',
                    "Failed to parse date $endDate, record {$this->source}."
                        . $this->getID()
                );
                $this->storeWarning('invalid end date');
                return null;
            }
            $endDate = $d->format('Y-m-t') . 'T23:59:59Z';
            $noprocess = true;
        } elseif (true
            && preg_match(
                '/(\d\d?)\s*\.\s*(\d\d?)\s*\.\s*(\d\d\d\d)/',
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
        } elseif (preg_match('/(\d\d?)\s*\.\s*(\d\d\d\d)/', $input, $matches) > 0) {
            $year = $matches[2];
            $month = sprintf('%02d', $matches[1]);
            $startDate = $year . '-' . $month . '-01' . 'T00:00:00Z';
            $endDate = $year . '-' . $month . '-01';
            try {
                $d = new \DateTime($endDate);
                $endDate = $d->format('Y-m-t') . 'T23:59:59Z';
            } catch (\Exception $e) {
                $this->logger->logDebug(
                    'Lido',
                    "Failed to parse date $endDate, record {$this->source}."
                        . $this->getID()
                );
                $this->storeWarning('invalid end date');
                return null;
            }
            $noprocess = true;
        } elseif (true
            && preg_match(
                //@codingStandardsIgnoreLine
                '/(\d?\d?\d\d)\s*-(luvun|luku)\s+(alkupuolelta|alkupuoli|alku|alusta)/',
                $input,
                $matches
            ) > 0
        ) {
            $year = $matches[1];

            if ($year % 100 == 0) {
                // Century
                $startDate = $year;
                $endDate = $year + 29;
            } elseif ($year % 10 == 0) {
                // Decade
                $startDate = $year;
                $endDate = $year + 3;
            } else {
                // Uhh?
                $startDate = $year;
                $endDate = $year;
            }
        } elseif (true
            && preg_match(
                '/(\d?\d?\d\d)\s*-(luvun|luku)\s+(puoliväli)/',
                $input,
                $matches
            ) > 0
        ) {
            $year = $matches[1];

            if ($year % 100 == 0) {
                // Century
                $startDate = $year + 29;
                $endDate = $year + 70;
            } elseif ($year % 10 == 0) {
                // Decade
                $startDate = $year + 3;
                $endDate = $year + 7;
            } else {
                // Uhh?
                $startDate = $year;
                $endDate = $year;
            }
        } elseif (true
            && preg_match(
                //@codingStandardsIgnoreLine
                '/(\d?\d?\d\d)\s*(-luvun|-l)\s+(loppupuoli|loppu|lopulta|loppupuolelta)/',
                $input,
                $matches
            ) > 0
        ) {
            $year = $matches[1];

            if ($year % 100 == 0) {
                // Century
                $startDate = $year + 70;
                $endDate = $year + 99;
            } elseif ($year % 10 == 0) {
                // Decade
                $startDate = $year + 7;
                $endDate = $year + 9;
            } else {
                $startDate = $year;
                $endDate = $year;
            }
        } elseif (true
            && preg_match(
                '/(-?\d?\d?\d\d)\s*-(luku|luvulta|l)/',
                $input,
                $matches
            ) > 0
        ) {
            $year = $matches[1];
            $startDate = $year;

            if ($year % 100 == 0) {
                $endDate = $year + 99;
            } elseif ($year % 10 == 0) {
                $endDate = $year + 9;
            } else {
                $endDate = $year;
            }
        } elseif (true
            && preg_match(
                '/(\d?\d?\d\d)\s*ekr.?\s*\-\s*(\d?\d?\d\d)\s*ekr.?/',
                $input,
                $matches
            ) > 0
        ) {
            $startDate = -$matches[1];
            $endDate = -$matches[2];
        } elseif (true
            && preg_match(
                '/(\d?\d?\d\d)\s*ekr.?\s*\-\s*(\d?\d?\d\d)\s*jkr.?/',
                $input,
                $matches
            ) > 0
        ) {
            $startDate = -$matches[1];
            $endDate = $matches[2];
        } elseif (preg_match('/(-?\d?\d?\d\d) jälkeen/', $input, $matches) > 0) {
            $year = $matches[1];

            $startDate = $year;
            $endDate = $year + 9;
        } elseif (true
            && preg_match(
                '/(-?\d\d\d\d)\s*-\s*(-?\d\d\d\d)/',
                $input,
                $matches
            ) > 0
        ) {
            $startDate = $matches[1];
            $endDate = $matches[2];
        } elseif (preg_match('/(-?\d{1-4})\s+-\s+(-?\d{1-4})/', $input, $matches) > 0
        ) {
            $startDate = $matches[1];
            $endDate = $matches[2];
        } elseif (preg_match('/(-?\d?\d?\d\d)\s*\?/', $input, $matches) > 0) {
            $year = $matches[1];

            $startDate = $year;
            $endDate = $year;
            $imprecise = true;
        } elseif (preg_match('/(-?\d?\d?\d\d)/', $input, $matches) > 0) {
            $year = $matches[1];

            $startDate = $year;
            $endDate = $year;
        } else {
            return null;
        }

        if ($startDate < 0) {
            $startDate = '-' . substr('0000', 0, 5 - strlen($startDate))
                . substr($startDate, 1);
        } elseif ($startDate == 0) {
            $startDate = '0000';
        }

        if ($endDate < 0) {
            $endDate = '-' . substr('0000', 0, 5 - strlen($endDate))
                . substr($endDate, 1);
        } elseif ($endDate == 0) {
            $endDate = '0000';
        }

        switch (strlen($startDate)) {
        case 1:
            $startDate = "000$startDate";
            break;
        case 2:
            $startDate = "19$startDate";
            break;
        case 3:
            $startDate = "0$startDate";
            break;
        }
        switch (strlen($endDate)) {
        case 1:
            $endDate = "000$endDate";
            break;
        case 2:
            // Take into account possible negative sign
            $endDate = substr($startDate, 0, -2) . $endDate;
            break;
        case 3:
            $endDate = "0$endDate";
            break;
        }

        if ($imprecise) {
            // This is way arbitrary, so disabled for now..
            //$startDate -= 2;
            //$endDate += 2;
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

        $start = $this->metadataUtils->validateISO8601Date($startDate);
        $end = $this->metadataUtils->validateISO8601Date($endDate);
        if ($start === false || $end === false) {
            $this->logger->logDebug(
                'Lido',
                "Invalid date range {$startDate} - {$endDate} parsed from "
                    . "'$input', record {$this->source}." . $this->getID()
            );
            $this->storeWarning('invalid date range');
            if ($start !== false) {
                $endDate = substr($startDate, 0, 4) . '-12-31T23:59:59Z';
            } elseif ($end !== false) {
                $startDate = substr($endDate, 0, 4) . '-01-01T00:00:00Z';
            } else {
                return null;
            }
        } elseif ($start > $end) {
            $this->logger->logDebug(
                'Lido',
                "Invalid date range {$startDate} - {$endDate} parsed from '$input', "
                    . "record {$this->source}." . $this->getID()
            );
            $this->storeWarning('invalid date range');
            $endDate = substr($startDate, 0, 4) . '-12-31T23:59:59Z';
        }

        return [$startDate, $endDate];
    }

    /**
     * Return the classifications.
     *
     * @link   http://www.lido-schema.org/schema/v1.0/lido-v1.0-schema-listing.html
     * #objectClassificationWrap
     * @return array
     */
    protected function getClassifications()
    {
        $empty = empty(
            $this->doc->lido->descriptiveMetadata
                ->objectClassificationWrap->classificationWrap->classification
        );
        if ($empty) {
            return [];
        }
        $results = [];
        foreach ($this->doc->lido->descriptiveMetadata->objectClassificationWrap
            ->classificationWrap->classification as $classification
        ) {
            if (!empty($classification->term)) {
                foreach ($classification->term as $term) {
                    $results[] = (string)$term;
                }
            }
        }
        return $results;
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
        $results = [];
        foreach ($this->getEventNodes($eventType) as $event) {
            if (!empty($event->eventName->appellationValue)) {
                $results[] = (string)$event->eventName->appellationValue;
            }
        }
        return $results;
    }

    /**
     * Return the rights holder legal body name.
     *
     * @link   http://www.lido-schema.org/schema/v1.0/lido-v1.0-schema-listing.html
     * #legalBodyRefComplexType
     * @return string
     */
    protected function getRightsHolderLegalBodyName()
    {
        $empty = empty(
            $this->doc->lido->administrativeMetadata->rightsWorkWrap
                ->rightsWorkSet
        );
        if ($empty) {
            return '';
        }

        foreach ($this->doc->lido->administrativeMetadata->rightsWorkWrap
            ->rightsWorkSet as $set
        ) {
            if (!empty($set->rightsHolder->legalBodyName->appellationValue)) {
                return (string)$set->rightsHolder->legalBodyName->appellationValue;
            }
        }
        return '';
    }

    /**
     * Return the organization name in the recordSource element
     *
     * @return array
     */
    protected function getRecordSourceOrganization()
    {
        $empty = empty(
            $this->doc->lido->administrativeMetadata->recordWrap
                ->recordSource->legalBodyName->appellationValue
        );
        if ($empty) {
            return '';
        }
        return (string)$this->doc->lido->administrativeMetadata->recordWrap
            ->recordSource->legalBodyName->appellationValue;
    }

    /**
     * Return the object types
     *
     * @link   http://www.lido-schema.org/schema/v1.0/lido-v1.0-schema-listing.html
     * #objectWorkTypeWrap
     * @return string|array
     */
    protected function getObjectWorkTypes()
    {
        $result = [$this->getObjectWorkType()];

        // Check for image links and add a work type for images
        $imageTypes = [
            'Kuva', 'Kuva, Valokuva', 'Valokuva', 'dia', 'kuva', 'negatiivi',
            'photograph', 'valoku', 'valokuva', 'valokuvat'
        ];
        $imageResourceTypes = [
            '', 'image_thumb', 'thumb', 'medium', 'image_large', 'large', 'zoomview',
            'image_master', 'image_original'
        ];
        if (empty(array_intersect($imageTypes, (array)$result))) {
            foreach ($this->getResourceSetNodes() as $set) {
                foreach ($set->resourceRepresentation as $node) {
                    if (!empty($node->linkResource)) {
                        $link = trim((string)$node->linkResource);
                        if (!empty($link)) {
                            $attributes = $node->attributes();
                            $type = (string)$attributes->type;
                            if (in_array($type, $imageResourceTypes)) {
                                $result = (array)$result;
                                $result[] = 'Kuva';
                                break;
                            }
                        }
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Return names of actors associated with specified event
     *
     * @param string|array $event        Which events to use (omit to scan all
     *                                   events)
     * @param string|array $role         Which roles to use (omit to scan all roles)
     * @param bool         $includeRoles Whether to include roles
     *
     * @return array
     */
    protected function getActors($event = null, $role = null, $includeRoles = true)
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
                            if ($includeRoles && $actorRole) {
                                $value .= ", $actorRole";
                            }
                            $result[] = $value;
                        }
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Return all related works of the object.
     *
     * @param string[] $relatedWorkRelType Which relation types to use
     *
     * @return array
     */
    protected function getRelatedWorks($relatedWorkRelType)
    {
        $result = [];
        foreach ($this->getRelatedWorkSetNodes($relatedWorkRelType) as $set) {
            if (!empty($set->relatedWork->displayObject)) {
                $result[] = trim((string)$set->relatedWork->displayObject);
            }
        }

        return $result;
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
                return (string)$set->workID;
            }
        }
        return '';
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
                $value = trim((string)$measurements);
                if ($value) {
                    $results[] = $value;
                }
            }
        }
        return $results;
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
                    $results[] = (string)$culture->term;
                }
            }
        }
        return $results;
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
     * Check if the record has links to high resolution images
     *
     * @return bool
     */
    protected function hasHiResImages()
    {
        foreach ($this->getResourceSetNodes() as $set) {
            foreach ($set->resourceRepresentation as $node) {
                if (!empty($node->linkResource)) {
                    $link = trim((string)$node->linkResource);
                    if (!empty($link)) {
                        $attributes = $node->attributes();
                        $type = (string)$attributes->type;
                        if ('image_original' === $type || 'image_master' === $type
                        ) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }
}

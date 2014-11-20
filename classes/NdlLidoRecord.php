<?php
/**
 * NdlLidoRecord Class
 *
 * PHP version 5
 *
 * Copyright (C) The National Library of Finland 2012-2014.
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
     * @param string $data     Metadata
     * @param string $oaiID    Record ID received from OAI-PMH (or empty string for file import)
     * @param string $source   Source ID
     * @param string $idPrefix Record ID prefix
     */
    public function __construct($data, $oaiID, $source, $idPrefix)
    {
        parent::__construct($data, $oaiID, $source, $idPrefix);

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
        if ($data['institution'] == 'Kantapuu' || $data['institution'] == 'Akseli') {
            $data['institution'] = $this->getRightsHolderLegalBodyName();
        }
        // Handle sources that contain multiple organisations properly
        if ($this->getDriverParam('institutionInBuilding', false)) {
            $data['building'] = reset(explode('/', $data['institution']));
        }
        if ($data['collection'] && $this->getDriverParam('collectionInBuilding', false)) {
            if (isset($data['building']) && $data['building']) {
                $data['building'] .= '/' . $data['collection'];
            } else {
                $data['building'] = $data['collection'];
            }
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

        $daterange = $this->getDateRange('valmistus');
        if ($daterange) {
            $data['main_date_str'] = MetadataUtils::extractYear($daterange[0]);
            $data['main_date'] = $daterange[0];
            $data['search_sdaterange_mv'][] = $data['creation_sdaterange'] = MetadataUtils::convertDateRange($daterange);
        } else {
            $dateSources = array('suunnittelu' => 'design', 'tuotanto' => 'production', 'kuvaus' => 'photography');
            foreach ($dateSources as $dateSource => $field) {
                $daterange = $this->getDateRange($dateSource);
                if ($daterange) {
                    $data[$field . '_sdaterange'] = MetadataUtils::convertDateRange($daterange);
                    if (!isset($data['search_sdaterange_mv'])) {
                        $data['search_sdaterange_mv'][] = $data[$field . '_sdaterange'];
                    }
                    if (!isset($data['main_date_str'])) {
                        $data['main_date_str'] = MetadataUtils::extractYear($daterange[0]);
                        $data['main_date'] = $daterange[0];
                    }
                }
            }
        }
        $data['use_sdaterange'] = MetadataUtils::convertDateRange($this->getDateRange('käyttö'));
        $data['finding_sdaterange'] = MetadataUtils::convertDateRange($this->getDateRange('löytyminen'));

        $data['source_str_mv'] = $this->source;
        $data['datasource_str_mv'] = $this->source;

        if ($this->getURLs()) {
            $data['online_boolean'] = true;
            $data['online_str_mv'] = $this->source;
        }

        $data['location_geo'] = $this->getEventPlaceCoordinates();

        $allfields[] = $this->getRecordSourceOrganization();

        return $data;
    }

    /**
     * Return record title
     *
     * @param bool     $forFiling            Whether the title is to be used in filing (e.g. sorting, non-filing characters should be removed)
     * @param string   $lang                 Language
     * @param string[] $excludedDescriptions Description types to exclude
     *
     * @return string
     */
    public function getTitle($forFiling = false, $lang = null, $excludedDescriptions = array('provenance'))
    {
        return parent::getTitle($forFiling, $lang, array('provenienssi'));
    }

    /**
     * Return materials associated with the object. Materials are contained inside events, and the
     * 'valmistus' (creation) event contains all the materials of the object.
     * Either the individual materials are retrieved, or the display materials element is
     * retrieved in case of failure.
     *
     * @param string $eventType Which event to use
     *
     * @link   http://www.lido-schema.org/schema/v1.0/lido-v1.0-schema-listing.html#materialsTechSetComplexType
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

        $material = '';
        foreach ($this->getEventNodes($eventType) as $node) {
            if (!empty($node->eventMaterialsTech->displayMaterialsTech)) {
                $material = (string) $node->eventMaterialsTech->displayMaterialsTech;
                break;
            }
        }
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
        $descriptionWrapDescriptions = array();
        foreach ($this->getObjectDescriptionSetNodes(array('provenienssi'))
            as $set
        ) {
            foreach ($set->descriptiveNoteValue as $descriptiveNoteValue) {
                $descriptionWrapDescriptions[] = (string) $descriptiveNoteValue;
            }
        }
        if ($descriptionWrapDescriptions && $this->getTitle() == implode('; ', $descriptionWrapDescriptions)) {
            // We have the description already in the title, don't repeat
            $descriptionWrapDescriptions = array();
        }

        // Also read in "description of subject" which contains data suitable for this field
        $subjectDescriptions = array();
        foreach ($this->getSubjectSetNodes() as $set) {
            if (mb_strtolower($set->displaySubject['label'], 'UTF-8') == 'aihe') {
                $subjectDescriptions[] = (string) $set->displaySubject;
            }
        }

        return implode(' ', array_merge($descriptionWrapDescriptions, $subjectDescriptions));
    }

    /**
     * Return subjects associated with object.
     *
     * @param string[] $exclude List of subject types to exclude (defaults to 'aihe'
     * and 'iconclass' since they don't contain human readable terms)
     *
     * @link http://www.lido-schema.org/schema/v1.0/lido-v1.0-schema-listing.html#subjectComplexType
     * @return string
     * @access public
     */
    protected function getSubjectTerms($exclude = array('aihe', 'iconclass'))
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
     * @return string[] Two ISO 8601 dates
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

        if ($startDate) {
            if ($endDate < $startDate) {
                global $logger;
                $logger->log('NdlLidoRecord', "Invalid date range {$startDate} - {$endDate}, record {$this->source}." . $this->getID(), Logger::WARNING);
                $endDate = $startDate;
            }
            if (strlen($startDate) == 4) {
                $startDate = $startDate . '-01-01T00:00:00Z';
            } else if (strlen($startDate) == 7) {
                $startDate = $startDate . '-01T00:00:00Z';
            } else if (strlen($startDate) == 10) {
                $startDate = $startDate . 'T00:00:00Z';
            }
            if (strlen($endDate) == 4) {
                $endDate = $endDate . '-12-31T23:59:59Z';
            } else if (strlen($endDate) == 7) {
                try {
                    $d = new DateTime($endDate . '-01');
                } catch (Exception $e) {
                    global $logger;
                    $logger->log('NdlLidoRecord', "Failed to parse date $endDate, record {$this->source}." . $this->getID(), Logger::ERROR);
                    return null;
                }
                $endDate = $d->format('Y-m-t') . 'T23:59:59Z';
            } else if (strlen($endDate) == 10) {
                $endDate = $endDate . 'T23:59:59Z';
            }
            return array($startDate, $endDate);
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
     * Return the event place coordinates associated with specified event
     *
     * @param string $event Which event to use (omit to scan all events)
     *
     * @return string
     */
    protected function getEventPlaceCoordinates($event = null)
    {
        $coordinates = array();
        foreach ($this->getEventNodes($event) as $event) {
            if (!empty($event->eventPlace->place->gml->Point->pos)) {
                $coordinates[] = (string) $event->eventPlace->place->gml->Point->pos;
            }
        }

        $results = array();
        foreach ($coordinates as $coord) {
            list($lat, $long) = explode(' ', (string)$coord, 2);
            if ($lat < -90 || $lat > 90 || $long < -180 || $long > 180) {
                global $logger;
                $logger->log('NdlLidoRecord', "Discarding invalid coordinates $lat,$long, record {$this->source}." . $this->getID(), Logger::WARNING);
                continue;
            }

            $results[] = "$long $lat";
        }
        return $results;
    }


    /**
     * Attempt to parse a string (in finnish) into a normalized date range.
     * TODO: complicated normalization like this should preferably reside within its own, separate component
     * which should allow modification of the algorithm by methods other than hard-coding rules into source.
     *
     * @param string $input Date range
     *
     * @return string[] Two ISO 8601 dates
     */
    protected function parseDateRange($input)
    {
        $input = trim(strtolower($input));

        $dateMappings = array(
            'kivikausi' => array('-8600-01-01T00:00:00Z', '-1501-12-31T23:59:59Z'),
            'pronssikausi'
                => array('-1500-01-01T00:00:00Z', '-0501-12-31T23:59:59Z'),
            'rautakausi' => array('-0500-01-01T00:00:00Z' ,'1299-12-31T23:59:59Z'),
            'keskiaika' => array('1300-01-01T00:00:00Z' ,'1550-12-31T23:59:59Z'),
            'ajoittamaton' => null,
            'tuntematon' => null
        );

        foreach ($dateMappings as $str => $value) {
            if (strstr($input, $str)) {
                return $value;
            }
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

        $imprecise = false;

        list($input) = explode(',', $input, 2);

        if (preg_match('/(\d\d?)\s*.\s*(\d\d?)\s*.\s*(\d\d\d\d)\s*-\s*(\d\d?)\s*.\s*(\d\d?)\s*.\s*(\d\d\d\d)/', $input, $matches) > 0) {
            $startDate = sprintf('%04d-%02d-%02dT00:00:00Z', $matches[3], $matches[2], $matches[1]);
            $endDate = sprintf('%04d-%02d-%02dT23:59:59Z', $matches[6], $matches[5], $matches[4]);
            $noprocess = true;
        } elseif (preg_match('/(\d\d\d\d)\s*-\s*(\d\d?)\s*.\s*(\d\d?)\s*.\s*(\d\d\d\d)/', $input, $matches) > 0) {
            $startDate = sprintf('%04d-01-01T00:00:00Z', $matches[1]);
            $endDate = sprintf('%04d-%02d-%02dT23:59:59Z', $matches[4], $matches[3], $matches[2]);
            $noprocess = true;
        } elseif (preg_match('/(\d\d?)\s*.\s*(\d\d?)\s*.\s*(\d\d\d\d)\s*-\s*(\d\d\d\d)/', $input, $matches) > 0) {
            $startDate = sprintf('%04d-%02d-%02dT00:00:00Z', $matches[3], $matches[2], $matches[1]);
            $endDate = sprintf('%04d-12-31T23:59:59Z', $matches[4]);
            $noprocess = true;
        } elseif (preg_match('/(\d\d\d\d)\s*.\s*(\d\d?)\s*.\s*(\d\d?)\s*-\s*(\d\d\d\d)\s*.\s*(\d\d?)\s*.\s*(\d\d?)/', $input, $matches) > 0) {
            $startDate = sprintf('%04d-%02d-%02dT00:00:00Z', $matches[1], $matches[2], $matches[3]);
            $endDate = sprintf('%04d-%02d-%02dT23:59:59Z', $matches[4], $matches[5], $matches[6]);
            $noprocess = true;
        } elseif (preg_match('/(\d\d\d\d)(\d\d?)(\d\d?)\s*-\s*(\d\d\d\d)(\d\d?)(\d\d?)/', $input, $matches) > 0) {
            $startDate = sprintf('%04d-%02d-%02dT00:00:00Z', $matches[1], $matches[2], $matches[3]);
            $endDate = sprintf('%04d-%02d-%02dT23:59:59Z', $matches[4], $matches[5], $matches[6]);
            $noprocess = true;
        } elseif (preg_match('/(\d\d\d\d)(\d\d?)\s*-\s*(\d\d\d\d)(\d\d?)/', $input, $matches) > 0) {
            $startDate = sprintf('%04d-%02d-01T00:00:00Z', $matches[1], $matches[2]);
            $endDate = sprintf('%04d-%02d-01', $matches[3], $matches[4]);
            try {
                $d = new DateTime($endDate);
            } catch (Exception $e) {
                global $logger;
                $logger->log('NdlLidoRecord', "Failed to parse date $endDate, record {$this->source}." . $this->getID(), Logger::ERROR);
                return null;
            }
            $endDate = $d->format('Y-m-t') . 'T23:59:59Z';
            $noprocess = true;
        } elseif (preg_match('/(\d\d\d\d)-(\d\d?)-(\d\d?)/', $input, $matches) > 0) {
            // This one needs to be before the lazy matcher below
            $year = $matches[1];
            $month =  sprintf('%02d', $matches[2]);
            $day = sprintf('%02d', $matches[3]);
            $startDate = $year . '-' . $month . '-' .  $day . 'T00:00:00Z';
            $endDate = $year . '-' . $month . '-' .  $day . 'T23:59:59Z';
            $noprocess = true;
        } elseif (preg_match('/(\d\d\d\d)\s*-\s*(\d\d\d\d)\s*(-luvun|-l)\s+(loppupuoli|loppu)/', $input, $matches) > 0) {
            $startDate = $matches[1];
            $endDate = $matches[2];
            if ($endDate % 100 == 0) {
                // Century
                $endDate += 99;
            } elseif ($endDate % 10 == 0) {
                // Decade
                $endDate += 9;
            }
        } elseif (preg_match('/(\d?\d?\d\d)\s*(-|~)\s*(\d?\d?\d\d)\s*(-luku|-l)?\s*(\(?\?\)?)?/', $input, $matches) > 0) {
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
        } elseif (preg_match('/(\d?\d?\d\d)\s+(tammikuu|helmikuu|maaliskuu|huhtikuu|toukokuu|kesäkuu|heinäkuu|elokuu|syyskuu|lokakuu|marraskuu|joulukuu)/', $input, $matches) > 0) {
            $year = $matches[1];
            $month = $k[$matches[2]];
            $startDate = $year . '-' . $month . '-01T00:00:00Z';
            $endDate = $year . '-' . $month . '-01';
            try {
                $d = new DateTime($endDate);
                $endDate = $d->format('Y-m-t') . 'T23:59:59Z';
            } catch (Exception $e) {
                global $logger;
                $logger->log('NdlLidoRecord', "Failed to parse date $endDate, record {$this->source}." . $this->getID(), Logger::ERROR);
                return null;
            }
            $noprocess = true;
        } elseif (preg_match('/(\d\d\d\d)(\d\d)(\d\d)/', $input, $matches) > 0) {
            $year = $matches[1];
            $month =  sprintf('%02d', $matches[2]);
            $day = sprintf('%02d', $matches[3]);
            $startDate = $year . '-' . $month . '-' .  $day . 'T00:00:00Z';
            $endDate = $year . '-' . $month . '-' .  $day . 'T23:59:59Z';
            $noprocess = true;
        } elseif (preg_match('/(\d\d\d\d)(\d\d)/', $input, $matches) > 0) {
            $year = $matches[1];
            $month =  sprintf('%02d', $matches[2]);
            $startDate = $year . '-' . $month . '-01T00:00:00Z';
            $endDate = $year . '-' . $month . '-01';
            try {
                $d = new DateTime($endDate);
            } catch (Exception $e) {
                global $logger;
                $logger->log('NdlLidoRecord', "Failed to parse date $endDate, record {$this->source}." . $this->getID(), Logger::ERROR);
                return null;
            }
            $endDate = $d->format('Y-m-t') . 'T23:59:59Z';
            $noprocess = true;
        } elseif (preg_match('/(\d\d?)\s*.\s*(\d\d?)\s*.\s*(\d\d\d\d)/', $input, $matches) > 0) {
            $year = $matches[3];
            $month =  sprintf('%02d', $matches[2]);
            $day = sprintf('%02d', $matches[1]);
            $startDate = $year . '-' . $month . '-' .  $day . 'T00:00:00Z';
            $endDate = $year . '-' . $month . '-' .  $day . 'T23:59:59Z';
            $noprocess = true;
        } elseif (preg_match('/(\d\d?)\s*.\s*(\d\d\d\d)/', $input, $matches) > 0) {
            $year = $matches[2];
            $month =  sprintf('%02d', $matches[1]);
            $startDate = $year . '-' . $month . '-01' . 'T00:00:00Z';
            $endDate = $year . '-' . $month . '-01';
            try {
                $d = new DateTime($endDate);
                $endDate = $d->format('Y-m-t') . 'T23:59:59Z';
            } catch (Exception $e) {
                global $logger;
                $logger->log('NdlLidoRecord', "Failed to parse date $endDate, record {$this->source}." . $this->getID(), Logger::ERROR);
                return null;
            }
            $noprocess = true;
        } elseif (preg_match('/(\d?\d?\d\d)\s*-(luvun|luku)\s+(alkupuolelta|alkupuoli|alku|alusta)/', $input, $matches) > 0) {
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
        } elseif (preg_match('/(\d?\d?\d\d)\s*-(luvun|luku)\s+(puoliväli)/', $input, $matches) > 0) {
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
        } elseif (preg_match('/(\d?\d?\d\d)\s*(-luvun|-l)\s+(loppupuoli|loppu|lopulta|loppupuolelta)/', $input, $matches) > 0) {
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
        } elseif (preg_match('/(-?\d?\d?\d\d)\s*-(luku|luvulta|l)/', $input, $matches) > 0) {
            $year = $matches[1];
            $startDate = $year;

            if ($year % 100 == 0) {
                $endDate = $year + 99;
            } elseif ($year % 10 == 0) {
                $endDate = $year + 9;
            } else {
                $endDate = $year;
            }
        } elseif (preg_match('/(\d?\d?\d\d)\s*ekr.?\s*\-\s*(\d?\d?\d\d)\s*ekr.?/', $input, $matches) > 0) {
            $startDate = -$matches[1];
            $endDate = -$matches[2];
        } elseif (preg_match('/(\d?\d?\d\d)\s*ekr.?\s*\-\s*(\d?\d?\d\d)\s*jkr.?/', $input, $matches) > 0) {
            $startDate = -$matches[1];
            $endDate = $matches[2];
        } elseif (preg_match('/(-?\d?\d?\d\d) jälkeen/', $input, $matches) > 0) {
            $year = $matches[1];

            $startDate = $year;
            $endDate = $year + 9;
        } elseif (preg_match('/(-?\d\d\d\d)\s*-\s*(-?\d\d\d\d)/', $input, $matches) > 0) {
            $startDate = $matches[1];
            $endDate = $matches[2];
        } elseif (preg_match('/(-?\d{1-4})\s+-\s+(-?\d{1-4})/', $input, $matches) > 0) {
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
            $startDate = '-' . substr('0000', 0, 5-strlen($startDate))
                . substr($startDate, 1);
        } elseif ($startDate == 0) {
            $startDate = '0000';
        }

        if ($endDate < 0) {
            $endDate = '-' . substr('0000', 0, 5-strlen($endDate))
                . substr($endDate, 1);
        } elseif ($endDate == 0) {
            $endDate = '0000';
        }

        switch (strlen($startDate)) {
        case 1:
            $startdate = "000$startDate";
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

        $start = MetadataUtils::validateISO8601Date($startDate);
        $end = MetadataUtils::validateISO8601Date($endDate);
        if ($start === false || $end === false) {
            global $logger;
            $logger->log('NdlLidoRecord', "Invalid date range {$startDate} - {$endDate} parsed from '$input', record {$this->source}." . $this->getID(), Logger::WARNING);
            if ($start !== false) {
                $endDate = substr($startDate, 0, 4) . '-12-31T23:59:59Z';
            } elseif ($end !== false) {
                $startDate = substr($endDate, 0, 4) . '-01-01T00:00:00Z';
            } else {
                return null;
            }
        } elseif ($start > $end) {
            global $logger;
            $logger->log('NdlLidoRecord', "Invalid date range {$startDate} - {$endDate} parsed from '$input', record {$this->source}." . $this->getID(), Logger::WARNING);
            $endDate = substr($startDate, 0, 4) . '-12-31T23:59:59Z';
        }

        return array($startDate, $endDate);
    }

    /**
     * Return the classifications.
     *
     * @link http://www.lido-schema.org/schema/v1.0/lido-v1.0-schema-listing.html#objectClassificationWrap
     * @return string[]
     */
    protected function getClassifications()
    {
        if (empty($this->doc->lido->descriptiveMetadata->objectClassificationWrap->classificationWrap->classification)) {
            return array();
        }
        $results = array();
        foreach ($this->doc->lido->descriptiveMetadata->objectClassificationWrap->classificationWrap->classification as $classification) {
            if (!empty($classification->term)) {
                $results[] = (string)$classification->term;
            }
        }
        return $results;
    }

    /**
     * Return all the names for the specified event type
     *
     * @param string $eventType Event type
     *
     * @return string[]
     */
    protected function getEventNames($eventType)
    {
        $results = array();
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
     * @link http://www.lido-schema.org/schema/v1.0/lido-v1.0-schema-listing.html#legalBodyRefComplexType
     * @return string
     */
    protected function getRightsHolderLegalBodyName()
    {
        if (empty($this->doc->lido->administrativeMetadata->rightsWorkWrap->rightsWorkSet)) {
            return '';
        }

        foreach ($this->doc->lido->administrativeMetadata->rightsWorkWrap->rightsWorkSet as $set) {
            if (!empty($set->rightsHolder->legalBodyName->appellationValue)) {
                return (string) $set->rightsHolder->legalBodyName->appellationValue;
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
        if (empty($this->doc->lido->administrativeMetadata->recordWrap->recordSource->legalBodyName->appellationValue)) {
            return '';
        }
        return (string) $this->doc->lido->administrativeMetadata->recordWrap->recordSource->legalBodyName->appellationValue;
    }

}

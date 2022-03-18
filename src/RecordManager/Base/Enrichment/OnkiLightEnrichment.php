<?php
/**
 * OnkiLightEnrichment Class
 *
 * PHP version 7
 *
 * Copyright (C) The National Library of Finland 2014-2022.
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
 * @author   Samuli Sillanpää <samuli.sillanpaa@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
namespace RecordManager\Base\Enrichment;

use RecordManager\Base\Record\AbstractRecord;

/**
 * OnkiLightEnrichment Class
 *
 * This is a base class for enrichment from an ONKI Light source.
 * Record drivers need to implement the 'enrich' method
 * (i.e. call enrichField with an URI and name of the Solr-field to enrich).
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @author   Samuli Sillanpää <samuli.sillanpaa@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
abstract class OnkiLightEnrichment extends AbstractEnrichment
{
    /**
     * ONKI Light API base url
     *
     * @var string
     */
    protected $onkiLightBaseURL;

    /**
     * List of allowed URL prefixes to try to fetch
     *
     * @var array
     */
    protected $urlPrefixAllowedList;

    /**
     * List of URI prefixes for which to process other vocabularies with
     * exact matches
     *
     * @var array
     */
    protected $uriPrefixExactMatches;

    /**
     * Solr field to use for the location data
     *
     * @var string
     */
    protected $solrLocationField = '';

    /**
     * Solr field to use for the center coordinates of locations
     *
     * @var string
     */
    protected $solrCenterField = '';

    /**
     * Initialize settings
     *
     * @return void
     */
    public function init()
    {
        parent::init();

        $settings = $this->config['OnkiLightEnrichment'] ?? [];
        $this->onkiLightBaseURL = $settings['base_url'] ?? '';

        // whitelist kept for back-compatibility
        $list = $settings['url_prefix_allowed_list']
            ?? $settings['url_prefix_whitelist']
            ?? [];
        $this->urlPrefixAllowedList = (array)$list;

        $this->uriPrefixExactMatches = $settings['uri_prefix_exact_matches'] ?? [];

        if (isset($settings['solr_location_field'])) {
            $this->solrLocationField = $settings['solr_location_field'];
        }
        if (isset($settings['solr_center_field'])) {
            $this->solrCenterField = $settings['solr_center_field'];
        }
    }

    /**
     * Enrich the record and return any additions in solrArray
     *
     * @param string $sourceId  Source ID
     * @param object $record    Metadata Record
     * @param array  $solrArray Metadata to be sent to Solr
     *
     * @throws \Exception
     * @return void
     */
    abstract public function enrich($sourceId, $record, &$solrArray);

    /**
     * Enrich the record and return any additions in solrArray
     *
     * @param string         $sourceId           Source ID
     * @param AbstractRecord $record             Metadata record
     * @param array          $solrArray          Metadata to be sent to Solr
     * @param string         $id                 Onki id
     * @param string         $solrPrefField      Target Solr field for preferred
     *                                           values (e.g. for terms in other
     *                                           languages)
     * @param string         $solrAltField       Target Solr field for alternative
     *                                           values
     * @param string         $solrCheckField     Solr field to check for existing
     *                                           values
     * @param bool           $includeInAllfields Whether to include the enriched
     *                                           value also in allFields
     *
     * @return void
     */
    protected function enrichField(
        string $sourceId,
        AbstractRecord $record,
        &$solrArray,
        $id,
        $solrPrefField,
        $solrAltField,
        $solrCheckField = '',
        $includeInAllfields = false
    ) {
        // Clean up any invalid characters from the id
        $id = trim(
            str_replace(
                ['|', '!', '"', '#', '€', '$', '%', '&', '<', '>'],
                [],
                $id
            )
        );

        // Check that the ID prefix matches that of the allowed ones
        $match = false;
        foreach ($this->urlPrefixAllowedList as $prefix) {
            if (strncmp($id, $prefix, strlen($prefix)) === 0) {
                $match = true;
                break;
            }
        }

        if (!$match) {
            $this->logger->logDebug(
                'enrichField',
                "Ignoring unlisted URI '$id', record $sourceId." . $record->getID(),
                true
            );
            return;
        }

        $checkFieldContents = $solrCheckField
            ? array_map(
                function ($s) {
                    return mb_strtolower($s, 'UTF-8');
                },
                (array)($solrArray[$solrCheckField] ?? [])
            ) : [];

        $localData = $this->db->findOntologyEnrichment(['_id' => $id]);
        if ($localData) {
            $map = [
                'prefLabels' => $solrPrefField,
                'altLabels' => $solrAltField,
                'hiddenLabels' => $solrAltField
            ];
            foreach ($map as $labelField => $solrField) {
                $values = $this->filterDuplicates(
                    explode('|', $localData[$labelField] ?? ''),
                    $checkFieldContents
                );
                // Additionally, filter empty strings
                $values = array_filter(
                    $values,
                    fn ($v) => !is_string($v) || '' !== trim($v)
                );
                if (empty($values)) {
                    continue;
                }
                if ($solrField) {
                    $solrArray[$solrField]
                        = array_merge($solrArray[$solrField] ?? [], $values);
                }
                if ($includeInAllfields) {
                    $solrArray['allfields']
                        = array_merge($solrArray['allfields'], $values);
                }
            }
            $wktLocationStr = $localData['geoLocation'] ?? '';
            if ($wktLocationStr) {
                $this->processLocationWkt($wktLocationStr, $solrArray);
            }
            return;
        }

        if (!($url = $this->getOnkiUrl($id))) {
            return;
        }

        try {
            $data = $this->getExternalData(
                $url,
                $id,
                ['Accept' => 'application/json'],
                [500]
            );
        } catch (\Exception $e) {
            $this->logger->logDebug(
                'enrichField',
                "Failed to fetch external data '$url', record " . $solrArray['id']
                . ': ' . $e->getMessage()
            );
            return;
        }

        if ($data) {
            $data = json_decode($data, true);
            if (!isset($data['graph'])) {
                return;
            }

            foreach ($data['graph'] as $item) {
                if (!isset($item['type'])) {
                    continue;
                }
                if (is_array($item['type'])) {
                    if (!in_array('skos:Concept', $item['type'])) {
                        continue;
                    }
                } elseif ($item['type'] != 'skos:Concept') {
                    continue;
                }

                if ($item['uri'] === $id) {
                    $this->processLocationWgs84($item, $solrArray);
                    $vals = [];

                    if ($val = $item['altLabel']['value'] ?? null) {
                        $vals[] = $val;
                    }
                    if ($val = $item['hiddenLabel']['value'] ?? null) {
                        $vals[] = $val;
                    }

                    if ($vals) {
                        foreach ($this->filterDuplicates($vals, $checkFieldContents)
                            as $val
                        ) {
                            $checkFieldContents[] = mb_strtolower($val, 'UTF-8');
                            if ($solrAltField) {
                                $solrArray[$solrAltField][] = $val;
                            }
                            if ($includeInAllfields) {
                                $solrArray['allfields'][] = $val;
                            }
                        }
                    }
                }

                // Check whether to process other exactMatch vocabularies
                $exactMatches = false;
                if (!empty($item['exactMatch'])) {
                    foreach ($this->uriPrefixExactMatches as $prefix) {
                        if (strncmp($item['uri'], $prefix, strlen($prefix)) === 0
                        ) {
                            $exactMatches = true;
                            break;
                        }
                    }
                }

                if ($exactMatches) {
                    foreach ($item['exactMatch'] as $exactMatch) {
                        $uri = is_array($exactMatch)
                            ? ($exactMatch['uri'] ?? null)
                            : $exactMatch;
                        if (!$uri) {
                            continue;
                        }
                        $matchId = $uri;
                        if (!($matchURL = $this->getOnkiUrl($matchId))) {
                            continue;
                        }
                        $matchData = $this->getExternalData(
                            $matchURL,
                            $matchId,
                            ['Accept' => 'application/json']
                        );
                        if (!$matchData) {
                            continue;
                        }
                        $matchData = json_decode($matchData, true);
                        if (!isset($matchData['graph'])) {
                            continue;
                        }
                        foreach ($matchData['graph'] as $matchItem) {
                            if (($matchItem['uri'] ?? null) != $matchId) {
                                continue;
                            }
                            if (!isset($matchItem['type'])) {
                                continue;
                            }
                            if (is_array($matchItem['type'])) {
                                if (!in_array('skos:Concept', $matchItem['type'])
                                ) {
                                    return;
                                }
                            } elseif ($matchItem['type'] != 'skos:Concept') {
                                continue;
                            }

                            $this->processLocationWgs84($matchItem, $solrArray);

                            foreach ((array)($matchItem['altLabel'] ?? [])
                                as $label
                            ) {
                                if (!($val = $label['value'] ?? null)) {
                                    continue;
                                }
                                $existing = !$this->filterDuplicates(
                                    [$val],
                                    $checkFieldContents
                                );
                                if ($existing) {
                                    continue;
                                }

                                $checkFieldContents[] = mb_strtolower($val, 'UTF-8');
                                if ($solrAltField) {
                                    $solrArray[$solrAltField][] = $val;
                                }
                                if ($includeInAllfields) {
                                    $solrArray['allfields'][] = $val;
                                }
                            }

                            foreach ((array)($matchItem['prefLabel'] ?? [])
                                as $label
                            ) {
                                if (!($val = $label['value'] ?? null)) {
                                    continue;
                                }
                                $existing = !$this->filterDuplicates(
                                    [$val],
                                    $checkFieldContents
                                );
                                if ($existing) {
                                    continue;
                                }

                                $checkFieldContents[] = mb_strtolower($val, 'UTF-8');
                                if ($solrPrefField) {
                                    $solrArray[$solrPrefField][] = $val;
                                }
                                if ($includeInAllfields) {
                                    $solrArray['allfields'][] = $val;
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * Process WKT location data and add that information to solrArray
     *
     * Implementation notes
     * Currently supports only single, non-empty 2D WKT point data format
     *
     * @param string $wkt       Well-known text to be processed
     * @param array  $solrArray Metadata to be sent to Solr
     *
     * @return void
     */
    protected function processLocationWkt($wkt, &$solrArray): void
    {
        preg_match(
            '/^POINT\s*\((?P<lon>-?\d+(?:\.?\d+)?)\s*(?P<lat>-?\d+(?:\.?\d+)?)\)/',
            $wkt,
            $matches
        );
        if ($matches) {
            $wktItem = [
                'lat' => $matches['lat'],
                'lon' => $matches['lon'],
                'wkt' => 'POINT(' . $matches['lon'] . ' ' . $matches['lat'] . ')'
            ];
            $this->processLocationItem($wktItem, $solrArray);
        }
    }

    /**
     * Process WGS 84 location data and add that information to solrArray
     *
     * @param array $item      Decoded JSON array item from which to extract loc data
     * @param array $solrArray Metadata to be sent to Solr
     *
     * @return void
     */
    protected function processLocationWgs84($item, &$solrArray): void
    {
        $lat = $item['http://www.w3.org/2003/01/geo/wgs84_pos#lat']
            ?? $item['wgs84:lat']
            ?? null;
        $lon = $item['http://www.w3.org/2003/01/geo/wgs84_pos#long']
            ?? $item['wgs84:long']
            ?? null;

        if (null !== $lat && null !== $lon) {
            $wktItem = [
                'lat' => $lat,
                'lon' => $lon,
                'wkt' => "POINT($lon $lat)"
            ];
            $this->processLocationItem($wktItem, $solrArray);
        }
    }

    /**
     * Add location information to solrArray
     *
     * @param array $locItem   Keyed array with keys wkt, lat and lon for each loc
     * @param array $solrArray Metadata to be sent to Solr
     *
     * @return void
     */
    protected function processLocationItem($locItem, &$solrArray): void
    {
        if ($this->solrCenterField && !isset($solrArray[$this->solrCenterField])) {
            $coords = $locItem['lon'] . ' ' . $locItem['lat'];
            $solrArray[$this->solrCenterField] = $coords;
        }
        if ($this->solrLocationField
            && !in_array($locItem['wkt'], $solrArray[$this->solrLocationField] ?? [])
        ) {
            $solrArray[$this->solrLocationField][] = $locItem['wkt'];
        }
    }

    /**
     * Filter duplicate values case-insensitively
     *
     * @param array $values Values to filter
     * @param array $check  Values to check against (expected to be lowercase)
     *
     * @return array Non-duplicates
     */
    protected function filterDuplicates(array $values, array $check)
    {
        return array_filter(
            $values,
            function ($v) use (&$check) {
                $v = mb_strtolower($v, 'UTF-8');
                if ($res = !in_array($v, $check)) {
                    $check[] = $v;
                }
                return $res;
            }
        );
    }

    /**
     * Return Onki API url.
     *
     * @param string $id Onki id
     *
     * @return string
     */
    protected function getOnkiUrl($id)
    {
        $url = $this->onkiLightBaseURL;
        if (!$url) {
            return '';
        }
        if (substr($url, -1) !== '/') {
            $url .= '/';
        }
        $url .= 'data?format=application/json&uri=' . urlencode($id);
        return $url;
    }
}

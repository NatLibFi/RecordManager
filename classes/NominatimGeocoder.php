<?php
/**
 * Nominatim Geocoder Class
 *
 * PHP version 5
 *
 * Copyright (C) The National Library of Finland 2013-2016.
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
require_once 'Enrichment.php';
require_once 'vendor/phayes/geophp/geoPHP.inc';

/**
 * Nominatim Geocoder Class
 *
 * This is a geocoder using the OpenStreetMap Nominatim interface
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class NominatimGeocoder extends Enrichment
{
    /**
     * Nominatim server base url
     *
     * @var string
     */
    protected $baseUrl = '';

    /**
     * Email address for nominatim
     *
     * @var string
     */
    protected $email = '';

    /**
     * Preferred area to prioritize
     *
     * @var string
     */
    protected $preferredArea = '';

    /**
     * Delay between requests
     *
     * @var int
     */
    protected $delay = 1500;

    /**
     * Last request time
     *
     * @var int
     */
    protected $lastRequestTime = null;

    /**
     * Tolerance in the polygon simplification
     *
     * @var int
     */
    protected $simplificationTolerance = 0;

    /**
     * Maximum simplified polygon length
     *
     * @var int
     */
    protected $simplificationMaxLength = 0;

    /**
     * Solr field to use for the location data
     *
     * @var string
     */
    protected $solrField = 'location_geo';

    /**
     * Solr field to use for the center coordinates of locations
     *
     * @var string
     */
    protected $solrCenterField = 'center_coords';

    /**
     * Optional terms that may be removed from a string to geocode
     *
     * @var array
     */
    protected $optionalTerms = [];

    /**
     * Blacklist of regular expressions. Matching locations are ignored.
     *
     * @var array
     */
    protected $blacklist = [];

    /**
     * Location transformations
     *
     * @var array
     */
    protected $transformations = [];

    /**
     * Constructor
     *
     * @param MongoDB $db  Database connection (for cache)
     * @param Logger  $log Logger
     */
    public function __construct($db, $log)
    {
        global $configArray;

        parent::__construct($db, $log);

        $settings = isset($configArray['NominatimGeocoder'])
            ? $configArray['NominatimGeocoder'] : [];
        if (!isset($settings['url']) || !$settings['url']) {
            throw new Exception('url must be specified for Nominatim');
        }
        if (!isset($settings['email']) || !$settings['email']) {
            throw new Exception(
                'Email address must be specified for Nominatim (see '
                . 'http://wiki.openstreetmap.org/wiki/Nominatim_usage_policy)'
            );
        }
        $this->email = $settings['email'];
        $this->baseUrl = $settings['url'];
        if (isset($settings['preferred_area'])) {
            $this->preferredArea = $settings['preferred_area'];
        }
        if (isset($settings['delay'])) {
            $this->delay = $settings['delay'];
        }
        if (isset($settings['delay'])) {
            $this->delay = $settings['delay'];
        }
        if (isset($settings['simplification_tolerance'])) {
            if (!geoPHP::geosInstalled()) {
                throw new Exception(
                    'PHP GEOS extension is required for simplification_tolerance'
                );
            }
            $this->simplificationTolerance = $settings['simplification_tolerance'];
        }
        if (isset($settings['simplification_max_length'])) {
            $this->simplificationMaxLength = $settings['simplification_max_length'];
        }
        if (isset($settings['solr_field'])) {
            $this->solrField = $settings['solr_field'];
        }
        if (isset($settings['solr_center_field'])) {
            $this->solrCenterField = $settings['solr_center_field'];
        }
        if (isset($settings['optional_terms'])) {
            $this->optionalTerms = $settings['optional_terms'];
        }
        if (isset($settings['blacklist'])) {
            $this->blacklist = $settings['blacklist'];
        }
        if (isset($settings['search'])) {
            foreach ($settings['search'] as $index => $search) {
                if (!isset($settings['replace'][$index])) {
                    throw new Exception(
                        "No matching 'replace' setting for search '$search'"
                    );
                }
                $this->transformations[] = [
                    'search' => $search,
                    'replace' => $settings['replace'][$index]
                ];
            }
        }
    }

    /**
     * Enrich the record and return any additions in solrArray
     *
     * @param string $sourceId  Source ID
     * @param object $record    Metadata Record
     * @param array  $solrArray Metadata to be sent to Solr
     *
     * @return void
     */
    public function enrich($sourceId, $record, &$solrArray)
    {
        if (empty($this->baseUrl) || !is_callable([$record, 'getLocations'])) {
            return;
        }
        $locations = $record->getLocations();
        foreach ($locations as $location) {
            if ($this->blacklist) {
                foreach ($this->blacklist as $entry) {
                    if (preg_match("/$entry/i", $location)) {
                        continue 2;
                    }
                }
            }
            for ($i = 0; $i < 10; $i++) {
                $wkts = $this->geocode($location);
                if ($wkts) {
                    if (!isset($solrArray[$this->solrField])) {
                        $solrArray[$this->solrField] = $wkts;
                    } else {
                        $solrArray[$this->solrField] = array_merge(
                            $solrArray[$this->solrField], $wkts
                        );
                    }
                    if (!empty($this->solrCenterField)) {
                        $solrArray[$this->solrCenterField]
                            = MetadataUtils::getCenterCoordinates(
                                $solrArray[$this->solrField]
                            );
                    }
                    break;
                }
                // Try to remove optional words if we have more than two words
                $cleaned = $location;
                if ($this->optionalTerms && str_word_count($location) > 2) {
                    foreach ($this->optionalTerms as $term) {
                        $cleaned = preg_replace(
                            "/([\.\,\s]* |^){$term}[\.\,\s]*( |\$)/i",
                            ' ',
                            $cleaned
                        );
                    }
                }
                // Apply transformations
                foreach ($this->transformations as $transformation) {
                    $cleaned = preg_replace(
                        "/{$transformation['search']}/",
                        $transformation['replace'],
                        $cleaned
                    );
                }
                if ($cleaned == $location || empty(trim($cleaned))) {
                    break;
                }
                $location = $cleaned;
            }
        }
    }

    /**
     * Do the geocoding
     *
     * @param string $location Location string
     *
     * @return array WKT strings
     */
    protected function geocode($location)
    {
        if (null !== $this->lastRequestTime) {
            $sinceLast = microtime(true) - $this->lastRequestTime;
            if ($sinceLast < $this->delay) {
                usleep($this->delay - $sinceLast);
            }
        }
        $this->lastRequestTime = microtime(true);

        $params = [
            'q' => $location,
            'format' => 'json',
            'polygon_text' => '1',
            'email' => $this->email
        ];

        if ($this->preferredArea) {
            $params['viewbox'] = $this->preferredArea;
        }

        $url = $this->baseUrl . '?' . http_build_query($params);
        $response = $this->getExternalData(
            $url, 'nominatim ' . md5($url), [], [500]
        );
        $places = json_decode($response, true);
        if (null === $places) {
            throw new Exception("Could not decode Nominatim response: $response");
        }

        $items = [];
        $highestImportance = null;
        foreach ($places as $place) {
            if (null === $highestImportance
                || $place['importance'] > $highestImportance
            ) {
                $highestImportance = $place['importance'];
            }
            $wkt = $place['geotext'];
            if ($this->simplificationTolerance) {
                if (strcasecmp(substr($wkt, 0, 7), 'POLYGON') == 0
                    || strcasecmp(substr($wkt, 0, 12), 'MULTIPOLYGON') == 0
                ) {
                    $wkt = $this->simplify($wkt);
                }
            }
            $items[] = [
                'wkt' => $wkt,
                'importance' => $place['importance']
            ];
        }
        // Include only items with the highest importance (there may be many with the
        // same importance)
        $results = [];
        foreach ($items as $item) {
            if ($item['importance'] == $highestImportance) {
                $results[] = $item['wkt'];
            }
        }
        $results = $this->mergeLineStrings($results);
        return $results;
    }

    /**
     * Simplify a polygon
     *
     * @param string $location WKT polygon
     *
     * @return string Simplified WKT polygon
     */
    protected function simplify($location)
    {
        $origPointCount = substr_count($location, ',') + 1;
        if ($origPointCount <= $this->simplificationMaxLength) {
            return $location;
        }
        $polygon = geoPHP::load($location, 'wkt');
        $tolerance = $this->simplificationTolerance;
        $simplifiedWKT = '';
        $pointCount = null;
        for ($try = 1; $try < 100; $try++) {
            $simplified = $polygon->simplify($tolerance);
            if (null === $simplified) {
                throw new Exception('Polygon simplification failed');
            }
            $simplifiedWKT = $simplified->out('wkt');
            $pointCount = substr_count($simplifiedWKT, ',') + 1;
            if (!$this->simplificationMaxLength
                || $pointCount <= $this->simplificationMaxLength
            ) {
                break;
            }
            $tolerance *= 2;
        }
        if (null !== $pointCount && $origPointCount > $pointCount) {
            $location = $simplifiedWKT;
        }
        return $location;
    }

    /**
     * Merge a set of linestrings if they are contiguous
     *
     * @param array $wktArray WKT shapes
     *
     * @return array
     */
    protected function mergeLineStrings($wktArray)
    {
        $results = [];
        $previous = null;
        foreach ($wktArray as $current) {
            if (null === $previous || strncmp($current, 'LINESTRING', 10) != 0
                || strncmp($previous, 'LINESTRING', 10) != 0
            ) {
                $results[] = $previous = $current;
                continue;
            }
            $prev = geoPHP::load($previous, 'wkt');
            $curr = geoPHP::load($current, 'wkt');
            if ($prev->startPoint() == $curr->endPoint()) {
                $previous = $this->mergeShapes($current, $previous);
                array_pop($results);
                $results[] = $previous;
            } elseif ($prev->endPoint() == $curr->startPoint()) {
                $previous = $this->mergeShapes($previous, $current);
                array_pop($results);
                $results[] = $previous;
            } else {
                $results[] = $previous = $current;
            }
        }
        return $results;
    }

    /**
     * Merge two WKT shapes (works with linestrings)
     *
     * @param string $shape1 First shape
     * @param string $shape2 Second shape
     *
     * @return string
     */
    protected function mergeShapes($shape1, $shape2)
    {
        $shape2 = preg_replace('/.*\(/', '', $shape2);
        $shape1 = preg_replace('/,\s*[\d\.\s]+\)$/', ",$shape2", $shape1);
        return $shape1;
    }
}

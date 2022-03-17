<?php
/**
 * Nominatim Geocoder Class
 *
 * PHP version 7
 *
 * Copyright (C) The National Library of Finland 2013-2022.
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
namespace RecordManager\Base\Enrichment;

/**
 * Nominatim Geocoder Class
 *
 * This is a geocoder using the OpenStreetMap Nominatim interface
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class NominatimGeocoder extends AbstractEnrichment
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
     * @var float
     */
    protected $delay = 1500;

    /**
     * Last request time
     *
     * @var float
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
     * Ignored classes
     *
     * @var array
     */
    protected $ignoredClasses = [
        'amenity', 'craft', 'emergency', 'office', 'power', 'public_transport',
        'shop', 'sport', 'tourism'
    ];

    /**
     * Optional terms that may be removed from a string to geocode
     *
     * @var array
     */
    protected $optionalTerms = [];

    /**
     * Blocklist of regular expressions. Matching locations are ignored.
     *
     * @var array
     */
    protected $blocklist = [];

    /**
     * Location transformations
     *
     * @var array
     */
    protected $transformations = [];

    /**
     * Initialize settings
     *
     * @return void
     */
    public function init()
    {
        parent::init();

        $settings = $this->config['NominatimGeocoder'] ?? [];
        if (!isset($settings['url']) || !$settings['url']) {
            throw new \Exception('url must be specified for Nominatim');
        }
        if (!isset($settings['email']) || !$settings['email']) {
            throw new \Exception(
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
            $this->delay = floatval($settings['delay']);
        }
        if (isset($settings['simplification_tolerance'])) {
            if (!\geoPHP::geosInstalled()) {
                throw new \Exception(
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
        if (isset($settings['ignored_classes'])) {
            $this->ignoredClasses = $settings['ignored_classes'];
        }
        if (isset($settings['optional_terms'])) {
            $this->optionalTerms = $settings['optional_terms'];
        }
        if (isset($settings['blocklist'])) {
            $this->blocklist = $settings['blocklist'];
        } elseif (isset($settings['blacklist'])) {
            // Kept for back-compatibility
            $this->blocklist = $settings['blacklist'];
        }
        if (isset($settings['search'])) {
            foreach ($settings['search'] as $index => $search) {
                if (!isset($settings['replace'][$index])) {
                    throw new \Exception(
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
        if (empty($locations)) {
            return;
        }
        if ($this->enrichLocations($locations['primary'], $solrArray)) {
            return;
        }
        $this->enrichLocations($locations['secondary'], $solrArray);
    }

    /**
     * Enrich using an array of location strings
     *
     * @param array $locations Locations
     * @param array $solrArray Metadata to be sent to Solr
     *
     * @return bool Whether locations were found
     */
    protected function enrichLocations($locations, &$solrArray)
    {
        $result = false;
        $cf = $this->solrCenterField;
        $center = $cf && isset($solrArray[$cf])
            ? \geoPHP::load('POINT(' . $solrArray[$cf] . ')', 'wkt')
            : null;

        foreach ($locations as $location) {
            if ($this->blocklist) {
                foreach ($this->blocklist as $entry) {
                    if (preg_match("/$entry/i", $location)) {
                        continue 2;
                    }
                }
            }
            for ($i = 0; $i < 10; $i++) {
                $geocoded = $this->geocode($location);
                if ($geocoded) {
                    $wkts = array_column($geocoded, 'wkt');
                    $poly = \geoPHP::load($wkts[0], 'wkt');

                    if (null === $center || $poly->contains($center)) {
                        if (!isset($solrArray[$this->solrField])) {
                            $solrArray[$this->solrField] = $wkts;
                        } else {
                            $solrArray[$this->solrField] = array_merge(
                                $solrArray[$this->solrField],
                                $wkts
                            );
                        }
                    }
                    // Set new center coordinates only if the field is in use and has
                    // no previous value
                    if ($cf && !isset($solrArray[$cf])) {
                        $solrArray[$cf]
                            = $geocoded[0]['lon'] . ' ' . $geocoded[0]['lat'];
                    }
                    $result = true;
                    break;
                }
                $cleaned = $location;

                // Try to remove optional words if we have more than two words
                if ($this->optionalTerms && str_word_count($location) > 2) {
                    foreach ($this->optionalTerms as $term) {
                        $cleaned = preg_replace(
                            "/([\.\,\s]* |^){$term}[\.\,\s]*( |\$)/i",
                            ' ',
                            $cleaned
                        );
                    }
                }
                // If optional words have been cleaned to no avail, try also removing
                // last word if we have more than two
                if ($cleaned == $location) {
                    $words = explode(',', $cleaned);
                    if (count($words) > 2) {
                        $cleaned = implode(',', array_splice($words, 0, -1));
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
        return $result;
    }

    /**
     * Do the geocoding
     *
     * @param string $location Location string
     *
     * @return array Array of keyed arrays with keys wkt, lat and lon for each
     * location
     */
    protected function geocode($location)
    {
        if (null !== $this->lastRequestTime) {
            $sinceLast = microtime(true) - $this->lastRequestTime;
            if ($sinceLast < $this->delay) {
                usleep((int)round(($this->delay - $sinceLast) * 1000));
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
            $url,
            'nominatim ' . md5($url),
            [],
            [500]
        );
        $places = json_decode($response, true);
        if (null === $places) {
            $this->logger->logError(
                'NominatimGeocoder',
                "Could not decode Nominatim response (request: $url): $response"
            );
            return [];
        }

        $items = [];
        $highestImportance = null;
        foreach ($places as $place) {
            if (in_array($place['class'], $this->ignoredClasses)) {
                continue;
            }
            $importance = $place['importance'];
            if ($place['class'] == 'boundary') {
                // Boost boundaries
                $importance *= 10;
            }
            if (null === $highestImportance || $importance > $highestImportance) {
                $highestImportance = $importance;
            } elseif ($importance < $highestImportance) {
                continue;
            }
            $wkt = $place['geotext'];
            if ($this->simplificationTolerance) {
                if (strcasecmp(substr($wkt, 0, 7), 'POLYGON') == 0
                    || strcasecmp(substr($wkt, 0, 12), 'MULTIPOLYGON') == 0
                    || strcasecmp(substr($wkt, 0, 10), 'LINESTRING') == 0
                    || strcasecmp(substr($wkt, 0, 15), 'MULTILINESTRING') == 0
                ) {
                    $wkt = $this->simplify($wkt);
                }
            }
            $items[] = [
                'wkt' => $wkt,
                'lat' => $place['lat'],
                'lon' => $place['lon'],
                'importance' => $importance
            ];
        }
        // Include only items with the highest importance (there may be many with the
        // same importance)
        $results = [];
        foreach ($items as $item) {
            if ($item['importance'] == $highestImportance) {
                $results[] = $item;
            }
        }
        $results = $this->mergeLineStrings($results);
        return $results;
    }

    /**
     * Simplify a shape
     *
     * @param string $location WKT shape
     *
     * @return string Simplified WKT shape
     */
    protected function simplify($location)
    {
        $origPointCount = substr_count($location, ',') + 1;
        if ($origPointCount <= $this->simplificationMaxLength) {
            return $location;
        }
        $polygon = \geoPHP::load($location, 'wkt');
        $tolerance = $this->simplificationTolerance;
        $simplifiedWKT = '';
        $pointCount = null;
        for ($try = 1; $try < 100; $try++) {
            $simplified = $polygon->simplify($tolerance);
            if (null === $simplified) {
                throw new \Exception('Shape simplification failed');
            }
            $simplifiedWKT = $simplified->out('wkt');
            if (strstr($simplifiedWKT, 'EMPTY') !== false) {
                // Got empty shape as result, return bounding box
                $bbox = $polygon->getBBox();
                return "ENVELOPE({$bbox['minx']}, {$bbox['maxx']}, {$bbox['maxy']}, "
                    . "{$bbox['miny']})";
            }
            $pointCount = substr_count($simplifiedWKT, ',') + 1;
            if (!$this->simplificationMaxLength
                || $pointCount <= $this->simplificationMaxLength
            ) {
                break;
            }
            $tolerance *= 2;
        }
        if (null !== $pointCount && $origPointCount > $pointCount) {
            return $simplifiedWKT;
        }
        // Simplification failed, return bounding box
        $bbox = $polygon->getBBox();
        return "ENVELOPE({$bbox['minx']}, {$bbox['maxx']}, {$bbox['maxy']}, "
            . "{$bbox['miny']})";
    }

    /**
     * Merge a set of linestrings if they are contiguous
     *
     * @param array $locations Locations (keyed arrays)
     *
     * @return array
     */
    protected function mergeLineStrings($locations)
    {
        $results = [];
        $previous = null;
        foreach ($locations as $current) {
            if (null === $previous || strncmp($current['wkt'], 'LINESTRING', 10) != 0
                || strncmp($previous['wkt'], 'LINESTRING', 10) != 0
            ) {
                $results[] = $previous = $current;
                continue;
            }
            $prev = \geoPHP::load($previous['wkt'], 'wkt');
            $curr = \geoPHP::load($current['wkt'], 'wkt');
            if ($prev->startPoint() == $curr->endPoint()) {
                $previous['wkt'] = $this->mergeShapes(
                    $current['wkt'],
                    $previous['wkt']
                );
                array_pop($results);
                $results[] = $previous;
            } elseif ($prev->endPoint() == $curr->startPoint()) {
                $previous['wkt'] = $this->mergeShapes(
                    $previous['wkt'],
                    $current['wkt']
                );
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

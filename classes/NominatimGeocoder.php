<?php
/**
 * Nominatim Geocoder Class
 *
 * PHP version 5
 *
 * Copyright (C) The National Library of Finland 2013.
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

require_once 'Geocoder.php';

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
class NominatimGeocoder extends Geocoder
{
    protected $baseUrl = '';
    protected $email = '';
    protected $preferredArea = '';
    protected $delay = 1500;
    protected $simplificationTolerance = 0;
    protected $simplificationMaxLength = 0;
    
    /**
     * Initialize the geocoder with the settings from configuration file
     * 
     * @param array $settings Settings from the ini file
     * 
     * @return void
     */
    public function init($settings)
    {
        if (!isset($settings['email']) || !$settings['email']) {
            throw new Exception('Email address must be specified for Nominatim (see http://wiki.openstreetmap.org/wiki/Nominatim_usage_policy)');
        }
        if (!isset($settings['url']) || !$settings['url']) {
            throw new Exception('url must be specified for Nominatim');
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
            $this->simplificationTolerance = $settings['simplification_tolerance'];
            include_once 'geoPHP/geoPHP.inc';
            if (!geoPHP::geosInstalled()) {
                throw new Exception('PHP GEOS extension not installed, cannot use simplification_tolerance');
            }
        }
        if (isset($settings['simplification_max_length'])) {
            $this->simplificationMaxLength = $settings['simplification_max_length'];
        }
    }
    
    /**
     * Do the geocoding
     * 
     * @param string $placeFile File with place names, one per line 
     * 
     * @return void
     */
    public function geocode($placeFile)
    {
        $fp = fopen($placeFile, 'r');
        if ($fp === false) {
            throw new Exception("Could not open $placeFile");
        }
        
        $request = new HTTP_Request2(
            $this->baseUrl, 
            HTTP_Request2::METHOD_GET, 
            array('ssl_verify_peer' => false)
        );       
        $request->setHeader('User-Agent', 'RecordManager');
        
        while (($line = fgets($fp))) {
            $line = trim($line);
            if ($line == '') {
                continue;
            }

            $params = array(
                'q' => $line,
                'format' => 'xml',
                'polygon_text' => '1',
                'email' => $this->email 
            );
            
            if ($this->preferredArea) {
                $params['viewbox'] = $this->preferredArea;
            }
            
            $url = $request->getURL();
            $url->setQueryVariables($params);

            $urlStr = $url->getURL();

            if ($this->verbose) {
                echo "Request url: $urlStr\n";
            }
            
            $response = $request->send();
            $code = $response->getStatus();
            if ($code >= 300) {
                $this->log->log('NominatimGeocoder', "Request '$urlStr' failed ($code)", Logger::FATAL);
                break;
            }
            
            $responseStr = $response->getBody();
            $xml = simplexml_load_string($responseStr);

            $places = $xml->xpath("//place[@class='boundary' or @class='place']");
            if ($places) {
                $this->db->location->remove(array('place' => mb_strtoupper($line)));
            }
            foreach ($places as $place) {
                $location = (string)$place->attributes()->geotext;
                if ($this->simplificationTolerance 
                    && (strtoupper(substr($location, 0, 7) == 'POLYGON' || strtoupper(substr($location, 0, 12) == 'MULTIPOLYGON')))
                ) {
                    $location = $this->simplify($location);
                }
                $record = array(
                    'place' => MetadataUtils::normalize($line),
                    'location' => $location,
                    'original_location' => (string)$place->attributes()->geotext,
                    'importance' => (string)$place->attributes()->importance
                );
                $record = $this->db->location->save($record);
            }
            $this->log->log("NominatimGeocoder", count($places) . " locations found for '$line'", Logger::INFO);
            usleep($this->delay * 1000);
        }
        fclose($fp);
    }

    /**
     * Reapply simplification with the current configuration parameters to all locations
     * 
     * @return void
     */
    public function resimplify()
    {
        $locations = $this->db->location->find();
        foreach ($locations as $location) {
            if (!isset($location['original_location'])) {
                throw new Exception('original_location not set for a location record');
            }
            if ($this->simplificationTolerance 
                && (strtoupper(substr($location['original_location'], 0, 7) == 'POLYGON' || strtoupper(substr($location['original_location'], 0, 12) == 'MULTIPOLYGON')))
            ) {
                $this->log->log("NominatimGeocoder", 'Resimplified ' . $location['place'], Logger::INFO);
                
                $location['location'] = $this->simplify($location['original_location']);
                $this->db->location->save($location);
            }
        }
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
        if (substr_count($location, ',') < $this->simplificationMaxLength) {
            if ($this->verbose) {
                echo substr_count($location, ',') . " elements, no need for simplification\n";
            }
            return $location;
        }
        $polygon = geoPHP::load($location, 'wkt');
        $tolerance = $this->simplificationTolerance;
        $simplifiedWKT = '';
        for ($try = 1; $try < 100; $try++) {
            $simplified = $polygon->simplify($tolerance);
            $simplifiedWKT = $simplified->out('wkt');
            if (!$this->simplificationMaxLength || substr_count($simplifiedWKT, ',') < $this->simplificationMaxLength) {
                break;
            } 
            $tolerance *= 2;
        }
        if ($this->verbose) {
            echo 'Simplified location from ' . substr_count($location, ',') . ' to ' . substr_count($simplifiedWKT, ',') . " elements\n";
        }
        if (substr_count($location, ',') > substr_count($simplifiedWKT, ',')) {
            $location = $simplifiedWKT;
        }
        return $location;
    }
}

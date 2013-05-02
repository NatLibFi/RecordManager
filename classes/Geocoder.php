<?php
/**
 * Geocoder Class
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

/**
 * Geocoder Class
 *
 * This is a base class for geocoding.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class Geocoder
{
    protected $db;
    protected $log;
    protected $verbose;

    /**
     * Constructor
     *
     * @param object  $db      Mongo database
     * @param object  $log     Logger 
     * @param boolean $verbose Whether to output verbose messages
     */
    public function __construct($db, $log, $verbose)
    {
        $this->db = $db;
        $this->log = $log;
        $this->verbose = $verbose;
    }

    /**
     * Initialize the geocoder with the settings from configuration file
     * 
     * @param array $settings Settings from the ini file
     * 
     * @return void
     */
    public function init($settings)
    {
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
    }

    /**
     * Reapply simplification with the current configuration parameters to all locations
     * 
     * @return void
     */
    public function resimplify()
    {
    }
}

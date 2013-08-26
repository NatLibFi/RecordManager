<?php
/**
 * RecordFactory Class
 *
 * PHP version 5
 *
 * Copyright (C) The National Library of Finland 2011-2012.
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
 * RecordFactory Class
 *
 * This is a factory class to build records for accessing metadata.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class RecordFactory
{
    /**
     * createRecord
     *
     * This constructs a metadata record driver for the specified format.
     *
     * @param string $format Metadata format
     * @param string $data   Metadata
     * @param string $oaiID  Record ID received from OAI-PMH
     * @param string $source Record source
     *
     * @return object       The record driver for handling the record.
     */
    static function createRecord($format, $data, $oaiID, $source)
    {
        global $configArray;
        
        if (isset($configArray['Record Classes'][$format])) {
            $class = $configArray['Record Classes'][$format];
        } else {
            $class = ucwords($format) . 'Record';
        }
        if (class_exists($class)) {
            $obj = new $class($data, $oaiID, $source);
            return $obj;
        }

        $path = "{$class}.php";
        include_once $path;
        if (class_exists($class)) {
            $obj = new $class($data, $oaiID, $source);
            return $obj;
        }

        throw new Exception("Could not load record driver for {$format}");
    }
}


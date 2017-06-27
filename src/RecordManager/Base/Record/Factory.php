<?php
/**
 * Record factory class
 *
 * PHP version 5
 *
 * Copyright (C) The National Library of Finland 2011-2017.
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
namespace RecordManager\Base\Record;

/**
 * Record factory class
 *
 * This is a factory class to build records for accessing metadata.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class Factory
{
    /**
     * Configured record classes
     *
     * @var array
     */
    protected $recordClasses;

    /**
     * Constructor
     *
     * @param array $recordClasses Record classes
     */
    public function __construct($recordClasses)
    {
        $this->recordClasses = $recordClasses;
    }

    /**
     * Check if a record driver for the specified format can be created.
     *
     * @param string $format Metadata format
     *
     * @return bool
     */
    public function canCreate($format)
    {
        $class = $this->getRecordClass($format);

        return class_exists($class);
    }

    /**
     * This constructs a metadata record driver for the specified format.
     *
     * @param string $format Metadata format
     * @param string $data   Metadata
     * @param string $oaiID  Record ID received from OAI-PMH
     * @param string $source Record source
     *
     * @return object       The record driver for handling the record.
     * @throws Exception
     */
    public function createRecord($format, $data, $oaiID, $source)
    {
        $idPrefix
            = isset($configArray['dataSourceSettings'][$source]['idPrefix'])
            ? $configArray['dataSourceSettings'][$source]['idPrefix']
            : $source;

        $class = $this->getRecordClass($format);

        if (class_exists($class)) {
            $obj = new $class($data, $oaiID, $source, $idPrefix);
            return $obj;
        }

        throw new \Exception("Could not load record driver '$class' for {$format}");
    }

    /**
     * Determine record class for the given format
     *
     * @param string $format Format
     *
     * @return string
     */
    protected function getRecordClass($format)
    {
        if (isset($this->recordClasses[$format])) {
            $class = $this->recordClasses[$format];
        } else {
            $class = ucfirst($format);
        }

        if (strpos($class, '\\') === false) {
            $class = "\\RecordManager\\Base\\Record\\$class";
        }

        return $class;
    }
}

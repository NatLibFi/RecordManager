<?php
/**
 * BaseRecord Class
 *
 * PHP version 5
 *
 * Copyright (C) Ere Maijala 2011-2012.
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
 * BaseRecord Class
 *
 * This is an abstract base class for processing records.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class BaseRecord
{
    // Record source ID
    protected $source;
    
    /**
     * Constructor
     *
     * @param string $data   Metadata
     * @param string $oaiID  Record ID received from OAI-PMH (or empty string for file import)
     * @param string $source Source ID
     */
    public function __construct($data, $oaiID, $source)
    {
        $this->source = $source;
    }

    /**
     * Return record ID (unique in the data source)
     *
     * @return string
     */
    public function getID()
    {
        die('unimplemented');
    }

    /**
     * Return record linking ID (typically same as ID) used for links
     * between records in the data source
     *
     * @return string
     */
    public function getLinkingID()
    {
        return $this->getID();
    }
    
    /**
     * Serialize the record for storing in the database
     *
     * @return string
     */
    public function serialize()
    {
        die('unimplemented');
    }
    
    /**
     * Serialize the record into XML for export
     *
     * @return string
     */
    public function toXML()
    {
        die('unimplemented');
    }
    
    /**
     * Normalize the record (optional)
     *
     * @return void
     */
    public function normalize()
    {
    }
    
    /**
     * Return whether the record is a component part 
     * 
     * @return boolean
     */
    public function getIsComponentPart()
    {
        return false;
    }
    
    /**
     * Return host record ID for component part
     *
     * @return string
     */
    public function getHostRecordID()
    {
        return '';
    }

    /**
     * Return fields to be indexed in Solr (an alternative to an XSL transformation)
     *
     * @return string[]
     */
    public function toSolrArray()
    {
        return '';
    }

    /**
     * Merge component parts to this record
     *
     * @param MongoCollection $componentParts Component parts to be merged
     * 
     * @return void
     */
    public function mergeComponentParts($componentParts)
    {
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
        return '';
    }

    /**
     * Component parts: get the volume that contains this component part
     * 
     * @return string
     */
    public function getVolume()
    {
        return '';
    }

    /**
     * Component parts: get the issue that contains this component part
     * 
     * @return string
     */
    public function getIssue()
    {
        return '';
    }
    
    /**
     * Component parts: get the start page of this component part in the host record
     * 
     * @return string
     */
    public function getStartPage()
    {
        return '';
    }
    
    /**
     * Component parts: get the container title
     *
     * @return string
     */
    public function getContainerTitle()
    {
        return '';
    }
    
    /**
     * Component parts: get the reference to the part in the container
     *
     * @return string
     */
    public function getContainerReference()
    {
        return '';
    }

    /**
     * Dedup: Return full title (for debugging purposes only)
     *
     * @return string
     */
    public function getFullTitle()
    {
        return '';
    }

    /**
     * Dedup: Return main author (format: Last, First)
     *
     * @return string
     */
    public function getMainAuthor()
    {
        return '';
    }

    /**
     * Dedup: Return unique IDs (control numbers)
     *
     * @return string[]
     */
    public function getUniqueIDs()
    {
        return array();
    }

    /**
     * Dedup: Return (unique) ISBNs in ISBN-13 format without dashes
     *
     * @return string[]
     */
    public function getISBNs()
    {
        return array();
    }

    /**
    * Dedup: Return ISSNs
    *
    * @return string[]
    */
    public function getISSNs()
    {
        return array();
    }
    
    /**
     * Dedup: Return series ISSN
     *
     * @return string
     */
    public function getSeriesISSN()
    {
        return '';
    }

    /**
     * Dedup: Return series numbering
     *
     * @return string
     */
    public function getSeriesNumbering()
    {
        return '';
    }

    /**
     * Dedup: Return format from predefined values
     *
     * @return string
     */
    public function getFormat()
    {
        return '';
    }

    /**
     * Dedup: Return publication year (four digits only)
     *
     * @return string
     */
    public function getPublicationYear()
    {
        return '';
    }

    /**
     * Dedup: Return page count (number only)
     *
     * @return string
     */
    public function getPageCount()
    {
        return '';
    }
    
    /**
     * Dedup: Add the dedup key to a suitable field in the metadata.
     * Used when exporting records to a file.
     *
     * @param string $dedupKey Dedup key to be added
     * 
     * @return void
     */
    public function addDedupKeyToMetadata($dedupKey)
    {
    }
}


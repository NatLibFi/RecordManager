<?php
/**
 * EAC-CPF Record Class
 *
 * PHP version 5
 *
 * Copyright (C) The National Library of Finland 2011-2018.
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

use RecordManager\Base\Utils\MetadataUtils;

/**
 * EAC-CPF Record Class
 *
 * This is a class for processing EAC-CPF records for an authority index.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class Eaccpf extends Base
{
    protected $doc = null;

    /**
     * Set record data
     *
     * @param string $source Source ID
     * @param string $oaiID  Record ID received from OAI-PMH (or empty string for
     *                       file import)
     * @param string $data   Metadata
     *
     * @return void
     */
    public function setData($source, $oaiID, $data)
    {
        parent::setData($source, $oaiID, $data);

        $this->doc = $this->parseXMLRecord($data);
    }

    /**
     * Return record ID (local)
     *
     * @return string
     */
    public function getID()
    {
        if (!isset($this->doc->control->recordId)) {
            throw new \Exception('No ID found for record: ' . $this->doc->asXML());
        }
        $id = (string) $this->doc->control->recordId;
        return urlencode($id);
    }

    /**
     * Serialize the record for storing in the database
     *
     * @return string
     */
    public function serialize()
    {
        return MetadataUtils::trimXMLWhitespace($this->doc->asXML());
    }

    /**
     * Serialize the record into XML for export
     *
     * @return string
     */
    public function toXML()
    {
        return $this->doc->asXML();
    }

    /**
     * Return fields to be indexed in Solr
     *
     * @return array
     */
    public function toSolrArray()
    {
        $data = [];

        $data['record_format'] = 'eaccpf';
        $data['fullrecord'] = MetadataUtils::trimXMLWhitespace($this->doc->asXML());
        $data['allfields'] = $this->getAllFields();
        $data['source'] = $this->getRecordSource();
        $data['record_type'] = $this->getRecordType();
        $data['heading'] = $this->getHeading();
        $data['use_for'] = $this->getUseForHeadings();
        $data['birth_date'] = $this->getBirthDate();
        $data['death_date'] = $this->getDeathDate();
        $data['birth_place'] = $this->getBirthPlace();
        $data['death_place'] = $this->getDeathPlace();
        $data['related_place'] = $this->getRelatedPlaces();
        $data['field_of_activity'] = $this->getFieldsOfActivity();
        $data['occupation'] = $this->getOccupations();
        $data['language'] = $this->getHeadingLanguage();

        return $data;
    }

    /**
     * Get agency name
     *
     * @return array
     */
    protected function getAgencyName()
    {
        return empty($this->doc->control->maintenanceAgency->agencyName)
            ? $this->source
            : (string)$this->doc->control->maintenanceAgency->agencyName;
    }

    /**
     * Get all XML fields
     *
     * @return string
     */
    protected function getAllFields()
    {
        $fields = [];
        if (!empty($this->doc->control->maintenanceAgency->agencyName)) {
            $fields[] = (string)$this->doc->control->maintenanceAgency->agencyName;
        }
        if (!empty($this->doc->cpfDescription->description->biogHist)) {
            foreach ($this->doc->cpfDescription->description->biogHist as $hist) {
                foreach ($hist->p as $p) {
                    $fields[] = (string)$p;
                }
            }
        }
        $fields[] = $this->getHeading();
        $fields = array_merge($fields, $this->getUseForHeadings());
        return $fields;
    }

    /**
     * Get birth date
     *
     * @return string
     */
    protected function getBirthDate()
    {
        $hasDates = isset(
            $this->doc->cpfDescription->description->existDates->dateSet->date
        );
        if (!$hasDates) {
            return '';
        }
        foreach ($this->doc->cpfDescription->description->existDates->dateSet->date
            as $date
        ) {
            $attrs = $date->attributes();
            $type = (string)$attrs->localType;
            if ('http://rdaregistry.info/Elements/a/P50121' === $type) {
                $d = (string)$attrs->standardDate;
                if (MetadataUtils::validateDate($d)) {
                    return $d;
                }
            }
        }
        return '';
    }

    /**
     * Get birth place
     *
     * @return string
     */
    protected function getBirthPlace()
    {
        if (!isset($this->doc->cpfDescription->description->places->place)) {
            return '';
        }
        foreach ($this->doc->cpfDescription->description->places->place as $place) {
            $attrs = $place->attributes();
            $type = $attrs->localType;
            if ('http://rdaregistry.info/Elements/a/P50119' == $type) {
                if ($place->placeEntry) {
                    return (string)$place->placeEntry;
                }
            }
        }
        return '';
    }

    /**
     * Get death date
     *
     * @return string
     */
    protected function getDeathDate()
    {
        $hasDates = isset(
            $this->doc->cpfDescription->description->existDates->dateSet->date
        );
        if (!$hasDates) {
            return '';
        }
        foreach ($this->doc->cpfDescription->description->existDates->dateSet->date
            as $date
        ) {
            $attrs = $date->attributes();
            $type = (string)$attrs->localType;
            if ('http://rdaregistry.info/Elements/a/P50120' === $type) {
                $d = (string)$attrs->standardDate;
                if (MetadataUtils::validateDate($d)) {
                    return $d;
                }
            }
        }
        return '';
    }

    /**
     * Get death place
     *
     * @return string
     */
    protected function getDeathPlace()
    {
        if (!isset($this->doc->cpfDescription->description->places->place)) {
            return '';
        }
        foreach ($this->doc->cpfDescription->description->places->place as $place) {
            $attrs = $place->attributes();
            $type = $attrs->localType;
            if ('http://rdaregistry.info/Elements/a/P50118' == $type) {
                if ($place->placeEntry) {
                    return (string)$place->placeEntry;
                }
            }
        }
        return '';
    }

    /**
     * Get fields of activity
     *
     * @return array
     */
    protected function getFieldsOfActivity()
    {
        if (!isset($this->doc->cpfDescription->description->functions->function)) {
            return [];
        }
        $result = [];
        foreach ($this->doc->cpfDescription->description->functions->function
            as $function
        ) {
            $attrs = $function->attributes();
            $type = $attrs->localType;
            if ('TJ37' == $type && $function->descriptiveNote->p) {
                $notes = [];
                foreach ($function->descriptiveNote->p as $p) {
                    $notes[] = (string)$p;
                }
                if ($notes) {
                    $result[] = implode('. ', $notes);
                }
            }
        }
        return $result;
    }

    /**
     * Get heading
     *
     * @return string
     */
    protected function getHeading()
    {
        if (!isset($this->doc->cpfDescription->identity->nameEntry->part)) {
            return '';
        }
        $name1 = '';
        $name2 = '';
        foreach ($this->doc->cpfDescription->identity->nameEntry->part as $part) {
            $type = $part->attributes()->localType;
            if ('TONI1' == $type) {
                $name1 = (string)$part;
            } elseif ('TONI4' == $type) {
                $name2 = (string)$part;
            }
        }
        return trim("$name1 $name2");
    }

    /**
     * Get heading language
     *
     * @return string
     */
    protected function getHeadingLanguage()
    {
        if (!isset($this->doc->cpfDescription->identity->nameEntry)) {
            return '';
        }
        $attrs = $this->doc->cpfDescription->identity->nameEntry->attributes();
        return (string)$attrs->language;
    }

    /**
     * Get occupations
     *
     * @return array
     */
    protected function getOccupations()
    {
        if (!isset($this->doc->cpfDescription->description->occupations->occupation)
        ) {
            return [];
        }
        $result = [];
        foreach ($this->doc->cpfDescription->description->occupations->occupation
            as $occupation
        ) {
            if ($occupation->term) {
                $result[] = (string)$occupation->term;
            }
        }
        return $result;
    }

    /**
     * Get related places
     *
     * @return array
     */
    protected function getRelatedPlaces()
    {
        if (!isset($this->doc->cpfDescription->description->places->place)) {
            return '';
        }
        $result = [];
        foreach ($this->doc->cpfDescription->description->places->place as $place) {
            $attrs = $place->attributes();
            $type = $attrs->localType;
            // Not place of death or birth..
            if ('http://rdaregistry.info/Elements/a/P50118' != $type
                && 'http://rdaregistry.info/Elements/a/P50119' != $type
            ) {
                if ($place->placeEntry) {
                    $result[] = (string)$place->placeEntry;
                }
            }
        }
        return $result;
    }

    /**
     * Get record source
     *
     * @return string
     */
    protected function getRecordSource()
    {
        return isset($this->doc->control->sources->source->sourceEntry)
            ? (string)$this->doc->control->sources->source->sourceEntry
            : $this->source;
    }

    /**
     * Get record type
     *
     * @return string
     */
    protected function getRecordType()
    {
        if (!isset($this->doc->cpfDescription->identity->entityType)) {
            return 'undefined';
        }
        return (string)$this->doc->cpfDescription->identity->entityType;
    }

    /**
     * Get use for headings
     *
     * @return string
     */
    protected function getUseForHeadings()
    {
        if (!isset($this->doc->cpfDescription->identity->nameEntryParallel)) {
            return [];
        }
        foreach ($this->doc->cpfDescription->identity->nameEntryParallel as $entry) {
            if (!isset($entry->nameEntry->part)) {
                continue;
            }
            $name1 = '';
            $name2 = '';
            foreach ($entry->nameEntry->part as $part) {
                $type = $part->attributes()->localType;
                if ('TONI1' == $type) {
                    $name1 = (string)$part;
                } elseif ('TONI4' == $type) {
                    $name2 = (string)$part;
                }
            }
            $s = trim("$name1 $name2");
            if ($s) {
                $result[] = $s;
            }
        }
        return implode(' ', $result);
    }
}

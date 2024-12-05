<?php

/**
 * EAC-CPF Record Class
 *
 * PHP version 8
 *
 * Copyright (C) The National Library of Finland 2011-2020.
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

namespace RecordManager\Base\Record;

use RecordManager\Base\Database\DatabaseInterface as Database;

/**
 * EAC-CPF Record Class
 *
 * This is a class for processing EAC-CPF records for an authority index.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @author   Samuli Sillanpää <samuli.sillanpaa@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class Eaccpf extends AbstractRecord
{
    use XmlRecordTrait;

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
        $id = (string)$this->doc->control->recordId;
        return urlencode($id);
    }

    /**
     * Return fields to be indexed in Solr
     *
     * @param ?Database $db Database connection. Omit to avoid database lookups for related records.
     *
     * @return array<string, mixed>
     */
    public function toSolrArray(?Database $db = null)
    {
        $data = [];

        $data['record_format'] = 'eaccpf';
        $data['fullrecord']
            = $this->metadataUtils->trimXMLWhitespace($this->doc->asXML());
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
     * @return string
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
     * @return array
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
        $fields = [...$fields, ...$this->getUseForHeadings()];
        return $fields;
    }

    /**
     * Get birth date
     *
     * @return string
     */
    protected function getBirthDate()
    {
        foreach ($this->doc->cpfDescription->description->existDates->dateSet->date ?? [] as $date) {
            $attrs = $date->attributes();
            $type = (string)$attrs->localType;
            if ('http://rdaregistry.info/Elements/a/P50121' === $type) {
                $d = (string)$attrs->standardDate;
                if ($date = $this->parseYear($d)) {
                    return $date;
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
        foreach ($this->doc->cpfDescription->description->places->place ?? [] as $place) {
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
        foreach ($this->doc->cpfDescription->description->existDates->dateSet->date ?? [] as $date) {
            $attrs = $date->attributes();
            $type = (string)$attrs->localType;
            if ('http://rdaregistry.info/Elements/a/P50120' === $type) {
                $d = (string)$attrs->standardDate;
                if ($date = $this->parseYear($d)) {
                    return $date;
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
        foreach ($this->doc->cpfDescription->description->places->place ?? [] as $place) {
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
     * Parse year.
     *
     * @param string $date Date
     *
     * @return null|string
     */
    protected function parseYear(string $date): ?string
    {
        return $this->metadataUtils->extractYear($date) ?: null;
    }

    /**
     * Get fields of activity
     *
     * @return array
     */
    protected function getFieldsOfActivity()
    {
        $result = [];
        foreach ($this->doc->cpfDescription->description->functions->function ?? [] as $function) {
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
        $name1 = '';
        $name2 = '';
        foreach ($this->doc->cpfDescription->identity->nameEntry->part ?? [] as $part) {
            $type = $part->attributes()->localType;
            if ('TONI1' == $type) {
                $name1 = (string)$part;
            } elseif ('TONI4' == $type) {
                $name2 = (string)$part;
            }
        }
        if (empty($name1) && empty($name2)) {
            if ($usefor = $this->getUseForHeadings()) {
                return $usefor[0];
            } else {
                return '';
            }
        } elseif (!empty($name1) && !empty($name2)) {
            return trim("$name1 $name2");
        } else {
            return !empty($name1) ? $name1 : $name2;
        }
    }

    /**
     * Get heading language
     *
     * @return string
     */
    protected function getHeadingLanguage()
    {
        if (!($language = $this->doc->control->languageDeclaration->language ?? null)) {
            return '';
        }
        $attrs = $language->attributes();
        return trim((string)$attrs->languageCode);
    }

    /**
     * Get occupations
     *
     * @return array
     */
    protected function getOccupations()
    {
        $result = [];
        foreach (
            $this->doc->cpfDescription->description->occupations->occupation ?? [] as $occupation
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
        $result = [];
        foreach ($this->doc->cpfDescription->description->places->place ?? [] as $place) {
            $attrs = $place->attributes();
            $type = (string)$attrs->localType;
            // Not place of death or birth..
            if (
                'http://rdaregistry.info/Elements/a/P50118' !== $type
                && 'http://rdaregistry.info/Elements/a/P50119' !== $type
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
        if ($name = trim((string)($this->doc->control->maintenanceAgency->agencyName ?? ''))) {
            return $name;
        }
        return $this->source;
    }

    /**
     * Get record type
     *
     * @return string
     */
    protected function getRecordType()
    {
        return (string)($this->doc->cpfDescription->identity->entityType ?? 'undefined');
    }

    /**
     * Get use for headings
     *
     * @return array<int, string>
     */
    protected function getUseForHeadings()
    {
        $result = [];
        foreach ($this->doc->cpfDescription->identity->nameEntryParallel ?? [] as $entry) {
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
        return $result;
    }
}

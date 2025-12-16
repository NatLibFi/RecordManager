<?php

/**
 * Marc authority Record Class
 *
 * PHP version 5
 *
 * Copyright (C) The National Library of Finland 2020.
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
 * @author   Samuli Sillanpää <samuli.sillanpaa@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */

namespace RecordManager\Base\Record;

use RecordManager\Base\Database\DatabaseInterface as Database;

/**
 * Forward authority Record Class
 *
 * This is a class for processing Forward records for an authority index.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Samuli Sillanpää <samuli.sillanpaa@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class MarcAuthority extends Marc
{
    /**
     * Delimiter for separating name related subfields.
     *
     * @var string
     */
    protected $nameDelimiter = ' / ';

    /**
     * Return record ID (local)
     *
     * @return string
     */
    public function getID()
    {
        return $this->getFieldSubfield('035', 'a');
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
        $data = parent::toSolrArray($db);

        $data['fullrecord'] = $this->getFullRecord();
        $data['allfields'] = $this->getAllFields();
        $data['source'] = $this->getRecordSource();
        $data['heading'] = $this->getHeading();
        $data['heading_keywords'] = $this->getHeadingKeywords();
        $data['use_for'] = $this->getUseForHeadings();
        $data['use_for_keywords'] = $this->getUseForHeadingKeywords();
        $data['record_type'] = $this->getRecordType();
        $data['birth_date'] = $this->getBirthDate();
        $data['death_date'] = $this->getDeathDate();
        $data['birth_place'] = $this->getBirthPlace();
        $data['death_place'] = $this->getDeathPlace();
        $data['country'] = $this->getCountry();
        $data['field_of_activity'] = $this->getFieldsOfActivity();
        $data['occupation'] = $this->getOccupations();

        return $data;
    }

    /**
     * Get fields of activity
     *
     * @param array<int, string> $additional List of additional fields to return
     *
     * @return array
     */
    public function getAlternativeNames($additional = [])
    {
        $result = [];
        $defaultFields = ['400', '410'];
        foreach ([...$defaultFields, ...$additional] as $code) {
            foreach ($this->record->getFields($code) as $field) {
                if ($activity = $this->record->getSubfield($field, 'a')) {
                    $result[] = $activity;
                }
            }
        }
        return $this->trimFields(array_unique($result));
    }

    /**
     * Get occupation control numbers (for enrichment)
     *
     * @return array
     */
    public function getOccupationIds(): array
    {
        return $this->record->getFieldsSubfields('374', ['0']);
    }

    /**
     * Get use for headings
     *
     * @return array
     */
    public function getUseForHeadings()
    {
        if (isset($this->resultCache[__METHOD__])) {
            return $this->resultCache[__METHOD__];
        }

        return $this->resultCache[__METHOD__] = $this->getAlternativeNames(['111', '411', '500', '510', '511']);
    }

    /**
     * Get use for heading keywords.
     *
     * @return array
     */
    public function getUseForHeadingKeywords(): array
    {
        return $this->getUseForHeadings();
    }

    /**
     * Get record format.
     *
     * @return string
     */
    protected function getRecordFormat(): string
    {
        return 'marcAuthority';
    }

    /**
     * Get fields of activity
     *
     * @return array
     */
    protected function getOccupations()
    {
        $result = [];
        foreach ($this->record->getFields('374') as $field) {
            if ($activity = $this->record->getSubfield($field, 'a')) {
                $result[] = $activity;
            }
        }
        return $result;
    }

    /**
     * Get fields of activity
     *
     * @return array
     */
    protected function getFieldsOfActivity()
    {
        $result = [];
        foreach ($this->record->getFields('372') as $field) {
            $result = [
                ...$result,
                ...$this->getSubfieldsArray($field, ['a']),
            ];
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
        if (isset($this->resultCache[__METHOD__])) {
            return $this->resultCache[__METHOD__];
        }

        $result = '';
        if ($name = $this->getFieldSubField('100', 'a', true)) {
            $result = rtrim($name, ' .');
        } else {
            foreach (['110', '111'] as $code) {
                if ($field = $this->record->getField($code)) {
                    if (!($sub = $this->record->getSubfield($field, 'a'))) {
                        continue;
                    }
                    $fields = [$sub];
                    $fields = [
                        ...$fields,
                        ...$this->getSubfieldsArray($field, ['b']),
                    ];
                    $result = implode($this->nameDelimiter, $this->trimFields($fields));
                    break;
                }
            }
        }
        return $this->resultCache[__METHOD__] = $result;
    }

    /**
     * Get heading keywords
     *
     * @return string
     */
    protected function getHeadingKeywords(): string
    {
        return $this->getHeading();
    }

    /**
     * Get record source
     *
     * @return string
     */
    protected function getRecordSource()
    {
        return $this->source;
    }

    /**
     * Get record type
     *
     * @return string
     */
    protected function getRecordType()
    {
        return $this->isPerson() ? 'Personal Name' : 'Corporate Name';
    }

    /**
     * Get birth date.
     *
     * @return string
     */
    protected function getBirthDate(): string
    {
        return $this->metadataUtils->extractYear($this->getFieldSubField('046', 'f'));
    }

    /**
     * Get death date.
     *
     * @return string
     */
    protected function getDeathDate(): string
    {
        return $this->metadataUtils->extractYear($this->getFieldSubField('046', 'g'));
    }

    /**
     * Get birth place.
     *
     * @return string
     */
    protected function getBirthPlace(): string
    {
        return $this->getFieldSubField('370', 'a');
    }

    /**
     * Get death place.
     *
     * @return string
     */
    protected function getDeathPlace(): string
    {
        return $this->getFieldSubField('370', 'b');
    }

    /**
     * Get country.
     *
     * @return string
     */
    protected function getCountry(): string
    {
        return $this->getFieldSubfield('370', 'c');
    }

    /**
     * Is this a Person authority record?
     *
     * @return boolean
     */
    protected function isPerson()
    {
        return !empty($this->record->getField('100'));
    }

    /**
     * Strip characters from the end of field values.
     *
     * @param array  $fields Field values.
     * @param string $mask   Character mask.
     *
     * @return array
     */
    protected function trimFields($fields, $mask = '. ')
    {
        return array_map(
            function ($field) use ($mask) {
                return $this->metadataUtils->stripTrailingPunctuation($field, $mask);
            },
            $fields
        );
    }
}

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
 * @link     https://github.com/KDK-Alli/RecordManager
 */
namespace RecordManager\Base\Record;

use RecordManager\Base\Utils\MetadataUtils;

/**
 * Forward authority Record Class
 *
 * This is a class for processing Forward records for an authority index.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Samuli Sillanpää <samuli.sillanpaa@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class MarcAuthority extends Marc
{
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
     * @return array
     */
    public function toSolrArray()
    {
        $data = [];

        $data['record_format'] = 'marcAuthority';
        $data['fullrecord'] = $this->toISO2709();
        if (!$data['fullrecord']) {
            // In case the record exceeds 99999 bytes...
            $data['fullrecord'] = $this->toXML();
        }

        $data['allfields'] = $this->getAllFields();
        $data['source'] = $this->getRecordSource();

        $heading = $this->getHeading();
        $data['heading'] = $data['heading_keywords'] = $heading;
        $data['use_for'] = $data['use_for_keywords'] = $this->getUseForHeadings();

        $data['record_type'] = $this->getRecordType();

        $data['birth_date']
            = MetadataUtils::extractYear($this->getFieldSubField('046', 'f'));
        $data['death_date']
            = MetadataUtils::extractYear($this->getFieldSubField('046', 'g'));

        $data['birth_place'] = $this->getFieldSubField('370', 'a');
        $data['death_place'] = $this->getFieldSubField('370', 'b');
        $data['country'] = $this->getFieldSubfield('370', 'c');
        $data['related_places_str_mv'] = $this->getRelatedPlaces();
        
        $data['field_of_activity'] = $this->getFieldsOfActivity();
        $data['occupation'] = $this->getOccupations();

        $data['datasource_str_mv'] = $data['source_str_mv'] = $this->source;

        $data['id_str_mv'] = [$this->getID()];

        foreach ($this->getFields('024') as $otherId) {
            $idSource = $this->getSubField($otherId, '2');
            $idVal = $this->getSubField($otherId, 'a');
            if (empty($idSource) || empty($idVal)) {
                continue;
            }
            $data['id_str_mv'][] = sprintf('(%s)%s', $idSource, $idVal);
        }
        
        return $data;
    }

    /**
     * Get fields of activity
     *
     * @param array $additional List of additional fields to return 
     *
     * @return array
     */
    public function getAlternativeNames($additional = [])
    {
        $result = [];
        foreach (array_merge(['400', '410', '500', '510'], $additional)
            as $code
        ) {
            foreach ($this->getFields($code) as $field) {
                if ($activity = $this->getSubfield($field, 'a')) {
                    $result[] = $activity;
                }
            }
        }
        return $this->trimFields(array_unique($result));
    }

    /**
     * Get fields of activity
     *
     * @return array
     */
    protected function getOccupations()
    {
        $result = [];
        foreach ($this->getFields('374') as $field) {
            if ($activity = $this->getSubfield($field, 'a')) {
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
        foreach ($this->getFields('372') as $field) {
            $result = array_merge(
                $result,
                $this->getSubfieldsArray($field, ['a' => 1])
            );
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
        if ($name = $this->getFieldSubField('100', 'a', true)) {
            return rtrim($name, ' .');
        }
        foreach (['110', '111'] as $code) {
            if ($field = $this->getFields($code)) {
                if (!$sub = $this->getSubfield($field[0], 'a')) {
                    continue;
                }
                $fields = [$sub];
                $fields = array_merge(
                    $fields, $this->getSubfieldsArray($field[0], ['b' => true])
                );
                return implode(' / ', $this->trimFields($fields));
            }
        }
        return '';
    }

    /**
     * Get use for headings
     *
     * @return array
     */
    public function getUseForHeadings()
    {
        return $this->getAlternativeNames(['111', '411', '511']);
    }

    /**
     * Get related places
     *
     * @return array
     */
    protected function getRelatedPlaces()
    {
        return array_unique(
            [
                $this->getFieldSubField('370', 'e', true),
                $this->getFieldSubField('370', 'f', true)
            ]
        );
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
     * Is this a Person authority record?
     *
     * @return boolean
     */
    protected function isPerson()
    {
        return !empty($this->getField('100'));
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
                return MetadataUtils::stripTrailingPunctuation($field, $mask);
            }, $fields
        );
    }
}

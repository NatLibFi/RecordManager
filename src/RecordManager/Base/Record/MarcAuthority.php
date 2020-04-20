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
        $data['use_for'] = $this->getUseForHeadings();

        $data['record_type'] = $this->getRecordType();

        $data['birth_date'] = $this->formatDate($this->getFieldSubField('046', 'f'));
        $data['death_date'] = $this->formatDate($this->getFieldSubField('046', 'g'));

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
        $name = $this->getFieldSubField('100', 'a', true);
        return !empty($name) ? $name : $this->getFieldSubField('110', 'a', true);
    }

    /**
     * Get use for headings
     *
     * @return string
     */
    protected function getUseForHeadings()
    {
        return array_unique(
            [
                $this->getHeading(),
                $this->getFieldSubField('400', 'a', true),
                $this->getFieldSubField('410', 'a', true),
                $this->getFieldSubField('410', 'b', true),
                $this->getFieldSubField('510', 'a', true)
            ]
        );
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
        if ($this->isPerson()) {
            return 'Personal Name';
        }
        $name = $this->getFieldSubField('368', 'a');
        return !empty($name) ? $name : 'Corporate Name';
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
     * Format date
     *
     * @param string $date   Date
     * @param string $format Format of converted date
     *
     * @return string
     */
    protected function formatDate($date, $format = 'j.n.Y')
    {
        if (false === (strpos($date, '-'))) {
            return $date;
        }
        if (false === ($time = strtotime($date))) {
            return $date;
        }
        return date($format, $time);
        
    }
}

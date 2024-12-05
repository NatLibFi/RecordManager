<?php

/**
 * Ead record class
 *
 * PHP version 8
 *
 * Copyright (C) The National Library of Finland 2011-2019.
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
 * @link     https://github.com/NatLibFi/RecordManager
 */

namespace RecordManager\Base\Record;

use RecordManager\Base\Database\DatabaseInterface as Database;

use function count;
use function in_array;

/**
 * Ead record class
 *
 * This is a class for processing EAD records.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class Ead extends AbstractRecord
{
    use XmlRecordTrait;

    /**
     * Archive fonds format
     *
     * @var string
     */
    protected $fondsType = 'fonds';

    /**
     * Archive collection format
     *
     * @var string
     */
    protected $collectionType = 'collection';

    /**
     * Archive series format
     *
     * @var string
     */
    protected $seriesType = 'series';

    /**
     * Archive subseries format
     *
     * @var string
     */
    protected $subseriesType = 'subseries';

    /**
     * Undefined type
     *
     * @var string
     */
    protected $undefinedType = null;

    /**
     * Field for geographic data
     *
     * @var string
     */
    protected $geoField = 'long_lat';

    /**
     * Field for geographic center coordinates
     *
     * @var string
     */
    protected $geoCenterField = '';

    /**
     * Return record ID (local)
     *
     * @return string
     */
    public function getID()
    {
        if (
            isset($this->doc->{'add-data'})
            && isset($this->doc->{'add-data'}->attributes()->identifier)
        ) {
            return (string)$this->doc->{'add-data'}->attributes()->identifier;
        }
        if (isset($this->doc->did->unitid)) {
            $id = isset($this->doc->did->unitid->attributes()->identifier)
                ? (string)$this->doc->did->unitid->attributes()->identifier
                : (string)$this->doc->did->unitid;
        } else {
            throw new \Exception('No ID found for record: ' . $this->doc->asXML());
        }
        return urlencode($id);
    }

    /**
     * Serialize the record for storing in the database
     *
     * @return string
     */
    public function serialize()
    {
        return $this->metadataUtils->trimXMLWhitespace($this->doc->asXML());
    }

    /**
     * Serialize the record into XML for export
     *
     * @return string
     */
    public function toXML()
    {
        $xml = $this->doc->asXML();
        if (false === $xml) {
            throw new \Exception(
                "Could not serialize record '{$this->source}."
                . $this->getId() . "' to XML"
            );
        }
        return (string)$xml;
    }

    /**
     * Return fields to be indexed in Solr
     *
     * @param Database $db Database connection. Omit to avoid database lookups for
     *                     related records.
     *
     * @return array<string, mixed>
     */
    public function toSolrArray(?Database $db = null)
    {
        $data = [];

        $doc = $this->doc;
        $data['record_format'] = 'ead';
        $data['ctrlnum'] = (string)$this->doc->attributes()->{'id'};
        $data['fullrecord'] = $this->metadataUtils->trimXMLWhitespace($doc->asXML());
        $data['allfields'] = $this->getAllFields($doc);

        if ($doc->scopecontent) {
            if ($doc->scopecontent->p) {
                // Join all p-elements into a flat string.
                $desc = [];
                foreach ($doc->scopecontent->p as $p) {
                    $desc[] = trim((string)$p);
                }
                $desc = implode('   /   ', $desc);
            } else {
                $desc = (string)$doc->scopecontent;
            }
            $data['description'] = $desc;
        }

        if ($names = $doc->xpath('controlaccess/persname')) {
            foreach ($names as $name) {
                if (trim((string)$name) !== '-') {
                    $data['author'][] = trim((string)$name);
                }
            }
        }
        if (!empty($data['author'])) {
            $data['author_sort'] = $data['author'][0];
        }

        if ($names = $doc->xpath('controlaccess/corpname')) {
            foreach ($names as $name) {
                $data['author_corporate'][] = trim((string)$name);
            }
        }

        if (!empty($doc->did->origination->corpname)) {
            $data['author_corporate'] = trim(
                (string)$doc->did->origination->corpname
            );
        }
        if (!empty($doc->did->origination->persname)) {
            $data['author2'] = trim(
                (string)$doc->did->origination->persname
            );
        }

        $data = array_merge($data, $this->getGeographicData());

        $data['topic'] = $data['topic_facet'] = $this->getTopics();

        $data['format'] = $this->getFormat();

        if (isset($doc->did->repository)) {
            $data['institution']
                = (string)($doc->did->repository->corpname ?? $doc->did->repository);
        }

        $data['series'] = $this->getSeries();
        $data['title_sub'] = $this->getSubtitle();
        $data['title_short'] = (string)$doc->did->unittitle;
        $data['title'] = '';
        // Ini handling returns true as '1':
        $prependTitle = $this->getDriverParam('prependTitleWithSubtitle', '1');
        if (
            '1' === $prependTitle
            || ('children' === $prependTitle && $this->doc->{'add-data'}->{'parent'})
        ) {
            if ($data['title_sub'] && $data['title_sub'] != $data['title_short']) {
                $data['title'] = $data['title_sub'] . ' ';
            }
        }
        $data['title'] .= $data['title_short'];
        $data['title_full'] = $data['title_sort'] = $data['title'];
        $data['title_sort'] = mb_strtolower(
            $this->metadataUtils->stripPunctuation($data['title_sort']),
            'UTF-8'
        );

        foreach ($doc->did->langmaterial ?? [] as $langmaterial) {
            foreach ($langmaterial->language ?? [] as $lang) {
                $l = $lang->attributes()->langcode ?? $lang;
                $data['language'][] = $this->metadataUtils
                    ->normalizeLanguageStrings($l);
            }
        }

        if ($extents = $doc->did->xpath('physdesc/extent')) {
            foreach ($extents as $extent) {
                if (trim((string)$extent) !== '-') {
                    $data['physical'][] = (string)$extent;
                }
            }
        }

        $nodes = isset($this->doc->did->daogrp)
            ? $this->doc->did->daogrp->xpath('daoloc[@role="image_thumbnail"]')
            : null;
        if ($nodes) {
            // store first thumbnail
            $node = $nodes[0];
            if (isset($node->attributes()->href)) {
                $data['thumbnail'] = (string)$node->attributes()->href;
            }
        }

        $data['hierarchytype'] = 'Default';
        if ($this->doc->{'add-data'}->archive) {
            $archiveAttr = $this->doc->{'add-data'}->archive->attributes();
            $data['hierarchy_top_id'] = (string)$archiveAttr->{'id'};
            $data['hierarchy_top_title'] = (string)$archiveAttr->title;
            if ($archiveAttr->subtitle) {
                $data['hierarchy_top_title'] .= ' : '
                    . (string)$archiveAttr->subtitle;
            }
            $data['allfields'][] = $data['hierarchy_top_title'];
            if ($archiveAttr->sequence) {
                $data['hierarchy_sequence'] = (string)$archiveAttr->sequence;
            }
        }
        if ($this->doc->{'add-data'}->{'parent'}) {
            $data['hierarchy_parent_id']
                = (string)$this->doc->{'add-data'}->{'parent'}->attributes()->{'id'};
            $data['allfields'][] = $data['hierarchy_parent_title']
                = (string)$this->doc->{'add-data'}->{'parent'}->attributes()->title;
        } else {
            $data['is_hierarchy_id'] = $data['hierarchy_top_id'] = $this->getID();
            $data['is_hierarchy_title'] = $data['hierarchy_top_title']
                = (string)$doc->did->unittitle;
        }
        if ($this->getDriverParam('addIdToHierarchyTitle', true)) {
            $data['title_in_hierarchy']
                = trim($this->getUnitId() . ' ' . $data['title']);
        }

        return $data;
    }

    /**
     * Return format from predefined values
     *
     * @return string|array
     */
    public function getFormat()
    {
        $genre = $this->doc->xpath('controlaccess/genreform');
        return (string)($genre ? $genre[0] : $this->doc->attributes()->level);
    }

    /**
     * Get all topic identifiers (for enrichment)
     *
     * @return array
     */
    public function getRawTopicIds(): array
    {
        return $this->getTopicTerms(true);
    }

    /**
     * Get all geographic topic identifiers (for enrichment)
     *
     * @return array
     */
    public function getRawGeographicTopicIds(): array
    {
        return [];
    }

    /**
     * Return main author (format: Last, First)
     *
     * @return string
     */
    public function getMainAuthor()
    {
        if ($names = $this->doc->xpath('controlaccess/persname')) {
            foreach ($names as $name) {
                if (trim((string)$name) !== '-') {
                    return trim((string)$name);
                }
            }
        }
        return trim((string)$this->doc->creator);
    }

    /**
     * Get topics.
     *
     * @return array
     */
    protected function getTopics()
    {
        return $this->getTopicTerms(false);
    }

    /**
     * Get topic identifiers.
     *
     * @return array
     */
    protected function getTopicIDs(): array
    {
        return $this->getRawTopicIds();
    }

    /**
     * Get topic labels or URIs.
     *
     * @param bool $identifiers Whether to return topic identifiers instead of labels
     *
     * @return array
     */
    protected function getTopicTerms($identifiers = false)
    {
        if ($subjects = $this->doc->xpath('controlaccess/subject')) {
            $result = [];
            foreach ($subjects as $subject) {
                if (!$identifiers) {
                    $label = trim((string)$subject);
                    if ($label !== '-') {
                        $result[] = $label;
                    }
                } else {
                    if ($subject->attributes()->href) {
                        $result[] = (string)$subject->attributes()->href;
                    }
                }
            }
            return $result;
        }
        return [];
    }

    /**
     * Return subtitle
     *
     * @return string
     */
    protected function getSubtitle()
    {
        $noSubtitleFormats = [
            $this->fondsType,
            $this->collectionType,
        ];
        if (in_array($this->getFormat(), $noSubtitleFormats)) {
            return '';
        }

        return $this->getUnitId();
    }

    /**
     * Return series title
     *
     * @return string
     */
    protected function getSeries()
    {
        $nonSeriesFormats = [
            $this->fondsType,
            $this->collectionType,
            $this->seriesType,
            $this->subseriesType,
            $this->undefinedType,
        ];

        if (in_array($this->getFormat(), $nonSeriesFormats)) {
            return '';
        }

        $addData = $this->doc->{'add-data'};
        if ($addData->parent) {
            $parentAttr = $addData->parent->attributes();
            if ($this->doc->{'add-data'}->archive) {
                // Check that parent is not top-level record (archive)
                $archiveAttr = $addData->archive->attributes();
                if (
                    isset($parentAttr->id) && isset($archiveAttr->id)
                    && (string)$parentAttr->id === (string)$archiveAttr->id
                ) {
                    return '';
                }
            }
            return (string)$parentAttr->title;
        }

        return '';
    }

    /**
     * Get unit id
     *
     * @return string
     */
    protected function getUnitId()
    {
        return (string)$this->doc->did->unitid;
    }

    /**
     * Get all XML fields
     *
     * @param \SimpleXMLElement $xml The XML document
     *
     * @return array<int, string>
     */
    protected function getAllFields($xml)
    {
        $allFields = [];
        foreach ($xml->children() as $field) {
            $s = trim((string)$field);
            if ($s) {
                $allFields[] = $s;
            }
            $s = $this->getAllFields($field);
            if ($s) {
                $allFields = [...$allFields, ...$s];
            }
        }
        return $allFields;
    }

    /**
     * Get geographic data
     * (geographic, geographic_facet, location_geo, center_coords)
     *
     * @return array
     */
    protected function getGeographicData()
    {
        $data = $names = $geoNames = [];

        foreach ($this->doc->controlaccess as $el) {
            foreach ($el->geogname as $name) {
                $geoNames[] = $name;
            }
        }

        foreach ($geoNames as $el) {
            if (!isset($el->part) && !isset($el->geographiccoordinates)) {
                // Text node with location name
                if (trim((string)$el) !== '-') {
                    $names[] = trim((string)$el);
                }
                continue;
            }

            // Node with 'part' and/or 'geographiccoordinates' childnodes
            if (isset($el->part)) {
                foreach ($el->part as $name) {
                    if (trim((string)$name) !== '-') {
                        $names[] = trim((string)$name);
                    }
                }
            }
            if (isset($el->geographiccoordinates)) {
                $attr = $el->geographiccoordinates->attributes();
                if (
                    isset($attr->coordinatesystem)
                    && (string)$attr->coordinatesystem === 'WGS84'
                ) {
                    $coordinates = array_map(
                        'trim',
                        explode(',', (string)$el->geographiccoordinates)
                    );
                    if (count($coordinates) !== 2) {
                        continue;
                    }
                    [$lat, $lon] = $coordinates;
                    if ($this->geoField) {
                        $data[$this->geoField] = "POINT($lon $lat)";
                    }
                    if ($this->geoCenterField) {
                        $data[$this->geoCenterField] = "$lon $lat";
                    }
                }
            }
        }
        if (!empty($names)) {
            $data['geographic'] = $data['geographic_facet'] = $names;
        }
        return $data;
    }
}

<?php
/**
 * Qdc record class
 *
 * PHP version 5
 *
 * Copyright (C) The National Library of Finland 2012-2017.
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
namespace RecordManager\Finna\Record;

use RecordManager\Base\Utils\MetadataUtils;

/**
 * Qdc record class
 *
 * This is a class for processing Qualified Dublin Core records.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class Qdc extends \RecordManager\Base\Record\Qdc
{
    /**
     * Return fields to be indexed in Solr (an alternative to an XSL transformation)
     *
     * @return array
     */
    public function toSolrArray()
    {
        $data = parent::toSolrArray();

        // Nonstandard author fields
        $authors = $this->getValues('author');
        if ($authors) {
            $data['author'] = array_shift($authors);
            if (isset($data['author2'])) {
                $data['author2'] = array_merge($authors, $data['author2']);
            } else {
                $data['author2'] = $authors;
            }
        }

        if (isset($data['publishDate'])) {
            $data['main_date_str']
                = MetadataUtils::extractYear($data['publishDate']);
            $data['main_date'] = $this->validateDate(
                $this->getPublicationYear() . '-01-01T00:00:00Z'
            );
        }

        if ($range = $this->getPublicationDateRange()) {
            $data['search_daterange_mv'][] = $data['publication_daterange']
                = MetadataUtils::dateRangeToStr($range);
        }

        foreach ($this->doc->relation as $relation) {
            $url = (string)$relation;
            // Ignore too long fields. Require at least one dot surrounded by valid
            // characters or a familiar scheme
            if (strlen($url) > 4096
                || (!preg_match('/[A-Za-z0-9]\.[A-Za-z0-9]/', $url)
                && !preg_match('/^(http|ftp)s?:\/\//', $url))
            ) {
                continue;
            }
            $link = [
                'url' => $url,
                'text' => '',
                'source' => $this->source
            ];
            $data['online_boolean'] = true;
            $data['online_str_mv'] = $this->source;
            // Mark everything free until we know better
            $data['free_online_boolean'] = true;
            $data['free_online_str_mv'] = $this->source;
            $data['online_urls_str_mv'][] = json_encode($link);
        }

        foreach ($this->doc->file as $file) {
            $url = (string)$file->attributes()->href
                ? (string)$file->attributes()->href
                : (string)$file;
            $link = [
                'url' => $url,
                'text' => (string)$file->attributes()->name,
                'source' => $this->source
            ];
            $data['online_boolean'] = true;
            $data['online_str_mv'] = $this->source;
            $data['free_online_boolean'] = true;
            // Mark everything free until we know better
            $data['free_online_str_mv'] = $this->source;
            $data['online_urls_str_mv'][] = json_encode($link);
            if (strcasecmp($file->attributes()->bundle, 'THUMBNAIL') == 0
                && !isset($data['thumbnail'])
            ) {
                $data['thumbnail'] = $url;
            }
        }

        if ($this->doc->permaddress) {
            $data['url'][] = (string)$this->doc->permaddress[0];
        }

        foreach ($this->getValues('identifier') as $identifier) {
            $res = preg_match(
                '/^(URN:NBN:fi:|URN:ISBN:978-?951|URN:ISBN:951)/i', $identifier
            );
            if ($res) {
                if (!empty($data['url'])) {
                    // Check that the identifier is not already listed
                    foreach ($data['url'] as $url) {
                        if (stristr($url, $identifier) !== false) {
                            continue 2;
                        }
                    }
                }
                $data['url'][] = "http://urn.fi/$identifier";
            }
        }

        foreach ($this->doc->coverage as $coverage) {
            $attrs = $coverage->attributes();
            if ($attrs->type == 'geocoding') {
                $match = preg_match(
                    '/([\d\.]+)\s*,\s*([\d\.]+)/', (string)$coverage, $matches
                );
                if ($match) {
                    if ($attrs->format == 'lon,lat') {
                        $lon = $matches[1];
                        $lat = $matches[2];
                    } else {
                        $lat = $matches[1];
                        $lon = $matches[2];
                    }
                    $data['location_geo'][] = "POINT($lon $lat)";
                }
            }
        }
        if (!empty($data['location_geo'])) {
            $data['center_coords']
                = MetadataUtils::getCenterCoordinates($data['location_geo']);
        }

        // Usage rights
        if ($rights = $this->getUsageRights()) {
            $data['usage_rights_str_mv'] = $rights;
        }

        $data['source_str_mv'] = $this->source;
        $data['datasource_str_mv'] = $this->source;

        return $data;
    }

    /**
     * Return publication year/date range
     *
     * @return array|null
     */
    protected function getPublicationDateRange()
    {
        $year = $this->getPublicationYear();
        if ($year) {
            $startDate = "$year-01-01T00:00:00Z";
            $endDate = "$year-12-31T23:59:59Z";
            return [$startDate, $endDate];
        }
        return null;
    }

    /**
     * Return usage rights if any
     *
     * @return array ['restricted'] or more specific id's if defined for the record
     */
    protected function getUsageRights()
    {
        if (!isset($this->doc->rights)) {
            return ['restricted'];
        }
        $result = [];
        foreach ($this->doc->rights as $rights) {
            $result[] = (string)$rights;
        }
        return $result;
    }
}

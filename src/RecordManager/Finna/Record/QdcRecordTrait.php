<?php
/**
 * Qdc record trait.
 *
 * PHP version 7
 *
 * Copyright (C) The National Library of Finland 2019-2020.
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
namespace RecordManager\Finna\Record;

use RecordManager\Base\Database\DatabaseInterface as Database;

/**
 * Qdc record trait.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @author   Samuli Sillanpää <samuli.sillanpaa@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
trait QdcRecordTrait
{
    /**
     * Return fields to be indexed in Solr
     *
     * @param Database $db Database connection. Omit to avoid database lookups for
     *                     related records.
     *
     * @return array
     */
    public function toSolrArray(Database $db = null)
    {
        $data = parent::toSolrArray($db);

        if (isset($data['publishDate'])) {
            $data['main_date_str']
                = $this->metadataUtils->extractYear($data['publishDate']);
            $data['main_date'] = $this->validateDate(
                $this->getPublicationYear() . '-01-01T00:00:00Z'
            );
        }

        if ($range = $this->getPublicationDateRange()) {
            $data['search_daterange_mv'][] = $data['publication_daterange']
                = $this->metadataUtils->dateRangeToStr($range);
        }

        foreach ($this->doc->relation as $relation) {
            $url = trim((string)$relation);
            // Ignore too long fields. Require at least one dot surrounded by valid
            // characters or a familiar scheme
            if (strlen($url) > 4096
                || (!preg_match('/^[A-Za-z0-9]\.[A-Za-z0-9]$/', $url)
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
                ? trim((string)$file->attributes()->href)
                : trim((string)$file);
            $link = [
                'url' => $url,
                'text' => trim((string)$file->attributes()->name),
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

        foreach ($this->doc->coverage as $coverage) {
            $attrs = $coverage->attributes();
            if ($attrs->type == 'geocoding') {
                $match = preg_match(
                    '/([\d\.]+)\s*,\s*([\d\.]+)/',
                    trim((string)$coverage),
                    $matches
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
                = $this->metadataUtils->getCenterCoordinates($data['location_geo']);
        }

        // Usage rights
        if ($rights = $this->getUsageRights()) {
            $data['usage_rights_str_mv'] = $rights;
        }

        $data['source_str_mv'] = $this->source;
        $data['datasource_str_mv'] = $this->source;

        $data['author_facet'] = array_merge(
            $this->getPrimaryAuthors(),
            $this->getSecondaryAuthors(),
            $this->getCorporateAuthors()
        );

        $data['format_ext_str_mv'] = $data['format'];

        return $data;
    }

    /**
     * Get secondary authors
     *
     * @return array
     */
    protected function getSecondaryAuthors()
    {
        return array_merge(
            parent::getSecondaryAuthors(),
            $this->getValues('author') ?? []
        );
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
        // Try to find useful rights, fall back to the first entry if not found
        $firstRights = '';
        foreach ($this->doc->rights as $rights) {
            if ('' === $firstRights) {
                $firstRights = (string)$rights;
            }
            if ($rights->attributes()->lang) {
                // Language string, hope for something better
                continue;
            }
            $type = (string)$rights->attributes()->type;
            if ('' !== $type && 'url' !== $type) {
                continue;
            }
            $rights = trim((string)$rights);
            $result[] = $rights;
        }
        if (!$result && $firstRights) {
            $result[] = $firstRights;
        }
        $result = array_map(
            function ($s) {
                // Convert lowercase CC rights to uppercase
                if (strncmp($s, 'cc', 2) === 0) {
                    $s = mb_strtoupper($s, 'UTF-8');
                }
                return $s;
            },
            $result
        );
        return $result;
    }

    /**
     * Return URLs associated with object
     *
     * @return array
     */
    protected function getUrls()
    {
        $urls = parent::getUrls();

        if ($this->doc->permaddress) {
            $urls[] = trim((string)$this->doc->permaddress[0]);
        }

        foreach ($this->getValues('identifier') as $identifier) {
            $res = preg_match(
                '/^(URN:NBN:fi:|URN:ISBN:978-?951|URN:ISBN:951)/i',
                $identifier
            );
            if ($res) {
                if (!empty($urls)) {
                    // Check that the identifier is not already listed
                    foreach ($urls as $url) {
                        if (stristr($url, $identifier) !== false) {
                            continue 2;
                        }
                    }
                }
                $urls[] = "http://urn.fi/$identifier";
            }
        }

        return $urls;
    }
}

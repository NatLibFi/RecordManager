<?php

/**
 * MusicBrainzEnrichment Class
 *
 * PHP version 8
 *
 * Copyright (C) The National Library of Finland 2019-2023.
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

namespace RecordManager\Base\Enrichment;

/**
 * MusicBrainzEnrichment Class
 *
 * Adds mbid_str_mv fields to the record if found in MusicBrainz database.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class MusicBrainzEnrichment extends AbstractEnrichment
{
    /**
     * Catalog number
     *
     * @var string
     */
    protected const CATNO = 'catno';

    /**
     * Barcode
     *
     * @var string
     */
    protected const BARCODE = 'barcode';

    /**
     * Release id
     *
     * @var string
     */
    protected const REID = 'reid';

    /**
     * MusicBrainz API base url
     *
     * @var string
     */
    protected $baseURL;

    /**
     * Initialize settings
     *
     * @return void
     */
    public function init()
    {
        parent::init();

        // Allow overriding of default cache expiration:
        $expiration = $this->config['MusicBrainzEnrichment']['cache_expiration']
            ?? null;
        if (null !== $expiration) {
            $this->maxCacheAge = 60 * $expiration;
        }
    }

    /**
     * Enrich the record and return any additions in solrArray
     *
     * @param string $sourceId  Source ID
     * @param object $record    Metadata Record
     * @param array  $solrArray Metadata to be sent to Solr
     *
     * @return void
     */
    public function enrich($sourceId, $record, &$solrArray)
    {
        $this->baseURL = $this->config['MusicBrainzEnrichment']['url'] ?? '';
        if (
            empty($this->baseURL)
            || !($record instanceof \RecordManager\Base\Record\Marc)
        ) {
            return;
        }

        $mbIds = [];
        foreach ($record->getMusicIds() as $identifier) {
            $type = $this->sanitizeId($identifier['type']);
            $newIds = [];
            switch ($type) {
                case 'ian':
                case 'upc':
                    $id = $this->sanitizeId($identifier['id']);
                    $newIds = $this->getFromReleaseIndex(self::BARCODE, $id);
                    break;
                case 'musicb':
                    // Do no sanitize mbid, as it is sensitive search
                    $newIds = $this->getFromReleaseIndex(self::REID, $identifier['id']);
                    break;
                default:
                    continue 2;
            }
            $mbIds = [...$mbIds, ...$newIds];
        }

        // Use publisher ids only if barcodes or musicbrainz id did not yield any results
        if (!$mbIds) {
            $shortTitle = $record->getShortTitleForEnrichment();
            foreach ($record->getPublisherNumbers(['0']) as $number) {
                if ($id = trim($number['id'])) {
                    $newIds = $this->getFromReleaseIndex(self::CATNO, $id, $shortTitle);
                    $mbIds = [...$mbIds, ...$newIds];
                }
            }
        }

        if ($mbIds) {
            $solrArray['mbid_str_mv'] = $mbIds;
        }
    }

    /**
     * Sanitize an identifier
     *
     * @param string $id Identifier
     *
     * @return string
     */
    protected function sanitizeId($id)
    {
        $id = preg_replace('/[\s\(\[].*$/', '', $id);
        $id = $this->metadataUtils->normalizeKey($id);
        return $id;
    }

    /**
     * Get MusicBrainz ids from release index
     *
     * @param string $type          Type for the search
     * @param string $id            Id to search for
     * @param string $releaseAccent Short version of the title to look for
     *
     * @return array
     */
    protected function getFromReleaseIndex(string $type, string $id, string $releaseAccent = ''): array
    {
        $searchFor = $type === self::REID ? urlencode($id) : addcslashes($id, '"\\');
        $query = "$type:\"$searchFor\"";
        if ($releaseAccent) {
            $query .= " AND releaseaccent:\"$releaseAccent\"";
        }
        $params = [
            'query' => $query,
            'fmt' => 'json',
        ];

        $url = $this->baseURL . '/ws/2/release?' . http_build_query($params);
        $results = [];
        if ($data = $this->getExternalData($url, $query)) {
            $data = json_decode($data, true);
            foreach ($data['releases'] ?? [] as $release) {
                $results[] = $release['id'];
            }
        }

        return $results;
    }
}

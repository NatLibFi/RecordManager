<?php
/**
 * MusicBrainzEnrichment Class
 *
 * PHP version 7
 *
 * Copyright (C) The National Library of Finland 2019-2022.
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
     * MusicBrainz API base url
     *
     * @var string
     */
    protected $baseURL;

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
        if (empty($this->baseURL)
            || !($record instanceof \RecordManager\Base\Record\Marc)
        ) {
            return;
        }

        $mbIds = [];
        foreach ($record->getMusicIds() as $identifier) {
            $id = $this->sanitizeId($identifier['id']);
            $type = $this->sanitizeId($identifier['type']);
            switch ($type) {
            case 'isrc':
                break;
            case 'upc':
            case 'ismn':
            case 'ian':
                $type = 'catno';
                break;
            case 'musicb':
                $type = 'reid';
                break;
            default:
                continue 2;
            }
            $query = "$type:\"" . addcslashes($id, '"\\') . '"';
            if ('catno' === $type) {
                $query .= ' AND releaseaccent:"'
                    . addcslashes($solrArray['title_short'], '"\\')
                    . '"';
            }
            $newIds = $this->getMBIDs($query);
            $mbIds = [...$mbIds, ...$newIds];
        }

        $shortTitle = $record->getShortTitle();

        foreach ($record->getPublisherNumbers() as $number) {
            $id = $this->sanitizeId($number['id']);
            $source = $this->sanitizeId($number['source']);
            $newIds = [];
            if ($id && $source) {
                $query = 'catno:"' . addcslashes("$source $id", '"\\') . '"';
                $newIds = $this->getMBIDs($query);
            }
            if (!$newIds && $id) {
                $query = 'catno:"' . addcslashes($id, '"\\')
                    . '" AND releaseaccent:"'
                    . addcslashes($shortTitle, '"\\')
                    . '"';
                $newIds = $this->getMBIDs($query);
            }
            if ($newIds) {
                $mbIds = [...$mbIds, ...$newIds];
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
     * Get MusicBrainz identifiers with a standard identifier
     *
     * @param string $query     Query
     * @param bool   $skipGroup Whether to skip checking release group
     *
     * @return array<int, string>
     */
    protected function getMBIDs($query, $skipGroup = false)
    {
        $results = [];

        // Search for a release
        $params = [
            'query' => $query,
            'fmt' => 'json'
        ];
        $url = $this->baseURL . '/ws/2/release?' . http_build_query($params);
        $data = $this->getExternalData($url, $query);
        if ($data) {
            $data = json_decode($data, true);
            foreach ($data['releases'] as $release) {
                if (!$skipGroup && ($rgid = $release['release-group']['id'])) {
                    $url = $this->baseURL . '/ws/2/release/?query=rgid:'
                        . urlencode($rgid) . '&fmt=json';
                    $rgData = $this->getExternalData($url, "rgid:$rgid");
                    if ($rgData) {
                        $rgData = json_decode($rgData, true);
                        foreach ($rgData['releases'] as $rgRelease) {
                            $results[] = $rgRelease['id'];
                        }
                    }
                } else {
                    $results[] = $release['id'];
                }
            }
        }
        return $results;
    }
}

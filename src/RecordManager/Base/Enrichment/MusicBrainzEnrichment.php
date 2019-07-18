<?php
/**
 * MusicBrainzEnrichment Class
 *
 * PHP version 5
 *
 * Copyright (C) The National Library of Finland 2019.
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
namespace RecordManager\Base\Enrichment;

use RecordManager\Base\Utils\Logger;
use RecordManager\Base\Utils\MetadataUtils;

/**
 * MusicBrainzEnrichment Class
 *
 * Adds mbid_str_mv fields to the record if found in MusicBrainz database.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class MusicBrainzEnrichment extends Enrichment
{
    /**
     * MusicBrainz API base url
     *
     * @var string
     */
    protected $baseURL;

    /**
     * Constructor
     *
     * @param Database $db     Database connection (for cache)
     * @param Logger   $logger Logger
     * @param array    $config Main configuration
     */
    public function __construct($db, $logger, $config)
    {
        parent::__construct($db, $logger, $config);

        $this->baseURL
            = isset($this->config['MusicBrainzEnrichment']['url'])
            ? $this->config['MusicBrainzEnrichment']['url']
            : '';
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
        if (empty($this->baseURL)
            || !($record instanceof \RecordManager\Base\Record\Marc)
        ) {
            return;
        }

        $leader = $record->getField('000');
        if (substr($leader, 6, 1) !== 'j') {
            return;
        }

        $mbIds = [];
        foreach ($record->getFields('024') as $field024) {
            $ind1 = $record->getIndicator($field024, 1);
            if (in_array($ind1, ['0', '1', '2', '3', '7'])
                && ($id = $this->sanitizeId($record->getSubfield($field024, 'a')))
            ) {
                switch ($ind1) {
                case '0':
                    $type = 'isrc';
                    break;
                case '7':
                    $source = $record->getSubfield($field024, '2');
                    if ('musicb' !== $source) {
                        continue 2;
                    }
                    $type = 'reid';
                    break;
                default:
                    $type = 'catno';
                }
                $query = "$type:\"" . addcslashes($id, "\"\\") . "\"";
                if ('catno' === $type) {
                    $query .= ' AND releaseaccent:"'
                        . addcslashes($solrArray['title_short'], "\"\\")
                        . '"';
                }
                $mbIds = array_merge($mbIds, $this->getMBIDs($query));
            }
        }
        foreach ($record->getFields('028') as $field028) {
            if ($id = $this->sanitizeId($record->getSubfield($field028, 'a'))) {
                $query = "catno:\"" . addcslashes($id, "\"\\")
                    . "\" AND releaseaccent:\""
                    . addcslashes($solrArray['title_short'], "\"\\")
                    . '"';
                $mbIds = array_merge($mbIds, $this->getMBIDs($query));
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
        $id = MetadataUtils::normalizeKey($id);
        return $id;
    }

    /**
     * Get MusicBrainz identifiers with a standard identifier
     *
     * @param string $query     Query
     * @param bool   $skipGroup Whether to skip checking release group
     *
     * @return array
     */
    protected function getMBIDs($query, $skipGroup = false)
    {
        $results = [];

        // Search for a release
        $params = [
            'query' => $query,
            'fmt' => 'json'
        ];
        $url = $this->baseURL . '/ws/2/release?'
            . http_build_query($params);
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

<?php
/**
 * MarcOnkiLightEnrichment Class
 *
 * PHP version 5
 *
 * Copyright (C) The National Library of Finland 2014.
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

/**
 * MarcOnkiLightEnrichment Class
 *
 * This is a class for enrichment of MARC records from an ONKI Light source.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class MarcOnkiLightEnrichment extends Enrichment
{
    /**
     * ONKI Light API base url
     *
     * @var string
     */
    protected $onkiLightBaseURL;

    /**
     * Constructor
     *
     * @param Database $db     Database connection (for cache)
     * @param Logger   $log    Logger
     * @param array    $config Main configuration
     */
    public function __construct($db, $log, $config)
    {
        parent::__construct($db, $log, $config);

        $this->onkiLightBaseURL
            = isset($this->config['MarcOnkiLightEnrichment']['base_url'])
            ? $this->config['MarcOnkiLightEnrichment']['base_url']
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
        if (empty($this->onkiLightBaseURL)) {
            return;
        }
        $this->enrichField($sourceId, $record, $solrArray, '650', 'topic');
        $this->enrichField($sourceId, $record, $solrArray, '651', 'geographic');
    }

    /**
     * Enrich the record and return any additions in solrArray
     *
     * @param string $sourceId  Source ID
     * @param object $record    Record
     * @param array  $solrArray Metadata to be sent to Solr
     * @param string $marcField MARC field code to use
     * @param string $solrField Target Solr field
     *
     * @return void
     */
    protected function enrichField($sourceId, $record, &$solrArray,
        $marcField, $solrField
    ) {
        foreach ($record->getFields($marcField) as $field) {
            $id = $record->getSubfield($field, '0');
            if (!$id) {
                continue;
            }
            $solrArray[$solrField . '_uri_str_mv'][] = $id;
            // Fetch alternate language expressions
            $url = $id;
            if (strncmp($id, 'http', 4) !== 0) {
                $url = $this->onkiLightBaseURL . '/data?format=application/json&uri='
                    . urlencode($id);
            }
            $data = $this->getExternalData(
                $url, $id, ['Accept' => 'application/json']
            );
            if ($data) {
                $data = json_decode($data, true);
                if (!isset($data['graph'])) {
                    continue;
                }
                foreach ($data['graph'] as $item) {
                    if (!isset($item['type'])) {
                        continue;
                    }
                    if (is_array($item['type'])) {
                        if (!in_array('skos:Concept', $item['type'])) {
                            continue;
                        }
                    } elseif ($item['type'] != 'skos:Concept') {
                        continue;
                    }
                    if ($item['uri'] == $id && isset($item['altLabel']['value'])) {
                        $solrArray[$solrField][] = $item['altLabel']['value'];
                    }

                    if (!empty($item['skos:exactMatch']['uri'])) {
                        $matchURL = $matchId = $item['skos:exactMatch']['uri'];
                        if (strncmp($matchURL, 'http', 4) !== 0) {
                            $matchURL = $this->onkiLightBaseURL
                                . '/data?format=application/json&uri='
                                . urlencode($matchId);
                        }
                        $matchData = $this->getExternalData(
                            $matchURL, $matchId,
                            ['Accept' => 'application/json']
                        );
                        if (!$matchData) {
                            continue;
                        }
                        $matchData = json_decode($matchData, true);
                        if (!isset($matchData['graph'])) {
                            continue;
                        }
                        foreach ($matchData['graph'] as $matchItem) {
                            if ($matchItem['uri'] != $matchId) {
                                continue;
                            }
                            if (is_array($matchItem['type'])) {
                                if (!in_array('skos:Concept', $matchItem['type'])) {
                                    continue;
                                }
                            } elseif ($matchItem['type'] != 'skos:Concept') {
                                continue;
                            }
                            if (isset($matchItem['altLabel']['value'])) {
                                $solrArray[$solrField][]
                                    = $matchItem['altLabel']['value'];
                            }
                            if (isset($matchItem['prefLabel']['value'])) {
                                $solrArray[$solrField][]
                                    = $matchItem['prefLabel']['value'];
                            }
                        }
                    }
                }
            }
        }
    }
}

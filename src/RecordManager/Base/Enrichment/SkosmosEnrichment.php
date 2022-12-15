<?php
/**
 * Skosmos Enrichment Class
 *
 * PHP version 7
 *
 * Copyright (C) The National Library of Finland 2014-2022.
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
 * @author   Samuli Sillanpää <samuli.sillanpaa@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
namespace RecordManager\Base\Enrichment;

use RecordManager\Base\Record\AbstractRecord;

/**
 * Skosmos Enrichment Class
 *
 * This is a base class for enrichment from a Skosmos instance. This class can be
 * used as the only enrichment to handle all records, or record format specific
 * classes can be enabled as needed.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @author   Samuli Sillanpää <samuli.sillanpaa@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class SkosmosEnrichment extends AbstractEnrichment
{
    /**
     * Prefix for SKOS core
     *
     * @var string
     */
    public const SKOS_CORE = 'http://www.w3.org/2004/02/skos/core#';

    /**
     * Prefix for WGS84 position data
     *
     * @var string
     */
    public const WGS84_POS = 'http://www.w3.org/2003/01/geo/wgs84_pos#';

    /**
     * API base url
     *
     * @var string
     */
    protected $apiBaseURL;

    /**
     * List of allowed URL prefixes to try to fetch
     *
     * @var array
     */
    protected $urlPrefixAllowedList;

    /**
     * List of URI prefixes for which to process other vocabularies with
     * exact matches
     *
     * @var array
     */
    protected $uriPrefixExactMatches;

    /**
     * Solr field to use for the location data
     *
     * @var string
     */
    protected $solrLocationField = '';

    /**
     * Solr field to use for the center coordinates of locations
     *
     * @var string
     */
    protected $solrCenterField = '';

    /**
     * Languages to allow
     *
     * @var array
     */
    protected $languages = [];

    /**
     * Cache for recent records
     *
     * @var ?\cash\LRUCache
     */
    protected $recordCache = null;

    /**
     * Cache for recent enrichment results
     *
     * @var ?\cash\LRUCache
     */
    protected $enrichmentCache = null;

    /**
     * Initialize settings
     *
     * @return void
     */
    public function init()
    {
        parent::init();

        // Read from SkosmosEnrichment but allow OnkiLightEnrichment for
        // back-compatibility
        $settings = $this->config['SkosmosEnrichment']
            ?? $this->config['OnkiLightEnrichment']
            ?? [];
        $this->apiBaseURL = $settings['base_url'] ?? '';

        // whitelist kept for back-compatibility
        $list = $settings['url_prefix_allowed_list']
            ?? $settings['url_prefix_whitelist']
            ?? [];
        $this->urlPrefixAllowedList = (array)$list;

        $this->uriPrefixExactMatches = $settings['uri_prefix_exact_matches'] ?? [];

        if (isset($settings['solr_location_field'])) {
            $this->solrLocationField = $settings['solr_location_field'];
        }
        if (isset($settings['solr_center_field'])) {
            $this->solrCenterField = $settings['solr_center_field'];
        }
        if (!empty($settings['languages'])) {
            $this->languages = (array)$settings['languages'];
        }

        if ($cacheSize = $settings['record_cache_size'] ?? 1000) {
            $this->recordCache = new \cash\LRUCache((int)$cacheSize);
        }

        if ($cacheSize = $settings['enrichment_cache_size'] ?? 10000) {
            $this->enrichmentCache = new \cash\LRUCache((int)$cacheSize);
        }
    }

    /**
     * Enrich the record and return any additions in solrArray
     *
     * @param string $sourceId  Source ID
     * @param object $record    Metadata Record
     * @param array  $solrArray Metadata to be sent to Solr
     *
     * @throws \Exception
     * @return void
     */
    public function enrich($sourceId, $record, &$solrArray)
    {
        if (!($record instanceof \RecordManager\Base\Record\Marc)) {
            return;
        }
        $fields = [
            'getRawTopicIds' => [
                'pref' => 'topic_add_txt_mv',
                'alt' => 'topic_alt_txt_mv',
                'check' => 'topic'
            ],
            'getRawGeographicTopicIds' => [
                'pref' => 'geographic_add_txt_mv',
                'alt' => 'geographic_alt_txt_mv',
                'check' => 'geographic'
            ]
        ];
        foreach ($fields as $method => $spec) {
            foreach (call_user_func([$record, $method]) as $id) {
                $this->enrichField(
                    $sourceId,
                    $record,
                    $solrArray,
                    $id,
                    $spec['pref'],
                    $spec['alt'],
                    $spec['check']
                );
            }
        }
    }

    /**
     * Enrich the record and return any additions in solrArray
     *
     * @param string         $sourceId           Source ID
     * @param AbstractRecord $record             Metadata record
     * @param array          $solrArray          Metadata to be sent to Solr
     * @param string         $id                 Entity id
     * @param string         $solrPrefField      Target Solr field for preferred
     *                                           values (e.g. for terms in other
     *                                           languages)
     * @param string         $solrAltField       Target Solr field for alternative
     *                                           values
     * @param string         $solrCheckField     Solr field to check for existing
     *                                           values
     * @param bool           $includeInAllfields Whether to include the enriched
     *                                           value also in allFields
     *
     * @return void
     */
    protected function enrichField(
        string $sourceId,
        AbstractRecord $record,
        &$solrArray,
        $id,
        $solrPrefField,
        $solrAltField,
        $solrCheckField = '',
        $includeInAllfields = false
    ) {
        if (!$this->apiBaseURL) {
            return;
        }

        // Clean up any invalid characters from the id
        $id = trim(
            str_replace(
                ['|', '!', '"', '#', '€', '$', '%', '&', '<', '>'],
                [],
                $id
            )
        );

        // Get enrichment results
        $data = null;
        if ($this->enrichmentCache) {
            $data = $this->enrichmentCache->get($id);
        }
        if (null === $data) {
            $data = $this->getEnrichmentData($id, "$sourceId." . $record->getID());
            if ($this->enrichmentCache) {
                $this->enrichmentCache->put($id, $data);
            }
        }

        if (!$data['preferred'] && !$data['alternative']) {
            return;
        }

        // Process results
        $checkFieldContents = $solrCheckField
            ? array_map(
                function ($s) {
                    return mb_strtolower($s, 'UTF-8');
                },
                (array)($solrArray[$solrCheckField] ?? [])
            ) : [];

        $map = [
            'preferred' => $solrPrefField,
            'alternative' => $solrAltField,
            'matchPreferred' => $solrPrefField,
            'matchAlternative' => $solrAltField,
        ];

        foreach ($map as $dataKey => $solrField) {
            foreach ($data[$dataKey] as $label) {
                $labelLc = mb_strtolower($label, 'UTF-8');
                if (in_array($labelLc, $checkFieldContents)) {
                    continue;
                }
                $checkFieldContents[] = $labelLc;
                if ($solrField) {
                    $solrArray[$solrField][] = $label;
                }
                if ($includeInAllfields) {
                    $solrArray['allfields'][] = $label;
                }
            }
        }

        if ($this->solrCenterField || $this->solrLocationField) {
            foreach ($data['locations'] as $location) {
                if ($this->solrCenterField
                    && !isset($solrArray[$this->solrCenterField])
                ) {
                    $solrArray[$this->solrCenterField]
                        = $location['lat'] . ', ' . $location['lon'];
                }
                if ($this->solrLocationField) {
                    $exists = in_array(
                        $location['wkt'],
                        $solrArray[$this->solrLocationField] ?? []
                    );
                    if (!$exists) {
                        $solrArray[$this->solrLocationField][] = $location['wkt'];
                    }
                }
            }
        }
    }

    /**
     * Get enrichment data for an identifier
     *
     * @param string $id       Entity ID
     * @param string $recordId Metadata record ID
     *
     * @return array Associative array with results
     */
    protected function getEnrichmentData(string $id, string $recordId): array
    {
        $result = [
            'preferred' => [],
            'alternative' => [],
            'matchPreferred' => [],
            'matchAlternative' => [],
            'locations' => [],
        ];

        // Check that the ID prefix matches that of the allowed ones
        $match = false;
        foreach ($this->urlPrefixAllowedList as $prefix) {
            if (strncmp($id, $prefix, strlen($prefix)) === 0) {
                $match = true;
                break;
            }
        }

        if (!$match) {
            $this->logger->logDebug(
                'enrichField',
                "Ignoring unlisted URI '$id', record $recordId",
                true
            );
            return $result;
        }

        try {
            if (!($doc = $this->getJsonLdDoc($id))) {
                return $result;
            }
        } catch (\Exception $e) {
            $this->logger->logDebug(
                'enrichField',
                "Enrichment failed for record $recordId: " . (string)$e
            );
            return $result;
        }

        $graph = $doc->getGraph();
        if (null === $graph) {
            return $result;
        }
        foreach ($graph->getNodes() as $node) {
            if (!$this->isConceptNode($node)) {
                continue;
            }

            if ($node->getId() === $id) {
                if ($locs = $this->processLocationWgs84($node)) {
                    $result['locations'] = [
                        ...$result['locations'],
                        ...$locs
                    ];
                }

                if ($labels = $this->getSkosPropertyValues($node, 'prefLabel')) {
                    $result['preferred'] = [...$result['preferred'], ...$labels];
                }

                if ($labels = $this->getSkosPropertyValues($node, 'altLabel')) {
                    $result['alternative'] = [...$result['alternative'], ...$labels];
                }
                if ($labels = $this->getSkosPropertyValues($node, 'hiddenLabel')) {
                    $result['alternative'] = [...$result['alternative'], ...$labels];
                }
            }

            // Process other exactMatch vocabularies
            $exactMatches = $node->getProperty(self::SKOS_CORE . 'exactMatch');
            if (!is_array($exactMatches)) {
                $exactMatches = $exactMatches ? [$exactMatches] : [];
            }

            foreach ($exactMatches as $exactMatch) {
                $matchId = $exactMatch->getId();
                if (!$matchId || !$this->uriPrefixAllowed($matchId)
                    || !($matchDoc = $this->getJsonLdDoc($matchId))
                ) {
                    continue;
                }
                $matchGraph = $matchDoc->getGraph();
                if (null === $matchGraph) {
                    continue;
                }
                foreach ($matchGraph->getNodes() as $matchNode) {
                    if ($matchNode->getId() !== $matchId
                        || !$this->isConceptNode($matchNode)
                    ) {
                        continue;
                    }

                    if ($locs = $this->processLocationWgs84($matchNode)) {
                        $result['locations'] = [
                            ...$result['locations'],
                            ...$locs
                        ];
                    }

                    $labels = $this->getSkosPropertyValues($matchNode, 'prefLabel');
                    if ($labels) {
                        $result['matchPreferred'] = [
                            ...$result['matchPreferred'],
                            ...$labels
                        ];
                    }

                    $labels = $this->getSkosPropertyValues($matchNode, 'altLabel');
                    if ($labels) {
                        $result['matchAlternative'] = [
                            ...$result['matchAlternative'],
                            ...$labels
                        ];
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Get an entity from local database or from Skosmos server
     *
     * @param string $id Entity ID
     *
     * @return ?\ML\JsonLD\Document
     *
     * @throws \Exception
     */
    protected function getJsonLdDoc(string $id): ?\ML\JsonLD\Document
    {
        if ($this->recordCache && $doc = $this->recordCache->get($id)) {
            return $doc;
        }
        if ($data = $this->db->findLinkedDataEnrichment(['_id' => $id])) {
            $doc = unserialize($data['data']);
            if (false === $doc) {
                $this->logger->logError(
                    'SkosmosEnrichment',
                    "Cannot unserialize document for $id:\n" . $data['data']
                );
            } else {
                if ($this->recordCache) {
                    $this->recordCache->put($id, $doc);
                }
                return $doc;
            }
        }

        if (!($url = $this->getEntityUrl($id))) {
            return null;
        }

        $data = $this->getExternalData(
            $url,
            $id,
            ['Accept' => 'application/ld+json'],
            [500],
            false
        );
        $doc = $data ? \ML\JsonLD\JsonLD::getDocument($data) : null;
        // Save the record for fast re-retrieval:
        $this->db->saveLinkedDataEnrichment(
            [
                '_id' => $id,
                'data' => serialize($doc)
            ]
        );
        if ($this->recordCache) {
            $this->recordCache->put($id, $doc);
        }

        return $doc;
    }

    /**
     * Get SKOS property as an array
     *
     * @param \ML\JsonLD\Node $node Node
     * @param string          $prop Property
     *
     * @return array<int, string>
     */
    protected function getSkosPropertyValues(
        \ML\JsonLD\Node $node,
        string $prop
    ): array {
        $vals = $node->getProperty(self::SKOS_CORE . $prop);
        if (!$vals) {
            return [];
        }
        $result = array_map(
            function ($val) {
                if ($val instanceof \ML\JsonLD\LanguageTaggedString) {
                    if ($this->languages
                        && !in_array($val->getLanguage(), $this->languages)
                    ) {
                        return false;
                    }
                }
                return $val->getValue();
            },
            is_array($vals) ? $vals : [$vals]
        );
        return array_values(array_filter($result));
    }

    /**
     * Check node for valid concept type
     *
     * @param \ML\JsonLD\Node $node Node
     *
     * @return bool
     */
    protected function isConceptNode(\ML\JsonLD\Node $node): bool
    {
        if (!($type = $node->getType())) {
            return false;
        }
        foreach (is_array($type) ? $type : [$type] as $t) {
            if ($t->getId() === self::SKOS_CORE . 'Concept') {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if given URI has an allowed prefix
     *
     * @param string $uri URI
     *
     * @return bool
     */
    protected function uriPrefixAllowed(string $uri): bool
    {
        foreach ($this->uriPrefixExactMatches as $prefix) {
            if (strncmp($uri, $prefix, strlen($prefix)) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Process WGS 84 location data
     *
     * @param \ML\JsonLD\Node $node Decoded JSON array item from which to extract
     *                              location data
     *
     * @return array
     */
    protected function processLocationWgs84(\ML\JsonLD\Node $node): array
    {
        $result = [];
        $lat = $node->getProperty(self::WGS84_POS . 'lat');
        $lon = $node->getProperty(self::WGS84_POS . 'long');
        if ($lat && $lon) {
            $lat = is_array($lat) ? $lat[0]->getValue() : $lat->getValue();
            $lon = is_array($lon) ? $lon[0]->getValue() : $lon->getValue();
            $result[] = [
                'lat' => $lat,
                'lon' => $lon,
                'wkt' => "POINT($lon $lat)"
            ];
        }
        return $result;
    }

    /**
     * Return API url
     *
     * @param string $id Entity id
     *
     * @return string
     */
    protected function getEntityUrl($id)
    {
        $url = $this->apiBaseURL;
        if (!$url || 'database' === $url) {
            return '';
        }
        if (substr($url, -1) !== '/') {
            $url .= '/';
        }
        $url .= 'data?format=application/json&uri=' . urlencode($id);
        return $url;
    }
}

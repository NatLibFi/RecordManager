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
                "Ignoring unlisted URI '$id', record $sourceId." . $record->getID(),
                true
            );
            return;
        }

        $checkFieldContents = $solrCheckField
            ? array_map(
                function ($s) {
                    return mb_strtolower($s, 'UTF-8');
                },
                (array)($solrArray[$solrCheckField] ?? [])
            ) : [];

        try {
            if (!($doc = $this->getJsonLdDoc($id))) {
                return;
            }
        } catch (\Exception $e) {
            $this->logger->logDebug(
                'enrichField',
                "Enrichment failed for record {$solrArray['id']}: " . (string)$e
            );
            return;
        }

        $graph = $doc->getGraph();
        if (null === $graph) {
            return;
        }
        foreach ($graph->getNodes() as $node) {
            if (!$this->isConceptNode($node)) {
                continue;
            }

            if ($node->getId() === $id) {
                $this->processLocationWgs84($node, $solrArray);

                $labels = $this->getSkosPropertyValues($node, 'prefLabel');
                foreach ($this->filterDuplicates($labels, $checkFieldContents)
                    as $label
                ) {
                    $checkFieldContents[] = mb_strtolower($label, 'UTF-8');
                    if ($solrPrefField) {
                        $solrArray[$solrPrefField][] = $label;
                    }
                    if ($includeInAllfields) {
                        $solrArray['allfields'][] = $label;
                    }
                }

                $altLabels = [];
                if ($labels = $this->getSkosPropertyValues($node, 'altLabel')) {
                    $altLabels = [...$altLabels, ...$labels];
                }
                if ($labels = $this->getSkosPropertyValues($node, 'hiddenLabel')) {
                    $altLabels = [...$altLabels, ...$labels];
                }

                foreach ($this->filterDuplicates($altLabels, $checkFieldContents)
                    as $label
                ) {
                    $checkFieldContents[] = mb_strtolower($label, 'UTF-8');
                    if ($solrAltField) {
                        $solrArray[$solrAltField][] = $label;
                    }
                    if ($includeInAllfields) {
                        $solrArray['allfields'][] = $label;
                    }
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

                    $this->processLocationWgs84($matchNode, $solrArray);

                    $labels = $this->getSkosPropertyValues($matchNode, 'prefLabel');
                    foreach ($this->filterDuplicates($labels, $checkFieldContents)
                        as $label
                    ) {
                        $checkFieldContents[] = mb_strtolower($label, 'UTF-8');
                        if ($solrPrefField) {
                            $solrArray[$solrPrefField][] = $label;
                        }
                        if ($includeInAllfields) {
                            $solrArray['allfields'][] = $label;
                        }
                    }

                    $labels = $this->getSkosPropertyValues($matchNode, 'altLabel');
                    foreach ($this->filterDuplicates($labels, $checkFieldContents)
                        as $label
                    ) {
                        $checkFieldContents[] = mb_strtolower($label, 'UTF-8');
                        if ($solrAltField) {
                            $solrArray[$solrAltField][] = $label;
                        }
                        if ($includeInAllfields) {
                            $solrArray['allfields'][] = $label;
                        }
                    }
                }
            }
        }
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
        if ($data = $this->db->findLinkedDataEnrichment(['_id' => $id])) {
            $doc = unserialize($data['data']);
            if (false === $doc) {
                $this->logger->logError(
                    'SkosmosEnrichment',
                    "Cannot unserialize document for $id:\n" . $data['data']
                );
            } else {
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
     * Process WKT location data and add that information to solrArray
     *
     * Implementation notes
     * Currently supports only single, non-empty 2D WKT point data format
     *
     * @param string $wkt       Well-known text to be processed
     * @param array  $solrArray Metadata to be sent to Solr
     *
     * @return void
     */
    protected function processLocationWkt($wkt, &$solrArray): void
    {
        preg_match(
            '/^POINT\s*\((?P<lon>-?\d+(?:\.?\d+)?)\s*(?P<lat>-?\d+(?:\.?\d+)?)\)/',
            $wkt,
            $matches
        );
        if ($matches) {
            $wktItem = [
                'lat' => $matches['lat'],
                'lon' => $matches['lon'],
                'wkt' => 'POINT(' . $matches['lon'] . ' ' . $matches['lat'] . ')'
            ];
            $this->processLocationItem($wktItem, $solrArray);
        }
    }

    /**
     * Process WGS 84 location data and add that information to solrArray
     *
     * @param \ML\JsonLD\Node $node      Decoded JSON array item from which to
     *                                   extract loc data
     * @param array           $solrArray Metadata to be sent to Solr
     *
     * @return void
     */
    protected function processLocationWgs84(\ML\JsonLD\Node $node, &$solrArray): void
    {
        $lat = $node->getProperty(self::WGS84_POS . 'lat');
        $lon = $node->getProperty(self::WGS84_POS . 'long');
        if ($lat && $lon) {
            $lat = is_array($lat) ? $lat[0]->getValue() : $lat->getValue();
            $lon = is_array($lon) ? $lon[0]->getValue() : $lon->getValue();
            $wktItem = [
                'lat' => $lat,
                'lon' => $lon,
                'wkt' => "POINT($lon $lat)"
            ];
            $this->processLocationItem($wktItem, $solrArray);
        }
    }

    /**
     * Add location information to solrArray
     *
     * @param array $locItem   Keyed array with keys wkt, lat and lon for each loc
     * @param array $solrArray Metadata to be sent to Solr
     *
     * @return void
     */
    protected function processLocationItem($locItem, &$solrArray): void
    {
        if ($this->solrCenterField && !isset($solrArray[$this->solrCenterField])) {
            $coords = $locItem['lat'] . ', ' . $locItem['lon'];
            $solrArray[$this->solrCenterField] = $coords;
        }
        if ($this->solrLocationField
            && !in_array($locItem['wkt'], $solrArray[$this->solrLocationField] ?? [])
        ) {
            $solrArray[$this->solrLocationField][] = $locItem['wkt'];
        }
    }

    /**
     * Filter duplicate values case-insensitively
     *
     * @param array $values Values to filter
     * @param array $check  Values to check against (expected to be lowercase)
     *
     * @return array Non-duplicates
     */
    protected function filterDuplicates(array $values, array $check)
    {
        return array_filter(
            $values,
            function ($v) use (&$check) {
                $v = mb_strtolower($v, 'UTF-8');
                if ($res = !in_array($v, $check)) {
                    $check[] = $v;
                }
                return $res;
            }
        );
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

<?php

/**
 * Skosmos enrichment test class
 *
 * PHP version 8
 *
 * Copyright (C) The National Library of Finland 2025.
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
 * @author   Juha Luoma <juha.luoma@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */

namespace RecordManagerTest\Base\Enrichment;

use Generator;
use RecordManager\Base\Enrichment\SkosmosEnrichment;
use RecordManager\Base\Http\HttpService;
use RecordManager\Base\Record\Marc;
use RecordManager\Base\Record\MarcAuthority;
use RecordManager\Base\Record\PluginManager;
use RecordManager\Base\Utils\Logger;
use RecordManager\Base\Utils\MetadataUtils;
use RecordManagerTest\Base\Record\RecordTestBase;

use function is_array;

/**
 * Skosmos enrichment test class
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Juha Luoma <juha.luoma@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class SkosmosEnrichmentTest extends RecordTestBase
{
    /**
     * Data provider for testing Skosmos enrichment
     *
     * @return Generator
     */
    public static function getSkosmosEnrichmentData(): Generator
    {
        yield 'basic topic enrichment' => [
            'fixture' => 'marc_skosmos_1.xml',
            'enrichmentFixture' => 'Enrichment/skosmos_results.json',
            'config' => [],
            'expected' => [
                'topic_add_txt_mv' => ['Enhanced Topic', 'Förbättrat ämne'],
                'topic_alt_txt_mv' => ['Alternative Topic'],
            ],
        ];
        yield 'geographic enrichment' => [
            'fixture' => 'marc_skosmos_2.xml',
            'enrichmentFixture' => 'Enrichment/skosmos_results.json',
            'config' => [],
            'expected' => [
                'geographic_add_txt_mv' => ['Helsinki', 'Helsingfors'],
                'location_geo' => ['POINT(24.9384 60.1699)'],
                'center_coords' => '60.1699, 24.9384',
            ],
        ];
        yield 'author enrichment (no method in Marc)' => [
            'fixture' => 'marc_skosmos_3.xml',
            'enrichmentFixture' => 'Enrichment/skosmos_results.json',
            'config' => [],
            'expected' => [
                'author' => ['Test Author'],
            ],
        ];
        yield 'authority record enrichment' => [
            'fixture' => 'marc_authority_skosmos.xml',
            'enrichmentFixture' => 'Enrichment/skosmos_authority_results.json',
            'config' => [],
            'expected' => [
                'occupation_str_mv' => [
                    'Test Occupation',
                ],
            ],
        ];
        yield 'URI prefix filtering (invalid URI)' => [
            'fixture' => 'marc_skosmos_invalid_uri.xml',
            'enrichmentFixture' => null,
            'config' => [],
            'expected' => [
                'topic_add_txt_mv' => null,
            ],
        ];
        yield 'exact match enrichment' => [
            'fixture' => 'marc_skosmos_exactmatch.xml',
            'enrichmentFixture' => 'Enrichment/skosmos_exactmatch_results.json',
            'config' => [],
            'expected' => [
                'topic_add_txt_mv' => [
                    'Exact Match Enhanced Topic',
                ],
            ],
        ];
        yield 'language filtering' => [
            'fixture' => 'marc_skosmos_multilang.xml',
            'enrichmentFixture' => 'Enrichment/skosmos_multilang_results.json',
            'config' => [
                'SkosmosEnrichment' => [
                    'base_url' => 'http://test.skosmos.url',
                    'url_prefix_allowed_list' => ['http://www.yso.fi/onto/yso/'],
                    'languages' => ['en', 'fi'],
                ],
            ],
            'expected' => [
                'topic_add_txt_mv' => [
                    'Multilingual Topic English',
                    'Monikielinen aihe suomeksi',
                ],
            ],
        ];
        yield 'excluded location matches' => [
            'fixture' => 'marc_skosmos_excluded_location.xml',
            'enrichmentFixture' => 'Enrichment/skosmos_excluded_location_results.json',
            'config' => [],
            'expected' => [
                'location_geo' => ['POINT(23.7610 61.4978)'],
            ],
        ];
        yield 'EAD3 with topic enrichment' => [
            'fixture' => 'ead3_skosmos.xml',
            'enrichmentFixture' => 'Enrichment/skosmos_results.json',
            'config' => [],
            'expected' => [
                'topic_add_txt_mv' => ['Enhanced Topic', 'Förbättrat ämne'],
            ],
        ];
        yield 'LIDO with topic enrichment' => [
            'fixture' => 'lido_skosmos.xml',
            'enrichmentFixture' => 'Enrichment/skosmos_results.json',
            'config' => [],
            'expected' => [
                'topic_add_txt_mv' => ['Enhanced Topic', 'Förbättrat ämne'],
            ],
        ];
        yield 'QDC without enrichment methods' => [
            'fixture' => 'qdc_skosmos.xml',
            'enrichmentFixture' => 'Enrichment/skosmos_results.json',
            'config' => [],
            'expected' => [
                'topic_add_txt_mv' => null,
            ],
        ];
        yield 'Forward without enrichment methods' => [
            'fixture' => 'forward_skosmos.xml',
            'enrichmentFixture' => 'Enrichment/skosmos_results.json',
            'config' => [],
            'expected' => [
                'topic_add_txt_mv' => null,
            ],
        ];
    }

    /**
     * Test Skosmos enrichment
     *
     * @param string      $fixture           Fixture filename
     * @param string|null $enrichmentFixture Enrichment data fixture filename
     * @param array       $config            Custom config
     * @param array       $expected          Expected results
     *
     * @return void
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('getSkosmosEnrichmentData')]
    public function testSkosmosEnrichment(
        string $fixture,
        ?string $enrichmentFixture,
        array $config,
        array $expected,
    ): void {
        $httpService = $this->createMock(HttpService::class);
        $record = match (true) {
            str_starts_with($fixture, 'marc_authority_') =>
                $this->createMarcRecord(MarcAuthority::class, $fixture, []),
            str_starts_with($fixture, 'marc_') =>
                $this->createMarcRecord(Marc::class, $fixture, []),
            str_starts_with($fixture, 'ead3_') =>
                $this->createRecord(
                    \RecordManager\Base\Record\Ead3::class,
                    $fixture,
                    constructorParams: [$httpService]
                ),
            str_starts_with($fixture, 'lido_') =>
                $this->createRecord(
                    \RecordManager\Base\Record\Lido::class,
                    $fixture,
                    constructorParams: [$httpService]
                ),
            str_starts_with($fixture, 'qdc_') =>
                $this->createRecord(
                    \RecordManager\Base\Record\Qdc::class,
                    $fixture,
                    constructorParams: [$httpService]
                ),
            str_starts_with($fixture, 'forward_') =>
                $this->createRecord(
                    \RecordManager\Base\Record\Forward::class,
                    $fixture,
                    []
                ),
            default => null,
        };

        $record->normalize();
        $fields = $record->toSolrArray();

        $dbEntities = [];
        if ($enrichmentFixture) {
            $enrichmentData = $this->getFixture($enrichmentFixture);
            $dbEntities = json_decode($enrichmentData, true);
        }

        $enricher = $this->getSkosmosEnricher($dbEntities, $config);
        $enricher->enrich('test', $record, $fields);

        foreach ($expected as $field => $values) {
            $this->assertEquals($values, $fields[$field] ?? null);
        }
    }

    /**
     * Get a Skosmos enrichment object
     *
     * @param array $dbEntities Database entities
     * @param array $config     Main config
     *
     * @return SkosmosEnrichment
     */
    protected function getSkosmosEnricher(array $dbEntities, array $config = []): SkosmosEnrichment
    {
        if (empty($config)) {
            $config = [
                'SkosmosEnrichment' => [
                    'base_url' => 'http://test.skosmos.url',
                    'url_prefix_allowed_list' => ['http://www.yso.fi/onto/yso/'],
                    'uri_prefix_exact_matches' => ['http://www.yso.fi/onto/yso/'],
                    'solr_location_field' => 'location_geo',
                    'solr_center_field' => 'center_coords',
                ],
            ];
        }

        $db = $this->createMock(
            \RecordManager\Base\Database\MongoDatabase::class,
        );

        foreach ($dbEntities as &$entity) {
            if (isset($entity['data']) && is_array($entity['data'])) {
                $entity['data'] = serialize(\ML\JsonLD\JsonLD::getDocument(json_encode($entity['data'])));
            }
        }
        unset($entity);

        $db->expects($this->any())->method('findLinkedDataEnrichment')
            ->willReturnCallback(function ($params) use ($dbEntities) {
                return $dbEntities[$params['_id']] ?? null;
            });

        $db->expects($this->any())->method('saveLinkedDataEnrichment')
            ->willReturn(true);

        $metadataUtils = $this->getMockBuilder(MetadataUtils::class)
            ->onlyMethods([])
            ->disableOriginalConstructor()
            ->getMock();

        $enricher = $this->getMockBuilder(SkosmosEnrichment::class)
            ->onlyMethods([])
            ->setConstructorArgs([
                $config,
                $db,
                $this->createMock(Logger::class),
                $this->createMock(PluginManager::class),
                $this->createMock(HttpService::class),
                $metadataUtils,
            ])
            ->getMock();

        return $enricher;
    }
}

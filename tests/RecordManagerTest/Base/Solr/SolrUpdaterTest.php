<?php

/**
 * Tests for SolrUpdater
 *
 * PHP version 8
 *
 * Copyright (C) The National Library of Finland 2020-2023.
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

namespace RecordManagerTest\Base\Solr;

use ArrayIterator;
use RecordManager\Base\Database\DatabaseInterface;
use RecordManager\Base\Database\MongoDatabase;
use RecordManager\Base\Enrichment\AuthEnrichment;
use RecordManager\Base\Enrichment\PluginManager as EnrichmentPluginManager;
use RecordManager\Base\Enrichment\SkosmosEnrichment;
use RecordManager\Base\Http\HttpService as HttpService;
use RecordManager\Base\Record\Marc\FormatCalculator;
use RecordManager\Base\Record\PluginManager as RecordPluginManager;
use RecordManager\Base\Settings\Ini;
use RecordManager\Base\Solr\SolrUpdater;
use RecordManager\Base\Utils\FieldMapper;
use RecordManager\Base\Utils\Logger;
use RecordManager\Base\Utils\MetadataUtils;
use RecordManager\Base\Utils\WorkerPoolManager;
use RecordManagerTest\Base\Feature\FixtureTrait;
use RecordManagerTest\Base\Record\CreateSampleRecordTrait;
use ReflectionClass;

/**
 * Tests for SolrUpdater
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class SolrUpdaterTest extends \PHPUnit\Framework\TestCase
{
    use FixtureTrait;
    use CreateSampleRecordTrait;

    /**
     * Main configuration
     *
     * @var array
     */
    protected $config = [
        'Solr Field Limits' => [
            '__default__' => 1024,
            'fullrecord' => 32766,
            'fulltext' => 0,
            'fulltext_unstemmed' => 0,
            'long_lat' => 0,
            '*_keys_*' => 20,
            'title_sh*' => 30,
            '*sort' => 40,
        ],
    ];

    /**
     * Data source settings
     *
     * @var array
     */
    protected $dataSourceConfig = [
        'test' => [
            'institution' => 'Test',
            'format' => 'marc',
        ],
    ];

    /**
     * Tests for single record processing
     *
     * @return void
     */
    public function testProcessSingleRecord()
    {
        $solrUpdater = $this->getSolrUpdater();

        $record = $this->createMarcRecord(
            \RecordManager\Base\Record\Marc::class,
            'marc-broken.xml'
        );

        $date = strtotime('2020-10-20 13:01:00');
        $dbRecord = [
            '_id' => $record->getID(),
            'oai_id' => '',
            'linking_id' => $record->getLinkingIDs(),
            'source_id' => 'test',
            'deleted' => false,
            'created' => $date,
            'updated' => $date,
            'date' => $date,
            'format' => 'marc',
            'original_data' => $record->serialize(),
            'normalized_data' => null,
        ];
        $result = $solrUpdater->processSingleRecord($dbRecord);

        $maxlen = function ($array) {
            return max(
                array_map(
                    function ($s) {
                        return mb_strlen($s, 'UTF-8');
                    },
                    $array
                )
            );
        };

        $this->assertIsArray($result['deleted']);
        $this->assertEmpty($result['deleted']);
        $this->assertIsArray($result['records']);
        $this->assertCount(1, $result['records']);
        $this->assertEquals(0, $result['mergedComponents']);
        $this->assertIsArray($result['records'][0]);

        $record = $result['records'][0];
        $this->assertEquals('63', $record['id']);
        $this->assertEquals('Test', $record['institution']);
        $this->assertEquals('marc', $record['record_format']);
        $this->assertEquals(['FCC004782937', '63'], $record['ctrlnum']);
        $this->assertIsArray($record['allfields']);
        $this->assertEquals(1024, $maxlen($record['allfields']));
        $this->assertIsArray($record['topic']);
        $this->assertEquals(1024, $maxlen($record['topic_facet']));
        $this->assertIsArray($record['work_keys_str_mv']);
        $this->assertEquals(20, $maxlen($record['work_keys_str_mv']));
        $this->assertEquals(143225, mb_strlen($record['fullrecord'], 'UTF-8'));
        $this->assertEquals(30, mb_strlen($record['title_short'], 'UTF-8'));
        $this->assertEquals(40, mb_strlen($record['title_sort'], 'UTF-8'));
    }

    /**
     * Test merged component
     *
     * @return void
     */
    public function testMergedComponents(): void
    {
        $record = $this->createMarcRecord(
            \RecordManager\Base\Record\Marc::class,
            'marc-broken.xml'
        );

        $date = strtotime('2020-10-20 13:01:00');
        $dbRecord = [
            '_id' => $record->getID(),
            'oai_id' => '',
            'linking_id' => $record->getLinkingIDs(),
            'source_id' => 'test',
            'deleted' => false,
            'created' => $date,
            'updated' => $date,
            'date' => $date,
            'format' => 'marc',
            'original_data' => $record->serialize(),
            'normalized_data' => null,
            'host_record_id' => 'test.1',
        ];

        $params = [
            'host_record_id' => [
                '$in' => array_values($dbRecord['linking_id']),
            ],
            'deleted' => false,
            'suppressed' => ['$in' => [null, false]],
            'source_id' => 'test',
        ];
        $records = new ArrayIterator([
            [
                '_id' => 'test.2',
            ],
            [
                '_id' => 'test.3',
            ],
        ]);
        $recordMap = [
            [$params, [], $records[0]],
        ];
        $dsOverride = [
            'test' => [
                'mergeMultiLevelParts' => true,
                'componentParts' => 'merge_all',
            ],
        ];
        $database = $this->getMockBuilder(MongoDatabase::class)->disableOriginalConstructor()->getMock();
        $database->expects($this->exactly(1))->method('findRecord')->willReturnMap($recordMap);
        $database->expects($this->exactly(1))->method('findRecords')->willReturn($records);
        $solrUpdater = $this->getSolrUpdater(
            dsConfigOverrides: $dsOverride,
            database: $database
        );

        $result = $solrUpdater->processSingleRecord($dbRecord);
        $record = $result['records'][0];
        $this->assertEquals(0, $result['mergedComponents']);
    }

    /**
     * Data provider for testFieldProcessingRules
     *
     * @return array
     */
    public static function processSingleRecordProvider(): array
    {
        return [
            'copy non-existent field' => [
                [
                    'copy foo newfield',
                ],
                [],
            ],
            'copy non-existent field with default' => [
                [
                    'copy foo newfield DEFAULT VALUE',
                ],
                [
                    'newfield' => 'DEFAULT VALUE',
                ],
            ],
            'copy non-existent field with param defaul' => [
                [
                    'copy foo newfield default="DEFAULT FIELD"',
                ],
                [
                    'newfield' => 'DEFAULT FIELD',
                ],
            ],
            'copy field' => [
                [
                    'copy institution newfield',
                ],
                [
                    'newfield' => 'Test',
                ],
            ],
            'copy field matching value' => [
                [
                    'copy institution newfield match="Test"',
                ],
                [
                    'newfield' => 'Test',
                ],
            ],
            'copy field matching regex' => [
                [
                    'copy institution newfield match="/^Test$/"',
                ],
                [
                    'newfield' => 'Test',
                ],
            ],
            'copy field matching case-insensitive regex' => [
                [
                    'copy institution newfield match="/^test$/i"',
                ],
                [
                    'newfield' => 'Test',
                ],
            ],
            'copy field not matching regex' => [
                [
                    'copy institution newfield match="/test/" ',
                ],
                [
                    'newfield' => null,
                ],
            ],
            'delete field' => [
                [
                    'delete institution',
                ],
                [
                    'institution' => null,
                ],
            ],
            'delete field matching Test' => [
                [
                    'delete institution match="Test"',
                ],
                [
                    'institution' => null,
                ],
            ],
            'copy and delete' => [
                [
                    'copy institution newfield',
                    'copy record_format newfield',
                    'delete institution',
                ],
                [
                    'newfield' => [
                        'Test',
                        'marc',
                    ],
                    'institution' => null,
                ],
            ],
            'move twice' => [
                [
                    'move institution newfield DEFAULT',
                    'move institution newfield DEFAULT2 ',
                ],
                [
                    'newfield' => [
                        'Test',
                        'DEFAULT2',
                    ],
                    'institution' => null,
                ],
            ],
            'copy multivalued' => [
                [
                    'copy topic newtopic match="/^tutkimus/"',
                ],
                [
                    'newtopic' => [
                        'tutkimusrahoitus',
                        'tutkimuspolitiikka',
                        'tutkimustyö',
                        'tutkimus',
                    ],
                    'topic' => [
                        'oppaat',
                        'ft: kirjoittaminen',
                        'apurahat',
                        'tutkimusrahoitus',
                        'tutkimuspolitiikka',
                        'opinnäytteet',
                        'tiedonhaku',
                        'kielioppaat',
                        'tutkimustyö',
                        'tutkimus',
                    ],
                ],
            ],
            'move multivalued' => [
                [
                    'move topic newtopic match="/^tutkimus/"',
                ],
                [
                    'newtopic' => [
                        'tutkimusrahoitus',
                        'tutkimuspolitiikka',
                        'tutkimustyö',
                        'tutkimus',
                    ],
                    'topic' => [
                        'oppaat',
                        'ft: kirjoittaminen',
                        'apurahat',
                        'opinnäytteet',
                        'tiedonhaku',
                        'kielioppaat',
                    ],
                ],
            ],
            'delete multivalued' => [
                [
                    'delete topic',
                ],
                [
                    'topic' => null,
                ],
            ],
            'delete multivalued matching' => [
                [
                    'delete topic match="/^tutkimus/"',
                ],
                [
                    'topic' => [
                        'oppaat',
                        'ft: kirjoittaminen',
                        'apurahat',
                        'opinnäytteet',
                        'tiedonhaku',
                        'kielioppaat',
                    ],
                ],
            ],
        ];
    }

    /**
     * Test field processing rules
     *
     * @param array $rules    Field processing rules
     * @param array $expected Expected results
     *
     * @return void
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('processSingleRecordProvider')]
    public function testFieldProcessingRules(array $rules, array $expected): void
    {
        $solrUpdater = $this->getSolrUpdater(
            [
                'test' => [
                    'fieldRules' => $rules,
                ],
            ],
        );

        $record = $this->createMarcRecord(
            \RecordManager\Base\Record\Marc::class,
            'marc1.xml'
        );

        $date = strtotime('2020-10-20 13:01:00');
        $dbRecord = [
            '_id' => $record->getID(),
            'oai_id' => '',
            'linking_id' => $record->getLinkingIDs(),
            'source_id' => 'test',
            'deleted' => false,
            'created' => $date,
            'updated' => $date,
            'date' => $date,
            'format' => 'marc',
            'original_data' => $record->serialize(),
            'normalized_data' => null,
        ];
        $result = $solrUpdater->processSingleRecord($dbRecord);

        $this->assertIsArray($result['records'][0]);
        $record = $result['records'][0];
        foreach ($expected as $field => $value) {
            $this->assertEquals($value, $record[$field] ?? null, $field);
        }
    }

    /**
     * Test enrichments using legacy names and new names.
     *
     * @return void
     */
    public function testLegacyEnrichments(): void
    {
        $dsOverride = [
            'test' => [
                'enrichments' => [
                    'MarcOnkiLightEnrichment',
                    'LidoOnkiLightEnrichment',
                    'Ead3SkosmosEnrichment',
                    'EadSkosmosEnrichment',
                    'OnkiLightEnrichment',
                    'MarcAuthEnrichment',
                    'SomeAuthEnrichment,final',
                    'BrokenEnrichment',
                ],
            ],
        ];
        $this->config['Solr']['enrichment'] = [
            'LidoSkosmosEnrichment',
            'LidoAuthEnrichment',
            'EadOnkiLightEnrichment,start',
        ];
        $solrUpdater = $this->getSolrUpdater(
            $dsOverride,
        );
        $record = $this->createMarcRecord(
            \RecordManager\Base\Record\Marc::class,
            'marc-broken.xml'
        );

        $date = strtotime('2020-10-20 13:01:00');
        $dbRecord = [
            '_id' => $record->getID(),
            'oai_id' => '',
            'linking_id' => $record->getLinkingIDs(),
            'source_id' => 'test',
            'deleted' => false,
            'created' => $date,
            'updated' => $date,
            'date' => $date,
            'format' => 'marc',
            'original_data' => $record->serialize(),
            'normalized_data' => null,
        ];
        $solrUpdater->processSingleRecord($dbRecord);
        $reflectionClass = new ReflectionClass($solrUpdater);
        $enrichments = array_keys($reflectionClass->getProperty('enrichments')->getValue($solrUpdater));
        $this->assertEquals([SkosmosEnrichment::class, AuthEnrichment::class], $enrichments);
    }

    /**
     * Create SolrUpdater
     *
     * @param array              $dsConfigOverrides Data source config overrides
     * @param ?DatabaseInterface $database          Database mock object
     *
     * @return SolrUpdater
     */
    protected function getSolrUpdater(
        array $dsConfigOverrides = [],
        ?DatabaseInterface $database = null
    ): SolrUpdater {
        $dsConfig = array_merge_recursive(
            $this->dataSourceConfig,
            $dsConfigOverrides
        );
        $logger = $this->createMock(Logger::class);
        $metadataUtils = new \RecordManager\Base\Utils\MetadataUtils(
            RECMAN_BASE_PATH,
            [],
            $logger,
        );
        $record = new \RecordManager\Base\Record\Marc(
            [],
            $dsConfig,
            $logger,
            $metadataUtils,
            function ($data) {
                return new \RecordManager\Base\Marc\Marc($data);
            },
            new FormatCalculator()
        );
        $recordPM = $this->createMock(RecordPluginManager::class);
        $recordPM->expects($this->once())
            ->method('get')
            ->willReturn($record);
        $fieldMapper = new FieldMapper(
            $this->getFixtureDir() . 'config/basic',
            [],
            $this->dataSourceConfig
        );
        $enrichmentTable = [
            'AuthEnrichment' => \RecordManager\Base\Enrichment\AuthEnrichment::class,
            'MusicBrainzEnrichment' => \RecordManager\Base\Enrichment\MusicBrainzEnrichment::class,
            'NominatimGeocoder' => \RecordManager\Base\Enrichment\NominatimGeocoder::class,
            'SkosmosEnrichment' => \RecordManager\Base\Enrichment\SkosmosEnrichment::class,

            // Legacy aliases:
            'EadOnkiLightEnrichment' => \RecordManager\Base\Enrichment\SkosmosEnrichment::class,
            'Ead3OnkiLightEnrichment' => \RecordManager\Base\Enrichment\SkosmosEnrichment::class,
            'LidoOnkiLightEnrichment' => \RecordManager\Base\Enrichment\SkosmosEnrichment::class,
            'LrmiOnkiLightEnrichment' => \RecordManager\Base\Enrichment\SkosmosEnrichment::class,
            'MarcAuthOnkiLightEnrichment' => \RecordManager\Base\Enrichment\SkosmosEnrichment::class,
            'MarcOnkiLightEnrichment' => \RecordManager\Base\Enrichment\SkosmosEnrichment::class,
            'OnkiLightEnrichment' => \RecordManager\Base\Enrichment\SkosmosEnrichment::class,
            'EadSkosmosEnrichment' => \RecordManager\Base\Enrichment\SkosmosEnrichment::class,
            'Ead3SkosmosEnrichment' => \RecordManager\Base\Enrichment\SkosmosEnrichment::class,
            'LidoSkosmosEnrichment' => \RecordManager\Base\Enrichment\SkosmosEnrichment::class,
            'LrmiSkosmosEnrichment' => \RecordManager\Base\Enrichment\SkosmosEnrichment::class,
            'MarcAuthSkosmosEnrichment' => \RecordManager\Base\Enrichment\SkosmosEnrichment::class,
            'MarcSkosmosEnrichment' => \RecordManager\Base\Enrichment\SkosmosEnrichment::class,

            'MarcAuthEnrichment' => \RecordManager\Base\Enrichment\AuthEnrichment::class,
        ];
        $enrichmentPluginManager = $this->createMock(EnrichmentPluginManager::class);
        $enrichmentPluginManager->expects($this->any())->method('get')->willReturnCallback(
            function ($name, $options = null) use ($enrichmentTable) {
                return new $enrichmentTable[$name](
                    [],
                    $this->createMock(DatabaseInterface::class),
                    $this->createMock(Logger::class),
                    $this->createMock(RecordPluginManager::class),
                    $this->createMock(HttpService::class),
                    $this->createMock(MetadataUtils::class),
                    $this->createMock(DatabaseInterface::class)
                );
            }
        );
        $enrichmentPluginManager->expects($this->any())->method('has')->willReturnCallback(
            function ($name) use ($enrichmentTable) {
                return isset($enrichmentTable[$name]);
            }
        );
        $solrUpdater = new SolrUpdater(
            $this->config,
            $dsConfig,
            $database,
            $logger,
            $recordPM,
            $enrichmentPluginManager,
            $this->createMock(HttpService::class),
            $this->createMock(Ini::class),
            $fieldMapper,
            $metadataUtils,
            $this->createMock(WorkerPoolManager::class)
        );

        return $solrUpdater;
    }
}

<?php
/**
 * Tests for SolrUpdater
 *
 * PHP version 7
 *
 * Copyright (C) The National Library of Finland 2020-2021.
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

use RecordManager\Base\Enrichment\PluginManager as EnrichmentPluginManager;
use RecordManager\Base\Http\ClientManager as HttpClientManager;
use RecordManager\Base\Record\Marc\FormatCalculator;
use RecordManager\Base\Record\PluginManager as RecordPluginManager;
use RecordManager\Base\Settings\Ini;
use RecordManager\Base\Solr\SolrUpdater;
use RecordManager\Base\Utils\FieldMapper;
use RecordManager\Base\Utils\Logger;
use RecordManager\Base\Utils\WorkerPoolManager;
use RecordManagerTest\Base\Feature\FixtureTrait;
use RecordManagerTest\Base\Record\CreateSampleRecordTrait;

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
        ]
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
        ]
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
        $mongoRecord = [
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
        $result = $solrUpdater->processSingleRecord($mongoRecord);

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
        $this->assertEquals(1, count($result['records']));
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
     * Create SolrUpdater
     *
     * @return SolrUpdater
     */
    protected function getSolrUpdater()
    {
        $logger = $this->createMock(Logger::class);
        $metadataUtils = new \RecordManager\Base\Utils\MetadataUtils(
            RECMAN_BASE_PATH,
            [],
            $logger,
        );
        $record = new \RecordManager\Base\Record\Marc(
            [],
            $this->dataSourceConfig,
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
            ->will($this->returnValue($record));
        $fieldMapper = new FieldMapper(
            $this->getFixtureDir() . 'config/basic',
            [],
            $this->dataSourceConfig
        );
        $solrUpdater = new SolrUpdater(
            $this->config,
            $this->dataSourceConfig,
            null,
            $logger,
            $recordPM,
            $this->createMock(EnrichmentPluginManager::class),
            $this->createMock(HttpClientManager::class),
            $this->createMock(Ini::class),
            $fieldMapper,
            $metadataUtils,
            $this->createMock(WorkerPoolManager::class)
        );

        return $solrUpdater;
    }
}

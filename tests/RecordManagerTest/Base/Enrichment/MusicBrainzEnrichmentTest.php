<?php

/**
 * MusicBrainz enrichment test class
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
use RecordManager\Base\Enrichment\MusicBrainzEnrichment;
use RecordManager\Base\Http\HttpService;
use RecordManager\Base\Record\Marc;
use RecordManager\Base\Record\PluginManager;
use RecordManager\Base\Utils\Logger;
use RecordManager\Base\Utils\MetadataUtils;
use RecordManagerTest\Base\Record\RecordTestBase;

/**
 * MusicBrainz enrichment test class
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Juha Luoma <juha.luoma@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class MusicBrainzEnrichmentTest extends RecordTestBase
{
    /**
     * Data provider for testing basic enrichments
     *
     * @return Generator
     */
    public static function getTestEnrichmentData(): Generator
    {
        yield 'test with upc' => [
          'marc_music_2.xml',
          [
            '5a323542-6431-48c2-89ed-f0cef6f17b85',
            'b3eb127e-75ec-4167-93aa-7c1cfcfa52d6',
            'cba9c83f-4017-42eb-89ce-6110fd0dc10c',
            '093baef2-31e5-408b-979e-e90ba8f2394f',
          ],
        ];
        yield 'test with ian' => [
          'marc_music_4.xml',
          [
            'b2022feb-88fe-4e4a-94cc-1f0eae644b9b',
          ],
        ];
        yield 'test with mbid' => [
          'marc_music_5.xml',
          [
            'cba9c83f-4017-42eb-89ce-6110fd0dc10c',
          ],
        ];
        yield 'test with publisher ids' => [
          'marc_music_6.xml',
          [
            '065e298f-a73f-4399-89b9-e2bb36606931',
          ],
        ];
    }

    /**
     * Test basic enrichment
     *
     * @param string $recordPath Path for the record fixture
     * @param array  $expected   Expected results
     *
     * @return       void
     * @dataProvider getTestEnrichmentData
     */
    public function testEnrichment(string $recordPath, array $expected): void
    {
        $record = self::createMarcRecord(
            Marc::class,
            $recordPath,
            [],
        );
        $record->normalize();
        $fields = $record->toSolrArray();
        $entitiesJson = $this->getFixture('Enrichment/music_brainz_results.json');
        $dbEntities = json_decode($entitiesJson, true);
        $enricher = $this->getMusicBrainzEnricher(
            $dbEntities,
            [
            'MusicBrainzEnrichment' => [
              'url' => 'http://testrichmenturl.cat',
            ],
            ]
        );
        $enricher->enrich('', $record, $fields);
        $this->assertEquals($expected, $fields['mbid_str_mv'] ?? []);
    }

    /**
     * Get a musicbrainz enrichment object
     *
     * @param array $dbEntities Database entities
     * @param array $config     Main config
     *
     * @return MusicBrainzEnrichment
     */
    public function getMusicBrainzEnricher(array $dbEntities, array $config = []): MusicBrainzEnrichment
    {
        $db = $this->getMockedClass(
            \RecordManager\Base\Database\MongoDatabase::class,
            ['findUriCache', 'getTimestamp', 'saveUriCache']
        );
        foreach ($dbEntities as &$entity) {
            $entity['data'] = json_encode($entity['data']);
        }
        unset($entity);
        $db->expects($this->any())->method('findUriCache')->willReturnCallback(function ($params) use ($dbEntities) {
            return $dbEntities[$params['_id']] ?? null;
        });
        $metadataUtils = $this->getMockBuilder(MetadataUtils::class)->onlyMethods([])
            ->disableOriginalConstructor()->getMock();
        $musicBrainzEnricher = $this->getMockBuilder(MusicBrainzEnrichment::class)->onlyMethods([])
            ->setConstructorArgs([
              $config,
              $db,
              $this->createMock(Logger::class),
              $this->createMock(PluginManager::class),
              $this->createMock(HttpService::class),
              $metadataUtils,
            ])->getMock();
        return $musicBrainzEnricher;
    }

    /**
     * Function to get a mocked class if required
     *
     * @param string $id      Class id
     * @param array  $methods Methods to mock
     *
     * @return mixed
     */
    public function getMockedClass(string $id, array $methods = [])
    {
        $builder = $this->getMockBuilder($id)->disableOriginalConstructor();
        if ($methods) {
            $builder->onlyMethods($methods);
        }
        return $builder->getMock();
    }
}

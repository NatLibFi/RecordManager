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
use GuzzleHttp\Client;
use Psr\Container\ContainerInterface;
use RecordManager\Base\Enrichment\AbstractEnrichmentFactory;
use RecordManager\Base\Enrichment\MusicBrainzEnrichment;
use RecordManager\Base\Record\Marc;
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
    protected array $mockDb = [];

    protected $enricher;

    /**
     * Set up test environment
     *
     * @return void
     */
    public function setUp(): void
    {
        $data = $this->getFixture('Enrichment/music_brainz_results.txt');
        $this->mockDb = json_decode($data, true);
        foreach ($this->mockDb as &$entry) {
            $entry['data'] = json_encode($entry['data']);
        }
        unset($entry);

        $configs = [
          'recordmanager.ini' => [
            'MusicBrainzEnrichment' => [
              'url' => 'test.url.fi',
            ],
          ],
        ];

        $configReader = $this->getMockedClass(\RecordManager\Base\Settings\Ini::class, ['get']);
        $configReader->expects($this->any())->method('get')->willReturnCallback(function ($toGet) use ($configs) {
            return $configs[$toGet];
        });

        $httpService = $this->getMockedClass(\RecordManager\Base\Http\HttpService::class, ['createClient']);
        $httpService->expects($this->any())->method('createClient')->willReturn($this->getMockedClass(Client::class));

        $metadataUtils = $this->getMockedClass(MetadataUtils::class, ['normalizeKey']);
        $metadataUtils->expects($this->any())->method('normalizeKey')->willReturnCallback(function ($str) {
            $table = [
            'Š' => 'S', 'š' => 's', 'Ž' => 'Z', 'ž' => 'z', 'À' => 'A',
            'Á' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A', 'Å' => 'A',
            'Æ' => 'A', 'Ç' => 'C', 'È' => 'E', 'É' => 'E', 'Ê' => 'E',
            'Ë' => 'E', 'Ì' => 'I', 'Í' => 'I', 'Î' => 'I', 'Ï' => 'I',
            'Ñ' => 'N', 'Ò' => 'O', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O',
            'Ö' => 'O', 'Ø' => 'O', 'Ù' => 'U', 'Ú' => 'U', 'Û' => 'U',
            'Ü' => 'U', 'Ý' => 'Y', 'Þ' => 'B', 'ß' => 'Ss', 'à' => 'a',
            'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
            'æ' => 'a', 'ç' => 'c', 'è' => 'e', 'é' => 'e', 'ê' => 'e',
            'ë' => 'e', 'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
            'ð' => 'o', 'ñ' => 'n', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o',
            'õ' => 'o', 'ö' => 'o', 'ø' => 'o', 'ù' => 'u', 'ú' => 'u',
            'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'þ' => 'b', 'ÿ' => 'y',
            ];
            $str = strtr($str, $table);
            $str = preg_replace(
                '/[\x00-\x20\x21-\x2F\x3A-\x40,\x5B-\x60,\x7B-\x7F]/',
                '',
                $str
            );
            return mb_strtolower(trim($str), 'UTF-8');
        });
        $mockDbData = $this->mockDb;
        $db = $this->getMockedClass(
            \RecordManager\Base\Database\MongoDatabase::class,
            ['findUriCache', 'getTimestamp', 'saveUriCache']
        );
        $db->expects($this->any())->method('findUriCache')->willReturnCallback(function ($params) use ($mockDbData) {
            return $mockDbData[$params['_id']] ?? null;
        });
        $mockedClasses = [
            \RecordManager\Base\Settings\Ini::class => $configReader,
            \RecordManager\Base\Http\HttpService::class => $httpService,
            \RecordManager\Base\Database\AbstractDatabase::class => $db,
            MetadataUtils::class => $metadataUtils,
        ];
        $containerWrapper = new class ($mockedClasses) extends RecordTestBase implements ContainerInterface {
            /**
             * Constructor
             *
             * @param array $mockedClasses Classes mocked
             *
             * @return void
             */
            public function __construct(protected $mockedClasses = [])
            {
            }

            /**
             * Get mocked class
             *
             * @param string $id Class id
             *
             * @return mixed
             */
            public function get(string $id)
            {
                return $this->mockedClasses[$id] ?? $this->getMockedClass($id);
            }

            /**
             * Contains mocked class
             *
             * @param string $id Class id
             *
             * @return bool
             */
            public function has(string $id)
            {
                return !!($this->mockedClasses[$id] ?? false);
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
        };
        $factory = new AbstractEnrichmentFactory();
        $this->enricher = ($factory)($containerWrapper, MusicBrainzEnrichment::class);
        $this->enricher->init();
    }

    /**
     * Data provider for testing basic enrichments
     *
     * @return Generator
     */
    public static function getTestEnrichmentData(): Generator
    {

        yield 'test with isrc id types' => [
          'marc_music_1.xml',
          [
            '5a599050-a851-4b84-86a7-e91d24677d74',
            '8fd393b3-fe3d-4a11-8ad0-11b28f087811',
          ],
        ];
        yield 'test with upc' => [
          'marc_music_2.xml',
          [
            '8e458771-cf65-4783-bb8b-153ec0236af9',
            '74292ac1-5707-3304-a337-4c65015e4ef8',
            '27fb716d-e873-4c1e-87c5-35d29a14027f',
            '29bc5657-cc98-372c-be6b-faa8560b500e',
            '5a323542-6431-48c2-89ed-f0cef6f17b85',
            '90f6d468-f0d4-32cd-8ca2-f1fc49f47311',
            'b3eb127e-75ec-4167-93aa-7c1cfcfa52d6',
            'cba9c83f-4017-42eb-89ce-6110fd0dc10c',
            'b6d9e833-2d8d-44ca-b95e-5ade7affe710',
            '093baef2-31e5-408b-979e-e90ba8f2394f',
            '9c9f992c-af31-4b60-80d4-24ccd5fa4a40',
            '8e458771-cf65-4783-bb8b-153ec0236af9',
            '74292ac1-5707-3304-a337-4c65015e4ef8',
            '27fb716d-e873-4c1e-87c5-35d29a14027f',
            '29bc5657-cc98-372c-be6b-faa8560b500e',
            '5a323542-6431-48c2-89ed-f0cef6f17b85',
            '90f6d468-f0d4-32cd-8ca2-f1fc49f47311',
            'b3eb127e-75ec-4167-93aa-7c1cfcfa52d6',
            'cba9c83f-4017-42eb-89ce-6110fd0dc10c',
            'b6d9e833-2d8d-44ca-b95e-5ade7affe710',
            '093baef2-31e5-408b-979e-e90ba8f2394f',
            '9c9f992c-af31-4b60-80d4-24ccd5fa4a40',
            '8e458771-cf65-4783-bb8b-153ec0236af9',
            '74292ac1-5707-3304-a337-4c65015e4ef8',
            '27fb716d-e873-4c1e-87c5-35d29a14027f',
            '29bc5657-cc98-372c-be6b-faa8560b500e',
            '5a323542-6431-48c2-89ed-f0cef6f17b85',
            '90f6d468-f0d4-32cd-8ca2-f1fc49f47311',
            'b3eb127e-75ec-4167-93aa-7c1cfcfa52d6',
            'cba9c83f-4017-42eb-89ce-6110fd0dc10c',
            'b6d9e833-2d8d-44ca-b95e-5ade7affe710',
            '093baef2-31e5-408b-979e-e90ba8f2394f',
            '9c9f992c-af31-4b60-80d4-24ccd5fa4a40',
            '8e458771-cf65-4783-bb8b-153ec0236af9',
            '74292ac1-5707-3304-a337-4c65015e4ef8',
            '27fb716d-e873-4c1e-87c5-35d29a14027f',
            '29bc5657-cc98-372c-be6b-faa8560b500e',
            '5a323542-6431-48c2-89ed-f0cef6f17b85',
            '90f6d468-f0d4-32cd-8ca2-f1fc49f47311',
            'b3eb127e-75ec-4167-93aa-7c1cfcfa52d6',
            'cba9c83f-4017-42eb-89ce-6110fd0dc10c',
            'b6d9e833-2d8d-44ca-b95e-5ade7affe710',
            '093baef2-31e5-408b-979e-e90ba8f2394f',
            '9c9f992c-af31-4b60-80d4-24ccd5fa4a40',
          ],
        ];
        yield 'test with ismn' => [
          'marc_music_3.xml',
          [],
        ];
        yield 'test with ian' => [
          'marc_music_4.xml',
          [
            '1182b911-f7b6-499e-8527-0f3bb46b9059',
            'eb1695fb-3372-4f28-a5b3-b7b9be97089f',
            'b2022feb-88fe-4e4a-94cc-1f0eae644b9b',
          ],
        ];
        yield 'test with mbid' => [
          'marc_music_5.xml',
          [
            '8e458771-cf65-4783-bb8b-153ec0236af9',
            '74292ac1-5707-3304-a337-4c65015e4ef8',
            '27fb716d-e873-4c1e-87c5-35d29a14027f',
            '29bc5657-cc98-372c-be6b-faa8560b500e',
            '5a323542-6431-48c2-89ed-f0cef6f17b85',
            '90f6d468-f0d4-32cd-8ca2-f1fc49f47311',
            'b3eb127e-75ec-4167-93aa-7c1cfcfa52d6',
            'cba9c83f-4017-42eb-89ce-6110fd0dc10c',
            'b6d9e833-2d8d-44ca-b95e-5ade7affe710',
            '093baef2-31e5-408b-979e-e90ba8f2394f',
            '9c9f992c-af31-4b60-80d4-24ccd5fa4a40',
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

        $this->enricher->enrich('', $record, $fields);
        $this->assertEquals($expected, $fields['mbid_str_mv'] ?? []);
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

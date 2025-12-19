<?php

/**
 * Auth enrichment test class
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
use RecordManager\Base\Enrichment\AuthEnrichment;
use RecordManager\Base\Http\HttpService;
use RecordManager\Base\Record\Marc;
use RecordManager\Base\Record\PluginManager;
use RecordManager\Base\Utils\Logger;
use RecordManager\Base\Utils\MetadataUtils;
use RecordManagerTest\Base\Record\RecordTestBase;

/**
 * Auth enrichment test class
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Juha Luoma <juha.luoma@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class AuthEnrichmentTest extends RecordTestBase
{
    /**
     * Data provider for testing Auth enrichment
     *
     * @return Generator
     */
    public static function getAuthEnrichmentData(): Generator
    {
        yield 'basic author enrichment' => [
            'fixture' => 'marc_auth_1.xml',
            'authorityRecords' => [
                '(FIN11)authority_001' => 'marc_authority_1.xml',
            ],
            'config' => [],
            'authorIds' => ['(FIN11)authority_001'],
            'expected' => [
                'author_variant' => ['p a pa', 'Alternative Name 1', 'Alternative Name 2'],
            ],
        ];
        yield 'no author IDs' => [
            'fixture' => 'marc_auth_no_ids.xml',
            'authorityRecords' => [],
            'config' => [],
            'authorIds' => [],
            'expected' => [
                'author2' => ['Second Author Without ID'],
            ],
        ];
        yield 'missing authority record' => [
            'fixture' => 'marc_auth_missing_authority.xml',
            'authorityRecords' => [],
            'config' => [],
            'authorIds' => ['(FIN11)nonexistent_authority', '(FIN11)another_missing'],
            'expected' => [
                'author2' => ['Secondary Author With Missing Authority'],
            ],
        ];
        yield 'no get alternative names method' => [
            'fixture' => 'marc_auth_2.xml',
            'authorityRecords' => [
                '(FIN11)authority_002' => 'forward_authority_1.xml',
            ],
            'config' => [],
            'authorIds' => ['(FIN11)authority_002'],
            'expected' => [],
        ];
    }

    /**
     * Test Auth enrichment
     *
     * @param string $fixture          Fixture filename
     * @param array  $authorityRecords Authority record fixtures (id => filename)
     * @param array  $config           Custom config
     * @param array  $authorIds        Author IDs to add to author2_id_str_mv
     * @param array  $expected         Expected results
     *
     * @return void
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('getAuthEnrichmentData')]
    public function testAuthEnrichment(
        string $fixture,
        array $authorityRecords,
        array $config,
        array $authorIds,
        array $expected
    ): void {
        $record = $this->createMarcRecord(Marc::class, $fixture, []);
        $record->normalize();
        $fields = $record->toSolrArray();

        // Add author IDs to the solr array (simulating what would be there in production)
        if (!empty($authorIds)) {
            $fields['author2_id_str_mv'] = $authorIds;
        }

        $enricher = $this->getAuthEnricher($authorityRecords, $config);
        $enricher->enrich('test', $record, $fields);
        if (!$expected) {
            $this->assertArrayNotHasKey('author_variant', $fields, 'author_variant field should not be present.');
        }
        foreach ($expected as $key => $value) {
            $this->assertEquals($value, $fields[$key] ?? null, "Field '$key' did not match expected value.");
        }
    }

    /**
     * Get an Auth enrichment object
     *
     * @param array $authorityRecords Authority record fixtures
     * @param array $config           Main config
     *
     * @return AuthEnrichment
     */
    protected function getAuthEnricher(array $authorityRecords, array $config = []): AuthEnrichment
    {
        if (empty($config)) {
            $config = [
                'AuthEnrichment' => [
                    'enabled' => true,
                ],
            ];
        }

        $db = $this->createMock(\RecordManager\Base\Database\DatabaseInterface::class);
        $authorityDb = $this->createMock(\RecordManager\Base\Database\DatabaseInterface::class);

        // Mock authority database records
        $authorityDbRecords = [];
        foreach ($authorityRecords as $id => $filename) {
            $authorityRecord = null;
            $format = '';
            if (str_contains($filename, 'forward_authority')) {
                $authorityRecord = $this->createRecord(
                    \RecordManager\Base\Record\ForwardAuthority::class,
                    $filename,
                    []
                );
                $format = 'forwardAuthority';
            } else {
                $authorityRecord = $this->createMarcRecord(
                    \RecordManager\Base\Record\MarcAuthority::class,
                    $filename,
                    []
                );
                $format = 'marc';
            }

            $authorityDbRecords[$id] = [
                '_id' => $id,
                'source_id' => 'test',
                'oai_id' => $id,
                'deleted' => false,
                'format' => $format,
                'original_data' => $authorityRecord->serialize(),
                'normalized_data' => $authorityRecord->serialize(),
            ];
        }

        $authorityDb->expects($this->any())
            ->method('getRecord')
            ->willReturnCallback(function ($id) use ($authorityDbRecords) {
                return $authorityDbRecords[$id] ?? null;
            });

        $metadataUtils = $this->getMockBuilder(MetadataUtils::class)
            ->onlyMethods([])
            ->disableOriginalConstructor()
            ->getMock();

        $recordPluginManager = $this->createMock(PluginManager::class);
        $recordPluginManager->expects($this->any())
            ->method('get')
            ->willReturnCallback(function ($format) {
                if ($format === 'marc') {
                    return $this->createMarcRecord(
                        \RecordManager\Base\Record\MarcAuthority::class,
                        'marc_authority_1.xml',
                        []
                    );
                } elseif ($format === 'forwardAuthority') {
                    return $this->createRecord(
                        \RecordManager\Base\Record\ForwardAuthority::class,
                        'forward_authority_1.xml',
                        []
                    );
                }
                throw new \Exception("Unknown format requested in test: $format");
            });

        $enricher = $this->getMockBuilder(AuthEnrichment::class)
            ->onlyMethods([])
            ->setConstructorArgs([
                $config,
                $db,
                $this->createMock(Logger::class),
                $recordPluginManager,
                $this->createMock(HttpService::class),
                $metadataUtils,
                $authorityDb,
            ])
            ->getMock();

        return $enricher;
    }
}

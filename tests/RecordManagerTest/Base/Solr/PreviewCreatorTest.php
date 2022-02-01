<?php
/**
 * Tests for preview creation (stresses mapping file handling)
 *
 * PHP version 7
 *
 * Copyright (C) The National Library of Finland 2017-2021.
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

use RecordManager\Base\Record\PluginManager as RecordPluginManager;
use RecordManager\Base\Utils\Logger;
use RecordManagerTest\Base\Feature\FixtureTrait;
use RecordManagerTest\Base\Feature\PreviewCreatorTrait;

/**
 * Preview creation tests
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class PreviewCreatorTest extends \PHPUnit\Framework\TestCase
{
    use FixtureTrait;
    use PreviewCreatorTrait;

    /**
     * Data source settings
     *
     * @var array
     */
    protected $dataSourceConfig = [
        'test' => [
            'institution' => 'Test',
            'format' => 'marc',
            'building_mapping' => [
                'building.map',
                'building_sub.map,regexp'
            ],
            'driverParams' => [
                'subLocationInBuilding=c'
            ]
        ]
    ];

    /**
     * Tests for building field
     *
     * @return void
     */
    public function testBuilding()
    {
        $logger = $this->createMock(Logger::class);
        $metadataUtils = new \RecordManager\Base\Utils\MetadataUtils(
            RECMAN_BASE_PATH,
            [],
            $logger
        );
        $marc = new \RecordManager\Base\Record\Marc(
            [],
            $this->dataSourceConfig,
            $logger,
            $metadataUtils
        );
        $recordPM = $this->createMock(RecordPluginManager::class);
        $recordPM->expects($this->once())
            ->method('get')
            ->will($this->returnValue($marc));
        $preview = $this->getPreviewCreator($recordPM);

        $timestamp = time();
        $xml = $this->getFixture('Solr/holdings_record.xml');
        $record = [
            'format' => 'marc',
            'original_data' => $xml,
            'normalized_data' => $xml,
            'source_id' => 'test',
            'linking_id' => '_preview',
            'oai_id' => '_preview',
            '_id' => '_preview',
            'created' => $timestamp,
            'date' => $timestamp
        ];

        $result = $preview->create($record);
        $this->assertEquals(
            ['B', 'A/2', 'A', 'DEF/2'],
            $result['building']
        );
    }
}

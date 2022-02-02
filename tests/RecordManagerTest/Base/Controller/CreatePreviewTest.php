<?php
/**
 * Tests for CreatePreview controller
 *
 * PHP version 7
 *
 * Copyright (C) The National Library of Finland 2022.
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
namespace RecordManagerTest\Base\Controller;

use RecordManager\Base\Controller\CreatePreview;
use RecordManager\Base\Database\PDODatabase;
use RecordManager\Base\Deduplication\DedupHandler;
use RecordManager\Base\Record\PluginManager as RecordPluginManager;
use RecordManager\Base\Splitter\PluginManager as SplitterPluginManager;
use RecordManager\Base\Utils\LineBasedMarcFormatter;
use RecordManager\Base\Utils\Logger;
use RecordManagerTest\Base\Feature\FixtureTrait;
use RecordManagerTest\Base\Feature\PreviewCreatorTrait;

/**
 * CreatePreview controller tests
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class CreatePreviewTest extends \PHPUnit\Framework\TestCase
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
        ]
    ];

    /**
     * Tests for building field
     *
     * @return void
     */
    public function testCreatePreview()
    {
        $record = $this->getFixture('Controller/CreatePreview/preview_marc.xml');
        $preview = $this->getCreatePreview($record);

        $result = $preview->launch(
            $record,
            'marc',
            'test'
        );
        $expected = json_decode(
            $this->getFixture('Controller/CreatePreview/preview_result.json'),
            true
        );

        $this->assertEquals($expected, $result);
    }

    /**
     * Create CreatePreview controller
     *
     * @return CreatePreview
     */
    protected function getCreatePreview()
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
            ->method('has')
            ->with('marc')
            ->will($this->returnValue(true));
        $recordPM->expects($this->once())
            ->method('get')
            ->will($this->returnValue($marc));
        return new CreatePreview(
            [],
            $this->dataSourceConfig,
            $logger,
            $this->createMock(PDODatabase::class),
            $recordPM,
            $this->createMock(SplitterPluginManager::class),
            $this->createMock(DedupHandler::class),
            $metadataUtils,
            $this->getPreviewCreator(),
            $this->createMock(LineBasedMarcFormatter::class)
        );
    }
}

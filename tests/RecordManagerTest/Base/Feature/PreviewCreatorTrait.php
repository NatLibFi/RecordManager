<?php
/**
 * Trait for creating a PreviewCreator.
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
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org/wiki/development:testing:unit_tests Wiki
 */
namespace RecordManagerTest\Base\Feature;

use RecordManager\Base\Enrichment\PluginManager as EnrichmentPluginManager;
use RecordManager\Base\Http\ClientManager as HttpClientManager;
use RecordManager\Base\Record\Marc\FormatCalculator;
use RecordManager\Base\Record\PluginManager as RecordPluginManager;
use RecordManager\Base\Settings\Ini;
use RecordManager\Base\Solr\PreviewCreator;
use RecordManager\Base\Utils\FieldMapper;
use RecordManager\Base\Utils\Logger;
use RecordManager\Base\Utils\WorkerPoolManager;

/**
 * Trait adding functionality for loading fixtures.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org/wiki/development:testing:unit_tests Wiki
 */
trait PreviewCreatorTrait
{
    /**
     * Create PreviewCreator
     *
     * @param object $recordPM Optional Record\PluginManager
     *
     * @return PreviewCreator
     */
    protected function getPreviewCreator($recordPM = null)
    {
        $logger = $this->createMock(Logger::class);
        $metadataUtils = new \RecordManager\Base\Utils\MetadataUtils(
            RECMAN_BASE_PATH,
            [],
            $logger
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
        if (null === $recordPM) {
            $recordPM = $this->createMock(RecordPluginManager::class);
            $recordPM->expects($this->once())
                ->method('get')
                ->will($this->returnValue($record));
        }

        $fieldMapper = new FieldMapper(
            $this->getFixtureDir() . 'config/basic',
            [],
            $this->dataSourceConfig
        );
        $preview = new PreviewCreator(
            [],
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

        return $preview;
    }
}

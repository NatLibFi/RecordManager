<?php

/**
 * Trait for creating records
 *
 * Prerequisites:
 * - FixtureTrait
 *
 * PHP version 8
 *
 * Copyright (C) The National Library of Finland 2020-2022.
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
 * @author   Eero Heikkinen <eero.heikkinen@gmail.com>
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */

namespace RecordManagerTest\Base\Record;

use RecordManager\Base\Record\Marc\FormatCalculator;
use RecordManager\Base\Utils\Logger;

/**
 * Trait for creating record
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Eero Heikkinen <eero.heikkinen@gmail.com>
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
trait CreateSampleRecordTrait
{
    /**
     * Create a sample record driver
     *
     * @param string $class             Record class
     * @param string $sample            Sample record file
     * @param array  $dsConfig          Datasource config
     * @param string $module            Module name
     * @param array  $constructorParams Additional constructor params
     *
     * @return \RecordManager\Base\Record\AbstractRecord
     */
    protected function createRecord(
        string $class,
        string $sample,
        array $dsConfig = [],
        string $module = 'Base',
        array $constructorParams = []
    ) {
        $recordString = $this->getFixture("record/$sample", $module);
        return $this->createRecordFromString(
            $recordString,
            $class,
            $dsConfig,
            $constructorParams
        );
    }

    /**
     * Create a sample record driver from a record string
     *
     * @param string $recordString      Record as a string
     * @param string $class             Record class
     * @param array  $dsConfig          Datasource config
     * @param array  $constructorParams Additional constructor params
     *
     * @return \RecordManager\Base\Record\AbstractRecord
     */
    protected function createRecordFromString(
        string $recordString,
        string $class,
        array $dsConfig = [],
        array $constructorParams = []
    ) {
        $logger = $this->createMock(Logger::class);
        $config = [
            'Site' => [
                'articles' => 'articles.lst',
            ],
        ];
        $metadataUtils = new \RecordManager\Base\Utils\MetadataUtils(
            $this->getFixtureDir() . 'config/recorddrivertest',
            $config,
            $logger
        );
        $record = new $class(
            [],
            $dsConfig,
            $logger,
            $metadataUtils,
            ...$constructorParams
        );
        $record->setData(
            '__unit_test_no_source__',
            '__unit_test_no_id__',
            $recordString
        );
        return $record;
    }

    /**
     * Create a sample MARC record driver
     *
     * @param string $class             Record class
     * @param string $sample            Sample record file
     * @param array  $dsConfig          Datasource config
     * @param string $module            Module name
     * @param array  $constructorParams Additional constructor params
     *
     * @return \RecordManager\Base\Record\AbstractRecord
     */
    protected function createMarcRecord(
        string $class,
        string $sample,
        array $dsConfig = [],
        string $module = 'Base',
        array $constructorParams = []
    ) {
        return $this->createRecord(
            $class,
            $sample,
            $dsConfig,
            $module,
            [
                function ($data) {
                    return new \RecordManager\Base\Marc\Marc($data);
                },
                new FormatCalculator(),
                ...$constructorParams,
            ]
        );
    }
}

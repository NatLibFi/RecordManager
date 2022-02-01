<?php
/**
 * Trait for creating records
 *
 * Prerequisites:
 * - FixtureTrait
 *
 * PHP version 7
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
     * @param array  $constructorParams Additional constructor params
     *
     * @return \RecordManager\Base\Record\AbstractRecord
     */
    protected function createRecord(
        string $class,
        string $sample,
        array $dsConfig = [],
        string $ns = 'Base',
        array $constructorParams = []
    ) {
        $logger = $this->createMock(Logger::class);
        $config = [
            'Site' => [
                'articles' => 'articles.lst'
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
        $data = $this->getFixture("record/$sample", $ns);
        $record->setData('__unit_test_no_source__', '__unit_test_no_id__', $data);
        return $record;
    }
}

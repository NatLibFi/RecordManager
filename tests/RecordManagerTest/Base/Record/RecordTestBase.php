<?php

/**
 * Generic Record Driver test class
 *
 * PHP version 8
 *
 * Copyright (C) Eero Heikkinen 2013.
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
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */

namespace RecordManagerTest\Base\Record;

use RecordManagerTest\Base\Feature\FixtureTrait;

/**
 * Generic Record Driver Test Class
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Eero Heikkinen <eero.heikkinen@gmail.com>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
abstract class RecordTestBase extends \PHPUnit\Framework\TestCase
{
    use FixtureTrait;
    use CreateSampleRecordTrait;

    /**
     * Compare two arrays
     *
     * This makes any errors easier to understand than using assertEquals on the
     * arrays.
     *
     * @param array  $expected Expected values
     * @param array  $provided Provided values
     * @param string $method   Method tested (for output messages)
     *
     * @return void
     */
    protected function compareArray($expected, $provided, $method)
    {
        foreach ($expected as $key => $value) {
            $this->assertEquals(
                $value,
                $provided[$key] ?? null,
                "[$method] Compare expected field $key"
            );
        }
        foreach ($provided as $key => $value) {
            $this->assertTrue(
                array_key_exists($key, $expected) !== false,
                "[$method] Unexpected field $key: " . var_export($value, true)
            );
        }
    }
}

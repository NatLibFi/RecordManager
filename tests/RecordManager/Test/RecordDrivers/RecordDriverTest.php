<?php
/**
 * Generic Record Driver test class
 *
 * PHP version 7
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
 * @link     https://github.com/KDK-Alli/RecordManager
 */
namespace RecordManager\Test\RecordDrivers;

use RecordManager\Base\Utils\MetadataUtils;

/**
 * Generic Record Driver Test Class
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Eero Heikkinen <eero.heikkinen@gmail.com>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
abstract class RecordDriverTest extends \RecordManager\Test\AbstractTest
{
    use CreateRecordTrait;

    /**
     * Standard setup method.
     *
     * @return void
     */
    public function setUp(): void
    {
        MetadataUtils::setConfig(
            [
                'Site' => [
                    'articles' => 'articles.lst'
                ],
            ],
            __DIR__ . '/../../../configs/recorddrivertest'
        );
    }

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
                $value, $provided[$key] ?? null, "[$method] Compare field $key"
            );
        }
        $this->assertEquals(
            count($expected), count($provided), "[$method] Field count equal"
        );
    }
}

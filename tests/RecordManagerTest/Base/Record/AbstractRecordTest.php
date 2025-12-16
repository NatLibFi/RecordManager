<?php

/**
 * AbstractRecord Test Class
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
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */

namespace RecordManagerTest\Base\Record;

use Generator;
use RecordManager\Base\Record\Marc;

/**
 * AbstractRecord Test Class
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class AbstractRecordTest extends RecordTestBase
{
    /**
     * Data provider for testGetSuppressed
     *
     * @return Generator
     */
    public static function getSuppressedProvider(): Generator
    {
        yield 'no rules' => [[], false];
        yield 'no matches' => [
            [
                'suppressOnField' => [
                    'building' => '100|200',
                ],
                'suppressOnFieldRegEx' => [
                    'building' => '/(100|200)/',
                ],
            ],
            false,
        ];
        yield 'non-existing field' => [
            [
                'suppressOnField' => [
                    'non-existing-field' => 'foo',
                ],
            ],
            false,
        ];

        yield 'match' => [
            [
                'suppressOnField' => [
                    'building' => '100|150|200',
                ],
            ],
            true,
        ];
        yield 'regex match' => [
            [
                'suppressOnField' => [
                    'non-existing-field' => 'foo',
                ],
                'suppressOnFieldRegEx' => [
                    'building' => '/1[05]0/',
                ],
            ],
            true,
        ];
    }

    /**
     * Test getSuppressed
     *
     * @param array $config             Data source config
     * @param bool  $expectedSuppressed Expected result
     *
     * @return void
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('getSuppressedProvider')]
    public function testGetSuppressed(array $config, bool $expectedSuppressed): void
    {
        $dsConfig = [
            '__unit_test_no_source__' => $config,
        ];
        // Use a MARC record for testing the base class:
        $record = $this->createMarcRecord(Marc::class, 'marc1.xml', $dsConfig);
        $this->assertEquals(
            $expectedSuppressed,
            $record->getSuppressed()
        );
    }
}

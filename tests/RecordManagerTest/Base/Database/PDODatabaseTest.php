<?php

/**
 * PDO Database Test Class
 *
 * PHP version 8
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

namespace RecordManagerTest\Base\Database;

use RecordManager\Base\Database\PDODatabase;
use RecordManager\Base\Database\PDOResultIterator;

/**
 * PDO Database Test Class
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class PDODatabaseTest extends \PHPUnit\Framework\TestCase
{
    /**
     * Fields for table record
     *
     * @var array
     */
    protected $recordFields = [
        '_id',
        'oai_id',
        'main_id',
        'source_id',
        'format',
        'created',
        'updated',
        'date',
        'deleted',
        'update_needed',
        'original_data',
        'normalized_data',
        'suppressed',
        'dedup_id',
        'mark',
    ];

    /**
     * Data provider for testQueryConversion
     *
     * @return array
     */
    public function getQueryConversionData(): array
    {
        return [
            'no params' => [
                [],
                [],
                'select * from record',
                [],
            ],
            'single param' => [
                [
                    '_id' => '1212',
                ],
                [],
                'select * from record where _id=?',
                [
                    '1212',
                ],
            ],
            'two params' => [
                [
                    '_id' => '1212',
                    'deleted' => false,
                ],
                [],
                'select * from record where _id=? AND deleted=?',
                [
                    '1212',
                    false,
                ],
            ],
            'params with $lt' => [
                [
                    'deleted' => true,
                    'updated' => ['$lt' => 1234],
                    'source_id' => 'foo',
                ],
                [],
                'select * from record where deleted=? AND updated<? AND source_id=?',
                [
                    true,
                    1234,
                    'foo',
                ],
            ],
            'params with $lt and options with limit' => [
                [
                    'deleted' => true,
                    'updated' => ['$lt' => 1234],
                    'source_id' => 'foo',
                ],
                [
                    'limit' => 1000,
                ],
                'select * from record where deleted=? AND updated<? AND source_id=?'
                . ' limit 1000',
                [
                    true,
                    1234,
                    'foo',
                ],
            ],
            'params with $lt and options with limit and skip' => [
                [
                    'deleted' => true,
                    'updated' => ['$lt' => 1234],
                    'source_id' => 'foo',
                ],
                [
                    'limit' => 1000,
                    'skip' => 1,
                ],
                'select * from record where deleted=? AND updated<? AND source_id=?'
                . ' limit 1,1000',
                [
                    true,
                    1234,
                    'foo',
                ],
            ],
            'params with $lt and options with limit, skip and sort' => [
                [
                    'deleted' => true,
                    'updated' => ['$lt' => 1234],
                    'source_id' => 'foo',
                ],
                [
                    'limit' => 1000,
                    'skip' => 1,
                    'sort' => ['dedup_id' => 1],
                ],
                'select * from record where deleted=? AND updated<? AND source_id=?'
                . ' order by dedup_id asc limit 1,1000',
                [
                    true,
                    1234,
                    'foo',
                ],
            ],
            'params with linkind_id' => [
                [
                    'deleted' => false,
                    'linking_id' => '1212',
                ],
                [],
                'select * from record where deleted=? AND _id IN (SELECT parent_id'
                . " FROM record_attrs ca WHERE ca.attr='linking_id' AND ca.value=?)",
                [
                    false,
                    '1212',
                ],
            ],
            'params with different array operators' => [
                [
                    'isbn_keys' => ['$in' => ['isbn', 'isbn2']],
                    'deleted' => false,
                    'suppressed' => ['$in' => [null, false]],
                    'source_id' => ['$ne' => 'source'],
                ],
                [],
                'select * from record where _id IN (SELECT parent_id FROM'
                . " record_attrs ca WHERE ca.attr='isbn_keys' AND ca.value in (?,?))"
                . ' AND deleted=? AND (suppressed IS NULL OR suppressed=?) AND'
                . ' source_id<>?',
                [
                    'isbn',
                    'isbn2',
                    false,
                    false,
                    'source',
                ],
            ],
            'params with null in $in' => [
                [
                    'isbn_keys' => ['$in' => [null]],
                ],
                [],
                'select * from record where (_id IN (SELECT parent_id FROM'
                . " record_attrs ca WHERE ca.attr='isbn_keys' AND ca.value IS NULL) OR _id NOT IN"
                . " (SELECT parent_id FROM record_attrs ca WHERE ca.attr='isbn_keys'))",
                [],
            ],
            'params with null and other values in $in' => [
                [
                    'isbn_keys' => ['$in' => [null, '', 'isbn']],
                ],
                [],
                'select * from record where ((_id IN (SELECT parent_id FROM'
                . " record_attrs ca WHERE ca.attr='isbn_keys' AND ca.value IS NULL) OR _id NOT IN"
                . " (SELECT parent_id FROM record_attrs ca WHERE ca.attr='isbn_keys')) OR _id IN"
                . " (SELECT parent_id FROM record_attrs ca WHERE ca.attr='isbn_keys' AND ca.value IN (?,?)))",
                [
                    '',
                    'isbn',
                ],
            ],
        ];
    }

    /**
     * Test query conversion
     *
     * @param array  $filter         Search filter
     * @param array  $options        Search options
     * @param string $expectedSql    Expected SQL query
     * @param array  $expectedParams Expected SQL query params
     *
     * @dataProvider getQueryConversionData
     *
     * @return void
     */
    public function testQueryConversion(
        array $filter,
        array $options,
        string $expectedSql,
        array $expectedParams
    ): void {
        $checkQuery = function (
            string $sql,
            array $params = []
        ) use (
            $expectedSql,
            $expectedParams
        ) {
            $this->assertEqualsIgnoringCase($expectedSql, $sql);
            $this->assertEquals($expectedParams, $params);

            return $this->createMock(PDOResultIterator::class);
        };

        $database = $this->getMockBuilder(PDODatabase::class)
            ->onlyMethods(['getDb', 'dbQuery', 'getMainFields'])
            ->setConstructorArgs([[]])
            ->getMock();
        $database->expects($this->once())
            ->method('dbQuery')
            ->will($this->returnCallback($checkQuery));
        $database->expects($this->any())
            ->method('getMainFields')
            ->with('record')
            ->will($this->returnValue($this->recordFields));

        $database->findRecords($filter, $options);
    }
}

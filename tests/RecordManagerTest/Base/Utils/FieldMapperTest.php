<?php
/**
 * FieldMapper tests
 *
 * PHP version 7
 *
 * Copyright (C) The National Library of Finland 2017-2021
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
namespace RecordManagerTest\Base\Utils;

use RecordManager\Base\Utils\FieldMapper;
use RecordManagerTest\Base\Feature\FixtureTrait;

/**
 * FieldMapper tests
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class FieldMapperTest extends \PHPUnit\Framework\TestCase
{
    use FixtureTrait;

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
            'rights_mapping' => [
                'rights.map,regexp'
            ]
        ]
    ];

    /**
     * Test mapValues
     *
     * @return void
     */
    public function testMapValues()
    {
        $fieldMapper = $this->getFieldMapper();

        $record = [
            'building' => ['A1', 'B1'],
            'rights' => ['CC FOO', 'CC BY ND']
        ];
        $expected = [
            'building' => ['A', 'B'],
            'rights' => ['by', 'nd']
        ];

        $mapped = $fieldMapper->mapValues('test', $record);

        $this->assertIsArray($mapped);
        $this->assertEquals($expected, $mapped);
    }

    /**
     * Test mapValues
     *
     * @return void
     */
    public function testMapValuesDefault()
    {
        $fieldMapper = $this->getFieldMapper();

        $record = [
            'building' => ['A1', 'B1'],
            'rights' => ['CC FOO', 'CC BAR']
        ];
        $expected = [
            'building' => ['A', 'B'],
            'rights' => ['other']
        ];

        $mapped = $fieldMapper->mapValues('test', $record);

        $this->assertIsArray($mapped);
        $this->assertEquals($expected, $mapped);
    }

    /**
     * Tests for basic mapping files
     *
     * @return void
     */
    public function testBasicMappingFile()
    {
        $fieldMapper = $this->getFieldMapper();

        $mapping = [
            [
                'type' => 'normal',
                'map' => $this->callProtected(
                    $fieldMapper,
                    'readMappingFile',
                    [$this->getConfigDir() . '/mappings/building-basic.map']
                )
            ]
        ];

        $result = $this->callProtected(
            $fieldMapper,
            'mapValue',
            ['val1', $mapping]
        );
        $this->assertEquals('a/b', $result);

        $result = $this->callProtected(
            $fieldMapper,
            'mapValue',
            ['val2', $mapping]
        );
        $this->assertEquals('', $result);

        $result = $this->callProtected(
            $fieldMapper,
            'mapValue',
            ['val3', $mapping]
        );
        $this->assertEquals(['a', 'b'], $result);

        $result = $this->callProtected(
            $fieldMapper,
            'mapValue',
            ['fi=val4', $mapping]
        );
        $this->assertEquals('c', $result);

        $result = $this->callProtected(
            $fieldMapper,
            'mapValue',
            ['val4', $mapping]
        );
        $this->assertEquals('def', $result);

        $result = $this->callProtected(
            $fieldMapper,
            'mapValue',
            ['', $mapping]
        );
        $this->assertEquals('def', $result);
    }

    /**
     * Tests for regexp mapping files
     *
     * @return void
     */
    public function testRegexpMappingFile()
    {
        $fieldMapper = $this->getFieldMapper();

        $mapping = [
            [
                'type' => 'regexp',
                'map' => $this->callProtected(
                    $fieldMapper,
                    'readMappingFile',
                    [$this->getConfigDir() . '/mappings/building-regexp.map']
                )
            ]
        ];

        $result = $this->callProtected(
            $fieldMapper,
            'mapValue',
            ['val1', $mapping]
        );
        $this->assertEquals('val/1', $result);

        $result = $this->callProtected(
            $fieldMapper,
            'mapValue',
            ['val', $mapping]
        );
        $this->assertEquals('string', $result);

        $result = $this->callProtected(
            $fieldMapper,
            'mapValue',
            ['!21!', $mapping]
        );
        $this->assertEquals('def', $result);

        $result = $this->callProtected(
            $fieldMapper,
            'mapValue',
            ['21!', $mapping]
        );
        $this->assertEquals('!', $result);

        $result = $this->callProtected(
            $fieldMapper,
            'mapValue',
            ['21', $mapping]
        );
        $result = $this->callProtected(
            $fieldMapper,
            'mapValue',
            ['FOO123', $mapping]
        );
        $this->assertEquals(['BAR', 'BAZ'], $result);
    }

    /**
     * Tests for regexp mapping files
     *
     * @return void
     */
    public function testRegexpNoDefaultMappingFile()
    {
        $fieldMapper = $this->getFieldMapper();

        $mapping = [
            [
                'type' => 'regexp',
                'map' => $this->callProtected(
                    $fieldMapper,
                    'readMappingFile',
                    [
                        $this->getConfigDir()
                        . '/mappings/building-regexp-no-default.map'
                    ]
                )
            ]
        ];

        $result = $this->callProtected(
            $fieldMapper,
            'mapValue',
            ['val1', $mapping]
        );
        $this->assertEquals('val/1', $result);

        $result = $this->callProtected(
            $fieldMapper,
            'mapValue',
            ['val', $mapping]
        );
        $this->assertEquals('string', $result);

        $result = $this->callProtected(
            $fieldMapper,
            'mapValue',
            ['!21!', $mapping]
        );
        $this->assertEquals('!21!', $result);

        $result = $this->callProtected(
            $fieldMapper,
            'mapValue',
            ['21!', $mapping]
        );
        $this->assertEquals('!', $result);

        $result = $this->callProtected(
            $fieldMapper,
            'mapValue',
            ['21', $mapping]
        );
        $this->assertEquals('', $result);
    }

    /**
     * Tests for regexp multi mapping files
     *
     * @return void
     */
    public function testRegexpMultiMappingFile()
    {
        $fieldMapper = $this->getFieldMapper();

        $mapping = [
            [
                'type' => 'regexp-multi',
                'map' => $this->callProtected(
                    $fieldMapper,
                    'readMappingFile',
                    [$this->getConfigDir() . '/mappings/building-regexp-multi.map']
                )
            ]
        ];

        $result = $this->callProtected(
            $fieldMapper,
            'mapValue',
            ['val1', $mapping]
        );
        $this->assertEquals(['val/1', 'string1'], $result);

        $result = $this->callProtected(
            $fieldMapper,
            'mapValue',
            ['val', $mapping]
        );
        $this->assertEquals(['string'], $result);

        $result = $this->callProtected(
            $fieldMapper,
            'mapValue',
            ['!21!', $mapping]
        );
        $this->assertEquals('def', $result);

        $result = $this->callProtected(
            $fieldMapper,
            'mapValue',
            ['21!', $mapping]
        );
        $this->assertEquals(['!'], $result);

        $result = $this->callProtected(
            $fieldMapper,
            'mapValue',
            ['21', $mapping]
        );
        $this->assertEquals([''], $result);
    }

    /**
     * Tests for regexp multi mapping files with an empty default and ##emptyarray
     * set
     *
     * @return void
     */
    public function testRegexpMultiEmptyArrayMappingFile()
    {
        $fieldMapper = $this->getFieldMapper();

        $mapping = [
            [
                'type' => 'regexp-multi',
                'map' => $this->callProtected(
                    $fieldMapper,
                    'readMappingFile',
                    [$this->getConfigDir() . '/mappings/building-regexp-multi.map']
                )
            ]
        ];

        $result = $this->callProtected(
            $fieldMapper,
            'mapValue',
            ['val1', $mapping]
        );
        $this->assertEquals(['val/1', 'string1'], $result);

        $result = $this->callProtected(
            $fieldMapper,
            'mapValue',
            ['val', $mapping]
        );
        $this->assertEquals(['string'], $result);

        $result = $this->callProtected(
            $fieldMapper,
            'mapValue',
            ['!21!', $mapping]
        );
        $this->assertEquals('def', $result);

        $result = $this->callProtected(
            $fieldMapper,
            'mapValue',
            ['21!', $mapping]
        );
        $this->assertEquals(['!'], $result);

        $result = $this->callProtected(
            $fieldMapper,
            'mapValue',
            ['21', $mapping]
        );
        $this->assertEquals([''], $result);
    }

    /**
     * Tests for multilevel mapping files
     *
     * @return void
     */
    public function testMultilevelMappingFile()
    {
        $fieldMapper = $this->getFieldMapper();

        $mapping = [
            [
                'type' => 'normal',
                'map' => $this->callProtected(
                    $fieldMapper,
                    'readMappingFile',
                    [$this->getConfigDir() . '/mappings/building-basic.map']
                )
            ],
            [
                'type' => 'regexp',
                'map' => $this->callProtected(
                    $fieldMapper,
                    'readMappingFile',
                    [$this->getConfigDir() . '/mappings/building-regexp.map']
                )
            ]
        ];

        $result = $this->callProtected(
            $fieldMapper,
            'mapValue',
            [['val1', 'val1'], $mapping]
        );
        $this->assertEquals('a/b/val/1', $result);

        $result = $this->callProtected(
            $fieldMapper,
            'mapValue',
            [['val2', 'val1'], $mapping]
        );
        $this->assertEquals('', $result);

        $result = $this->callProtected(
            $fieldMapper,
            'mapValue',
            [['val1', '21'], $mapping]
        );
        $this->assertEquals('a/b', $result);
    }

    /**
     * Call a protected method using reflection API
     *
     * @param object|string $object    Object or class name
     * @param string        $method    Method name
     * @param array         $arguments Method arguments
     *
     * @return mixed
     */
    protected function callProtected($object, $method, array $arguments = [])
    {
        $reflectionMethod = new \ReflectionMethod($object, $method);
        $reflectionMethod->setAccessible(true);
        return $reflectionMethod->invokeArgs($object, $arguments);
    }

    /**
     * Create FieldMapper
     *
     * @return FieldMapper
     */
    protected function getFieldMapper()
    {
        return new FieldMapper($this->getConfigDir(), [], $this->dataSourceConfig);
    }

    /**
     * Get config directory
     *
     * @return string
     */
    protected function getConfigDir()
    {
        return $this->getFixtureDir() . 'config/fieldmappertest';
    }
}

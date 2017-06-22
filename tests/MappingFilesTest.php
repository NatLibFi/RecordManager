<?php
/**
 * Tests for handling of mapping files
 *
 * PHP version 5
 *
 * Copyright (C) The National Library of Finland 2017
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
 * @link     https://github.com/KDK-Alli/RecordManager
 */

use RecordManager\Base\Solr\SolrUpdater;
use RecordManager\Base\Utils\Logger;

/**
 * Mapping file tests
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class MappingFilesTest extends AbstractTest
{
    /**
     * Basic mapping
     *
     * @var array
     */
    protected $basicMapping = [
        [
            'type' => 'normal',
            'map' => [
                'val1' => 'a/b',
                'val2' => '',
                '##default' => 'def'
            ]
        ]
    ];

    /**
     * Regexp mapping
     *
     * @var array
     */
    protected $regexpMapping = [
        [
            'type' => 'regexp',
            'map' => [
                '([a-z]+)(\d)' => '$1/$2',
                '([a-z]+)' => 'string',
                '^\d+(.*)$' => '$1',
                '.+' => 'def'
            ]
        ]
    ];

    /**
     * Multilevel mapping for hierarchical values
     *
     * @var array
     */
    protected $multilevelMapping = [
        [
            'type' => 'normal',
            'map' => [
                'val1' => 'a/b',
                'val2' => '',
                '##default' => 'def'
            ]
        ],
        [
            'type' => 'regexp',
            'map' => [
                '([a-z]+)(\d)' => '\1/\2',
                '([a-z]+)' => 'string',
                '^\d+(.*)$' => '$1',
                '.+' => 'def'
            ]
        ]
    ];

    /**
     * Tests for basic mapping files
     *
     * @return void
     */
    public function testBasicMappingFile()
    {
        $solrUpdater = $this->getSolrUpdater();

        $result = $this->callProtected(
            $solrUpdater, 'mapValue', ['val1', $this->basicMapping]
        );
        $this->assertEquals('a/b', $result);

        $result = $this->callProtected(
            $solrUpdater, 'mapValue', ['val2', $this->basicMapping]
        );
        $this->assertEquals('', $result);

        $result = $this->callProtected(
            $solrUpdater, 'mapValue', ['val3', $this->basicMapping]
        );
        $this->assertEquals('def', $result);

        $result = $this->callProtected(
            $solrUpdater, 'mapValue', ['', $this->basicMapping]
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
        $solrUpdater = $this->getSolrUpdater();

        $result = $this->callProtected(
            $solrUpdater, 'mapValue', ['val1', $this->regexpMapping]
        );
        $this->assertEquals('val/1', $result);

        $result = $this->callProtected(
            $solrUpdater, 'mapValue', ['val', $this->regexpMapping]
        );
        $this->assertEquals('string', $result);

        $result = $this->callProtected(
            $solrUpdater, 'mapValue', ['!21!', $this->regexpMapping]
        );
        $this->assertEquals('def', $result);

        $result = $this->callProtected(
            $solrUpdater, 'mapValue', ['21!', $this->regexpMapping]
        );
        $this->assertEquals('!', $result);

        $result = $this->callProtected(
            $solrUpdater, 'mapValue', ['21', $this->regexpMapping]
        );
        $this->assertEquals('', $result);
    }

    /**
     * Tests for multilevel mapping files
     *
     * @return void
     */
    public function testMultilevelMappingFile()
    {
        $solrUpdater = $this->getSolrUpdater();

        $result = $this->callProtected(
            $solrUpdater, 'mapValue', [['val1', 'val1'], $this->multilevelMapping]
        );
        $this->assertEquals('a/b/val/1', $result);

        $result = $this->callProtected(
            $solrUpdater, 'mapValue', [['val2', 'val1'], $this->multilevelMapping]
        );
        $this->assertEquals('', $result);

        $result = $this->callProtected(
            $solrUpdater, 'mapValue', [['val1', '21'], $this->multilevelMapping]
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
        $reflectionMethod = new ReflectionMethod($object, $method);
        $reflectionMethod->setAccessible(true);
        return $reflectionMethod->invokeArgs($object, $arguments);
    }

    /**
     * Create SolrUpdater
     *
     * @return SolrUpdater
     */
    protected function getSolrUpdater()
    {
        $basePath = dirname(__FILE__) . '/configs/mappingfilestest';
        $logger = $this->createMock(Logger::class);
        $solrUpdater = new SolrUpdater(null, $basePath, $logger, false);

        return $solrUpdater;
    }
}

<?php
/**
 * Tests for preview creation (stresses mapping file handling)
 *
 * PHP version 5
 *
 * Copyright (C) The National Library of Finland 2017.
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
use RecordManager\Base\Record\Factory as RecordFactory;
use RecordManager\Base\Solr\PreviewCreator;
use RecordManager\Base\Utils\Logger;

/**
 * Preview creation tests
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class PreviewCreatorTest extends AbstractTest
{
    /**
     * Holding test record
     *
     * @var string
     */
    protected $holdingRecord = <<<EOT
<record>
  <datafield tag="852">
    <subfield code="b">B1</subfield>
  </datafield>
  <datafield tag="852">
    <subfield code="b">A1</subfield>
    <subfield code="c">2</subfield>
  </datafield>
  <datafield tag="852">
    <subfield code="b">A1</subfield>
    <subfield code="c">X</subfield>
  </datafield>
  <datafield tag="852">
    <subfield code="b">C1</subfield>
    <subfield code="c">2</subfield>
  </datafield>
  <datafield tag="852">
    <subfield code="b">D1</subfield>
    <subfield code="c">2</subfield>
  </datafield>
</record>
EOT;

    /**
     * Data source settings
     *
     * @var array
     */
    protected $dataSourceSettings = [
        'test' => [
            'institution' => 'Test',
            'format' => 'marc',
            'building_mapping' => [
                'building.map',
                'building_sub.map,regexp'
            ]
        ]
    ];

    /**
     * Tests for building field
     *
     * @return void
     */
    public function testBuilding()
    {
        global $configArray;

        $configArray['dataSourceSettings']['test']['driverParams'] = [
            'subLocationInBuilding=c'
        ];

        $preview = $this->getPreviewCreator();

        $timestamp = new \MongoDB\BSON\UTCDateTime();
        $record = [
            'format' => 'marc',
            'original_data' => $this->holdingRecord,
            'normalized_data' => $this->holdingRecord,
            'source_id' => 'test',
            'linking_id' => '_preview',
            'oai_id' => '_preview',
            '_id' => '_preview',
            'created' => $timestamp,
            'date' => $timestamp
        ];

        $result = $preview->create($record);
        $this->assertEquals(
            ['B', 'A/2', 'A', 'DEF/2'],
            $result['building']
        );
    }

    /**
     * Create PreviewCreator
     *
     * @return PreviewCreator
     */
    protected function getPreviewCreator()
    {
        $basePath = dirname(__FILE__) . '/configs/mappingfilestest';
        $logger = $this->createMock(Logger::class);
        $recordFactory = new RecordFactory([]);
        $preview = new PreviewCreator(
            null, $basePath, $logger, false, [], $this->dataSourceSettings,
            $recordFactory
        );

        return $preview;
    }
}
